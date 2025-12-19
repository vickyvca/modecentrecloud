<?php
// FILE: payroll/payroll_report_absensi.php

session_start();
require_once __DIR__ . '/payroll_lib.php';
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('Akses ditolak. Khusus admin.');
}

global $conn;

$ym = $_GET['bulan'] ?? date('Y-m');
$ym_label = date('F Y', strtotime($ym . '-01'));
[$d1, $d2] = ym_first_last_day($ym);

// Ambil semua pegawai yang terpilih
$selected_employees = get_selected_employees();
$report_data = [];

// Definisikan nilai bonus (samakan dengan payroll_dashboard/recalc_items)
define('BONUS_KEHADIRAN_FULL', 50000);
define('BONUS_KEHADIRAN_HALF', 25000);
define('BONUS_PER_OT', 5000);

// Proses data untuk setiap pegawai
foreach ($selected_employees as $employee) {
    $nik = $employee['nik'];
    $nama = $employee['nama'];
    
    // 1. Hitung total hari pengajuan libur/cuti/izin yang disetujui
    $total_absen_disetujui = 0;
    try {
        $sql_libur = "SELECT TGL_MULAI, TGL_SELESAI FROM T_PENGAJUAN_LIBUR 
                      WHERE KODESL = :nik AND STATUS = 'APPROVED'
                      AND TGL_MULAI <= :end_date AND TGL_SELESAI >= :start_date";
        $stmt_libur = $conn->prepare($sql_libur);
        $stmt_libur->execute([':nik' => $nik, ':start_date' => $d1, ':end_date' => $d2]);
        $leaves = $stmt_libur->fetchAll(PDO::FETCH_ASSOC);

        foreach ($leaves as $leave) {
            $start = new DateTime(max($d1, $leave['TGL_MULAI']));
            $end = new DateTime(min($d2, $leave['TGL_SELESAI']));
            // Tambahkan 1 hari karena diff tidak inklusif
            $total_absen_disetujui += $start->diff($end)->days + 1;
        }
    } catch (PDOException $e) { /* Abaikan jika error */ }

    // 2. Hitung total hari hadir dan OT dari T_ABSENSI
    $total_hadir = 0;
    $total_ot = 0;
    try {
        // Hadir
        $sql_hadir = "SELECT COUNT(*) FROM T_ABSENSI 
                      WHERE KODESL = :nik AND TGL BETWEEN :start_date AND :end_date 
                      AND (STATUS_HARI = 'HADIR' OR STATUS_HARI = 'HADIR_MANUAL')";
        $stmt_hadir = $conn->prepare($sql_hadir);
        $stmt_hadir->execute([':nik' => $nik, ':start_date' => $d1, ':end_date' => $d2]);
        $total_hadir = (int)$stmt_hadir->fetchColumn();

        // OT mengikuti payroll_dashboard: OVERTIME_BONUS_FLAG = 1
        $sql_ot = "SELECT COUNT(*) FROM T_ABSENSI 
                   WHERE KODESL = :nik AND TGL BETWEEN :start_date AND :end_date 
                   AND OVERTIME_BONUS_FLAG = 1";
        $stmt_ot = $conn->prepare($sql_ot);
        $stmt_ot->execute([':nik' => $nik, ':start_date' => $d1, ':end_date' => $d2]);
        $total_ot = (int)$stmt_ot->fetchColumn();
    } catch (PDOException $e) { /* Abaikan jika error */ }
    
    // 3. Hitung bonus kehadiran berdasarkan total absen
    $bonus_kehadiran = 0;
    if ($total_absen_disetujui == 0) {
        $bonus_kehadiran = BONUS_KEHADIRAN_FULL;
    } elseif ($total_absen_disetujui == 1) {
        $bonus_kehadiran = BONUS_KEHADIRAN_HALF;
    }
    
    // 4. Hitung bonus OT
    $bonus_ot = $total_ot * BONUS_PER_OT;

    // Gabungkan semua data untuk laporan
    $report_data[] = [
        'nik' => $nik,
        'nama' => $nama,
        'total_hadir' => $total_hadir,
        'total_absen_disetujui' => $total_absen_disetujui,
        'total_ot' => $total_ot,
        'bonus_kehadiran' => $bonus_kehadiran,
        'bonus_ot' => $bonus_ot
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Absensi - <?= htmlspecialchars((string)$ym_label) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/ui.css">
    <script defer src="../assets/js/ui.js"></script>
</head>
<body>
<div class="container">
    <div class="store-header">
        <img src="../assets/img/modecentre.png" alt="Mode Centre" class="logo">
        <div class="info">
            <div class="addr">Jl Pemuda Komp Ruko Pemuda 13 - 21 Banjarnegara Jawa Tengah</div>
            <div class="contact">Contact: 0813-9983-9777</div>
        </div>
    </div>
    <div class="header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <h1>Laporan Absensi: <?= htmlspecialchars((string)$ym_label) ?></h1>
        <form method="get" class="form" style="flex-direction:row;gap:10px;align-items:center;">
            <input type="month" name="bulan" value="<?= htmlspecialchars((string)$ym) ?>" onchange="this.form.submit()">
            <a href="payroll_dashboard.php" class="btn">Kembali ke Payroll</a>
        </form>
    </div>

    <div class="table-wrap table-card">
        <table class="table">
            <thead>
                <tr>
                    <th>NIK</th>
                    <th>Nama Pegawai</th>
                    <th>Hadir (Hari)</th>
                    <th>Absen Disetujui (Hari)</th>
                    <th>Lembur (OT)</th>
                    <th>Bonus Kehadiran (Rp)</th>
                    <th>Bonus Lembur OT (Rp)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($report_data)): ?>
                    <tr><td colspan="7">Tidak ada pegawai terpilih untuk diproses.</td></tr>
                <?php endif; ?>
                <?php foreach ($report_data as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['nik']) ?></td>
                    <td><?= htmlspecialchars($row['nama']) ?></td>
                    <td><?= $row['total_hadir'] ?></td>
                    <td><?= $row['total_absen_disetujui'] ?></td>
                    <td><?= $row['total_ot'] ?></td>
                    <td>Rp <?= fmt_idr($row['bonus_kehadiran']) ?></td>
                    <td>Rp <?= fmt_idr($row['bonus_ot']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card" style="margin-top:12px;">
        <p class="muted"><strong>Cara Perhitungan Bonus:</strong></p>
        <ul style="margin:0 0 0 16px;">
            <li><strong>Bonus Kehadiran:</strong> 0 hari absen disetujui = Rp <?= fmt_idr(BONUS_KEHADIRAN_FULL) ?>, 1 hari = Rp <?= fmt_idr(BONUS_KEHADIRAN_HALF) ?>, 2+ hari = Rp 0.</li>
            <li><strong>Bonus Lembur (OT):</strong> Rp <?= fmt_idr(BONUS_PER_OT) ?> per kejadian. Penentuan OT mengikuti payroll (kolom OVERTIME_BONUS_FLAG pada T_ABSENSI).</li>
        </ul>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
  if (window.enableAutoPaging) { enableAutoPaging({ pageSize: 25, selector: 'table' }); }
});
</script>
</body>
</html>
