<?php
require_once 'config.php';
require_once 'vendor/autoload.php';
use Dompdf\Dompdf;

session_start();
if (!isset($_SESSION['kodesp']) || $_SESSION['role'] !== 'supplier') { die("Akses ditolak"); }

// (1) Ambil filter dari URL
$kodesp = $_SESSION['kodesp'];
$kodejn = $_GET['kodejn'] ?? 'all';
$bulan = $_GET['bulan'] ?? 'this';

if ($bulan === 'this') {
    $start = date('Y-m-01');
    $end = date('Y-m-d');
} else {
    $start = date('Y-m-01', strtotime($bulan . '-01'));
    $end = date('Y-m-t', strtotime($bulan . '-01'));
}

// (2) Bangun dan eksekusi query, HILANGKAN NETTO
$filter = "KODESP = :kodesp AND TGL BETWEEN :start AND :end AND QTY > 0";
$params = ['kodesp' => $kodesp, 'start' => $start, 'end' => $end];

if ($kodejn !== 'all') {
    $filter .= " AND KODEJN = :kodejn";
    $params['kodejn'] = $kodejn;
}

$stmt = $conn->prepare("
    SELECT KODEJN, KETJENIS, KODEBRG, NAMABRG, ARTIKELBRG, SUM(QTY) AS QTY
    FROM V_JUAL
    WHERE $filter
    GROUP BY KODEJN, KETJENIS, KODEBRG, NAMABRG, ARTIKELBRG
    ORDER BY KODEJN, KODEBRG
");
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($data as $row) {
    $kj = $row['KODEJN'];
    $grouped[$kj]['jenis'] = $row['KETJENIS'] ?: "Jenis $kj";
    $grouped[$kj]['data'][] = $row;
}

// (3) Siapkan HTML khusus untuk PDF
ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Kuantitas Penjualan</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10pt; color: #333; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 18pt; }
        .info { margin-bottom: 20px; font-size: 10pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; word-wrap: break-word; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .group-title { font-size: 12pt; font-weight: bold; padding: 10px 0 5px 0; }
        .text-right { text-align: right; }
        .subtotal td, .grand-total td { font-weight: bold; background-color: #f2f2f2; }
    </style>
</head>
<body>
    <div class="header">
        <h1>LAPORAN KUANTITAS PENJUALAN</h1>
        <p>PERIODE: <?= date('d F Y', strtotime($start)) ?> - <?= date('d F Y', strtotime($end)) ?></p>
    </div>
    <div class="info">
        <strong>Supplier:</strong> <?= htmlspecialchars($kodesp) ?>
    </div>

    <?php
    $grand_total_qty = 0;
    if (empty($grouped)): ?>
        <p>Tidak ada data penjualan yang cocok dengan filter yang diberikan.</p>
    <?php else:
        foreach ($grouped as $kj => $group):
        $subtotal_qty = 0;
        ?>
        <div class="group-title">Jenis: <?= htmlspecialchars($group['jenis']) ?> (<?= htmlspecialchars($kj) ?>)</div>
        <table>
            <thead>
                <tr>
                    <th style="width:20%;">KODE BARANG</th>
                    <th style="width:45%;">NAMA BARANG</th>
                    <th style="width:20%;">ARTIKEL</th>
                    <th class="text-right" style="width:15%;">QTY TERJUAL</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($group['data'] as $row): 
                $subtotal_qty += $row['QTY'];
            ?>
                <tr>
                    <td><?= htmlspecialchars($row['KODEBRG']) ?></td>
                    <td><?= htmlspecialchars($row['NAMABRG']) ?></td>
                    <td><?= htmlspecialchars($row['ARTIKELBRG']) ?></td>
                    <td class="text-right"><?= number_format($row['QTY']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="subtotal">
                <td colspan="3" class="text-right"><strong>Subtotal Qty</strong></td>
                <td class="text-right"><strong><?= number_format($subtotal_qty) ?></strong></td>
            </tr>
            </tbody>
        </table>
    <?php 
        $grand_total_qty += $subtotal_qty;
        endforeach; ?>
    
        <br>
        <table>
            <tr class="grand-total">
                <td style="border:none; text-align:right;" colspan="3"><strong>GRAND TOTAL QTY</strong></td>
                <td class="text-right" style="width:15%;"><strong><?= number_format($grand_total_qty) ?></strong></td>
            </tr>
        </table>
    <?php endif; ?>
</body>
</html>
<?php
$html = ob_get_clean();

// (4) Generate PDF dengan Dompdf
$pdf = new Dompdf(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true]);
$pdf->loadHtml($html);
$pdf->setPaper('A4', 'portrait');
$pdf->render();
$pdf->stream("laporan_kuantitas_penjualan_".date('Ymd').".pdf", ["Attachment" => false]);
exit;
?>