<?php
session_start();
if (!isset($_SESSION['role'])) {
    header('Location: login.php');
    exit;
}
$today = date('Y-m-d');
$fromDefault = date('Y-m-d', strtotime('-90 days'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Asisten Stok & Penjualan</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <style>
      :root {
        --brand-bg: #d32f2f; --brand-text: #ffffff; --bg: #0f1117; --card: #151821; --text: #e5e7ef;
        --muted: #98a0b3; --border: #23283a; --focus: #5a93ff;
      }
      body { margin:0; font-family: "Segoe UI", system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); }
      .page { max-width: 1100px; margin: 32px auto; padding: 0 18px 48px; }
      .hero { display:flex; align-items:center; gap:14px; margin-bottom:16px; }
      .pill { background: rgba(255,255,255,0.05); border:1px solid var(--border); border-radius:12px; padding:8px 12px; font-size:13px; color:var(--muted); }
      .card { background: var(--card); border:1px solid var(--border); border-radius:16px; padding:20px; box-shadow: 0 12px 35px rgba(0,0,0,0.28); }
      h1 { margin:0; font-size:24px; }
      p.lead { margin:6px 0 12px; color: var(--muted); }
      form { display:grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap:12px; margin-top:12px; }
      label { display:flex; flex-direction:column; gap:6px; font-size:13px; color:var(--muted); }
      input, textarea { width:100%; background:#0c0f16; border:1px solid var(--border); color:var(--text); border-radius:10px; padding:10px 12px; font-size:15px; }
      textarea { min-height:100px; resize:vertical; }
      input:focus, textarea:focus { outline:2px solid var(--focus); border-color:var(--focus); }
      .actions { display:flex; gap:10px; align-items:center; margin-top:6px; flex-wrap:wrap; }
      button { background: var(--brand-bg); color:var(--brand-text); border:none; border-radius:10px; padding:12px 18px; cursor:pointer; font-weight:600; }
      button:hover { filter: brightness(1.05); }
      .hint { font-size:13px; color: var(--muted); }
      .result { margin-top:18px; display:grid; gap:12px; }
      .answer { padding:14px; border:1px dashed var(--border); border-radius:12px; background: #0f121a; white-space:pre-wrap; line-height:1.55; }
      table { width:100%; border-collapse: collapse; }
      th, td { padding:8px 10px; border-bottom:1px solid var(--border); text-align:left; font-size:13px; }
      th { color:var(--muted); text-transform:uppercase; letter-spacing:0.2px; }
      .muted { color: var(--muted); }
      .badge { display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); color:var(--muted); font-size:12px; }
    </style>
</head>
<body>
  <div class="page">
    <div class="hero">
      <div class="badge">AI Beta</div>
      <h1>AI Asisten Stok & Penjualan</h1>
    </div>
    <p class="lead">Tanyakan apa saja seputar stok barang, tren penjualan, atau tanggal terakhir terjual. AI akan merangkum data dari database dan memberikan insight singkat.</p>

    <div class="card">
      <form id="ai-form">
        <label style="grid-column:1 / -1">
          Pertanyaan
          <textarea name="question" id="question" placeholder="Contoh: Berapa stok dan kapan terakhir terjual untuk kode BRG123?"></textarea>
        </label>
        <label>
          Kode/Nama Barang (opsional)
          <input type="text" name="keyword" id="keyword" placeholder="BRG123 atau kata kunci">
        </label>
        <label>
          Dari Tanggal
          <input type="date" name="from" id="from" value="<?= htmlspecialchars($fromDefault) ?>">
        </label>
        <label>
          Sampai Tanggal
          <input type="date" name="to" id="to" value="<?= htmlspecialchars($today) ?>">
        </label>
      </form>
      <div class="actions">
        <button id="ask-btn">Kirim ke AI</button>
        <span class="hint" id="status-text">Default: rentang 90 hari terakhir.</span>
      </div>
    </div>

    <div class="result" id="result">
      <div class="answer muted">Jawaban AI akan muncul di sini.</div>
    </div>
  </div>

  <script>
    const form = document.getElementById('ai-form');
    const askBtn = document.getElementById('ask-btn');
    const statusText = document.getElementById('status-text');
    const resultBox = document.getElementById('result');

    const fmtIdr = (v) => {
      if (v === null || v === undefined || v === '') return '-';
      return 'Rp ' + Number(v).toLocaleString('id-ID');
    };

    function renderTable(title, rows, columns) {
      if (!rows || !rows.length) return '';
      let thead = '<tr>' + columns.map(c => `<th>${c.label}</th>`).join('') + '</tr>';
      let tbody = rows.map(r => {
        return '<tr>' + columns.map(c => `<td>${c.format ? c.format(r[c.key], r) : (r[c.key] ?? '-')}</td>`).join('') + '</tr>';
      }).join('');
      return `
        <div class="card">
          <div class="pill" style="margin-bottom:8px;">${title}</div>
          <div class="table-wrap">
            <table>${thead}${tbody}</table>
          </div>
        </div>
      `;
    }

    async function askAI() {
      const payload = {
        question: document.getElementById('question').value.trim(),
        keyword: document.getElementById('keyword').value.trim(),
        from: document.getElementById('from').value,
        to: document.getElementById('to').value,
      };

      if (!payload.question) {
        alert('Pertanyaan tidak boleh kosong.');
        return;
      }

      askBtn.disabled = true;
      statusText.textContent = 'Memproses...';

      try {
        const resp = await fetch('api_ai_assistant.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const data = await resp.json();
        if (!data.ok) {
          throw new Error(data.message || 'Gagal memproses permintaan.');
        }

        // Jawaban AI
        const answerBox = `<div class="card"><div class="pill" style="margin-bottom:8px;">Jawaban AI</div><div class="answer">${data.answer}</div></div>`;

        // Stok
        const stockTable = renderTable(
          'Ringkasan Stok',
          data.stock,
          [
            { key:'kode', label:'Kode' },
            { key:'nama', label:'Nama' },
            { key:'stok_total', label:'Stok Total' },
            { key:'umur_hari', label:'Umur Stok (hari)' },
            { key:'tgl_beli_terakhir', label:'Beli Terakhir' },
            { key:'harga_beli', label:'Hrg Beli', format:(v)=> fmtIdr(v) },
            { key:'harga_jual', label:'Hrg Jual', format:(v)=> fmtIdr(v) },
          ]
        );

        // Penjualan
        const salesTable = renderTable(
          'Ringkasan Penjualan',
          data.sales,
          [
            { key:'KODEBRG', label:'Kode' },
            { key:'NAMABRG', label:'Nama' },
            { key:'TOTAL_QTY', label:'Qty' },
            { key:'TOTAL_NETTO', label:'Omzet', format:(v)=> fmtIdr(v) },
            { key:'ACTIVE_DAYS', label:'Hari Aktif' },
            { key:'LAST_SOLD', label:'Terakhir Terjual' },
          ]
        );

        // Trend harian
        const dailyTable = renderTable(
          'Trend Harian (qty & omzet)',
          data.daily,
          [
            { key:'TANGGAL', label:'Tanggal' },
            { key:'QTY', label:'Qty' },
            { key:'NETTO', label:'Omzet', format:(v)=> fmtIdr(v) },
          ]
        );

        resultBox.innerHTML = answerBox + stockTable + salesTable + dailyTable;
        statusText.textContent = `Rentang data: ${data.range.from} s/d ${data.range.to}`;
      } catch (err) {
        console.error(err);
        resultBox.innerHTML = `<div class="card"><div class="pill" style="margin-bottom:8px;">Error</div><div class="answer">${err.message}</div></div>`;
        statusText.textContent = 'Gagal memproses permintaan.';
      } finally {
        askBtn.disabled = false;
      }
    }

    askBtn.addEventListener('click', (e) => { e.preventDefault(); askAI(); });
  </script>
</body>
</html>
