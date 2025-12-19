<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['nik']) || ($_SESSION['role'] ?? '') !== 'pegawai') {
    die('Akses ditolak.');
}

$nik = $_SESSION['nik'];
$message = null; $type = 'info';
require_once __DIR__ . '/payroll/payroll_lib.php';
$existing_wa = get_pegawai_wa($nik);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST['old'] ?? '';
    $new = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $wa = preg_replace('/[^0-9]/','', $_POST['wa'] ?? '');

    if ($new !== $confirm) {
        $message = 'Konfirmasi password tidak sama.'; $type='error';
    } elseif (strlen($new) < 6) {
        $message = 'Password baru minimal 6 karakter.'; $type='error';
    } elseif ($new === $nik) {
        $message = 'Password tidak boleh sama dengan NIK.'; $type='error';
    } elseif (in_array(strtolower($new), ['123','1234','12345','123456','password'], true)) {
        $message = 'Password terlalu lemah. Gunakan kombinasi lain.'; $type='error';
    } else {
        try {
            global $conn;
            $stmt = $conn->prepare("SELECT COUNT(*) FROM [LOGIN ANDROID] WHERE NIK = :nik AND PASS = :pass");
            $stmt->execute([':nik' => $nik, ':pass' => $old]);
            $ok = (int)$stmt->fetchColumn() > 0;
            if (!$ok) {
                $message = 'Password saat ini salah.'; $type='error';
            } else {
                $upd = $conn->prepare("UPDATE [LOGIN ANDROID] SET PASS = :new WHERE NIK = :nik");
                $upd->execute([':new' => $new, ':nik' => $nik]);
                unset($_SESSION['must_change_password']);
                if (!$existing_wa && $wa) { set_pegawai_wa($nik, $wa); }
                $message = 'Password berhasil diubah.'; $type='success';
                header('Location: dashboard_pegawai.php');
                exit;
            }
        } catch (PDOException $e) {
            $message = 'DB Error: ' . htmlspecialchars($e->getMessage()); $type='error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ganti Password</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <style>
        body { background:#121218; color:#e0e0e0; font-family: system-ui, sans-serif; }
        .container { max-width: 480px; margin: 40px auto; background:#1e1e24; border:1px solid #33333d; border-radius:12px; padding:24px; }
        .title { margin:0 0 16px 0; font-size:20px; }
        .muted { color:#8b8b9a; }
        .input { width:100%; padding:12px; border:1px solid #33333d; background:#2a2a32; color:#e0e0e0; border-radius:8px; margin-bottom:12px; }
        .btn { width:100%; padding:12px; background:#d32f2f; color:white; border:0; border-radius:8px; cursor:pointer; font-weight:700; }
        .msg { padding:10px; border-radius:8px; margin-bottom:12px; }
        .msg.error { background: rgba(231, 76, 60, .15); color:#e74c3c; }
        .msg.success { background: rgba(88,214,141,.15); color:#58d68d; }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function(){
            const must = <?= isset($_SESSION['must_change_password']) ? 'true' : 'false' ?>;
            if(must){ alert('Anda menggunakan password default. Harap ganti password terlebih dahulu.'); }
        });
    </script>
    </head>
<body>
  <div class="container">
    <h1 class="title">Ganti Password</h1>
    <div class="muted" style="margin-bottom:8px;">NIK: <?= htmlspecialchars($nik) ?></div>
    <?php if ($message): ?><div class="msg <?= $type ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <form method="post">
        <input class="input" type="password" name="old" placeholder="Password Saat Ini" required>
        <input class="input" type="password" name="new" placeholder="Password Baru" required>
        <input class="input" type="password" name="confirm" placeholder="Ulangi Password Baru" required>
        <?php if (!$existing_wa): ?>
        <input class="input" type="text" name="wa" placeholder="Nomor WhatsApp (contoh: 628123456789)" required>
        <div class="muted" style="margin:-6px 0 8px 0; font-size:12px;">Masukkan nomor WA agar menerima notifikasi dan memudahkan reset password.</div>
        <?php endif; ?>
        <button class="btn" type="submit">Simpan Password</button>
    </form>
  </div>
</body>
</html>
