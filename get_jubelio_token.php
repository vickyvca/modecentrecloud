<?php
function getJubelioToken($email = '', $password = '') {
    $url = "https://api2.jubelio.com/login";

    $credentials = [
        "email" => $email ?: "modecenter27@gmail.com",       // ← Ganti default jika mau
        "password" => $password ?: "@Cuan5758"         // ← Ganti default jika mau
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode($credentials),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && $response) {
        $json = json_decode($response, true);
        return $json['token'] ?? null;
    } else {
        echo "<pre style='color:red;'>Gagal login Jubelio ($code):\n$response</pre>";
        return null;
    }
}

// === CONTOH PAKAI ===
$token = getJubelioToken();  // atau getJubelioToken('email', 'password')
echo "<pre style='color:green;'>TOKEN:\n" . substr($token, 0, 80) . "...</pre>";
