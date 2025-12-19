<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payroll/payroll_lib.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { die('Akses ditolak.'); }

$date = $_POST['date'] ?? $_GET['date'] ?? date('Y-m-d');
$group = $_POST['group'] ?? $_GET['group'] ?? 'all';
$msg   = trim($_POST['msg'] ?? $_GET['msg'] ?? '');

global $conn;

// Kumpulkan NIK sesuai group
$niks = [];
try {
  if ($group === 'all') {
    $sql = "SELECT DISTINCT KODESL FROM T_ABSENSI WHERE TGL = :d";
    $st = $conn->prepare($sql); $st->execute([':d'=>$date]);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){ $niks[] = $r['KODESL']; }
  } elseif (in_array($group, ['s1','s2'])) {
    $shift = $group==='s1' ? 'S1':'S2';
    $sql = "SELECT KODESL FROM T_ABSENSI WHERE TGL = :d AND SHIFT_JADWAL = :s";
    $st = $conn->prepare($sql); $st->execute([':d'=>$date, ':s'=>$shift]);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){ $niks[] = $r['KODESL']; }
  } elseif ($group === 'libur') {
    $sql = "SELECT KODESL FROM T_ABSENSI WHERE TGL = :d AND UPPER(STATUS_HARI) IN ('LIBUR','SAKIT','CUTI','IZIN')";
    $st = $conn->prepare($sql); $st->execute([':d'=>$date]);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){ $niks[] = $r['KODESL']; }
  } elseif ($group === 'ot') {
    $sql = "SELECT KODESL FROM T_ABSENSI WHERE TGL = :d AND OVERTIME_BONUS_FLAG = 1";
    $st = $conn->prepare($sql); $st->execute([':d'=>$date]);
    while($r=$st->fetch(PDO::FETCH_ASSOC)){ $niks[] = $r['KODESL']; }
  }
} catch (PDOException $e) { $niks=[]; }

$niks = array_values(array_unique($niks));

// Ambil WA pegawai
$wa_map = get_pegawai_wa_map();
$sent = 0; $skipped = 0;
foreach ($niks as $nik) {
  $wa = $wa_map[$nik] ?? '';
  if ($wa) { kirimWATeksFonnte($wa, $msg !== '' ? $msg : ('Info shift ' . date('d/m/Y', strtotime($date)))); $sent++; }
  else { $skipped++; }
}

$_SESSION['payroll_msg'] = "WA terkirim: $sent, tanpa WA: $skipped";
header('Location: dashboard_admin.php?kal_bulan=' . urlencode(date('Y-m', strtotime($date))));
exit;

