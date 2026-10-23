<?php
/**
 * Login Page - Windows 11 Style
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Auth.php';

$auth = new Auth();

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$error = '';

// Handle login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'تکایە هەموو خانەکان پڕبکەوە';
    } else {
        $result = $auth->login($username, $password);
        if ($result['success']) {
            // Redirect based on role — an accountant starts on their work
            // queue (the approval center), not on a page of links to it.
            $redirectUrl = ($_SESSION['role'] ?? '') === ROLE_ACCOUNTANT
                ? APP_URL . '/accountant/approval-center.php'
                : APP_URL . '/index.php';
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چوونەژوورەوە - <?= APP_NAME ?></title>

    <!-- PWA -->
    <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
    <meta name="theme-color" content="#0078D4">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Lezan Dental">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/images/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= APP_URL ?>/assets/images/icons/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= APP_URL ?>/assets/images/icons/favicon-16.png">
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('<?= APP_URL ?>/sw.js').catch(function () { /* SW optional */ });
        });
    }
    </script>

    <!-- App Font - NRT (local, single global config in fonts.css) -->
    <link rel="preload" href="<?= APP_URL ?>/fonts/NRT%20Font/NRT-Reg.woff" as="font" type="font/woff" crossorigin>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">

    <!-- Icons -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">

    <style>
        :root {
            /* Windows 11 Colors */
            --win-accent: #0078D4;
            --win-accent-hover: #106EBE;
            --win-accent-light: rgba(0, 120, 212, 0.1);
            --surface-primary: rgba(255, 255, 255, 0.9);
            --surface-card: rgba(255, 255, 255, 0.85);
            --text-primary: #1A1A1A;
            --text-secondary: #5C5C5C;
            --stroke-default: rgba(0, 0, 0, 0.06);
            --stroke-focus: rgba(0, 0, 0, 0.1);
            --status-error: #C42B1C;
            --status-error-bg: rgba(196, 43, 28, 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--app-font-family);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            background-attachment: fixed;
            position: relative;
        }

        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:%230078D4;stop-opacity:0.1"/><stop offset="100%" style="stop-color:%23764ba2;stop-opacity:0.05"/></linearGradient></defs><rect fill="url(%23g)" width="100" height="100"/></svg>');
            pointer-events: none;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            position: relative;
            z-index: 1;
        }

        .login-card {
            background: var(--surface-card);
            -webkit-backdrop-filter: blur(40px) saturate(150%);
            backdrop-filter: blur(40px) saturate(150%);
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .login-header {
            padding: 40px 32px 24px;
            text-align: center;
        }

        .login-logo {
            width: 72px;
            height: 72px;
            background: var(--win-accent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 4px 12px rgba(0, 120, 212, 0.3);
        }

        .login-logo i {
            font-size: 32px;
            color: white;
        }

        .login-title {
            font-size: 22px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .login-subtitle {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .login-body {
            padding: 0 32px 32px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i.input-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 14px;
            pointer-events: none;
        }

        .form-control {
            width: 100%;
            padding: 10px 38px 10px 12px;
            border: 1px solid var(--stroke-default);
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            background: rgba(255, 255, 255, 0.8);
            color: var(--text-primary);
            transition: all 0.15s ease;
        }

        .form-control:hover {
            border-color: var(--stroke-focus);
            background: rgba(255, 255, 255, 0.9);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--win-accent);
            border-bottom-width: 2px;
            background: white;
        }

        .form-control::placeholder {
            color: var(--text-secondary);
        }

        .password-toggle {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: background 0.15s ease;
        }

        .password-toggle:hover {
            background: var(--win-accent-light);
            color: var(--win-accent);
        }

        .remember-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--win-accent);
            cursor: pointer;
        }

        .checkbox-wrapper label {
            font-size: 13px;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .btn-login {
            width: 100%;
            padding: 12px 20px;
            background: var(--win-accent);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-login:hover {
            background: var(--win-accent-hover);
        }

        .btn-login:active {
            transform: scale(0.98);
        }

        .btn-login:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .error-message {
            background: var(--status-error-bg);
            color: var(--status-error);
            padding: 12px 14px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-right: 3px solid var(--status-error);
        }

        .error-message i {
            font-size: 16px;
        }

        .login-footer {
            text-align: center;
            padding: 16px 32px;
            background: rgba(0, 0, 0, 0.02);
            border-top: 1px solid var(--stroke-default);
        }

        .login-footer p {
            color: var(--text-secondary);
            font-size: 12px;
        }

        /* Time Display - Windows 11 Lock Screen Style */
        .time-display {
            text-align: center;
            margin-bottom: 32px;
            color: white;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }

        .time-display .time {
            font-size: 64px;
            font-weight: 300;
            letter-spacing: -2px;
            line-height: 1;
        }

        .time-display .date {
            font-size: 18px;
            margin-top: 8px;
            opacity: 0.9;
        }

        @media (max-width: 767px) {
            body { padding: 12px; }
            /* Prevent iOS from auto-zooming on input focus (needs >=16px) */
            .form-control { font-size: 16px; padding: 12px 40px 12px 14px; }
            .btn-login { font-size: 16px; padding: 13px 20px; min-height: 48px; }
            .password-toggle { padding: 8px; min-width: 36px; min-height: 36px; }
            .login-title { font-size: 20px; }
            .time-display { margin-bottom: 20px; }
        }

        @media (max-width: 480px) {
            body { padding: 10px; }
            .login-header {
                padding: 28px 20px 16px;
            }

            .login-body {
                padding: 0 20px 20px;
            }

            .login-footer {
                padding: 12px 20px;
            }

            .time-display .time {
                font-size: 44px;
            }

            .time-display .date {
                font-size: 13px;
            }

            .login-logo { width: 60px; height: 60px; margin-bottom: 14px; }
            .login-logo i { font-size: 26px; }
        }

        /* Spinner animation */
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .fa-spin {
            animation: spin 1s linear infinite;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Time Display -->
        <div class="time-display">
            <div class="time" id="loginTime"></div>
            <div class="date" id="loginDate"></div>
        </div>

        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <i class="fas fa-tooth"></i>
                </div>
                <h1 class="login-title"><?= APP_NAME ?></h1>
                <p class="login-subtitle">سیستەمی بەڕێوەبردنی کلینیکی ددان</p>
            </div>

            <div class="login-body">
                <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm">
                    <div class="form-group">
                        <label class="form-label" for="username">ناوی بەکارهێنەر</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" class="form-control" id="username" name="username"
                                   placeholder="ناوی بەکارهێنەر"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                   required autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">وشەی نهێنی</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" class="form-control" id="password" name="password"
                                   placeholder="وشەی نهێنی" required>
                            <button type="button" class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye" id="passwordIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="remember-row">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="remember" name="remember">
                            <label for="remember">بمهێڵەوە چوومەژوورەوە</label>
                        </div>
                    </div>

                    <button type="submit" class="btn-login" id="loginBtn">
                        <i class="fas fa-sign-in-alt"></i>
                        چوونەژوورەوە
                    </button>
                </form>
            </div>

            <div class="login-footer">
                <p>&copy; <?= date('Y') ?> <?= APP_NAME ?> - v<?= APP_VERSION ?></p>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const passwordIcon = document.getElementById('passwordIcon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                passwordIcon.classList.remove('fa-eye');
                passwordIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                passwordIcon.classList.remove('fa-eye-slash');
                passwordIcon.classList.add('fa-eye');
            }
        }

        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> چاوەڕوانبە...';
        });

        // Update time display
        function updateLoginTime() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            document.getElementById('loginTime').textContent = `${hours}:${minutes}`;

            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('loginDate').textContent = now.toLocaleDateString('ar-IQ', options);
        }

        updateLoginTime();
        setInterval(updateLoginTime, 1000);
    </script>
</body>
</html>
