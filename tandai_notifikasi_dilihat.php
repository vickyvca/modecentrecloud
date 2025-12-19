<?php
require_once 'config.php';
session_start();

$penerimaID = null;
$tipePenerima = null;

if (isset($_SESSION['nik'])) {
    $penerimaID = $_SESSION['nik'];
    $tipePenerima = 'pegawai';
} elseif (isset($_SESSION['kodesp'])) {
    $penerimaID = $_SESSION['kodesp'];
    $tipePenerima = 'supplier';
}

if ($penerimaID) {
    $stmt = $conn->prepare("UPDATE T_NOTIFIKASI SET SudahDilihat = 1 WHERE PenerimaID = ? AND TipePenerima = ? AND SudahDilihat = 0");
    $stmt->execute([$penerimaID, $tipePenerima]);
}

echo json_encode(['status' => 'success']);
?>