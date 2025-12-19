<?php
require_once 'config.php';
session_start();

header('Content-Type: application/json');

$penerimaID = null;
$tipePenerima = null;

if (isset($_SESSION['nik'])) {
    $penerimaID = $_SESSION['nik'];
    $tipePenerima = 'pegawai';
} elseif (isset($_SESSION['kodesp'])) {
    $penerimaID = $_SESSION['kodesp'];
    $tipePenerima = 'supplier';
}

if (!$penerimaID) {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT TOP 10 ID, Pesan, Link, WaktuDibuat, SudahDilihat 
    FROM T_NOTIFIKASI 
    WHERE PenerimaID = ? AND TipePenerima = ? 
    ORDER BY WaktuDibuat DESC
");
$stmt->execute([$penerimaID, $tipePenerima]);
$notifikasi = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt_unread = $conn->prepare("SELECT COUNT(*) as total FROM T_NOTIFIKASI WHERE PenerimaID = ? AND TipePenerima = ? AND SudahDilihat = 0");
$stmt_unread->execute([$penerimaID, $tipePenerima]);
$unread_count = $stmt_unread->fetch(PDO::FETCH_ASSOC)['total'];

echo json_encode([
    'status' => 'success',
    'notifikasi' => $notifikasi,
    'unread_count' => $unread_count
]);
?>