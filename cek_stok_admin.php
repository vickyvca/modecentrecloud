<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { die("Akses ditolak."); }

/* ===== Utils ===== */
function column_exists(PDO $conn, $table, $column) {
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:t AND COLUMN_NAME=:c";
  $st=$conn->prepare($sql); $st->execute([':t'=>$table,':c'=>$column]); return (bool)$st->fetchColumn();
}
function pick_column(PDO $conn, $table, array $cands, $fallback=null){
  foreach($cands as $c){ if(column_exists($conn,$table,$c)) return $c; } return $fallback;
}
function escape_like(string $s): string {
  return strtr($s,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_','['=>'\\[']);
}
function fmt_idr($v){ if($v===null||$v==='') return '-'; return number_format((float)$v,0,',','.'); }
function fmt_disc($v){
  if($v===null||$v==='') return '-';
  $v=(float)$v; if($v>0 && $v<=1) $v*=100;
  return rtrim(rtrim(number_format($v,1,',','.'),'0'),',').'%';
}

/* ===== Resolve schema (V_BARANG) ===== */
try{
  $vb_kode = pick_column($conn,'V_BARANG',['KODEBRG','KODE','KODE_BARANG']);
  $vb_nama = pick_column($conn,'V_BARANG',['NAMABRG','NAMA','NAMA_BARANG']);
  if(!$vb_kode||!$vb_nama) throw new Exception("Tidak menemukan kolom Kode/Nama di V_BARANG.");
  $vb_artikel = pick_column($conn,'V_BARANG',['ARTIKELBRG','ARTIKEL'],null);

  $st_cols=[]; foreach(['ST00','ST01','ST02','ST03','ST04'] as $st){ $st_cols[$st]=column_exists($conn,'V_BARANG',$st); }

  $vb_retur = pick_column($conn,'V_BARANG',['RETUR','RETURFLAG','ADA_RETUR','STATUS_RETUR'],null);
  $vb_umur_num = pick_column($conn,'V_BARANG',['UMUR','UMUR_STOK','UMURHARI'],null);
  $vb_lastbuy_date = pick_column($conn,'V_BARANG',['TGLBELITERAKHIR','TGL_BELI_TERAKHIR','LASTBUY','LAST_BUY','LAST_PURCHASE_DATE'],null);

  $vb_hbeli = pick_column($conn,'V_BARANG',['HARGABELI','HGBELI','H_BELI'],null);
  $vb_hjual = pick_column($conn,'V_BARANG',['HARGAJUAL','HGJUAL','H_JUAL'],null);
  $vb_disc  = pick_column($conn,'V_BARANG',['DISKON','DISK','DISC'],null);

}catch(Exception $e){ die("Schema error: ".htmlspecialchars($e->getMessage())); }

/* ===== Filters ===== */
$q = trim($_GET['q'] ?? '');
$stok_gt0 = isset($_GET['stok_gt0']) ? 1 : 0;
$retur    = $_GET['retur'] ?? '';         // '', 'ya', 'tidak'
$sort     = $_GET['sort']  ?? 'baru';      // baru|relevansi|kode_asc|kode_desc

/* ===== Expressions ===== */
$stok_total_expr_parts=[]; foreach(['ST00','ST01','ST02','ST03','ST04'] as $st){ if(!empty($st_cols[$st])) $stok_total_expr_parts[]="ISNULL(v.$st,0)"; }
$stok_total_expr = $stok_total_expr_parts ? implode(' + ',$stok_total_expr_parts) : "0";

$retur_truth_expr = $vb_retur
 ? "CASE WHEN TRY_CAST(v.$vb_retur AS INT)=1 OR UPPER(LTRIM(RTRIM(CAST(v.$vb_retur AS NVARCHAR(10))))) IN ('Y','YA','YES','TRUE','1') THEN 'Ya' ELSE 'Tidak' END"
 : "'Tidak'";

$umur_expr = null;
if($vb_umur_num) $umur_expr="TRY_CAST(v.$vb_umur_num AS INT)";
elseif($vb_lastbuy_date) $umur_expr="DATEDIFF(DAY, TRY_CONVERT(date, v.$vb_lastbuy_date), CAST(GETDATE() AS date))";
$show_umur = $umur_expr !== null;

/* ===== Query (TOP 100) ===== */
$limit = 100;
$sql = "
SELECT TOP $limit
    v.$vb_kode AS KODEBRG,
    RTRIM(LTRIM(v.$vb_nama)) AS NAMABRG,".
    ($vb_artikel ? " RTRIM(LTRIM(v.$vb_artikel)) AS ARTIKELBRG," : " '-' AS ARTIKELBRG,").
    "($stok_total_expr) AS STOK_TOTAL,". // DITAMBAHKAN KEMBALI
    "$retur_truth_expr AS STATUS_RETUR".
    ($show_umur ? ", $umur_expr AS UMUR_HARI" : "").",".
    ($vb_hbeli ? "TRY_CAST(v.$vb_hbeli AS NUMERIC(18,2)) AS HARGA_BELI," : "NULL AS HARGA_BELI,").
    ($vb_hjual ? "TRY_CAST(v.$vb_hjual AS NUMERIC(18,2)) AS HARGA_JUAL," : "NULL AS HARGA_JUAL,").
    ($vb_disc  ? "TRY_CAST(v.$vb_disc  AS NUMERIC(18,4)) AS DISKON"      : "NULL AS DISKON")."
FROM V_BARANG v
WHERE 1=1
";

// ... sisa kode PHP tidak ada perubahan ...
$order_clauses=[];
if($q!==''){
  $qEsc=escape_like($q);
  $p_sw =$qEsc.'%';
  $p_any='%'.$qEsc.'%';
  $sql.=" AND (v.$vb_kode LIKE ".$conn->quote($p_sw)." ESCAPE '\\'
               OR v.$vb_nama LIKE ".$conn->quote($p_any)." ESCAPE '\\' ".
               ($vb_artikel?" OR v.$vb_artikel LIKE ".$conn->quote($p_any)." ESCAPE '\\' ":'')."
               OR v.$vb_kode LIKE ".$conn->quote($p_any)." ESCAPE '\\')";
  if($sort==='relevansi'){
    $order_clauses[]="(CASE WHEN v.$vb_kode LIKE ".$conn->quote($p_sw)."  ESCAPE '\\' THEN 1 ELSE 0 END) DESC";
    $order_clauses[]="(CASE WHEN v.$vb_nama LIKE ".$conn->quote($p_any)." ESCAPE '\\' THEN 1 ELSE 0 END) DESC";
    if($vb_artikel) $order_clauses[]="(CASE WHEN v.$vb_artikel LIKE ".$conn->quote($p_any)." ESCAPE '\\' THEN 1 ELSE 0 END) DESC";
    $order_clauses[]="(CASE WHEN v.$vb_kode LIKE ".$conn->quote($p_any)." ESCAPE '\\' THEN 1 ELSE 0 END) DESC";
  }
}
if($stok_gt0) $sql.=" AND ($stok_total_expr) > 0 ";
if($retur==='ya') $sql.=" AND ($retur_truth_expr) = 'Ya' ";
elseif($retur==='tidak') $sql.=" AND ($retur_truth_expr) = 'Tidak' ";

if($sort==='relevansi' && !empty($order_clauses)) $sql.=" ORDER BY ".implode(", ",$order_clauses).", v.$vb_kode ";
elseif($sort==='kode_asc') $sql.=" ORDER BY v.$vb_kode ASC ";
elseif($sort==='kode_desc') $sql.=" ORDER BY v.$vb_kode DESC ";
else { if($show_umur) $sql.=" ORDER BY CASE WHEN $umur_expr IS NULL THEN 1 ELSE 0 END ASC, $umur_expr ASC "; else $sql.=" ORDER BY v.$vb_kode DESC "; }

try{ $stmt=$conn->query($sql); }catch(Exception $e){ die("Query error: ".htmlspecialchars($e->getMessage())); }
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
<meta charset="utf-8">
<title>Persediaan Barang (Admin)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
/* ... CSS tidak ada perubahan ... */
:root {
  --brand-bg: #d32f2f; --brand-text: #ffffff; --bg: #121218; --card: #1e1e24; --text: #e0e0e0;
  --text-muted: #8b8b9a; --border: #33333d; --border-hover: #4a4a58; --focus-ring: #5a93ff;
  --row-hover: rgba(74, 74, 88, 0.2); --row-even: rgba(0,0,0,0.15);
  --fs-body: clamp(14px, 1.1vw, 15.5px); --fs-sm: clamp(12.5px, 0.95vw, 14px);
  --fs-h1: clamp(22px, 2.6vw, 28px); --space-1: clamp(8px, 0.8vw, 12px);
  --space-2: clamp(12px, 1.4vw, 18px); --space-3: clamp(18px, 2vw, 24px);
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
.header { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin-bottom: var(--space-2); }
.logo {
  width: 42px; height: 42px; border-radius: var(--radius-sm); background: var(--brand-bg); display: inline-flex;
  align-items: center; justify-content: center; color: var(--brand-text); font-weight: 700; font-size: 1.2em;
}
.hint { font-size: var(--fs-sm); color: var(--text-muted); }
.card {
  background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-lg);
  padding: var(--space-2) var(--space-3); box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}
.actions { display: flex; gap: var(--space-1); margin-left: auto; }
form.filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-1); align-items: center; margin-bottom: var(--space-2); }
input[type=text], select {
  background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 10px 14px;
  border-radius: var(--radius-sm); font-size: var(--fs-body); transition: var(--transition); outline: none;
}
input[type=text]:hover, select:hover { border-color: var(--border-hover); }
input[type=text]:focus-visible, select:focus-visible { border-color: var(--focus-ring); box-shadow: 0 0 0 3px rgba(90, 147, 255, 0.3); }
label.chk { display: flex; align-items: center; gap: 8px; user-select: none; font-size: var(--fs-sm); color: #ddd; cursor: pointer; }
button, .btn {
  border: 1px solid var(--border); background: #2a2a32; color: var(--text); padding: 10px 16px;
  border-radius: var(--radius-sm); cursor: pointer; text-decoration: none; display: inline-flex;
  align-items: center; gap: 8px; font-size: var(--fs-body); font-weight: 500; transition: var(--transition);
}
button:hover, .btn:hover { border-color: var(--border-hover); background: #33333d; transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
button:active, .btn:active { transform: translateY(0); box-shadow: none; }
.btn-primary { background: var(--brand-bg); border-color: transparent; color: var(--brand-text); }
.btn-primary:hover { background: #e53935; border-color: transparent; }
.btn-ghost { background: transparent; border-color: var(--border); }
.btn-ghost:hover { background: var(--row-hover); }
.table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border: 1px solid var(--border); border-radius: var(--radius-md); }
table { width: 100%; border-collapse: collapse; font-size: var(--fs-body); min-width: 900px; }
th, td { padding: 12px 14px; text-align: left; vertical-align: middle; border-bottom: 1px solid var(--border); }
th { position: sticky; top: 0; background: #18181e; z-index: 1; font-weight: 600; font-size: var(--fs-sm); text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); }
tbody tr { transition: background-color 0.15s ease-in-out; }
tbody tr:hover { background-color: var(--row-hover); }
tbody tr:nth-child(even) { background-color: var(--row-even); }
tbody tr:last-child td { border-bottom: none; }
td { word-break:normal; overflow-wrap:break-word; hyphens:auto; }
.namabarang { font-weight: 600; line-height: 1.4; margin: 0; }
.artikel { font-size: var(--fs-sm); color: var(--text-muted); line-height: 1.3; margin: 0; }
td.nowrap { white-space: nowrap; }
.right { text-align: right; }
.muted { color: var(--text-muted); text-align: center; padding: 40px; }
.badge { padding: 4px 12px; border-radius: 999px; font-size: var(--fs-sm); font-weight: 500; border: 1px solid transparent; }
.badge-ok { background: rgba(46, 204, 113, 0.18); color: #58d68d; }
.badge-warn { background: rgba(231, 76, 60, 0.18); color: #e74c3c; }

@media (max-width: 768px) {
  .data-row { cursor: pointer; }
}

.modal-overlay {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(5px);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000; opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
}
.modal-overlay.active { opacity: 1; pointer-events: auto; }
.modal-content {
  background: var(--card); padding: var(--space-3); border-radius: var(--radius-lg);
  border: 1px solid var(--border); width: min(500px, 90vw);
  box-shadow: 0 10px 30px rgba(0,0,0,0.3); transform: scale(0.95); transition: transform 0.3s ease;
}
.modal-overlay.active .modal-content { transform: scale(1); }
.modal-header {
  display: flex; justify-content: space-between; align-items: center;
  border-bottom: 1px solid var(--border); padding-bottom: var(--space-2); margin-bottom: var(--space-2);
}
.modal-header h2 { font-size: var(--fs-h1); margin: 0; line-height: 1.3; }
.modal-close {
  background: transparent; border: none; color: var(--text-muted);
  font-size: 28px; cursor: pointer; line-height: 1;
}
.modal-body .detail-item {
  display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #2a2a32;
}
.modal-body .detail-item:last-child { border-bottom: none; }
.modal-body .detail-item .label { color: var(--text-muted); font-size: var(--fs-sm); }
.modal-body .detail-item .value { font-weight: 600; text-align: right; }
.hidden { display: none; }
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
    <a href="dashboard_admin.php" class="btn btn-secondary">Kembali ke Dashboard</a>
  </div>
</div>
<div class="container">
  <div class="header">
    <div class="logo">MC</div>
    <div>
      <h1>Persediaan Barang (Admin)</h1>
      <div class="hint">Default: <b>Terbaru dulu</b> (pakai umur/tgl beli bila ada). Jika tidak, fallback Kode Z→A. Max 100 hasil.</div>
    </div>
    <div class="actions no-print">
      <a href="dashboard_admin.php" class="btn">Kembali ke Dashboard</a>
      <button class="btn btn-primary" onclick="window.print()">Print / PDF</button>
    </div>
  </div>

  <div class="card">
    <form method="get" class="filters no-print">
      <input type="text" name="q" placeholder="Cari KODE / Nama / Artikel..." value="<?=htmlspecialchars($q)?>">
      <label class="chk"><input type="checkbox" name="stok_gt0" value="1" <?= $stok_gt0 ? 'checked' : '' ?>> Hanya stok &gt; 0</label>
      <select name="retur" title="Filter status retur">
        <option value="" <?= $retur===''?'selected':'' ?>>Semua Status Retur</option>
        <option value="ya" <?= $retur==='ya'?'selected':'' ?>>Retur: Ya</option>
        <option value="tidak" <?= $retur==='tidak'?'selected':'' ?>>Retur: Tidak</option>
      </select>
      <select name="sort" title="Urutkan">
        <option value="baru" <?= $sort==='baru'?'selected':'' ?>>Terbaru dulu</option>
        <option value="relevansi" <?= $sort==='relevansi'?'selected':'' ?>>Relevansi</option>
        <option value="kode_asc" <?= $sort==='kode_asc'?'selected':'' ?>>Kode A→Z</option>
        <option value="kode_desc" <?= $sort==='kode_desc'?'selected':'' ?>>Kode Z→A</option>
      </select>
      <button type="submit">Terapkan</button>
      <a class="btn btn-ghost" href="cek_stok_admin.php">Reset</a>
    </form>
    
<div class="table-wrap table-card">
      <table class="table">
        <thead>
          <tr>
            <th style="width:130px">Kode</th>
            <th>Nama &amp; Artikel</th>
            <th style="width:110px" class="right">Stok Total</th> <th style="width:120px" class="right">Hg Beli</th>
            <th style="width:120px" class="right">Hg Jual</th>
            <th style="width:90px" class="right">Disc</th>
            <th style="width:110px" class="right">Umur (hari)</th>
            <th style="width:110px">Status Retur</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $total_rows=0;
          while($row=$stmt->fetch(PDO::FETCH_ASSOC)):
            $total_rows++;
            $stok    = number_format((int)$row['STOK_TOTAL']); // DITAMBAHKAN KEMBALI
            $artikel = (!empty($row['ARTIKELBRG']) && $row['ARTIKELBRG']!=='-') ? $row['ARTIKELBRG'] : '-';
            $hb      = fmt_idr($row['HARGA_BELI']);
            $hj      = fmt_idr($row['HARGA_JUAL']);
            $dc      = fmt_disc($row['DISKON']);
            $umur    = $show_umur ? (is_null($row['UMUR_HARI'])?'-':number_format((int)$row['UMUR_HARI'],0,',','.')) : '-';
            $isRetur = ($row['STATUS_RETUR']==='Ya');
          ?>
          <tr class="data-row">
            <td class="data-kode nowrap"><?= htmlspecialchars($row['KODEBRG']) ?></td>
            <td class="data-nama">
              <div class="namabarang"><?= htmlspecialchars($row['NAMABRG']) ?></div>
              <?php if ($artikel !== '-'): ?><div class="artikel"><?= htmlspecialchars($artikel) ?></div><?php endif; ?>
            </td>
            <td class="data-stok nowrap right"><?= $stok ?></td> <td class="data-hbeli nowrap right"><?= $hb ?></td>
            <td class="data-hjual nowrap right"><?= $hj ?></td>
            <td class="data-disc nowrap right"><?= $dc ?></td>
            <td class="data-umur right nowrap"><?= $umur ?></td>
            <td class="data-retur">
              <?php if ($isRetur): ?>
                   <span class="badge badge-warn">Ya</span>
              <?php else: ?>
                   <span class="badge badge-ok">Tidak</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; ?>
          <?php if ($total_rows===0): ?>
            <tr><td colspan="8" class="muted">Tidak ada data dengan filter saat ini.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="detailModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-header">
      <h2 id="modalNamaBrg">Nama Barang</h2>
      <button id="modalCloseBtn" class="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="detail-item">
        <span class="label">Kode</span>
        <span class="value" id="modalKode"></span>
      </div>
       <div class="detail-item">
        <span class="label">Artikel</span>
        <span class="value" id="modalArtikel"></span>
      </div>
      <div class="detail-item">
        <span class="label">Stok Total</span> <span class="value" id="modalStok"></span>
      </div>
      <div class="detail-item">
        <span class="label">Harga Beli</span>
        <span class="value" id="modalHbeli"></span>
      </div>
      <div class="detail-item">
        <span class="label">Harga Jual</span>
        <span class="value" id="modalHjual"></span>
      </div>
      <div class="detail-item">
        <span class="label">Diskon</span>
        <span class="value" id="modalDisc"></span>
      </div>
      <div class="detail-item">
        <span class="label">Umur Stok (hari)</span>
        <span class="value" id="modalUmur"></span>
      </div>
      <div class="detail-item">
        <span class="label">Status Retur</span>
        <span class="value" id="modalRetur"></span>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.innerWidth > 768) {
        return;
    }
    
    const modal = document.getElementById('detailModal');
    const modalCloseBtn = document.getElementById('modalCloseBtn');
    const dataRows = document.querySelectorAll('.data-row');

    function showModal() {
        modal.classList.add('active');
    }

    function hideModal() {
        modal.classList.remove('active');
    }

    dataRows.forEach(row => {
        row.addEventListener('click', function() {
            // Ambil data dari setiap sel di baris yang diklik
            const nama = this.querySelector('.namabarang').textContent;
            const artikel = this.querySelector('.artikel') ? this.querySelector('.artikel').textContent : '-';
            const kode = this.querySelector('.data-kode').textContent;
            const stok = this.querySelector('.data-stok').textContent; // DITAMBAHKAN KEMBALI
            const hbeli = this.querySelector('.data-hbeli').textContent;
            const hjual = this.querySelector('.data-hjual').textContent;
            const disc = this.querySelector('.data-disc').textContent;
            const umur = this.querySelector('.data-umur').textContent;
            
            // Masukkan data ke dalam modal
            document.getElementById('modalNamaBrg').textContent = nama;
            document.getElementById('modalKode').textContent = kode;
            document.getElementById('modalArtikel').textContent = artikel;
            document.getElementById('modalStok').textContent = stok; // DITAMBAHKAN KEMBALI
            document.getElementById('modalHbeli').textContent = hbeli;
            document.getElementById('modalHjual').textContent = hjual;
            document.getElementById('modalDisc').textContent = disc;
            document.getElementById('modalUmur').textContent = umur;
            document.getElementById('modalRetur').innerHTML = this.querySelector('.data-retur').innerHTML;

            showModal();
        });
    });

    modalCloseBtn.addEventListener('click', hideModal);

    modal.addEventListener('click', function(event) {
        if (event.target === modal) {
            hideModal();
        }
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
  if (window.enableAutoPaging) { enableAutoPaging({ pageSize: 25 }); }
});
</script>
</body>
</html>
