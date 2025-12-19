<?php
session_start();
if (!isset($_SESSION['nik']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

// --- 1. AMBIL SEMUA PARAMETER FILTER ---
$selected_supplier_id = $_POST['supplier_id'] ?? $_GET['supplier_id'] ?? null;
$filter_category = $_GET['category'] ?? null;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15; // Tampilkan 15 item per halaman

// Ambil data supplier untuk dropdown
$suppliers = [];
try {
    $supplier_stmt = $conn->query("SELECT KODESP, NAMASP FROM T_SUPLIER ORDER BY NAMASP ASC");
    $suppliers = $supplier_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Gagal mengambil data supplier: " . $e->getMessage());
}

$selected_supplier_name = null;
$selected_supplier_display = '';
$analysis_results = null;

if (!empty($selected_supplier_id)) {
    foreach($suppliers as $sp) {
        if ($sp['KODESP'] == $selected_supplier_id) {
            $selected_supplier_name = $sp['NAMASP'];
            $selected_supplier_display = $sp['NAMASP'] . ' (' . $sp['KODESP'] . ')';
            break;
        }
    }

    $end_date = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime('-90 days'));

    $params = [
        ':kodesp' => $selected_supplier_id,
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ];
    $item_filter_params = $params;

    try {
        // --- 2. PERSIAPAN QUERY UNTUK ITEM TERLARIS (DENGAN PAGINASI & FILTER KATEGORI) ---
        $item_where_clause = "j.KODESP = :kodesp AND j.TGL BETWEEN :start_date AND :end_date";
        if ($filter_category) {
            $item_where_clause .= " AND j.KETJENIS = :category";
            $item_filter_params[':category'] = $filter_category;
        }

        // Query untuk menghitung total item (untuk paginasi)
        $count_query = "SELECT COUNT(DISTINCT j.KODEBRG) FROM V_JUAL j WHERE $item_where_clause";
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->execute($item_filter_params);
        $total_records = (int)$count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);
        $offset = ($page - 1) * $limit;

        // Query utama untuk item terlaris dengan paginasi
        $top_items_query = "
            SELECT j.KODEBRG, j.NAMABRG, j.ARTIKELBRG, SUM(j.QTY) as total_terjual,
                   ISNULL((SELECT SUM(ST00 + ST01 + ST02 + ST03 + ST04) FROM T_BARANG b WHERE b.KODEBRG = j.KODEBRG), 0) as sisa_stok
            FROM V_JUAL j
            WHERE $item_where_clause
            GROUP BY j.KODEBRG, j.NAMABRG, j.ARTIKELBRG
            ORDER BY total_terjual DESC
            OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY
        ";
        $stmt_items = $conn->prepare($top_items_query);
        // Bind semua parameter filter
        foreach($item_filter_params as $key => $val) {
            $stmt_items->bindValue($key, $val);
        }
        $stmt_items->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt_items->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt_items->execute();
        $top_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        // --- 3. QUERY LAINNYA (TIDAK BERUBAH) ---
        $top_categories_query = "SELECT TOP 5 KETJENIS, SUM(QTY) as total_terjual FROM V_JUAL WHERE KODESP = :kodesp AND TGL BETWEEN :start_date AND :end_date AND KETJENIS IS NOT NULL AND KETJENIS <> '' GROUP BY KETJENIS ORDER BY total_terjual DESC";
        $stmt_cats = $conn->prepare($top_categories_query);
        $stmt_cats->execute($params);
        $top_categories = $stmt_cats->fetchAll(PDO::FETCH_ASSOC);

        // [DIUBAH] Query untuk Top Artikel dengan pengelompokan
        $top_articles_query = "
            SELECT TOP 5 
                CASE 
                    WHEN CHARINDEX('.', ARTIKELBRG, CHARINDEX('.', ARTIKELBRG) + 1) > 0 THEN LEFT(ARTIKELBRG, CHARINDEX('.', ARTIKELBRG, CHARINDEX('.', ARTIKELBRG) + 1) - 1)
                    ELSE LEFT(ARTIKELBRG, 7)
                END as article_group, 
                SUM(QTY) as total_terjual
            FROM V_JUAL
            WHERE KODESP = :kodesp AND TGL BETWEEN :start_date AND :end_date AND ARTIKELBRG IS NOT NULL AND ARTIKELBRG <> ''
            GROUP BY CASE WHEN CHARINDEX('.', ARTIKELBRG, CHARINDEX('.', ARTIKELBRG) + 1) > 0 THEN LEFT(ARTIKELBRG, CHARINDEX('.', ARTIKELBRG, CHARINDEX('.', ARTIKELBRG) + 1) - 1) ELSE LEFT(ARTIKELBRG, 7) END
            ORDER BY total_terjual DESC
        ";
        $stmt_arts = $conn->prepare($top_articles_query);
        $stmt_arts->execute($params);
        $top_articles = $stmt_arts->fetchAll(PDO::FETCH_ASSOC);

        $total_revenue_query = "SELECT SUM(NETTO) as total_omzet FROM V_JUAL WHERE KODESP = :kodesp AND TGL BETWEEN :start_date AND :end_date";
        $stmt_rev = $conn->prepare($total_revenue_query);
        $stmt_rev->execute($params);
        $total_revenue_result = $stmt_rev->fetch(PDO::FETCH_ASSOC);

        $analysis_results = [
            'start_date' => $start_date, 'end_date' => $end_date,
            'top_items' => $top_items, 'top_categories' => $top_categories, 'top_articles' => $top_articles,
            'total_revenue' => $total_revenue_result['total_omzet'] ?? 0,
            'pagination' => ['page' => $page, 'total_pages' => $total_pages, 'total_records' => $total_records]
        ];

    } catch (PDOException $e) {
        die("Error saat melakukan analisa: " . $e->getMessage());
    }
}
function idr($v) { return 'Rp ' . number_format($v, 0, ',', '.'); }
function pcs($v) { return number_format($v, 0, ',', '.') . ' pcs'; }

