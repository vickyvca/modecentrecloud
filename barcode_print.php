<?php
// Ganti ini dengan path TCPDF yang benar di server Anda
require_once('tcpdf/tcpdf.php'); 
require_once 'config.php'; 

/* ===== Utils ===== */
function column_exists(PDO $conn, $table, $column) {
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:t AND COLUMN_NAME=:c";
  $st=$conn->prepare($sql); $st->execute([':t'=>$table,':c'=>$column]); return (bool)$st->fetchColumn();
}
function pick_column(PDO $conn, $table, array $cands, $fallback=null){
  foreach($cands as $c){ if(column_exists($conn,$table,$c)) return $c; } return $fallback;
}
function fmt_idr($v){ if($v===null||$v==='') return '0'; return number_format((float)$v,0,',','.'); }
function fmt_disc($v){
  if($v===null||$v==='') return '0%';
  $v=(float)$v; if($v>0 && $v<=1) $v*=100;
  return rtrim(rtrim(number_format($v,1,',','.'),'0'),',').'%';
}
function round_nearest($number, $nearest) {
    if ($nearest <= 0) return (float)$number;
    return (float)round($number / $nearest) * $nearest;
}

/* ===== Resolve schema (V_BARANG) ===== */
try{
  $vb_kode = pick_column($conn,'V_BARANG',['KODEBRG','KODE','KODE_BARANG']);
  $vb_artikel = pick_column($conn,'V_BARANG',['ARTIKELBRG','ARTIKEL'],null);
  $vb_hjual = pick_column($conn,'V_BARANG',['HARGAJUAL','HGJUAL','H_JUAL'],null);
  $vb_disc  = pick_column($conn,'V_BARANG',['DISKON','DISK','DISC'],null);
  if(!$vb_kode || !$vb_hjual) throw new Exception("Skema V_BARANG tidak lengkap: Kode atau Harga Jual tidak ditemukan.");
}catch(Exception $e){ die("Schema error: ".htmlspecialchars($e->getMessage())); }


/* ===== Filters & Params ===== */
$raw_kode = $_GET['kode'] ?? '';
$copies = max(1, (int)($_GET['copies'] ?? 1));
$fill_empty = (int)($_GET['fill'] ?? 1);
$gap = (float)($_GET['gap'] ?? 0.0);
$round_to = (int)($_GET['round'] ?? 0);
$sheet_size = (int)($_GET['size'] ?? 210);

$bh = (float)($_GET['bh'] ?? 8.0); 
$bw = (float)($_GET['bw'] ?? 0.9); 
$sp = (float)($_GET['sp'] ?? 9.0); 
$p  = (float)($_GET['p'] ?? 14.0); 


// Penentuan dimensi sheet dan jumlah slot
$sheet_width = 190; 
$sheet_height = $sheet_size; 
$cols = 6;
$rows = ($sheet_height === 210) ? 9 : 7; 
$max_slots = $cols * $rows;

// Dimensi Label
$cell_width = $sheet_width / $cols;
$cell_height = $sheet_height / $rows;
$label_padding = 1; 

// Normalize kode barang list
$kode_list = array_unique(array_filter(explode(',', $raw_kode)));
$placeholders = implode(',', array_fill(0, count($kode_list), '?'));

$final_data = [];

/* ===== Ambil Data Harga & Diskon ===== */
if (!empty($kode_list)) {
    $sql = "
        SELECT
            v.$vb_kode AS KODEBRG,
            RTRIM(LTRIM(v.$vb_artikel)) AS ARTIKELBRG,
            TRY_CAST(v.$vb_hjual AS NUMERIC(18,2)) AS HARGA_JUAL,
            TRY_CAST(v.$vb_disc AS NUMERIC(18,4)) AS DISKON
        FROM V_BARANG v
        WHERE v.$vb_kode IN ($placeholders)
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($kode_list);
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw_data as $row) {
        $hjual = (float)($row['HARGA_JUAL'] ?? 0);
        $diskon = (float)($row['DISKON'] ?? 0);
        
        if ($diskon > 0 && $diskon <= 1) { $diskon_perc = $diskon; } else { $diskon_perc = $diskon / 100; }
        $netto = $hjual * (1 - $diskon_perc);
        
        $netto_rounded = round_nearest($netto, $round_to);

        $item = [
            'kode' => $row['KODEBRG'],
            'artikel' => $row['ARTIKELBRG'] ?? '',
            'hjual_fmt' => fmt_idr($hjual),
            'disc_perc' => fmt_disc($diskon_perc),
            'netto_fmt' => fmt_idr($netto_rounded),
        ];

        for ($i = 0; $i < $copies; $i++) { $final_data[] = $item; }
    }
}

