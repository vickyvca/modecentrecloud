<?php
$email = "vickyv40@gmail.com";
$password = "@Cuan5758";

// === 1. LOGIN DAN AMBIL TOKEN ===
function getJubelioToken($email, $password) {
    $url = "https://api2.jubelio.com/login";
    $payload = ["email" => $email, "password" => $password];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($res, true);
    echo "=== LOGIN ===\nRAW TOKEN RESPONSE:\n$res\n\n";
    return $json['token'] ?? null;
}

// === 2. UPLOAD GAMBAR KE API ===
function uploadImage($token, $filePath) {
    $url = "https://api2.jubelio.com/inventory/images/new";
    $cFile = new CURLFile(realpath($filePath), mime_content_type($filePath), basename($filePath));
    $post = ['file' => $cFile];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($res, true);
    echo "=== UPLOAD GAMBAR ===\nHTTP CODE: $code\nRESPONSE:\n" . print_r($json, true) . "\n\n";
    return $json['url'] ?? "";
}

// === 3. KIRIM PRODUK KE API ===
function createProduct($token, $payload) {
    $url = "https://api2.jubelio.com/inventory/catalog";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "=== KIRIM PRODUK ===\nHTTP CODE: $code\nRESPONSE:\n$res\n\n";
}

// === MAIN EXECUTION ===
$token = getJubelioToken($email, $password);
if (!$token) die("❌ Gagal login\n");

// === KONFIG DATA ===
$kode_barang = "082010595";
$nama_barang = "LOIS C.JEANS";
$artikel = "DFL.099C";
$harga = 509500;
$stok = 11;
$satuan = "PCS";
$brand = "LOIS";
$kategori_id = 9999;
$image_path = "11.jpg";

// Upload gambar ke API
$image_url = uploadImage($token, $image_path);

// Format deskripsi minimal 30 karakter
$deskripsi = "Produk: $nama_barang\nArtikel: $artikel\nKode: $kode_barang\nHarga: Rp " .
    number_format($harga) . "\nDiskon: 15%\nDipayuda: 0 | Pemuda: $stok\n\n" .
    "Garment care:\n- Cuci air dingin\n- Setrika suhu rendah\n\n" .
    "Warna pada gambar mungkin sedikit berbeda.\nTukar 1x24 jam, sertakan video unboxing.";

// Payload produk
$payload = [
    "sku" => $kode_barang,
    "name" => "$nama_barang - $artikel",
    "item_group_name" => "$nama_barang $artikel",
    "description" => $deskripsi,
    "price" => $harga,
    "uom_name" => $satuan,
    "brand" => $brand,
    "stock" => $stok,
    "category_id" => $kategori_id,
    "weight_gram" => 350,
    "dimension" => [
        "length" => 15,
        "width" => 15,
        "height" => 15
    ],
    "images" => [
        ["url" => $image_url]
    ]
];

// Kirim produk
createProduct($token, $payload);