function generatePagination($base_url, $total_pages, $current_page) {
    if ($total_pages <= 1) return '';
    $html = '<div class="pagination no-print"><div class="pagination-links">';
    // ... (Logika pagination sama seperti di laporan stok, bisa di-copy paste) ...
    $html .= '</div></div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Helper</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        body { background-color: var(--bg); }
        .topbar { display:flex; flex-wrap: wrap; gap:16px; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .topbar h1 { margin: 0; }
        .actions { display: flex; gap: 8px; }
        .card, .summary-card { animation: fadeInUp 0.5s ease-out forwards; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .summary-card { background-color: var(--card); padding: 20px; border-radius: var(--radius-lg); border: 1px solid var(--border); }
        .summary-card .label { display: flex; align-items: center; gap: 8px; font-size: 1em; color: var(--text-muted); margin-bottom: 8px; }
        .summary-card .value { font-size: 2em; font-weight: 700; word-break: break-all;}
        .list-group { padding: 0; margin: 0; list-style: none; }
        .list-group-item { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .list-group-item:last-child { border-bottom: none; }
        /* [BARU] Styling untuk kategori yang bisa diklik */
        .list-group-item a { text-decoration: none; color: var(--text); display: block; flex-grow: 1; }
        .list-group-item a:hover { color: var(--brand-bg); }
        .list-group-item strong { font-weight: 600; }
        .active-filter-notice { background-color: rgba(211, 47, 47, 0.1); border: 1px solid var(--brand-bg); padding: 12px; border-radius: var(--radius-md); margin-top: 16px; display: flex; justify-content: space-between; align-items: center; }

        /* Style untuk pagination sama seperti laporan_stok.php */
        .pagination { display: flex; justify-content: space-between; align-items: center; padding-top: var(--space-2); margin-top: var(--space-2); border-top: 1px solid var(--border); }
        .pagination-info { font-size: var(--fs-sm); color: var(--text-muted); }
        .pagination-links { display: flex; gap: 5px; }
        .pagination-links .btn { padding: 6px 12px; min-width: 40px; justify-content: center; }
        .pagination-links .btn.active { background: var(--brand-bg); color: var(--brand-text); border-color: var(--brand-bg); }
        .pagination-links .btn.disabled { pointer-events: none; opacity: 0.5; }
        .page-ellipsis { padding: 6px; color: var(--text-muted); }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <h1><i class="fas fa-magic"></i> Order Helper</h1>
        <div class="actions">
            <?php if (!empty($selected_supplier_id)): ?>
            <a href="order_helper_metrics.php?supplier_id=<?= urlencode($selected_supplier_id) ?>&window=28&lt=7&z=1.28" class="btn"><i class="fas fa-lightbulb"></i> Rekomendasi (Beta)</a>
            <?php endif; ?>
            <a href="dashboard_admin.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <div class="card" style="animation-delay: 0.1s;">
        <form action="order_helper.php" method="POST" class="filters">
            <div style="flex-grow: 1;">
                <label for="supplier_search" class="muted">Cari Supplier untuk dianalisa</label>
                <input class="input" type="text" id="supplier_search" list="supplier_list" placeholder="Ketik Nama atau Kode Supplier..." value="<?= htmlspecialchars($selected_supplier_display) ?>" required autocomplete="off">
                <datalist id="supplier_list">
                    <?php foreach ($suppliers as $supplier): ?>
                        <option data-value="<?= htmlspecialchars($supplier['KODESP']); ?>"><?= htmlspecialchars($supplier['NAMASP']); ?> (<?= htmlspecialchars($supplier['KODESP']);?>)</option>
                    <?php endforeach; ?>
                </datalist>
                <input type="hidden" id="supplier_id" name="supplier_id" value="<?= htmlspecialchars($selected_supplier_id) ?>">
            </div>
            <button type="submit" class="btn btn-primary" style="align-self: flex-end;"><i class="fas fa-chart-bar"></i> Tampilkan Analisa</button>
        </form>
    </div>

    <?php if ($analysis_results): ?>
    <h2 style="margin-top: 32px; animation: fadeInUp 0.5s ease-out forwards; animation-delay: 0.2s;">
        Hasil Analisa: <strong><?= htmlspecialchars($selected_supplier_name) ?></strong><br>
        <small class="muted" style="font-size: 0.6em;">(Periode: <?= date('d M Y', strtotime($analysis_results['start_date'])) ?> - <?= date('d M Y', strtotime($analysis_results['end_date'])) ?>)</small>
    </h2>

    <div class="summary-grid">
        <div class="summary-card" style="animation-delay: 0.3s;">
            <span class="label"><i class="fas fa-dollar-sign"></i> Total Omzet</span>
            <span class="value" class="text-var-green"><?= idr($analysis_results['total_revenue']) ?></span>
        </div>
        <div class="summary-card" style="animation-delay: 0.4s;">
            <span class="label"><i class="fas fa-tags"></i> Top 5 Kategori Terlaris</span>
            <ul class="list-group">
                <?php foreach($analysis_results['top_categories'] as $cat): ?>
                    <li class="list-group-item">
                        <a href="?supplier_id=<?=urlencode($selected_supplier_id)?>&category=<?=urlencode($cat['KETJENIS'])?>">
                            <?= htmlspecialchars($cat['KETJENIS']) ?>
                        </a>
                        <strong><?= pcs($cat['total_terjual']) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="summary-card" style="animation-delay: 0.5s;">
            <span class="label"><i class="fas fa-tshirt"></i> Top 5 Artikel Terlaris (Dikelompokkan)</span>
            <ul class="list-group">
                <?php foreach($analysis_results['top_articles'] as $art): ?>
                    <li class="list-group-item">
                        <span><?= htmlspecialchars($art['article_group']) ?>..</span>
                        <strong><?= pcs($art['total_terjual']) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="card" style="animation-delay: 0.6s;">
        <h3><i class="fas fa-star"></i> Item Terlaris</h3>
        
        <?php if($filter_category): ?>
        <div class="active-filter-notice">
            <span>Menampilkan item untuk kategori: <strong><?= htmlspecialchars($filter_category) ?></strong></span>
            <a href="?supplier_id=<?=urlencode($selected_supplier_id)?>" class="btn btn-sm">Hapus Filter</a>
        </div>
        <?php endif; ?>

        <div class="table-wrap table-card">
            <table class="table">
                <thead>
                    <tr><th>No</th><th>Kode Barang</th><th>Artikel</th><th>Nama Barang</th><th class="right">Total Terjual</th><th class="right">Sisa Stok</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($analysis_results['top_items'] as $index => $item): ?>
                    <tr style="<?= ($item['sisa_stok'] <= 0) ? 'background-color: rgba(231, 76, 60, 0.1);' : '' ?>">
                        <td><?= $offset + $index + 1; ?></td>
                        <td><?= htmlspecialchars($item['KODEBRG']); ?></td>
                        <td><?= htmlspecialchars($item['ARTIKELBRG']); ?></td>
                        <td><?= htmlspecialchars($item['NAMABRG']); ?></td>
                        <td class="right"><?= pcs($item['total_terjual']); ?></td>
                        <td class="right"><strong><?= pcs($item['sisa_stok']); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="pagination no-print">
            <div class="pagination-info">
                Menampilkan <?= count($analysis_results['top_items']) ?> dari <?= pcs($analysis_results['pagination']['total_records']) ?> total item.
            </div>
            <?php 
                $queryParams = $_GET;
                if(isset($_POST['supplier_id'])) $queryParams['supplier_id'] = $_POST['supplier_id']; // pastikan supplier_id terbawa
                echo generatePagination(http_build_query($queryParams), $analysis_results['pagination']['total_pages'], $analysis_results['pagination']['page']); 
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('supplier_search');
    const hiddenInput = document.getElementById('supplier_id');
    const dataList = document.getElementById('supplier_list');
    searchInput.addEventListener('input', function() {
        const selectedValue = searchInput.value;
        let correspondingId = '';
        for (const option of dataList.options) {
            if (option.value === selectedValue) {
                correspondingId = option.getAttribute('data-value');
                break;
            }
        }
        hiddenInput.value = correspondingId;
    });
});
</script>
</body>
</html>
