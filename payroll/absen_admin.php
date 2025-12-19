<?php
// FILE: payroll/absen_admin.php

session_start();
require_once __DIR__ . '/payroll_lib.php'; 
require_once __DIR__ . '/../config.php'; 

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('Akses ditolak. Khusus admin.');
}

global $conn;

$date = $_GET['date'] ?? date('Y-m-d');
$action = $_GET['action'] ?? null;
$nik = $_GET['nik'] ?? null;
$status = $_GET['status'] ?? null;

// Variabel untuk input waktu manual
$manual_masuk = $_GET['manual_masuk'] ?? null;
$manual_pulang = $_GET['manual_pulang'] ?? null;

// Fungsi utility
function get_sales_name(PDO $conn, $nik) {
    try {
        $stmt = $conn->prepare("SELECT TOP 1 NAMASL FROM T_SALES WHERE KODESL = :nik");
        $stmt->execute([':nik' => $nik]);
        return $stmt->fetchColumn() ?? 'N/A';
    } catch (PDOException $e) { return 'DB Error'; }
}

// Logika Set Status (Libur/Sakit/Cuti)
if ($action === 'set_status' && $nik && $date && $status) {
    try {
        $expected_shift = get_expected_shift_for_date($nik, $date) ?? 'S1'; // fallback ke jadwal rotasi tim
        
        if ($status === 'HADIR_MANUAL') {
            $sql_update = "UPDATE T_ABSENSI SET STATUS_HARI = :status, SHIFT_JADWAL = ISNULL(NULLIF(SHIFT_JADWAL,''), :shift), SHIFT_MASUK = NULL, SHIFT_PULANG = NULL, OVERTIME_BONUS_FLAG = 0 WHERE KODESL = :kodesl AND TGL = :tgl";
            $stmt = $conn->prepare($sql_update);
            $stmt->execute([':kodesl' => $nik, ':tgl' => $date, ':status' => $status, ':shift' => $expected_shift]);
        } else {
            $sql_update = "UPDATE T_ABSENSI SET STATUS_HARI = :status, SHIFT_JADWAL = ISNULL(NULLIF(SHIFT_JADWAL,''), :shift), SHIFT_MASUK = NULL, SHIFT_PULANG = NULL, OVERTIME_BONUS_FLAG = 0 WHERE KODESL = :kodesl AND TGL = :tgl";
            $stmt = $conn->prepare($sql_update);
            $stmt->execute([':kodesl' => $nik, ':tgl' => $date, ':status' => $status, ':shift' => $expected_shift]);
        }
        
        if ($stmt->rowCount() === 0) {
            if ($status === 'HADIR_MANUAL') {
                $sql_insert = "INSERT INTO T_ABSENSI (KODESL, TGL, STATUS_HARI, SHIFT_JADWAL) VALUES (:kodesl, :tgl, :status, :shift)";
                $stmt = $conn->prepare($sql_insert);
                $stmt->execute([':kodesl' => $nik, ':tgl' => $date, ':status' => $status, ':shift' => $expected_shift]);
            } else {
                $sql_insert = "INSERT INTO T_ABSENSI (KODESL, TGL, STATUS_HARI, SHIFT_JADWAL) VALUES (:kodesl, :tgl, :status, :shift)";
                $stmt = $conn->prepare($sql_insert);
                $stmt->execute([':kodesl' => $nik, ':tgl' => $date, ':status' => $status, ':shift' => $expected_shift]);
            }
        }
        $_SESSION['absen_msg'] = "✅ Status " . get_sales_name($conn, $nik) . " ($date) diubah menjadi **" . strtoupper($status) . "**.";
        
    } catch (PDOException $e) {
        $_SESSION['absen_msg'] = "❌ Error DB: " . htmlspecialchars($e->getMessage());
    }
    header("Location: absen_admin.php?date=$date");
    exit;
}

