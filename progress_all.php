<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['nik']) || ($_SESSION['role'] ?? '') !== 'admin') {
    die('Akses ditolak.');
}

global $conn;

// =================================================================
// 1. PENGAMBILAN DATA & TARGET
// =================================================================

$bulan_param = $_GET['bulan'] ?? date('Y-m');
$tanggal_awal = $bulan_param . '-01';
$tanggal_akhir = date('Y-m-t', strtotime($tanggal_awal));

// Target manual
$target_per_orang = 0; $target_total = 0;
if (file_exists('target_manual.json')) {
    $tj = json_decode(file_get_contents('target_manual.json'), true);
    if (is_array($tj)) {
        $target_data = $tj[$bulan_param] ?? $tj; // Dukung format lama & baru
        $target_total = (float)($target_data['total'] ?? 0);
        $target_per_orang = (float)($target_data['per_orang'] ?? 0);
    }
}

// Pegawai terpilih
$selected_niks = [];
$sales_names = [];
$selected = file_exists('target_selected.json') ? json_decode(file_get_contents('target_selected.json'), true) : [];
if (is_array($selected) && !empty($selected)) {
    foreach ($selected as $nik_nama) {
        $parts = explode(' - ', (string)$nik_nama, 2);
        $nik = trim($parts[0] ?? '');
        if ($nik) $selected_niks[] = $nik;
    }

    if (!empty($selected_niks)) {
        $placeholders = implode(',', array_fill(0, count($selected_niks), '?'));
        try {
            $stmtNames = $conn->prepare("SELECT KODESL, NAMASL FROM T_SALES WHERE KODESL IN ($placeholders)");
            $stmtNames->execute($selected_niks);
            while ($row = $stmtNames->fetch(PDO::FETCH_ASSOC)) {
                $sales_names[$row['KODESL']] = trim($row['NAMASL']);
            }
        } catch (PDOException $e) {}
    }
}
$jumlah_spg = count($selected_niks);

// =================================================================
// 2. [OPTIMASI] AMBIL REALISASI SEMUA PEGAWAI (HANYA 1x QUERY)
// =================================================================

$realisasi_pegawai = [];
$total_kolektif = 0.0;
if ($jumlah_spg > 0) {
    $placeholders = implode(',', array_fill(0, count($selected_niks), '?'));
    try {
        $sql = "SELECT KODESL, SUM(NETTO) as total 
                FROM V_JUAL 
                WHERE KODESL IN ($placeholders) AND TGL BETWEEN ? AND ?
                GROUP BY KODESL";
        $params = array_merge($selected_niks, [$tanggal_awal, $tanggal_akhir]);
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($results as $row) {
            $realisasi_pegawai[$row['KODESL']] = (float)$row['total'];
            $total_kolektif += (float)$row['total'];
        }
    } catch (PDOException $e) {}
}

// =================================================================
// 3. HITUNG LEVEL KOLEKTIF & PERSIAPAN DATA
// =================================================================

$target_levels = [
    5  => $target_total,
    10 => round($target_total * 1.10),
    15 => round($target_total * 1.15),
    20 => round($target_total * 1.20),
];
$bonus_persen_map = [ 5 => 0.005, 10 => 0.010, 15 => 0.015, 20 => 0.020 ];

$level_kolektif = 0;
if ($target_total > 0) {
    foreach ([20, 15, 10, 5] as $lvl) {
        if ($total_kolektif >= $target_levels[$lvl]) {
            $level_kolektif = $lvl;
            break;
        }
    }
}
$capaian_kolektif = $target_total > 0 ? (int)round(($total_kolektif / $target_total) * 100) : 0;

// Gabungkan semua data menjadi satu array
$data_pegawai = [];
foreach ($selected_niks as $nik) {
    $realisasi = $realisasi_pegawai[$nik] ?? 0;
    
    $level_individu = 0;
    if ($jumlah_spg > 0) {
        foreach ([20, 15, 10, 5] as $lvl) {
            $target_level_total = $target_levels[$lvl];
            if ($target_level_total > 0) {
                $ind_target = $target_level_total / $jumlah_spg;
                if ($realisasi >= $ind_target) {
                    $level_individu = $lvl;
                    break;
                }
            }
        }
    }
    
    $data_pegawai[] = [
        'nik' => $nik,
        'nama' => $sales_names[$nik] ?? $nik,
        'realisasi' => $realisasi,
        'level_individu' => $level_individu
    ];
}

function idr($v){ return number_format((float)$v, 0, ',', '.'); }

