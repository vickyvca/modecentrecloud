<?php
// FILE: payroll/payroll_dashboard.php

session_start();
require_once __DIR__.'/payroll_lib.php';
require_once __DIR__ . '/../config.php'; 

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('Akses ditolak. Khusus admin.');
}

$ym = $_GET['bulan'] ?? ym_now();

// --- LOGIKA AKSI (HAPUS, RECALC) ---
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'delete_payroll') {
        $file = payroll_file_for($ym);
        if (is_file($file)) {
            unlink($file);
            $_SESSION['payroll_msg'] = "✅ Data payroll untuk {$ym} berhasil dihapus.";
        }
    } elseif ($_GET['action'] === 'force_recalc') {
        $_SESSION['payroll_msg'] = "✅ Data absensi dan bonus berhasil dihitung ulang dari database.";
    }
    header('Location: payroll_dashboard.php?bulan=' . urlencode($ym));
    exit;
}

$payroll = load_payroll_json($ym);
if (!isset($conn)) { die('Koneksi DB ($conn) tidak tersedia.'); }

// --- LOGIKA LOAD DATA & PERHITUNGAN ---
$ym_prev = date('Y-m', strtotime($ym . ' -1 month'));
$payroll_prev = load_payroll_json($ym_prev);
$is_prev_data_missing = empty($payroll_prev['items']); 
$prev_items_map = array_column($payroll_prev['items'] ?? [], null, 'nik');

// [DIUBAH] Ambil NIK dari JSON, lalu ambil NAMA terbaru dari database T_SALES
$selected_niks = [];
$selected_from_json = get_selected_employees(); // Fungsi ini membaca target_selected.json
if (!empty($selected_from_json)) {
    $selected_niks = array_column($selected_from_json, 'nik');
}

$sales_names_map = [];
if (!empty($selected_niks)) {
    try {
        $placeholders = implode(',', array_fill(0, count($selected_niks), '?'));
        $stmtNames = $conn->prepare("SELECT KODESL, NAMASL FROM T_SALES WHERE KODESL IN ($placeholders)");
        $stmtNames->execute($selected_niks);
        while ($row = $stmtNames->fetch(PDO::FETCH_ASSOC)) {
            $sales_names_map[$row['KODESL']] = trim($row['NAMASL']);
        }
    } catch (PDOException $e) {
        // Jika query gagal, nama akan fallback ke NIK
    }
}

$sales_map = get_sales_total_by_nik($conn, $ym);

// 1. Pastikan item ada, gunakan NAMA DARI DATABASE, dan load default dari bulan sebelumnya
foreach ($selected_niks as $nik) {
    // Gunakan nama terbaru dari T_SALES, jika tidak ada, gunakan NIK sebagai fallback
    $nama_terbaru = $sales_names_map[$nik] ?? $nik;
    ensure_item($payroll, $nik, $nama_terbaru);
    
    foreach ($payroll['items'] as &$it) {
        if ($it['nik'] === $nik) {
            // Selalu update nama dengan yang terbaru dari database
            $it['nama'] = $nama_terbaru;

            // Load data bulan lalu jika field kosong
            if (isset($prev_items_map[$nik])) {
                $prev_item = $prev_items_map[$nik];
                $fields_to_load = ['jabatan', 'gapok', 'tunj_jabatan', 'tunj_bpjs'];
                foreach ($fields_to_load as $field) {
                    if (empty($it[$field])) {
                        $it[$field] = $prev_item[$field] ?? (is_numeric($prev_item[$field] ?? null) ? 0 : '');
                    }
                }
            }
        }
    }
    unset($it);
}

// 2. Hitung ulang nilai otomatis
recalc_items($payroll, $sales_map, $ym); 

