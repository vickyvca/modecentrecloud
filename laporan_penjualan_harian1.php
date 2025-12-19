<?php
// penjualan_hari_ini.php (v3) - cocok skema: HIS_DTJUAL punya NETTO/BRUTO/HGJUAL/QTY/DISC1,DIC2,HITDISC1,HITDISC2
require_once 'config.php';
session_start();
date_default_timezone_set('Asia/Jakarta');

// if (!isset($_SESSION['nik'])) { die("Akses ditolak."); }

try {
    if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
    if (!isset($pdo)) { throw new Exception("Objek PDO \$pdo tidak tersedia dari config.php"); }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) { http_response_code(500); die("Koneksi DB gagal: ".$e->getMessage()); }

// ===== Helpers =====
function moneyIDR($n){ return 'Rp '.number_format((float)$n,0,',','.'); }
function getColumns(PDO $pdo, $table){
    $sql = "SELECT c.name FROM sys.columns c JOIN sys.objects o ON o.object_id=c.object_id
            WHERE o.type='U' AND o.name=:t";
    $st=$pdo->prepare($sql); $st->execute([':t'=>$table]);
    return array_map('strtoupper',$st->fetchAll(PDO::FETCH_COLUMN));
}
function pickFirstExisting(array $allColsUpper, array $cands){
    foreach ($cands as $c){
        if (in_array(strtoupper($c), $allColsUpper, true)) return $c;
    }
    return null;
}

// ===== Tables =====
$T_JUAL  = 'HIS_JUAL';
$T_DJUAL = 'HIS_DTJUAL';

// ===== Columns discovery (header) =====
$colsHeader = getColumns($pdo, $T_JUAL);
$dateCol  = pickFirstExisting($colsHeader, ['TGL','TANGGAL','TGLJUAL','TGLTRANSAKSI','DATE','DOC_DATE']) ?? 'TGL';
$totalCol = pickFirstExisting($colsHeader, [
    'TOTALJUAL','TOTAL','NETTO','GRANDTOTAL','TOTALNET','TOTAL_BAYAR',
    'NILAIJUAL','NILAI','BRUTO','NET','TOTALBAYAR','TOTALRP','JUMLAHRP','TOTALHTG','TOTAL_NOTA','TAGIHAN','BAYAR'
]); // kalau ada BAYAR di header & memang itu total, sistem akan pakai; kalau bukan, pakai detail
$notaCol  = pickFirstExisting($colsHeader, ['NONOTA','NOBUKTI','NOJUAL','NOFAKTUR']) ?? 'NONOTA';

// ===== Columns discovery (detail) =====
$colsDetail = getColumns($pdo, $T_DJUAL);
$detailNotaCol = pickFirstExisting($colsDetail, ['NONOTA','NOBUKTI','NOJUAL','NOFAKTUR']) ?? 'NONOTA';

// Prioritas subtotal per baris: NETTO lalu BRUTO
$subtotalCol = pickFirstExisting($colsDetail, [
    'NETTO','SUBTOTAL','TOTAL','JUMLAHRP','AMOUNT','NILAI','NILAI_ITEM','LINE_TOTAL','BRUTO'
]);

// Jika tidak ada subtotal, pakai qty*harga - diskon
$qtyCol    = pickFirstExisting($colsDetail, ['QTY','QTYJUAL','QTY_BRG','JUMLAH','JUMLAHQTY','QTYDTL']);
$hargaCol  = pickFirstExisting($colsDetail, ['HGJUAL','HARGA','HJUAL','H_JUAL','HARGAJUAL','HARGA_JUAL','PRICE','HRG','HRGJUAL','HRG_JUAL','NETHRG','NET_HRG','HARGANET','HNET']);
$discRp1   = pickFirstExisting($colsDetail, ['HITDISC1','DISC_RP1','DISKONRP','DISKON_RP','DISCOUNT_RP','POTONGANRP']);
$discRp2   = pickFirstExisting($colsDetail, ['HITDISC2','DISC_RP2']);
$discPct1  = pickFirstExisting($colsDetail, ['DISC1','DISKON1','DISC_PCT1']);
$discPct2  = pickFirstExisting($colsDetail, ['DISC2','DISKON2','DISC_PCT2']);

// ===== Debug endpoint =====
if (isset($_GET['debug']) && $_GET['debug']=='1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "TABLES\n- HEADER: $T_JUAL\n- DETAIL: $T_DJUAL\n\n";
    echo "HEADER COLS:\n".implode(', ',$colsHeader)."\n\n";
    echo "DETAIL COLS:\n".implode(', ',$colsDetail)."\n\n";
    echo "DETECTED:\n";
    echo " dateCol      = $dateCol\n";
    echo " totalCol     = ".($totalCol?:'(null)')."\n";
    echo " notaCol      = $notaCol\n";
    echo " detailNota   = $detailNotaCol\n";
    echo " subtotalCol  = ".($subtotalCol?:'(null)')."\n";
    echo " qtyCol       = ".($qtyCol?:'(null)')."\n";
    echo " hargaCol     = ".($hargaCol?:'(null)')."\n";
    echo " discRp1      = ".($discRp1?:'(null)')."\n";
    echo " discRp2      = ".($discRp2?:'(null)')."\n";
    echo " discPct1     = ".($discPct1?:'(null)')."\n";
    echo " discPct2     = ".($discPct2?:'(null)')."\n";
    exit;
}

