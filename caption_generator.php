<?php
session_start();
if (!isset($_SESSION['nik']) || $_SESSION['role'] !== 'admin') {
    die("Akses ditolak. Fitur ini hanya untuk admin.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caption Generator E-Commerce</title>
    <style>
        :root {
            --primary-bg: #121212; --secondary-bg: #1E1E1E; --accent-color: #e53935;
            --primary-text: #F5F5F5; --secondary-text: #BDBDBD; --border-color: #424242;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--primary-bg); color: var(--primary-text); padding: 20px; margin: 0; }
        .container { background: var(--secondary-bg); padding: 30px; max-width: 700px; margin: 20px auto; border-radius: 12px; border: 1px solid var(--border-color); }
        h2 { color: var(--accent-color); margin-top: 0; }
        a.back { background: #424242; color: white; padding: 9px 15px; text-decoration: none; display: inline-block; margin-bottom: 20px; border-radius: 8px; font-size: 14px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: var(--secondary-text); font-size: 14px; }
        input[type="text"], input[type="file"], textarea {
            width: 100%; padding: 12px; font-size: 16px; background-color: #333;
            border: 1px solid var(--border-color); border-radius: 8px; color: var(--primary-text); box-sizing: border-box;
        }
        button { width: 100%; padding: 14px; font-size: 16px; font-weight: bold; background-color: var(--accent-color); color: white; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.2s; }
        button:hover { background-color: #c62828; }
        button:disabled { background-color: #555; cursor: not-allowed; }
        .results-container { margin-top: 30px; display: none; }
        .result-box { background: #2a2a2a; padding: 20px; border-radius: 8px; margin-bottom: 15px; position: relative; }
        .result-box h3 { margin-top: 0; }
        .result-box textarea { width: 100%; height: 200px; font-size: 14px; line-height: 1.6; }
        .result-box input[type="text"] { font-size: 14px; margin-bottom: 10px; }
        .copy-btn { position: absolute; top: 15px; right: 15px; font-size: 12px; padding: 6px 12px; background-color: #4CAF50; border: none; color: white; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>
<div class="container">
    <a href="dashboard_admin.php" class="back">🔙 Kembali ke Dashboard</a>
    <h2>🚀 E-Commerce Post Helper</h2>
    <form id="productForm" enctype="multipart/form-data">
        <div class="form-group">
            <label for="product_image">1. Upload Foto Produk</label>
            <input type="file" id="product_image" name="product_image" accept="image/*" required>
        </div>
        <div class="form-group">
            <label for="kode_barang">2. Masukkan Kode Barang (KODEBRG)</label>
            <input type="text" id="kode_barang" name="kode_barang" placeholder="Contoh: 001020123" required>
        </div>
        <button type="submit" id="submitBtn">Buat Konten Sekarang!</button>
    </form>

    <div id="results" class="results-container">
        <div class="result-box">
            <h3 style="color: #E040FB;">📱 Caption Sosial Media</h3>
            <button class="copy-btn" onclick="copyToClipboard('caption_sosmed')">Salin</button>
            <textarea id="caption_sosmed" readonly></textarea>
        </div>
        <div class="result-box">
            <h3 style="color: #4CAF50;">🛒 Info Marketplace</h3>
            <label for="nama_produk_marketplace">Nama Produk</label>
            <input type="text" id="nama_produk_marketplace" readonly>
            
            <label for="deskripsi_marketplace" style="margin-top:10px;">Deskripsi Produk</label>
            <textarea id="deskripsi_marketplace" readonly></textarea>
        </div>
    </div>
</div>

<script>
    function copyToClipboard(elementId) {
        const element = document.getElementById(elementId);
        element.select();
        document.execCommand('copy');
        alert('Teks berhasil disalin!');
    }

    document.getElementById('productForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('submitBtn');
        const resultsDiv = document.getElementById('results');
        const formData = new FormData(this);
        
        resultsDiv.style.display = 'none';
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sedang membuat konten...';
        
        try {
            const response = await fetch('proses_caption.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.status === 'success') {
                document.getElementById('caption_sosmed').value = result.data.caption_sosmed;
                document.getElementById('nama_produk_marketplace').value = result.data.nama_produk_marketplace;
                document.getElementById('deskripsi_marketplace').value = result.data.deskripsi_marketplace;
                resultsDiv.style.display = 'block';
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            console.error(error);
            alert('Terjadi kesalahan jaringan atau server. Silakan coba lagi.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Buat Konten Sekarang!';
        }
    });
</script>
</body>
</html>
