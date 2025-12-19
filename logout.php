<?php
// 1. Mulai session untuk mengakses data sesi yang aktif
session_start();

// 2. Hapus semua variabel di dalam session
$_SESSION = array();

// 3. Hapus cookie session dari browser
// Ini penting untuk memastikan sesi benar-benar berakhir
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Hancurkan session secara permanen
session_destroy();

// 5. Alihkan pengguna kembali ke halaman utama (login chooser)
header("Location: index.php");
exit;
?>