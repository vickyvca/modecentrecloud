<?php
require_once 'config.php';
session_start();

// Hanya admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Akses ditolak.");
}

/* ---------- Utils ---------- */
function column_exists(PDO $conn, $table, $column) {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :tbl AND COLUMN_NAME = :col";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':tbl'=>$table, ':col'=>$column]);
    return (bool)$stmt->fetchColumn();
}
function pick_column(PDO $conn, $table, array $candidates, $fallback = null) {
    foreach ($candidates as $c) {
        if (column_exists($conn, $table, $c)) return $c;
    }
    return $fallback;
}
// Escape LIKE (% _ [ \) with ESCAPE '\'
function escape_like(string $s): string {
    return strtr($s, ['\\'=>'\\\\','%'=>'\\%','_'=>'\\_','['=>'\\[']);
}

/* ---------- Resolve kolom dari V_BARANG ---------- */
try {
    $vb_kode    = pick_column($conn, 'V_BARANG', ['KODEBRG','KODE','KODE_BARANG']);
    $vb_nama    = pick_column($conn, 'V_BARANG', ['NAMABRG','NAMA','NAMA_BARANG']);
    if (!$vb_kode || !$vb_nama) throw new Exception("Tidak menemukan kolom Kode/Nama di V_BARANG.");
    $vb_artikel = pick_column($conn, 'V_BARANG', ['ARTIKELBRG','ARTIKEL'], null);

    // Kolom stok ST00..ST04 (tanpa ST99)
    $st_cols = [];
    foreach (['ST00','ST01','ST02','ST03','ST04'] as $st) {
        $st_cols[$st] = column_exists($conn, 'V_BARANG', $st);
    }

    // Flag retur mengikuti cek_stok_sales
    $vb_retur = pick_column($conn, 'V_BARANG', ['RETUR','RETURFLAG','ADA_RETUR','STATUS_RETUR'], null);

    // UMUR stok langsung (angka) kalau ada
    $vb_umur_num = pick_column($conn, 'V_BARANG', ['UMUR','UMUR_STOK','UMURHARI'], null);

    // Atau tanggal beli terakhir (tanggal) untuk dihitung
    $vb_lastbuy_date = pick_column($conn, 'V_BARANG', [
        'TGLBELITERAKHIR','TGL_BELI_TERAKHIR','LASTBUY','LAST_BUY','LAST_PURCHASE_DATE'
    ], null);

} catch (Exception $e) {
    die("Schema error: " . htmlspecialchars($e->getMessage()));
}

/* ---------- Filters UI ---------- */
$q        = trim($_GET['q'] ?? '');
$stok_gt0 = isset($_GET['stok_gt0']) ? 1 : 0;
$retur    = $_GET['retur'] ?? '';           // '', 'ya', 'tidak'
$sort     = $_GET['sort']  ?? 'baru';       // default 'baru' (paling baru dulu)

/* ---------- Ekspresi ---------- */
$stok_total_expr_parts = [];
foreach (['ST00','ST01','ST02','ST03','ST04'] as $st) {
    if (!empty($st_cols[$st])) $stok_total_expr_parts[] = "ISNULL(v.$st,0)";
}
$stok_total_expr = $stok_total_expr_parts ? implode(' + ', $stok_total_expr_parts) : "0";

if ($vb_retur) {
    $retur_truth_expr = "CASE 
        WHEN TRY_CAST(v.$vb_retur AS INT) = 1 
          OR UPPER(LTRIM(RTRIM(CAST(v.$vb_retur AS NVARCHAR(10))))) IN ('Y','YA','YES','TRUE','1')
        THEN 'Ya' ELSE 'Tidak' END";
} else {
    $retur_truth_expr = "'Tidak'";
}

// UMUR stok (hari): prioritas numerik; kalau tidak, hitung dari tanggal beli terakhir; jika tidak ada → null
$umur_expr = null;
if ($vb_umur_num) {
    $umur_expr = "TRY_CAST(v.$vb_umur_num AS INT)";
} elseif ($vb_lastbuy_date) {
    $umur_expr = "DATEDIFF(DAY, TRY_CONVERT(date, v.$vb_lastbuy_date), CAST(GETDATE() AS date))";
}
$show_umur = $umur_expr !== null;