// Logika Set Waktu Manual
if ($action === 'set_time' && $nik && $date && ($manual_masuk || $manual_pulang)) {
    try {
        $fields = [];
        $params = [':kodesl' => $nik, ':tgl' => $date];
        
        if ($manual_masuk) {
            $fields[] = "SHIFT_MASUK = :masuk";
            $params[':masuk'] = $manual_masuk;
        }
        if ($manual_pulang) {
            $fields[] = "SHIFT_PULANG = :pulang";
            $params[':pulang'] = $manual_pulang;
        }
        
        $set_clause = implode(', ', $fields);
        
        $sql = "UPDATE T_ABSENSI SET $set_clause, STATUS_HARI = 'HADIR_MANUAL' WHERE KODESL = :kodesl AND TGL = :tgl";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        $_SESSION['absen_msg'] = "✅ Jam Absen " . get_sales_name($conn, $nik) . " ($date) berhasil di-update.";
        
    } catch (PDOException $e) {
        $_SESSION['absen_msg'] = "❌ Error DB saat update waktu: " . htmlspecialchars($e->getMessage());
    }
    header("Location: absen_admin.php?date=$date");
    exit;
}

// Logika untuk Tukar Shift
if ($action === 'tukar_shift' && $nik && $date) {
    $current_shift = $_GET['current_shift'] ?? null;
    if ($current_shift === 'S1' || $current_shift === 'S2') {
        $new_shift = ($current_shift === 'S1') ? 'S2' : 'S1';
        try {
            $sql = "UPDATE T_ABSENSI SET SHIFT_JADWAL = :new_shift, OVERTIME_BONUS_FLAG = 0 WHERE KODESL = :nik AND TGL = :tgl";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':new_shift' => $new_shift,
                ':nik' => $nik,
                ':tgl' => $date
            ]);
            if ($stmt->rowCount() === 0) {
                $ins = $conn->prepare("INSERT INTO T_ABSENSI (KODESL, TGL, STATUS_HARI, SHIFT_JADWAL, OVERTIME_BONUS_FLAG) VALUES (:nik, :tgl, 'HADIR', :shift, 0)");
                $ins->execute([':nik' => $nik, ':tgl' => $date, ':shift' => $new_shift]);
            }
            $_SESSION['absen_msg'] = "✅ Shift " . get_sales_name($conn, $nik) . " ($date) berhasil ditukar ke **$new_shift**.";

        } catch (PDOException $e) {
            $_SESSION['absen_msg'] = "❌ Error DB saat tukar shift: " . htmlspecialchars($e->getMessage());
        }
    } else {
        $_SESSION['absen_msg'] = "⚠️ Aksi tukar shift tidak valid.";
    }
    header("Location: absen_admin.php?date=$date");
    exit;
}

// Hapus record absensi (CRUD - Delete)
if ($action === 'delete' && $nik && $date) {
    try {
        $stmt = $conn->prepare("DELETE FROM T_ABSENSI WHERE KODESL = :nik AND TGL = :tgl");
        $stmt->execute([':nik' => $nik, ':tgl' => $date]);
        $_SESSION['absen_msg'] = "Sukses: Record absensi $nik ($date) dihapus.";
    } catch (PDOException $e) {
        $_SESSION['absen_msg'] = "Error DB saat hapus: " . htmlspecialchars($e->getMessage());
    }
    header("Location: absen_admin.php?date=$date");
    exit;
}

// Buat/Reset hadir kosong (CRUD - Create/Upsert)
if ($action === 'create_hadir' && $nik && $date) {
    try {
        $expected_shift = get_expected_shift_for_date($nik, $date) ?? 'S1';
        $sql = "IF EXISTS (SELECT 1 FROM T_ABSENSI WHERE KODESL = :nik AND TGL = :tgl)
                  UPDATE T_ABSENSI SET STATUS_HARI = 'HADIR', SHIFT_JADWAL = ISNULL(NULLIF(SHIFT_JADWAL,''), :shift), SHIFT_MASUK = NULL, SHIFT_PULANG = NULL, OVERTIME_BONUS_FLAG = 0 WHERE KODESL = :nik AND TGL = :tgl
                ELSE
                  INSERT INTO T_ABSENSI (KODESL, TGL, STATUS_HARI, SHIFT_JADWAL) VALUES (:nik, :tgl, 'HADIR', :shift)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':nik' => $nik, ':tgl' => $date, ':shift' => $expected_shift]);
        $_SESSION['absen_msg'] = "Sukses: Record hadir $nik ($date) dibuat/diperbarui.";
    } catch (PDOException $e) {
        $_SESSION['absen_msg'] = "Error DB saat membuat hadir: " . htmlspecialchars($e->getMessage());
    }
    header("Location: absen_admin.php?date=$date");
    exit;
}

