<?php
session_start();
require_once 'db.php';

$error = "";

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM hotel_users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];

        // Record Login History
        $log_stmt = $pdo->prepare("INSERT INTO hotel_login_history (user_id, login_time) VALUES (?, ?)");
        $log_stmt->execute([$user['id'], date('Y-m-d H:i:s')]);
        $_SESSION['login_history_id'] = $pdo->lastInsertId();

        header("Location: index.php");
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($site_settings['hotel_name'] ?? 'Pinobretel Suites') ?> Management System</title>
    <link href='css/vendor/boxicons.min.css' rel='stylesheet'>
    <link href="css/fonts.css" rel="stylesheet">
    <style>
        :root {
            --primary: <?= $site_settings['primary_color'] ?? '#4f46e5' ?>;
            --secondary: <?= $site_settings['secondary_color'] ?? '#6366f1' ?>;
            --bg: #f8fafc;
            --text: #1e293b;
            --card-bg: rgba(255, 255, 255, 0.9);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
            overflow: hidden;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 40px;
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header i {
            font-size: 50px;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .login-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 5px;
        }

        .login-header p {
            color: #64748b;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #475569;
            font-size: 14px;
        }

        .form-group i {
            position: absolute;
            left: 15px;
            top: 40px;
            color: #94a3b8;
            font-size: 20px;
        }

        .form-control {
            width: 100%;
            padding: 12px 12px 12px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s;
            outline: none;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }

        .btn-login:hover {
            background: var(--secondary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }

        .error-message {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
            border: 1px solid #fecaca;
        }

        .footer-text {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>
<body>

    <div class="login-container">
        <div class="login-header">
            <i class='bx bxs-hotel'></i>
            <h1><?= htmlspecialchars($site_settings['hotel_name'] ?? 'Pinobretel Suites') ?></h1>
            <p>Management System Portal</p>
        </div>

        <?php if($error): ?>
            <div class="error-message"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label>Username</label>
                <i class='bx bx-user'></i>
                <input type="text" name="username" class="form-control" placeholder="Enter username" required autofocus>
            </div>

            <div class="form-group" style="margin-bottom: 30px;">
                <label>Password</label>
                <i class='bx bx-lock-alt'></i>
                <input type="password" name="password" class="form-control" placeholder="Enter password" required>
            </div>

            <button type="submit" class="btn-login">Log In to Dashboard</button>
        </form>

        <div class="footer-text">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($site_settings['hotel_name'] ?? 'Pinobretel Suites') ?> Management System. Secure Access only.
        </div>
    </div>

</body>
</html>
