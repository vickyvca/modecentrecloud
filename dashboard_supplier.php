<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['kodesp']) || ($_SESSION['role'] ?? '') !== 'supplier') {
    die('Akses ditolak.');
}

// =================================================================
// [REFAKTOR] FUNGSI HELPER UNTUK MENGAMBIL DATA PENJUALAN
// =================================================================
/**
 * Mengambil total penjualan (QTY) untuk supplier dalam rentang tanggal tertentu.
 * @param PDO $conn Koneksi database.
 * @param string $kodesp Kode supplier.
 * @param string $startDate Tanggal mulai ('Y-m-d').
 * @param string $endDate Tanggal akhir ('Y-m-d').
 * @return float Jumlah total QTY.
 */
function getSoldPcsByDateRange(PDO $conn, string $kodesp, string $startDate, string $endDate): float {
    try {
        $stmt = $conn->prepare("SELECT SUM(QTY) as total FROM V_JUAL WHERE KODESP = :k AND TGL BETWEEN :s AND :e");
        $stmt->execute([':k' => $kodesp, ':s' => $startDate, ':e' => $endDate]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($result['total'] ?? 0);
    } catch (PDOException $e) {
        // Sebaiknya log error ini di production: error_log($e->getMessage());
        return 0;
    }
}

global $conn;
$kodesp = $_SESSION['kodesp'];

// Ambil nama supplier (cache di session)
if (!isset($_SESSION['namasp'])) {
    try {
        $stmt_nama = $conn->prepare("SELECT NAMASP FROM T_SUPLIER WHERE KODESP = :k");
        $stmt_nama->execute([':k' => $kodesp]);
        $r = $stmt_nama->fetch(PDO::FETCH_ASSOC);
        $_SESSION['namasp'] = $r && !empty($r['NAMASP']) ? trim($r['NAMASP']) : 'Supplier';
    } catch (PDOException $e) { $_SESSION['namasp'] = 'Supplier'; }
}

$today = date('Y-m-d');
function format_pcs($v){ return number_format((float)$v, 0, ',', '.'); }

// Kinerja penjualan (pcs) - Menggunakan fungsi helper
$pcs_bulan_ini = getSoldPcsByDateRange($conn, $kodesp, date('Y-m-01'), date('Y-m-t'));
$pcs_mtd_ini = getSoldPcsByDateRange($conn, $kodesp, date('Y-m-01'), $today);
$pcs_mtd_lalu = getSoldPcsByDateRange($conn, $kodesp, date('Y-m-01', strtotime('last month')), date('Y-m-d', strtotime('last month')));
$perbandingan_mtd_persen = 0;
if ($pcs_mtd_lalu > 0) { $perbandingan_mtd_persen = (int)round((($pcs_mtd_ini - $pcs_mtd_lalu) / $pcs_mtd_lalu) * 100); }
elseif ($pcs_mtd_ini > 0) { $perbandingan_mtd_persen = 100; }

$pcs_3bulan_ini = getSoldPcsByDateRange($conn, $kodesp, date('Y-m-d', strtotime('-89 days')), $today);
$pcs_3bulan_lalu = getSoldPcsByDateRange($conn, $kodesp, date('Y-m-d', strtotime('-179 days')), date('Y-m-d', strtotime('-90 days')));
$perbandingan_3bulan_persen = 0;
if ($pcs_3bulan_lalu > 0) { $perbandingan_3bulan_persen = (int)round((($pcs_3bulan_ini - $pcs_3bulan_lalu) / $pcs_3bulan_lalu) * 100); }
elseif ($pcs_3bulan_ini > 0) { $perbandingan_3bulan_persen = 100; }

// 5 hutang terakhir
$lima_nota_terakhir = [];
try {
    $stmt_nota = $conn->prepare(
        "SELECT TOP 5 H.NONOTA, B.NOBUKTI, B.TGL, H.TOTALHTG, H.STATUS
         FROM HIS_HUTANG H
         INNER JOIN HIS_BELI B ON B.NONOTA = H.NONOTA
         WHERE B.KODESP = :k
         ORDER BY B.TGL DESC"
    );
    $stmt_nota->execute([':k' => $kodesp]);
    $lima_nota_terakhir = $stmt_nota->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Biarkan array kosong jika ada error
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Supplier</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        .store-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid var(--border); }
        .store-header .logo { max-height: 50px; }
        .store-header .info { text-align: right; font-size: 13px; color: var(--text-muted); margin-left: auto; }
        
        .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
        .main-grid { display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start; }
        @media (max-width: 992px) { .main-grid { grid-template-columns: 1fr; } }
        
        .card { opacity: 0; animation: fadeInUp 0.5s ease-out forwards; }
        .main-content .card:nth-child(2) { animation-delay: 0.1s; }
        .sidebar .card { animation-delay: 0.2s; }
        
        .stats-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); gap:16px; }
        .stat-item { background: var(--bg); border:1px solid var(--border); padding:16px; border-radius: var(--radius-md); }
        .stat-item .label { color: var(--text-muted); font-size:14px; margin-bottom:8px; display:flex; gap:8px; align-items:center; }
        .stat-item .value { font-size:24px; font-weight:700; }
        .stat-item .detail { font-size:13px; color:var(--text-muted); margin-top:8px; }
        .change.up { color: var(--green); } .change.down { color: var(--red); }
        .action-list { display:flex; flex-direction:column; gap:12px; }
        .action-link { padding:12px 16px; border-radius: var(--radius-md); display:flex; align-items:center; gap:12px; font-weight:600; text-decoration:none; background:#2a2a32; color:var(--text); border:1px solid var(--border); transition:var(--transition); }
        .action-link:hover { background:#3a3a42; border-color:#555; transform: translateY(-2px); }
        .action-link.logout { background: var(--red); color:white; }
        .nota-list { display:flex; flex-direction:column; gap:12px; }
        .nota-item { display:flex; justify-content:space-between; align-items:center; background: var(--bg); padding:12px 16px; border-radius: var(--radius-md); border:1px solid var(--border); }
        .nota-info { display:flex; flex-direction:column; }
        .nota-info .no-bukti { font-weight:700; }
        .nota-info .tanggal { font-size:13px; color:var(--text-muted); }
        .nota-status { text-align: right; }
        .nota-status .total { font-weight:700; color: var(--blue); }
        .status-badge { font-size:12px; font-weight:700; padding:4px 8px; border-radius:20px; text-transform:uppercase; margin-top: 4px; display: inline-block; }
        .status-badge.lunas { background: rgba(88,214,141,.2); color: var(--green); }
        .status-badge.belum-lunas { background: rgba(231,76,60,.2); color: var(--red); }
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
        <h1 class="text-2xl font-bold">Dashboard Supplier</h1>
        <span class="opacity-80">Selamat datang, <strong><?= htmlspecialchars($_SESSION['namasp'] ?? 'Supplier') ?></strong></span>
    </div>

    <div class="main-grid grid gap-6 lg:grid-cols-3">
        <div class="main-content grid gap-6 lg:col-span-2">
            <div class="card rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] p-5 shadow">
                <h2 class="card-title"><i class="fa-solid fa-chart-bar"></i> Kinerja Penjualan (PCS)</h2>
                <div class="stats-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4">
                        <span class="label"><i class="fa-solid fa-box"></i> Penjualan Bulan Ini</span>
                        <span class="value" class="text-var-blue"><?= format_pcs($pcs_bulan_ini) ?> <small>pcs</small></span>
                    </div>
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4">
                        <span class="label"><i class="fa-solid fa-code-compare"></i> Perbandingan MTD</span>
                        <div class="value">
                            <span class="change <?= $perbandingan_mtd_persen >= 0 ? 'up' : 'down' ?>">
                                <?= ($perbandingan_mtd_persen >= 0 ? '▲' : '▼') . ' ' . abs($perbandingan_mtd_persen) ?>%
                            </span>
                        </div>
                        <div class="detail">Bln Ini: <?= format_pcs($pcs_mtd_ini) ?> pcs · Bln Lalu: <?= format_pcs($pcs_mtd_lalu) ?> pcs</div>
                    </div>
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4">
                        <span class="label"><i class="fa-solid fa-chart-line"></i> Tren 3 Bulan</span>
                        <div class="value">
                            <span class="change <?= $perbandingan_3bulan_persen >= 0 ? 'up' : 'down' ?>">
                                <?= ($perbandingan_3bulan_persen >= 0 ? '▲' : '▼') . ' ' . abs($perbandingan_3bulan_persen) ?>%
                            </span>
                        </div>
                        <div class="detail">90 Hari Ini: <?= format_pcs($pcs_3bulan_ini) ?> pcs · 90 Hari Lalu: <?= format_pcs($pcs_3bulan_lalu) ?> pcs</div>
                    </div>
                </div>
            </div>

            <div class="card rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] p-5 shadow">
                <h2 class="card-title"><i class="fa-solid fa-file-invoice"></i> 5 Hutang Terakhir</h2>
                <div class="nota-list">
                    <?php if (empty($lima_nota_terakhir)): ?>
                        <p class="text-var-muted" style="" text-align:center;">Belum ada riwayat hutang.</p>
                    <?php else: ?>
                        <?php foreach ($lima_nota_terakhir as $nota): ?>
                            <?php $bulan_link = !empty($nota['TGL']) ? date('Y-m', strtotime($nota['TGL'])) : date('Y-m'); ?>
                            <a class="nota-item flex items-center justify-between border border-[color:var(--border)] bg-[color:var(--bg)] rounded-xl p-3" href="laporan_pembayaran_hutang.php?nonota=<?= urlencode($nota['NONOTA'] ?? '') ?>" style="text-decoration:none; color:inherit;">
                                <div class="nota-info flex items-center gap-3">
                                    <span class="no-bukti"><?= htmlspecialchars($nota['NOBUKTI']) ?></span>
                                    <span class="tanggal"><?= date('d F Y', strtotime($nota['TGL'])) ?></span>
                                </div>
                                <div class="nota-status flex items-center gap-3">
                                    <span class="total font-semibold">Rp <?= number_format($nota['TOTALHTG'], 0, ',', '.') ?></span>
                                    <span class="status-badge <?= ($nota['STATUS'] ?? 0) == 2 ? 'lunas' : 'belum-lunas' ?> inline-flex px-2 py-1 rounded-md text-xs font-semibold " style="background: <?= ($nota['STATUS'] ?? 0) == 2 ? 'var(--green)' : 'var(--red)' ?>; color: var(--bg);">
                                        <?= ($nota['STATUS'] ?? 0) == 2 ? 'Lunas' : 'Belum Lunas' ?>
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="sidebar lg:col-span-1">
            <div class="card rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] p-5 shadow">
                <h2 class="card-title"><i class="fa-solid fa-bars"></i> Menu Laporan</h2>
                <div class="action-list grid gap-3">
                    <a href="laporan_stok.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-boxes-stacked"></i> Laporan Stok Barang</a>
                    <a href="laporan_penjualan.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-chart-column"></i> Laporan Penjualan</a>
                    <a href="laporan_hutang.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-file-invoice-dollar"></i> Laporan Hutang Dagang</a>
                    <a href="laporan_pembayaran_hutang.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-receipt"></i> Rincian Pembayaran Hutang</a>
                    <a href="pengaturan_wa.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-brands fa-whatsapp"></i> Pengaturan Notifikasi WA</a>
                    <hr class="hr">
                    <a href="logout.php" class="action-link logout rounded-xl transition"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
