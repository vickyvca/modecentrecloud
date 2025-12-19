<?php
require_once 'config.php';
session_start();

// Redirect jika sudah login
if (isset($_SESSION['role']) && $_SESSION['role'] === 'supplier') {
    header("Location: dashboard_supplier.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kodesp = $_POST['kodesp'];
    $pass = $_POST['pass'];

    $stmt = $conn->prepare("SELECT * FROM T_SUPLIER WHERE KODESP = :kodesp AND PASS = :pass");
    $stmt->execute(['kodesp' => $kodesp, 'pass' => $pass]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $_SESSION['kodesp'] = $kodesp;
        $_SESSION['role'] = 'supplier';
        header("Location: dashboard_supplier.php");
        exit;
    } else {
        $error = "Kode Supplier atau Password salah.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Supplier</title>
    <style>
        :root {
            --brand-bg: #d32f2f; --brand-text: #ffffff; --bg: #121218; --card: #1e1e24; --text: #e0e0e0;
            --text-muted: #8b8b9a; --border: #33333d; --border-hover: #4a4a58; --focus-ring: #5a93ff;
            --error-bg: rgba(231, 76, 60, 0.1); --error-border: #88433d; --error-text: #e74c3c;
            --fs-body: clamp(14px, 1.1vw, 16px); --fs-sm: clamp(12.5px, 0.95vw, 14px);
            --fs-h1: clamp(24px, 2.8vw, 30px);
            --space-1: clamp(8px, 0.8vw, 12px); --space-2: clamp(12px, 1.4vw, 18px);
            --space-3: clamp(18px, 2vw, 24px);
            --radius-sm: 8px; --radius-md: 12px; --radius-lg: 16px;
            --transition: all 0.2s cubic-bezier(0.165, 0.84, 0.44, 1);
        }
        
        /* Keyframes untuk animasi masuk */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg); color: var(--text); font-size: var(--fs-body);
            display: flex; align-items: center; justify-content: center; min-height: 100vh;
            padding: var(--space-2);
        }

        /* Wrapper untuk logo dan card */
        .login-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            max-width: 420px;
        }

        /* Styling dan animasi untuk logo */
        .logo {
            max-width: 150px;
            margin-bottom: var(--space-3);
            opacity: 0;
            animation: fadeInUp 0.5s ease-out forwards;
        }

        .card {
            background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-lg);
            padding: var(--space-3); box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            width: 100%;
            text-align: center;
            opacity: 0;
            animation: fadeInUp 0.6s ease-out 0.2s forwards;
        }
        
        h1 { font-size: var(--fs-h1); line-height: 1.2; margin: 0 0 var(--space-3) 0; font-weight: 700; }
        
        form { display: flex; flex-direction: column; gap: var(--space-2); }
        
        input {
            background: var(--bg); border: 1px solid var(--border); color: var(--text); padding: 14px;
            border-radius: var(--radius-sm); font-size: var(--fs-body); transition: var(--transition); outline: none;
        }
        input:hover { border-color: var(--border-hover); }
        input:focus-visible { border-color: var(--focus-ring); box-shadow: 0 0 0 3px rgba(90, 147, 255, 0.3); }

        .btn {
            border: 1px solid var(--border); background: #2a2a32; color: var(--text); padding: 14px 16px;
            border-radius: var(--radius-sm); cursor: pointer; text-decoration: none; display: inline-flex;
            align-items: center; justify-content: center; gap: 8px; font-size: 1.05em; font-weight: 600; transition: var(--transition);
        }
        .btn:hover { border-color: var(--border-hover); background: #33333d; transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .btn-primary { background: var(--brand-bg); border-color: transparent; color: var(--brand-text); }
        .btn-primary:hover { background: #e53935; border-color: transparent; }

        .error-box {
            background: var(--error-bg); border: 1px solid var(--error-border);
            color: var(--error-text); padding: var(--space-2);
            border-radius: var(--radius-sm); margin-bottom: var(--space-2);
            text-align: center;
        }
        
        .back-link {
            display: block; margin-top: var(--space-3); font-size: var(--fs-sm);
            color: var(--text-muted); text-decoration: none;
        }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>

<main class="login-container">
    <img src="assets/img/modecentre.png" alt="Mode Centre" class="logo">

    <div class="card">
        <h1>Login Supplier</h1>

        <?php if (isset($error)): ?>
            <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="text" name="kodesp" placeholder="Kode Supplier" required>
            <input type="password" name="pass" placeholder="Password" required>
            <button type="submit" class="btn btn-primary">LOGIN</button>
        </form>
        
        <a href="index.php" class="back-link">Kembali ke pilihan login</a>
    </div>
</main>

</body>
</html>