$total_items = count($final_data);
if ($fill_empty && $total_items < $max_slots) {
    $remaining_slots = $max_slots - $total_items;
    for ($i = 0; $i < $remaining_slots; $i++) { $final_data[] = ['empty' => true]; }
}
$data_to_print = array_slice($final_data, 0, $max_slots);


/* ===== PDF GENERATOR LOGIC (TCPDF) ===== */

// Buat instance PDF dengan ukuran kustom
$pdf = new TCPDF('L', 'mm', array($sheet_width, $sheet_height), true, 'UTF-8', false); 

// --- PENGATURAN MARGIN KRUSIAL UNTUK CETAK PRESISI ---
$pdf->SetMargins(0, 0, 0); 
$pdf->SetAutoPageBreak(false, 0); 
$pdf->SetPrintHeader(false);
$pdf->SetPrintFooter(false);
$pdf->AddPage();
// --------------------------------------------------

$i = 0;
foreach ($data_to_print as $item) {
    if (!isset($item['empty'])) {
        $col = $i % $cols;
        $row = floor($i / $cols);
        $x_start = $col * $cell_width;
        $y_start = $row * $cell_height;

        $text_width = $cell_width - (2 * $label_padding);
        $x_content = $x_start + $label_padding;
        
        // --- Urutan 1: ARTIKELBRG ---
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetXY($x_content, $y_start + $label_padding);
        $pdf->Cell($text_width, 3, $item['artikel'] ?: '---', 0, 1, 'C', 0, '', 0, false, 'T', 'M');
        
        // --- Urutan 2: BARCODE CODE39 & KODEBRG ---
        $barcode_height_mm = $bh; 
        $barcode_y = $y_start + 4;
        
        // Barcode
        $pdf->write1DBarcode(
            $item['kode'], 
            'C39', 
            $x_content, 
            $barcode_y, 
            $text_width, 
            $barcode_height_mm, 
            $bw, 
            array('text' => false, 'stretch' => false), 
            'N'
        );
        
        // Kode Barang (di bawah barcode)
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY($x_content, $barcode_y + $barcode_height_mm);
        $pdf->Cell($text_width, 3, $item['kode'], 0, 1, 'C', 0, '', 0, false, 'T', 'M');
        
        // --- Urutan 3: HARGA ---
        $harga_y_start = $y_start + $cell_height - 6; 
        
        // Harga Coret (Sebelum Diskon) + % Disc
        $pdf->SetFont('helvetica', '', $sp);
        $pdf->SetTextColor(102, 102, 102); 
        $pdf->SetAlpha(0.7); 
        $pdf->SetXY($x_content, $harga_y_start - 4); 
        $pdf->Cell($text_width, 3, 'Rp ' . $item['hjual_fmt'] . ' (' . $item['disc_perc'] . ')', 0, 1, 'C', 0, '', 0, false, 'T', 'M');
        
        // Garis coret
        $text_w = $pdf->GetStringWidth('Rp ' . $item['hjual_fmt'] . ' (' . $item['disc_perc'] . ')', 'helvetica', '', $sp);
        $x_text_center = $x_start + ($cell_width / 2);
        $x_line_start = $x_text_center - ($text_w / 2);
        $y_line = $harga_y_start - 4 + 1.5; 

        $pdf->Line($x_line_start, $y_line, $x_line_start + $text_w, $y_line, array('width' => 0.2, 'color' => array(102, 102, 102)));

        // Harga Netto (Final)
        $pdf->SetFont('helvetica', 'B', $p);
        $pdf->SetTextColor(0, 0, 0); 
        $pdf->SetAlpha(1); 
        $pdf->SetXY($x_content, $harga_y_start);
        $pdf->Cell($text_width, 5, 'Rp ' . $item['netto_fmt'], 0, 1, 'C', 0, '', 0, false, 'T', 'M');

    }
    $i++;
}

// Output PDF ke browser
$pdf->Output('barcode_sheet_' . date('YmdHis') . '.pdf', 'I');
?>