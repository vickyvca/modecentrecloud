<?php
// FILE: absen.php (PDO Version - FINAL FIX GPS)

session_start();
require_once 'config.php'; 
require_once __DIR__ . '/payroll/payroll_lib.php'; 

if (!isset($_SESSION['nik']) || $_SESSION['role'] !== 'pegawai') {
    die("Akses ditolak.");
}

$nik = $_SESSION['nik'];
$action = $_POST['action'] ?? null;
$shift_code = $_POST['shift'] ?? null;
$user_lat = $_POST['user_lat'] ?? '0'; // Ambil 0 jika gagal dari frontend
$user_lon = $_POST['user_lon'] ?? '0'; // Ambil 0 jika gagal dari frontend

$today = date('Y-m-d');
$now_time = date('H:i:s'); 

// Definisi Shift
$shift_times = [
    'S1' => ['start' => '08:30:00', 'end' => '18:00:00', 'name' => 'Shift 1 (Pagi)'],
    'S2' => ['start' => '12:00:00', 'end' => '20:30:00', 'name' => 'Shift 2 (Siang)'],
];

if (!$shift_code || !isset($shift_times[$shift_code])) {
    $_SESSION['message'] = "❌ Error: Kode shift tidak valid.";
    header('Location: dashboard_pegawai.php');
    exit;
}

$shift_info = $shift_times[$shift_code];
global $conn;

// GPS SERVER SIDE LOGIC - DIKOMENTARI KARENA MASALAH MULTI-USER/TUNNELING
/*
const OFFICE_LAT = -7.397074;
const OFFICE_LON = 109.697524;
const MAX_DISTANCE_M = 200;

function is_gps_within_zone($lat1, $lon1, $lat2, $lon2, $max_distance_m) {
    // ... (Haversine code) ...
    return $distance <= $max_distance_m;
}
*/

