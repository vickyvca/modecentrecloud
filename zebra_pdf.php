<?php
// PDF generator for 3-column labels (e.g., Zebra), using TCPDF
// Accepts mm-based sizing params and lays out labels in a grid

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
require_once('tcpdf/tcpdf.php');
require_once 'config.php';

function column_exists(PDO $conn, $table, $column) {
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:t AND COLUMN_NAME=:c";
  $st=$conn->prepare($sql); $st->execute([':t'=>$table,':c'=>$column]); return (bool)$st->fetchColumn();
}
function pick_column(PDO $conn, $table, array $cands, $fallback=null){
  foreach($cands as $c){ if(column_exists($conn,$table,$c)) return $c; } return $fallback;
}
function fmt_idr($v){ if($v===null||$v==='') return '0'; return number_format((float)$v,0,',','.'); }
function fmt_disc($v){ if($v===null||$v==='') return '0%'; $v=(float)$v; if($v>0 && $v<=1) $v*=100; return rtrim(rtrim(number_format($v,1,',','.'),'0'),',').'%'; }
function round_nearest($number, $nearest) { if ($nearest <= 0) return (float)$number; return (float)round($number / $nearest) * $nearest; }

try{
  $vb_kode = pick_column($conn,'V_BARANG',[ 'KODEBRG','KODE','KODE_BARANG' ]);
  $vb_artikel = pick_column($conn,'V_BARANG',[ 'ARTIKELBRG','ARTIKEL' ], null);
  $vb_hjual = pick_column($conn,'V_BARANG',[ 'HARGAJUAL','HGJUAL','H_JUAL' ], null);
  $vb_disc  = pick_column($conn,'V_BARANG',[ 'DISKON','DISK','DISC' ], null);
  if(!$vb_kode || !$vb_hjual) throw new Exception('Skema V_BARANG tidak lengkap: Kode atau Harga Jual tidak ditemukan.');
}catch(Exception $e){ die('Schema error: '.htmlspecialchars($e->getMessage())); }

// Params
$raw_kode = $_GET['kode'] ?? '';
$raw_copies = $_GET['copies'] ?? '';
$round_to = (int)($_GET['round'] ?? 0);

// Sizing (mm)
$cols = max(1, (int)($_GET['z_cols'] ?? 3));
$label_w = (float)($_GET['label_w_mm'] ?? 40.0); // mm
$label_h = (float)($_GET['label_h_mm'] ?? 30.0); // mm
$gap_h   = (float)($_GET['gap_mm_h'] ?? 2.0);     // mm
$gap_v   = (float)($_GET['gap_mm_v'] ?? 2.0);     // mm

// Barcode height (mm)
$bh = (float)($_GET['bch_mm'] ?? 16.0);
// Font sizes (could be parameterized later)
$font_artikel = 7.0;
$font_code = 7.0;
$font_strike = 9.0;
$font_netto = 14.0;

// Parse kode/copies
$kode_list_raw = array_unique(array_filter(explode(',', $raw_kode)));
$copies_list_raw = array_map('intval', array_filter(explode(',', $raw_copies)));
$kode_to_copies = [];
$i=0; foreach($kode_list_raw as $k){ $c=$copies_list_raw[$i] ?? 1; if($c>0) $kode_to_copies[$k]=$c; $i++; }
$kode_to_query = array_keys($kode_to_copies);
if(empty($kode_to_query)) die('Tidak ada kode');

$placeholders = implode(',', array_fill(0, count($kode_to_query), '?'));

// Query data
$sql = "SELECT v.$vb_kode AS KODEBRG, RTRIM(LTRIM(v.$vb_artikel)) AS ARTIKELBRG, TRY_CAST(v.$vb_hjual AS NUMERIC(18,2)) AS HARGA_JUAL, TRY_CAST(v.$vb_disc AS NUMERIC(18,4)) AS DISKON FROM V_BARANG v WHERE v.$vb_kode IN ($placeholders)";
$stmt = $conn->prepare($sql); $stmt->execute($kode_to_query); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$items = [];
foreach($rows as $row){
  $kode = $row['KODEBRG'];
  $copies_req = $kode_to_copies[$kode] ?? 1;
  $hjual = (float)($row['HARGA_JUAL'] ?? 0);
  $diskon = (float)($row['DISKON'] ?? 0);
  $disc_perc = ($diskon > 0 && $diskon <= 1) ? $diskon : ($diskon/100);
  $netto = $hjual * (1 - $disc_perc);
  $netto_rounded = round_nearest($netto, $round_to);
  $item = [
    'kode'=>$kode,
    'artikel'=>$row['ARTIKELBRG'] ?? '',
    'hjual_fmt'=>fmt_idr($hjual),
    'disc_perc'=>fmt_disc($disc_perc),
    'netto_fmt'=>fmt_idr($netto_rounded),
  ];
  for($j=0;$j<$copies_req;$j++){ $items[]=$item; }
}

