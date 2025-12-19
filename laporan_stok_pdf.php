<?php
ini_set('memory_limit', '1024M'); // Beri memori lebih untuk data besar

require_once 'config.php';
require_once 'vendor/autoload.php';
use Dompdf\Dompdf;

session_start();
if (!isset($_SESSION['kodesp']) || $_SESSION['role'] !== 'supplier') { die("Akses ditolak"); }

// (1) Ambil semua filter dari URL
$kodesp = $_SESSION['kodesp'];
$keyword = $_GET['keyword'] ?? '';
$umur_op = $_GET['umur_op'] ?? '>';
$umur_val = $_GET['umur_val'] ?? '';
$retur = $_GET['retur'] ?? 'all';
$kodejn = $_GET['kodejn'] ?? 'all';

// (2) Bangun query database untuk mengambil semua data yang cocok
$filter = "KODESP = :kodesp";
$params = ['kodesp' => $kodesp];

if ($keyword !== '') {
    $filter .= " AND (KODEBRG LIKE :kw1 OR ARTIKELBRG LIKE :kw2 OR NAMABRG LIKE :kw3)";
    $params['kw1'] = "%$keyword%";
    $params['kw2'] = "%$keyword%";
    $params['kw3'] = "%$keyword%";
}
if (is_numeric($umur_val)) {
    $filter .= " AND UMUR $umur_op :umur_val";
    $params['umur_val'] = (int)$umur_val;
}
if ($retur === '1') { $filter .= " AND RETUR = 1"; } 
elseif ($retur === '0') { $filter .= " AND (RETUR = 0 OR RETUR IS NULL)"; }
if ($kodejn !== 'all') { $filter .= " AND KODEJN = :kodejn"; $params['kodejn'] = $kodejn; }

$sql = "SELECT KODEJN, KETJENIS, KODEBRG, ARTIKELBRG, NAMABRG, UMUR, RETUR, ST00, ST01, ST02, ST03, ST04 FROM V_BARANG WHERE $filter ORDER BY KODEJN ASC, UMUR ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($data as $row) {
    $kj = $row['KODEJN'];
    if (!isset($grouped[$kj])) { $grouped[$kj] = ['jenis' => $row['KETJENIS'] ?: "Jenis $kj", 'data' => []]; }
    $grouped[$kj]['data'][] = $row;
}

// (3) Siapkan HTML khusus untuk PDF
ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Stok Barang</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 9pt; color: #333; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 16pt; }
        .header p { margin: 2px 0; font-size: 9pt; }
        .info { margin-bottom: 20px; font-size: 9pt; }
        .info-table { width: 300px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #ccc; padding: 5px; text-align: left; word-wrap: break-word; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .group-title { font-size: 11pt; font-weight: bold; padding: 10px 0 5px 0; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .subtotal td { font-weight: bold; background-color: #f2f2f2; }
    </style>
</head>
<body>
    <div class="header">
        <h1>LAPORAN UMUR BARANG</h1>
        <p>MODECENTRE GUDANG</p>
        <p>JL. DIPAYUDA NO 2, BANJARNEGARA</p>
    </div>

    <div class="info">
        <table class="info-table">
            <tr>
                <td><strong>Tanggal</strong></td>
                <td>: <?= date('d/m/Y') ?></td>
            </tr>
            <tr>
                <td><strong>Supplier</strong></td>
                <td>: <?= htmlspecialchars($kodesp) ?></td>
            </tr>
        </table>
    </div>

    <?php if (empty($grouped)): ?>
        <p>Tidak ada data stok yang cocok dengan filter yang diberikan.</p>
    <?php else:
        foreach ($grouped as $kj => $group):
        $subtotal = 0; ?>
        <div class="group-title">Jenis: <?= htmlspecialchars($group['jenis']) ?> (<?= htmlspecialchars($kj) ?>)</div>
        <table>
            <thead>
                <tr>
                    <th style="width:4%;">NO</th>
                    <th style="width:13%;">KODEBRG</th>
                    <th style="width:13%;">ARTIKEL</th> <th style="width:30%;">NAMA BARANG</th>
                    <th class="text-right" style="width:8%;">UMUR</th>
                    <th class="text-center" style="width:12%;">STATUS RETUR</th> <th class="text-right" style="width:20%;">STOK TOTAL</th>
                </tr>
            </thead>
            <tbody>
            <?php 
            $no = 1;
            foreach ($group['data'] as $row): 
                $total = $row['ST00'] + $row['ST01'] + $row['ST02'] + $row['ST03'] + $row['ST04'];
                $subtotal += $total; ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><?= htmlspecialchars($row['KODEBRG']) ?></td>
                    <td><?= htmlspecialchars($row['ARTIKELBRG']) ?></td> <td><?= htmlspecialchars($row['NAMABRG']) ?></td>
                    <td class="text-right"><?= $row['UMUR'] ?></td>
                    <td class="text-center"><?= $row['RETUR'] ? 'Ya' : 'Tidak' ?></td> <td class="text-right"><?= $total ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="subtotal">
                <td colspan="6" class="text-right"><strong>Subtotal</strong></td> <td class="text-right"><strong><?= $subtotal ?></strong></td>
            </tr>
            </tbody>
        </table>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
<?php
$html = ob_get_clean();

// (4) Generate PDF dengan Dompdf
$pdf = new Dompdf();
$pdf->loadHtml($html);
$pdf->setPaper('A4', 'portrait');
$pdf->render();
$pdf->stream("laporan_stok_".date('Ymd').".pdf", ["Attachment" => false]);
exit;
?>