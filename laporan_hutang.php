<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['kodesp']) || $_SESSION['role'] !== 'supplier') {
    die("Akses ditolak.");
}

// --- 1. PENGAMBILAN DATA ---
$kodesp = $_SESSION['kodesp'];
$bulan = $_GET['bulan'] ?? date('Y-m');
// Opsional: jika datang dari dashboard untuk sorot baris tertentu
$target_nobukti = $_GET['nobukti'] ?? '';

$stmt = $conn->prepare("
    SELECT H.NONOTA, B.TGL, B.NOBUKTI, S.KODESP, NAMASP, TGLJTO,
           CASE WHEN H.STATUS = 2 THEN 'LUNAS' ELSE 'BELUM' END AS STATUS,
           TOTALHTG, SISAHTG
    FROM HIS_HUTANG H
    INNER JOIN HIS_BELI B ON B.NONOTA = H.NONOTA
    INNER JOIN T_SUPLIER S ON S.KODESP = B.KODESP
    WHERE B.KODESP = :kodesp AND FORMAT(B.TGL, 'yyyy-MM') = :bulan
    ORDER BY B.TGL
");
$stmt->execute(['kodesp' => $kodesp, 'bulan' => $bulan]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 2. PERHITUNGAN TOTAL UNTUK RINGKASAN ---
$total_hutang = 0;
$total_sisa_hutang = 0;
$jumlah_belum_lunas = 0;

foreach ($data as $row) {
    $total_hutang += (float)$row['TOTALHTG'];
    $total_sisa_hutang += (float)$row['SISAHTG'];
    if (strtoupper($row['STATUS']) !== 'LUNAS') {
        $jumlah_belum_lunas++;
    }
}

function idr($v) { return 'Rp ' . number_format($v, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Hutang Dagang</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        body { background: var(--bg); color: var(--text); }
        .topbar { display:flex; flex-wrap: wrap; gap:16px; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .topbar h1 { margin: 0; }
        .actions { display: flex; gap: 8px; margin-left: auto; }
        
        .card { animation: fadeInUp 0.5s ease-out forwards; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .summary-card { background-color: var(--card); padding: 20px; border-radius: var(--radius-lg); border: 1px solid var(--border); animation: fadeInUp 0.5s ease-out forwards; }
        .summary-card:nth-child(1) { animation-delay: 0.1s; }
        .summary-card:nth-child(2) { animation-delay: 0.2s; }
        .summary-card:nth-child(3) { animation-delay: 0.3s; }
        .summary-card .label { display: flex; align-items: center; gap: 8px; font-size: 1em; color: var(--text-muted); margin-bottom: 8px; }
        .summary-card .value { font-size: 2em; font-weight: 700; }
        
        .table-wrap { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid var(--border); white-space: nowrap; }
        th { background: #18181e; font-size: var(--fs-sm); text-transform: uppercase; color: var(--text-muted); }
        tbody tr:hover { background-color: var(--row-hover); }
        .right { text-align: right; } .center { text-align: center; }
        
        .badge { padding: 4px 12px; border-radius: 999px; font-size: var(--fs-sm); }
        .badge-green { background: rgba(46, 204, 113, 0.18); color: #58d68d; }
        .badge-red { background: rgba(231, 76, 60, 0.18); color: #e74c3c; }
        tr.belum-lunas { background-color: rgba(231, 76, 60, 0.05); }

        /* Sorotan baris target dari dashboard */
        tr.target-highlight { outline: 2px solid var(--blue); background-color: rgba(0, 123, 255, 0.08); }

        @media print { /* Styles for printing */ }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar no-print">
        <h1><i class="fas fa-file-invoice-dollar"></i> Laporan Hutang Dagang</h1>
        <div class="actions">
            <a href="dashboard_supplier.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali</a>
            <button onclick="window.print()" class="btn"><i class="fas fa-print"></i> Cetak</button>
        </div>
    </div>

    <div class="card filter-card no-print" style="animation-delay: 0s;">
        <form method="get" class="filters">
            <label for="bulan" class="muted">Pilih Bulan:</label>
            <input type="month" id="bulan" name="bulan" value="<?= htmlspecialchars($bulan) ?>">
            <button class="btn btn-primary" type="submit">Tampilkan</button>
        </form>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <span class="label"><i class="fas fa-file-invoice"></i> Total Hutang</span>
            <span class="value" class="text-var-blue"><?= idr($total_hutang) ?></span>
        </div>
        <div class="summary-card">
            <span class="label"><i class="fas fa-money-bill-wave"></i> Total Sisa Hutang</span>
            <span class="value" style="color:var(--red);"><?= idr($total_sisa_hutang) ?></span>
        </div>
        <div class="summary-card">
            <span class="label"><i class="fas fa-exclamation-circle"></i> Nota Belum Lunas</span>
            <span class="value"><?= number_format($jumlah_belum_lunas) ?></span>
        </div>
    </div>

    <div class="card report-card" style="animation-delay: 0.4s;">
        <?php if (!empty($data)): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No Nota</th>
                            <th class="center">Tanggal</th>
                            <th>No Bukti</th>
                            <th class="center">Jatuh Tempo</th>
                            <th class="center">Status</th>
                            <th class="right">Total Hutang</th>
                            <th class="right">Sisa Hutang</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($data as $row): 
                        $is_lunas = (strtoupper($row['STATUS']) === 'LUNAS');
                        $is_target = ($target_nobukti !== '' && isset($row['NOBUKTI']) && $row['NOBUKTI'] === $target_nobukti);
                    ?>
                        <tr class="<?= !$is_lunas ? 'belum-lunas' : '' ?><?= $is_target ? ' target-highlight' : '' ?>" <?= $is_target ? 'id="target-nobukti"' : '' ?>>
                            <td><?= htmlspecialchars($row['NONOTA']) ?></td>
                            <td class="center"><?= !empty($row['TGL']) ? date('d-m-Y', strtotime($row['TGL'])) : '-' ?></td>
                            <td><?= htmlspecialchars($row['NOBUKTI']) ?></td>
                            <td class="center"><?= !empty($row['TGLJTO']) ? date('d-m-Y', strtotime($row['TGLJTO'])) : '-' ?></td>
                            <td class="center">
                                <span class="badge <?= $is_lunas ? 'badge-green' : 'badge-red' ?>">
                                    <?= $is_lunas ? 'Lunas' : 'Belum Lunas' ?>
                                </span>
                            </td>
                            <td class="right"><?= idr($row['TOTALHTG']) ?></td>
                            <td class="right"><?= idr($row['SISAHTG']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="text-align: center; color: var(--text-muted); padding: var(--space-3) 0;">
                <i class="fas fa-check-circle" style="font-size: 2em; margin-bottom: 8px;"></i><br>
                Tidak ada data hutang pada bulan yang dipilih.
            </p>
        <?php endif; ?>
    </div>
</div>
<script>
// Scroll ke baris target jika ada
(function(){
  var el = document.getElementById('target-nobukti');
  if (!el) return;
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
})();
</script>
</body>
</html>
