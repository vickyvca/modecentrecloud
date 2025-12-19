<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Akses hanya untuk admin.");
}

$bulan_ini = date('Y_m');
$file_json = "data/gaji_{$bulan_ini}.json";

if (!file_exists($file_json)) {
    die("File gaji belum dibuat. Silakan jalankan admin_gaji.php lebih dulu.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents($file_json), true);
    foreach ($data as &$pegawai) {
        $nik = $pegawai['NIK'];
        foreach (['Absensi','Lembur','Bonus','BPJS','TunjanganJabatan','PenaltyAbsensi'] as $field) {
            $pegawai[$field] = (int) ($_POST[$field][$nik] ?? 0);
        }
        $pegawai['TOTAL'] = $pegawai['GajiPokok'] + $pegawai['Komisi'] + $pegawai['Lembur'] + $pegawai['Bonus'] - $pegawai['Absensi'] - $pegawai['PenaltyAbsensi'];
    }
    file_put_contents($file_json, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "<script>alert('Perubahan berhasil disimpan!'); location.reload();</script>";
    exit;
}

$data = json_decode(file_get_contents($file_json), true);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <meta charset="UTF-8">
    <title>Preview & Edit Slip Gaji</title>
    <style>
        body { font-family: sans-serif; background: #f0f0f0; padding: 30px; }
        .slip { background: #fff; padding: 20px; margin-bottom: 30px; border: 1px solid #ccc; max-width: 700px; margin-left: auto; margin-right: auto; }
        .slip h3 { margin-top: 0; }
        .row { display: flex; justify-content: space-between; margin-bottom: 5px; }
        input[type=number] { width: 100px; }
        .footer { text-align: center; font-size: 12px; color: #666; margin-top: 10px; }
        .save-btn { display: block; width: 200px; margin: 30px auto; padding: 10px; background: green; color: white; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; }
    </style>
</head>
<body>
<h2 style="text-align:center;">PREVIEW & EDIT SLIP GAJI - <?= strtoupper(date('F Y')) ?></h2>
<form method="post">
<?php foreach ($data as $pegawai): ?>
<div class="slip">
    <h3><?= $pegawai['Nama'] ?> (<?= $pegawai['NIK'] ?>)</h3>
    <div>Jabatan: <?= $pegawai['Jabatan'] ?></div>
    <div class="row"><div>Gaji Pokok:</div><div>Rp <?= number_format($pegawai['GajiPokok'], 0, ',', '.') ?></div></div>
    <div class="row"><div>Komisi:</div><div>Rp <?= number_format($pegawai['Komisi'], 0, ',', '.') ?></div></div>
    <div class="row"><div>Lembur:</div><div><input type="number" name="Lembur[<?= $pegawai['NIK'] ?>]" value="<?= $pegawai['Lembur'] ?>"></div></div>
    <div class="row"><div>Bonus:</div><div><input type="number" name="Bonus[<?= $pegawai['NIK'] ?>]" value="<?= $pegawai['Bonus'] ?>"></div></div>
    <div class="row"><div>Absensi:</div><div><input type="number" name="Absensi[<?= $pegawai['NIK'] ?>]" value="<?= $pegawai['Absensi'] ?>"></div></div>
    <div class="row"><div>BPJS:</div><div><input type="number" name="BPJS[<?= $pegawai['NIK'] ?>]" value="<?= $pegawai['BPJS'] ?>"></div></div>
    <div class="row"><div>Tunjangan Jabatan:</div><div><input type="number" name="TunjanganJabatan[<?= $pegawai['NIK'] ?>]" value="<?= $pegawai['TunjanganJabatan'] ?>"></div></div>
    <div class="row"><div>Penalty Absensi:</div><div><input type="number" name="PenaltyAbsensi[<?= $pegawai['NIK'] ?>]" value="<?= $pegawai['PenaltyAbsensi'] ?>"></div></div>
    <div class="row"><strong>TOTAL:</strong><strong>Rp <?= number_format($pegawai['TOTAL'], 0, ',', '.') ?></strong></div>
    <div class="footer">Banjarnegara - <?= date('d/m/Y') ?></div>
</div>
<?php endforeach; ?>
<button type="submit" class="save-btn">SIMPAN SEMUA PERUBAHAN</button>
</form>
</body>
</html>
