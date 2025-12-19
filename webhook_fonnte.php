<?php
// --- KONFIGURASI ---
// Masukkan Token Fonnte Anda di sini
$fonnte_token = 'mnpxcjgnvSu4WaADiPmx';

// --- FUNGSI UNTUK MENGIRIM PESAN BALASAN (menggunakan API /send) ---
function send_reply($token, $target, $message) {
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => [
            'target' => $target,
            'message' => $message,
            'countryCode' => '62'
        ],
        CURLOPT_HTTPHEADER => array(
            'Authorization: ' . $token
        ),
    ));
    $response = curl_exec($curl);
    curl_close($curl);
    return $response;
}

// 1. Ambil data JSON yang dikirim oleh Fonnte
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// 2. Hanya proses jika data pesan yang masuk valid
if (isset($data['sender'], $data['device'], $data['message'])) {
    
    $sender = $data['sender'];
    $device = $data['device']; // Nomor perangkat Anda yang menerima pesan
    $message = $data['message'];
    
    // Logika Anti-Loop yang sudah terbukti
    if ($sender === $device || strpos($message, "Pesan ini dikirim secara otomatis") !== false) {
        http_response_code(200); // Beri tahu Fonnte webhook diterima
        exit('Pesan dari diri sendiri, diabaikan.');
    }
    
    // 3. Siapkan pesan balasan otomatis Anda
    $reply_message = "Terima kasih telah menghubungi Mode Centre.\n\nPesan Anda sudah kami terima. Tim kami akan segera merespons dalam jam kerja.\n\n---\nPesan ini dikirim secara otomatis.";
    
    // 4. Kirim balasan menggunakan API /send
    send_reply($fonnte_token, $sender, $reply_message);
    
    // 5. Beri respons 'OK' ke server Fonnte untuk menandakan webhook berhasil diterima
    http_response_code(200);
    echo "OK";
    
} else {
    // Jika data yang masuk bukan notifikasi pesan, abaikan.
    http_response_code(200);
    echo "Bukan pesan, diabaikan.";
}
?>