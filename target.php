<?php
session_start();
if (!isset($_SESSION['nik']) || ($_SESSION['role'] ?? '') !== 'admin') {
    die('Akses ditolak.');
}

$selectedFile = 'target_selected.json';
$manualTargetFile = 'target_manual.json';
$selectedNIK = file_exists($selectedFile) ? json_decode(file_get_contents($selectedFile), true) : [];
$jumlahNIK = is_array($selectedNIK) ? count($selectedNIK) : 0;
$message = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bulan = $_POST['bulan'] ?? '';
    $total_target = (int)str_replace([',','.',' '], '', $_POST['total'] ?? '0');
    if ($jumlahNIK === 0 || !$bulan || $total_target <= 0) {
        $message = 'Mohon lengkapi semua data dan pastikan ada NIK terpilih.';
        $msg_type = 'error';
    } else {
        $target_per_orang = (int)round($total_target / $jumlahNIK);
        $target_data = ['bulan' => $bulan, 'total' => $total_target, 'per_orang' => $target_per_orang];
        file_put_contents($manualTargetFile, json_encode($target_data, JSON_PRETTY_PRINT));
        $message = 'Target berhasil disimpan: Rp ' . number_format($target_per_orang) . ' per orang.';
        $msg_type = 'success';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Target Manual</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
</head>
<body class="center-page">
    <div class="card fade-in" style="width:100%;max-width:520px;">
        <h1 class="mb-2" style="color:var(--brand-bg);">Set Target Manual</h1>
        <?php if ($message): ?>
            <div class="<?= $msg_type === 'success' ? 'notif-bar success' : 'error-box' ?>" style="margin-bottom:16px;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <form method="post" class="form">
            <div>
                <label for="total" class="muted">Total Target Komisi (Rp)</label>
                <input type="text" id="total" name="total" required placeholder="Contoh: 50000000">
            </div>
            <div>
                <label for="bulan" class="muted">Pilih Bulan Target</label>
                <input type="month" id="bulan" name="bulan" required value="<?= date('Y-m') ?>">
            </div>
            <div>
                <label class="muted">Jumlah Pegawai Terpilih</label>
                <input type="text" value="<?= (int)$jumlahNIK ?>" disabled>
            </div>
            <button type="submit" class="btn btn-primary">Simpan Target</button>
        </form>
    </div>
</body>
</html>
