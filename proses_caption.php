<?php
require_once 'config.php';
require 'vendor/autoload.php';

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'Terjadi kesalahan.'];

if (empty($_POST['kode_barang']) || empty($_FILES['product_image']['name'])) {
    $response['message'] = 'Kode barang dan foto tidak boleh kosong.';
    echo json_encode($response);
    exit;
}

$kode_barang = trim($_POST['kode_barang'], '*');

$target_dir = "uploads/";
if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
$image_path = $target_dir . uniqid() . '-' . basename($_FILES["product_image"]["name"]);
if (!move_uploaded_file($_FILES["product_image"]["tmp_name"], $image_path)) {
    $response['message'] = 'Gagal mengupload file gambar.';
    echo json_encode($response);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT KODEBRG, NAMABRG, ARTIKELBRG, HGJUAL FROM V_BARANG WHERE KODEBRG = ?");
    $stmt->execute([$kode_barang]);
    $produk = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($produk) {
        $your_api_key = "AIzaSyAd8Ng0-cWadUFy4KrEAqV2zKiIHtW4EDs";
        $client = Gemini::client($your_api_key);

        $sku = $produk['KODEBRG'];
        $nama_dasar = $produk['NAMABRG'] . ' ' . $produk['ARTIKELBRG'];
        $harga = number_format($produk['HGJUAL']);

        // =================================================================
        // PROMPT BARU: Meminta 2 jenis output yang berbeda
        // =================================================================
        $prompt = "Anda adalah seorang copywriter dan manajer e-commerce ahli. Buatkan DUA jenis output untuk produk fashion berikut:
        
        Data Produk:
        - SKU: {$sku}
        - Nama Dasar Produk: {$nama_dasar}
        - Harga: Rp {$harga}
        
        Tugas Anda:
        1. Buat 'caption_sosmed': Sebuah caption untuk sosial media (seperti Instagram). Buat se-atraktif mungkin dengan gaya bahasa santai, ceria, persuasif, tonjolkan harga, pakai banyak emoji, dan sertakan 7 hashtag relevan.
        2. Buat 'nama_produk_marketplace': Sebuah nama produk yang jelas, informatif, dan SEO-friendly untuk marketplace seperti Tokopedia/Shopee. Maksimal 70 karakter. Formatnya: [Nama Brand/Produk] [Model/Artikel] [Kata Kunci Tambahan].
        3. Buat 'deskripsi_marketplace': Sebuah deskripsi produk yang profesional dan terstruktur. Awali dengan ringkasan singkat, berikan poin-poin spesifikasi (SKU, Bahan (jika bisa diasumsikan), Ukuran), jelaskan keunggulan produk, dan akhiri dengan ajakan membeli yang sopan.
        
        Aturan PENTING: Berikan output HANYA dalam format JSON murni, langsung mulai dengan karakter { dan akhiri dengan }. Contoh format:
        {\"caption_sosmed\": \"...\", \"nama_produk_marketplace\": \"...\", \"deskripsi_marketplace\": \"...\"}";
        
        $result = $client->generativeModel('gemini-1.5-pro-latest')->generateContent($prompt);
        $gemini_text = $result->text();
        
        function extractJson(string $text): ?string {
            $firstBrace = strpos($text, '{');
            $lastBrace = strrpos($text, '}');
            if ($firstBrace === false || $lastBrace === false) { return null; }
            return substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
        }
        
        $json_string = extractJson($gemini_text);
        
        if ($json_string === null) {
             $response['message'] = "AI tidak memberikan respon JSON yang valid. Respon mentah: " . $gemini_text;
        } else {
            $captions = json_decode($json_string, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($captions['caption_sosmed'])) {
                $response = ['status' => 'success', 'data' => $captions];
            } else {
                $response['message'] = "Gagal mem-parsing JSON dari AI setelah dibersihkan. JSON string: " . $json_string;
            }
        }

    } else {
        $response['message'] = "Kode barang '{$kode_barang}' tidak ditemukan di database.";
    }
} catch (Exception $e) {
    $response['message'] = "Terjadi kesalahan saat koneksi ke API: " . $e->getMessage();
}

echo json_encode($response);
?>