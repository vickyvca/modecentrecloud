<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['kodesp']) || $_SESSION['role'] !== 'supplier') {
    die("Akses ditolak.");
}

// --- 1. PENGAMBILAN FILTER & SETUP ---
$kodesp = $_SESSION['kodesp'];
$tgl1 = $_GET['tgl1'] ?? date('Y-m-01');
$tgl2 = $_GET['tgl2'] ?? date('Y-m-d');
// Parameter opsional untuk langsung ke rincian satu nota
$requested_nonota = trim($_GET['nonota'] ?? '');
$requested_nobukti = trim($_GET['nobukti'] ?? '');

// Jika hanya diberikan nobukti, coba cari NONOTA dari HIS_BELI
$filter_nonota = '';
if ($requested_nonota !== '') {
    $filter_nonota = $requested_nonota;
} elseif ($requested_nobukti !== '') {
    try {
        $q = $conn->prepare("SELECT NONOTA FROM HIS_BELI WHERE KODESP = :k AND NOBUKTI = :nb");
        $q->execute(['k' => $kodesp, 'nb' => $requested_nobukti]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r && !empty($r['NONOTA'])) { $filter_nonota = $r['NONOTA']; }
    } catch (PDOException $e) { /* abaikan */ }
}

// --- 2. [OPTIMASI] QUERY UTAMA MENGGUNAKAN JOIN & CASE ---
$params = ['kodesp' => $kodesp];
if ($filter_nonota !== '') {
    $sql = "
        SELECT 
            H.NONOTA, 
            H.NOBUKTI, 
            S.NAMASP, 
            K.KET AS JENISTRANS, 
            H.TGL, 
            D.TGL AS TGLJT, 
            D.JENIS, 
            D.NOTABAYAR,
            CASE WHEN D.JENIS = 1 THEN D.NILAI ELSE 0 END AS KREDIT,
            CASE WHEN D.JENIS <> 1 THEN D.NILAI ELSE 0 END AS DEBET
        FROM HIS_BAYARHUTANG H
        INNER JOIN HIS_DTBAYARHUTANG D ON D.NONOTA = H.NONOTA
        INNER JOIN T_KETHUTANG K ON K.JENIS = D.JENIS
        INNER JOIN T_SUPLIER S ON S.KODESP = H.KODESP
        WHERE H.KODESP = :kodesp AND H.NONOTA = :nonota
        ORDER BY H.NONOTA, H.TGL
    ";
    $params['nonota'] = $filter_nonota;
} else {
    $sql = "
        SELECT 
            H.NONOTA, 
            H.NOBUKTI, 
            S.NAMASP, 
            K.KET AS JENISTRANS, 
            H.TGL, 
            D.TGL AS TGLJT, 
            D.JENIS, 
            D.NOTABAYAR,
            CASE WHEN D.JENIS = 1 THEN D.NILAI ELSE 0 END AS KREDIT,
            CASE WHEN D.JENIS <> 1 THEN D.NILAI ELSE 0 END AS DEBET
        FROM HIS_BAYARHUTANG H
        INNER JOIN HIS_DTBAYARHUTANG D ON D.NONOTA = H.NONOTA
        INNER JOIN T_KETHUTANG K ON K.JENIS = D.JENIS
        INNER JOIN T_SUPLIER S ON S.KODESP = H.KODESP
        WHERE H.KODESP = :kodesp AND H.TGL BETWEEN :tgl1 AND :tgl2
        ORDER BY H.NONOTA, H.TGL
    ";
    $params['tgl1'] = $tgl1; $params['tgl2'] = $tgl2;
}

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. PENGELOMPOKAN DATA DI PHP ---
$grouped = [];
foreach ($rows as $row) {
    $nota = $row['NONOTA'];
    if (!isset($grouped[$nota])) {
        $grouped[$nota] = [
            'nobukti' => $row['NOBUKTI'],
            'supplier' => $row['NAMASP'],
            'tanggal' => $row['TGL'],
            'rows' => [],
            'total_debet' => 0,
            'total_kredit' => 0,
        ];
    }
    $grouped[$nota]['rows'][] = $row;
    $grouped[$nota]['total_debet'] += $row['DEBET'] ?? 0;
    $grouped[$nota]['total_kredit'] += $row['KREDIT'] ?? 0;
}

