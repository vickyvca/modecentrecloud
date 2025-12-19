<?php
session_start();
require_once __DIR__.'/payroll_lib.php';
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','pegawai'])) {
    die('Akses ditolak.');
}
$ym = $_GET['bulan'] ?? ym_now();
$payroll = load_payroll_json($ym);
?>
<!doctype html>
<html>
<head>
    <link rel="stylesheet" href="../assets/css/ui.css">
    <script defer src="../assets/js/ui.js"></script>
<meta charset="utf-8">
<title>Preview Slip • <?=$ym?></title>
<style>
<?=payroll_dark_css()?>
.slip{background:#fff;color:#000;border:1px solid #ddd;border-radius:10px;margin:12px auto;padding:18px;max-width:210mm}
.slip h2{margin:0 0 8px}
.row{display:flex;justify-content:space-between;margin:6px 0}
.hr{border-top:1px dashed #999;margin:10px 0}
.right{text-align:right}
.meta{font-size:13px;color:#333}
.titlebar{display:flex;justify-content:space-between;align-items:center}
</style>
</head>
<body>
  <div class="header noprint">
    <div class="brand"><?=$PAYROLL_BRAND_NAME?> • Preview Slip Bulan <?=$ym?></div>
    <a class="btn" href="/dashboard_admin.php">Kembali Dashboard</a>
    <button class="btn" onclick="window.print()">Print / Simpan PDF</button>
  </div>

  <div class="print-a4">
    <?php foreach($payroll['items'] as $it): ?>
    <div class="slip">
      <div class="titlebar">
        <h2>Slip Gaji Pegawai</h2>
        <div class="meta">Bulan: <?=$ym?></div>
      </div>
      <div class="hr"></div>
      <div class="row"><div>NIK</div><div class="right"><?=htmlspecialchars($it['nik'])?></div></div>
      <div class="row"><div>Nama</div><div class="right"><?=htmlspecialchars($it['nama'])?></div></div>
      <div class="row"><div>Jabatan</div><div class="right"><?=htmlspecialchars($it['jabatan'])?></div></div>

      <div class="row"><div>Penjualan (V_JUAL.NETTO)</div><div class="right">Rp <?=fmt_idr($it['penjualan'])?></div></div>
      <div class="row"><div>Komisi (1%)</div><div class="right">Rp <?=fmt_idr($it['komisi'])?></div></div>
      <div class="row"><div>Bonus (Level <?=$it['level']? $it['level'].'%':'-';?>)</div><div class="right">Rp <?=fmt_idr($it['bonus'])?></div></div>

      <div class="hr"></div>
      <div class="row"><div>Gaji Pokok</div><div class="right">Rp <?=fmt_idr($it['gapok'])?></div></div>
      <div class="row"><div>Lembur</div><div class="right">Rp <?=fmt_idr($it['lembur'])?></div></div>
      <div class="row"><div>Bonus Absensi</div><div class="right">Rp <?=fmt_idr($it['bonus_absensi'])?></div></div>
      <div class="row"><div>Tunjangan Jabatan</div><div class="right">Rp <?=fmt_idr($it['tunj_jabatan'])?></div></div>
      <div class="row"><div>Tunjangan BPJS</div><div class="right">Rp <?=fmt_idr($it['tunj_bpjs'])?></div></div>
      <div class="row"><div>Potongan</div><div class="right">- Rp <?=fmt_idr($it['potongan'])?></div></div>

      <div class="hr"></div>
      <div class="row" style="font-weight:700"><div>TOTAL DITERIMA</div><div class="right">Rp <?=fmt_idr($it['total'])?></div></div>

      <?php if(!empty($it['catatan'])): ?>
      <div class="hr"></div>
      <div class="row"><div>Catatan</div><div class="right"><?=htmlspecialchars($it['catatan'])?></div></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</body>
</html>
