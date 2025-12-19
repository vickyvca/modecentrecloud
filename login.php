<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nik = $_POST['nik'];
    $pass = $_POST['pass'];

    $stmt = $conn->prepare("SELECT * FROM [LOGIN ANDROID] WHERE NIK = ? AND PASS = ?");
    $stmt->execute([$nik, $pass]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $_SESSION['nik'] = $user['NIK'];
        $_SESSION['is_admin'] = ($user['NIK'] === '225');
        header('Location: index.php');
        exit;
    } else {
        $error = "Login gagal. NIK atau password salah.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Mode Centre</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <style> body { display:flex; align-items:center; justify-content:center; min-height:100vh; } </style>
    </head>
<body>
    <div class="card fade-in" style="width:100%;max-width:420px;">
    <div class="store-header">
        <img src="assets/img/modecentre.png" alt="Mode Centre" class="logo">
    </div>
    <h1>Login</h1>
        <?php if (!empty($error)): ?>
            <div class="error-box mb-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" class="form">
            <div>
                <label for="nik" class="muted">NIK</label>
                <input id="nik" type="text" name="nik" required>
            </div>
            <div>
                <label for="pass" class="muted">Password</label>
                <input id="pass" type="password" name="pass" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>
    </div>
</body>
</html>
