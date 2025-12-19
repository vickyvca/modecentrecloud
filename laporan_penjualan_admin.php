<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { die("Akses ditolak."); }

// --- 1. AMBIL FILTER DARI URL ---
$tgl1 = $_GET['tgl1'] ?? date('Y-m-01');
$tgl2 = $_GET['tgl2'] ?? date('Y-m-d');
$kodesp = $_GET['kodesp'] ?? 'all';
$kodejn = $_GET['kodejn'] ?? 'all';

// --- 2. AMBIL DATA UNTUK DROPDOWN FILTER ---
$suppliers = $conn->query("SELECT KODESP, NAMASP FROM T_SUPLIER ORDER BY KODESP ASC")->fetchAll(PDO::FETCH_ASSOC);
$item_types = $conn->query("SELECT DISTINCT KODEJN, KETJENIS FROM V_JUAL WHERE KETJENIS IS NOT NULL AND KETJENIS <> '' ORDER BY KODEJN ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- 3. BANGUN DAN EKSEKUSI QUERY UTAMA ---
$filter = "TGL BETWEEN :tgl1 AND :tgl2";
$params = ['tgl1' => $tgl1, 'tgl2' => $tgl2];

if ($kodesp !== 'all') {
    $filter .= " AND KODESP = :kodesp";
    $params['kodesp'] = $kodesp;
}
if ($kodejn !== 'all') {
    $filter .= " AND KODEJN = :kodejn";
    $params['kodejn'] = $kodejn;
}

$stmt = $conn->prepare("
    SELECT KODESP, NAMASP, KODEJN, KETJENIS, KODEBRG, NAMABRG, ARTIKELBRG, SUM(QTY) AS TOTAL_QTY, SUM(NETTO) AS TOTAL_NETTO
    FROM V_JUAL
    WHERE $filter
    GROUP BY KODESP, NAMASP, KODEJN, KETJENIS, KODEBRG, NAMABRG, ARTIKELBRG
    HAVING SUM(QTY) > 0
    ORDER BY NAMASP, KETJENIS, NAMABRG
");
$stmt->execute($params);

// --- 4. [DIUBAH] KELOMPOKKAN DATA SAMBIL LOOPING (LEBIH EFISIEN) ---
$grouped_data = [];
$grand_total_qty = 0;
$grand_total_netto = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $sp_key = $row['KODESP'];
    $jn_key = $row['KODEJN'];

    if (!isset($grouped_data[$sp_key])) {
        $grouped_data[$sp_key] = [
            'supplier_name' => $row['NAMASP'],
            'jenis' => [],
            'total_qty' => 0,
            'total_netto' => 0
        ];
    }
    if (!isset($grouped_data[$sp_key]['jenis'][$jn_key])) {
        $grouped_data[$sp_key]['jenis'][$jn_key] = [
            'jenis_name' => $row['KETJENIS'] ?: "Lainnya",
            'items' => [],
            'subtotal_qty' => 0,
            'subtotal_netto' => 0
        ];
    }
    
    $grouped_data[$sp_key]['jenis'][$jn_key]['items'][] = $row;
    $grouped_data[$sp_key]['jenis'][$jn_key]['subtotal_qty'] += $row['TOTAL_QTY'];
    $grouped_data[$sp_key]['jenis'][$jn_key]['subtotal_netto'] += $row['TOTAL_NETTO'];
    $grouped_data[$sp_key]['total_qty'] += $row['TOTAL_QTY'];
    $grouped_data[$sp_key]['total_netto'] += $row['TOTAL_NETTO'];

    $grand_total_qty += $row['TOTAL_QTY'];
    $grand_total_netto += $row['TOTAL_NETTO'];
}

function idr($v) { return 'Rp ' . number_format($v, 0, ',', '.'); }
function pcs($v) { return number_format($v, 0, ',', '.') . ' pcs'; }

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Penjualan per Supplier/Jenis</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script defer src="assets/js/ui.js"></script>
    <style>
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        body { background: var(--bg); }
        .topbar { display:flex; flex-wrap: wrap; gap:16px; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .topbar h1 { margin: 0; }
        .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--space-1); align-items: center; flex-grow: 1; }
        .actions { display: flex; gap: var(--space-1); }
        
        .card { animation: fadeInUp 0.5s ease-out forwards; }
        .card:nth-of-type(2) { animation-delay: 0.1s; }
        
        /* [BARU] Styling untuk Accordion/Collapsible */
        details { border-bottom: 1px solid var(--border); margin-bottom: var(--space-2); }
        details[open] { padding-bottom: var(--space-2); }
        summary { cursor: pointer; padding: var(--space-2) 0; list-style: none; display: flex; align-items: center; justify-content: space-between; }
        summary::-webkit-details-marker { display: none; }
        summary:focus { outline: none; }
        .supplier-header { font-size: var(--fs-h1); color: var(--brand-bg); }
        .supplier-summary { font-size: var(--fs-body); color: var(--text-muted); }
        summary .toggle-icon { transition: transform 0.2s; }
        details[open] summary .toggle-icon { transform: rotate(90deg); }

        .jenis-header { font-size: var(--fs-h2); color: var(--text-muted); margin: var(--space-2) 0 var(--space-1) 0; padding-left: var(--space-2); border-left: 3px solid var(--border); }
        .table-wrap { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 700px; }
        th, td { padding: 10px 12px; text-align: left; vertical-align: middle; border-bottom: 1px solid var(--border); }
        th { background: #18181e; font-size: var(--fs-sm); text-transform: uppercase; color: var(--text-muted); }
        .right { text-align: right; }
        .subtotal td { font-weight: bold; background-color: rgba(74, 74, 88, 0.2); }
        
        .grand-total { margin-top: var(--space-3); padding: var(--space-2); background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-md); text-align: right; }
        .grand-total span { font-size: var(--fs-sm); color: var(--text-muted); }
        .grand-total strong { font-size: var(--fs-h2); color: var(--brand-bg); display: block; }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <h1>Laporan Penjualan</h1>
        <div class="actions">
            <a href="dashboard_admin.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali</a>
            <button onclick="window.print()" class="btn"><i class="fas fa-print"></i> Cetak</button>
        </div>
    </div>

    <div class="card filter-card no-print">
        <form method="get" class="filters">
            <input type="date" name="tgl1" value="<?= htmlspecialchars($tgl1) ?>" title="Tanggal Mulai">
            <input type="date" name="tgl2" value="<?= htmlspecialchars($tgl2) ?>" title="Tanggal Selesai">
            <select name="kodesp" title="Supplier">
                <option value="all">Semua Supplier</option>
                <?php foreach ($suppliers as $sp): ?>
                    <option value="<?= htmlspecialchars($sp['KODESP']) ?>" <?= $kodesp === $sp['KODESP'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sp['KODESP']) ?> - <?= htmlspecialchars($sp['NAMASP']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="kodejn" title="Jenis Barang">
                <option value="all">Semua Jenis</option>
                <?php foreach ($item_types as $jt): ?>
                    <option value="<?= htmlspecialchars($jt['KODEJN']) ?>" <?= $kodejn === $jt['KODEJN'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($jt['KODEJN']) ?> - <?= htmlspecialchars($jt['KETJENIS']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Tampilkan</button>
        </form>
    </div>

    <div class="card report-card">
        <?php if (empty($grouped_data)): ?>
            <p style="text-align: center; color: var(--text-muted); padding: var(--space-3) 0;">
                <i class="fas fa-box-open" style="font-size: 2em; margin-bottom: 8px;"></i><br>
                Tidak ada data penjualan yang cocok dengan filter yang diberikan.
            </p>
        <?php else: ?>
            <?php foreach ($grouped_data as $sp_data): ?>
                <details open>
                    <summary>
                        <div>
                            <div class="supplier-header"><?= htmlspecialchars($sp_data['supplier_name']) ?></div>
                            <div class="supplier-summary">
                                Total Penjualan: <strong><?= idr($sp_data['total_netto']) ?></strong> (<?= pcs($sp_data['total_qty']) ?>)
                            </div>
                        </div>
                        <i class="fas fa-chevron-right toggle-icon"></i>
                    </summary>
                    <div class="details-content">
                        <?php foreach ($sp_data['jenis'] as $jn_data): ?>
                            <h3 class="jenis-header"><?= htmlspecialchars($jn_data['jenis_name']) ?></h3>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>KODE</th>
                                            <th>NAMA BARANG</th>
                                            <th>ARTIKEL</th>
                                            <th class="right">QTY</th>
                                            <th class="right">NETTO</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($jn_data['items'] as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['KODEBRG']) ?></td>
                                            <td><?= htmlspecialchars($item['NAMABRG']) ?></td>
                                            <td><?= htmlspecialchars($item['ARTIKELBRG']) ?></td>
                                            <td class="right"><?= pcs($item['TOTAL_QTY']) ?></td>
                                            <td class="right"><?= idr($item['TOTAL_NETTO']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="subtotal">
                                        <td colspan="3"><strong>Subtotal Jenis</strong></td>
                                        <td class="right"><strong><?= pcs($jn_data['subtotal_qty']) ?></strong></td>
                                        <td class="right"><strong><?= idr($jn_data['subtotal_netto']) ?></strong></td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
            
            <div class="grand-total">
                <span>Grand Total Semua Penjualan</span>
                <strong><?= idr($grand_total_netto) ?> (Total Qty: <?= pcs($grand_total_qty) ?>)</strong>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
