<?php
require_once 'config.php';
require_once 'notifikasi_helper.php';
session_start();

if (!isset($_SESSION['nik']) || $_SESSION['role'] !== 'admin') {
    die("Akses ditolak.");
}

// Mengambil daftar pegawai untuk dropdown (sudah diperbaiki)
$pegawai_stmt = $conn->query("SELECT NIK FROM [LOGIN ANDROID] WHERE NIK <> '" . $_SESSION['nik'] . "'");
$pegawai_list = $pegawai_stmt->fetchAll(PDO::FETCH_ASSOC);

$msg = '';
$msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_nik = $_POST['target_nik'];
    $pesan = trim($_POST['pesan']);

    if (!empty($pesan) && !empty($target_nik)) {
        if ($target_nik === 'semua') {
            $berhasil = 0;
            foreach ($pegawai_list as $pegawai) {
                if(buatNotifikasi($conn, $pegawai['NIK'], 'pegawai', $pesan)) {
                    $berhasil++;
                }
            }
            $msg = "Notifikasi berhasil dikirim ke " . $berhasil . " pegawai.";
            $msg_type = "success";
        } else {
            if (buatNotifikasi($conn, $target_nik, 'pegawai', $pesan)) {
                $msg = "Notifikasi berhasil dikirim ke NIK " . htmlspecialchars($target_nik);
                $msg_type = "success";
            } else {
                $msg = "Gagal mengirim notifikasi.";
                $msg_type = "error";
            }
        }
    } else {
        $msg = "Form tidak boleh kosong.";
        $msg_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kirim Notifikasi Pegawai</title>
    <style>
        :root {
            --primary-bg: #121212;
            --secondary-bg: #1E1E1E;
            --accent-color: #e53935;
            --primary-text: #F5F5F5;
            --secondary-text: #BDBDBD;
            --border-color: #424242;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--primary-bg); color: var(--primary-text); padding: 20px; margin: 0; }
        .container { background: var(--secondary-bg); padding: 30px; max-width: 600px; margin: 40px auto; border-radius: 12px; border: 1px solid var(--border-color); }
        h2 { color: var(--accent-color); margin-top: 0; }
        a.back { background: #424242; color: white; padding: 9px 15px; text-decoration: none; display: inline-block; margin-bottom: 20px; border-radius: 8px; }
        label { display: block; margin-bottom: 8px; color: var(--secondary-text); font-size: 14px; }
        select, textarea, button { width: 100%; padding: 12px; margin-bottom: 20px; font-size: 16px; border-radius: 8px; box-sizing: border-box; }
        select, textarea { background-color: #333; border: 1px solid var(--border-color); color: var(--primary-text); }
        button { background: var(--accent-color); color: white; border: none; cursor: pointer; font-weight: bold; }
        .msg { padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .msg.success { background-color: #1e4620; color: #a5d6a7; }
        .msg.error { background-color: #5c1f1f; color: #ef9a9a; }
    </style>
</head>
<body>
<div class="container">
    <a href="dashboard_admin.php" class="back">🔙 Kembali ke Dashboard</a>
    <h2>✉️ Kirim Notifikasi Pegawai</h2>
    <?php if ($msg) echo "<div class='msg $msg_type'>$msg</div>"; ?>
    <form method="post">
        <label for="target_nik">Kirim Ke:</label>
        <select name="target_nik" id="target_nik" required>
            <option value="semua">Semua Pegawai</option>
            <?php foreach ($pegawai_list as $pegawai): ?>
                <option value="<?= htmlspecialchars($pegawai['NIK']) ?>"><?= htmlspecialchars($pegawai['NIK']) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="pesan">Isi Pesan:</label>
        <textarea name="pesan" id="pesan" rows="5" required placeholder="Ketik pesan notifikasi di sini..."></textarea>
        <button type="submit">Kirim Notifikasi</button>
    </form>
</div>
</body>
</html>