/* ---------- Query dasar (TOP 100) ---------- */
$limit = 100;
$sql = "
SELECT TOP $limit
    v.$vb_kode AS KODEBRG,
    RTRIM(LTRIM(v.$vb_nama)) AS NAMABRG,".
    ($vb_artikel ? " RTRIM(LTRIM(v.$vb_artikel)) AS ARTIKELBRG," : " '-' AS ARTIKELBRG,")."
    ($stok_total_expr) AS STOK_TOTAL,
    $retur_truth_expr AS STATUS_RETUR".
    ($show_umur ? ", $umur_expr AS UMUR_HARI" : "")."
FROM V_BARANG v
WHERE 1=1
";

/* ---------- Pencarian cerdas TANPA PARAM ---------- */
$order_clauses = [];
if ($q !== '') {
    $qEsc = escape_like($q);
    $pattern_code_sw = $qEsc.'%';
    $pattern_any     = '%'.$qEsc.'%';

    $sql .= " AND (v.$vb_kode LIKE " . $conn->quote($pattern_code_sw) . " ESCAPE '\\'
                   OR v.$vb_nama LIKE " . $conn->quote($pattern_any)     . " ESCAPE '\\' "
            . ($vb_artikel ? " OR v.$vb_artikel LIKE " . $conn->quote($pattern_any) . " ESCAPE '\\' " : "")
            . " OR v.$vb_kode LIKE " . $conn->quote($pattern_any)        . " ESCAPE '\\' ) ";

    if ($sort === 'relevansi') {
        // Urutkan berdasar relevansi
        $order_clauses[] = "(CASE WHEN v.$vb_kode LIKE " . $conn->quote($pattern_code_sw) . " ESCAPE '\\' THEN 1 ELSE 0 END) DESC";
        $order_clauses[] = "(CASE WHEN v.$vb_nama LIKE " . $conn->quote($pattern_any)     . " ESCAPE '\\' THEN 1 ELSE 0 END) DESC";
        if ($vb_artikel) {
            $order_clauses[] = "(CASE WHEN v.$vb_artikel LIKE " . $conn->quote($pattern_any) . " ESCAPE '\\' THEN 1 ELSE 0 END) DESC";
        }
        $order_clauses[] = "(CASE WHEN v.$vb_kode LIKE " . $conn->quote($pattern_any)     . " ESCAPE '\\' THEN 1 ELSE 0 END) DESC";
    }
}

/* ---------- Filter stok & retur ---------- */
if ($stok_gt0)           $sql .= " AND ($stok_total_expr) > 0 ";
if     ($retur==='ya')   $sql .= " AND ($retur_truth_expr) = 'Ya' ";
elseif ($retur==='tidak')$sql .= " AND ($retur_truth_expr) = 'Tidak' ";

/* ---------- ORDER BY ---------- */
if ($sort === 'relevansi' && !empty($order_clauses)) {
    $sql .= " ORDER BY " . implode(", ", $order_clauses) . ", v.$vb_kode ";
} elseif ($sort === 'kode_asc') {
    $sql .= " ORDER BY v.$vb_kode ASC ";
} elseif ($sort === 'kode_desc') {
    $sql .= " ORDER BY v.$vb_kode DESC ";
} else { 
    // 'baru' (default): jika ada UMUR_HARI → paling baru dulu (umur paling kecil),
    // kalau tidak ada kolom umur → fallback Kode DESC.
    if ($show_umur) {
        // NULLS LAST agar baris tanpa umur jatuh ke bawah
        $sql .= " ORDER BY CASE WHEN $umur_expr IS NULL THEN 1 ELSE 0 END ASC, $umur_expr ASC ";
    } else {
        $sql .= " ORDER BY v.$vb_kode DESC ";
    }
}

/* ---------- Eksekusi ---------- */
try {
    $stmt = $conn->query($sql); // tanpa params -> bebas HY093
} catch (Exception $e) {
    die("Query error: " . htmlspecialchars($e->getMessage()));
}
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

