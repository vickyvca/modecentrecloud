<?php
require_once 'config.php';
session_start();
// Pastikan hanya admin yang bisa mengakses halaman ini
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { die("Akses ditolak."); }

// --- LOKASI FILE DATA ---
$tampilan_file = 'tampilan.json';
$koleksi_file = 'koleksi.json';

// --- FUNGSI BANTU ---

// FUNGSI BARU YANG DITAMBAHKAN UNTUK MEMPERBAIKI ERROR
function column_exists(PDO $conn, $table, $column){
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:t AND COLUMN_NAME=:c";
    $st=$conn->prepare($sql);
    $st->execute([':t'=>$table,':c'=>$column]);
    return (bool)$st->fetchColumn();
}

function pick_column(PDO $conn, $table, array $cands, $fallback=null){
    $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:t AND COLUMN_NAME=:c";
    $st=$conn->prepare($sql);
    foreach($cands as $c){
        $st->execute([':t'=>$table,':c'=>$c]);
        if($st->fetchColumn()) return $c;
    }
    return $fallback;
}
function fmt_idr($v){ if($v===null||$v==='') return 'Rp 0'; return 'Rp '.number_format((float)$v,0,',','.'); }
function fmt_disc($v){
    if($v===null||$v==='') return '0%';
    $v=(float)$v; if($v>0 && $v<1) $v*=100;
    return rtrim(rtrim(number_format($v,1,',','.'),'0'),',').'%';
}

// --- PROSES FORM (POST & GET) ---
$pesan_sukses = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_tampilan') {
        $bg_url = $_POST['background_url'] ?? '';
        file_put_contents($tampilan_file, json_encode(['background_url' => $bg_url], JSON_PRETTY_PRINT));
        $pesan_sukses = 'Gambar latar berhasil diperbarui.';
    } elseif ($action === 'add_koleksi') {
        $koleksi_data = json_decode(file_get_contents($koleksi_file), true) ?: [];
        $koleksi_data[] = [
            'image_url' => $_POST['image_url'],
            'title' => $_POST['title']
        ];
        file_put_contents($koleksi_file, json_encode($koleksi_data, JSON_PRETTY_PRINT));
        $pesan_sukses = 'Item koleksi berhasil ditambahkan.';
    }
}
if (isset($_GET['action']) && $_GET['action'] === 'delete_koleksi') {
    $index_to_delete = (int)($_GET['index'] ?? -1);
    $koleksi_data = json_decode(file_get_contents($koleksi_file), true) ?: [];
    if (isset($koleksi_data[$index_to_delete])) {
        array_splice($koleksi_data, $index_to_delete, 1);
        file_put_contents($koleksi_file, json_encode($koleksi_data, JSON_PRETTY_PRINT));
        header('Location: admin_panel.php?pesan=Koleksi berhasil dihapus'); // Redirect untuk membersihkan URL
        exit;
    }
}
if (isset($_GET['pesan'])) {
    $pesan_sukses = htmlspecialchars($_GET['pesan']);
}

// --- BACA DATA TERKINI DARI JSON ---
$tampilan_data = file_exists($tampilan_file) ? json_decode(file_get_contents($tampilan_file), true) : ['background_url' => ''];
$koleksi_data = file_exists($koleksi_file) ? json_decode(file_get_contents($koleksi_file), true) : [];

// --- LOGIKA DAFTAR PRODUK DARI DATABASE (UNTUK REFERENSI) ---
$vb_nama = pick_column($conn,'V_BARANG',['NAMABRG','NAMA','NAMA_BARANG']);
$vb_artikel = pick_column($conn,'V_BARANG',['ARTIKELBRG','ARTIKEL'],null);
$vb_hjual = pick_column($conn,'V_BARANG',['HARGAJUAL','HGJUAL','H_JUAL'],null);
$vb_disc  = pick_column($conn,'V_BARANG',['DISKON','DISK','DISC'],null);
$st_cols=[]; foreach(['ST00','ST01','ST02','ST03','ST04'] as $st){ $st_cols[$st]=column_exists($conn,'V_BARANG',$st); }
$stok_total_expr_parts=[]; foreach(['ST00','ST01','ST02','ST03','ST04'] as $st){ if(!empty($st_cols[$st])) $stok_total_expr_parts[]="ISNULL($st,0)"; }
$stok_total_expr = $stok_total_expr_parts ? implode(' + ',$stok_total_expr_parts) : "0";

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$count_stmt = $conn->query("SELECT COUNT(*) FROM V_BARANG");
$total_records = (int)$count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

