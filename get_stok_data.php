<?php
require_once 'config.php';
header('Content-Type: application/json');

function format_rp($val) {
    return 'Rp ' . number_format($val, 0, ',', '.');
}

$response = ['status' => 'error', 'message' => 'Tidak ada parameter pencarian.'];
$search_term = '';
$search_mode = '';

if (!empty($_GET['kode'])) {
    $search_term = trim($_GET['kode']);
    $search_mode = 'kode';
} elseif (!empty($_GET['artikel'])) {
    $search_term = trim($_GET['artikel']);
    $search_mode = 'artikel';
}

if ($search_mode) {
    try {
        global $conn;
        
        // DIUBAH: Menggunakan V_BARANG dan menghitung STOK_TOTAL secara manual
        $sql = "SELECT TOP 1 
                    KODEBRG, NAMABRG, ARTIKELBRG, HGJUAL, DISC, ST03, UMUR,
                    (ST01 + ST02 + ST03 + ST04 + ST99) AS STOK_TOTAL
                FROM V_BARANG ";

        if ($search_mode === 'kode') {
            $sql .= "WHERE KODEBRG = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$search_term]);
        } else { // search_mode === 'artikel'
            $sql .= "WHERE NAMABRG LIKE ? OR ARTIKELBRG LIKE ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute(['%' . $search_term . '%', '%' . $search_term . '%']);
        }

        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            $response = [
                'status' => 'success',
                'data' => [
                    'KODEBRG'    => $data['KODEBRG'],
                    'NAMABRG'    => $data['NAMABRG'],
                    'ARTIKELBRG' => $data['ARTIKELBRG'],
                    'HGJUAL_F'   => format_rp($data['HGJUAL']),
                    'DISC_F'     => $data['DISC'] . '%',
                    'ST03'       => (int)$data['ST03'],
                    'STOK_TOTAL' => (int)$data['STOK_TOTAL'],
                    'UMUR_F'     => $data['UMUR'] . ' hari' // DIUBAH: Menggunakan kolom UMUR
                ]
            ];
        } else {
            $response = ['status' => 'error', 'message' => 'Barang tidak ditemukan.'];
        }

    } catch (PDOException $e) {
        $response = ['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
    }
}

echo json_encode($response);
?>