<?php
require_once 'config.php';
require_once 'vendor/autoload.php';
use Dompdf\Dompdf;

session_start();
if (!isset($_SESSION['nik']) || $_SESSION['role'] !== 'pegawai') {
    die("Akses ditolak.");
}

// --- 1. PENGAMBILAN DATA ---
$nik = $_SESSION['nik'];
$bulan = $_GET['bulan'] ?? 'this';
$isPdf = isset($_GET['pdf']);

if ($bulan === 'this') {
    $start = date('Y-m-01');
    $end = date('Y-m-d');
} else {
    $start = date('Y-m-01', strtotime($bulan . '-01'));
    $end = date('Y-m-t', strtotime($bulan . '-01'));
}

$stmt = $conn->prepare("
    SELECT KODEJN, KETJENIS, KODEBRG, NAMABRG, ARTIKELBRG, QTY, NETTO
    FROM V_JUAL
    WHERE KODESL = :nik AND TGL BETWEEN :start AND :end
    ORDER BY KODEJN, KODEBRG
");
$stmt->execute(['nik' => $nik, 'start' => $start, 'end' => $end]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 2. [REFAKTOR] PENGELOMPOKAN DATA & PERHITUNGAN SUBTOTAL ---
$grouped = [];
$grand_total_qty = 0;
$grand_total_netto = 0;

foreach ($data as $row) {
    $kj = $row['KODEJN'];
    if (!isset($grouped[$kj])) {
        $grouped[$kj] = [
            'jenis' => $row['KETJENIS'] ?: "Jenis $kj",
            'data' => [],
            'subtotal_qty' => 0,
            'subtotal_netto' => 0,
        ];
    }
    $grouped[$kj]['data'][] = $row;
    $grouped[$kj]['subtotal_qty'] += $row['QTY'];
    $grouped[$kj]['subtotal_netto'] += $row['NETTO'];
    
    $grand_total_qty += $row['QTY'];
    $grand_total_netto += $row['NETTO'];
}

function idr($v) { return 'Rp ' . number_format($v, 0, ',', '.'); }
function pcs($v) { return number_format($v, 0, ',', '.') . ' pcs'; }

ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Penjualan Pegawai</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        body { background: var(--bg); color: var(--text); }
        .topbar { display:flex; flex-wrap: wrap; gap:16px; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .topbar h1 { margin: 0; }
        form.filters { display: flex; gap: 10px; align-items: center; }
        .actions { display: flex; gap: 8px; margin-left: auto; }
        
        .card { animation: fadeInUp 0.5s ease-out forwards; }
        .card.filter-card { animation-delay: 0.1s; }
        .card.report-card { animation-delay: 0.2s; }
        
        details { border-bottom: 1px solid var(--border); }
        details:last-of-type { border-bottom: none; }
        details[open] { padding-bottom: var(--space-2); }
        summary { cursor: pointer; padding: var(--space-2) 0; list-style: none; display: flex; align-items: center; justify-content: space-between; font-size: var(--fs-h2); }
        summary::-webkit-details-marker { display: none; }
        summary .toggle-icon { transition: transform 0.2s; color: var(--text-muted); }
        details[open] summary .toggle-icon { transform: rotate(90deg); }
        
        .table-wrap { width: 100%; overflow-x: auto; border-radius: var(--radius-md); border: 1px solid var(--border); }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
        th { background: #18181e; font-size: var(--fs-sm); text-transform: uppercase; color: var(--text-muted); }
        tbody tr:hover { background-color: var(--row-hover); }
        tbody tr:last-child td { border-bottom: none; }
        .right { text-align: right; }
        .subtotal td { text-align: right !important; font-weight: bold; background-color: rgba(74, 74, 88, 0.2); }
        
        .grand-total { margin-top: var(--space-3); padding: var(--space-2); background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-md); text-align: right; }
        .grand-total span { color: var(--text-muted); }
        .grand-total strong { font-size: var(--fs-h2); color: var(--brand-bg); display: block; }

        @media print {
            body { background: white; color: black; font-size: 9pt; }
            .container, .card { box-shadow: none; border: none; padding: 0; background: transparent; }
            .no-print { display: none; }
            h1, summary { color: black; }
            th { background-color: #f2f2f2; }
            td, th { border: 1px solid #ccc; }
            .table-wrap { overflow: visible; border: none; }
            .subtotal td, .grand-total { background-color: #f2f2f2; }
            details[open] { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar no-print">
        <h1><i class="fas fa-file-invoice"></i> Laporan Penjualan Anda</h1>
        <div class="actions">
            <a href="dashboard_pegawai.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali</a>
            <a class="btn" href="?<?= http_build_query(array_merge($_GET, ['pdf' => 1])) ?>" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
            <button onclick="window.print()" class="btn"><i class="fas fa-print"></i> Cetak</button>
        </div>
    </div>

    <?php if (!$isPdf): ?>
    <div class="card filter-card no-print">
        <form method="get" class="filters">
            <select name="bulan" onchange="this.form.submit()">
                <option value="this" <?= $bulan === 'this' ? 'selected' : '' ?>>Bulan Ini (Berjalan)</option>
                <?php 
                $current_ym = date('Y-m');
                for ($i = 1; $i <= 12; $i++):
                    $b = date('Y-m', strtotime("-$i month"));
                ?>
                    <option value="<?= $b ?>" <?= $bulan === $b ? 'selected' : '' ?>><?= date('F Y', strtotime($b . '-01')) ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>
    <?php endif; ?>

    <div class="card report-card">
        <?php if (empty($grouped)): ?>
            <p style="text-align: center; color: var(--text-muted); padding: var(--space-3) 0;">
                <i class="fas fa-box-open" style="font-size: 2em; margin-bottom: 8px;"></i><br>
                Tidak ada data penjualan pada periode yang dipilih.
            </p>
        <?php else: ?>
            <?php foreach ($grouped as $kodejn => $group): ?>
                <details open>
                    <summary>
                        <span><?= htmlspecialchars($group['jenis']) ?> (<?= htmlspecialchars($kodejn) ?>)</span>
                        <i class="fas fa-chevron-right toggle-icon"></i>
                    </summary>
                    <div class="table-wrap table-card">
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
                            <?php foreach ($group['data'] as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['KODEBRG']) ?></td>
                                    <td><?= htmlspecialchars($row['NAMABRG']) ?></td>
                                    <td><?= htmlspecialchars($row['ARTIKELBRG']) ?></td>
                                    <td class="right"><?= pcs($row['QTY']) ?></td>
                                    <td class="right"><?= idr($row['NETTO']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="subtotal">
                                <td colspan="3"><strong>Subtotal</strong></td>
                                <td class="right"><strong><?= pcs($group['subtotal_qty']) ?></strong></td>
                                <td class="right"><strong><?= idr($group['subtotal_netto']) ?></strong></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endforeach; ?>

            <div class="grand-total">
                <span>Grand Total Penjualan</span>
                <strong><?= idr($grand_total_netto) ?> (Total Qty: <?= pcs($grand_total_qty) ?>)</strong>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
<?php
$html = ob_get_clean();
if ($isPdf) {
    $pdf = new Dompdf();
    $pdf->loadHtml($html);
    $pdf->setPaper('A4', 'landscape');
    $pdf->render();
    $pdf->stream("laporan_penjualan_pegawai_".$nik."_".$bulan.".pdf", ["Attachment" => false]);
    exit;
} else {
    echo $html;
}
?>