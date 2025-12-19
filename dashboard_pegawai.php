<?php
require_once 'config.php';
require_once __DIR__ . '/payroll/payroll_lib.php';
session_start();

if (!isset($_SESSION['nik']) || ($_SESSION['role'] ?? '') !== 'pegawai') {
    die('Akses ditolak.');
}

// Paksa ganti password jika ditandai wajib
if (!empty($_SESSION['must_change_password'])) {
    header('Location: change_password.php');
    exit;
}

$nik = $_SESSION['nik'];
// Ambil nomor WA pegawai untuk keperluan notifikasi (jika belum ada akan diminta)
$my_wa = get_pegawai_wa($nik);
$ym_now = date('Y-m');
// Bulan untuk metrik dapat dipilih via query ?bulan=YYYY-MM
$metrics_ym = $_GET['bulan'] ?? $ym_now;
$month_start = $metrics_ym . '-01';
$month_end = date('Y-m-t', strtotime($month_start));

// Hitung metrik lembur (pribadi vs partner) dan libur untuk bulan berjalan
$ot_pribadi = 0; $ot_partner = 0; $libur_hari = 0; $jadwal_libur = [];
try {
    global $conn;
    // OT split
    $sql_ot = "SELECT 
                  SUM(CASE WHEN OVERTIME_BONUS_FLAG=1 AND (OVERTIME_NOTES LIKE '%OT_PARTNER%') THEN 1 ELSE 0 END) AS ot_partner,
                  SUM(CASE WHEN OVERTIME_BONUS_FLAG=1 AND (OVERTIME_NOTES NOT LIKE '%OT_PARTNER%' OR OVERTIME_NOTES IS NULL) THEN 1 ELSE 0 END) AS ot_pribadi
               FROM T_ABSENSI
               WHERE KODESL = :nik AND TGL BETWEEN :d1 AND :d2";
    $st_ot = $conn->prepare($sql_ot);
    $st_ot->execute([':nik'=>$nik, ':d1'=>$month_start, ':d2'=>$month_end]);
    $row_ot = $st_ot->fetch(PDO::FETCH_ASSOC) ?: [];
    $ot_pribadi = (int)($row_ot['ot_pribadi'] ?? 0);
    $ot_partner = (int)($row_ot['ot_partner'] ?? 0);

    // Libur approved overlapping this month
    $sql_lv = "SELECT TGL_MULAI, TGL_SELESAI, JENIS_CUTI FROM T_PENGAJUAN_LIBUR
               WHERE KODESL = :nik AND STATUS = 'APPROVED' AND TGL_MULAI <= :end AND TGL_SELESAI >= :start";
    $st_lv = $conn->prepare($sql_lv);
    $st_lv->execute([':nik'=>$nik, ':start'=>$month_start, ':end'=>$month_end]);
    while ($lv = $st_lv->fetch(PDO::FETCH_ASSOC)) {
        $start = new DateTime(max($month_start, $lv['TGL_MULAI']));
        $end   = new DateTime(min($month_end, $lv['TGL_SELESAI']));
        $days = $start->diff($end)->days + 1;
        $libur_hari += $days;
        $jadwal_libur[] = [
            'mulai' => $start->format('Y-m-d'),
            'selesai' => $end->format('Y-m-d'),
            'jenis' => $lv['JENIS_CUTI'] ?? '-'
        ];
    }
} catch (PDOException $e) { /* ignore */ }

// Bonus/komisi dari payroll JSON untuk bulan yang dipilih
$bonus_individu_now = 0; $bonus_kolektif_now = 0; $komisi_now = 0;
try {
    $payroll_now = load_payroll_json($metrics_ym);
    if (!empty($payroll_now['items'])) {
        foreach ($payroll_now['items'] as $it) {
            if (($it['nik'] ?? '') === $nik) {
                $bonus_individu_now = (int)($it['bonus_individu'] ?? 0);
                $bonus_kolektif_now = (int)($it['bonus_kolektif'] ?? 0);
                $komisi_now = (int)($it['komisi'] ?? 0);
                break;
            }
        }
    }
} catch (Exception $e) { /* ignore */ }

