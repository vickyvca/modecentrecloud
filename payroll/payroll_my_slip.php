<?php
require_once __DIR__ . '/payroll_lib.php';
require_once __DIR__ . '/../config.php'; // Akses $conn dan konstanta global
session_start();

if (!isset($_SESSION['nik']) || !in_array($_SESSION['role'], ['admin','pegawai'])) {
    die('Akses ditolak.');
}
$ym = $_GET['bulan'] ?? ym_now();

$my_nik = $_SESSION['nik'] ?? null;
if(!$my_nik) die('NIK tidak ditemukan pada sesi.');

// --- LOGIKA MENDAPATKAN NAMA PEGAWAI DARI T_SALES (Database) ---
$nama_salesman = 'Nama Pegawai Tidak Ditemukan'; 
$db_jabatan = '-'; 

try {
    global $conn;
    $stmt = $conn->prepare("SELECT NAMASL, JABATAN FROM T_SALES WHERE KODESL = :nik");
    $stmt->execute(['nik' => $my_nik]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $nama_salesman = $result['NAMASL'];
        $db_jabatan = $result['JABATAN'] ?? '-'; 
    }
} catch (PDOException $e) {
    // Biarkan default jika DB error
}
// --- AKHIR LOGIKA AMBIL NAMA ---

$payroll = load_payroll_json($ym);

$item = null; foreach($payroll['items'] as $it){ if(($it['nik'] ?? '') === $my_nik){ $item=$it; break; } }
if(!$item) die('Slip belum tersedia untuk periode ini. Hubungi admin.');

// Fallback nama dan jabatan
if ($nama_salesman === 'Nama Pegawai Tidak Ditemukan' && isset($item['nama'])) { $nama_salesman = $item['nama']; }
$jabatan = $item['jabatan'] ?? $db_jabatan; 

// --- Data untuk kolom Penerimaan dan Potongan ---
// Siapkan breakdown bonus individu & kolektif (fallback jika belum ada)
$bonus_individu = (float)($item['bonus_individu'] ?? 0);
$bonus_kolektif = (float)($item['bonus_kolektif'] ?? 0);
if (($bonus_individu + $bonus_kolektif) <= 0 && isset($item['bonus'])) {
    $bonus_individu = (float)$item['bonus'];
    $bonus_kolektif = 0;
}

$penerimaan = [
    'Gaji Pokok' => $item['gapok'] ?? 0,
    'Komisi (1%)' => $item['komisi'] ?? 0,
    'Bonus Individu' => $bonus_individu,
    'Bonus Kolektif' => $bonus_kolektif,
    'Lembur' => $item['lembur'] ?? 0,
    'Bonus Absensi' => $item['bonus_absensi'] ?? 0,
    'Tunjangan Jabatan' => $item['tunj_jabatan'] ?? 0,
    'Tunjangan BPJS' => $item['tunj_bpjs'] ?? 0,
];

$potongan = [
    'Potongan' => $item['potongan'] ?? 0,
];

$total_penerimaan = array_sum($penerimaan);
$total_potongan = array_sum($potongan);
$total_bersih = $item['total'] ?? ($total_penerimaan - $total_potongan);

?>
<!doctype html>
<html>
<head>
    <link rel="stylesheet" href="../assets/css/ui.css">
    <script defer src="../assets/js/ui.js"></script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Slip Saya • <?=$ym?></title>
<style>
/* --- STYLING MODERN DAN DARK MODE BASE --- */
:root {
    --brand-color: #d32f2f; 
    --text-primary: #e0e0e0;
    --text-secondary: #8b8b9a;
    --bg-main: #121218;
    --card-bg: #1e1e24;
    --border-color: #33333d;
    --success-color: #58d68d;
    --danger-color: #e74c3c;
    --space-1: 8px;
    --space-2: 18px;
    --space-3: 24px;
    --radius-md: 12px;
}

* { box-sizing: border-box; }
body {
    margin: 0; font-family: sans-serif;
    background: var(--bg-main); color: var(--text-primary); 
    font-size: 14px; line-height: 1.6;
    padding: var(--space-2);
}

/* Header Navigasi (Hanya untuk non-print) */
.header.noprint {
    display: flex; justify-content: space-between; align-items: center; 
    padding: 10px 0; margin-bottom: var(--space-3);
}
.brand { font-size: 1.2em; font-weight: 700; color: var(--text-primary); }
.btn { 
    background: var(--brand-color); color: white; padding: 8px 15px; border: none; 
    border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 0.9em; 
    transition: background 0.2s;
}
.btn:hover { background: #b71c1c; }

/* Container Slip */
.print-a4 {
    max-width: 800px;
    margin: var(--space-3) auto;
    padding: 0;
}
.card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: var(--space-3);
    box-shadow: 0 4px 15px rgba(0,0,0,0.3);
}

/* Header Slip */
.slip-header {
    border-bottom: 2px solid var(--brand-color);
    padding-bottom: 10px;
    margin-bottom: var(--space-3);
}
.slip-header h2 {
    font-size: 1.8em;
    margin: 0;
    color: var(--text-primary);
}
.slip-header p {
    color: var(--text-secondary);
    margin: 5px 0 0 0;
    font-size: 1.1em;
}

