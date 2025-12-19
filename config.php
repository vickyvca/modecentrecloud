<?php
// FILE: config.php (DI DIRECTORY UTAMA)

ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Jakarta');

function wa_log($text) {
    global $PAYROLL_DATA_DIR;
    $logfile = $PAYROLL_DATA_DIR . '/wa.log';
    if (!is_dir(dirname($logfile))) @mkdir(dirname($logfile), 0777, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n";
    @file_put_contents($logfile, $line, FILE_APPEND);
}

try {
    $serverName = "192.168.4.99";
    $database = "MODECENTRE";
    $uid = "sa";
    $pwd = "mode1234ABC";
    $dsn = "sqlsrv:Server=$serverName;Database=$database";
    $conn = new PDO($dsn, $uid, $pwd, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);
} catch (PDOException $e) {
    error_log("Koneksi DB Gagal: " . $e->getMessage());
    die("Gagal koneksi ke database. Cek log error.");
}

$PAYROLL_BRAND_NAME = "MODESTOK HR"; 
$PAYROLL_DATA_DIR = __DIR__ . '/payroll/data'; 

// ==================================================
// == FUNGSI PENGIRIM PESAN WHATSAPP VIA FONNTE ==
// ==================================================
function kirimWAPembayaranFonnte($nomorPenerima, $detailPembayaran) {
    // Pastikan token Fonnte Anda sudah benar di sini
    $fonnteToken = 'mnpxcjgnvSu4WaADiPmx';

    $nota_details = "";
    foreach ($detailPembayaran['notas'] as $nota) {
        $nota_details .= "No Nota = " . $nota['nomor'] . " : Rp " . number_format($nota['nominal'], 0, ',', '.') . "\n";
    }

    $pesan = "Pembayaran di terima\n\n" . trim($nota_details) . "\n\n" .
             "Potongan = " . htmlspecialchars($detailPembayaran['potongan']) . "\n" .
             "Total = Rp " . number_format($detailPembayaran['total'], 0, ',', '.') . "\n\n" .
             "Tanggal = " . date('d F Y', strtotime($detailPembayaran['tanggal'])) . "\n\n" .
             "Silahkan login ke https://modecentre.cloud untuk melihat detail";

    $payload = ['target' => $nomorPenerima, 'message' => $pesan];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.fonnte.com/send');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $fonnteToken]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

// Kirim pesan WA teks biasa via Fonnte
function kirimWATeksFonnte($nomorPenerima, $pesan, $context = '') {
    $fonnteToken = 'mnpxcjgnvSu4WaADiPmx';
    $payload = ['target' => $nomorPenerima, 'message' => $pesan];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.fonnte.com/send');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $fonnteToken, 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $result = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (function_exists('wa_log')) {
        if ($result === false) {
            wa_log('WA ERROR ' . ($context ? "($context) " : '') . 'to ' . $nomorPenerima . ' http=' . $http . ' err=' . curl_error($ch));
        } else {
            wa_log('WA SEND ' . ($context ? "($context) " : '') . 'to ' . $nomorPenerima . ' http=' . $http . ' resp=' . $result);
        }
    }
    curl_close($ch);
    return $result;
}
?>