// Tren lembur dihapus sesuai permintaan

// Item perhatian (cakupan stok) berdasarkan penjualan pegawai pada bulan terpilih (TOP 5)
$items_perhatian = [];
try {
    $days_in_period = (int)date('t', strtotime($month_start));
    $q = $conn->prepare("SELECT TOP 5 KODEBRG, NAMABRG, SUM(QTY) AS total_qty 
                         FROM V_JUAL 
                         WHERE KODESL = :nik AND TGL BETWEEN :d1 AND :d2 
                         GROUP BY KODEBRG, NAMABRG 
                         ORDER BY SUM(QTY) DESC");
    $q->execute([':nik'=>$nik, ':d1'=>$month_start, ':d2'=>$month_end]);
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        $kode = (string)$row['KODEBRG'];
        $nama = (string)$row['NAMABRG'];
        $total = (float)($row['total_qty'] ?? 0);
        $avg = $days_in_period > 0 ? ($total / $days_in_period) : 0;
        // Ambil stok on-hand
        $qs = $conn->prepare("SELECT SUM(ST00+ST01+ST02+ST03+ST04) FROM T_BARANG WHERE KODEBRG = :kb");
        $qs->execute([':kb'=>$kode]);
        $stok = (int)($qs->fetchColumn() ?? 0);
        $avg_eff = max($avg, 0.01); // hindari bagi 0
        $coverage = round($stok / $avg_eff, 1);
        $risk = ($coverage <= 7) ? 'Ya' : 'Tidak';
        $items_perhatian[] = [
            'kode' => $kode,
            'nama' => $nama,
            'stok' => $stok,
            'avg' => round($avg, 2),
            'coverage' => $coverage,
            'risk' => $risk
        ];
    }
} catch (PDOException $e) { /* ignore */ }

// Ambil nama pegawai (cache di session)
if (!isset($_SESSION['nama_pegawai'])) {
    try {
        global $conn;
        $stmt_nama = $conn->prepare("SELECT NAMASL FROM T_SALES WHERE KODESL = :nik");
        $stmt_nama->execute([':nik' => $nik]);
        $result = $stmt_nama->fetch(PDO::FETCH_ASSOC);
        $_SESSION['nama_pegawai'] = ($result && !empty($result['NAMASL'])) ? trim($result['NAMASL']) : 'Pegawai';
    } catch (PDOException $e) { $_SESSION['nama_pegawai'] = 'Pegawai'; }
}

// Variabel dasar
$bulan = date('Y-m');
$today_date = date('Y-m-d');
function idr($v){ return number_format((float)$v, 0, ',', '.'); }

// Target manual per orang
$semua_target = file_exists('target_manual.json') ? json_decode(file_get_contents('target_manual.json'), true) : [];
$target_bulan_data = is_array($semua_target) ? ($semua_target[$bulan] ?? $semua_target) : null; // dukung dua format
$target_per_orang = (float)($target_bulan_data['per_orang'] ?? 0);

// Realisasi
try {
    global $conn;
    $stmt = $conn->prepare("SELECT SUM(NETTO) as total FROM V_JUAL WHERE KODESL = :nik AND TGL BETWEEN :start AND :end");
    $stmt->execute(['nik' => $nik, 'start' => date('Y-m-01'), 'end' => date('Y-m-t')]);
    $realisasi = (float)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) { $realisasi = 0; }

$komisi = (int)round($realisasi * 0.01);
$persen = $target_per_orang > 0 ? (int)round($realisasi / $target_per_orang * 100) : 0;

