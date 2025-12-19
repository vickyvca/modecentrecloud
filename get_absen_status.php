<?php
// FILE: get_absen_status.php (Harus di root directory: MODESTOK/)

session_start();
// Memuat config.php yang membuat variabel $conn (objek PDO)
require_once 'config.php'; 

// Definisikan shift_times
$shift_times = [
    'S1' => ['start' => '08:30', 'end' => '18:00', 'name' => 'Shift 1 (Pagi)'],
    'S2' => ['start' => '12:00', 'end' => '20:30', 'name' => 'Shift 2 (Siang)'],
];

header('Content-Type: application/json');

if (!isset($_SESSION['nik']) || $_SESSION['role'] !== 'pegawai') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.'], JSON_PRETTY_PRINT);
    exit;
}

// AKSES VARIABEL KONEKSI GLOBAL $conn
global $conn;

if (!isset($conn) || !($conn instanceof PDO)) {
    // Jika config.php gagal dan tidak menjalankan exit, berikan pesan ini
    echo json_encode(['status' => 'error', 'message' => 'Koneksi database ($conn) tidak valid di scope ini. Cek config.php.'], JSON_PRETTY_PRINT);
    exit;
}

$nik = $_SESSION['nik'];
$today_date = date('Y-m-d');

$response = [
    'status' => 'success',
    'masuk' => null, 
    'pulang' => null, 
    'overtime' => false,
];

try {
    // Gunakan PREPARE statement untuk eksekusi yang aman
$sql = "SELECT SHIFT_MASUK, SHIFT_PULANG, OVERTIME_BONUS_FLAG, SHIFT_JADWAL 
            FROM T_ABSENSI 
            WHERE KODESL = :nik AND TGL = :tgl";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([':nik' => $nik, ':tgl' => $today_date]);
    
    $result_absen = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result_absen) {
        // Karena SHIFT_MASUK/PULANG adalah kolom TIME di MSSQL, PDO mungkin mengembalikan string.
        $response['masuk'] = $result_absen['SHIFT_MASUK'] ? date('H:i', strtotime($result_absen['SHIFT_MASUK'])) : null;
        $response['pulang'] = $result_absen['SHIFT_PULANG'] ? date('H:i', strtotime($result_absen['SHIFT_PULANG'])) : null;
        $response['overtime'] = (bool)$result_absen['OVERTIME_BONUS_FLAG'];

        // Auto-pulang jika sudah lewat jam kerja dan belum absen pulang
        $shift_code = $result_absen['SHIFT_JADWAL'] ?: 'S1';
        $end_time_cfg = $shift_times[$shift_code]['end'] ?? '18:00';
        $now = date('H:i');
        if ($response['masuk'] && !$response['pulang'] && $now >= $end_time_cfg) {
            // Set pulang otomatis pada jam end shift
            $stmt2 = $conn->prepare("UPDATE T_ABSENSI SET SHIFT_PULANG = :end_time WHERE KODESL = :nik AND TGL = :tgl");
            $stmt2->execute([':end_time' => $end_time_cfg . ':00', ':nik' => $nik, ':tgl' => $today_date]);
            $response['pulang'] = $end_time_cfg;
        }
    }

} catch (PDOException $e) {
    // Tangani error jika query gagal (misalnya, tabel T_ABSENSI tidak ada)
    $response['status'] = 'error';
    $response['message'] = 'DB Query Error (T_ABSENSI): ' . $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>
