<?php
require_once 'config.php';
session_start();
date_default_timezone_set('Asia/Jakarta');
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { die("Akses ditolak."); }

try {
    if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
    if (!isset($pdo)) { throw new Exception("Objek PDO \$pdo tidak tersedia dari config.php"); }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) { http_response_code(500); die("Koneksi DB gagal: ".$e->getMessage()); }

// ===== Helpers =====
function moneyIDR($n){ return 'Rp '.number_format((float)$n,0,',','.'); }
function getColumns(PDO $pdo, $table){
    $sql = "SELECT c.name FROM sys.columns c JOIN sys.objects o ON o.object_id=c.object_id WHERE o.type='U' AND o.name=:t";
    $st=$pdo->prepare($sql); $st->execute([':t'=>$table]);
    return array_map('strtoupper', $st->fetchAll(PDO::FETCH_COLUMN));
}
function pickFirstExisting(array $allColsUpper, array $cands){
    foreach ($cands as $c){ if (in_array(strtoupper($c), $allColsUpper, true)) return $c; }
    return null;
}
function mapCaraBayar(?string $val): string {
    if ($val === null || $val === '') return '(Tidak diisi)';
    $trim = trim($val);
    if ($trim === '1') return 'Cash';
    if ($trim === '2') return 'Card';
    return $trim;
}

// ===== Konfigurasi Tabel & Kolom =====
$T_JUAL = 'HIS_JUAL';
$T_DJUAL = 'HIS_DTJUAL';

$colsHeader = getColumns($pdo, $T_JUAL);
$dateCol = pickFirstExisting($colsHeader, ['TGL','TANGGAL','TGLJUAL','TGLTRANSAKSI','DATE','DOC_DATE']) ?? 'TGL';
$notaCol = pickFirstExisting($colsHeader, ['NONOTA','NOBUKTI','NOJUAL','NOFAKTUR']) ?? 'NONOTA';
$payCol = pickFirstExisting($colsHeader, ['CARABAYAR','JENISBAYAR','METODEBAYAR','TYPEBAYAR','TIPEBAYAR']);

$colsDetail = getColumns($pdo, $T_DJUAL);
$detailNotaCol = pickFirstExisting($colsDetail, ['NONOTA','NOBUKTI','NOJUAL','NOFAKTUR']) ?? 'NONOTA';
$subtotalCol = pickFirstExisting($colsDetail, ['NETTO','SUBTOTAL','TOTAL','BRUTO']);
$qtyCol = pickFirstExisting($colsDetail, ['QTY','QTYJUAL']);
$hargaCol = pickFirstExisting($colsDetail, ['HGJUAL','HARGA','HJUAL']);
$discRp1 = pickFirstExisting($colsDetail, ['HITDISC1','DISC_RP1']);
$discRp2 = pickFirstExisting($colsDetail, ['HITDISC2','DISC_RP2']);