/* Info Pegawai (NIK, Nama, Jabatan) */
.slip-info table {
    width: 100%;
    margin-bottom: var(--space-3);
    border-collapse: collapse;
}
.slip-info th, .slip-info td {
    padding: 6px 0;
    border-bottom: 1px dashed #282830;
    font-weight: normal;
}
.slip-info th {
    width: 30%;
    color: var(--text-secondary);
    font-weight: 500;
}
.slip-info td {
    font-weight: 600;
    color: var(--text-primary);
}

/* Detail Gaji Layout (Penerimaan & Potongan) */
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-3);
    margin-bottom: var(--space-3);
}
.detail-column h3 {
    margin-top: 0;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border-color);
    font-size: 1.2em;
    font-weight: 600;
}
.detail-table {
    width: 100%;
    border-collapse: collapse;
}
.detail-table td {
    padding: 8px 0;
    border-bottom: 1px dashed #282830;
}
.detail-table tr:last-child td {
    border-bottom: none;
}
.detail-table .amount {
    text-align: right;
    font-weight: 600;
    color: var(--success-color);
}
.detail-table tr:has(.amount.danger-color) .amount {
     color: var(--danger-color);
}
.detail-table tr:has(> td:first-child[style*="font-weight:700"]) .amount {
    color: var(--text-primary) !important; 
}


/* Total Section */
.total-section {
    border-top: 3px solid var(--border-color);
    padding-top: var(--space-2);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 1.5em;
    font-weight: 700;
}
.total-section .label {
    color: var(--brand-color);
}
.total-section .value {
    color: var(--success-color);
}

/* Catatan */
.slip-catatan {
    border-top: 1px solid var(--border-color);
    margin-top: var(--space-2);
    padding-top: var(--space-1);
    color: var(--text-secondary);
    font-size: 0.9em;
}

/* Print Styles */
@media print {
    body { background: white; color: black; padding: 0; }
    .card { background: white; border: none; box-shadow: none; padding: 0; color: black; }
    .slip-header { border-bottom-color: black; }
    .slip-info th, .slip-info td, .detail-table td { border-bottom-color: #aaa !important; color: black !important; }
    .slip-info th { color: #555; }
    .detail-table .amount { color: #008000 !important; }
    .detail-table tr:has(.amount.danger-color) .amount { color: #d32f2f !important; }
    .total-section .value { color: #008000 !important; }
    .total-section .label { color: black; }
    .slip-catatan { color: #555; border-top-color: #ccc; }
}

@media (max-width: 600px) {
    .detail-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="container">
  <div class="grid grid-auto justify-end mb-2">
    <a href="../dashboard_pegawai.php" class="btn btn-secondary">Kembali ke Dashboard</a>
  </div>
</div>
    <div class="header noprint">
        <div class="brand"><?= $PAYROLL_BRAND_NAME ?? 'Payroll System' ?> • Slip Saya</div>
        <div>
            <a class="btn" style="background:#555;" href="/dashboard_pegawai.php">Kembali</a>
            <button class="btn" onclick="window.print()">🖨️ Print / Simpan PDF</button>
        </div>
    </div>

    <div class="print-a4">
        <div class="card">
            <div class="slip-header">
                <h2>Slip Gaji Pegawai</h2>
                <p>Periode: **<?= date('F Y', strtotime($ym.'-01')) ?>**</p>
            </div>

            <div class="slip-info">
                <table class="table">
                    <tr><th style="width:40%">NIK</th><td><?= htmlspecialchars($my_nik) ?></td></tr>
                    <tr><th>Nama</th><td><?= htmlspecialchars($nama_salesman) ?></td></tr> 
                    <tr><th>Jabatan</th><td><?= htmlspecialchars($jabatan) ?></td></tr> 
                    <tr><th>Realisasi Penjualan</th><td>Rp <?= fmt_idr($item['penjualan'] ?? 0) ?></td></tr>
                </table>
            </div>

            <div class="detail-grid">
                <div class="detail-column">
                    <h3>Penerimaan (+)</h3>
                    <table class="detail-table">
                        <?php foreach ($penerimaan as $label => $nilai): ?>
                            <tr>
                                <td class="label"><?= htmlspecialchars($label) ?></td>
                                <td class="amount">Rp <?= fmt_idr($nilai) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="border-top: 1px solid var(--border-color); font-weight:700;">
                            <td>TOTAL</td>
                            <td class="amount">Rp <?= fmt_idr($total_penerimaan) ?></td>
                        </tr>
                    </table>
                </div>

                <div class="detail-column">
                    <h3>Potongan (-)</h3>
                    <table class="detail-table">
                        <?php foreach ($potongan as $label => $nilai): ?>
                            <tr>
                                <td class="label"><?= htmlspecialchars($label) ?></td>
                                <td class="amount danger-color">- Rp <?= fmt_idr($nilai) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="border-top: 1px solid var(--border-color); font-weight:700;">
                            <td>TOTAL</td>
                            <td class="amount danger-color">- Rp <?= fmt_idr($total_potongan) ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="total-section">
                <span class="label">GAJI BERSIH (NET PAY)</span>
                <span class="value">Rp <?= fmt_idr($total_bersih) ?></span>
            </div>

            <?php if(!empty($item['catatan'])): ?>
                <div class="slip-catatan">
                    <b>Catatan Admin:</b> <?= htmlspecialchars($item['catatan']) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