if(empty($items)) die('Tidak ada data');

// Layout calc
$rows_count = (int)ceil(count($items)/$cols);
$page_w = $cols * $label_w + ($cols-1)*$gap_h; // mm
// Paginate to avoid too-tall pages: cap ~30 rows per page
$rows_per_page = 30;
$pages = (int)ceil($rows_count / $rows_per_page);

$pdf = new TCPDF('P', 'mm', [ $page_w, $rows_per_page*$label_h + ($rows_per_page-1)*$gap_v ], true, 'UTF-8', false);
$pdf->SetMargins(0,0,0); $pdf->SetAutoPageBreak(false,0); $pdf->SetPrintHeader(false); $pdf->SetPrintFooter(false);

for($p=0;$p<$pages;$p++){
  $pdf->AddPage(); $pdf->SetXY(0,0);
  for($r=0;$r<$rows_per_page;$r++){
    $global_row = $p*$rows_per_page + $r;
    if($global_row >= $rows_count) break;
    for($c=0;$c<$cols;$c++){
      $idx = $global_row*$cols + $c;
      if($idx >= count($items)) break;
      $it = $items[$idx];
      $x = $c*($label_w + $gap_h);
      $y = $r*($label_h + $gap_v);

      $cell_w = $label_w; $cell_h = $label_h; $pad = 1.0; $tw = $cell_w - 2*$pad; $xc = $x + $pad;

      // Artikel
      $pdf->SetFont('helvetica','B',$font_artikel);
      $pdf->SetXY($xc, $y + $pad);
      $pdf->Cell($tw, 3, $it['artikel'] ?: '---', 0, 1, 'C', 0, '', 0, false, 'T', 'M');

      // Barcode
      $barcode_h = $bh; $barcode_y = $y + 4;
      $pdf->write1DBarcode($it['kode'],'C39',$xc,$barcode_y,$tw,$barcode_h,0.9,[ 'text'=>false, 'stretch'=>false ],'N');

      // Kode
      $pdf->SetFont('helvetica','', $font_code);
      $pdf->SetXY($xc, $barcode_y + $barcode_h);
      $pdf->Cell($tw, 3, $it['kode'], 0, 1, 'C', 0, '', 0, false, 'T', 'M');

      // Harga coret + disc
      $harga_y = $y + $cell_h - 6;
      $pdf->SetFont('helvetica','', $font_strike);
      $pdf->SetTextColor(102,102,102); $pdf->SetAlpha(0.7);
      $pdf->SetXY($xc, $harga_y - 4);
      $strike_text = 'Rp ' . $it['hjual_fmt'] . ' (' . $it['disc_perc'] . ')';
      $pdf->Cell($tw, 3, $strike_text, 0, 1, 'C', 0, '', 0, false, 'T', 'M');
      $text_w = $pdf->GetStringWidth($strike_text, 'helvetica','',$font_strike);
      $x_center = $x + ($cell_w/2);
      $x_line = $x_center - ($text_w/2);
      $y_line = $harga_y - 4 + 1.5;
      $pdf->Line($x_line, $y_line, $x_line + $text_w, $y_line, [ 'width'=>0.2, 'color'=>[102,102,102] ]);

      // Harga netto
      $pdf->SetFont('helvetica','B',$font_netto); $pdf->SetTextColor(0,0,0); $pdf->SetAlpha(1);
      $pdf->SetXY($xc, $harga_y);
      $pdf->Cell($tw, 5, 'Rp ' . $it['netto_fmt'], 0, 1, 'C', 0, '', 0, false, 'T', 'M');
    }
  }
}

$pdf->Output('zebra_labels_' . date('YmdHis') . '.pdf','I');
?>

