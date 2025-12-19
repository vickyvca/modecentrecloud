<?php
// CSV export for NiceLabel: fields for Artikel, Kode, Harga Coret, Diskon, Netto, Qty
// Usage: zebra_csv.php?kode=K1,K2&copies=2,5&round=100&explode=0
// If explode=1, rows are duplicated by qty; else a single row with qty column.

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: inline; filename="labels_'.date('YmdHis').'.csv"');

require_once 'config.php';

function column_exists(PDO $conn, $table, $column) {
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:t AND COLUMN_NAME=:c";
  $st=$conn->prepare($sql); $st->execute([':t'=>$table,':c'=>$column]); return (bool)$st->fetchColumn();
}
function pick_column(PDO $conn, $table, array $cands, $fallback=null){ foreach($cands as $c){ if(column_exists($conn,$table,$c)) return $c; } return $fallback; }
function fmt_idr($v){ if($v===null||$v==='') return '0'; return number_format((float)$v,0,',','.'); }
function round_nearest($number, $nearest) { if ($nearest <= 0) return (float)$number; return (float)round($number / $nearest) * $nearest; }

try{
  $vb_kode = pick_column($conn,'V_BARANG',[ 'KODEBRG','KODE','KODE_BARANG' ]);
  $vb_artikel = pick_column($conn,'V_BARANG',[ 'ARTIKELBRG','ARTIKEL' ], null);
  $vb_hjual = pick_column($conn,'V_BARANG',[ 'HARGAJUAL','HGJUAL','H_JUAL' ], null);
  $vb_disc  = pick_column($conn,'V_BARANG',[ 'DISKON','DISK','DISC' ], null);
  if(!$vb_kode || !$vb_hjual) throw new Exception('Skema V_BARANG tidak lengkap');
}catch(Exception $e){ echo "ERROR,","",",","",",","",",","\n"; exit; }

$raw_kode = $_GET['kode'] ?? '';
$raw_copies = $_GET['copies'] ?? '';
$round_to = (int)($_GET['round'] ?? 0);
$explode = (int)($_GET['explode'] ?? 0); // 1=duplicate rows per qty

$kode_list_raw = array_unique(array_filter(explode(',', $raw_kode)));
$copies_list_raw = array_map('intval', array_filter(explode(',', $raw_copies)));
$kode_to_copies = [];
$i=0; foreach($kode_list_raw as $k){ $c=$copies_list_raw[$i] ?? 1; if($c>0) $kode_to_copies[$k]=$c; $i++; }
$kode_to_query = array_keys($kode_to_copies);
if(empty($kode_to_query)) { echo "KODE,ARTIKEL,HARGA_CORET,DISKON,NETTO,qty\n"; exit; }

$ph = implode(',', array_fill(0, count($kode_to_query), '?'));
$sql = "SELECT v.$vb_kode AS KODEBRG, RTRIM(LTRIM(v.$vb_artikel)) AS ARTIKELBRG, TRY_CAST(v.$vb_hjual AS NUMERIC(18,2)) AS HARGA_JUAL, TRY_CAST(v.$vb_disc AS NUMERIC(18,4)) AS DISKON FROM V_BARANG v WHERE v.$vb_kode IN ($ph)";
$stmt = $conn->prepare($sql); $stmt->execute($kode_to_query); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$out = fopen('php://output','w');
// UTF-8 BOM for better Excel/NiceLabel compatibility
fputs($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, ['KODE','ARTIKEL','HARGA_CORET','DISKON','NETTO','qty']);

foreach($rows as $row){
  $kode = $row['KODEBRG'];
  $qty = $kode_to_copies[$kode] ?? 1;
  $artikel = $row['ARTIKELBRG'] ?? '';
  $hjual = (float)($row['HARGA_JUAL'] ?? 0);
  $disc = (float)($row['DISKON'] ?? 0);
  $disc_perc = ($disc > 0 && $disc <= 1) ? ($disc*100) : $disc; // percent 0..100
  $netto = $hjual * (1 - ($disc_perc/100));
  $netto_rounded = round_nearest($netto, $round_to);

  if ($explode) {
    for($j=0;$j<$qty;$j++){
      fputcsv($out, [ $kode, $artikel, fmt_idr($hjual), rtrim(rtrim(number_format($disc_perc,1,',','.'),'0'),','), fmt_idr($netto_rounded), 1 ]);
    }
  } else {
    fputcsv($out, [ $kode, $artikel, fmt_idr($hjual), rtrim(rtrim(number_format($disc_perc,1,',','.'),'0'),','), fmt_idr($netto_rounded), $qty ]);
  }
}

fclose($out);
?>

