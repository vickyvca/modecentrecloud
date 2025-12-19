<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payroll/payroll_lib.php';

$msg=null;$type='info';

if ($_SERVER['REQUEST_METHOD']==='POST'){
    $nik = trim($_POST['nik'] ?? '');
    $note = trim($_POST['note'] ?? '');
    if ($nik==='') { $msg='Masukkan NIK Anda.'; $type='error'; }
    else {
        // Ambil nama
        $nama = $nik;
        try { global $conn; $st=$conn->prepare("SELECT NAMASL FROM T_SALES WHERE KODESL=:nik"); $st->execute([':nik'=>$nik]); $n=$st->fetchColumn(); if($n) $nama=$n; } catch(Exception $e){}
        // Kirim ke admin WA
        $admins = get_admin_wa_list();
        if (!empty($admins)) {
            $text = wa_tpl_reset_password_request_admin($nik, $nama, $note);
            foreach($admins as $wa){ kirimWATeksFonnte($wa, $text); }
            $msg='Permintaan reset telah dikirim ke admin via WA.'; $type='success';
        } else {
            $msg='Tidak ada nomor admin terdaftar. Hubungi admin secara manual.'; $type='error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Permintaan Reset Password</title>
  <link rel="stylesheet" href="assets/css/ui.css">
  <style>
    body{background:#121218;color:#e0e0e0;font-family:sans-serif}
    .box{max-width:480px;margin:40px auto;background:#1e1e24;border:1px solid #333;padding:20px;border-radius:10px}
    .input{width:100%;padding:12px;background:#2a2a32;color:#e0e0e0;border:1px solid #333;border-radius:8px;margin-bottom:10px}
    .btn{padding:12px;background:#d32f2f;color:#fff;border:0;border-radius:8px;cursor:pointer;width:100%}
    .msg{padding:10px;border-radius:8px;margin-bottom:10px}
    .msg.error{background:rgba(231,76,60,.15);color:#e74c3c}
    .msg.success{background:rgba(88,214,141,.15);color:#58d68d}
  </style>
</head>
<body>
  <div class="box">
    <h2 style="margin-top:0">Permintaan Reset Password</h2>
    <?php if($msg): ?><div class="msg <?= $type ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <form method="post">
      <input class="input" type="text" name="nik" placeholder="NIK" required>
      <textarea class="input" name="note" rows="3" placeholder="Catatan (opsional)"></textarea>
      <button class="btn" type="submit">Kirim Permintaan</button>
      <a class="btn" style="background:#555;margin-top:8px;display:inline-block;text-align:center" href="login_pegawai.php">Kembali ke Login</a>
    </form>
  </div>
</body>
</html>
