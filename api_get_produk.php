<?php
require_once 'config.php'; 

// --- FUNGSI BANTU ---
function column_exists(PDO $conn, $table, $column) {
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:t AND COLUMN_NAME=:c";
    $st=$conn->prepare($sql);
    $st->execute([':t'=>$table,':c'=>$column]);
    return (bool)$st->fetchColumn();
}
function pick_column(PDO $conn, $table, array $cands, $fallback=null){
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:t AND COLUMN_NAME=:c";
    $st=$conn->prepare($sql);
    foreach($cands as $c){
        $st->execute([':t'=>$table,':c'=>$c]);
        if($st->fetchColumn()) return $c;
    }
    return $fallback;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
// define('SECRET_API_KEY', 'KunciRahasiaSuperAman123!@#'); 
// $api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
// if ($api_key !== SECRET_API_KEY) { /* ... (Logika API Key) ... */ }

if (!isset($_GET['kode'])) {
    echo json_encode(['status' => 'error', 'message' => 'Parameter kode dibutuhkan.']);
    exit;
}
$kode = trim($_GET['kode']);

// --- RESOLVE NAMA KOLOM ---
$vb_nama = pick_column($conn, 'V_BARANG', ['NAMABRG','NAMA','NAMA_BARANG']);
$vb_artikel = pick_column($conn, 'V_BARANG', ['ARTIKELBRG','ARTIKEL'], null);
$vb_hjual = pick_column($conn, 'V_BARANG', ['HARGAJUAL','HGJUAL','H_JUAL'], null);
$vb_disc  = pick_column($conn, 'V_BARANG', ['DISKON','DISK','DISC'], null);
$st_cols=[]; foreach(['ST00','ST01','ST02','ST03','ST04'] as $st){ $st_cols[$st]=column_exists($conn,'V_BARANG',$st); }
$stok_total_expr_parts=[]; foreach(['ST00','ST01','ST02','ST03','ST04'] as $st){ if(!empty($st_cols[$st])) $stok_total_expr_parts[]="ISNULL($st,0)"; }
$stok_total_expr = $stok_total_expr_parts ? implode(' + ',$stok_total_expr_parts) : "0";

// --- QUERY DATA PRODUK ---
$sql = "
    SELECT 
        $vb_nama AS NAMABRG,
        " . ($vb_artikel ? "$vb_artikel AS ARTIKELBRG," : "'-' AS ARTIKELBRG,") . "
        " . ($vb_hjual ? "TRY_CAST($vb_hjual AS NUMERIC(18,2)) AS HGJUAL," : "0 AS HGJUAL,") . "
        " . ($vb_disc ? "TRY_CAST($vb_disc AS NUMERIC(18,4)) AS DISC," : "0 AS DISC,") . "
        ($stok_total_expr) AS STOK_TOTAL
    FROM V_BARANG
    WHERE UPPER(RTRIM(LTRIM(KODEBRG))) = :kode
";
$stmt = $conn->prepare($sql);
$stmt->execute([':kode' => strtoupper($kode)]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    // --- PERUBAHAN BARU: Cari semua gambar terkait ---
    $gambar = [];
    // Dev env: simpan gambar di htdocs/modecentreweb/uploads/produk/
    $base_url = '/modecentreweb/uploads/produk/'; // URL publik ke folder gambar (root-relative to site base)
    $base_path = __DIR__ . '/../modecentreweb/uploads/produk/'; // Path lokal ke folder gambar

    // Cek gambar utama
    if (file_exists($base_path . $kode . '.jpg')) {
        $gambar[] = $base_url . $kode . '.jpg';
    }
    // Cek gambar variasi (_1, _2, _3)
    for ($i = 1; $i <= 3; $i++) {
        if (file_exists($base_path . "{$kode}_{$i}.jpg")) {
            $gambar[] = $base_url . "{$kode}_{$i}.jpg";
        }
    }
    // --- AKHIR PERUBAHAN ---

    $v_disc=(float)$row['DISC'];
    if($v_disc>0 && $v_disc<1) $v_disc*=100;
    
    $response = [
        'status' => 'success',
        'data' => [
            'kode' => $kode,
            'nama' => trim($row['NAMABRG']),
            'artikel' => trim($row['ARTIKELBRG']),
            'harga' => number_format($row['HGJUAL'], 0, ',', '.'),
            'diskon' => rtrim(rtrim(number_format($v_disc,1,',','.'),'0'),',').'%',
            'stok' => (int)$row['STOK_TOTAL'],
            'gambar' => $gambar // Kirim array berisi URL gambar
        ]
    ];
} else {
    $response = ['status' => 'error', 'message' => 'Produk tidak ditemukan.'];
}

echo json_encode($response);
?>

