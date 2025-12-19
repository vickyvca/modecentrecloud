<?php
session_start();
if (!isset($_SESSION['nik']) || $_SESSION['role'] !== 'pegawai') {
    header("Location: login_pegawai.php");
    exit;
}

require_once 'config.php';

$data = null;
$input_val = '';
$kriteria = 'kodebrg';
$kode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_val = trim($_POST['keyword'] ?? '');
    $kriteria = $_POST['kriteria'] ?? 'kodebrg';
    $kode = trim($input_val, '*');

    if ($kode !== '') {
        $field = ($kriteria === 'artikel') ? 'ARTIKELBRG' : 'KODEBRG';
        $search = "%$kode%";
        $stmt = $conn->prepare("
            SELECT KODEBRG, NAMABRG, ARTIKELBRG, HGJUAL, DISC, UMUR,
                   ST00, ST01, ST02, ST03, ST04, ST99
            FROM V_BARANG WHERE $field LIKE :search
        ");
        $stmt->execute(['search' => $search]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cek Barang Sales</title>
    <style>
        :root {
            --primary-bg: #121212;
            --secondary-bg: #1E1E1E;
            --accent-color: #e53935;
            --primary-text: #F5F5F5;
            --border-color: #424242;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--primary-bg); color: var(--primary-text); padding: 15px; margin: 0; }
        .container { background: var(--secondary-bg); padding: 25px; max-width: 700px; margin: auto; border-radius: 12px; border: 1px solid var(--border-color); }
        h2 { color: var(--accent-color); margin-top: 0; }
        form { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 25px; }
        form select { flex-basis: 150px; }
        form input { flex: 1; }
        form button { flex-basis: 100px; }
        input, select, button {
            padding: 12px; font-size: 16px; border-radius: 8px; box-sizing: border-box;
            background-color: #333; border: 1px solid var(--border-color); color: var(--primary-text);
        }
        button { background-color: var(--accent-color); color: white; cursor: pointer; border: none; font-weight: bold; }
        .result-box { margin-top: 20px; }
        .result-header h3 { margin: 0; font-size: 1.4em; }
        .result-header p { margin: 5px 0 20px 0; color: #BDBDBD; }
        .result-details { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .info-card { background: #2a2a2a; padding: 15px; border-radius: 8px; }
        .info-card strong { display: block; color: #BDBDBD; font-size: 0.9em; margin-bottom: 5px; }
        .info-card span { font-size: 1.2em; font-weight: 500; color: var(--primary-text); }
        .stock-table { width: 100%; margin-top: 20px; border-collapse: collapse; }
        .stock-table th, .stock-table td { padding: 10px; border: 1px solid var(--border-color); text-align: left; }
        .stock-table th { background-color: #333; }
        .total-stock { font-weight: bold; background-color: var(--accent-color) !important; color: white; }
        .not-found { color: #ef9a9a; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <h2>🔍 Cek Barang Sales</h2>
    <form method="POST">
        <select name="kriteria">
            <option value="kodebrg" <?= $kriteria == 'kodebrg' ? 'selected' : '' ?>>KODE</option>
            <option value="artikel" <?= $kriteria == 'artikel' ? 'selected' : '' ?>>ARTIKEL</option>
        </select>
        <input type="text" name="keyword" placeholder="Scan atau ketik di sini..." required autofocus value="<?= htmlspecialchars($input_val) ?>">
        <button type="submit">Cari</button>
    </form>

    <?php if ($data):
        $harga = (float) $data['HGJUAL'];
        $disc = (float) $data['DISC'];
        $netto = $harga - ($harga * $disc / 100);
        $total = $data['ST01'] + $data['ST02'] + $data['ST03'] + $data['ST04'] + $data['ST99'];
    ?>
        <div class="result-box">
            <div class="result-header">
                <h3><?= htmlspecialchars($data['NAMABRG']) ?></h3>
                <p>Kode: <?= $data['KODEBRG'] ?> | Artikel: <?= $data['ARTIKELBRG'] ?></p>
            </div>
            <div class="result-details">
                <div class="info-card"><strong>Harga Netto</strong><span>Rp <?= number_format($netto) ?></span></div>
                <div class="info-card"><strong>Diskon</strong><span><?= $data['DISC'] ?>%</span></div>
                <div class="info-card"><strong>Harga Jual</strong><span>Rp <?= number_format($data['HGJUAL']) ?></span></div>
                <div class="info-card"><strong>Umur Barang</strong><span><?= $data['UMUR'] ?> hari</span></div>
            </div>
            <table class="stock-table">
                <thead><tr><th>Lokasi</th><th>Stok</th></tr></thead>
                <tbody>
                    <tr><td>Dipayuda (ST01)</td><td><?= $data['ST01'] ?></td></tr>
                    <tr><td>Veteran (ST02)</td><td><?= $data['ST02'] ?></td></tr>
                    <tr><td>Pemuda (ST03)</td><td><?= $data['ST03'] ?></td></tr>
                    <tr><td>Toko Baru (ST04)</td><td><?= $data['ST04'] ?></td></tr>
                    <tr><td>Gudang (ST00)</td><td><?= $data['ST00'] ?></td></tr>
                    <tr><td>Rusak (ST99)</td><td><?= $data['ST99'] ?></td></tr>
                    <tr class="total-stock"><td>TOTAL STOK</td><td><?= $total ?></td></tr>
                </tbody>
            </table>
        </div>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <p class="not-found">Barang tidak ditemukan untuk: <strong><?= htmlspecialchars($kode) ?></strong></p>
    <?php endif; ?>
</div>
</body>
</html>
