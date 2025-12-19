<?php
// FILE: payroll/admin_manage_shift.php
session_start();
require_once __DIR__ . '/payroll_lib.php';
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('Akses ditolak. Khusus admin.');
}

$data_file = __DIR__ . '/data/shift_requests.json';
if (!is_dir(dirname($data_file))) { @mkdir(dirname($data_file), 0777, true); }
if (!is_file($data_file)) { file_put_contents($data_file, json_encode([], JSON_PRETTY_PRINT)); }

function load_shift_requests($file){ $js = json_decode(@file_get_contents($file), true); return is_array($js)?$js:[]; }
function save_shift_requests($file, $rows){ file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }

global $conn;
$action = $_GET['action'] ?? null;
$id = $_GET['id'] ?? null;
$msg = null; $type = 'info';

if ($action && $id) {
    $rows = load_shift_requests($data_file);
    foreach ($rows as &$r) {
        if (($r['id'] ?? '') === $id) {
            if ($action === 'approve' && ($r['status'] ?? '') === 'PENDING') {
                // Swap shifts for both employees on given date
                $date = $r['date']; $nikA = $r['requester']; $nikB = $r['partner'];
                try {
                    // Fetch current shift codes
                    $stmt = $conn->prepare("SELECT KODESL, SHIFT_JADWAL FROM T_ABSENSI WHERE TGL = :tgl AND KODESL IN (:a, :b)");
                    // SQL Server doesn't allow binding list like this; fetch individually
                } catch (Exception $e) {}
                // Fetch individually
                $getShift = function($nik) use ($conn, $date){
                    try { $q=$conn->prepare("SELECT SHIFT_JADWAL FROM T_ABSENSI WHERE KODESL=:nik AND TGL=:tgl"); $q->execute([':nik'=>$nik, ':tgl'=>$date]); return $q->fetchColumn(); } catch(Exception $e){ return null; }
                };
                $setShift = function($nik, $shift) use ($conn, $date){
                    try {
                        $sql = "IF EXISTS (SELECT 1 FROM T_ABSENSI WHERE KODESL = :nik AND TGL = :tgl)
                                  UPDATE T_ABSENSI SET SHIFT_JADWAL = :s, OVERTIME_BONUS_FLAG = 0 WHERE KODESL = :nik AND TGL = :tgl
                                ELSE
                                  INSERT INTO T_ABSENSI (KODESL, TGL, STATUS_HARI, SHIFT_JADWAL) VALUES (:nik, :tgl, 'HADIR', :s)";
                        $st=$conn->prepare($sql); $st->execute([':nik'=>$nik, ':tgl'=>$date, ':s'=>$shift?:'S1']);
                    } catch (Exception $e) {}
                };
                $sA = $getShift($nikA); $sB = $getShift($nikB);
                if (!$sA && !$sB) { $sA='S1'; $sB='S2'; }
                $setShift($nikA, $sB ?: 'S2');
                $setShift($nikB, $sA ?: 'S1');
                $r['status'] = 'APPROVED'; $r['approved_at'] = date('c');
                $msg = 'Tukar shift disetujui dan diterapkan.'; $type='success';
            } elseif ($action === 'reject' && ($r['status'] ?? '') === 'PENDING') {
                $r['status'] = 'REJECTED'; $r['rejected_at'] = date('c');
                $msg = 'Pengajuan ditolak.'; $type='success';
            } elseif ($action === 'delete') {
                $r['__delete__'] = true; $msg='Pengajuan dihapus.'; $type='success';
            }
            break;
        }
    }
    unset($r);
    $rows = array_values(array_filter($rows, fn($x)=>empty($x['__delete__'])));
    save_shift_requests($data_file, $rows);
}

$rows = load_shift_requests($data_file);

// Build map NIK -> Nama for display
$niks = [];
foreach ($rows as $r) {
    if (!empty($r['requester'])) $niks[$r['requester']] = true;
    if (!empty($r['partner'])) $niks[$r['partner']] = true;
}
$nik_list = array_keys($niks);
$name_map = [];
if (!empty($nik_list)) {
    try {
        $placeholders = implode(',', array_fill(0, count($nik_list), '?'));
        $q = $conn->prepare("SELECT KODESL, NAMASL FROM T_SALES WHERE KODESL IN ($placeholders)");
        $q->execute($nik_list);
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $name_map[$row['KODESL']] = trim($row['NAMASL']);
        }
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Admin - Pengajuan Tukar Shift</title>
  <link rel="stylesheet" href="../assets/css/ui.css">
  <style> body{background:#121218;color:#e0e0e0;font-family:sans-serif} .container{max-width:1000px;margin:20px auto} .card{background:#1e1e24;border:1px solid #333;border-radius:10px;padding:16px} table{width:100%;border-collapse:collapse} th,td{border-bottom:1px solid #333;padding:8px;white-space:nowrap} th{background:#2a2a32} .btn{padding:6px 10px;border-radius:6px;border:0;cursor:pointer} .btn.success{background:#58d68d;color:#121218} .btn.warn{background:#f39c12;color:#121218} .btn.danger{background:#e74c3c;color:#fff} .bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}</style>
</head>
<body>
  <div class="container">
    <div class="card">
      <div class="bar">
        <h2 style="margin:0">Pengajuan Tukar Shift</h2>
        <a class="btn warn" href="../dashboard_admin.php">Kembali</a>
      </div>
      <?php if ($msg): ?><div class="notif-bar <?= $type==='success'?'success':'warn' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>ID</th><th>Tanggal</th><th>Pemohon</th><th>Partner</th><th>Status</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php if(empty($rows)): ?><tr><td colspan="6" style="text-align:center">Tidak ada pengajuan.</td></tr><?php endif; ?>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['id'] ?? '-') ?></td>
                <td><?= htmlspecialchars($r['date'] ?? '-') ?></td>
                <td><?= htmlspecialchars($name_map[$r['requester']] ?? $r['requester'] ?? '-') ?></td>
                <td><?= htmlspecialchars($name_map[$r['partner']] ?? $r['partner'] ?? '-') ?></td>
                <td><?= htmlspecialchars($r['status'] ?? '-') ?></td>
                <td>
                  <?php if(($r['status'] ?? '') === 'PENDING'): ?>
                    <a class="btn success" href="?action=approve&id=<?= urlencode($r['id']) ?>">Setujui</a>
                    <a class="btn warn" href="?action=reject&id=<?= urlencode($r['id']) ?>">Tolak</a>
                  <?php endif; ?>
                  <a class="btn danger" href="?action=delete&id=<?= urlencode($r['id']) ?>" onclick="return confirm('Hapus pengajuan ini?')">Hapus</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
