<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Koneksi database (PDO SQLSRV)
try {
    $conn = new PDO("sqlsrv:Server=192.168.4.99;Database=MODECENTRE", "sa", "mode1234ABC", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
    ]);
} catch (PDOException $e) {
    die('Koneksi gagal: ' . $e->getMessage());
}

$caption = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kode_barang'])) {
    $kode = $_POST['kode_barang'];
    $stmt = $conn->prepare("SELECT TOP 1 * FROM V_BARANG WHERE KODEBRG = ?");
    $stmt->execute([$kode]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($data) {
        $stok = (int)($data['ST03'] ?? 0);
        $caption = $data['NAMABRG'] . ' - ' . $data['ARTIKELBRG'] . "\n"
                 . 'Kode: ' . $data['KODEBRG'] . "\n"
                 . 'Harga: Rp ' . number_format((float)$data['HGJUAL'], 0, ',', '.') . "\n"
                 . 'Stok tersedia: ' . $stok . "\n\n"
                 . 'Bahan nyaman dipakai, cocok untuk daily, casual, hingga semi-formal\n'
                 . 'Produk real pict - warna sesuai\n'
                 . 'Garansi retur 1x24 jam (dengan video unboxing)\n'
                 . '#' . preg_replace('/\W+/', '', $data['KODEBRG']);
    } else {
        $caption = 'Barang tidak ditemukan.';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Generate Caption Marketplace</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <style>
        body { padding: 30px; }
        .caption-box textarea {
            width: 100%; height: 260px; background: var(--bg); color: var(--text);
            border: 1px solid var(--border); padding: 15px; border-radius: var(--radius-sm);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        }
    </style>
</head>
<body>
    <div class="container" style="max-width:720px;">
        <div class="card fade-in">
            <h1 class="mb-2">Generate Caption Marketplace</h1>
            <form method="post" class="form">
                <div>
                    <label class="muted">Kode Barang (KODEBRG)</label>
                    <input type="text" name="kode_barang" required>
                </div>
                <button type="submit" class="btn btn-primary">Generate Caption</button>
            </form>

            <?php if ($caption): ?>
                <div class="caption-box mt-3">
                    <label class="muted">Caption</label>
                    <textarea id="caption-box" readonly><?= htmlspecialchars($caption) ?></textarea>
                    <button class="btn mt-2" onclick="copyCaption()">Copy Caption</button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function copyCaption() {
            const textArea = document.getElementById('caption-box');
            textArea.select();
            document.execCommand('copy');
            alert('Caption berhasil disalin!');
        }
    </script>
</body>
</html>
