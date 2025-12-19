<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['nik']) || $_SESSION['role'] !== 'pegawai') {
    die("Akses ditolak.");
}

// ... (PHP logic remains unchanged) ...
$nik = $_SESSION['nik'];
$bulan_ini = date('Y-m');
$tanggal_hari_ini = date('Y-m-d');
$sisa_hari = date('t') - date('j');

$komisi = [];
$file = 'komisi.json';
if (file_exists($file)) {
    $json = file_get_contents($file);
    $komisi = json_decode($json, true);
}
$data_nik = $komisi[$nik] ?? [];

use PhpOffice\PhpSpreadsheet\IOFactory;
$target_file = 'TARGET.xlsx';
$target_table = [];

if (file_exists($target_file)) {
    $spreadsheet = IOFactory::load($target_file);
    $sheet = $spreadsheet->getSheet(0);
    $rows = $sheet->toArray();

    foreach ($rows as $row) {
        if (strtolower(substr($row[0], 0, 3)) === strtolower(substr(date('F'), 0, 3))) {
            $target_table = [
                'bulan' => $row[0], 'target_5' => $row[1], 'target_10' => $row[2],
                'target_15' => $row[3], 'target_20' => $row[4], 'jumlah_spg' => $row[5],
            ];
            break;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progress Komisi</title>
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
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th, td { padding: 12px; border: 1px solid var(--border-color); text-align: center; font-size: 13px; white-space: nowrap; }
        th { background: #2a2a2a; }
        .status-ok { color: #a5d6a7; }
        .status-bad { color: #ef9a9a; }
    </style>
</head>
<body>
<div class="container">
    <a href="dashboard_pegawai.php" class="back">🔙 Kembali ke Dashboard</a>
    <h2>Progress Komisi & Bonus</h2>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Bulan</th><th>Target (Pribadi)</th><th>Realisasi</th><th>% Capaian</th>
                    <th>Komisi (1%)</th><th>Target Harian</th><th>Bonus</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (!empty($target_table) && !empty($target_table['jumlah_spg'])) {
                $key = 'target_' . $target_table['jumlah_spg'];
                if (isset($target_table[$key])) {
                     $target_bulan_ini = $target_table[$key];
                     $target_perorang = round($target_bulan_ini / $target_table['jumlah_spg']);
                     $stmt = $conn->prepare("SELECT SUM(NETTO) as total FROM V_JUAL WHERE KODESL = :nik AND TGL BETWEEN :start AND :end");
                     $stmt->execute(['nik' => $nik, 'start' => date('Y-m-01'), 'end' => date('Y-m-t')]);
                     $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
                     $persen = $target_perorang > 0 ? round(($total / $target_perorang) * 100) : 0;
                     $komisi = round($total * 0.01);
                     $sisa_target = max($target_perorang - $total, 0);
                     $target_harian = $sisa_hari > 0 ? ceil($sisa_target / $sisa_hari) : 0;
                     $bonus_level = 0;
                     if ($persen >= 100) $bonus_level = 0.02;
                     elseif ($persen >= 90) $bonus_level = 0.015;
                     elseif ($persen >= 80) $bonus_level = 0.01;
                     elseif ($persen >= 70) $bonus_level = 0.005;
                     $status_class = $persen >= 70 ? "status-ok" : "status-bad";
                     $status_text = $persen >= 70 ? "✅ Berhak Bonus" : "❌ Belum";
                    echo "<tr>
                        <td>" . date("F Y") . "</td>
                        <td>Rp " . number_format($target_perorang) . "</td>
                        <td>Rp " . number_format($total) . "</td>
                        <td>{$persen}%</td>
                        <td>Rp " . number_format($komisi) . "</td>
                        <td>Rp " . number_format($target_harian) . "/hari</td>
                        <td>" . ($bonus_level * 100) . "%</td>
                        <td class='{$status_class}'>{$status_text}</td>
                    </tr>";
                } else {
                     echo "<tr><td colspan='8'>Konfigurasi target untuk jumlah SPG saat ini tidak ditemukan.</td></tr>";
                }
            } else {
                echo "<tr><td colspan='8'>Data target belum tersedia untuk bulan ini.</td></tr>";
            }
            ?>
            </tbody>
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