// Export handlers (CSV)
if (isset($_GET['export'])) {
    $type = $_GET['export'];
    if ($type === 'pdf') {
        require_once __DIR__ . '/../vendor/autoload.php';
        $rowsHtml = '';
        $sum = [ 'gapok'=>0,'penjualan'=>0,'komisi'=>0,'bonus_individu'=>0,'bonus_kolektif'=>0,
                 'bonus_absensi'=>0,'lembur'=>0,'tunj_jabatan'=>0,'tunj_bpjs'=>0,'potongan'=>0,'total'=>0 ];
        foreach ($payroll['items'] as $it) {
            $rowsHtml .= '<tr>'
                . '<td>' . htmlspecialchars($it['nik']) . '</td>'
                . '<td>' . htmlspecialchars($it['nama']) . '</td>'
                . '<td class="r">' . fmt_idr($it['gapok'] ?? 0) . '</td>'
                . '<td class="r">' . fmt_idr($it['penjualan'] ?? 0) . '</td>'
                . '<td class="r">' . fmt_idr($it['komisi'] ?? 0) . '</td>'
                . '<td class="r">' . fmt_idr($it['bonus_individu'] ?? 0) . '</td>'
                . '<td class="r">' . fmt_idr($it['bonus_kolektif'] ?? 0) . '</td>'
                . '<td class="r">' . fmt_idr($it['bonus_absensi'] ?? 0) . '</td>'
                . '<td class="r">' . fmt_idr($it['lembur'] ?? 0) . '</td>'
                . '<td class="r">' . fmt_idr($it['tunj_jabatan'] ?? 0) . '</td>'
                . '<td class="r">' . fmt_idr($it['tunj_bpjs'] ?? 0) . '</td>'
                . '<td class="r">' . fmt_idr($it['potongan'] ?? 0) . '</td>'
                . '<td class="r"><b>' . fmt_idr($it['total'] ?? 0) . '</b></td>'
                . '</tr>';
            $sum['gapok'] += (float)($it['gapok'] ?? 0);
            $sum['penjualan'] += (float)($it['penjualan'] ?? 0);
            $sum['komisi'] += (float)($it['komisi'] ?? 0);
            $sum['bonus_individu'] += (float)($it['bonus_individu'] ?? 0);
            $sum['bonus_kolektif'] += (float)($it['bonus_kolektif'] ?? 0);
            $sum['bonus_absensi'] += (float)($it['bonus_absensi'] ?? 0);
            $sum['lembur'] += (float)($it['lembur'] ?? 0);
            $sum['tunj_jabatan'] += (float)($it['tunj_jabatan'] ?? 0);
            $sum['tunj_bpjs'] += (float)($it['tunj_bpjs'] ?? 0);
            $sum['potongan'] += (float)($it['potongan'] ?? 0);
            $sum['total'] += (float)($it['total'] ?? 0);
        }
        $html = '<html><head><meta charset="UTF-8"><style>'
            . '@page { size: Letter landscape; margin: 10mm; }'
            . 'body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color:#000; }'
            . 'h1 { font-size: 14px; margin: 0 0 6px 0; }'
            . '.muted { color:#555; margin-bottom:8px; }'
            . 'table { width:100%; border-collapse:collapse; table-layout:fixed; }'
            . 'th, td { border: 1px solid #999; padding: 3px 4px; }'
            . 'th { background:#efefef; font-weight:700; }'
            . '.r { text-align:right; }'
            . '.foot td { font-weight:700; background:#fafafa; }'
            . '</style></head><body>'
            . '<h1>Payroll ' . htmlspecialchars($ym) . '</h1>'
            . '<div class="muted">Letter Landscape, ringkas, coba 1 halaman</div>'
            . '<table><thead><tr>'
            . '<th>NIK</th><th>Nama</th><th>Gapok</th><th>Penjualan</th><th>Komisi</th><th>Bonus Individu</th><th>Bonus Kolektif</th><th>Bonus Absensi</th><th>Lembur</th><th>Tunj. Jab</th><th>Tunj. BPJS</th><th>Potongan</th><th>Total</th>'
            . '</tr></thead><tbody>' . $rowsHtml . '</tbody>'
            . '<tfoot><tr class="foot">'
            . '<td colspan="2">TOTAL</td>'
            . '<td class="r">' . fmt_idr($sum['gapok']) . '</td>'
            . '<td class="r">' . fmt_idr($sum['penjualan']) . '</td>'
            . '<td class="r">' . fmt_idr($sum['komisi']) . '</td>'
            . '<td class="r">' . fmt_idr($sum['bonus_individu']) . '</td>'
            . '<td class="r">' . fmt_idr($sum['bonus_kolektif']) . '</td>'
            . '<td class="r">' . fmt_idr($sum['bonus_absensi']) . '</td>'
            . '<td class="r">' . fmt_idr($sum['lembur']) . '</td>'
            . '<td class="r">' . fmt_idr($sum['tunj_jabatan']) . '</td>'
            . '<td class="r">' . fmt_idr($sum['tunj_bpjs']) . '</td>'
            . '<td class="r">' . fmt_idr($sum['potongan']) . '</td>'
            . '<td class="r">' . fmt_idr($sum['total']) . '</td>'
            . '</tr></tfoot></table>'
            . '</body></html>';
        $dompdf = new Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->render();
        $dompdf->stream('payroll_' . $ym . '.pdf', ["Attachment" => false]);
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    $fname = 'payroll_' . $ym . '_' . $type . '.csv';
    header('Content-Disposition: attachment; filename=' . $fname);
    $out = fopen('php://output', 'w');

    if ($type === 'csv_transfer') {
        fputcsv($out, ['NIK', 'NAMA', 'TOTAL']);
        $sum_total = 0;
        foreach ($payroll['items'] as $it) {
            $total = (float)($it['total'] ?? 0);
            fputcsv($out, [$it['nik'], $it['nama'], $total]);
            $sum_total += $total;
        }
        fputcsv($out, ['TOTAL', '', $sum_total]);
        fclose($out); exit;
    }

    // Default / csv_full
    fputcsv($out, [
        'NIK','NAMA','JABATAN','GAJI_POKOK','PENJUALAN','KOMISI',
        'BONUS_INDIVIDU','BONUS_KOLEKTIF','ABSEN_DISETUJUI','BONUS_KEHADIRAN',
        'HARI_OT','BONUS_LEMBUR','TUNJ_JABATAN','TUNJ_BPJS','POTONGAN','TOTAL'
    ]);
    $sums = [
        'gapok'=>0,'penjualan'=>0,'komisi'=>0,'bonus_individu'=>0,'bonus_kolektif'=>0,
        'absen_disetujui_days'=>0,'bonus_absensi'=>0,'absen_ot_days'=>0,'lembur'=>0,
        'tunj_jabatan'=>0,'tunj_bpjs'=>0,'potongan'=>0,'total'=>0
    ];
    foreach ($payroll['items'] as $it) {
        $row = [
            $it['nik'], $it['nama'], $it['jabatan'] ?? '',
            (float)($it['gapok'] ?? 0), (float)($it['penjualan'] ?? 0), (float)($it['komisi'] ?? 0),
            (float)($it['bonus_individu'] ?? 0), (float)($it['bonus_kolektif'] ?? 0), (int)($it['absen_disetujui_days'] ?? 0), (float)($it['bonus_absensi'] ?? 0),
            (int)($it['absen_ot_days'] ?? 0), (float)($it['lembur'] ?? 0), (float)($it['tunj_jabatan'] ?? 0), (float)($it['tunj_bpjs'] ?? 0), (float)($it['potongan'] ?? 0), (float)($it['total'] ?? 0)
        ];
        fputcsv($out, $row);
        $sums['gapok'] += (float)($it['gapok'] ?? 0);
        $sums['penjualan'] += (float)($it['penjualan'] ?? 0);
        $sums['komisi'] += (float)($it['komisi'] ?? 0);
        $sums['bonus_individu'] += (float)($it['bonus_individu'] ?? 0);
        $sums['bonus_kolektif'] += (float)($it['bonus_kolektif'] ?? 0);
        $sums['absen_disetujui_days'] += (int)($it['absen_disetujui_days'] ?? 0);
        $sums['bonus_absensi'] += (float)($it['bonus_absensi'] ?? 0);
        $sums['absen_ot_days'] += (int)($it['absen_ot_days'] ?? 0);
        $sums['lembur'] += (float)($it['lembur'] ?? 0);
        $sums['tunj_jabatan'] += (float)($it['tunj_jabatan'] ?? 0);
        $sums['tunj_bpjs'] += (float)($it['tunj_bpjs'] ?? 0);
        $sums['potongan'] += (float)($it['potongan'] ?? 0);
        $sums['total'] += (float)($it['total'] ?? 0);
    }
    fputcsv($out, [
        'TOTAL','','', $sums['gapok'], $sums['penjualan'], $sums['komisi'],
        $sums['bonus_individu'], $sums['bonus_kolektif'], $sums['absen_disetujui_days'], $sums['bonus_absensi'],
        $sums['absen_ot_days'], $sums['lembur'], $sums['tunj_jabatan'], $sums['tunj_bpjs'], $sums['potongan'], $sums['total']
    ]);
    fclose($out); exit;
}

// 3. Simpan perubahan (jika ada POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($payroll['items'] as &$it) {
        $nik = $it['nik'];
        $it['jabatan'] = trim($_POST['jabatan'][$nik] ?? $it['jabatan']);
        $it['gapok'] = (float)($_POST['gapok'][$nik] ?? $it['gapok']);
        $it['tunj_jabatan'] = (float)($_POST['tunj_jabatan'][$nik] ?? $it['tunj_jabatan']);
        $it['tunj_bpjs'] = (float)($_POST['tunj_bpjs'][$nik] ?? $it['tunj_bpjs']);
        $it['potongan'] = (float)($_POST['potongan'][$nik] ?? $it['potongan']);
        $it['catatan'] = trim($_POST['catatan'][$nik] ?? $it['catatan']);

        // Editable attendance override
        if (isset($_POST['absen_disetujui_days'][$nik]) || isset($_POST['bonus_absensi'][$nik]) || isset($_POST['absen_ot_days'][$nik]) || isset($_POST['lembur'][$nik])) {
            $it['absen_disetujui_days'] = (int)($_POST['absen_disetujui_days'][$nik] ?? $it['absen_disetujui_days']);
            $it['bonus_absensi'] = (float)($_POST['bonus_absensi'][$nik] ?? $it['bonus_absensi']);
            $it['absen_ot_days'] = (int)($_POST['absen_ot_days'][$nik] ?? $it['absen_ot_days']);
            $it['lembur'] = (float)($_POST['lembur'][$nik] ?? $it['lembur']);
            $it['manual_attendance'] = 1; // flag to keep manual values on recalc
        }
    }
    unset($it);
    recalc_items($payroll, $sales_map, $ym);
    save_payroll_json($ym, $payroll);
    $_SESSION['payroll_msg'] = "✅ Data payroll untuk {$ym} berhasil disimpan.";
    header('Location: payroll_dashboard.php?bulan='.urlencode($ym));
    exit;
}

