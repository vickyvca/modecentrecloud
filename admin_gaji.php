<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Akses hanya untuk admin.");
}

$bulan_ini = date('Y_m');
$periode_label = date('F Y');

// Ambil daftar pegawai dari T_SALES
$stmt = $conn->query("SELECT NIK, NAMA, JABATAN FROM T_SALES ORDER BY NAMA");
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ambil target dari target_manual.json dan target_selected.json
$target_per_orang = 0;
$jumlah_pegawai = 0;
if (file_exists('target_selected.json')) {
    $jumlah_pegawai = count(json_decode(file_get_contents('target_selected.json'), true));
}
if (file_exists('target_manual.json')) {
    $json = json_decode(file_get_contents('target_manual.json'), true);
    if ($json['bulan'] === date('Y-m') && $jumlah_pegawai > 0) {
        $target_per_orang = $json['per_orang'];
    }
}

// Ambil data target bonus dari file TARGET.xlsx (diolah manual sebelumnya sebagai array)
$bonus_map = [
    5 => 30000,
    10 => 40000,
    15 => 50000,
    20 => 60000
];
$bonus_default = $bonus_map[$jumlah_pegawai] ?? 0;

$data_gaji = [];
foreach ($sales as $s) {
    $nik = $s['NIK'];
    $nama = $s['NAMA'];
    $jabatan = $s['JABATAN'];

    // Ambil total penjualan
    $stmt = $conn->prepare("SELECT SUM(NETTO) as total FROM V_JUAL WHERE KODESL = :nik AND TGL BETWEEN :start AND :end");
    $stmt->execute([
        'nik' => $nik,
        'start' => date('Y-m-01'),
        'end' => date('Y-m-t')
    ]);
    $total_jual = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    $komisi = round($total_jual * 0.01);

    $persen = $target_per_orang > 0 ? round($total_jual / $target_per_orang * 100) : 0;
    $bonus = $persen >= 100 ? $bonus_default : 0;

    // Gaji pokok ambil manual dari data master (hardcoded sementara)
    $gaji_pokok = 850000; // default sementara
    if ($nik === '001') $gaji_pokok = 875000;

    $total = $gaji_pokok + $komisi + $bonus;

    $data_gaji[] = [
        'NIK' => $nik,
        'Nama' => $nama,
        'Jabatan' => $jabatan,
        'GajiPokok' => $gaji_pokok,
        'Komisi' => $komisi,
        'Lembur' => 0,
        'Bonus' => $bonus,
        'Absensi' => 0,
        'BPJS' => 0,
        'TunjanganJabatan' => 0,
        'PenaltyAbsensi' => 0,
        'TOTAL' => $total
    ];
}

file_put_contents("data/gaji_{$bulan_ini}.json", json_encode($data_gaji, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "<h3>Data gaji untuk $periode_label berhasil dihitung dan disimpan.</h3>";
echo "<a href='preview_slip.php' target='_blank'>Preview Slip Gaji</a>";
