<?php
session_start();
// Redirect ke dashboard sesuai role jika sudah login
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: dashboard_admin.php');
        exit;
    } elseif ($_SESSION['role'] === 'pegawai') {
        header('Location: dashboard_pegawai.php');
        exit;
    } elseif ($_SESSION['role'] === 'supplier') {
        header('Location: dashboard_supplier.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selamat Datang di Mode Centre</title>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <style> body { display:flex; align-items:center; justify-content:center; min-height:100vh; } </style>
    </head>
<body>
<div class="card fade-in max-w-md w-full text-center shadow-xl border border-[color:var(--border)] bg-[color:var(--card)] rounded-2xl p-6">
    <div class="store-header">
        <img src="assets/img/modecentre.png" alt="Mode Centre" class="logo">
    </div>
    <h1 class="text-2xl font-bold text-[color:var(--text)] mb-4">Selamat Datang</h1>
    <div class="stagger space-y-3">
        <a href="login_pegawai.php" class="btn btn-primary btn-block w-full py-3 rounded-md text-white bg-[color:var(--brand-bg)] hover:brightness-110 transition">Login Pegawai / Admin</a>
        <a href="login_supplier.php" class="btn btn-secondary btn-block w-full py-3 rounded-md bg-[#2a2a32] hover:bg-[#3a3a42] text-[color:var(--text)] border border-[color:var(--border)] transition">Login Supplier</a>
    </div>
</div>
</body>
</html>
