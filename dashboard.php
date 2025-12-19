<?php
session_start();
include 'config.php';

require 'vendor/autoload.php'; // pastikan dompdf sudah diinstall melalui composer
use Dompdf\Dompdf;

if (!isset($_SESSION['nik']) || !$_SESSION['is_admin']) {
    die("Akses ditolak.");
}

function getSupplierName($conn, $kodesp) {
    $stmt = $conn->prepare("SELECT TOP 1 NAMASP FROM T_SUPLIER WHERE KODESP = ?");
    $stmt->execute([$kodesp]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['NAMASP'] : 'TIDAK DIKETAHUI';
}

$selected_tab = $_GET['tab'] ?? 'stok';
$selected_supplier = $_GET['kodesp'] ?? '001';
$selected_jenis = $_GET['kodejn'] ?? 'ALL';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$usia_operator = $_GET['usia_operator'] ?? '';
$usia_value = $_GET['usia_value'] ?? '';
$export_pdf = isset($_GET['export_pdf']);

$jenisList = $conn->query("SELECT DISTINCT KODEJN, KETJENIS FROM V_BARANG ORDER BY KODEJN")->fetchAll(PDO::FETCH_ASSOC);
$supplierList = $conn->query("SELECT KODESP, NAMASP FROM T_SUPLIER ORDER BY KODESP")->fetchAll(PDO::FETCH_ASSOC);

function hitungUmurBarang($tgl) {
    $today = new DateTime();
    $tglBeli = new DateTime($tgl);
    return $today->diff($tglBeli)->days;
}

$data = [];
if ($selected_tab === 'stok') {
    $sql = "SELECT V.*, CASE WHEN V.RETUR = 1 THEN 'YA' ELSE 'TIDAK' END AS ADA_RETUR FROM V_BARANG V WHERE V.KODESP = ?";
    $params = [$selected_supplier];

    if ($selected_jenis !== 'ALL') {
        $sql .= " AND V.KODEJN = ?";
        $params[] = $selected_jenis;
    }

    if ($usia_operator && is_numeric($usia_value)) {
        $tanggal_batas = (new DateTime())->modify("-{$usia_value} days")->format('Y-m-d');
        if ($usia_operator === 'le') {
            $sql .= " AND V.TGLBELI >= ?";
        } elseif ($usia_operator === 'ge') {
            $sql .= " AND V.TGLBELI <= ?";
        }
        $params[] = $tanggal_batas;
    }

    $sql .= " ORDER BY V.KODEJN, V.TGLBELI DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($selected_tab === 'penjualan') {
    $sql = "SELECT KODEJN, KETJENIS, NONOTA, TGL AS TGLJUAL, KODEBRG, NAMABRG, ARTIKELBRG, QTY, NETTO AS TOTAL FROM V_JUAL WHERE KODESP = ?";
    $params = [$selected_supplier];

    if ($selected_jenis !== 'ALL') {
        $sql .= " AND KODEJN = ?";
        $params[] = $selected_jenis;
    }

    if (!empty($start_date) && !empty($end_date)) {
        $sql .= " AND TGL BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }

    $sql .= " ORDER BY KODEJN, TGL DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function renderTable($data, $jenisList, $selected_tab) {
    ob_start();
    if (!empty($data)) {
        $grouped = [];
        foreach ($data as $row) {
            $grouped[$row['KODEJN']][] = $row;
        }
        foreach ($grouped as $kodejn => $rows) {
            $label = $selected_tab === 'stok' ? array_column($jenisList, 'KETJENIS', 'KODEJN')[$kodejn] ?? 'TIDAK DIKETAHUI' : $rows[0]['KETJENIS'];
            echo "<h5 class='mt-4'>Jenis: $kodejn - $label</h5>";
            echo "<table class='table table-bordered table-sm'><thead class='thead-dark'>";
            if ($selected_tab === 'stok') {
                echo "<tr><th>KODEBRG</th><th>NAMABRG</th><th>ARTIKEL</th><th>HARGA BELI</th><th>DISKON</th><th>STOK</th><th>TGL BELI</th><th>UMUR (hari)</th><th>RETUR</th></tr>";
            } else {
                echo "<tr><th>NONOTA</th><th>TGL JUAL</th><th>KODEBRG</th><th>NAMABRG</th><th>ARTIKEL</th><th>JUMLAH</th><th>TOTAL</th></tr>";
            }
            echo "</thead><tbody>";
            $total_qty = 0;
            $total_val = 0;
            foreach ($rows as $row) {
                echo "<tr>";
                if ($selected_tab === 'stok') {
                    $stok = $row['ST01'] + $row['ST02'] + $row['ST03'] + $row['ST04'];
                    $val = $stok * $row['HGBELI'];
                    $total_qty += $stok;
                    $total_val += $val;
                    echo "<td>{$row['KODEBRG']}</td><td>{$row['NAMABRG']}</td><td>{$row['ARTIKELBRG']}</td><td>" . number_format($row['HGBELI']) . "</td><td>{$row['DISC']}%</td><td>{$stok}</td><td>" . date('d-m-Y', strtotime($row['TGLBELI'])) . "</td><td>" . hitungUmurBarang($row['TGLBELI']) . "</td><td>{$row['ADA_RETUR']}</td>";
                } else {
                    $total_qty += $row['QTY'];
                    $total_val += $row['TOTAL'];
                    echo "<td>{$row['NONOTA']}</td><td>" . date('d-m-Y', strtotime($row['TGLJUAL'])) . "</td><td>{$row['KODEBRG']}</td><td>{$row['NAMABRG']}</td><td>{$row['ARTIKELBRG']}</td><td>{$row['QTY']}</td><td>" . number_format($row['TOTAL']) . "</td>";
                }
                echo "</tr>";
            }
            echo "<tr class='font-weight-bold bg-light'><td colspan='" . ($selected_tab === 'stok' ? 5 : 6) . "'>Sub Total</td><td>{$total_qty}</td><td colspan='2'>" . number_format($total_val) . "</td></tr>";
            echo "</tbody></table>";
        }
    } else {
        echo "<div class='alert alert-warning'>Tidak ada data ditemukan.</div>";
    }
    return ob_get_clean();
}

if ($export_pdf) {
    $html = renderTable($data, $jenisList, $selected_tab);
    $dompdf = new Dompdf();
    $dompdf->loadHtml('<html><body>' . $html . '</body></html>');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream("laporan_{$selected_tab}.pdf");
    exit;
}
?>
<!-- HTML Rendering -->
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <title>Dashboard Laporan</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
    <h3>Dashboard Laporan</h3>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?= $selected_tab === 'stok' ? 'active' : '' ?>" href="?tab=stok&kodesp=<?= $selected_supplier ?>&kodejn=<?= $selected_jenis ?>">Stok Barang</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $selected_tab === 'penjualan' ? 'active' : '' ?>" href="?tab=penjualan&kodesp=<?= $selected_supplier ?>&kodejn=<?= $selected_jenis ?>">Penjualan</a>
        </li>
    </ul>

    <form method="get" class="form-inline mb-3">
        <input type="hidden" name="tab" value="<?= $selected_tab ?>">
        <!-- Supplier & Jenis -->
        <label class="mr-2">Supplier:</label>
        <select name="kodesp" class="form-control mr-2">
            <?php foreach ($supplierList as $s): ?>
                <option value="<?= $s['KODESP'] ?>" <?= $selected_supplier == $s['KODESP'] ? 'selected' : '' ?>>
                    <?= $s['KODESP'] ?> - <?= $s['NAMASP'] ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($selected_tab !== 'hutang'): ?>
        <label class="mr-2">Jenis:</label>
        <select name="kodejn" class="form-control mr-2">
            <option value="ALL" <?= $selected_jenis === 'ALL' ? 'selected' : '' ?>>SEMUA</option>
            <?php foreach ($jenisList as $j): ?>
                <option value="<?= $j['KODEJN'] ?>" <?= $selected_jenis == $j['KODEJN'] ? 'selected' : '' ?>>
                    <?= $j['KODEJN'] ?> - <?= $j['KETJENIS'] ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <?php if ($selected_tab === 'penjualan'): ?>
        <label class="mr-2">Tanggal:</label>
        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="form-control mr-1">
        <span class="mr-2">s.d.</span>
        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="form-control mr-2">
        <?php endif; ?>

        <?php if ($selected_tab === 'stok'): ?>
        <label class="mr-2">Usia (hari):</label>
        <select name="usia_operator" class="form-control mr-1">
            <option value="">--Pilih--</option>
            <option value="le" <?= $usia_operator === 'le' ? 'selected' : '' ?>>>=</option>
            <option value="ge" <?= $usia_operator === 'ge' ? 'selected' : '' ?>><=</option>
        </select>
        <input type="number" name="usia_value" value="<?= htmlspecialchars($usia_value) ?>" class="form-control mr-2" placeholder="Usia">
        <?php endif; ?>

        <button class="btn btn-primary mr-2">Tampilkan</button>
        <a href="?tab=<?= $selected_tab ?>&kodesp=<?= $selected_supplier ?>&kodejn=<?= $selected_jenis ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&usia_operator=<?= $usia_operator ?>&usia_value=<?= $usia_value ?>&export_pdf=1" class="btn btn-danger">Export PDF</a>
    </form>

    <?= renderTable($data, $jenisList, $selected_tab) ?>
</body>
</html>