$session_message = $_SESSION['payroll_msg'] ?? null;
unset($_SESSION['payroll_msg']);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Payroll Admin - <?=$ym?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        body { background: var(--bg); }
        .topbar { display:flex; flex-wrap: wrap; gap:12px; align-items:center; justify-content:space-between; margin-bottom:16px; padding: 16px; background-color: var(--card); border: 1px solid var(--border); border-radius: var(--radius-lg); animation: fadeInUp 0.5s ease-out forwards; }
        .topbar h1 { margin: 0; font-size: 1.5em; }
        .filters { display:flex; gap:10px; align-items:center; }
        .actions { display: flex; gap: 8px; margin-left: auto; }
        .dropdown { position: relative; }
        .dropdown-content { display: none; position: absolute; right: 0; background-color: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm); min-width: 200px; z-index: 10; box-shadow: 0 8px 16px rgba(0,0,0,0.2); }
        .dropdown-content a { color: var(--text); padding: 10px 15px; text-decoration: none; display: flex; align-items: center; gap: 8px; font-size: 0.9em;}
        .dropdown-content a:hover { background-color: #2a2a32; }
        .dropdown-content a.danger { color: var(--red); }
        .dropdown:hover .dropdown-content { display: block; }
        
        .card { animation: fadeInUp 0.5s ease-out forwards; animation-delay: 0.1s; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: var(--radius-md); }
        .table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
        .table th, .table td { padding: 10px 12px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; white-space: nowrap; }
        .table th { position: sticky; top: 0; background: #2a2a32; z-index: 5; }
        .table tbody tr:hover { background: #25252b; }
        .table input { width: 100%; min-width: 120px; box-sizing: border-box; }
        .table input[type="number"] { text-align: right; }
        .readonly-cell { background-color: rgba(0,0,0,0.2); padding: 10px 12px; font-weight: bold; }

        tfoot { position: sticky; bottom: 0; z-index: 5; }
        tfoot td { background-color: var(--card); border-top: 1px solid var(--border); }
        .footer-actions { display: flex; justify-content: flex-end; align-items: center; gap: 12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <h1><i class="fas fa-file-invoice-dollar"></i> Payroll Admin</h1>
        <form method="get" class="filters">
            <input class="input" type="month" name="bulan" value="<?=$ym?>">
            <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Lihat</button>
        </form>
        <div class="actions">
            <a class="btn" href="payroll_preview.php?bulan=<?=$ym?>" target="_blank"><i class="fas fa-print"></i> Preview Cetak</a>
            <a class="btn" href="payroll_report_absensi.php?bulan=<?=$ym?>"><i class="fas fa-file-alt"></i> Laporan Absensi</a>
            <a class="btn" href="payroll_dashboard.php?bulan=<?=$ym?>&export=pdf"><i class="fas fa-file-pdf"></i> Export PDF (Letter Landscape)</a>
            <a class="btn" href="payroll_dashboard.php?bulan=<?=$ym?>&export=csv_full"><i class="fas fa-file-csv"></i> Export CSV (Lengkap)</a>
            <a class="btn" href="payroll_dashboard.php?bulan=<?=$ym?>&export=csv_transfer"><i class="fas fa-money-bill-wave"></i> Export CSV (Transfer)</a>
            <a class="btn btn-secondary" href="../dashboard_admin.php"><i class="fas fa-arrow-left"></i> Kembali</a>
            <div class="dropdown">
                <button class="btn"><i class="fas fa-cog"></i> Opsi Lanjutan</button>
                <div class="dropdown-content">
                    <a href="?bulan=<?=$ym?>&action=force_recalc" onclick="return confirm('Ambil ulang data absensi & penjualan dari database? Ini akan menimpa bonus yang ada.')"><i class="fas fa-sync-alt"></i> Hitung Ulang Bonus</a>
                    <a href="?bulan=<?=$ym?>&action=delete_payroll" class="danger" onclick="return confirm('YAKIN menghapus data payroll bulan <?=$ym?>? Semua input manual akan hilang.')"><i class="fas fa-trash"></i> Hapus Payroll Ini</a>
                </div>
            </div>
        </div>
    </div>

    <main>
        <?php if ($session_message): ?><div class="notif-bar success"><?= htmlspecialchars($session_message) ?></div><?php endif; ?>
        <?php if ($is_prev_data_missing): ?><div class="notif-bar warn">⚠️ Data payroll bulan lalu (<?=$ym_prev?>) kosong. Gaji Pokok & Tunjangan tidak terisi otomatis.</div><?php endif; ?>
        
        <?php
            // Aggregate totals for footer
            $sum_gapok=0;$sum_komisi=0;$sum_bonus_ind=0;$sum_bonus_kol=0;$sum_absen_days=0;$sum_bonus_abs=0;$sum_ot_days=0;$sum_lembur=0;$sum_tunj_jab=0;$sum_tunj_bpjs=0;$sum_pot=0;$sum_total=0;
            foreach ($payroll['items'] as $agg) {
                $sum_gapok += (float)($agg['gapok'] ?? 0);
                $sum_komisi += (float)($agg['komisi'] ?? 0);
                $sum_bonus_ind += (float)($agg['bonus_individu'] ?? 0);
                $sum_bonus_kol += (float)($agg['bonus_kolektif'] ?? 0);
                $sum_absen_days += (int)($agg['absen_disetujui_days'] ?? 0);
                $sum_bonus_abs += (float)($agg['bonus_absensi'] ?? 0);
                $sum_ot_days += (int)($agg['absen_ot_days'] ?? 0);
                $sum_lembur += (float)($agg['lembur'] ?? 0);
                $sum_tunj_jab += (float)($agg['tunj_jabatan'] ?? 0);
                $sum_tunj_bpjs += (float)($agg['tunj_bpjs'] ?? 0);
                $sum_pot += (float)($agg['potongan'] ?? 0);
                $sum_total += (float)($agg['total'] ?? 0);
            }
        ?>
        <form method="post">
            <div class="card table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>NIK</th>
                            <th>Nama</th>
                            <th>Jabatan</th>
                            <th>Gaji Pokok</th>
                            <th class="right">Komisi</th>
                            <th class="right">Bonus Individu</th>
                            <th class="right">Bonus Kolektif</th>
                            <th class="center" title="Jumlah hari tidak masuk yang disetujui (Sakit/Cuti)">Absen Disetujui</th>
                            <th class="right">Bonus Kehadiran</th>
                            <th class="center" title="Jumlah hari lembur">Hari OT</th>
                            <th class="right">Bonus Lembur</th>
                            <th>Tunj. Jabatan</th><th>Tunj. BPJS</th>
                            <th>Potongan</th><th class="right">TOTAL</th><th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($payroll['items'] as $it): $nik=$it['nik']; ?>
                        <tr>
                            <td><?=htmlspecialchars($nik)?></td>
                            <td><?=htmlspecialchars($it['nama'])?></td>
                            <td><input class="input" type="text" name="jabatan[<?=$nik?>]" value="<?=htmlspecialchars($it['jabatan'])?>" placeholder="Jabatan"></td>
                            <td><input class="input" type="number" name="gapok[<?=$nik?>]" value="<?=$it['gapok']?>" placeholder="0"></td>
                            <td class="right readonly-cell"><?=fmt_idr($it['komisi'])?></td>
                            <td class="right readonly-cell"><?=fmt_idr($it['bonus_individu'] ?? 0)?></td>
                            <td class="right readonly-cell"><?=fmt_idr($it['bonus_kolektif'] ?? 0)?></td>
                            <td class="center"><input class="input" type="number" min="0" name="absen_disetujui_days[<?=$nik?>]" value="<?=$it['absen_disetujui_days']?>"></td>
                            <td class="right"><input class="input" type="number" min="0" name="bonus_absensi[<?=$nik?>]" value="<?=$it['bonus_absensi']?>"></td>
                            <td class="center"><input class="input" type="number" min="0" name="absen_ot_days[<?=$nik?>]" value="<?=$it['absen_ot_days']?>"></td>
                            <td class="right"><input class="input" type="number" min="0" name="lembur[<?=$nik?>]" value="<?=$it['lembur']?>"></td>
                            <td><input class="input" type="number" name="tunj_jabatan[<?=$nik?>]" value="<?=$it['tunj_jabatan']?>" placeholder="0"></td>
                            <td><input class="input" type="number" name="tunj_bpjs[<?=$nik?>]" value="<?=$it['tunj_bpjs']?>" placeholder="0"></td>
                            <td><input class="input" type="number" name="potongan[<?=$nik?>]" value="<?=$it['potongan']?>" placeholder="0"></td>
                            <td class="right readonly-cell" class="text-var-green"><?=fmt_idr($it['total'])?></td>
                            <td><input class="input" type="text" name="catatan[<?=$nik?>]" value="<?=htmlspecialchars($it['catatan'])?>" placeholder="Catatan..."></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="right"><strong>TOTAL</strong></td>
                            <td class="right"><strong><?=fmt_idr($sum_gapok)?></strong></td>
                            <td class="right"><strong><?=fmt_idr($sum_komisi)?></strong></td>
                            <td class="right"><strong><?=fmt_idr($sum_bonus_ind)?></strong></td>
                            <td class="right"><strong><?=fmt_idr($sum_bonus_kol)?></strong></td>
                            <td class="center"><strong><?=($sum_absen_days)?></strong></td>
                            <td class="right"><strong><?=fmt_idr($sum_bonus_abs)?></strong></td>
                            <td class="center"><strong><?=($sum_ot_days)?></strong></td>
                            <td class="right"><strong><?=fmt_idr($sum_lembur)?></strong></td>
                            <td class="right"><strong><?=fmt_idr($sum_tunj_jab)?></strong></td>
                            <td class="right"><strong><?=fmt_idr($sum_tunj_bpjs)?></strong></td>
                            <td class="right"><strong><?=fmt_idr($sum_pot)?></strong></td>
                            <td class="right"><strong><?=fmt_idr($sum_total)?></strong></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="16">
                                <div class="footer-actions">
                                    <span class="muted note">Pastikan semua data sudah benar sebelum menyimpan.</span>
                                    <button class="btn btn-success" type="submit"><i class="fas fa-save"></i> Simpan Semua Perubahan</button>
                                </div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </form>
    </main>
</div>
</body>
</html>
