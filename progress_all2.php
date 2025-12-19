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
    <title>Progress Semua Pegawai</title>
    <style>
        :root {
            --primary-bg: #121212;
            --secondary-bg: #1E1E1E;
            --accent-color: #e53935;
            --primary-text: #F5F5F5;
            --border-color: #424242;
        }
        body { background: var(--primary-bg); color: var(--primary-text); padding: 15px; margin: 0; font-family: sans-serif; }
        .container { background: var(--secondary-bg); padding: 25px; max-width: 1000px; margin: auto; border-radius: 12px; border: 1px solid var(--border-color); }
        h2 { color: var(--accent-color); }
        .back { background: #424242; color: white; padding: 9px 15px; text-decoration: none; border-radius: 8px; display: inline-block; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; min-width: 800px; }
        th, td { padding: 10px; border: 1px solid var(--border-color); text-align: center; font-size: 13px; }
        th { background: #2a2a2a; }
        ul { list-style: none; padding-left: 0; }
        ul li { margin: 6px 0; }
    </style>
</head>
<body>
<div class="container">
    <a href="dashboard_admin.php" class="back">🔙 Kembali ke Dashboard</a>
    <h2>Progress Pegawai - <?= date("F Y") ?></h2>

    <table>
        <tr>
            <th>NIK</th>
            <th>Target</th>
            <th>Realisasi</th>
            <th>%</th>
            <th>Komisi</th>
            <th>Target Harian</th>
            <th>Level Individu</th>
        </tr>
        <?php
        $total_kolektif = 0;
        $jumlah_spg = count($selected);

        $target_levels = [
            5 => $target_data['total'],
            10 => round($target_data['total'] * 1.10),
            15 => round($target_data['total'] * 1.15),
            20 => round($target_data['total'] * 1.20)
        ];

        foreach ($selected as $nik):
            $stmt = $conn->prepare("SELECT SUM(NETTO) as total FROM V_JUAL WHERE KODESL = :nik AND TGL BETWEEN :start AND :end");
            $stmt->execute(['nik' => $nik, 'start' => date('Y-m-01'), 'end' => date('Y-m-t')]);
            $realisasi = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            $total_kolektif += $realisasi;

            $komisi = round($realisasi * 0.01);
            $persen = $target_per_orang > 0 ? round($realisasi / $target_per_orang * 100) : 0;
            $sisa_target = max($target_per_orang - $realisasi, 0);
            $target_harian = $sisa_hari > 0 ? ceil($sisa_target / $sisa_hari) : 0;

            // Cek level individu
            $level_individu = 0;
            foreach ([20, 15, 10, 5] as $lvl) {
                $ind_target = $target_levels[$lvl] / $jumlah_spg;
                if ($realisasi >= $ind_target) {
                    $level_individu = $lvl;
                    break;
                }
            }
        ?>
        <tr>
            <td><?= $nik ?></td>
            <td>Rp <?= number_format($target_per_orang) ?></td>
            <td>Rp <?= number_format($realisasi) ?></td>
            <td><?= $persen ?>%</td>
            <td>Rp <?= number_format($komisi) ?></td>
            <td>Rp <?= number_format($target_harian) ?>/hr</td>
            <td><?= $level_individu > 0 ? "TARGET $level_individu ✅" : "❌ Belum Tercapai" ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <?php
    $persen_kolektif = $target_data['total'] > 0 ? round($total_kolektif / $target_data['total'] * 100) : 0;
    $level_tercapai = 0;
    foreach ([20, 15, 10, 5] as $lvl) {
        if ($total_kolektif >= $target_levels[$lvl]) {
            $level_tercapai = $lvl;
            break;
        }
    }
    ?>
    <h3 style="margin-top:40px">📊 Progress Kolektif Tim</h3>
    <ul>
        <li>Total Target Tim (LEVEL 5): Rp <?= number_format($target_data['total']) ?></li>
        <li>Total Realisasi Tim: Rp <?= number_format($total_kolektif) ?></li>
        <li>Persentase Capaian: <?= $persen_kolektif ?>%</li>
        <li>Level Target Tercapai: <strong><?= $level_tercapai > 0 ? 'TARGET ' . $level_tercapai : '❌ Belum Tercapai' ?></strong></li>
    </ul>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
  if (window.enableAutoPaging) { enableAutoPaging({ pageSize: 25 }); }
});
</script>
</body>
</html>