// ===== Parameter =====
$todayStr = date('Y-m-d');
$tanggal = (isset($_GET['tanggal']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tanggal'])) ? $_GET['tanggal'] : $todayStr;
$start = $tanggal . ' 00:00:00';
$end = $tanggal . ' 23:59:59';
$now = date('Y-m-d H:i:s');
$isToday = ($tanggal === $todayStr);

// ===== [REFAKTOR] Hitung Total & Breakdown dengan 1 Query =====
$total = 0.0;
$byPay = [];
$source = '';

try {
    // Tentukan ekspresi subtotal berdasarkan kolom yang tersedia
    $subtotal_expression = '';
    if ($subtotalCol && strtoupper($subtotalCol) === 'NETTO') {
        $subtotal_expression = "CAST(D.$subtotalCol AS DECIMAL(18,2))";
        $source = "$T_DJUAL.NETTO";
    } elseif ($subtotalCol && strtoupper($subtotalCol) === 'BRUTO') {
        $d1 = $discRp1 ? "ISNULL(CAST(D.$discRp1 AS DECIMAL(18,2)), 0)" : "0";
        $d2 = $discRp2 ? "ISNULL(CAST(D.$discRp2 AS DECIMAL(18,2)), 0)" : "0";
        $subtotal_expression = "CAST(D.$subtotalCol AS DECIMAL(18,2)) - ($d1 + $d2)";
        $source = "$T_DJUAL.BRUTO - diskon";
    } elseif ($qtyCol && $hargaCol) {
        $subtotal_expression = "CAST(D.$qtyCol AS DECIMAL(18,4)) * CAST(D.$hargaCol AS DECIMAL(18,2))";
        $source = "$T_DJUAL ($qtyCol × $hargaCol)";
    } else {
        throw new Exception("Kolom untuk perhitungan (NETTO/BRUTO/QTY*HGJUAL) tidak ditemukan di tabel $T_DJUAL.");
    }

    // Bangun query utama
    $sql = "
        WITH JualHeader AS (
            SELECT 
                $notaCol,
                " . ($payCol ? "COALESCE(CAST($payCol AS NVARCHAR(50)), N'')" : "N''") . " AS CARA
            FROM $T_JUAL
            WHERE $dateCol BETWEEN :s AND :e
        )
        SELECT 
            J.CARA,
            SUM($subtotal_expression) AS SubtotalPerCara
        FROM $T_DJUAL D
        INNER JOIN JualHeader J ON J.$notaCol = D.$detailNotaCol
        GROUP BY J.CARA
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':s' => $start, ':e' => $end]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Proses hasil query tunggal untuk total dan breakdown
    foreach ($results as $row) {
        $subtotal = (float)($row['SubtotalPerCara'] ?? 0);
        $label = mapCaraBayar($row['CARA']);
        $byPay[$label] = ($byPay[$label] ?? 0) + $subtotal;
        $total += $subtotal;
    }

} catch (Throwable $e) {
    if (isset($_GET['json']) && $_GET['json'] == '1') {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
    die("Gagal menghitung: " . $e->getMessage());
}

// ===== Respon JSON (jika diminta) =====
if (isset($_GET['json']) && $_GET['json'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'date' => $tanggal, 'start' => $start, 'end' => $end, 'now' => $now,
        'total' => $total, 'total_fmt' => moneyIDR($total), 'source' => $source,
        'by_pay' => $byPay
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Total Penjualan Harian</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { /* Dark Theme Variables */
            --brand-bg: #d32f2f; --brand-text: #ffffff; --bg: #121218; --card: #1e1e24; --text: #e0e0e0;
            --text-muted: #8b8b9a; --border: #33333d; --border-hover: #4a4a58;
            --fs-h1: clamp(22px, 2.6vw, 28px); --fs-h2: clamp(18px, 1.8vw, 22px);
            --radius-md: 12px; --radius-lg: 16px;
        }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); }
        .container { max-width: min(960px, 96vw); margin: 24px auto; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 24px; margin-bottom: 24px; animation: fadeInUp 0.5s ease-out forwards; }
        .card:nth-of-type(2) { animation-delay: 0.1s; }
        .card:nth-of-type(3) { animation-delay: 0.2s; }
        
        .topbar { display:flex; flex-wrap: wrap; gap:16px; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .topbar h1 { margin: 0; display: flex; align-items: center; gap: 12px;}
        form.filters { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; }
        .table-wrap { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid var(--border); }
        th { font-weight: 600; font-size: 0.9em; text-transform: uppercase; color: var(--text-muted); }
        tbody tr:last-child td { border-bottom: none; }
        .right { text-align: right; }
        .muted { font-size: 0.9em; color: var(--text-muted); }
        .total-amount {
            font-size: clamp(44px, 8vw, 64px);
            font-weight: 800;
            color: var(--brand-bg);
            line-height: 1.1;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <h1><i class="fas fa-dollar-sign"></i> Total Penjualan</h1>
        <a href="dashboard_admin.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>

    <div class="card">
        <form method="get" class="filters">
            <label for="tanggal" class="muted">Pilih Tanggal</label>
            <input type="date" id="tanggal" name="tanggal" value="<?= htmlspecialchars($tanggal) ?>" />
            <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Tampilkan</button>
            <a class="btn" href="?tanggal=<?= htmlspecialchars($todayStr) ?>"><i class="fas fa-sync-alt"></i> Hari Ini</a>
        </form>
    </div>

    <div class="card">
        <div class="total-amount" id="total"><?= moneyIDR($total) ?></div>
        <div class="muted" id="period"><?= htmlspecialchars($start) ?> &mdash; <?= htmlspecialchars($end) ?></div>
    </div>

    <div class="card">
        <h3><i class="fas fa-credit-card"></i> Rincian Metode Bayar</h3>
        <div class="table-wrap table-card">
            <table class="table">
                <thead>
                    <tr>
                        <th>Metode</th>
                        <th class="right">Nominal</th>
                    </tr>
                </thead>
                <tbody id="byPayBody">
                    <?php if (empty($byPay)): ?>
                        <tr><td colspan="2" class="muted" style="text-align:center; padding: 20px;">Belum ada data pembayaran.</td></tr>
                    <?php else: ?>
                        <?php arsort($byPay); foreach ($byPay as $cara => $tot): ?>
                            <tr>
                                <td><?= htmlspecialchars($cara) ?></td>
                                <td class="right"><?= moneyIDR($tot) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    async function refreshNow() {
        try {
            const q = new URLSearchParams(window.location.search);
            q.set('json', '1');
            const response = await fetch(location.pathname + '?' + q.toString(), { cache: 'no-store' });
            if (!response.ok) throw new Error('Network error: ' + response.statusText);
            
            const data = await response.json();
            
            document.getElementById('total').textContent = data.total_fmt;
            document.getElementById('period').textContent = data.start + ' — ' + data.end;
            
            const tbody = document.getElementById('byPayBody');
            tbody.innerHTML = '';
            const entries = Object.entries(data.by_pay || {});
            
            if (entries.length === 0) {
                tbody.innerHTML = '<tr><td colspan="2" class="muted" style="text-align:center; padding: 20px;">Belum ada data pembayaran.</td></tr>';
                return;
            }

            entries.sort((a, b) => b[1] - a[1]);
            entries.forEach(([cara, tot]) => {
                const tr = tbody.insertRow();
                const cellCara = tr.insertCell();
                cellCara.textContent = cara;

                const cellTot = tr.insertCell();
                cellTot.className = 'right';
                cellTot.textContent = 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(tot));
            });
        } catch (e) {
            console.error("Gagal refresh data:", e);
        }
    }

    const isToday = "<?= $isToday ? '1' : '0' ?>";
    if (isToday === '1') {
        // [OPTIMASI] Ubah interval menjadi 15 detik
        setInterval(refreshNow, 15000); 
    }
</script>
</body>
</html>