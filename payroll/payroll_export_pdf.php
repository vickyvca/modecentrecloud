<?php
session_start();
require_once __DIR__.'/payroll_lib.php';
if (!is_admin()) { die('Akses ditolak.'); }
if (!$PAYROLL_PDF_ENABLE) { die('Export PDF dimatikan. Pakai tombol Print di browser.'); }
$ym = $_GET['bulan'] ?? ym_now();
$payroll = load_payroll_json($ym);

require_once __DIR__.'/vendor/autoload.php';
use Mpdf\Mpdf;
$mpdf = new Mpdf(['format'=>'A4']);
ob_start();
?>
<html><head><meta charset="utf-8"><style>
body{font-family:dejavu sans, sans-serif;font-size:12px}
.table{width:100%;border-collapse:collapse}
.table th,.table td{border:1px solid #ccc;padding:6px}
</style></head><body>
<?php foreach($payroll['items'] as $it): ?>
  <h3>Slip Gaji • <?=$PAYROLL_BRAND_NAME?> • <?=$ym?></h3>
  <table class="table">
    <tr><th style="width:40%">NIK</th><td><?=$it['nik']?></td></tr>
    <tr><th>Nama</th><td><?=htmlspecialchars($it['nama'])?></td></tr>
    <tr><th>Jabatan</th><td><?=htmlspecialchars($it['jabatan'])?></td></tr>
    <tr><th>Penjualan</th><td>Rp <?=fmt_idr($it['penjualan'])?></td></tr>
    <tr><th>Komisi (1%)</th><td>Rp <?=fmt_idr($it['komisi'])?></td></tr>
    <tr><th>Bonus</th><td>Rp <?=fmt_idr($it['bonus'])?></td></tr>
    <tr><th>Gaji Pokok</th><td>Rp <?=fmt_idr($it['gapok'])?></td></tr>
    <tr><th>Lembur</th><td>Rp <?=fmt_idr($it['lembur'])?></td></tr>
    <tr><th>Bonus Absensi</th><td>Rp <?=fmt_idr($it['bonus_absensi'])?></td></tr>
    <tr><th>Tunjangan Jabatan</th><td>Rp <?=fmt_idr($it['tunj_jabatan'])?></td></tr>
    <tr><th>BPJS</th><td>Rp <?=fmt_idr($it['bpjs'])?></td></tr>
    <tr><th>Potongan</th><td>- Rp <?=fmt_idr($it['potongan'])?></td></tr>
    <tr><th><b>TOTAL</b></th><td><b>Rp <?=fmt_idr($it['total'])?></b></td></tr>
  </table>
  <div style="page-break-after:always"></div>
<?php endforeach; ?>
</body></html>
<?php
$html = ob_get_clean();
$mpdf->WriteHTML($html);
$mpdf->Output('slip-'.$ym.'.pdf','I');
