<?php
require_once 'config.php';
require_once 'vendor/autoload.php';
use Dompdf\Dompdf;

session_start();
if (!isset($_SESSION['kodesp']) || $_SESSION['role'] !== 'supplier') {
    die("Akses ditolak");
}

$kodesp = $_SESSION['kodesp'];
$kodejn = $_GET['kodejn'] ?? 'all';
$bulan = $_GET['bulan'] ?? date('Y-m');
$isPdf = isset($_GET['pdf']);

// Menggunakan 'this' sebagai nilai default jika bulan sekarang, untuk konsistensi
if ($bulan === date('Y-m')) {
    $bulan = 'this';
}

if ($bulan === 'this') {
    $start = date('Y-m-01');
    $end = date('Y-m-d');
} else {
    $start = date('Y-m-01', strtotime($bulan . '-01'));
    $end = date('Y-m-t', strtotime($bulan . '-01'));
}

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
    if (!isset($grouped[$kj])) {
        $grouped[$kj]['jenis'] = $row['KETJENIS'] ?: "Jenis $kj";
        $grouped[$kj]['data'] = [];
    }
    $grouped[$kj]['data'][] = $row;
}

$jenis_list_stmt = $conn->prepare("SELECT DISTINCT KODEJN, KETJENIS FROM V_JUAL WHERE KODESP = :kodesp ORDER BY KETJENIS");
$jenis_list_stmt->execute(['kodesp' => $kodesp]);
$jenis_list = $jenis_list_stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Penjualan per Jenis</title>
    <style>
    :root {
      --brand-bg: #d32f2f; --brand-text: #ffffff; --bg: #121218; --card: #1e1e24; --text: #e0e0e0;
      --text-muted: #8b8b9a; --border: #33333d; --border-hover: #4a4a58; --focus-ring: #5a93ff;
      --row-hover: rgba(74, 74, 88, 0.2); --row-even: rgba(0,0,0,0.15);
      --fs-body: clamp(14px, 1.1vw, 15.5px); --fs-sm: clamp(12.5px, 0.95vw, 14px);
      --fs-h1: clamp(22px, 2.6vw, 28px); --fs-h2: clamp(18px, 1.8vw, 22px);
      --space-1: clamp(8px, 0.8vw, 12px); --space-2: clamp(12px, 1.4vw, 18px);
      --space-3: clamp(18px, 2vw, 24px);
      --radius-sm: 8px; --radius-md: 12px; --radius-lg: 16px;
      --transition: all 0.2s cubic-bezier(0.165, 0.84, 0.44, 1);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: var(--bg); color: var(--text); font-size: var(--fs-body); line-height: 1.6;
    }
    .container { max-width: min(1320px, 96vw); margin: var(--space-3) auto; padding: 0 var(--space-2); }
    h1 { font-size: var(--fs-h1); line-height: 1.2; margin: 0; font-weight: 700; }
    h4.group-title {
        font-size: var(--fs-h2); color: var(--brand-bg); margin-top: var(--space-3); margin-bottom: var(--space-1);
        padding-bottom: var(--space-1); border-bottom: 1px solid var(--border);
    }
    .header { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin-bottom: var(--space-2); }
    .logo {
      width: 42px; height: 42px; border-radius: var(--radius-sm); background: var(--brand-bg); display: inline-flex;
      align-items: center; justify-content: center; color: var(--brand-text); font-weight: 700; font-size: 1.2em;
    }
    .card {
      background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-lg);
      padding: var(--space-2) var(--space-3); box-shadow: 0 4px 20px rgba(0,0,0,0.1); margin-bottom: var(--space-3);
    }
    .actions { display: flex; gap: var(--space-1); margin-left: auto; }
    form.filters {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: var(--space-1); align-items: center;
    }
    select {
      background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 10px 14px;
      border-radius: var(--radius-sm); font-size: var(--fs-body); transition: var(--transition); outline: none;
    }
    select:hover { border-color: var(--border-hover); }
    select:focus-visible { border-color: var(--focus-ring); box-shadow: 0 0 0 3px rgba(90, 147, 255, 0.3); }
    .btn {
      border: 1px solid var(--border); background: #2a2a32; color: var(--text); padding: 10px 16px;
      border-radius: var(--radius-sm); cursor: pointer; text-decoration: none; display: inline-flex;
      align-items: center; gap: 8px; font-size: var(--fs-body); font-weight: 500; transition: var(--transition);
    }
    .btn:hover { border-color: var(--border-hover); background: #33333d; transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
    .btn:active { transform: translateY(0); box-shadow: none; }
    .btn-primary { background: var(--brand-bg); border-color: transparent; color: var(--brand-text); }
    .btn-primary:hover { background: #e53935; border-color: transparent; }
    .btn-secondary { background-color: #004d40; border-color: transparent; color: var(--brand-text); }
    .btn-secondary:hover { background-color: #00695c; }

    .table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: var(--radius-md); }
    table { width: 100%; border-collapse: collapse; font-size: var(--fs-body); min-width: 700px; }
    th, td { padding: 10px 12px; text-align: left; vertical-align: middle; border-bottom: 1px solid var(--border); }
    th { position: sticky; top: 0; background: #18181e; z-index: 1; font-weight: 600; font-size: var(--fs-sm); text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); }
    tbody tr:hover { background-color: var(--row-hover); }
    tbody tr:last-child td { border-bottom: none; }
    .right { text-align: right; }
    .subtotal td { text-align: right !important; font-weight: bold; background-color: rgba(74, 74, 88, 0.2); color: var(--brand-text); }

    @media (max-width: 768px) {
      .actions { width: 100%; margin-left: 0; justify-content: flex-start; }
      .header { justify-content: space-between; }
      .header > div:nth-child(2) { flex-grow: 1; }
      th, td { padding: 8px 6px; }
      .card { padding: var(--space-2); }
    }
    @media print {
      body { background: white; color: black; font-size: 9pt; }
      .container, .card { box-shadow: none; border: none; padding: 0; background: transparent; }
      .no-print { display: none; }
      h1, h4.group-title { color: black; }
      th { background-color: #f2f2f2; }
      td, th { border: 1px solid #ccc; }
      .table-wrap { overflow: visible; border: none; }
      .subtotal td { background-color: #f2f2f2; }
    }
    </style>
</head>
<body>
<div class="container">
  <div class="store-header">
    <img src="assets/img/modecentre.png" alt="Mode Centre" class="logo">
    <div class="info">
      <div class="addr">Jl Pemuda Komp Ruko Pemuda 13 - 21 Banjarnegara Jawa Tengah</div>
      <div class="contact">Contact: 0813-9983-9777</div>
    </div>
  </div>
  <div class="grid grid-auto justify-end mb-2">
    <a href="dashboard_supplier.php" class="btn btn-secondary">Kembali ke Dashboard</a>
  </div>
</div>
<div class="container">
    <div class="header no-print">
        <div class="logo">SP</div>
        <div>
            <h1>Laporan Penjualan per Jenis</h1>
        </div>
        <div class="actions">
            <a href="dashboard_supplier.php" class="btn">Kembali</a>
        </div>
    </div>

    <?php if (!$isPdf): ?>
    <div class="card no-print">
        <form method="get" class="filters">
            <select name="bulan">
                <option value="this" <?= $bulan === 'this' ? 'selected' : '' ?>>Bulan Ini</option>
                <?php for ($i = 0; $i < 12; $i++):
                    $b = date('Y-m', strtotime("-$i month")); ?>
                    <option value="<?= $b ?>" <?= $bulan === $b ? 'selected' : '' ?>><?= date('F Y', strtotime($b . '-01')) ?></option>
                <?php endfor; ?>
            </select>
            <select name="kodejn">
                <option value="all" <?= $kodejn === 'all' ? 'selected' : '' ?>>Semua Jenis</option>
                <?php foreach ($jenis_list as $g): ?>
                    <option value="<?= htmlspecialchars($g['KODEJN']) ?>" <?= $kodejn === $g['KODEJN'] ? 'selected' : '' ?>><?= htmlspecialchars($g['KETJENIS']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Tampilkan</button>
            <a class="btn btn-secondary" href="laporan_penjualan_pdf.php?<?= http_build_query($_GET) ?>" target="_blank">📄 PDF</a>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <?php if (empty($grouped)): ?>
            <p>Tidak ada data penjualan yang cocok dengan filter yang diberikan.</p>
        <?php else:
            foreach ($grouped as $kj => $group):
            $subtotal = 0; ?>
            <h4 class="group-title"><?= htmlspecialchars($group['jenis']) ?> (<?= htmlspecialchars($kj) ?>)</h4>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>KODE BARANG</th>
                            <th>NAMA BARANG</th>
                            <th>ARTIKEL</th>
                            <th class="right">QTY</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group['data'] as $row): 
                        $subtotal += $row['QTY']; ?>
                        <tr>
                            <td><?= htmlspecialchars($row['KODEBRG']) ?></td>
                            <td><?= htmlspecialchars($row['NAMABRG']) ?></td>
                            <td><?= htmlspecialchars($row['ARTIKELBRG']) ?></td>
                            <td class="right"><?= $row['QTY'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="subtotal">
                        <td colspan="3"><strong>Subtotal QTY</strong></td>
                        <td class="right"><strong><?= $subtotal ?></strong></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
  if (window.enableAutoPaging) { enableAutoPaging({ pageSize: 25 }); }
});
</script>
</body>
</html>
<?php
$html = ob_get_clean();
if ($isPdf) {
    $pdf = new Dompdf();
    $pdf->loadHtml($html);
    $pdf->setPaper('A4', 'landscape');
    $pdf->render();
    $pdf->stream("laporan_penjualan_per_jenis.pdf", ["Attachment" => false]);
    exit;
} else {
    echo $html;
}
?>
