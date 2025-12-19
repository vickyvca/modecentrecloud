<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['kodesp']) || $_SESSION['role'] !== 'supplier') {
    die("Akses ditolak.");
}

global $conn;
$kodesp = $_SESSION['kodesp'];
$message = '';
$message_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $sql = "UPDATE T_SUPLIER SET WA_1 = :wa1, WA_2 = :wa2, WA_3 = :wa3, WA_4 = :wa4, WA_5 = :wa5 WHERE KODESP = :kodesp";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':wa1' => $_POST['wa_1'] ?? null, ':wa2' => $_POST['wa_2'] ?? null, ':wa3' => $_POST['wa_3'] ?? null,
            ':wa4' => $_POST['wa_4'] ?? null, ':wa5' => $_POST['wa_5'] ?? null, ':kodesp' => $kodesp
        ]);
        $message = '✅ Nomor WhatsApp berhasil diperbarui!';
    } catch (PDOException $e) {
        $message = '❌ Gagal memperbarui data: ' . $e->getMessage();
        $message_type = 'error';
    }
}

$nomor_wa = [];
try {
    $stmt_get = $conn->prepare("SELECT WA_1, WA_2, WA_3, WA_4, WA_5 FROM T_SUPLIER WHERE KODESP = :kodesp");
    $stmt_get->execute([':kodesp' => $kodesp]);
    $nomor_wa = $stmt_get->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* Biarkan kosong */ }

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <meta charset="UTF-8"><title>Pengaturan Notifikasi WhatsApp</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --bg: #121218; --card: #1e1e24; --text: #e0e0e0; --border: #33333d; --green: #58d68d; --red: #e74c3c; --blue: #4fc3f7; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); padding: 20px; }
        .container { max-width: 600px; margin: 20px auto; background: var(--card); padding: 30px; border-radius: 12px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        input[type="text"] { width: 100%; padding: 12px; border: 1px solid var(--border); background: #2a2a32; color: var(--text); border-radius: 8px; }
        .btn-submit { background: var(--blue); color: white; padding: 12px 20px; border: none; border-radius: 8px; cursor: pointer; width: 100%; font-size: 16px; }
        .message { padding: 12px; margin-bottom: 20px; border-radius: 8px; font-weight: 600; }
        .message.success { background: rgba(88, 214, 141, 0.2); color: var(--green); }
        .message.error { background: rgba(231, 76, 60, 0.2); color: var(--red); }
    </style>
</head>
<body>
<div class="container">
  <div class="store-header">
    <img src="assets/img/modecentre.png" alt="Mode Centre" class="logo">
    <div class="info">
      <div class="addr">Jl Pemuda Komp Ruko Pemuda 13 - 21 Banjarnegara Jawa Tengah</div>
      <div class="contact">Contact: 0813-9983-9777</div>
    </div>
  </div>
  <div class="grid grid-auto justify-end mb-2">
    <a href="dashboard_supplier.php" class="btn btn-secondary">Kembali ke Dashboard</a>
  </div>
</div>
    <div class="container">
        <h2><i class="fa-brands fa-whatsapp"></i> Pengaturan Notifikasi WA</h2>
        <a href="dashboard_supplier.php" style="color:var(--blue); margin-bottom:20px; display:inline-block;">&larr; Kembali ke Dashboard</a>
        <?php if ($message): ?><div class="message <?= $message_type ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <p style="color:#8b8b9a; font-size:14px;">Masukkan nomor WA dengan format internasional (contoh: 628123456789). Kosongkan jika tidak digunakan.</p>
        <form method="POST">
            <?php for ($i = 1; $i <= 5; $i++): ?>
            <div class="form-group">
                <label for="wa_<?= $i ?>">Nomor Penerima <?= $i ?></label>
                <input type="text" id="wa_<?= $i ?>" name="wa_<?= $i ?>" placeholder="Contoh: 628123456789" value="<?= htmlspecialchars($nomor_wa['WA_'.$i] ?? '') ?>">
            </div>
            <?php endfor; ?>
            <button type="submit" class="btn-submit">Simpan Pengaturan</button>
        </form>
    </div>
</body>
</html>
