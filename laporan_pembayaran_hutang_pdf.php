<?php
require_once 'config.php';
require_once 'vendor/autoload.php';
use Dompdf\Dompdf;

session_start();
if (!isset($_SESSION['kodesp']) || $_SESSION['role'] !== 'supplier') {
    die("Akses ditolak.");
}

// (1) Ambil filter dari URL
$kodesp = $_SESSION['kodesp'];
$tgl1 = $_GET['tgl1'] ?? date('Y-m-01');
$tgl2 = $_GET['tgl2'] ?? date('Y-m-d');

// (2) Eksekusi query untuk mengambil SEMUA data sesuai filter
$stmt = $conn->prepare("
    SELECT *, (SELECT NILAI FROM HIS_DTBAYARHUTANG D WHERE D.NONOTA = B.NONOTA AND D.JENIS = 1 AND D.ID = B.ID) AS KREDIT, (SELECT NILAI FROM HIS_DTBAYARHUTANG D WHERE D.NONOTA = B.NONOTA AND D.JENIS <> 1 AND D.ID = B.ID) AS DEBET FROM ( SELECT D.ID, H.NONOTA, H.NOBUKTI, NAMASP, K.KET AS JENISTRANS, H.TGL, D.TGL AS TGLJT, D.JENIS, D.NOTABAYAR FROM HIS_BAYARHUTANG H INNER JOIN HIS_DTBAYARHUTANG D ON D.NONOTA = H.NONOTA INNER JOIN T_KETHUTANG K ON K.JENIS = D.JENIS INNER JOIN T_SUPLIER S ON S.KODESP = H.KODESP WHERE H.KODESP = :kodesp AND H.TGL BETWEEN :tgl1 AND :tgl2 ) AS B ORDER BY NONOTA, TGL
");
$stmt->execute(['kodesp' => $kodesp, 'tgl1' => $tgl1, 'tgl2' => $tgl2]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($rows as $row) {
    $grouped[$row['NONOTA']]['nobukti'] = $row['NOBUKTI'];
    $grouped[$row['NONOTA']]['supplier'] = $row['NAMASP'];
    $grouped[$row['NONOTA']]['tanggal'] = $row['TGL'];
    $grouped[$row['NONOTA']]['rows'][] = $row;
}

// (3) Siapkan HTML khusus untuk PDF
ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Pembayaran Hutang</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10pt; color: #333; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 18pt; }
        .info { margin-bottom: 20px; font-size: 10pt; }
        .group-title {
            font-size: 11pt; font-weight: bold; padding: 8px;
            background-color: #f2f2f2; border: 1px solid #ccc;
            margin-top: 20px;
        }
        table { width: 100%; border-collapse: collapse; margin-top: 5px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .text-right { text-align: right; }
        .total-row td { font-weight: bold; background-color: #f2f2f2; }
    </style>
</head>
<body>
    <div class="header">
        <h1>LAPORAN PEMBAYARAN HUTANG</h1>
        <p>PERIODE: <?= date('d F Y', strtotime($tgl1)) ?> - <?= date('d F Y', strtotime($tgl2)) ?></p>
    </div>
    <div class="info">
        <strong>Supplier:</strong> <?= htmlspecialchars($kodesp) ?>
    </div>

    <?php if (empty($grouped)): ?>
        <p>Tidak ada data pembayaran hutang pada periode ini.</p>
    <?php else: ?>
        <?php foreach ($grouped as $nota => $group): ?>
            <div class="group-title">
                <?= htmlspecialchars($nota) ?> / <?= htmlspecialchars($group['nobukti']) ?> // <?= htmlspecialchars(strtoupper($group['supplier'])) ?> // <?= date('d/m/Y', strtotime($group['tanggal'])) ?>
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="width:5%;">No</th>
                        <th style="width:35%;">Keterangan</th>
                        <th>No Ref</th>
                        <th>Tanggal</th>
                        <th class="text-right">Debet</th>
                        <th class="text-right">Kredit</th>
                    </tr>
                </thead>
                <tbody>
                <?php $no = 1; $total_debet = 0; $total_kredit = 0; foreach ($group['rows'] as $row): $total_debet += $row['DEBET'] ?? 0; $total_kredit += $row['KREDIT'] ?? 0; ?>
                    <tr>
                        <td><?= $no++ ?></td>
                        <td><?= htmlspecialchars($row['JENISTRANS']) ?></td>
                        <td><?= htmlspecialchars($row['NOTABAYAR']) ?></td>
                        <td><?= !empty($row['TGLJT']) ? date('d/m/Y', strtotime($row['TGLJT'])) : '-' ?></td>
                        <td class="text-right"><?= number_format($row['DEBET'] ?? 0, 0, ',', '.') ?></td>
                        <td class="text-right"><?= number_format($row['KREDIT'] ?? 0, 0, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="4" class="text-right"><strong>TOTAL BAYAR:</strong></td>
                    <td class="text-right"><strong><?= number_format($total_debet ?? 0, 0, ',', '.') ?></strong></td>
                    <td class="text-right"><strong><?= number_format($total_kredit ?? 0, 0, ',', '.') ?></strong></td>
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
$pdf = new Dompdf(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true]);
$pdf->loadHtml($html);
$pdf->setPaper('A4', 'portrait');
$pdf->render();
$pdf->stream("laporan_pembayaran_hutang_".date('Ymd').".pdf", ["Attachment" => false]);
exit;
?>