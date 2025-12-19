<?php
require_once 'config.php';
session_start();

if (!isset($_SESSION['nik']) || ($_SESSION['is_admin'] ?? false)) {
    die("Akses ditolak.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Cek Stok Barang</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --brand-bg: #d32f2f; --bg: #121218; --card: #1e1e24; --text: #e0e0e0;
            --text-muted: #8b8b9a; --border: #33333d; --green: #58d68d; --blue: #4fc3f7;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); padding: 16px; }
        .container { max-width: 600px; margin: 16px auto; }
        h1 { font-size: 24px; } .header { display: flex; align-items: center; gap: 16px; margin-bottom: 16px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 24px; margin-bottom: 24px; }
        
        .btn { border: 1px solid var(--border); background: #2a2a32; color: var(--text); padding: 10px 16px; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 500; }
        .btn-primary { background: var(--brand-bg); border-color: transparent; color: white; width: 100%; justify-content: center;}
        
        .tab-navigation { display: flex; background-color: var(--bg); border-radius: 8px; padding: 4px; margin-bottom: 24px; }
        .tab-button { flex: 1; padding: 10px; background: none; border: none; color: var(--text-muted); cursor: pointer; border-radius: 6px; font-weight: 600; }
        .tab-button.active { color: var(--text); background: var(--border); }
        .tab-pane { display: none; } .tab-pane.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        #scanTab label { display: block; margin-bottom: 8px; color: var(--text-muted); font-size: 14px; }
        #cameraSelect { width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        #preview { width: 100%; aspect-ratio: 16 / 10; border-radius: 12px; border: 2px solid var(--border); background: #000; overflow: hidden; }
        #preview video { width: 100%; height: 100%; object-fit: cover; }
        
        .manual-search-section { margin-top: 16px; }
        .manual-search-section h3 { font-size: 16px; color: var(--text-muted); margin: 0 0 8px 0; }
        .input-manual { width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 12px; border-radius: 8px; margin-bottom: 8px; }

        /* Gaya Kartu Hasil Baru */
        #scanResult { padding-top: 24px; }
        .result-title { font-size: 22px; font-weight: 700; color: var(--text); margin: 0 0 16px 0; }
        .result-main-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
        .result-stat-item { background-color: var(--bg); padding: 12px; border-radius: 8px; text-align: center; }
        .result-stat-item .label { color: var(--text-muted); font-size: 13px; display: block; margin-bottom: 4px; }
        .result-stat-item .value { font-size: 24px; font-weight: 700; }
        .result-stat-item .value.green { color: var(--green); }
        .result-detail-list .result-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #2a2a32; font-size: 14px; }
        .result-detail-list .result-item:last-child { border-bottom: none; }
        .status-info, .status-error { text-align: center; color: var(--text-muted); padding: 20px 0; }
        .status-error { font-weight: bold; color: var(--red); }
    </style>
</head>
<body>
<div class="container">
  <div class="store-header">
    <img src="assets/img/modecentre.png" alt="Mode Centre" class="logo">
    <div class="info">
      <div class="addr">Jl Pemuda Komp Ruko Pemuda 13 - 21 Banjarnegara Jawa Tengah</div>
      <div class="contact">Contact: 0813-9983-9777</div>
    </div>
  </div>
  <div class="grid grid-auto justify-end mb-2">
    <a href="dashboard_pegawai.php" class="btn btn-secondary">Kembali ke Dashboard</a>
  </div>
</div>
<div class="container">
    <div class="header">
        <h1><i class="fa-solid fa-barcode"></i> Cek Stok Barang</h1>
        <a href="dashboard_pegawai.php" class="btn" style="margin-left:auto;">Kembali</a>
    </div>

    <div class="card">
        <div class="tab-navigation">
            <button class="tab-button active" data-tab="scanTab">📷 Scan Kamera</button>
            <button class="tab-button" data-tab="manualTab">⌨️ Ketik Manual</button>
        </div>

        <div class="tab-content">
            <div id="scanTab" class="tab-pane active">
                <label for="cameraSelect">Pilih Kamera:</label>
                <select id="cameraSelect"></select>
                <div id="preview"></div>
            </div>
            <div id="manualTab" class="tab-pane">
                <div class="manual-search-section">
                    <h3>Cari Berdasarkan Kode</h3>
                    <form onsubmit="event.preventDefault(); searchByCode();">
                        <input type="number" id="kodeInput" class="input-manual" placeholder="Masukkan Kode Barang" inputmode="numeric" required>
                        <button type="submit" class="btn btn-primary">Cari Kode</button>
                    </form>
                </div>
                <hr style="border-color:var(--border); margin: 20px 0;">
                <div class="manual-search-section">
                    <h3>Cari Berdasarkan Nama / Artikel</h3>
                    <form onsubmit="event.preventDefault(); searchByArtikel();">
                        <input type="text" id="artikelInput" class="input-manual" placeholder="Masukkan Nama atau Artikel" required>
                        <button type="submit" class="btn btn-primary">Cari Nama</button>
                    </form>
                </div>
            </div>
        </div>

        <div id="scanResult">
            <p class="status-info">Arahkan kamera ke barcode atau ketik manual.</p>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script>
    const resultDiv = document.getElementById('scanResult');
    const beepSound = new Audio('data:audio/wav;base64,UklGRl9vT19XQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YU'+Array(30).join('123'));
    let isScanPaused = false;
    const html5QrCode = new Html5Qrcode("preview", { verbose: false });

    // Fungsi pencarian baru
    function searchByCode() {
        const code = document.getElementById('kodeInput').value;
        fetchData('kode', code);
    }
    function searchByArtikel() {
        const artikel = document.getElementById('artikelInput').value;
        fetchData('artikel', artikel);
    }

    // Fungsi fetch data yang lebih fleksibel
    function fetchData(param, value) {
        if (!value) return;
        resultDiv.innerHTML = '<p class="status-info">Mencari...</p>';
        fetch(`get_stok_data.php?${param}=${encodeURIComponent(value)}`)
            .then(response => response.ok ? response.json() : Promise.reject('Network error'))
            .then(response => {
                if (response.status === 'success') {
                    const data = response.data;
                    beepSound.play();
                    // TAMPILAN KARTU HASIL BARU
                    resultDiv.innerHTML = `
                        <h3 class="result-title">${data.NAMABRG}</h3>
                        <div class="result-main-grid">
                            <div class="result-stat-item">
                                <span class="label">Total Stok</span>
                                <span class="value">${data.STOK_TOTAL}</span>
                            </div>
                            <div class="result-stat-item">
                                <span class="label">Harga Jual</span>
                                <span class="value green">${data.HGJUAL_F}</span>
                            </div>
                        </div>
                        <div class="result-detail-list">
                            <div class="result-item"><span class="label">Kode</span> <span class="value">${data.KODEBRG}</span></div>
                            <div class="result-item"><span class="label">Artikel</span> <span class="value">${data.ARTIKELBRG}</span></div>
                            <div class="result-item"><span class="label">Stok Gudang (ST03)</span> <span class="value">${data.ST03}</span></div>
                            <div class="result-item"><span class="label">Diskon</span> <span class="value">${data.DISC_F}</span></div>
                            <div class="result-item"><span class="label">Umur Barang</span> <span class="value">${data.UMUR_F}</span></div>
                        </div>
                    `;
                } else {
                    resultDiv.innerHTML = `<p class="status-error">${response.message}</p>`;
                }
            }).catch(error => {
                resultDiv.innerHTML = `<p class="status-error">Gagal mengambil data dari server.</p>`;
            });
    }

    function onScanSuccess(decodedText) {
        if (isScanPaused) return;
        isScanPaused = true;
        const code = decodedText.replace(/\*/g, '');
        fetchData('kode', code); // Scanner akan selalu mencari berdasarkan 'kode'
        setTimeout(() => { isScanPaused = false; }, 2000);
    }

    // (Logika kamera dan tab tidak berubah)
    const cameraSelect = document.getElementById('cameraSelect');
    function startScanner(cameraId) {
        if (!cameraId) return;
        const config = { fps: 10, qrbox: { width: 250, height: 150 }, formatsToSupport: [Html5QrcodeSupportedFormats.CODE_39] };
        html5QrCode.start(cameraId, config, onScanSuccess).catch(err => console.error(`Error starting camera:`, err));
    }
    function stopScanner() {
        if (html5QrCode && html5QrCode.isScanning) {
            html5QrCode.stop().catch(err => {});
        }
    }
    document.querySelectorAll(".tab-button").forEach(button => {
        button.addEventListener("click", () => {
            document.querySelectorAll(".tab-button").forEach(btn => btn.classList.remove("active"));
            button.classList.add("active");
            const targetTab = button.getAttribute("data-tab");
            document.querySelectorAll(".tab-pane").forEach(pane => pane.classList.toggle("active", pane.id === targetTab));
            if (targetTab === 'scanTab' && cameraSelect.value) {
                startScanner(cameraSelect.value);
            } else {
                stopScanner();
            }
        });
    });
    Html5Qrcode.getCameras().then(devices => {
        if (devices && devices.length) {
            devices.forEach(device => {
                const option = document.createElement('option');
                option.value = device.id;
                option.innerHTML = device.label || `Kamera ${cameraSelect.length + 1}`;
                cameraSelect.appendChild(option);
            });
            const rearCamera = devices.find(d => /back|rear|environment/i.test(d.label)) || devices[0];
            cameraSelect.value = rearCamera.id;
            
            if (document.getElementById('scanTab').classList.contains('active')) {
               startScanner(rearCamera.id);
            }
            cameraSelect.addEventListener('change', () => {
                stopScanner();
                setTimeout(() => startScanner(cameraSelect.value), 100);
            });
        }
    }).catch(err => {
        document.getElementById('scanTab').innerHTML = `<p class="status-error">Tidak dapat mengakses kamera. Pastikan izin sudah diberikan dan koneksi aman (HTTPS).</p>`;
    });
</script>
</body>
</html>