function idr($v) { return 'Rp ' . number_format($v, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Pembayaran Hutang</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        body { background: var(--bg); color: var(--text); }
        .topbar { display:flex; flex-wrap: wrap; gap:16px; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .topbar h1 { margin: 0; }
        .actions { display: flex; gap: 8px; margin-left: auto; }
        .card { animation: fadeInUp 0.5s ease-out forwards; }
        .card.filter-card { animation-delay: 0.1s; }
        .card.report-card { animation-delay: 0.2s; }

        details.report-group { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-lg); margin-bottom: 16px; }
        details.report-group[open] { background-color: #1a1a20; }
        details.report-group.target-highlight { outline: 2px solid var(--blue); }
        summary { cursor: pointer; padding: 16px 24px; list-style: none; display: flex; align-items: center; justify-content: space-between; font-size: 1.1em; font-weight: 600; }
        summary::-webkit-details-marker { display: none; }
        .summary-info { display: flex; flex-wrap: wrap; gap: 8px 24px; font-size: 0.9em; font-weight: normal; color: var(--text-muted); }
        summary .toggle-icon { transition: transform 0.2s; color: var(--text-muted); }
        details[open] summary .toggle-icon { transform: rotate(90deg); }
        .details-content { padding: 0 24px 24px 24px; }

        .table-wrap { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
        th { background: #18181e; font-size: var(--fs-sm); text-transform: uppercase; color: var(--text-muted); }
        .right { text-align: right; }
        .total-row td { font-weight: bold; background-color: rgba(74, 74, 88, 0.2); }
        a.retur-link { color: #ff8a80; text-decoration: none; border-bottom: 1px dotted #ff8a80; }
        a.retur-link:hover { color: #ff5252; border-bottom-color: #ff5252;}

        @media print { /* Styles for printing */ }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar no-print">
        <h1><i class="fas fa-receipt"></i> Laporan Pembayaran Hutang</h1>
        <div class="actions">
            <a href="dashboard_supplier.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali</a>
            <button onclick="window.print()" class="btn"><i class="fas fa-print"></i> Cetak</button>
        </div>
    </div>

    <div class="card filter-card no-print">
        <form method="get" class="filters">
            <label for="tgl1" class="muted">Rentang Tanggal:</label>
            <input type="date" id="tgl1" name="tgl1" value="<?= htmlspecialchars($tgl1) ?>">
            <span class="muted">s/d</span>
            <input type="date" name="tgl2" value="<?= htmlspecialchars($tgl2) ?>">
            <button class="btn btn-primary" type="submit" style="margin-left: auto;">Tampilkan</button>
        </form>
    </div>

    <div class="report-content">
        <?php if (empty($grouped)): ?>
            <div class="card">
                <p style="text-align: center; color: var(--text-muted); padding: var(--space-3) 0;">
                    <i class="fas fa-box-open" style="font-size: 2em; margin-bottom: 8px;"></i><br>
                    Tidak ada data pembayaran pada rentang tanggal yang dipilih.
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $nota => $group): 
                $is_target = ($filter_nonota !== '' && $nota === $filter_nonota);
            ?>
            <details class="report-group<?= $is_target ? ' target-highlight' : '' ?>" <?= $is_target ? 'id="target-nonota" open' : 'open' ?>>
                <summary>
                    <div>
                        <span>No. Nota: <?= htmlspecialchars($nota) ?></span>
                        <div class="summary-info">
                            <span><strong>No. Bukti:</strong> <?= htmlspecialchars($group['nobukti']) ?></span>
                            <span><strong>Tanggal:</strong> <?= !empty($group['tanggal']) ? date('d/m/Y', strtotime($group['tanggal'])) : '-' ?></span>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right toggle-icon"></i>
                </summary>
                <div class="details-content">
                    <div class="table-wrap table-card">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width:5%;">No</th>
                                    <th>Keterangan</th>
                                    <th>No. Ref</th>
                                    <th>Tanggal</th>
                                    <th class="right">Debet</th>
                                    <th class="right">Kredit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($group['rows'] as $row): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= htmlspecialchars($row['JENISTRANS']) ?></td>
                                    <td>
                                        <?php if ($row['JENIS'] == 2): ?>
                                            <a class="retur-link" href="#" onclick="event.preventDefault(); loadRetur('<?= htmlspecialchars($row['NOTABAYAR']) ?>')"><?= htmlspecialchars($row['NOTABAYAR']) ?></a>
                                        <?php else: echo htmlspecialchars($row['NOTABAYAR']); endif; ?>
                                    </td>
                                    <td><?= !empty($row['TGLJT']) ? date('d/m/Y', strtotime($row['TGLJT'])) : '-' ?></td>
                                    <td class="right"><?= idr($row['DEBET'] ?? 0) ?></td>
                                    <td class="right"><?= idr($row['KREDIT'] ?? 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="total-row">
                                    <td colspan="4" class="right"><strong>TOTAL BAYAR:</strong></td>
                                    <td class="right"><strong><?= idr($group['total_debet'] ?? 0) ?></strong></td>
                                    <td class="right"><strong><?= idr($group['total_kredit'] ?? 0) ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </details>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="retur-detail" class="card" style="display:none; animation-delay: 0.3s;">
        <h3><i class="fas fa-undo-alt"></i> Detail Retur: <span id="retur-nota"></span></h3>
        <div class="table-wrap table-card">
            <table id="retur-table"></table>
        </div>
    </div>
</div>

<script>
// Jika datang dengan nonota tertentu, scroll ke group target
(function(){
  var el = document.getElementById('target-nonota');
  if (!el) return;
  el.scrollIntoView({ behavior: 'smooth', block: 'start' });
})();
function loadRetur(nobukti) {
    const box = document.getElementById("retur-detail");
    const tag = document.getElementById("retur-nota");
    const table = document.getElementById("retur-table");
    
    tag.innerText = 'Memuat...';
    box.style.display = "block";
    table.innerHTML = `<tbody><tr><td class="muted" style="text-align:center;">Mengambil data...</td></tr></tbody>`;

    fetch("get_retur.php?nobukti=" + encodeURIComponent(nobukti))
    .then(res => {
        if (!res.ok) { throw new Error('Network response was not ok'); }
        return res.json();
    })
    .then(data => {
        tag.innerText = nobukti;
        let tableHTML = `<thead><tr><th>Barang</th><th class="right">Harga Beli</th><th class="right">Qty</th><th class="right">Nilai</th></tr></thead><tbody>`;
        if (!data || data.length === 0) {
            tableHTML += `<tr><td colspan='4' class="muted" style="text-align:center;">Tidak ada data detail untuk retur ini.</td></tr>`;
        } else {
            data.forEach(row => {
                tableHTML += `<tr>
                    <td>${row.BARANG}</td>
                    <td class="right">${idr(row.HGBELI)}</td>
                    <td class="right">${pcs(row.TTL)}</td>
                    <td class="right">${idr(row.NILAI)}</td>
                </tr>`;
            });
        }
        tableHTML += `</tbody>`;
        table.innerHTML = tableHTML;
        box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    })
    .catch(error => {
        console.error('Error fetching retur details:', error);
        table.innerHTML = `<tbody><tr><td colspan="4" style="text-align:center; color: var(--red);">Gagal memuat detail retur.</td></tr></tbody>`;
    });

    // Helper sederhana untuk format di JS
    function idr(val) { return 'Rp ' + Number(val).toLocaleString('id-ID'); }
    function pcs(val) { return Number(val).toLocaleString('id-ID'); }
}
</script>
</body>
</html>