// =================================================================
// 4. [REFAKTOR] FUNGSI HELPER UNTUK PERHITUNGAN BONUS
// =================================================================
/**
 * Menghitung semua nilai turunan (komisi, bonus, total, dll) untuk seorang pegawai.
 * @return array Hasil perhitungan.
 */
function calculate_final_values(array $pegawai, int $level_kolektif, array $bonus_map, float $target_orang): array {
    $realisasi = (float)$pegawai['realisasi'];
    $level_individu = (int)$pegawai['level_individu'];
    $level_final = max($level_kolektif, $level_individu);
    
    $komisi = round($realisasi * 0.01);
    $percent_final    = $level_final > 0    ? (float)($bonus_map[$level_final]    ?? 0) : 0.0;
    $percent_individu = $level_individu > 0 ? (float)($bonus_map[$level_individu] ?? 0) : 0.0;

    // Bagi komponen bonus: individu vs kolektif (base)
    $bonus_individu = round($realisasi * $percent_individu);
    $bonus_kolektif_base = 0;
    if ($percent_final > $percent_individu) {
        $bonus_kolektif_base = round($realisasi * ($percent_final - $percent_individu));
    }

    // Tambahan kolektif +0.5% jika kolektif >= 100% dan pegawai sudah punya bonus individu
    $bonus_kolektif_extra = 0;
    if ($level_kolektif >= 5 && $level_individu > 0) {
        $bonus_kolektif_extra = round($realisasi * 0.005);
    }

    $bonus_kolektif = $bonus_kolektif_base + $bonus_kolektif_extra;
    $bonus = $bonus_individu + $bonus_kolektif;
    $total = $komisi + $bonus;
    $persen = $target_orang > 0 ? round(($realisasi / $target_orang) * 100) : 0;

    return [
        'komisi' => (int)$komisi,
        'bonus'  => (int)$bonus,
        'bonus_individu' => (int)$bonus_individu,
        'bonus_kolektif' => (int)$bonus_kolektif,
        'total'  => (int)$total,
        'persen' => (int)$persen,
        'level'  => $level_final
    ];
}

// =================================================================
// 5. LOGIKA EXPORT (CSV & PDF)
// =================================================================