// Peringkat penjualan
$user_rank = 'N/A';
$total_salespeople = 0;
try {
    global $conn;
    $stmt_rank = $conn->prepare(
        "SELECT KODESL, SUM(NETTO) as total_penjualan
         FROM V_JUAL WHERE TGL BETWEEN :start AND :end
         GROUP BY KODESL ORDER BY total_penjualan DESC"
    );
    $stmt_rank->execute(['start' => date('Y-m-01'), 'end' => date('Y-m-t')]);
    $all_sales_data = $stmt_rank->fetchAll(PDO::FETCH_ASSOC);
    $total_salespeople = count($all_sales_data);
    $rank = 1;
    foreach ($all_sales_data as $sales_row) {
        if (($sales_row['KODESL'] ?? '') === $nik) { $user_rank = $rank; break; }
        $rank++;
    }
} catch (PDOException $e) { $user_rank = 'Err'; $total_salespeople = 'Err'; }

// Jadwal & shift (tim A/B bergantian per hari)
$shift_times = [
    'S1' => ['start' => '08:30', 'end' => '18:00', 'name' => 'Shift 1 (Pagi)'],
    'S2' => ['start' => '12:00', 'end' => '20:30', 'name' => 'Shift 2 (Siang)'],
];
$teams = file_exists('employee_teams.json') ? json_decode(file_get_contents('employee_teams.json'), true) : [];
$is_team_A = in_array($nik, $teams['team_A'] ?? []);
$is_team_B = in_array($nik, $teams['team_B'] ?? []);
$start_date = new DateTime('2025-01-01'); $today_dt = new DateTime($today_date); $interval = $start_date->diff($today_dt); $days_diff = $interval->days; $is_odd_day = ($days_diff % 2 != 0);
if ($is_team_A) { $pegawai_shift = $is_odd_day ? 'S2' : 'S1'; }
elseif ($is_team_B) { $pegawai_shift = $is_odd_day ? 'S1' : 'S2'; }
else { $pegawai_shift = 'N/A'; }
$my_shift_label = $pegawai_shift === 'N/A' ? 'Tidak Terjadwal' : $shift_times[$pegawai_shift]['name'];
$my_shift_start_time = $pegawai_shift === 'N/A' ? '-' : $shift_times[$pegawai_shift]['start'];
$my_shift_end_time   = $pegawai_shift === 'N/A' ? '-' : $shift_times[$pegawai_shift]['end'];
$my_shift_code = $pegawai_shift;

