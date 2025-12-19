<?php
// Output ZPL for Zebra ZD220, 3x4 cm, 3 columns, 2mm gaps
// Content-Type as plain text so browser shows raw ZPL
header('Content-Type: text/plain; charset=UTF-8');

require_once 'config.php';

function column_exists(PDO $conn, $table, $column) {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:t AND COLUMN_NAME=:c";
  $st = $conn->prepare($sql);
  $st->execute([':t'=>$table, ':c'=>$column]);
  return (bool)$st->fetchColumn();
}
function pick_column(PDO $conn, $table, array $cands, $fallback=null){
  foreach($cands as $c){ if(column_exists($conn,$table,$c)) return $c; }
  return $fallback;
}
function fmt_idr($v){ if($v===null||$v==='') return '0'; return number_format((float)$v,0,',','.'); }
function fmt_disc($v){
  if($v===null||$v==='') return '0%';
  $v=(float)$v; if($v>0 && $v<=1) $v*=100;
  return rtrim(rtrim(number_format($v,1,',','.'),'0'),',').'%';
}
function round_nearest($number, $nearest) { if ($nearest <= 0) return (float)$number; return (float)round($number / $nearest) * $nearest; }

try{
  $vb_kode = pick_column($conn,'V_BARANG',['KODEBRG','KODE','KODE_BARANG']);
  $vb_artikel = pick_column($conn,'V_BARANG',['ARTIKELBRG','ARTIKEL'],null);
  $vb_hjual = pick_column($conn,'V_BARANG',['HARGAJUAL','HGJUAL','H_JUAL'],null);
  $vb_disc  = pick_column($conn,'V_BARANG',['DISKON','DISK','DISC'],null);
  if(!$vb_kode || !$vb_hjual) throw new Exception('Skema V_BARANG tidak lengkap: Kode atau Harga Jual tidak ditemukan.');
}catch(Exception $e){ http_response_code(500); echo ";ERROR " . $e->getMessage(); exit; }

// Params: expect kode (comma-separated) and copies (comma-separated, parallel to kode)
$raw_kode = $_GET['kode'] ?? '';
$raw_copies = $_GET['copies'] ?? '';
$round_to = (int)($_GET['round'] ?? 0);

// Zebra stock config (defaults as requested)
$labels_per_row = (int)($_GET['z_cols'] ?? 3);
$label_w_mm = (float)($_GET['label_w_mm'] ?? 40.0); // default width 40 mm
$label_h_mm = (float)($_GET['label_h_mm'] ?? 30.0); // default height 30 mm
$gap_mm_h = (float)($_GET['gap_mm_h'] ?? 2.0);      // horizontal gap between columns
$gap_mm_v = (float)($_GET['gap_mm_v'] ?? 2.0);      // vertical gap between rows (feed gap)
// Barcode tuning
$bch_mm = (float)($_GET['bch_mm'] ?? 16.0);         // barcode height in mm
$mw = (int)($_GET['mw'] ?? 2);                      // module width in dots
$ratio = (float)($_GET['ratio'] ?? 3.0);            // wide:narrow ratio

// Convert mm -> dots (203 dpi ≈ 8 dots/mm)
$DPMM = 203/25.4; // ≈ 8.0
$dw = (int)round($label_w_mm * $DPMM);
$dh = (int)round($label_h_mm * $DPMM);
$dgap_h = (int)round($gap_mm_h * $DPMM);
$dgap_v = (int)round($gap_mm_v * $DPMM);
$bar_h = (int)round($bch_mm * $DPMM);

// Total print width for a row of N labels
$total_width = $labels_per_row * $dw + ($labels_per_row - 1) * $dgap_h;
$total_height = $dh; // one row height per print format

// Parse kode and copies lists
$kode_list_raw = array_unique(array_filter(explode(',', $raw_kode)));
$copies_list_raw = array_map('intval', array_filter(explode(',', $raw_copies)));

$kode_to_copies = [];
$i = 0;
foreach ($kode_list_raw as $k) {
  $copies = $copies_list_raw[$i] ?? 1;
  if ($copies > 0) { $kode_to_copies[$k] = $copies; }
  $i++;
}

$kode_to_query = array_keys($kode_to_copies);
if (empty($kode_to_query)) { echo ";ERROR Tidak ada kode diberikan"; exit; }

$placeholders = implode(',', array_fill(0, count($kode_to_query), '?'));

