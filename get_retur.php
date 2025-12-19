<?php
require_once 'config.php';

header('Content-Type: application/json');

$nobukti = $_GET['nobukti'] ?? '';

if (!$nobukti) {
    echo json_encode([]);
    exit;
}

// Step 1: cari NONOTA dari HIS_RETURBELI berdasarkan NOBUKTI
$stmt = $conn->prepare("SELECT NONOTA FROM HIS_RETURBELI WHERE NOBUKTI = :nobukti");
$stmt->execute(['nobukti' => $nobukti]);
$retur = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$retur) {
    echo json_encode([]);
    exit;
}

$nonota = $retur['NONOTA'];

// Step 2: ambil detail retur dari HIS_DTRETURBELI dan T_BARANG
$stmt2 = $conn->prepare("
    SELECT T.NAMABRG + ' ' + T.ARTIKELBRG AS BARANG, D.HGBELI,
           D.QTY00 + D.QTY01 + D.QTY02 + D.QTY03 + D.QTY04 + D.QTY99 AS TTL,
           D.HGBELI * (D.QTY00 + D.QTY01 + D.QTY02 + D.QTY03 + D.QTY04 + D.QTY99) AS NILAI
    FROM HIS_DTRETURBELI D
    INNER JOIN T_BARANG T ON T.ID = D.ID
    WHERE D.NONOTA = :nonota
");
$stmt2->execute(['nonota' => $nonota]);
$detail = $stmt2->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($detail);
