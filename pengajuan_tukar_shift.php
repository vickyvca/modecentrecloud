<?php
// FILE: pengajuan_tukar_shift.php
session_start();
require_once 'config.php';
require_once __DIR__ . '/payroll/payroll_lib.php';

if (!isset($_SESSION['nik']) || ($_SESSION['role'] ?? '') !== 'pegawai') {
    die('Akses ditolak.');
}

$nik = $_SESSION['nik'];
$msg = null; $type = 'info';

$data_file = __DIR__ . '/payroll/data/shift_requests.json';
if (!is_dir(dirname($data_file))) { @mkdir(dirname($data_file), 0777, true); }
if (!is_file($data_file)) { file_put_contents($data_file, json_encode([], JSON_PRETTY_PRINT)); }

function load_shift_requests($file){ $js = json_decode(@file_get_contents($file), true); return is_array($js)?$js:[]; }
function save_shift_requests($file, $rows){ file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'] ?? '';
    $partner = $_POST['partner'] ?? '';
    if (!$date || !$partner) {
        $msg = 'Tanggal dan partner wajib diisi.'; $type='error';
    } elseif ($partner === $nik) {
        $msg = 'Partner tidak boleh diri sendiri.'; $type='error';
    } else {
        $rows = load_shift_requests($data_file);
        $rows[] = [
            'id' => uniqid('SR'),
            'requester' => $nik,
            'partner' => $partner,
            'date' => $date,
            'status' => 'PENDING',
            'created_at' => date('c')
        ];
        save_shift_requests($data_file, $rows);
        $msg = 'Pengajuan tukar shift dikirim. Menunggu persetujuan admin.'; $type='success';

        // Kirim WA ke admin (jika ada) dengan template
        $admins = get_admin_wa_list();
        if (!empty($admins)) {
            $nama = $nik; $namap = $partner;
            try { $q=$conn->prepare("SELECT KODESL,NAMASL FROM T_SALES WHERE KODESL IN (?,?)"); $q->execute([$nik,$partner]); while($r=$q->fetch(PDO::FETCH_ASSOC)){ if($r['KODESL']===$nik) $nama=$r['NAMASL']; if($r['KODESL']===$partner) $namap=$r['NAMASL']; } } catch(Exception $e){}
            $pesan = wa_tpl_shift_swap_submitted_admin($date, $nama, $nik, $namap, $partner);
            foreach ($admins as $wa) { kirimWATeksFonnte($wa, $pesan); }
        }
    }
}

$selected = get_selected_employees();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Pengajuan Tukar Shift</title>
  <link rel="stylesheet" href="assets/css/ui.css">
  <style>
    body{background:#121218;color:#e0e0e0;font-family:sans-serif}
    .container{max-width:720px;margin:24px auto;background:#1e1e24;border:1px solid #333;padding:20px;border-radius:10px}
    .input{width:100%;padding:10px;background:#2a2a32;color:#e0e0e0;border:1px solid #333;border-radius:6px}
    .btn{padding:10px 14px;background:#d32f2f;color:#fff;border:0;border-radius:6px;cursor:pointer}
    .msg{padding:10px;border-radius:6px;margin-bottom:10px}
    .msg.error{background:rgba(231,76,60,.15);color:#e74c3c}
    .msg.success{background:rgba(88,214,141,.15);color:#58d68d}
  </style>
</head>
<body>
  <div class="container">
    <h2>Ajukan Tukar Shift</h2>
    <?php if($msg): ?><div class="msg <?= $type ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <form method="post">
      <div style="margin-bottom:10px"><label>Tanggal</label><input class="input" type="date" name="date" value="<?= date('Y-m-d') ?>" required></div>
      <div style="margin-bottom:10px"><label>Partner</label>
        <select class="input" name="partner" required>
          <option value="">-- Pilih Partner --</option>
          <?php foreach($selected as $emp): if(($emp['nik']??'')===$nik) continue; ?>
            <option value="<?= htmlspecialchars($emp['nik']) ?>"><?= htmlspecialchars(($emp['nama']??$emp['nik']).' ('.$emp['nik'].')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn" type="submit">Kirim Pengajuan</button>
      <a class="btn" style="background:#555;margin-left:8px" href="dashboard_pegawai.php">Kembali</a>
    </form>
  </div>
</body>
</html>