$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Pegawai</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        
        .store-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }
        .store-header .logo { max-height: 50px; }
        .store-header .info { text-align: right; font-size: 13px; color: var(--text-muted); margin-left: auto; }

        .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
        .main-grid { display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start; }
        @media (max-width: 992px) { .main-grid { grid-template-columns: 1fr; } }
        
        .card { opacity: 0; animation: fadeInUp 0.5s ease-out forwards; }
        .main-content .card:nth-child(2) { animation-delay: 0.1s; }
        .sidebar .card:nth-child(1) { animation-delay: 0.2s; }
        .sidebar .card:nth-child(2) { animation-delay: 0.3s; }

        .stats-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:16px; }
        .stat-item { background: var(--bg); border:1px solid var(--border); padding:16px; border-radius: var(--radius-md); }
        .stat-item .label { color: var(--text-muted); font-size:14px; margin-bottom:8px; }
        .stat-item .value { font-size:22px; font-weight:700; }
        .actions-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(150px,1fr)); gap:12px; }
        .action-link { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:16px; gap:8px; text-decoration:none; background:#2a2a32; color:var(--text); border:1px solid var(--border); border-radius: var(--radius-md); transition:var(--transition); text-align: center; }
        .action-link:hover { background:#3a3a42; border-color:#555; transform: translateY(-2px); }
        .attendance-box { background: var(--bg); border:1px solid var(--border); border-radius: var(--radius-md); padding:16px; }
        .dashboard-links { display:flex; flex-direction:column; gap:12px; }
        .dashboard-link { padding:12px 16px; border-radius: var(--radius-md); border:1px solid var(--border); text-decoration:none; color:var(--text); background:#2a2a32; }
        .dashboard-link.logout { background: var(--red); color:white; }
        .notif-bar { padding:12px; margin-bottom:20px; border-radius: var(--radius-md); font-weight:600; }
        .notif-bar.success { background: var(--green); color: var(--bg); }
        .notif-bar.error { background: #e74c3c; color: white; }
        .btn-absen { width:100%; padding:12px; border-radius: var(--radius-sm); background: var(--brand-bg); color:var(--brand-text); border:0; cursor:pointer; }
        
        .progress-circle {
            width: 100px; height: 100px; border-radius: 50%;
            display: grid; place-items: center;
            background: conic-gradient(var(--green) calc(var(--p, 0) * 1%), var(--border) 0);
            transition: background 0.5s; margin: 0 auto;
        }
        .progress-circle::before {
            content: ""; display: block;
            width: 80px; height: 80px;
            background: var(--bg); border-radius: 50%;
        }
        .progress-circle .value {
            position: absolute; font-size: 24px; font-weight: 700; color: var(--green);
        }
    </style>
</head>
<body class="dark">
<div class="container max-w-[1200px] mx-auto px-4">
    <div class="store-header">
        <img src="assets/img/modecentre.png" alt="Mode Centre" class="logo">
        <div class="info">
            <div class="addr">Jl Pemuda Komp Ruko Pemuda 13 - 21 Banjarnegara Jawa Tengah</div>
            <div class="contact">Contact: 0813-9983-9777</div>
        </div>
    </div>
    <div class="header flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">Dashboard</h1>
        <div class="user-info opacity-80">Selamat Datang, <strong><?= htmlspecialchars($_SESSION['nama_pegawai'] ?? 'Pegawai'); ?></strong></div>
    </div>

    <?php if ($message): ?>
        <div class="notif-bar <?= stripos($message, 'berhasil') !== false ? 'success' : 'error' ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="main-grid grid gap-6 lg:grid-cols-3">
        <div class="main-content lg:col-span-2">
            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-chart-line"></i> Performa Anda (<?= date('F Y') ?>)</h2>
                <div class="stats-grid grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4"><span class="label">Target</span><span class="value" class="text-var-blue">Rp <?= idr($target_per_orang) ?></span></div>
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4"><span class="label">Realisasi</span><span class="value" class="text-var-green">Rp <?= idr($realisasi) ?></span></div>
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4"><span class="label">Komisi</span><span class="value" class="text-var-green">Rp <?= idr($komisi) ?></span></div>
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4"><span class="label">Peringkat</span><span class="value"><?= $user_rank ?> / <?= $total_salespeople ?></span></div>
                    <div class="stat-item rounded-xl border border-[color:var(--border)] bg-[color:var(--bg)] p-4 col-span-2 text-center">
                        <span class="label">Capaian Target</span>
                        <div id="progress-capaian" class="progress-circle" style="--p: <?= $persen ?>;">
                           <span class="value"><?= $persen ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-rocket"></i> Aksi Cepat</h2>
                <div class="actions-grid grid gap-3 sm:grid-cols-4">
                    <a href="cek_stok_pegawai.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-box-archive fa-2x"></i><span>Cek Stok</span></a>
                    <a href="laporan_penjualan_pegawai.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-file-lines fa-2x"></i><span>Laporan Jual</span></a>
                    <a href="pengajuan_libur.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-umbrella-beach fa-2x"></i><span>Ajukan Libur</span></a>
                    <a href="pengajuan_tukar_shift.php" class="action-link rounded-xl border border-[color:var(--border)] bg-[color:var(--card)] hover:bg-[#3a3a42] transition"><i class="fa-solid fa-right-left fa-2x"></i><span>Tukar Shift</span></a>
                </div>
            </div>

            <?php if (!$my_wa): ?>
            <div class="card">
                <h2 class="card-title"><i class="fa-brands fa-whatsapp"></i> Tambahkan Nomor WhatsApp</h2>
                <p class="muted" style="margin-top:-6px">Nomor WA diperlukan untuk menerima notifikasi (reset password, info libur, tukar shift, dll).</p>
                <form method="post" action="save_pegawai_wa.php" class="form" style="display:flex; gap:8px; align-items:center;">
                    <input class="input" type="text" name="wa" placeholder="Contoh: 628123456789" required style="max-width:280px;">
                    <button class="btn" type="submit">Simpan</button>
                </form>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="bar" style="display:flex; justify-content:space-between; align-items:center;">
                    <h2 class="card-title"><i class="fa-solid fa-chart-simple"></i> Statistik Bulan <?= htmlspecialchars(date('F Y', strtotime($month_start))) ?></h2>
                    <form method="get" class="form" style="display:flex; gap:8px; align-items:center;">
                        <input class="input" type="month" name="bulan" value="<?= htmlspecialchars($metrics_ym) ?>">
                        <button class="btn" type="submit">Terapkan</button>
                    </form>
                </div>
                <div class="summary-grid">
                    <div class="summary-card">
                        <span class="label">Lembur Pribadi (Rp 5.000)</span>
                        <span class="value"><?= (int)$ot_pribadi ?>x &middot; Rp <?= fmt_idr($ot_pribadi * 5000) ?></span>
                    </div>
                    <div class="summary-card">
                        <span class="label">Lembur Partner (Rp 10.000)</span>
                        <span class="value"><?= (int)$ot_partner ?>x &middot; Rp <?= fmt_idr($ot_partner * 10000) ?></span>
                    </div>
                    <div class="summary-card">
                        <span class="label">Total Libur Disetujui</span>
                        <span class="value"><?= (int)$libur_hari ?> hari</span>
                    </div>
                    <div class="summary-card">
                        <span class="label">Komisi (1%)</span>
                        <span class="value">Rp <?= fmt_idr($komisi_now) ?></span>
                    </div>
                    <div class="summary-card">
                        <span class="label">Bonus Individu</span>
                        <span class="value">Rp <?= fmt_idr($bonus_individu_now) ?></span>
                    </div>
                    <div class="summary-card">
                        <span class="label">Bonus Kolektif</span>
                        <span class="value">Rp <?= fmt_idr($bonus_kolektif_now) ?></span>
                    </div>
                </div>
                <?php if (!empty($jadwal_libur)): ?>
                <div class="mt-3">
                    <span class="label" style="display:block; margin-bottom:6px;">Jadwal Libur (Approved) – <?= htmlspecialchars(date('F Y', strtotime($month_start))) ?></span>
                    <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:8px;">
                        <?php foreach($jadwal_libur as $lv): ?>
                            <div class="badge" style="display:block; background:#3a3a42; border:1px solid var(--border);">
                                <?= htmlspecialchars($lv['jenis']) ?> · <?= htmlspecialchars(date('d/m', strtotime($lv['mulai']))) ?> - <?= htmlspecialchars(date('d/m', strtotime($lv['selesai']))) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            

            <?php if (!empty($items_perhatian)): ?>
            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-triangle-exclamation"></i> Item yang Perlu Perhatian (Cakupan Stok)</h2>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Kode</th><th>Nama</th><th class="right">Stok</th><th class="right">Rata2/Hari</th><th class="right">Cakupan (hari)</th><th class="center">Risiko Kehabisan</th></tr></thead>
                        <tbody>
                            <?php foreach ($items_perhatian as $itx): ?>
                                <tr>
                                    <td><?= htmlspecialchars($itx['kode']) ?></td>
                                    <td><?= htmlspecialchars($itx['nama']) ?></td>
                                    <td class="right"><?= (int)$itx['stok'] ?></td>
                                    <td class="right"><?= htmlspecialchars($itx['avg']) ?></td>
                                    <td class="right"><?= htmlspecialchars($itx['coverage']) ?></td>
                                    <td class="center"><?= $itx['risk'] === 'Ya' ? '<span class="badge level-down">Ya</span>' : '<span class="badge level-up">Tidak</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="muted" style="margin-top:6px;">Catatan: Risiko dihitung dari cakupan stok ≤ 7 hari berdasarkan penjualan bulan ini.</div>
            </div>
            <?php endif; ?>
        </div>

        <div class="sidebar lg:col-span-1">
            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-clock"></i> Jadwal & Absensi</h2>
                <div class="attendance-box">
                    <p style="margin:0; color: var(--text-muted); font-size: 0.9em;">Jadwal Hari Ini (<?= $today_date ?>):</p>
                    <p id="shift-status" style="margin:5px 0; font-size:1.1em; font-weight:600;">
                        <span><?= $my_shift_label ?></span>
                        <small>(<?= $my_shift_start_time ?> - <?= $my_shift_end_time ?>)</small>
                    </p>
                    <div id="attendance-actions" data-shift-end="<?= htmlspecialchars($my_shift_end_time) ?>"><button class="btn-absen" disabled>Memuat...</button></div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-file-invoice-dollar"></i> Gaji & Akun</h2>
                <div class="dashboard-links">
                    <?php $ym_now = date('Y-m'); $payroll_json = load_payroll_json($ym_now); $my_slip = null; if (!empty($payroll_json['items'])) { foreach ($payroll_json['items'] as $it) { if (!empty($it['nik']) && $it['nik'] === $nik) { $my_slip = $it; break; } } } ?>
                    <?php if ($my_slip): ?>
                        <a href="payroll/payroll_my_slip.php?bulan=<?= $ym_now ?>" class="dashboard-link"><i class="fa-solid fa-receipt"></i> Slip Gaji Bulan Ini</a>
                    <?php endif; ?>
                    <?php $available_slips = []; $cursor = strtotime(date('Y-m-01')); for ($i=0; $i<12; $i++) { $ym = date('Y-m', strtotime("-$i month", $cursor)); $file = payroll_file_for($ym); if (is_file($file)) { $js = json_decode(@file_get_contents($file), true); if (is_array($js) && !empty($js['items'])) { foreach ($js['items'] as $row) { if (!empty($row['nik']) && $row['nik'] === $nik) { $available_slips[] = [ 'ym' => $ym, 'label' => date('F Y', strtotime($ym.'-01')) ]; break; } } } } } ?>
                    <select class="dashboard-link" style="width:100%; padding:11px; -webkit-appearance: none;" onchange="if(this.value) window.location.href='payroll/payroll_my_slip.php?bulan=' + this.value">
                        <option value="">Lihat Periode Lain...</option>
                        <?php foreach ($available_slips as $row): ?>
                            <option value="<?= $row['ym'] ?>"><?= htmlspecialchars($row['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="logout.php" class="dashboard-link logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                </div>
            </div>
        </div>
    </div>
</div>

<form id="absen-form" action="absen.php" method="POST" style="display:none;">
    <input type="hidden" name="action" id="absen-action">
    <input type="hidden" name="shift" id="absen-shift" value="<?= htmlspecialchars($my_shift_code) ?>">
    <input type="hidden" name="user_lat" id="user_lat">
    <input type="hidden" name="user_lon" id="user_lon">
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Script untuk animasi progress circle
    const progressCircle = document.getElementById('progress-capaian');
    if (progressCircle) {
        const finalPercent = parseInt(progressCircle.style.getPropertyValue('--p'), 10);
        progressCircle.style.setProperty('--p', 0); // Reset ke 0
        setTimeout(() => {
            progressCircle.style.setProperty('--p', finalPercent); // Animasikan ke nilai akhir
        }, 100);
    }

    // Script absensi fungsional
    const actions = document.getElementById('attendance-actions');
    const form = document.getElementById('absen-form');
    const actionInput = document.getElementById('absen-action');
    const latInput = document.getElementById('user_lat');
    const lonInput = document.getElementById('user_lon');

    async function getStatus() {
        try {
            const res = await fetch('get_absen_status.php', { credentials: 'same-origin' });
            if (!res.ok) return { status: 'error', message: 'Server error' };
            return await res.json();
        } catch (e) { return { status: 'error', message: 'Network error' }; }
    }

    function setGeo() {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    latInput.value = pos.coords.latitude.toFixed(6);
                    lonInput.value = pos.coords.longitude.toFixed(6);
                }, 
                () => { /* ignore error */ }
            );
        }
    }

    function render(status) {
        actions.innerHTML = '';
        setGeo();
        const makeBtn = (label, kind, cls) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = cls || 'btn-absen';
            b.textContent = label;
            b.addEventListener('click', () => {
                actionInput.value = kind;
                form.submit();
            });
            return b;
        };

        if (status.status !== 'success') {
            actions.innerHTML = '<div class="text-var-muted" style=""font-size:0.9em;">Gagal memuat status absen.</div>';
            return;
        }
        
        const hasMasuk = !!status.masuk;
        const hasPulang = !!status.pulang;

        if (!hasMasuk) {
            // Tailwind green button for check-in
            actions.appendChild(makeBtn('Absen Masuk', 'masuk', 'w-full py-3 rounded-md text-white bg-emerald-600 hover:bg-emerald-500 transition'));
        } else if (!hasPulang) {
            // Default red; switch to green when shift end time active
            const endStr = actions.getAttribute('data-shift-end') || '';
            const now = new Date();
            let useGreen = false;
            if (/^\d{2}:\d{2}$/.test(endStr)) {
                const [hh, mm] = endStr.split(':').map(v => parseInt(v, 10));
                const end = new Date(); end.setHours(hh, mm, 0, 0);
                if (now >= end) useGreen = true;
            }
            const cls = useGreen || status.overtime
              ? 'w-full py-3 rounded-md text-white bg-emerald-600 hover:bg-emerald-500 transition'
              : 'w-full py-3 rounded-md text-white bg-red-600 hover:bg-red-500 transition';
            const label = status.overtime ? 'Selesai Lembur' : 'Absen Pulang';
            actions.appendChild(makeBtn(label, 'pulang', cls));
            // Info bar to notify already checked in
            const info = document.createElement('div');
            info.className = 'mt-2 text-sm text-[color:var(--text-muted)]';
            info.textContent = (status.overtime ? 'Status: Lembur aktif.' : 'Status: Sudah absen masuk') + (status.masuk ? ' ' + status.masuk : '');
            actions.appendChild(info);
        } else {
            // Sudah ada pulang (bisa auto-pulang). Tawarkan tombol Lembur jika belum OT
            if (!status.overtime) {
                const b = document.createElement('button');
                b.type = 'button'; b.className = 'btn-absen'; b.textContent = 'Mulai Lembur';
                b.addEventListener('click', () => { actionInput.value = 'lembur'; form.submit(); });
                actions.appendChild(b);
                const info = document.createElement('div');
                info.className = 'mt-2 text-sm text-[color:var(--text-muted)]';
                info.textContent = 'Status: Pulang otomatis pada ' + (status.pulang || '-') + '. Klik untuk mulai lembur.';
                actions.appendChild(info);
            } else {
                const info = document.createElement('div');
                info.className = 'text-var-muted';
                info.textContent = 'Lembur aktif. Silakan Absen Pulang saat selesai.';
                actions.appendChild(info);
            }
        }
    }

    if (actions && form) {
        getStatus().then(render);
    }
});
</script>
</body>
</html>
// $my_wa sudah didefinisikan di atas