try {
    // *** VALIDASI GPS DIHAPUS DARI SERVER UNTUK MENGIZINKAN ABSENSI MULTI-USER ***
    // Tambah handler cepat untuk aksi 'lembur'
    if ($action === 'lembur') {
        $stmt_check = $conn->prepare("SELECT SHIFT_MASUK, SHIFT_PULANG, OVERTIME_BONUS_FLAG, SHIFT_JADWAL, OVERTIME_NOTES FROM T_ABSENSI WHERE KODESL = :nik AND TGL = :today");
        $stmt_check->execute([':nik' => $nik, ':today' => $today]);
        $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if (!$existing || empty($existing['SHIFT_MASUK'])) {
            $_SESSION['message'] = "Tidak dapat memulai lembur tanpa absen masuk.";
        } else {
            $new_note = trim(($existing['OVERTIME_NOTES'] ?? ''));
            if ($new_note !== '') { $new_note .= ' | '; }
            $new_note .= 'OT Manual Start';

            $sql = "UPDATE T_ABSENSI SET 
                        OVERTIME_BONUS_FLAG = 1,
                        OVERTIME_NOTES = :note,
                        SHIFT_PULANG = NULL
                    WHERE KODESL = :nik AND TGL = :today";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':note' => $new_note, ':nik' => $nik, ':today' => $today]);
            $_SESSION['message'] = "Lembur dimulai. Jangan lupa absen pulang saat selesai.";
        }
        header('Location: dashboard_pegawai.php');
        exit;
    }
    // Logika di sini hanya akan memproses data.

    if ($action === 'masuk') {
        // Cek apakah sudah absen masuk
        $stmt_check = $conn->prepare("SELECT SHIFT_MASUK FROM T_ABSENSI WHERE KODESL = :nik AND TGL = :today");
        $stmt_check->execute([':nik' => $nik, ':today' => $today]);
        $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($existing && $existing['SHIFT_MASUK'] !== null) {
            $_SESSION['message'] = "⚠️ Anda sudah Absen Masuk hari ini.";
            header('Location: dashboard_pegawai.php');
            exit;
        }
        
        // Tentukan Overtime Bonus Masuk (selaraskan dengan laporan/payroll)
        // S2: masuk sebelum 10:00 dianggap OT
        $overtime_flag = 0; $overtime_note = null;
        if ($shift_code === 'S2' && strtotime($now_time) < strtotime('10:00:00')) {
            $overtime_flag = 1; $overtime_note = 'OT Masuk Pagi (S2)';
        }
        
        // PDO MERGE/UPSERT (UPDATE atau INSERT)
        if ($existing) {
            $sql = "UPDATE T_ABSENSI SET 
                        SHIFT_JADWAL = :shift_code, SHIFT_MASUK = :now_time, STATUS_HARI = 'HADIR', 
                        OVERTIME_BONUS_FLAG = ISNULL(OVERTIME_BONUS_FLAG, 0) | :ot_flag,
                        OVERTIME_NOTES = ISNULL(OVERTIME_NOTES, '') + IIF(ISNULL(OVERTIME_BONUS_FLAG, 0)=1, ' | ' + :ot_note, ''),
                        LAST_LATITUDE = :lat, LAST_LONGITUDE = :lon
                    WHERE KODESL = :nik AND TGL = :today";
        } else {
            $sql = "INSERT INTO T_ABSENSI (KODESL, TGL, SHIFT_JADWAL, SHIFT_MASUK, STATUS_HARI, OVERTIME_BONUS_FLAG, OVERTIME_NOTES, LAST_LATITUDE, LAST_LONGITUDE)
                    VALUES (:nik, :today, :shift_code, :now_time, 'HADIR', :ot_flag, :ot_note, :lat, :lon)";
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':shift_code' => $shift_code, ':now_time' => $now_time, ':ot_flag' => $overtime_flag,
            ':ot_note' => $overtime_note, ':lat' => $user_lat, ':lon' => $user_lon,
            ':nik' => $nik, ':today' => $today
        ]);
        
        $_SESSION['message'] = "✅ Absen Masuk **" . date('H:i', strtotime($now_time)) . "** berhasil!";
        
    } elseif ($action === 'pulang') {
        // Logika Absen Pulang
        $stmt_check = $conn->prepare("SELECT SHIFT_MASUK, SHIFT_PULANG, OVERTIME_BONUS_FLAG, SHIFT_JADWAL, OVERTIME_NOTES FROM T_ABSENSI WHERE KODESL = :nik AND TGL = :today");
        $stmt_check->execute([':nik' => $nik, ':today' => $today]);
        $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);

        // PDO fetch memberi string/datetime-string, bukan objek DateTime
        $has_masuk = ($existing && !empty($existing['SHIFT_MASUK']));
        $has_pulang = ($existing && !empty($existing['SHIFT_PULANG']));
        
        if (!$has_masuk || $has_pulang) {
             $_SESSION['message'] = "❌ Absen Pulang gagal. Cek status masuk Anda.";
             header('Location: dashboard_pegawai.php'); exit;
        }

        // Tentukan Overtime Bonus Pulang
        $current_shift = $existing['SHIFT_JADWAL'];
        $overtime_flag = $existing['OVERTIME_BONUS_FLAG'] ?? 0;
        $overtime_note = $existing['OVERTIME_NOTES'] ?? '';
        $new_ot_flag = $overtime_flag;
        $new_ot_note = $overtime_note;

        // S1: pulang setelah 19:00 dianggap OT (selaras laporan)
        if ($current_shift === 'S1' && strtotime($now_time) > strtotime('19:00:00')) {
            $new_ot_flag = 1;
            $new_ot_note .= (empty($overtime_note) ? '' : ' | ') . 'OT Pulang Malam (S1)';
        }
        
        $sql = "UPDATE T_ABSENSI SET 
                    SHIFT_PULANG = :now_time, 
                    OVERTIME_BONUS_FLAG = :ot_flag,
                    OVERTIME_NOTES = :ot_note,
                    LAST_LATITUDE = :lat, LAST_LONGITUDE = :lon
                WHERE KODESL = :nik AND TGL = :today";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':now_time' => $now_time, ':ot_flag' => $new_ot_flag, ':ot_note' => $new_ot_note,
            ':lat' => $user_lat, ':lon' => $user_lon,
            ':nik' => $nik, ':today' => $today
        ]);

        $_SESSION['message'] = "🎉 Absen Pulang **" . date('H:i', strtotime($now_time)) . "** berhasil!";

    } else {
        $_SESSION['message'] = "❌ Aksi tidak dikenal.";
    }
    
} catch (Exception $e) {
    $_SESSION['message'] = "❌ Database Error: " . $e->getMessage();
}

header('Location: dashboard_pegawai.php');
exit;
