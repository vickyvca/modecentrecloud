<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payroll/payroll_lib.php';

if (!isset($_SESSION['nik']) || ($_SESSION['role'] ?? '') !== 'pegawai') {
    die('Akses ditolak.');
}

$nik = $_SESSION['nik'];
$wa  = $_POST['wa'] ?? '';
$wa  = preg_replace('/[^0-9]/','',$wa);
if ($wa === '' || strlen($wa) < 10) {
    $_SESSION['message'] = 'Nomor WA tidak valid.';
    header('Location: dashboard_pegawai.php'); exit;
}

if (set_pegawai_wa($nik, $wa)) {
    $_SESSION['message'] = 'Nomor WA berhasil disimpan.';
} else {
    $_SESSION['message'] = 'Gagal menyimpan nomor WA.';
}
header('Location: dashboard_pegawai.php');
exit;

