<?php
session_start();
require_once __DIR__ . '/config.php';
// require_once __DIR__ . '/payroll/payroll_lib.php'; // Tidak digunakan di file ini, bisa dihapus jika tidak ada fungsi lain yang dipakai

if (!isset($_SESSION['nik']) || ($_SESSION['role'] ?? '') !== 'admin') {
    die('Akses ditolak.');
}

// =================================================================
// [REFAKTOR] FUNGSI HELPER UNTUK MENGAMBIL OMZET
// =================================================================
/**
 * Mengambil total omzet (NETTO) dalam rentang tanggal tertentu.
 * @param PDO $conn Koneksi database PDO.
 * @param string $startDate Tanggal mulai format 'Y-m-d'.
 * @param string $endDate Tanggal akhir format 'Y-m-d'.
 * @return float Total omzet.
 */
function getOmzetByDateRange(PDO $conn, string $startDate, string $endDate, ?array $storeCodes = null, ?string $storeNameLike = null): float {
    try {
        $sql = "SELECT SUM(NETTO) as total FROM V_JUAL WHERE TGL BETWEEN :s AND :e";
        $params = [':s' => $startDate, ':e' => $endDate];

        // Opsional filter cabang/toko jika diberikan (contoh: KODESP/NAMASP)
        if (!empty($storeCodes)) {
            $in = implode(',', array_fill(0, count($storeCodes), '?'));
            $sql .= " AND KODESP IN ($in)";
            foreach ($storeCodes as $c) { $params[] = $c; }
        }
        if ($storeNameLike) {
            $sql .= " AND NAMASP LIKE ?";
            $params[] = $storeNameLike;
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($result['total'] ?? 0);
    } catch (PDOException $e) {
        // Pada production, sebaiknya log error ini: error_log($e->getMessage());
        return 0; // Kembalikan 0 jika query gagal
    }
}

if (!function_exists('fmt_idr')) {
    function fmt_idr($v) { return number_format((float)$v, 0, ',', '.'); }
}

// Ranking supplier (MTD / YTD)
function get_supplier_ranking(PDO $conn, string $startDate, string $endDate): array {
    try {
        $sql = "SELECT KODESP, NAMASP, SUM(NETTO) AS TOTAL_NETTO, SUM(QTY) AS TOTAL_QTY
                FROM V_JUAL
                WHERE TGL BETWEEN :s AND :e
                GROUP BY KODESP, NAMASP
                ORDER BY SUM(NETTO) DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':s' => $startDate, ':e' => $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

global $conn;
$today = date('Y-m-d');
$ym_now = date('Y-m');

// 1. Omzet Hari Ini
$omzet_hari_ini = getOmzetByDateRange($conn, $today, $today);

// 2. Omzet & Target Bulan (pilihan)
$mtd_ym = $_GET['mtd_month'] ?? $ym_now;
$is_current_month = ($mtd_ym === $ym_now);
$mtd_start = $mtd_ym . '-01';
$mtd_end_full = date('Y-m-t', strtotime($mtd_start));
$mtd_end = $is_current_month ? $today : $mtd_end_full;

$omzet_bulan_ini = getOmzetByDateRange($conn, $mtd_start, $mtd_end);

$target_total_bulan_ini = 0;
if (file_exists('target_manual.json')) {
    $tj = json_decode(file_get_contents('target_manual.json'), true);
    if (is_array($tj)) {
        if (isset($tj[$ym_now]['total'])) { $target_total_bulan_ini = (float)$tj[$ym_now]['total']; }
        elseif (isset($tj['total'])) { $target_total_bulan_ini = (float)$tj['total']; }
    }
}
$persen_capaian = 0;
if ($target_total_bulan_ini > 0) {
    $persen_capaian = (int)round(($omzet_bulan_ini / $target_total_bulan_ini) * 100);
}

// 3. Perbandingan Mingguan (7 hari terakhir vs 7 hari sebelumnya)
$omzet_minggu_ini = getOmzetByDateRange($conn, date('Y-m-d', strtotime('-6 days')), $today);
$omzet_minggu_lalu = getOmzetByDateRange($conn, date('Y-m-d', strtotime('-13 days')), date('Y-m-d', strtotime('-7 days')));
$perbandingan_mingguan_persen = 0;
if ($omzet_minggu_lalu > 0) {
    $perbandingan_mingguan_persen = (int)round((($omzet_minggu_ini - $omzet_minggu_lalu) / $omzet_minggu_lalu) * 100);
} elseif ($omzet_minggu_ini > 0) {
    $perbandingan_mingguan_persen = 100;
}

// 4. Perbandingan Month-to-Date (MTD)
$omzet_mtd_ini = getOmzetByDateRange($conn, $mtd_start, $mtd_end);
$prev_mtd_ym = date('Y-m', strtotime($mtd_start . ' -1 month'));
$prev_mtd_start = $prev_mtd_ym . '-01';
$prev_mtd_days = (int)date('t', strtotime($prev_mtd_start));
$cap_day = (int)date('d', strtotime($mtd_end));
$prev_mtd_end = $prev_mtd_ym . '-' . str_pad(min($cap_day, $prev_mtd_days), 2, '0', STR_PAD_LEFT);
$omzet_mtd_lalu = getOmzetByDateRange($conn, $prev_mtd_start, $prev_mtd_end);
$perbandingan_mtd_persen = 0;
if ($omzet_mtd_lalu > 0) {
    $perbandingan_mtd_persen = (int)round((($omzet_mtd_ini - $omzet_mtd_lalu) / $omzet_mtd_lalu) * 100);
} elseif ($omzet_mtd_ini > 0) {
    $perbandingan_mtd_persen = 100;
}

// 4b. Perbandingan MTD vs tahun sebelumnya (YoY)
$prev_year_start = date('Y-m-01', strtotime($mtd_start . ' -1 year'));
$prev_year_end_month = date('Y-m-t', strtotime($prev_year_start));
$target_day = (int)date('d', strtotime($mtd_end));
$prev_year_end_dt = new DateTime($prev_year_start);
$prev_year_end_dt->modify('+' . ($target_day - 1) . ' days');
$prev_month_end_dt = new DateTime($prev_year_end_month);
if ($prev_year_end_dt > $prev_month_end_dt) {
    $prev_year_end_dt = $prev_month_end_dt; // jangan melebihi akhir bulan tahun lalu
}
$prev_year_end = $prev_year_end_dt->format('Y-m-d');
$omzet_mtd_prev_year = getOmzetByDateRange($conn, $prev_year_start, $prev_year_end);
$perbandingan_mtd_yoy_persen = 0;
if ($omzet_mtd_prev_year > 0) {
    $perbandingan_mtd_yoy_persen = (int)round((($omzet_mtd_ini - $omzet_mtd_prev_year) / $omzet_mtd_prev_year) * 100);
} elseif ($omzet_mtd_ini > 0) {
    $perbandingan_mtd_yoy_persen = 100;
}

// 5. YTD (global) vs tahun lalu
$ytd_start_now = date('Y-01-01');
$omzet_ytd_ini = getOmzetByDateRange($conn, $ytd_start_now, $today);
$prev_ytd_start = date('Y-01-01', strtotime('-1 year'));
$day_of_year = (int)date('z'); // 0-based; hari ke-(z+1)
$prev_ytd_end_dt = new DateTime($prev_ytd_start);
$prev_ytd_end_dt->modify('+' . $day_of_year . ' days');
$prev_year_dec31 = new DateTime(date('Y-12-31', strtotime('-1 year')));
if ($prev_ytd_end_dt > $prev_year_dec31) { $prev_ytd_end_dt = $prev_year_dec31; }
$prev_ytd_end = $prev_ytd_end_dt->format('Y-m-d');
// YTD prev-year split: Jan–May tetap global, Jun+ hanya cabang PEMUDA/C
$adj_branch_codes = ['C']; $adj_branch_name_like = '%PEMUDA%';
$prev_part1_end = date('Y-05-31', strtotime('-1 year'));
$prev_part2_start = date('Y-06-01', strtotime('-1 year'));

$omzet_ytd_prev_year = 0;
// Bagian 1: Jan–Mei (global)
$range1_start = $prev_ytd_start;
$range1_end = min($prev_part1_end, $prev_ytd_end);
if ($range1_start <= $range1_end) {
    $omzet_ytd_prev_year += getOmzetByDateRange($conn, $range1_start, $range1_end);
}
// Bagian 2: Jun+ (hanya cabang aktif)
if ($prev_ytd_end >= $prev_part2_start) {
    $range2_start = $prev_part2_start;
    $range2_end = $prev_ytd_end;
    $omzet_ytd_prev_year += getOmzetByDateRange($conn, $range2_start, $range2_end, $adj_branch_codes, $adj_branch_name_like);
}
$perbandingan_ytd_yoy_persen = 0;
if ($omzet_ytd_prev_year > 0) {
    $perbandingan_ytd_yoy_persen = (int)round((($omzet_ytd_ini - $omzet_ytd_prev_year) / $omzet_ytd_prev_year) * 100);
} elseif ($omzet_ytd_ini > 0) {
    $perbandingan_ytd_yoy_persen = 100;
}

// 6. Parameter ranking supplier (MTD / YTD)
$rank_mode = $_GET['rank_mode'] ?? 'MTD';
$rank_month = $_GET['rank_month'] ?? $mtd_ym;
$rank_year = substr($rank_month, 0, 4);
$rank_is_current_month = ($rank_month === $ym_now);
if ($rank_mode === 'YTD') {
    $rank_start = $rank_year . '-01-01';
    $rank_month_end = date('Y-m-t', strtotime($rank_month . '-01'));
    // Untuk tahun berjalan, batasi sampai hari ini jika sebelum akhir bulan
    if ($rank_year === date('Y') && $today < $rank_month_end) {
        $rank_end = $today;
    } else {
        $rank_end = $rank_month_end;
    }
} else { // MTD
    $rank_start = $rank_month . '-01';
    $rank_end_full = date('Y-m-t', strtotime($rank_start));
    $rank_end = $rank_is_current_month ? $today : $rank_end_full;
}
// Range pembanding
if ($rank_mode === 'YTD') {
    $prev_rank_start = date('Y-m-d', strtotime($rank_start . ' -1 year'));
    $prev_rank_end   = date('Y-m-d', strtotime($rank_end   . ' -1 year')); // selesaikan di bulan yang sama tahun lalu
} else { // MTD
    $prev_rank_month = date('Y-m', strtotime($rank_start . ' -1 month'));
    $prev_rank_start = $prev_rank_month . '-01';
    $prev_rank_days  = (int)date('t', strtotime($prev_rank_start));
    $cap_day_rank    = (int)date('d', strtotime($rank_end));
    $prev_rank_end   = $prev_rank_month . '-' . str_pad(min($cap_day_rank, $prev_rank_days), 2, '0', STR_PAD_LEFT);
}
$supplier_ranking_all = get_supplier_ranking($conn, $rank_start, $rank_end);
$supplier_prev    = get_supplier_ranking($conn, $prev_rank_start, $prev_rank_end); // pembanding
$supplier_prev_map = [];
foreach ($supplier_prev as $row) {
    $code = $row['KODESP'] ?? '';
    $supplier_prev_map[$code] = $row;
}
$rank_page = max(1, (int)($_GET['rank_page'] ?? 1));
$page_size = 10;
$total_suppliers = count($supplier_ranking_all);
$total_pages = max(1, (int)ceil($total_suppliers / $page_size));
$offset = ($rank_page - 1) * $page_size;
$supplier_ranking = array_slice($supplier_ranking_all, $offset, $page_size);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* [BARU] Animasi untuk kartu */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header { display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; }
        .main-grid { display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start; }
        @media (max-width: 992px) { .main-grid { grid-template-columns: 1fr; } }
        
        /* [DIUBAH] Tambahkan animasi pada kartu utama */
        .main-content .card, .sidebar .card {
            opacity: 0;
            animation: fadeInUp 0.5s ease-out forwards;
        }
        /* [BARU] Tambahkan delay animasi agar muncul berurutan */
        .main-content .card:nth-child(2) { animation-delay: 0.1s; }
        .sidebar .card { animation-delay: 0.2s; }

        .stats-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); gap:16px; }
        .stat-item { background: var(--bg); border:1px solid var(--border); padding:16px; border-radius: var(--radius-md); }
        .stat-item .label { color: var(--text-muted); font-size:14px; margin-bottom:8px; display:flex; gap:8px; align-items:center; }
        .stat-item .value { font-size:24px; font-weight:700; }
        .stat-item .detail { font-size:13px; color:var(--text-muted); margin-top:8px; }
        .change.up { color: var(--green); } .change.down { color: var(--red); }
        
        /* [BARU] Styling untuk Progress Bar */
        .progress-bar {
            background-color: #333; border-radius: 4px; height: 8px;
            overflow: hidden; margin-top: 12px;
        }
        .progress-bar-inner {
            background-color: var(--blue); height: 100%;
            width: <?= max(0, min(100, $persen_capaian)) ?>%; /* Pastikan lebar antara 0-100% */
            border-radius: 4px; transition: width 0.5s ease-out;
        }

        .action-list { display:flex; flex-direction:column; gap:12px; }
        .action-link { padding:12px 16px; border-radius: var(--radius-md); display:flex; align-items:center; gap:12px; font-weight:600; text-decoration:none; background:#2a2a32; color:var(--text); border:1px solid var(--border); transition:var(--transition); }
        .action-link:hover { background:#3a3a42; border-color:#555; }
        .action-link.logout { background: var(--red); color:white; }
    </style>
</head>
<body class="dark">
<div class="container max-w-[1200px] mx-auto px-4">
    <div class="store-header">
        <img src="assets/img/modecentre.png" alt="Mode Centre" class="logo">
        <div class="info">
            <div class="addr">Jl Pemuda Komp Ruko Pemuda 13 - 21 Banjarnegara Jawa Tengah</div>
            <div class="contact">Contact: 0813-9983-9777</div>
        </div>
    </div>
    <div class="header flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Dashboard Admin</h1>
    </div>

    <div class="main-grid grid gap-6 lg:grid-cols-3">
        <div class="main-content grid gap-6 lg:col-span-2">
            <div class="card rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] p-5 shadow">
                <h2 class="card-title"><i class="fa-solid fa-chart-pie"></i> Ringkasan Kinerja</h2>
                <form method="get" style="margin-bottom:12px; display:flex; gap:10px; align-items:center;">
                    <label for="mtd_month" class="muted" style="min-width:140px;">Pilih Bulan MTD</label>
                    <input class="input" style="max-width:180px;" type="month" id="mtd_month" name="mtd_month" value="<?= htmlspecialchars($mtd_ym) ?>">
                    <?php if (!empty($_GET['kal_bulan'])): ?>
                        <input type="hidden" name="kal_bulan" value="<?= htmlspecialchars($_GET['kal_bulan']) ?>">
                    <?php endif; ?>
                    <button class="btn" type="submit">Terapkan</button>
                </form>
                <div class="stats-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4">
                        <span class="label text-sm text-[color:var(--text-muted)] flex items-center gap-2"><i class="fa-solid fa-sun"></i> Omzet Hari Ini</span>
                        <span class="value text-xl font-bold text-[color:var(--green)]">Rp <?= fmt_idr($omzet_hari_ini) ?></span>
                    </div>
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4">
                        <span class="label text-sm text-[color:var(--text-muted)] flex items-center gap-2"><i class="fa-solid fa-dollar-sign"></i> Omzet Bulan Ini</span>
                        <span class="value text-xl font-bold text-[color:var(--blue)]">Rp <?= fmt_idr($omzet_bulan_ini) ?></span>
                        <div class="detail text-[color:var(--text-muted)] text-sm">Target: <?= (int)$persen_capaian ?>% tercapai</div>
                        <div class="w-full h-2 bg-[color:var(--border)] rounded mt-1">
                            <div class="h-2 bg-[color:var(--blue)] rounded" style="width: <?= max(0, min(100, (int)$persen_capaian)) ?>%;"></div>
                        </div>
                    </div>
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4">
                        <span class="label"><i class="fa-solid fa-chart-line"></i> Omzet Mingguan</span>
                        <div class="value">
                            <span class="change <?= $perbandingan_mingguan_persen >= 0 ? 'up' : 'down' ?>">
                                <?= ($perbandingan_mingguan_persen >= 0 ? "&uarr;" : "&darr;") . " " . abs($perbandingan_mingguan_persen) ?>%
                            </span>
                        </div>
                        <div class="detail">Skrg: Rp <?= fmt_idr($omzet_minggu_ini) ?> | Lalu: Rp <?= fmt_idr($omzet_minggu_lalu) ?></div>
                    </div>
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4">
                        <span class="label"><i class="fa-solid fa-code-compare"></i> Omzet Bulanan (MTD)</span>
                        <div class="value">
                            <span class="change <?= $perbandingan_mtd_persen >= 0 ? "up" : "down" ?>">
                                <?= ($perbandingan_mtd_persen >= 0 ? "&uarr;" : "&darr;") . " " . abs($perbandingan_mtd_persen) ?>%
                            </span>
                        </div>
                        <div class="detail">Skrg: Rp <?= fmt_idr($omzet_mtd_ini) ?> | Bulan lalu (MTD): Rp <?= fmt_idr($omzet_mtd_lalu) ?></div>
                    </div>
                </div>
            </div>

            <div class="card rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] p-5 shadow">
                <h2 class="card-title"><i class="fa-solid fa-calendar-day"></i> Perbandingan Tahunan</h2>
                <div class="stats-grid grid gap-4 sm:grid-cols-2">
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4">
                        <span class="label"><i class="fa-solid fa-rotate"></i> MTD Tahun Lalu</span>
                        <div class="value">Rp <?= fmt_idr($omzet_mtd_prev_year) ?></div>
                        <div class="detail">vs MTD sekarang:
                            <span class="change <?= $perbandingan_mtd_yoy_persen >= 0 ? "up" : "down" ?>">
                                <?= $perbandingan_mtd_yoy_persen >= 0 ? "&uarr;" : "&darr;" ?> <?= abs($perbandingan_mtd_yoy_persen) ?>%
                            </span>
                        </div>
                    </div>
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4">
                        <span class="label"><i class="fa-solid fa-globe"></i> YTD (Jan–Mei global, Jun+ cabang C/PEMUDA)</span>
                        <div class="value">Rp <?= fmt_idr($omzet_ytd_ini) ?></div>
                        <div class="detail">Tahun lalu (YTD):
                            Rp <?= fmt_idr($omzet_ytd_prev_year) ?>
                        </div>
                        <div class="detail">Selisih:
                            <span class="change <?= $perbandingan_ytd_yoy_persen >= 0 ? "up" : "down" ?>">
                                <?= $perbandingan_ytd_yoy_persen >= 0 ? "&uarr;" : "&darr;" ?> <?= abs($perbandingan_ytd_yoy_persen) ?>%
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] p-5 shadow">
                <h2 class="card-title"><i class="fa-solid fa-ranking-star"></i> Ranking Penjualan Supplier</h2>
                <form method="get" style="margin-bottom:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                    <label class="muted">Mode</label>
                    <select name="rank_mode" class="input" style="max-width:120px;">
                        <option value="MTD" <?= $rank_mode === 'MTD' ? 'selected' : '' ?>>MTD</option>
                        <option value="YTD" <?= $rank_mode === 'YTD' ? 'selected' : '' ?>>YTD</option>
                    </select>
                    <label class="muted">Periode</label>
                    <input type="month" class="input" style="max-width:160px;" name="rank_month" value="<?= htmlspecialchars($rank_month) ?>">
                    <input type="hidden" name="rank_page" value="1">
                    <button class="btn" type="submit">Tampilkan</button>
                    <!-- Keep existing filters when switching -->
                    <input type="hidden" name="mtd_month" value="<?= htmlspecialchars($mtd_ym) ?>">
                    <?php if (!empty($_GET['kal_bulan'])): ?>
                        <input type="hidden" name="kal_bulan" value="<?= htmlspecialchars($_GET['kal_bulan']) ?>">
                    <?php endif; ?>
                </form>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Supplier</th>
                                <th class="right">Omzet</th>
                                <th class="right">Qty</th>
                                <th class="right">Prev</th>
                                <th class="right">Δ</th>
                                <th class="right">% Growth</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($supplier_ranking)): ?>
                                <tr><td colspan="7" style="text-align:center;">Tidak ada data.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($supplier_ranking as $i => $sup): 
                                $code = $sup['KODESP'] ?? '';
                                $prev = (float)($supplier_prev_map[$code]['TOTAL_NETTO'] ?? 0);
                                $curr = (float)($sup['TOTAL_NETTO'] ?? 0);
                                $delta = $curr - $prev;
                                if ($prev > 0) {
                                    $growth = ($delta / $prev) * 100;
                                } else {
                                    $growth = $curr > 0 ? 100 : 0;
                                }
                            ?>
                                <tr>
                                    <td><?= ($offset + $i + 1) ?></td>
                                    <td><?= htmlspecialchars(($sup['NAMASP'] ?? '') ?: ($sup['KODESP'] ?? '-')) ?></td>
                                    <td class="right">Rp <?= fmt_idr($sup['TOTAL_NETTO'] ?? 0) ?></td>
                                    <td class="right"><?= fmt_idr($sup['TOTAL_QTY'] ?? 0) ?></td>
                                    <td class="right">Rp <?= fmt_idr($prev) ?></td>
                                    <td class="right">Rp <?= fmt_idr($delta) ?></td>
                                    <td class="right"><span class="change <?= $growth >= 0 ? 'up' : 'down' ?>"><?= $growth >= 0 ? '&uarr;' : '&darr;' ?> <?= fmt_idr(abs(round($growth,2))) ?>%</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:8px; flex-wrap:wrap;">
                    <?php for ($p=1; $p<=$total_pages; $p++): 
                        $params = $_GET;
                        $params['rank_page'] = $p;
                        $url = '?' . http_build_query($params);
                    ?>
                        <a class="btn <?= $p==$rank_page?'btn-secondary':' ' ?>" href="<?= htmlspecialchars($url) ?>"><?= $p ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <div class="muted" style="margin-top:6px;">Mode MTD memakai rentang bulan terpilih; pembanding = bulan sebelumnya dengan panjang hari sama. Mode YTD memakai 1 Jan–akhir bulan terpilih; pembanding = periode yang sama tahun lalu.</div>
            </div>

            <div class="card rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] p-5 shadow">
                <h2 class="card-title"><i class="fa-solid fa-file-invoice"></i> Payroll & Laporan</h2>
                <div class="picker" style="display:flex; gap:10px; align-items:center;">
                    <input id="bulan" class="input" type="month" value="<?= htmlspecialchars($ym_now) ?>" style="flex-grow:1;">
                    <a id="linkKelola" class="action-link" href="payroll/payroll_dashboard.php?bulan=<?= $ym_now ?>" style="margin:0; text-align:center;">Kelola Payroll</a>
                </div>
            </div>
        </div>

        <div class="sidebar lg:col-span-1">
            <div class="card rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] p-5 shadow">
                <h2 class="card-title"><i class="fa-solid fa-rocket"></i> Modul & Pengaturan</h2>
                <div class="action-list grid gap-3">
                    <a href="admin_barcode.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-barcode"></i> Generate/Print Barcode</a>
                    <a href="target.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-crosshairs"></i> Set Target Komisi</a>
                    <a href="progress_all.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-bars-progress"></i> Progress Semua Pegawai</a>
                    <a href="payroll/absen_admin.php?date=<?= $today ?>" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-calendar-days"></i> Kelola Absensi Harian</a>
                    <a href="payroll/admin_manage_cuti.php?filter=PENDING" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-umbrella-beach"></i> Setujui Cuti/Libur</a>
                    <a href="payroll/admin_manage_shift.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-right-left"></i> Setujui Tukar Shift</a>
                    <a href="payroll/payroll_shift_setup.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-users"></i> Setup Rotasi Shift</a>
                    <a href="laporan_penjualan_admin.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-file-excel"></i> Laporan Penjualan</a>
                    <a href="laporan_penjualan_harian.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-calendar-day"></i> Laporan Jualan Harian</a>
                    <a href="order_helper.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-boxes-packing"></i> Order Helper</a>
                    <a href="cek_stok_admin.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-boxes-stacked"></i> Cek Stok Barang</a>
                    <hr class="hr">
                    <a href="logout.php" class="action-link logout rounded-xl transition"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                </div>
            </div>

            <div class="card rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] p-5 shadow" style="margin-top:16px;">
                <div class="bar" style="display:flex; justify-content:space-between; align-items:center;">
                    <h2 class="card-title"><i class="fa-solid fa-calendar-days"></i> Kalender Shift & Libur</h2>
                    <form method="get" class="form" style="display:flex; gap:8px; align-items:center;">
                        <input type="month" class="input" name="kal_bulan" value="<?= htmlspecialchars($_GET['kal_bulan'] ?? $ym_now) ?>">
                        <button class="btn" type="submit">Lihat</button>
                    </form>
                </div>
                <?php
                  $kal_ym = $_GET['kal_bulan'] ?? $ym_now; [$k1,$k2]=[($kal_ym.'-01'), date('Y-m-t', strtotime($kal_ym.'-01'))];
                  $kal = [];
                  try {
                    // Ambil absensi harian untuk periode
                    $stmt = $conn->prepare("SELECT TGL, KODESL, SHIFT_JADWAL, STATUS_HARI, OVERTIME_BONUS_FLAG FROM T_ABSENSI WHERE TGL BETWEEN :d1 AND :d2");
                    $stmt->execute([':d1'=>$k1, ':d2'=>$k2]);
                    $names = [];
                    // Load nama SPG
                    $stn = $conn->query("SELECT KODESL,NAMASL FROM T_SALES");
                    while($r=$stn->fetch(PDO::FETCH_ASSOC)){ $names[$r['KODESL']] = trim($r['NAMASL']); }
                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $d = date('Y-m-d', strtotime($r['TGL'])); $nik=$r['KODESL'];
                        if (!isset($kal[$d])) $kal[$d] = ['s1'=>[], 's2'=>[], 'libur'=>[], 'ot'=>[]];
                        $nm = $names[$nik] ?? $nik;
                        if (($r['SHIFT_JADWAL'] ?? '') === 'S1') $kal[$d]['s1'][] = $nm;
                        if (($r['SHIFT_JADWAL'] ?? '') === 'S2') $kal[$d]['s2'][] = $nm;
                        $st = strtoupper((string)($r['STATUS_HARI'] ?? ''));
                        if (in_array($st, ['LIBUR','SAKIT','CUTI','IZIN'])) $kal[$d]['libur'][] = $nm;
                        if ((int)($r['OVERTIME_BONUS_FLAG'] ?? 0) === 1) $kal[$d]['ot'][] = $nm;
                    }
                  } catch (PDOException $e) { $kal=[]; }
                  $first = new DateTime($k1); $days = (int)date('t', strtotime($k1)); $startDow=(int)$first->format('N');
                ?>
                <div class="table-wrap">
                  <?php $kal_js = json_encode($kal, JSON_UNESCAPED_UNICODE); ?>
                  <script>
                    window.kalData = <?= $kal_js ? $kal_js : '{}' ?>;
                    function showKalDetail(d){
                      var box = document.getElementById('kal-detail-box');
                      var tit = document.getElementById('kal-detail-title');
                      var s1 = document.getElementById('kal-detail-s1');
                      var s2 = document.getElementById('kal-detail-s2');
                      var lb = document.getElementById('kal-detail-libur');
                      var ot = document.getElementById('kal-detail-ot');
                      var dateInp = document.getElementById('kal-wa-date');
                      tit.textContent = 'Detail ' + d;
                      var dat = (window.kalData||{})[d] || {s1:[],s2:[],libur:[],ot:[]};
                      function list(arr){ if(!arr||!arr.length) return '<span class="muted">-</span>'; return arr.join(', '); }
                      s1.innerHTML = list(dat.s1);
                      s2.innerHTML = list(dat.s2);
                      lb.innerHTML = list(dat.libur);
                      ot.innerHTML = list(dat.ot);
                      dateInp.value = d;
                      box.style.display='block';
                    }
                    function toggleKalGrid(){
                      var g = document.getElementById('kal-grid');
                      g.style.display = (g.style.display==='none'?'block':'none');
                    }
                    document.addEventListener('DOMContentLoaded', function(){
                      var g = document.getElementById('kal-grid');
                      if (g) g.style.display = 'none';
                      showKalDetail('<?= date('Y-m-d') ?>');
                    });
                  </script>
                  <div style="display:flex; justify-content:flex-end; margin-bottom:6px;">
                    <button class="btn" type="button" onclick="toggleKalGrid()">Tampilkan/Sembunyikan Kalender</button>
                  </div>
                  <div id="kal-grid">
                  <table class="table">
                    <thead><tr><th>Sen</th><th>Sel</th><th>Rab</th><th>Kam</th><th>Jum</th><th>Sab</th><th>Min</th></tr></thead>
                    <tbody>
                      <?php $day=1; for($week=0;$week<6;$week++): ?>
                        <tr>
                        <?php for($dow=1;$dow<=7;$dow++): ?>
                          <?php if($week===0 && $dow<$startDow): ?>
                            <td class="muted"></td>
                          <?php elseif($day>$days): ?>
                            <td></td>
                          <?php else: $dateKey = date('Y-m-d', strtotime($k1.' +'.($day-1).' day')); $dat = $kal[$dateKey] ?? ['s1'=>[], 's2'=>[], 'libur'=>[], 'ot'=>[]]; ?>
                            <td style="vertical-align:top; cursor:pointer;" onclick="showKalDetail('<?= $dateKey ?>')">
                              <div style="font-weight:700; margin-bottom:4px;"><?= $day ?></div>
                              <div style="display:flex; gap:6px; font-size:11px; color:#bbb;">
                                <span title="Shift Pagi">S1: <?= count($dat['s1']) ?></span>
                                <span title="Shift Siang">S2: <?= count($dat['s2']) ?></span>
                                <span title="Libur">L: <?= count($dat['libur']) ?></span>
                                <span title="Lembur">OT: <?= count($dat['ot']) ?></span>
                              </div>
                            </td>
                          <?php $day++; endif; ?>
                        <?php endfor; ?>
                        </tr>
                        <?php if ($day>$days) break; endfor; ?>
                    </tbody>
                  </table>
                  </div>
                </div>
                <div id="kal-detail-box" class="card" style="margin-top:10px; display:none;">
                  <h3 id="kal-detail-title" style="margin-top:0">Detail</h3>
                  <div class="grid" style="grid-template-columns: 1fr 1fr; gap:8px;">
                    <div><b>Shift Pagi (S1)</b><div id="kal-detail-s1" class="muted">-</div></div>
                    <div><b>Shift Siang (S2)</b><div id="kal-detail-s2" class="muted">-</div></div>
                    <div><b>Libur</b><div id="kal-detail-libur" class="muted">-</div></div>
                    <div><b>Lembur</b><div id="kal-detail-ot" class="muted">-</div></div>
                  </div>
                  <form method="post" action="send_wa_admin.php" style="margin-top:10px; display:flex; gap:6px; flex-wrap:wrap;">
                    <input type="hidden" id="kal-wa-date" name="date" value="">
                    <select name="group" class="input" style="min-width:140px">
                      <option value="all">Semua</option>
                      <option value="s1">Shift Pagi</option>
                      <option value="s2">Shift Siang</option>
                      <option value="libur">Libur</option>
                      <option value="ot">Lembur</option>
                    </select>
                    <input type="text" name="msg" class="input" placeholder="Pesan singkat" style="min-width:220px">
                    <button class="btn" type="submit">Kirim WA</button>
                  </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bulanInput = document.getElementById('bulan');
    const linkKelola = document.getElementById('linkKelola');
    function updateLinks() {
        const selectedMonth = bulanInput.value || '<?= $ym_now ?>';
        linkKelola.href = 'payroll/payroll_dashboard.php?bulan=' + encodeURIComponent(selectedMonth);
    }
    bulanInput.addEventListener('change', updateLinks);
    updateLinks();
});
</script>
</body>
</html>

