<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['nik']) || $_SESSION['role'] !== 'pegawai') {
    die("Akses ditolak.");
}

$nik = $_SESSION['nik'];
$nama = $_SESSION['nama'];
$bulan_ini = date('Y_m');
$file_json = "data/gaji_{$bulan_ini}.json";

if (!file_exists($file_json)) {
    die("Data gaji bulan ini belum tersedia.");
}

$data_gaji = json_decode(file_get_contents($file_json), true);
$gaji_pegawai = null;

foreach ($data_gaji as $row) {
    if ($row['NIK'] === $nik) {
        $gaji_pegawai = $row;
        break;
    }
}

if (!$gaji_pegawai) {
    die("Slip gaji tidak ditemukan untuk NIK Anda.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <meta charset="UTF-8">
    <title>Slip Gaji</title>
    <style>
        body { font-family: Arial, sans-serif; background: #fff; padding: 30px; color: #000; }
        .slip { max-width: 700px; margin: auto; border: 1px solid #000; padding: 30px; line-height: 1.6; }
        .row { display: flex; justify-content: space-between; }
        .title { text-align: center; font-weight: bold; font-size: 18px; margin-bottom: 20px; }
        .footer { text-align: center; margin-top: 40px; }
    </style>
</head>
<body>
<div class="slip">
    <div class="title">SLIP GAJI PEGAWAI <?= strtoupper(date('F Y')) ?></div>
    <div>NIK <?= htmlspecialchars($gaji_pegawai['NIK']) ?></div>
    <div>NAMA <?= htmlspecialchars($gaji_pegawai['Nama']) ?></div>
    <div>Jabatan : <?= htmlspecialchars($gaji_pegawai['Jabatan']) ?></div>
    <br>
    <div class="row">
        <div>Gaji Pokok : <?= number_format($gaji_pegawai['GajiPokok'], 0, ',', '.') ?>Rp</div>
        <div>POTONGAN</div>
    </div>
    <div class="row">
        <div>Komisi : <?= number_format($gaji_pegawai['Komisi'], 0, ',', '.') ?>Rp</div>
        <div>Pinjaman :</div>
    </div>
    <div class="row">
        <div>Lembur : <?= number_format($gaji_pegawai['Lembur'], 0, ',', '.') ?>Rp</div>
        <div>Absen : <?= number_format($gaji_pegawai['Absensi'], 0, ',', '.') ?>Rp</div>
    </div>
    <div class="row">
        <div>Bonus Absensi :</div>
        <div>BPJS : <?= number_format($gaji_pegawai['BPJS'], 0, ',', '.') ?>Rp</div>
    </div>
    <div class="row">
        <div>Tunjangan Jab :</div>
    </div>
    <br>
    <div class="row">
        <div>Total Penerimaan <?= number_format($gaji_pegawai['TOTAL'], 0, ',', '.') ?>Rp</div>
        <div>-Rp</div>
    </div>
    <div>GRAND TOTAL <?= number_format($gaji_pegawai['TOTAL'], 0, ',', '.') ?>Rp</div>
    <br><br>
    <div class="footer">
        Mode Centre<br><br>
        <?= date('d/m/Y') ?><br>
        Banjarnegara
    </div>
</div>
</body>
</html>
