<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['nik']) || $_SESSION['role'] !== 'admin') {
    die("Akses ditolak.");
}

$bulan = date('Y-m');
$sisa_hari = date('t') - date('j');
$selected = file_exists('target_selected.json') ? json_decode(file_get_contents('target_selected.json'), true) : [];
$target_data = file_exists('target_manual.json') ? json_decode(file_get_contents('target_manual.json'), true) : [];
$target_per_orang = 0;
if (isset($target_data['bulan']) && $target_data['bulan'] === $bulan && count($selected) > 0) {
    $target_per_orang = $target_data['per_orang'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progress Semua Pegawai</title>
    <style>
        :root {
            --primary-bg: #121212;
            --secondary-bg: #1E1E1E;
            --accent-color: #e53935;
            --primary-text: #F5F5F5;
            --border-color: #424242;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--primary-bg); color: var(--primary-text); padding: 15px; margin: 0; }
        .container { background: var(--secondary-bg); padding: 25px; max-width: 900px; margin: auto; border-radius: 12px; border: 1px solid var(--border-color); }
        h2 { color: var(--accent-color); margin-top: 0; }
        .back { background: #424242; color: white; padding: 9px 15px; text-decoration: none; display: inline-block; margin-bottom: 20px; border-radius: 8px; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 700px; }
        th, td { padding: 12px; border: 1px solid var(--border-color); text-align: center; font-size: 13px; white-space: nowrap; }
        th { background: #2a2a2a; }
    </style>
</head>
<body>
<div class="container">
    <a href="dashboard_admin.php" class="back">🔙 Kembali ke Dashboard</a>
    <h2>Progress Pegawai - <?= date("F Y") ?></h2>
    <div class="table-responsive">
        <table>
            <tr><th>NIK</th><th>Target</th><th>Realisasi</th><th>%</th><th>Komisi</th><th>Target Harian</th></tr>
            <?php foreach ($selected as $nik):
                $stmt = $conn->prepare("SELECT SUM(NETTO) as total FROM V_JUAL WHERE KODESL = :nik AND TGL BETWEEN :start AND :end");
                $stmt->execute(['nik' => $nik, 'start' => date('Y-m-01'), 'end' => date('Y-m-t')]);
                $realisasi = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
                $komisi = round($realisasi * 0.01);
                $persen = $target_per_orang > 0 ? round($realisasi / $target_per_orang * 100) : 0;
                $sisa_target = max($target_per_orang - $realisasi, 0);
                $target_harian = $sisa_hari > 0 ? ceil($sisa_target / $sisa_hari) : 0;
            ?>
            <tr>
                <td><?= $nik ?></td>
                <td>Rp <?= number_format($target_per_orang) ?></td>
                <td>Rp <?= number_format($realisasi) ?></td>
                <td><?= $persen ?>%</td>
                <td>Rp <?= number_format($komisi) ?></td>
                <td>Rp <?= number_format($target_harian) ?>/hr</td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
  if (window.enableAutoPaging) { enableAutoPaging({ pageSize: 25 }); }
});
</script>
</body>
</html>