// Pull product data
$items = [];
$sql = "SELECT v.$vb_kode AS KODEBRG, RTRIM(LTRIM(v.$vb_artikel)) AS ARTIKELBRG, TRY_CAST(v.$vb_hjual AS NUMERIC(18,2)) AS HARGA_JUAL, TRY_CAST(v.$vb_disc AS NUMERIC(18,4)) AS DISKON FROM V_BARANG v WHERE v.$vb_kode IN ($placeholders)";
$stmt = $conn->prepare($sql);
$stmt->execute($kode_to_query);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
  $kode = $row['KODEBRG'];
  $copies_req = $kode_to_copies[$kode] ?? 1;
  $hjual = (float)($row['HARGA_JUAL'] ?? 0);
  $diskon = (float)($row['DISKON'] ?? 0);
  $disc_perc = ($diskon > 0 && $diskon <= 1) ? $diskon : ($diskon/100);
  $netto = $hjual * (1 - $disc_perc);
  $netto_rounded = round_nearest($netto, $round_to);
  $item = [
    'kode' => $kode,
    'artikel' => $row['ARTIKELBRG'] ?? '',
    'hjual_fmt' => fmt_idr($hjual),
    'disc_perc' => fmt_disc($disc_perc),
    'netto_fmt' => fmt_idr($netto_rounded),
  ];
  for ($j=0; $j<$copies_req; $j++) { $items[] = $item; }
}

if (empty($items)) { echo ";ERROR Data barang tidak ditemukan"; exit; }

// Chunk items into rows of N (labels_per_row)
$rows_of_items = array_chunk($items, $labels_per_row);

// ZPL generation helpers
function zpl_escape($s){
  // Replace characters that could interfere with ZPL (^,~,\) in data fields
  $s = str_replace(['^','~','\\'], ['','-','/'], $s);
  return $s;
}

// Start emitting ZPL. Use one format (^XA..^XZ) per row of up to N labels.
foreach ($rows_of_items as $row_items) {
  echo "^XA\n";
  // Set media width/length and label home
  echo "^PW{$total_width}\n";         // print width in dots
  echo "^LL{$total_height}\n";        // label length in dots
  echo "^LH0,0\n";                    // label home
  echo "^CI28\n";                     // UTF-8 interpretation if supported
  echo "^PR3\n";                      // print speed (1-6), keep moderate
  echo "^MD20\n";                     // darkness (0-30), adjust as needed
  echo "^MTD\n";                      // media type: direct thermal

  // Per-column constants
  $artikel_font = '^A0N,20,20';
  $price_font_bold = '^A0N,30,30';
  $price_font_small = '^A0N,18,18';
  $code_font = '^A0N,18,18';

  // Barcode module width and height tuned for 30mm width
  // ^BYw,r,h
  $by = '^BY' . max(1,$mw) . ',' . number_format($ratio,1,'.','') . ',' . max(20,$bar_h);

  // Compute x offsets for each column
  $x_offsets = [];
  for ($c=0; $c<count($row_items); $c++) {
    $x_offsets[$c] = $c * ($dw + $dgap_h);
  }

  // Render each label in the row
  $col_index = 0;
  foreach ($row_items as $it) {
    $x = $x_offsets[$col_index];
    $y = 0;
    $w = $dw;

    $artikel = zpl_escape($it['artikel']);
    $kode = zpl_escape($it['kode']);
    $harga_coret = 'Rp ' . $it['hjual_fmt'] . ' (' . $it['disc_perc'] . ')';
    $harga_final  = 'Rp ' . $it['netto_fmt'];

    // Artikel (top, centered within column block).
    // Use ^FB to center in column width.
    echo "^FO{$x},8{$artikel_font}^FB{$w},1,0,C,0^FD{$artikel}^FS\n";

    // Barcode Code39 without human readable (we will print code as text).
    echo "^FO{$x},36{$by}^B3N,N," . max(20,$bar_h) . ",N,N^FD{$kode}^FS\n";

    // Kode (below barcode, centered)
    echo "^FO{$x},160{$code_font}^FB{$w},1,0,C,0^FD{$kode}^FS\n";

    // Harga coret + disc (smaller)
    echo "^FO{$x},190{$price_font_small}^FB{$w},1,0,C,0^FD{$harga_coret}^FS\n";
    // Strike-through line (approx width 80% of column)
    $line_x = $x + (int)round($w*0.1);
    $line_w = (int)round($w*0.8);
    echo "^FO{$line_x},205^GB{$line_w},2,2^FS\n";

    // Harga final (bold/larger)
    echo "^FO{$x},220{$price_font_bold}^FB{$w},1,0,C,0^FD{$harga_final}^FS\n";

    $col_index++;
  }

  echo "^XZ\n";
}

// Done
?>