$sql_produk = "
    SELECT 
        $vb_nama AS NAMABRG,
        " . ($vb_artikel ? "$vb_artikel AS ARTIKELBRG," : "'-' AS ARTIKELBRG,") . "
        " . ($vb_hjual ? "TRY_CAST($vb_hjual AS NUMERIC(18,2)) AS HGJUAL," : "0 AS HGJUAL,") . "
        " . ($vb_disc ? "TRY_CAST($vb_disc AS NUMERIC(18,4)) AS DISC," : "0 AS DISC,") . "
        ($stok_total_expr) AS STOK_TOTAL
    FROM V_BARANG
    ORDER BY $vb_nama
    OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY
";
$produk_stmt = $conn->prepare($sql_produk);
$produk_stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$produk_stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$produk_stmt->execute();
$produk_list = $produk_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Mode Centre</title>
    <style>
    :root {
      --brand-bg: #d32f2f; --brand-text: #ffffff; --bg: #121218; --card: #1e1e24; --text: #e0e0e0;
      --text-muted: #8b8b9a; --border: #33333d; --border-hover: #4a4a58; --focus-ring: #5a93ff;
      --fs-body: clamp(14px, 1.1vw, 15.5px); --fs-sm: clamp(12.5px, 0.95vw, 14px);
      --fs-h1: clamp(22px, 2.6vw, 28px); --fs-h2: clamp(18px, 1.8vw, 22px);
      --space-1: clamp(8px, 0.8vw, 12px); --space-2: clamp(12px, 1.4vw, 18px);
      --space-3: clamp(18px, 2vw, 24px);
      --radius-sm: 8px; --radius-md: 12px; --radius-lg: 16px;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: var(--bg); color: var(--text); font-size: var(--fs-body); line-height: 1.6; }
    .container { max-width: min(1200px, 96vw); margin: var(--space-3) auto; padding: 0 var(--space-2); }
    h1 { font-size: var(--fs-h1); line-height: 1.2; margin: 0; font-weight: 700; }
    h2 { font-size: var(--fs-h2); color: var(--brand-bg); margin-top: 0; margin-bottom: var(--space-2); }
    .header { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin-bottom: var(--space-2); }
    .logo { width: 42px; height: 42px; border-radius: var(--radius-sm); background: var(--brand-bg); display: inline-flex; align-items: center; justify-content: center; color: var(--brand-text); font-weight: 700; font-size: 1.2em; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: var(--space-2) var(--space-3); box-shadow: 0 4px 20px rgba(0,0,0,0.1); margin-bottom: var(--space-3); }
    .actions { display: flex; gap: var(--space-1); margin-left: auto; }
    label { display: block; margin-bottom: var(--space-1); color: var(--text-muted); font-size: var(--fs-sm); }
    input { background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 10px 14px; border-radius: var(--radius-sm); font-size: var(--fs-body); width: 100%; }
    .btn { border: 1px solid var(--border); background: #2a2a32; color: var(--text); padding: 10px 16px; border-radius: var(--radius-sm); cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: var(--fs-body); font-weight: 500; }
    .btn-primary { background: var(--brand-bg); border-color: transparent; color: var(--brand-text); }
    .success-msg { background: rgba(46, 204, 113, 0.18); color: #58d68d; padding: var(--space-2); border-radius: var(--radius-sm); margin-bottom: var(--space-2); }
    .collection-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-2); }
    .collection-item { position: relative; }
    .collection-item img { width: 100%; height: 250px; object-fit: cover; border-radius: var(--radius-md); }
    .collection-item .delete-btn { position: absolute; top: 8px; right: 8px; background-color: rgba(229, 57, 53, 0.8); color: white; border: none; border-radius: 50%; width: 32px; height: 32px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.2em; }
    .table-wrap { width: 100%; overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; min-width: 600px; }
    th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
    th { font-size: var(--fs-sm); text-transform: uppercase; color: var(--text-muted); }
    .right { text-align: right; }
    .pagination { display: flex; justify-content: space-between; align-items: center; padding-top: var(--space-2); font-size: var(--fs-sm); color: var(--text-muted); }
    .pagination .btn.disabled { pointer-events: none; opacity: 0.5; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="logo">AP</div>
        <div>
            <h1>Admin Panel</h1>
            <div class="hint">Kelola tampilan halaman depan dan lihat data produk.</div>
        </div>
        <div class="actions">
            <a href="dashboard_admin.php" class="btn">🔙 Kembali ke Dashboard</a>
        </div>
    </div>

    <?php if ($pesan_sukses): ?>
        <div class="success-msg"><?= $pesan_sukses ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Pengaturan Tampilan</h2>
        <form action="admin_panel.php" method="POST">
            <input type="hidden" name="action" value="update_tampilan">
            <div style="margin-bottom: var(--space-2);">
                <label for="background_url">URL Gambar Latar Utama</label>
                <input type="url" id="background_url" name="background_url" value="<?= htmlspecialchars($tampilan_data['background_url'] ?? '') ?>" placeholder="https://source.unsplash.com/1600x900/?fashion,style" required>
            </div>
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        </form>
    </div>

    <div class="card">
        <h2>Manajemen Koleksi Terbaru</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: var(--space-3);">
            <div>
                <h3>Tambah Item Koleksi Baru</h3>
                <form action="admin_panel.php" method="POST" style="display: flex; flex-direction: column; gap: var(--space-2);">
                    <input type="hidden" name="action" value="add_koleksi">
                    <div>
                        <label for="image_url">URL Gambar Produk</label>
                        <input type="url" id="image_url" name="image_url" placeholder="https://source.unsplash.com/400x500/?shirt" required>
                    </div>
                    <div>
                        <label for="title">Judul Koleksi</label>
                        <input type="text" id="title" name="title" placeholder="Kemeja Pria Modern" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Tambah</button>
                </form>
            </div>
            <div>
                <h3>Koleksi Saat Ini</h3>
                <?php if (empty($koleksi_data)): ?>
                    <p class="text-muted">Belum ada item koleksi.</p>
                <?php else: ?>
                    <div class="collection-grid">
                        <?php foreach ($koleksi_data as $index => $item): ?>
                            <div class="collection-item">
                                <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['title']) ?>">
                                <a href="admin_panel.php?action=delete_koleksi&index=<?= $index ?>" class="delete-btn" onclick="return confirm('Yakin ingin menghapus item ini?')">&times;</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Daftar Produk (Referensi)</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nama Barang</th>
                        <th>Artikel</th>
                        <th class="right">Harga Jual</th>
                        <th class="right">Diskon</th>
                        <th class="right">Stok</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($produk_list)): ?>
                        <tr><td colspan="5" style="text-align: center;" class="text-muted">Tidak ada produk.</td></tr>
                    <?php else: ?>
                        <?php foreach ($produk_list as $produk): ?>
                            <tr>
                                <td><?= htmlspecialchars($produk['NAMABRG']) ?></td>
                                <td><?= htmlspecialchars($produk['ARTIKELBRG']) ?></td>
                                <td class="right"><?= fmt_idr($produk['HGJUAL']) ?></td>
                                <td class="right"><?= fmt_disc($produk['DISC']) ?></td>
                                <td class="right"><?= number_format($produk['STOK_TOTAL']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <div>Halaman <strong><?= $page ?></strong> dari <strong><?= $total_pages ?></strong></div>
            <div style="display: flex; gap: var(--space-1);">
                <?php
                $queryParams = $_GET;
                if ($page > 1) {
                    $queryParams['page'] = $page - 1;
                    echo '<a href="?' . http_build_query($queryParams) . '" class="btn">Sebelumnya</a>';
                } else {
                    echo '<a class="btn disabled">Sebelumnya</a>';
                }
                
                if ($page < $total_pages) {
                    $queryParams['page'] = $page + 1;
                    echo '<a href="?' . http_build_query($queryParams) . '" class="btn">Berikutnya</a>';
                } else {
                    echo '<a class="btn disabled">Berikutnya</a>';
                }
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>
</body>
</html>

