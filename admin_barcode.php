<?php
require_once 'config.php';
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { die("Akses ditolak."); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin • Cetak Barcode</title>
<style>
  :root {
    --brand-bg: #d32f2f; --brand-text:#ffffff; --bg:#121218; --card:#1e1e24; --text:#e0e0e0;
    --text-muted:#8b8b9a; --border:#33333d; --border-hover:#4a4a58; --focus-ring:#5a93ff;
    --fs-body: clamp(14px, 1.1vw, 15.5px); --fs-sm: clamp(12.5px, 0.95vw, 14px); --fs-h1: clamp(20px, 2.2vw, 26px);
    --radius-sm: 8px; --radius-md: 12px;
  }
  *{box-sizing:border-box}
  html,body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;font-size:var(--fs-body)}
  header{position:sticky;top:0;background:#17171d;border-bottom:1px solid var(--border);padding:12px 16px;z-index:10}
  h1{margin:0;font-size:var(--fs-h1);font-weight:600}
  .container{padding:20px 16px;display:grid;grid-template-columns:minmax(320px, 360px) 1fr;gap:20px;align-items:start}
  .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-md);padding:16px}
  label{font-size:var(--fs-sm);color:var(--text-muted);display:block;margin-bottom:4px}
  input, textarea, select{width:100%;padding:10px;border-radius:var(--radius-sm);border:1px solid var(--border);background:#0f1115;color:var(--text);outline:none;font-size:var(--fs-body);transition:border-color 0.2s}
  input:focus, textarea:focus, select:focus{border-color:var(--focus-ring);box-shadow:0 0 0 3px rgba(90,147,255,0.2)}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid var(--border);background:#2b334a;color:#fff;border-radius:var(--radius-sm);padding:10px 14px;cursor:pointer;font-weight:500;transition:all 0.2s}
  .btn:hover{filter:brightness(1.1);transform:translateY(-1px)}
  .btn.secondary{background:#1f2333;border-color:#33333d}
  .btn.brand{background:var(--brand-bg);border-color:var(--brand-bg)}
  .btn.brand:hover{border-color:#e53935}
  .muted{color:var(--text-muted);font-size:12px;margin:10px 0 0 0}
  iframe{width:100%;height:calc(100vh - 100px);background:#fff;border-radius:var(--radius-md);border:1px solid var(--border);margin-top:5px}
  .chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
  .chip{font-size:11px;background:#0f1115;border:1px dashed var(--border);color:var(--text-muted);padding:4px 8px;border-radius:999px}
  .group{margin-top:16px}
  .group:first-child{margin-top:0}
  .inline{display:flex;align-items:center;gap:8px}
  .unit{font-size:12px;color:var(--text-muted);width:40px}
  .range{flex-grow:1}
</style>
</head>
<body>
<header>
  <h1>🧾 Cetak Barcode Code39 Sheet (6 Kolom)</h1>
</header>

<div class="container">
  <div class="card">
    <form id="f" onsubmit="evPreview(event)">
      <div class="group">
        <label for="kode">Daftar Kode Barang dan Jumlah (Contoh: **046990001=80**)</label>
        <textarea id="kode" rows="6" placeholder="Pisahkan dengan koma atau baris baru (Enter). Contoh:
046990001=80
092140278=36,093390115=10"></textarea>
        <div class="chips">
          <span class="chip">Format Input: **KODE=JUMLAH**</span>
          <span class="chip">Isi Label: Artikel • Barcode • Harga Coret (%Disc) • Netto</span>
        </div>
      </div>

      <div class="group row">
        <div>
          <label for="print_mode">Mode Cetak</label>
          <select id="print_mode" onchange="updateSheetInfo()">
            <option value="pdf_sheet" selected>PDF Sheet (6 Kolom)</option>
            <option value="zebra_pdf">Zebra PDF (3 kolom, 40x30mm)</option>
            <option value="zebra_zd220">Zebra ZPL (3 kolom, 40x30mm)</option>
            <option value="nicelabel_csv">NiceLabel CSV (untuk .nlbl)</option>
          </select>
        </div>
        <div>
          <label for="label_type">Tipe Label</label>
          <select id="label_type" onchange="updateSheetInfo()">
            <option value="54">Label Besar (6x9 = 54 slot)</option>
            <option value="42">Label Kecil (6x7 = 42 slot)</option>
          </select>
        </div>
        <div>
          <label for="copies_info">Jumlah Copies (Info)</label>
          <input type="text" id="copies_info" value="Diambil dari input" readonly style="color: #4CAF50; font-weight: bold;">
        </div>
      </div>

      <div class="group row">
        <div>
          <label for="fill">Isi Sisa Slot Kosong</label>
          <select id="fill">
            <option value="1">Ya, isi dengan slot kosong</option>
            <option value="0">Tidak</option>
          </select>
        </div>
        <div>
          <label for="round">Pembulatan Harga Netto</label>
          <select id="round">
            <option value="0">Normal (tanpa pembulatan)</option>
            <option value="100">Bulat ke Rp100</option>
            <option value="500">Bulat ke Rp500</option>
            <option value="1000">Bulat ke Rp1.000</option>
          </select>
        </div>
      </div>
      
      <div class="group row">
        <div>
          <label for="gap">Jarak Antar Label (mm)</label>
          <input type="number" step="0.1" id="gap" value="0.0">
          <p class="muted">Abaikan jika *sheet* tidak punya *gap*.</p>
        </div>
      </div>

      <div class="group">
        <label>Tuning Visual Barcode</label>
        <div class="inline">
          <input class="range" type="range" id="bh" min="5" max="14" step="0.1" value="8">
          <input style="width:55px" type="number" id="bh_n" min="5" max="14" step="0.1" value="8">
          <span class="unit">Tinggi (mm)</span>
        </div>
        <div class="inline" style="margin-top:10px">
          <input class="range" type="range" id="bw" min="0.6" max="1.6" step="0.05" value="0.9">
          <input style="width:55px" type="number" id="bw_n" min="0.6" max="1.6" step="0.05" value="0.9">
          <span class="unit">Lebar Garis</span>
        </div>
      </div>

      <div class="group">
        <label>Tuning Ukuran Font Harga (pt)</label>
        <div class="row">
          <div>
            <label for="sp">Harga Coret</label>
            <div class="inline">
              <input class="range" type="range" id="sp" min="6" max="14" step="0.5" value="9">
              <input style="width:55px" type="number" id="sp_n" min="6" max="14" step="0.5" value="9">
              <span class="unit">pt</span>
            </div>
          </div>
          <div>
            <label for="p">Harga Netto</label>
            <div class="inline">
              <input class="range" type="range" id="p" min="10" max="22" step="0.5" value="14">
              <input style="width:55px" type="number" id="p_n" min="10" max="22" step="0.5" value="14">
              <span class="unit">pt</span>
            </div>
          </div>
        </div>
      </div>

      <div class="group" style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" class="btn brand">🔍 Preview & Ambil Data</button>
        <button type="button" class="btn" onclick="evPrintIframe()">🖨️ Cetak</button>
        <a class="btn secondary" href="dashboard_admin.php">⬅️ Kembali</a>
      </div>
    </form>
  </div>

  <div class="card" style="padding: 10px;">
    <iframe id="preview" src="about:blank" title="Preview Label"></iframe>
  </div>
</div>

<script>
function bindPair(rangeId, numId){
  const r = document.getElementById(rangeId);
  const n = document.getElementById(numId);
  r.addEventListener('input',()=>{ n.value = r.value; });
  n.addEventListener('input',()=>{ r.value = n.value; });
}
['bh','bw','sp','p'].forEach(id=>bindPair(id, id+'_n'));

/**
 * Memproses input kode=jumlah menjadi array of objects.
 * @returns {Array<{kode: string, copies: number}>}
 */
function normKode(raw){
  const parts = raw.split(/[\n,;]+/) // Pisahkan dengan baris baru, koma, atau semicolon
                 .map(s => s.trim())
                 .filter(Boolean);
  
  const result = [];
  const unique_codes = new Set();
  
  parts.forEach(part => {
    // Mencari format KODE=JUMLAH
    const match = part.match(/^([a-zA-Z0-9*-]+)\s*=\s*(\d+)$/);
    let kode, copies;
    
    if (match) {
      // Format KODE=JUMLAH ditemukan
      kode = match[1].replace(/\*/g, '').toUpperCase();
      copies = parseInt(match[2], 10);
    } else {
      // Jika tidak ada '=', asumsikan 1 copy. KODE tanpa *=
      kode = part.replace(/\*/g, '').toUpperCase();
      copies = 1;
    }
    
    if (kode && copies > 0 && !unique_codes.has(kode)) {
      result.push({ kode: kode, copies: copies });
      unique_codes.add(kode); // Pastikan setiap kode hanya diproses sekali
    }
  });

  return result;
}

function buildUrl(){
  const data = normKode(document.getElementById('kode').value);
  if (data.length === 0) { alert('Masukkan minimal 1 kode barang.'); return null; }
  
  // Pisahkan array kode dan array jumlah untuk dikirim via URL
  const kode_list = data.map(item => item.kode);
  const copies_list = data.map(item => item.copies);
  
  const fill = document.getElementById('fill').value;
  const gap = document.getElementById('gap').value || '0.0';
  const round = document.getElementById('round').value || '0';
  const labelType = document.getElementById('label_type').value;
  const mode = document.getElementById('print_mode').value;
  
  const bh = document.getElementById('bh').value;
  const bw = document.getElementById('bw').value;
  const sp = document.getElementById('sp').value;
  const p  = document.getElementById('p').value;

  const params = new URLSearchParams();
  // Kirim daftar kode dan daftar jumlah secara terpisah
  params.set('kode', kode_list.join(','));
  params.set('copies', copies_list.join(',')); // Kirim daftar jumlah copies
  params.set('round', round);

  if (mode === 'zebra_zd220' || mode === 'zebra_pdf' || mode === 'nicelabel_csv') {
    // Zebra modes: gunakan kontrol khusus Zebra
    const zl_w = (document.getElementById('z_label_w')?.value || '40');
    const zl_h = (document.getElementById('z_label_h')?.value || '30');
    const z_cols = (document.getElementById('z_cols')?.value || '3');
    const z_gap_h = (document.getElementById('z_gap_h')?.value || '2');
    const z_gap_v = (document.getElementById('z_gap_v')?.value || '2');
    const z_bch = (document.getElementById('z_bch')?.value || '16');
    const z_mw  = (document.getElementById('z_mw')?.value  || '2');
    const z_ratio = (document.getElementById('z_ratio')?.value || '3.0');

    params.set('z_cols', z_cols);
    params.set('label_w_mm', zl_w);
    params.set('label_h_mm', zl_h);
    params.set('gap_mm_h', z_gap_h);
    params.set('gap_mm_v', z_gap_v);
    params.set('bch_mm', z_bch);

    if (mode === 'zebra_zd220') {
      params.set('mw', z_mw);
      params.set('ratio', z_ratio);
      return 'zebra_zpl.php?' + params.toString();
    } else if (mode === 'zebra_pdf') {
      return 'zebra_pdf.php?' + params.toString();
    } else {
      // nicelabel_csv
      // Optional: explode=0 (satu baris + qty) / 1 (duplikasi baris per qty)
      params.set('explode', '0');
      return 'zebra_csv.php?' + params.toString();
    }
  } else {
    params.set('fill', fill);
    params.set('gap', gap);
    params.set('type', labelType); 
    params.set('bh', bh);
    params.set('bw', bw);
    params.set('sp', sp);
    params.set('p',  p);
    return 'barcode_pdf.php?' + params.toString();
  }
}

function updateSheetInfo() {
    const type = document.getElementById('label_type').value;
    const h1 = document.querySelector('header h1');
    const fillLabel = document.querySelector('label[for="fill"]');

    if (type === '42') {
        h1.textContent = '🧾 Cetak Barcode Code39 Sheet (190 x 160 mm, 6x7 = 42 Label)';
        fillLabel.textContent = 'Isi Sisa Slot Kosong (Hingga 42)';
    } else {
        h1.textContent = '🧾 Cetak Barcode Code39 Sheet (190 x 210 mm, 6x9 = 54 Label)';
        fillLabel.textContent = 'Isi Sisa Slot Kosong (Hingga 54)';
    }
}

function evPreview(e){
  e.preventDefault();
  const url = buildUrl();
  if (!url) return;
document.getElementById('preview').src = url;
}

// Override updateSheetInfo with Zebra mode support
function updateSheetInfo() {
  const mode = document.getElementById('print_mode') ? document.getElementById('print_mode').value : 'pdf_sheet';
  const type = document.getElementById('label_type') ? document.getElementById('label_type').value : '54';
  const h1 = document.querySelector('header h1');
  const fillLabel = document.querySelector('label[for="fill"]');

  if (!h1 || !fillLabel) return;

  // Ensure dynamic UI pieces exist
  ensureZebraControls();
  ensureZPLDownloadButton();
  const zebraCtl = document.getElementById('zebra_controls');
  const btnDl = document.getElementById('btn_dl_zpl');

  if (mode === 'zebra_zd220' || mode === 'zebra_pdf' || mode === 'nicelabel_csv') {
    if (mode === 'zebra_pdf') {
      h1.textContent = 'Cetak Barcode Zebra PDF (3 kolom, 40x30mm)';
    } else if (mode === 'zebra_zd220') {
      h1.textContent = 'Cetak Barcode Zebra ZPL (3 kolom, 40x30mm)';
    } else {
      h1.textContent = 'Export NiceLabel CSV (40x30mm)';
    }
    fillLabel.textContent = 'Isi Sisa Slot Kosong (Tidak berlaku)';
    if (zebraCtl) zebraCtl.style.display = 'block';
    if (btnDl) {
      if (mode === 'zebra_zd220') { btnDl.style.display = 'inline-flex'; btnDl.textContent = '⬇️ Download ZPL'; }
      else if (mode === 'nicelabel_csv') { btnDl.style.display = 'inline-flex'; btnDl.textContent = '⬇️ Download CSV'; }
      else { btnDl.style.display = 'none'; }
    }
  } else if (type === '42') {
    h1.textContent = 'Cetak Barcode Code39 Sheet (190 x 160 mm, 6x7 = 42 Label)';
    fillLabel.textContent = 'Isi Sisa Slot Kosong (Hingga 42)';
    if (zebraCtl) zebraCtl.style.display = 'none';
    if (btnDl) btnDl.style.display = 'none';
  } else {
    h1.textContent = 'Cetak Barcode Code39 Sheet (190 x 210 mm, 6x9 = 54 Label)';
    fillLabel.textContent = 'Isi Sisa Slot Kosong (Hingga 54)';
    if (zebraCtl) zebraCtl.style.display = 'none';
    if (btnDl) btnDl.style.display = 'none';
  }
}

function evPrintIframe(){
    const ifr = document.getElementById('preview');
    const url = ifr.src;

    const ok = url && url !== 'about:blank' && (url.includes('barcode_pdf.php') || url.includes('zebra_zpl.php') || url.includes('zebra_pdf.php') || url.includes('zebra_csv.php'));
    if (!ok) { 
        alert('Preview belum dimuat. Klik "Preview & Ambil Data" terlebih dahulu.'); 
        return; 
    }
    
    window.open(url, '_blank');
}

// Dynamically add Zebra controls panel if missing
function ensureZebraControls(){
  if (document.getElementById('zebra_controls')) return;
  const form = document.getElementById('f');
  if (!form) return;
  const zebraDiv = document.createElement('div');
  zebraDiv.id = 'zebra_controls';
  zebraDiv.className = 'card';
  zebraDiv.style.display = 'none';
  zebraDiv.style.marginTop = '12px';
  zebraDiv.innerHTML = `
    <label style="margin-bottom:8px; font-weight:600;">Pengaturan Zebra ZD220</label>
    <div class="row">
      <div>
        <label for="z_label_w">Label Width (mm)</label>
        <input type="number" step="0.5" id="z_label_w" value="40">
      </div>
      <div>
        <label for="z_label_h">Label Height (mm)</label>
        <input type="number" step="0.5" id="z_label_h" value="30">
      </div>
    </div>
    <div class="row" style="margin-top:8px;">
      <div>
        <label for="z_cols">Kolom per Baris</label>
        <input type="number" id="z_cols" min="1" max="4" step="1" value="3">
      </div>
      <div>
        <label for="z_gap_h">Gap Horizontal (mm)</label>
        <input type="number" step="0.5" id="z_gap_h" value="2">
      </div>
      <div>
        <label for="z_gap_v">Gap Vertikal (mm)</label>
        <input type="number" step="0.5" id="z_gap_v" value="2">
      </div>
    </div>
    <div class="row" style="margin-top:8px;">
      <div>
        <label for="z_bch">Tinggi Barcode (mm)</label>
        <input type="number" step="0.5" id="z_bch" value="16">
      </div>
      <div>
        <label for="z_mw">Module Width (dot)</label>
        <input type="number" id="z_mw" min="1" max="4" step="1" value="2">
      </div>
      <div>
        <label for="z_ratio">Ratio (2.0 - 3.0)</label>
        <input type="number" id="z_ratio" min="2" max="3" step="0.1" value="3.0">
      </div>
    </div>
  `;
  // Insert before the last .group (actions group)
  const groups = form.querySelectorAll('.group');
  const lastGroup = groups[groups.length - 1];
  form.insertBefore(zebraDiv, lastGroup);
}

// Ensure ZPL download button exists inside the actions group
function ensureZPLDownloadButton(){
  if (document.getElementById('btn_dl_zpl')) return;
  const form = document.getElementById('f');
  if (!form) return;
  const groups = form.querySelectorAll('.group');
  const actions = groups[groups.length - 1];
  if (!actions) return;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.id = 'btn_dl_zpl';
  btn.className = 'btn secondary';
  btn.style.display = 'none';
  btn.textContent = '⬇️ Download ZPL';
  btn.addEventListener('click', evDownloadZPL);
  // Insert before the last link (Kembali) if exists, else append
  const backLink = actions.querySelector('a.btn.secondary[href="dashboard_admin.php"]');
  if (backLink) actions.insertBefore(btn, backLink);
  else actions.appendChild(btn);
}

function evDownloadZPL(){
  const ifr = document.getElementById('preview');
  const url = ifr.src;
  if (!url || !(url.includes('zebra_zpl.php') || url.includes('zebra_csv.php'))) { alert('Mode Zebra/NiceLabel belum aktif atau preview belum dibuat.'); return; }
  const a = document.createElement('a');
  a.href = url;
  const ext = url.includes('zebra_csv.php') ? '.csv' : '.zpl';
  a.download = 'labels_' + Date.now() + ext;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

updateSheetInfo();

document.addEventListener('keydown', (e) => {
  if (e.ctrlKey && e.key.toLowerCase() === 'p') { e.preventDefault(); evPrintIframe(); }
  if (e.ctrlKey && e.key === 'Enter') { e.preventDefault(); evPreview(e); }
});
</script>
</body>
</html>