if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    
    if ($export_type === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=progress_pegawai_' . $bulan_param . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['NIK', 'NAMA', 'TARGET_ORANG', 'REALISASI', 'PERSEN', 'KOMISI_1PCT', 'BONUS_INDIVIDU', 'BONUS_KOLEKTIF', 'TOTAL', 'LEVEL_FINAL']);
        
        foreach ($data_pegawai as $pegawai) {
            $calculated = calculate_final_values($pegawai, $level_kolektif, $bonus_persen_map, $target_per_orang);
            fputcsv($out, [
                $pegawai['nik'], $pegawai['nama'], $target_per_orang, $pegawai['realisasi'],
                $calculated['persen'], $calculated['komisi'], $calculated['bonus_individu'], $calculated['bonus_kolektif'], $calculated['total'], $calculated['level']
            ]);
        }
        fclose($out);
        exit;
    }

    if ($export_type === 'pdf') {
        require_once __DIR__ . '/vendor/autoload.php';
        $rowsHtml = '';
        foreach ($data_pegawai as $pegawai) {
            $calculated = calculate_final_values($pegawai, $level_kolektif, $bonus_persen_map, $target_per_orang);
            $rowsHtml .= '<tr>'
                . '<td>' . htmlspecialchars($pegawai['nik']) . '</td>'
                . '<td>' . htmlspecialchars($pegawai['nama']) . '</td>'
                . '<td style="text-align:right">Rp ' . idr($target_per_orang) . '</td>'
                . '<td style="text-align:right">Rp ' . idr($pegawai['realisasi']) . '</td>'
                . '<td style="text-align:center">' . $calculated['persen'] . '%</td>'
                . '<td style="text-align:right">Rp ' . idr($calculated['komisi']) . '</td>'
                . '<td style="text-align:right">Rp ' . idr($calculated['bonus_individu']) . '</td>'
                . '<td style="text-align:right">Rp ' . idr($calculated['bonus_kolektif']) . '</td>'
                . '<td style="text-align:right">Rp ' . idr($calculated['total']) . '</td>'
                . '<td style="text-align:center">' . ($calculated['level'] > 0 ? ('Level ' . $calculated['level']) : '--') . '</td>'
                . '</tr>';
        }

        $html = '<html><head><meta charset="UTF-8"><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; } h1 { font-size: 16px; } .muted { color: #666; }
            table { width: 100%; border-collapse: collapse; } th, td { border: 1px solid #999; padding: 5px; } thead th { background: #efefef; }
            </style></head><body>'
            . '<h1>Progress Pegawai</h1>'
            . '<div class="muted">Periode: ' . htmlspecialchars($tanggal_awal) . ' s/d ' . htmlspecialchars($tanggal_akhir) . '</div>'
            . '<div class="muted">Target Total: Rp ' . idr($target_total) . ' &middot; Realisasi Total: Rp ' . idr($total_kolektif) . ' &middot; Target/Orang: Rp ' . idr($target_per_orang) . '</div>'
            . '<table class="table"><thead><tr><th>NIK</th><th>Nama</th><th>Target</th><th>Realisasi</th><th>%</th><th>Komisi</th><th>Bonus Individu</th><th>Bonus Kolektif</th><th>Total</th><th>Level</th></tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
            . '</body></html>';

        $dompdf = new Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->stream('progress_pegawai_' . $bulan_param . '.pdf', ["Attachment" => false]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progress Pegawai - <?= htmlspecialchars($bulan_param) ?></title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script defer src="assets/js/ui.js"></script>
    <style>
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        
        body { background-color: var(--bg); }
        .topbar { display:flex; flex-wrap: wrap; gap:12px; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .topbar h1 { margin: 0; }
        .filters { display:flex; gap:10px; align-items:center; margin-left: auto; }
        .export-buttons { display:flex; gap:8px; }
        .table-wrap { overflow-x:auto; }
        .progress-inline { height:8px; background: var(--border); border-radius:4px; overflow:hidden; margin-top: 4px; }
        .progress-inline .bar { height:100%; background: linear-gradient(90deg, #4fc3f7, #58d68d); }
        
        .main-card { opacity: 0; animation: fadeInUp 0.5s ease-out forwards; }
        .main-card:nth-of-type(2) { animation-delay: 0.1s; }

        /* [BARU] Grid untuk ringkasan kolektif */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        .summary-card {
            background-color: var(--card);
            padding: 20px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            opacity: 0;
            animation: fadeInUp 0.5s ease-out forwards;
        }
        /* [BARU] Delay animasi untuk setiap kartu ringkasan */
        .summary-card:nth-child(1) { animation-delay: 0.1s; }
        .summary-card:nth-child(2) { animation-delay: 0.2s; }
        .summary-card:nth-child(3) { animation-delay: 0.3s; }
        .summary-card:nth-child(4) { animation-delay: 0.4s; }

        .summary-card .label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1em;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .summary-card .value {
            font-size: 2em;
            font-weight: 700;
        }
        .summary-bar { display:flex; align-items: center; gap: 16px; margin-top: 16px; }
        .summary-bar .progress-container { flex-grow: 1; }
        .summary-bar .progress-bar { width: 100%; height: 10px; background-color: var(--border); border-radius: 5px; overflow: hidden;}
        .summary-bar .progress-bar-inner { height: 100%; background: linear-gradient(90deg, #4fc3f7, #58d68d); transition: width 0.5s ease-out;}
        .summary-bar .progress-label { font-weight: bold; font-size: 1.5em; color: var(--green); white-space: nowrap; }

        #tbl-progress .badge { font-size: 0.8em; padding: 3px 8px; border-radius: 10px; font-weight: 600; }
        #tbl-progress .badge.level-up { background:rgba(46,204,113,.2); color:#58d68d; }
        #tbl-progress .badge.level-down { background:rgba(231,76,60,.2); color:#e74c3c; }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <h1>Progress Semua Pegawai</h1>
        <form method="get" class="filters">
            <label class="muted">Bulan</label>
            <input type="month" name="bulan" value="<?= htmlspecialchars($bulan_param) ?>">
            <button class="btn btn-primary" type="submit">Lihat</button>
        </form>
        <div class="export-buttons">
            <a href="dashboard_admin.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali</a>
            <a class="btn" href="?bulan=<?= urlencode($bulan_param) ?>&export=csv"><i class="fas fa-file-csv"></i> CSV</a>
            <a class="btn" href="?bulan=<?= urlencode($bulan_param) ?>&export=pdf" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <span class="label"><i class="fas fa-flag-checkered"></i> Target Total</span>
            <span class="value" class="text-var-blue">Rp <?= idr($target_total) ?></span>
        </div>
        <div class="summary-card">
            <span class="label"><i class="fas fa-chart-line"></i> Realisasi Total</span>
            <span class="value" class="text-var-green">Rp <?= idr($total_kolektif) ?></span>
        </div>
        <div class="summary-card">
            <span class="label"><i class="fas fa-user"></i> Target/Orang</span>
            <span class="value">Rp <?= idr($target_per_orang) ?></span>
        </div>
        <div class="summary-card">
            <span class="label"><i class="fas fa-trophy"></i> Level Kolektif</span>
            <span class="value" style="color: <?= $level_kolektif > 0 ? 'var(--green)' : 'var(--text-muted)'?>;">
                <?= $level_kolektif > 0 ? 'TARGET ' . $level_kolektif : 'Belum Tercapai' ?>
            </span>
        </div>
    </div>

    <div class="main-card card"> <div style="padding: 20px;">
            <h2 class="card-title" style="margin:0 0 16px 0;"><i class="fas fa-tasks"></i> Capaian Kolektif & Detail Pegawai</h2>
            <div class="summary-bar">
                <div class="progress-label"><?= $capaian_kolektif ?>%</div>
                <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-bar-inner" style="width: <?= min(100, $capaian_kolektif) ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($target_total <= 0): ?>
            <div class="error-box" style="margin: 20px;">Target bulan <?= htmlspecialchars($bulan_param) ?> belum ditentukan di file <strong>target_manual.json</strong>.</div>
        <?php elseif ($jumlah_spg === 0): ?>
            <div class="error-box" style="margin: 20px;">Tidak ada pegawai terpilih di file <strong>target_selected.json</strong>.</div>
        <?php else: ?>
            <div class="grid" style="grid-template-columns: 1fr auto; align-items:center; margin: 0 20px 16px 20px;">
                <div class="notif-bar <?= $level_kolektif > 0 ? 'success' : 'warn' ?>" style="margin:0;">
                    <?php if ($level_kolektif > 0): ?>
                        <i class="fas fa-check-circle"></i> <strong>Kolektif Tercapai: TARGET <?= $level_kolektif ?>.</strong> Bonus minimal untuk semua adalah Level <?= $level_kolektif ?>.
                    <?php else: ?>
                        <i class="fas fa-exclamation-triangle"></i> <strong>Kolektif belum tercapai.</strong> Bonus dihitung berdasarkan performa individu.
                    <?php endif; ?>
                </div>
                <input id="progress-search" type="search" placeholder="Cari NIK atau Nama..." style="max-width:260px;" />
            </div>
            
            <div class="table-wrap table-card">
                <table id="tbl-progress">
                    <thead>
                        <tr>
                            <th data-sort="text">NIK</th>
                            <th data-sort="text">Nama</th>
                            <th class="right" data-sort="number">Realisasi</th>
                            <th class="center" data-sort="number">% Thd Target</th>
                            <th class="right" data-sort="number">Komisi (1%)</th>
                            <th class="right" data-sort="number">Bonus Individu</th>
                            <th class="right" data-sort="number">Bonus Kolektif</th>
                            <th class="right" data-sort="number">Total Diterima</th>
                            <th class="center" data-sort="number">Level Final</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data_pegawai as $pegawai):
                            $calculated = calculate_final_values($pegawai, $level_kolektif, $bonus_persen_map, $target_per_orang);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($pegawai['nik']) ?></td>
                            <td><?= htmlspecialchars($pegawai['nama']) ?></td>
                            <td class="right">Rp <?= idr($pegawai['realisasi']) ?></td>
                            <td class="center">
                                <?= $calculated['persen'] ?>%
                                <div class="progress-inline"><div class="bar" style="width: <?= min(100, $calculated['persen']) ?>%"></div></div>
                            </td>
                            <td class="right">Rp <?= idr($calculated['komisi']) ?></td>
                            <td class="right">Rp <?= idr($calculated['bonus_individu']) ?></td>
                            <td class="right">Rp <?= idr($calculated['bonus_kolektif']) ?></td>
                            <td class="right"><strong>Rp <?= idr($calculated['total']) ?></strong></td>
                            <td class="center">
                                <?php if ($calculated['level'] > 0): ?>
                                    <span class="badge level-up">Level <?= $calculated['level'] ?></span>
                                <?php else: ?>
                                    <span class="badge level-down">--</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function(){
    if (window.enableTableSort) { enableTableSort('#tbl-progress'); }
    if (window.enableTableSearch) { enableTableSearch('#tbl-progress', '#progress-search'); }
  });
</script>
</body>
</html>
