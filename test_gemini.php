<?php
// Set untuk menampilkan semua error
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "Memulai tes koneksi Gemini...<br>";

if (!file_exists('vendor/autoload.php')) {
    die("<strong>ERROR:</strong> File 'vendor/autoload.php' tidak ditemukan!");
}
require 'vendor/autoload.php';
echo "Autoloader Composer berhasil dimuat.<br>";

try {
    $your_api_key = "AIzaSyAd8Ng0-cWadUFy4KrEAqV2zKiIHtW4EDs";
    $client = Gemini::client($your_api_key);
    echo "Client Gemini berhasil dibuat.<br>";

    $model_name = 'gemini-2.0-flash'; // Model yang kita targetkan
    echo "Mencoba mengirim permintaan sederhana ke model '{$model_name}'...<br>";

    // ==========================================================
    // PERBAIKAN FINAL: Gunakan metode generativeModel() dengan nama model yang tepat
    // ==========================================================
    $result = $client->generativeModel($model_name)->generateContent('Halo, apakah kamu berfungsi? Berikan respon singkat.');
    
    echo "<strong>SUKSES!</strong> Respon dari Gemini: " . $result->text();

} catch (Exception $e) {
    echo "<strong>GAGAL!</strong> Terjadi error saat mencoba terhubung ke Gemini API.<br>";
    echo "<strong>Pesan Error:</strong> <pre>" . $e->getMessage() . "</pre>";
}
?>