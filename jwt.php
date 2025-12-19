<?php
function getJubelioToken() {
    $url = "https://api2.jubelio.com/login";
    $credentials = [
        "email" => "vickyv40@gmail.com",
        "password" => "@Cuan5758"
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($credentials),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);

    if ($httpCode === 200 && isset($json["token"])) {
        return $json["token"];
    }

    echo "❌ Gagal login. HTTP: $httpCode\n";
    echo "RESPONSE:\n$response\n";
    return null;
}