:root{
  --brand:#b30000; --bg:#0f0f0f; --card:#1b1b1b; --muted:#aaa; --border:#2a2a2a; --text:#f3f3f3;
}
*{box-sizing:border-box}
body{margin:0; font-family:system-ui,Arial,sans-serif; background:var(--bg); color:var(--text);}
.container{max-width:1280px;margin:24px auto;padding:0 16px;}
.header{display:flex; align-items:center; gap:12px; margin-bottom:12px;}
.logo{width:38px;height:38px;border-radius:10px;background:var(--brand); display:inline-flex; align-items:center; justify-content:center; color:white; font-weight:700}
h1{font-size:22px;margin:0}
.card{background:var(--card); border:1px solid var(--border); border-radius:14px; padding:12px;}
form.filters{display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:10px;}
input[type=text], select{background:#111; border:1px solid var(--border); color:var(--text); padding:10px 12px; border-radius:10px;}
input[type=text]{min-width:220px; flex:1}
label.chk{display:flex; align-items:center; gap:8px; user-select:none; font-size:14px; color:#ddd}
button,.btn{border:1px solid var(--border); background:#151515; color:var(--text); padding:10px 14px; border-radius:10px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:8px;}
button:hover,.btn:hover{border-color:#3a3a3a; background:#181818}
.btn-primary{background:var(--brand); border-color:#801010}
.btn-ghost{background:transparent}
.actions{display:flex; gap:8px; margin-left:auto}

/* Table base (tetap tabel di mobile) */
.table-wrap{width:100%; overflow-x:auto; border-radius:12px;}
table{width:100%; border-collapse:collapse; margin-top:10px; font-size:14px;}

th,td{border-bottom:1px solid var(--border); padding:10px 8px; text-align:left; vertical-align:top; background:transparent;}
th{position:sticky; top:0; background:#121212; z-index:1; font-weight:600}
.right{text-align:right}
.muted{color:var(--muted)}
.badge{padding:2px 10px; border-radius:999px; font-size:12px; border:1px solid var(--border);}
.badge-ya{background:#0f2a15; color:#8df0a8; border-color:#214a28}
.badge-tidak{background:#2a1515; color:#f2a2a2; border-color:#4a2121}
.hint{font-size:12px; color:var(--muted)}
/* teks panjang tetap rapi */
td{word-break:break-word}
td.nowrap{white-space:nowrap}
.namabarang { font-size:14px; font-weight:600; line-height:1.3; }
.artikel    { font-size:13.5px; color:#d2d2d2; line-height:1.25; margin-top:2px; }

@media (max-width: 680px){
  .namabarang { font-size:15px; }
  .artikel    { font-size:14.5px; color:#e0e0e0; }
}


/* Mobile tweaks: tetap tabel, hanya diperkecil & bisa scroll */
@media (max-width: 680px){
  table{font-size:13px; min-width:640px;}
  th,td{padding:8px 6px}
  .actions{flex-wrap:wrap}
}

/* Print */
@media print{
  .no-print{display:none !important}
  body{background:white;color:black}
  .card{border:none}
  th{background:#eee}
}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="logo">MC</div>
    <div>
      <h1>Persediaan Barang (Admin)</h1>
      <div class="hint">
        Default: <b>Terbaru dulu</b> (butuh kolom umur/tgl beli). Jika tidak tersedia, urut Kode Z→A. Max 100 hasil.
      </div>
    </div>
    <div class="actions no-print" style="margin-left:auto">
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

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:100px">Kode</th>
            <th>Nama & Artikel</th>
            <th class="right" style="width:50px">Stok</th>
            <th style="width:70px">Retur</th>
            <?php if ($show_umur): ?>
              <th class="right" style="width:50px">Umur</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php
          $total_rows = 0;
          while($row = $stmt->fetch(PDO::FETCH_ASSOC)):
            $total_rows++;
            $artikel = (!empty($row['ARTIKELBRG']) && $row['ARTIKELBRG']!=='-') ? $row['ARTIKELBRG'] : '-';
          ?>
          <tr>
            <td class="nowrap"><?= htmlspecialchars($row['KODEBRG']) ?></td>
			<td>
			<div class="namabarang"><?= htmlspecialchars($row['NAMABRG']) ?></div>
			<?php if ($artikel !== '-') : ?>
			<div class="artikel"><?= htmlspecialchars($artikel) ?></div>
			<?php endif; ?>
			</td>
            <td class="right nowrap"><?= number_format((int)$row['STOK_TOTAL']) ?></td>
            <td>
              <?php if ($row['STATUS_RETUR'] === 'Ya'): ?>
                <span class="badge badge-ya">Ya</span>
              <?php else: ?>
                <span class="badge badge-tidak">Tidak</span>
              <?php endif; ?>
            </td>
            <?php if ($show_umur): ?>
              <td class="right nowrap">
                <?= is_null($row['UMUR_HARI']) ? '-' : number_format((int)$row['UMUR_HARI']) ?>
              </td>
            <?php endif; ?>
          </tr>
          <?php endwhile; ?>
          <?php if ($total_rows === 0): ?>
            <tr><td colspan="<?= $show_umur ? 6 : 5 ?>" class="muted">Tidak ada data dengan filter saat ini.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>
