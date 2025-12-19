<?php
require_once 'config.php'; // Sesuaikan jika config.php di folder berbeda

header('Content-Type: application/json');

$query = "SELECT TOP 20 KODEBRG, NAMABRG, ARTIKELBRG, HGJUAL, DISC, ST00, ST01, ST02, ST03, ST04 FROM V_BARANG ORDER BY NAMABRG";
$stmt = $conn->prepare($query);
$stmt->execute();

$data = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $kode = trim($row['KODEBRG']);
    $gambar = [];

    // Gambar utama + variasi (_1, _2, _3)
    $base_url = 'https://modecentre.cloud/img/produk/';
    $base_path = __DIR__ . '/img/produk/';
    if (file_exists($base_path . $kode . '.jpg')) {
        $gambar[] = $base_url . $kode . '.jpg';
        for ($i = 1; $i <= 3; $i++) {
            if (file_exists($base_path . "{$kode}_{$i}.jpg")) {
                $gambar[] = $base_url . "{$kode}_{$i}.jpg";
            }
        }
    }

    $total_stok = (int)$row['ST00'] + (int)$row['ST01'] + (int)$row['ST02'] + (int)$row['ST03'] + (int)$row['ST04'];

    $data[] = [
        'kode' => $kode,
        'nama' => $row['NAMABRG'],
        'artikel' => $row['ARTIKELBRG'],
        'harga' => number_format($row['HGJUAL'], 0, ',', '.'),
        'diskon' => number_format($row['DISC'], 0, ',', '.'),
        'stok' => $total_stok,
        'gambar' => $gambar
    ];
}

echo json_encode($data);
?>
