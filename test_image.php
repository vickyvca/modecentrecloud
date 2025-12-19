<?php
include 'jwt.php';
$token = getJubelioToken();

if (!$token) {
    die("❌ Gagal ambil token.");
}

$imagePath = "11.jpg"; // pastikan file ada di folder ini

if (!file_exists($imagePath)) {
    die("❌ File tidak ditemukan: $imagePath");
}

$url = "https://api2.jubelio.com/inventory/upload-image";

$postFields = [
    "uid" => uniqid("img-"),
    "name" => basename($imagePath),
    "imageType" => "1",
    "file" => new CURLFile($imagePath, mime_content_type($imagePath), basename($imagePath))
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token"
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "TOKEN:\n$token\n";
echo "HTTP CODE: $httpCode\n";
echo "RESPONSE:\n$response\n";