// ===== Range: today 00:00 -> now =====
$today = date('Y-m-d');
$start = $today.' 00:00:00';
$now   = date('Y-m-d H:i:s');

// ===== Compute total =====
$total = 0.0; $source = '';
try {
    if ($totalCol) {
        // Hati-hati: di skema Anda ada BAYAR di header; gunakan ini hanya jika memang merepresentasikan total penjualan.
        $sql = "SELECT SUM(CAST($totalCol AS DECIMAL(18,2))) FROM $T_JUAL WHERE $dateCol BETWEEN :s AND :e";
        $st = $pdo->prepare($sql); $st->execute([':s'=>$start, ':e'=>$now]);
        $total = (float)($st->fetchColumn() ?: 0);
        $source = "$T_JUAL.$totalCol";
    } else {
        // FILTER nota hari ini dari header
        if ($subtotalCol && strtoupper($subtotalCol)==='NETTO') {
            // NETTO per baris = nilai bersih setelah diskon → paling akurat
            $sql = "
                WITH J AS ( SELECT $notaCol FROM $T_JUAL WHERE $dateCol BETWEEN :s AND :e )
                SELECT SUM(CAST(D.$subtotalCol AS DECIMAL(18,2))) AS tot
                FROM $T_DJUAL D
                INNER JOIN J ON J.$notaCol = D.$detailNotaCol
            ";
            $st=$pdo->prepare($sql); $st->execute([':s'=>$start, ':e'=>$now]);
            $total = (float)($st->fetchColumn() ?: 0);
            $source = "$T_DJUAL.NETTO";
        } elseif ($subtotalCol && strtoupper($subtotalCol)==='BRUTO') {
            // BRUTO = qty*harga sebelum diskon; koreksi dengan diskon jika ada
            if ($discRp1 || $discRp2) {
                $d1 = $discRp1 ? "ISNULL(CAST(D.$discRp1 AS DECIMAL(18,2)),0)" : "0";
                $d2 = $discRp2 ? "ISNULL(CAST(D.$discRp2 AS DECIMAL(18,2)),0)" : "0";
                $sql = "
                    WITH J AS ( SELECT $notaCol FROM $T_JUAL WHERE $dateCol BETWEEN :s AND :e )
                    SELECT SUM( CAST(D.$subtotalCol AS DECIMAL(18,2)) - ($d1 + $d2) ) AS tot
                    FROM $T_DJUAL D
                    INNER JOIN J ON J.$notaCol = D.$detailNotaCol
                ";
                $source = "$T_DJUAL.BRUTO - (HITDISC1+HITDISC2)";
            } elseif ($discPct1 || $discPct2) {
                $p1 = $discPct1 ? " (1 - (NULLIF(CAST(D.$discPct1 AS DECIMAL(9,4)),0) / 100.0)) " : "1";
                $p2 = $discPct2 ? " (1 - (NULLIF(CAST(D.$discPct2 AS DECIMAL(9,4)),0) / 100.0)) " : "1";
                $sql = "
                    WITH J AS ( SELECT $notaCol FROM $T_JUAL WHERE $dateCol BETWEEN :s AND :e )
                    SELECT SUM( CAST(D.$subtotalCol AS DECIMAL(18,2)) * $p1 * $p2 ) AS tot
                    FROM $T_DJUAL D
                    INNER JOIN J ON J.$notaCol = D.$detailNotaCol
                ";
                $source = "$T_DJUAL.BRUTO * (1-DISC1%) * (1-DISC2%)";
            } else {
                $sql = "
                    WITH J AS ( SELECT $notaCol FROM $T_JUAL WHERE $dateCol BETWEEN :s AND :e )
                    SELECT SUM( CAST(D.$subtotalCol AS DECIMAL(18,2)) ) AS tot
                    FROM $T_DJUAL D
                    INNER JOIN J ON J.$notaCol = D.$detailNotaCol
                ";
                $source = "$T_DJUAL.BRUTO (tanpa diskon terdeteksi)";
            }
            $st=$pdo->prepare($sql); $st->execute([':s'=>$start, ':e'=>$now]);
            $total = (float)($st->fetchColumn() ?: 0);
        } else {
            // Tidak ada subtotal → pakai qty*harga - diskonRp (kalau ada) atau diskon%
            if (!$qtyCol || !$hargaCol) {
                throw new Exception("Tidak menemukan NETTO/BRUTO maupun pasangan qty/harga di $T_DJUAL. Buka ?debug=1 untuk melihat nama kolom.");
            }

            if ($discRp1 || $discRp2) {
                $d1 = $discRp1 ? "ISNULL(CAST(D.$discRp1 AS DECIMAL(18,2)),0)" : "0";
                $d2 = $discRp2 ? "ISNULL(CAST(D.$discRp2 AS DECIMAL(18,2)),0)" : "0";
                $sql = "
                    WITH J AS ( SELECT $notaCol FROM $T_JUAL WHERE $dateCol BETWEEN :s AND :e )
                    SELECT SUM( CAST(D.$qtyCol AS DECIMAL(18,4)) * CAST(D.$hargaCol AS DECIMAL(18,2)) - ($d1 + $d2) ) AS tot
                    FROM $T_DJUAL D
                    INNER JOIN J ON J.$notaCol = D.$detailNotaCol
                ";
                $source = "$T_DJUAL ($qtyCol×$hargaCol − HITDISC1/HITDISC2)";
            } elseif ($discPct1 || $discPct2) {
                $p1 = $discPct1 ? " (1 - (NULLIF(CAST(D.$discPct1 AS DECIMAL(9,4)),0) / 100.0)) " : "1";
                $p2 = $discPct2 ? " (1 - (NULLIF(CAST(D.$discPct2 AS DECIMAL(9,4)),0) / 100.0)) " : "1";
                $sql = "
                    WITH J AS ( SELECT $notaCol FROM $T_JUAL WHERE $dateCol BETWEEN :s AND :e )
                    SELECT SUM( CAST(D.$qtyCol AS DECIMAL(18,4)) * CAST(D.$hargaCol AS DECIMAL(18,2)) * $p1 * $p2 ) AS tot
                    FROM $T_DJUAL D
                    INNER JOIN J ON J.$notaCol = D.$detailNotaCol
                ";
                $source = "$T_DJUAL ($qtyCol×$hargaCol × (1-DISC1%) × (1-DISC2%))";
            } else {
                $sql = "
                    WITH J AS ( SELECT $notaCol FROM $T_JUAL WHERE $dateCol BETWEEN :s AND :e )
                    SELECT SUM( CAST(D.$qtyCol AS DECIMAL(18,4)) * CAST(D.$hargaCol AS DECIMAL(18,2)) ) AS tot
                    FROM $T_DJUAL D
                    INNER JOIN J ON J.$notaCol = D.$detailNotaCol
                ";
                $source = "$T_DJUAL ($qtyCol×$hargaCol)";
            }
            $st=$pdo->prepare($sql); $st->execute([':s'=>$start, ':e'=>$now]);
            $total = (float)($st->fetchColumn() ?: 0);
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    die("Gagal menghitung: ".$e->getMessage());
}

// ===== JSON =====
if (isset($_GET['json']) && $_GET['json']=='1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'date'=>$today,'start'=>$start,'now'=>$now,
        'total'=>$total,'total_fmt'=>moneyIDR($total),'source'=>$source
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== UI =====
?>
<!doctype html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Total Penjualan Hari Ini</title>
<style>
  :root{color-scheme:light dark}
  body{font-family:system-ui,Arial,sans-serif;margin:24px;display:grid;place-items:center}
  .wrap{text-align:center;max-width:560px;width:100%}
  .title{font-size:16px;color:#666;margin-bottom:8px}
  .total{font-size:clamp(40px,8vw,72px);font-weight:800;letter-spacing:.5px}
  .muted{margin-top:6px;color:#777;font-size:12px}
  .row{display:flex;gap:8px;justify-content:center;margin-top:12px}
  .btn{padding:8px 12px;border:1px solid #aaa;border-radius:10px;background:transparent;color:inherit;text-decoration:none}
  .btn:hover{background:rgba(0,0,0,.06)}
</style>
<script>
async function refreshNow(){
  try{
    const r = await fetch(location.pathname + '?json=1', {cache:'no-store'});
    const j = await r.json();
    document.getElementById('total').textContent = j.total_fmt;
    document.getElementById('time').textContent  = j.now + ' (Asia/Jakarta)';
    document.getElementById('source').textContent  = j.source;
  }catch(e){}
}
setInterval(refreshNow, 5000);
window.addEventListener('load', refreshNow);
</script>
</head>
<body>
  <div class="wrap">
    <div class="title">Total Penjualan Hari Ini</div>
    <div id="total" class="total"><?= moneyIDR($total) ?></div>
    <div class="muted">Periode: <?= htmlspecialchars($start) ?> — <span id="time"><?= htmlspecialchars($now) ?> (Asia/Jakarta)</span></div>
    <div class="muted">Sumber: <span id="source"><?= htmlspecialchars($source) ?></span></div>
    <div class="row" style="margin-top:14px;">
      <a class="btn" href="penjualan_hari_ini.php">↻ Refresh</a>
      <a class="btn" href="penjualan_hari_ini.php?json=1">API JSON</a>
      <a class="btn" href="penjualan_hari_ini.php?debug=1">🔎 Debug</a>
      <a class="btn" href="dashboard.php">← Kembali</a>
    </div>
  </div>
</body>
</html>
