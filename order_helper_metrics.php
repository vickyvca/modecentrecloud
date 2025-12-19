<?php
session_start();
if (!isset($_SESSION['nik']) || ($_SESSION['role'] ?? '') !== 'admin') { die('Akses ditolak.'); }
require_once __DIR__ . '/config.php';

// Params
$supplier = $_GET['supplier_id'] ?? $_POST['supplier_id'] ?? '';
$category = $_GET['category'] ?? $_POST['category'] ?? '';
$window_days = max(7, (int)($_GET['window'] ?? $_POST['window'] ?? 28));
$lead_time = max(1, (int)($_GET['lt'] ?? $_POST['lt'] ?? 7));
$service_z = (float)($_GET['z'] ?? $_POST['z'] ?? 1.28); // ~90% service level

$start_date = date('Y-m-d', strtotime('-' . $window_days . ' days'));
$end_date = date('Y-m-d');

$rows = [];
$supplier_name = '';
if ($supplier) {
  try {
    // Supplier name
    $st = $conn->prepare("SELECT NAMASP FROM T_SUPLIER WHERE KODESP = :id");
    $st->execute([':id' => $supplier]);
    $supplier_name = (string)($st->fetchColumn() ?: $supplier);

    // Aggregate sales and stock by SKU
    $sql = "SELECT x.KODEBRG, x.NAMABRG,
                   SUM(x.QTY) AS total_qty,
                   ISNULL((SELECT SUM(ST00+ST01+ST02+ST03+ST04) FROM T_BARANG b WHERE b.KODEBRG = x.KODEBRG),0) AS on_hand
            FROM V_JUAL x
            WHERE x.KODESP = :sp AND x.TGL BETWEEN :d1 AND :d2" . ($category? " AND x.KETJENIS = :cat" : "") .
           " GROUP BY x.KODEBRG, x.NAMABRG
            ORDER BY SUM(x.QTY) DESC";
    $stmt = $conn->prepare($sql);
    $params = [':sp'=>$supplier, ':d1'=>$start_date, ':d2'=>$end_date];
    if ($category) { $params[':cat'] = $category; }
    $stmt->execute($params);
    // Range untuk analisis tren mingguan
    $last7_start = date('Y-m-d', strtotime($end_date . ' -6 days'));
    $last7_end   = $end_date;
    $prev7_start = date('Y-m-d', strtotime($end_date . ' -13 days'));
    $prev7_end   = date('Y-m-d', strtotime($end_date . ' -7 days'));

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $total = (float)($r['total_qty'] ?? 0);
      $mu = $total / $window_days; // avg daily demand
      $sigma = sqrt(max($mu, 0.0001)); // Poisson approx.
      $safety = $service_z * sqrt($lead_time) * $sigma;
      $rop = ($mu * $lead_time) + $safety;
      $on_hand = (float)$r['on_hand'];
      $rec = max(0, (int)ceil($rop - $on_hand));

      // Cakupan stok (hari)
      $coverage = ($mu > 0) ? ($on_hand / $mu) : INF;
      $risk = ($mu > 0 && $coverage < $lead_time) ? 'Ya' : 'Tidak';

      // Tren 7 hari vs 7 hari sebelumnya (opsional, untuk sense demand)
      $trend_pct = null;
      try {
        $q7 = $conn->prepare("SELECT SUM(QTY) FROM V_JUAL WHERE KODESP=:sp AND KODEBRG=:kb " . ($category? " AND KETJENIS = :cat " : "") . " AND TGL BETWEEN :s AND :e");
        $p7 = [':sp'=>$supplier, ':kb'=>$r['KODEBRG'], ':s'=>$last7_start, ':e'=>$last7_end]; if ($category) { $p7[':cat'] = $category; }
        $q7->execute($p7);
        $last7 = (float)($q7->fetchColumn() ?? 0);
        $p7[':s'] = $prev7_start; $p7[':e'] = $prev7_end;
        $q7->execute($p7);
        $prev7 = (float)($q7->fetchColumn() ?? 0);
        if ($prev7 > 0) { $trend_pct = round((($last7 - $prev7)/$prev7)*100); }
        elseif ($last7 > 0) { $trend_pct = 100; }
        else { $trend_pct = 0; }
      } catch (PDOException $e) { $trend_pct = null; }

      // Penjelasan detail per SKU (dalam Bahasa Indonesia)
      $detail = "Rata-rata harian ≈ " . number_format($mu,2,',','.') . " unit/hari dari total " . (int)$total . " unit selama " . (int)$window_days . " hari. Lead Time = " . (int)$lead_time . " hari, Z = " . (float)$service_z . ". Safety Stock ≈ " . (int)ceil($safety) . ". ROP ≈ " . (int)ceil($rop) . ". Stok saat ini = " . (int)$on_hand . ". Perkiraan cakupan stok ≈ " . ($coverage === INF ? '-' : number_format($coverage,1,',','.')) . " hari.";
      if ($trend_pct !== null) { $detail .= " Tren 7 hari terakhir: " . ($trend_pct>=0?'+':'') . $trend_pct . "%."; }

      // Tandai anomali stok jika jauh di atas kebutuhan
      $anomali = ($on_hand >= 6000 || ($mu > 0 && $on_hand > 5 * $rop)) ? 'Cek Stok' : '';

      $rows[] = [
        'kodebrg' => (string)$r['KODEBRG'],
        'namabrg' => (string)$r['NAMABRG'],
        'total' => (int)$total,
        'avg_daily' => round($mu, 2),
        'on_hand' => (int)$on_hand,
        'safety' => (int)ceil($safety),
        'rop' => (int)ceil($rop),
        'coverage' => ($coverage===INF?'-':round($coverage,1)),
        'risk' => $risk,
        'trend' => $trend_pct,
        'recommend' => (int)$rec,
        'detail' => $detail,
        'anomali' => $anomali,
      ];
    }
  } catch (PDOException $e) {
    die('DB Error: ' . htmlspecialchars($e->getMessage()));
  }
}

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=rekomendasi_order_' . $supplier . '_' . date('Ymd') . '.csv');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['SUPPLIER', $supplier_name]);
  fputcsv($out, ['WindowDays', $window_days, 'LeadTime', $lead_time, 'Z', $service_z]);
  fputcsv($out, ['KODEBRG','NAMABRG','TOTAL_WINDOW','AVG_DAILY','ON_HAND','ROP','REKOMENDASI_QTY']);
  foreach ($rows as $r) {
    fputcsv($out, [$r['kodebrg'],$r['namabrg'],$r['total'],$r['avg_daily'],$r['on_hand'],$r['rop'],$r['recommend']]);
  }
  fclose($out); exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Order Helper – Rekomendasi (Beta)</title>
  <link rel="stylesheet" href="assets/css/ui.css">
  <style>
    body{background:#121218;color:#e0e0e0;font-family:sans-serif}
    .container{max-width:1200px;margin:20px auto}
    .card{background:#1e1e24;border:1px solid #333;padding:16px;border-radius:10px;margin-bottom:16px}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid #333;padding:8px;white-space:nowrap}
    th{background:#2a2a32}
    .right{text-align:right}
    .muted{color:#8b8b9a}
  </style>
</head>
<body>
  <div class="container">
    <div class="card">
      <h2 style="margin:0 0 8px">Rekomendasi Order (Beta)</h2>
      <form method="get" style="display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
        <div><label>Supplier</label><input class="input" type="text" name="supplier_id" value="<?= htmlspecialchars($supplier) ?>" placeholder="Kode Supplier (KODESP)" required></div>
        <div><label>Jenis Barang (opsional)</label><input class="input" type="text" name="category" value="<?= htmlspecialchars($category) ?>" placeholder="Contoh: KAOS / AKSESORIS"></div>
        <div><label>Jendela Analisis (hari)</label><input class="input" type="number" name="window" value="<?= (int)$window_days ?>" min="7" max="120"></div>
        <div><label>Lead Time (hari)</label><input class="input" type="number" name="lt" value="<?= (int)$lead_time ?>" min="1" max="60"></div>
        <div><label>Tingkat Layanan (Z)</label><input class="input" type="number" step="0.01" name="z" value="<?= htmlspecialchars($service_z) ?>" min="0" max="3"></div>
        <button class="btn btn-primary" type="submit">Hitung</button>
        <?php if ($supplier): ?>
          <a class="btn" href="?supplier_id=<?= urlencode($supplier) ?>&window=<?= $window_days ?>&lt=<?= $lead_time ?>&z=<?= $service_z ?>&export=csv">Ekspor CSV</a>
          <a class="btn" href="order_helper.php?supplier_id=<?= urlencode($supplier) ?>">Kembali</a>
        <?php endif; ?>
      </form>
      <div class="muted" style="margin-top:6px;">Supplier: <b><?= htmlspecialchars($supplier_name ?: '-') ?></b> · Periode: <?= htmlspecialchars($start_date) ?> s/d <?= htmlspecialchars($end_date) ?><?= $category? ' · Jenis: <b>'.htmlspecialchars($category).'</b>':'' ?></div>
    </div>

    <?php if ($supplier): ?>
    <div class="card">
      <h3 style="margin-top:0">Penjelasan Metrik</h3>
      <ul style="margin:6px 0 10px 18px;">
        <li><b>Jendela Analisis (hari):</b> jumlah hari penjualan yang dipakai sebagai dasar perhitungan.</li>
        <li><b>Lead Time (hari):</b> rata-rata waktu dari pesan sampai barang diterima.</li>
        <li><b>Tingkat Layanan (Z):</b> parameter untuk stok pengaman (safety stock). Contoh: Z≈1,28 ≈ 90%, Z≈1,65 ≈ 95%, Z≈2,05 ≈ 98%.</li>
        <li><b>Rata‑rata Harian:</b> total terjual ÷ jumlah hari.</li>
        <li><b>Deviasi (σ) aproksimasi:</b> √(rata‑rata harian) — pendekatan Poisson untuk permintaan.</li>
        <li><b>Safety Stock:</b> Z × √(Lead Time) × σ.</li>
        <li><b>ROP (Reorder Point):</b> (Rata‑rata Harian × Lead Time) + Safety Stock.</li>
        <li><b>Rekomendasi Qty:</b> max(0, ceil(ROP − Stok Saat Ini)).</li>
      </ul>
      <div class="muted">Catatan: alat ini untuk <b>analisa</b>, bukan otomatis membuat PO. Versi ini belum memperhitungkan stok on‑order/PO, MOQ, serta kelipatan kemasan. Data penjualan dari <b>V_JUAL</b>, stok dari <b>T_BARANG</b> (ST00..ST04). Gunakan bersama insight permintaan pelanggan (preorder, promosi toko, dsb).</div>
    </div>
    <div class="card">
      <table>
        <thead><tr>
          <th title="Kode Barang">KODEBRG</th><th title="Nama Barang">NAMABRG</th>
          <th class="right" title="Jumlah terjual dalam jendela analisis">Terjual (<?= (int)$window_days ?> hari)</th>
          <th class="right" title="Rata-rata penjualan per hari">Rata2/Hari</th>
          <th class="right" title="Stok pengaman (Safety Stock)">Safety</th>
          <th class="right" title="Titik pemesanan ulang (Reorder Point)">ROP</th>
          <th class="right" title="Stok tersedia saat ini">On Hand</th>
          <th class="right" title="Perkiraan berapa hari stok mencukupi">Cakupan (hari)</th>
          <th class="right" title="Perubahan penjualan 7 hari terakhir vs sebelumnya">Tren 7d</th>
          <th class="center" title="Risiko kehabisan sebelum barang datang (dibanding Lead Time)">Risiko</th>
          <th class="right" title="Jumlah saran untuk mencapai/menjaga di atas ROP">Rek. Qty</th>
          <th>Detail</th>
        </tr></thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="12" style="text-align:center">Data tidak ditemukan.</td></tr>
          <?php else: foreach($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['kodebrg']) ?></td>
              <td><?= htmlspecialchars($r['namabrg']) ?></td>
              <td class="right"><?= (int)$r['total'] ?></td>
              <td class="right"><?= htmlspecialchars($r['avg_daily']) ?></td>
              <td class="right"><?= (int)$r['safety'] ?></td>
              <td class="right"><?= (int)$r['rop'] ?></td>
              <td class="right"><?= (int)$r['on_hand'] ?></td>
              <td class="right"><?= is_numeric($r['coverage'])? htmlspecialchars($r['coverage']):'-' ?></td>
              <td class="right"><?= ($r['trend']===null?'-':(($r['trend']>=0?'+':'').(int)$r['trend'].'%')) ?></td>
              <td class="center"><?= $r['risk'] === 'Ya' ? '<span class="badge level-down">Ya</span>' : '<span class="badge level-up">Tidak</span>' ?></td>
              <td class="right"><b><?= (int)$r['recommend'] ?></b><?= $r['anomali']? ' <span class="badge level-down" title="Nilai stok tampak tidak wajar. Mohon cek ulang di sistem/stok fisik.">'.$r['anomali'].'</span>' : '' ?></td>
              <td>
                <details><summary>Lihat</summary>
                  <div class="muted" style="max-width:520px; white-space:normal;"><?= htmlspecialchars($r['detail']) ?></div>
                </details>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</body>
</html>