// Tampilan Data Absensi Harian
$selected_employees = get_selected_employees();
$expected_shift_map = [];
foreach ($selected_employees as $spg) {
    $expected_shift_map[$spg['nik']] = get_expected_shift_for_date($spg['nik'], $date);
}
$absen_data_map = [];

if (!empty($selected_employees)) {
    $niks = array_column($selected_employees, 'nik');
    $placeholders = implode(',', array_fill(0, count($niks), '?'));
    
    $sql = "SELECT KODESL, SHIFT_MASUK, SHIFT_PULANG, STATUS_HARI, SHIFT_JADWAL, OVERTIME_BONUS_FLAG
            FROM T_ABSENSI 
            WHERE TGL = ? AND KODESL IN ($placeholders)";
            
    $params = array_merge([$date], $niks);
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $absen_data_map[$row['KODESL']] = $row;
    }
}

$message = $_SESSION['absen_msg'] ?? null;
unset($_SESSION['absen_msg']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Admin Absensi Harian - <?= date('d M Y', strtotime($date)) ?></title>
    <link rel="stylesheet" href="../assets/css/ui.css">
    <script defer src="../assets/js/ui.js"></script>
    <style>
        body { font-family: sans-serif; background: #121218; color: #e0e0e0; margin: 0; padding: 0;}
        .container { max-width: 1200px; margin: 20px auto; padding: 0 15px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card { background: #1e1e24; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #333; }
        .table-wrap { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 10px; border: 1px solid #333; text-align: center; white-space: nowrap; }
        .table th { background: #2a2a32; }
        .btn-action { padding: 5px 10px; margin: 2px; font-size: 0.8em; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; border: none; }
        .btn-hadir { background: #58d68d; color: #121218; }
        .btn-libur { background: #f39c12; color: #121218; }
        .btn-cuti { background: #4fc3f7; color: #121218; }
        .btn-sakit { background: #e74c3c; color: white; }
        .btn-tukar { background: #9b59b6; color: white; }
        .status-hadir, .status-hadir_manual { color: #58d68d; font-weight: bold; }
        .status-libur { color: #f39c12; }
        .status-cuti { color: #4fc3f7; }
        .status-sakit { color: #e74c3c; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; font-weight: bold; background: #4caf50; color: white; }
        .time-input-group { display: flex; gap: 5px; justify-content: center; align-items: center; }
        .time-input { width: 60px; padding: 5px; background: #333; border: 1px solid #444; color: #e0e0e0; border-radius: 3px; }
        .time-submit-btn { background: #007bff; color: white; padding: 4px 8px; border: none; border-radius: 3px; cursor: pointer; font-size: 0.7em; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Admin Koreksi Absensi Harian</h1>
        <a href="../dashboard_admin.php" class="btn btn-secondary">Kembali ke Dashboard</a>
    </div>

    <?php if ($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>Absensi Tanggal: <?= date('d F Y', strtotime($date)) ?></h3>
        <form method="get" style="margin-bottom: 15px;">
            <input type="date" name="date" value="<?= $date ?>" onchange="this.form.submit()">
        </form>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>NIK</th>
                        <th>Nama</th>
                        <th>Jadwal Seharusnya</th>
                        <th>Masuk</th>
                        <th>Pulang</th>
                        <th>Status Saat Ini</th>
                        <th>Koreksi Waktu Manual</th>
                        <th>Koreksi Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($selected_employees as $spg): 
                        $nik = $spg['nik'];
                        $absen = $absen_data_map[$nik] ?? null;
                        $nama = get_sales_name($conn, $nik);
                        $expected_shift = $expected_shift_map[$nik] ?? null;

                        // [FIX] Tambahkan pengecekan if ($absen) untuk menghindari error jika data absen null
                        if ($absen) {
                            $status_current = $absen['STATUS_HARI'] ?? 'BELUM ABSEN';
                            $jadwal_code = $absen['SHIFT_JADWAL'] ?? null;
                            if (!$jadwal_code) $jadwal_code = $expected_shift ?? 'N/A';
                            $masuk_str = $absen['SHIFT_MASUK'] ? date('H:i', strtotime($absen['SHIFT_MASUK'])) : '-';
                            $pulang_str = $absen['SHIFT_PULANG'] ? date('H:i', strtotime($absen['SHIFT_PULANG'])) : '-';
                            $default_masuk = $absen['SHIFT_MASUK'] ? date('H:i', strtotime($absen['SHIFT_MASUK'])) : '';
                            $default_pulang = $absen['SHIFT_PULANG'] ? date('H:i', strtotime($absen['SHIFT_PULANG'])) : '';
                            $has_ot = !empty($absen['OVERTIME_BONUS_FLAG']);
                        } else {
                            // [FIX] Sediakan nilai default jika $absen adalah null
                            $status_current = 'BELUM ABSEN';
                            $jadwal_code = $expected_shift ?? 'N/A';
                            $masuk_str = '-';
                            $pulang_str = '-';
                            $default_masuk = '';
                            $default_pulang = '';
                            $has_ot = false;
                        }
                        
                        $jadwal_display = $jadwal_code;
                        if ($jadwal_code === 'S1') $jadwal_display = 'Pagi';
                        if ($jadwal_code === 'S2') $jadwal_display = 'Siang';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($nik) ?></td>
                        <td><?= htmlspecialchars($nama) ?></td>
                        <td><?= $jadwal_display ?></td>
                        <td><?= $masuk_str ?></td>
                        <td><?= $pulang_str ?></td>
                        <td class="status-<?= strtolower($status_current) ?>">
                            <?= $status_current ?> 
                            <?php if ($has_ot): ?> (OT) <?php endif; ?>
                        </td>
                        
                        <td>
                            <form method="get" class="time-input-group">
                                <input type="hidden" name="action" value="set_time">
                                <input type="hidden" name="date" value="<?= $date ?>">
                                <input type="hidden" name="nik" value="<?= $nik ?>">
                                
                                <input type="time" name="manual_masuk" class="time-input" value="<?= $default_masuk ?>">
                                <input type="time" name="manual_pulang" class="time-input" value="<?= $default_pulang ?>">
                                
                                <button type="submit" class="time-submit-btn">Simpan</button>
                            </form>
                        </td>

                        <td>
                            <?php if ($jadwal_code === 'S1' || $jadwal_code === 'S2'): ?>
                                <a href="?action=tukar_shift&date=<?= $date ?>&nik=<?= $nik ?>&current_shift=<?= $jadwal_code ?>" class="btn-action btn-tukar">Tukar Shift</a>
                            <?php endif; ?>
                            
                            <a href="?action=set_status&date=<?= $date ?>&nik=<?= $nik ?>&status=LIBUR" class="btn-action btn-libur">Libur</a>
                            <a href="?action=set_status&date=<?= $date ?>&nik=<?= $nik ?>&status=CUTI" class="btn-action btn-cuti">Cuti</a>
                            <a href="?action=set_status&date=<?= $date ?>&nik=<?= $nik ?>&status=SAKIT" class="btn-action btn-sakit">Sakit</a>
                            <a href="?action=create_hadir&date=<?= $date ?>&nik=<?= $nik ?>" class="btn-action btn-hadir">Set Hadir</a>
                            <a href="?action=delete&date=<?= $date ?>&nik=<?= $nik ?>" class="btn-action btn-sakit" onclick="return confirm('Hapus record absensi <?= $nik ?> pada tanggal <?= $date ?>?')">Hapus</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
