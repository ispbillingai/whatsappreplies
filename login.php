<?php
require_once __DIR__ . '/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';
$tab = $_GET['tab'] ?? 'login';

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (login($email, $password)) {
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid email or password';
            $tab = 'login';
        }
    }

    if ($_POST['action'] === 'register') {
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $tab = 'register';

        if (empty($email) || empty($password)) {
            $error = 'Email and password are required';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match';
        } else {
            $db = getDB();
            $phone = preg_replace('/[^0-9]/', '', $phone);

            // Check email
            $existing = $db->prepare('SELECT id FROM users WHERE email = ?');
            $existing->execute([$email]);
            if ($existing->fetch()) {
                $error = 'This email address is already registered. Try logging in or use a different email.';
            }

            // Check phone if provided
            if (!$error && $phone) {
                $existingPhone = $db->prepare('SELECT id FROM users WHERE phone = ? AND phone IS NOT NULL AND phone != ""');
                $existingPhone->execute([$phone]);
                if ($existingPhone->fetch()) {
                    $error = 'This phone number is already registered to another account. Try a different number.';
                }
            }

            if (!$error) {
                $name = explode('@', $email)[0]; // use email prefix as name
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $db->prepare('INSERT INTO users (name, email, phone, password, role, is_active) VALUES (?, ?, ?, ?, "user", 1)')
                   ->execute([$name, $email, $phone ?: null, $hash]);

                // Auto-login after registration
                if (login($email, $password)) {
                    header('Location: dashboard.php');
                    exit;
                }
            }
        }
    }

    if ($_POST['action'] === 'forgot') {
        $email = trim($_POST['email'] ?? '');
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_new_password'] ?? '';
        $tab = 'forgot';

        if (empty($email)) {
            $error = 'Please enter your email address';
        } else {
            // Check if email exists FIRST
            $db = getDB();
            $user = $db->prepare('SELECT id FROM users WHERE email = ?');
            $user->execute([$email]);
            if (!$user->fetch()) {
                $error = 'No account found with this email address. Please check your email or create a new account.';
            } elseif (empty($newPass) || strlen($newPass) < 6) {
                $error = 'New password must be at least 6 characters';
            } elseif ($newPass !== $confirmPass) {
                $error = 'Passwords do not match';
            } else {
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $db->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE email = ?')->execute([$newHash, $email]);
                $success = 'Your password has been reset successfully. You can now login with your new password.';
                $tab = 'login';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Login - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #075E54 0%, #128C7E 50%, #25D366 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .login-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .login-card .brand { text-align: center; margin-bottom: 25px; }
        .login-card .brand i { font-size: 48px; color: #25D366; }
        .login-card .brand h2 { color: #075E54; font-weight: 700; margin-top: 10px; }
        .login-card .brand p { color: #999; font-size: 14px; }
        .form-control:focus { border-color: #25D366; box-shadow: 0 0 0 0.2rem rgba(37,211,102,0.15); }
        .btn-login {
            background: #25D366; border: none; color: white;
            padding: 12px; font-size: 16px; font-weight: 600;
            border-radius: 10px; width: 100%;
        }
        .btn-login:hover { background: #128C7E; color: white; }
        .input-group-text { background: #f8f9fa; border-right: none; }
        .form-control { border-left: none; }
        .nav-pills .nav-link { color: #666; font-size: 14px; font-weight: 600; }
        .nav-pills .nav-link.active { background: #075E54; }
        .link-btn { background: none; border: none; color: #128C7E; font-size: 13px; cursor: pointer; padding: 0; }
        .link-btn:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand">
            <img src="logo.png" alt="FreeISP" style="width: 80px; height: 80px;">
            <h2><?= APP_NAME ?></h2>
            <p>WhatsApp Message Relay</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger alert-sm py-2 text-center small">
            <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success alert-sm py-2 text-center small">
            <i class="bi bi-check-circle"></i> <?= $success ?>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <ul class="nav nav-pills nav-justified mb-3" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'login' ? 'active' : '' ?>" data-bs-toggle="pill" href="#loginTab">Sign In</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'register' ? 'active' : '' ?>" data-bs-toggle="pill" href="#registerTab">Create Account</a>
            </li>
        </ul>

        <!-- Login Tab -->
        <div class="tab-content">
            <div class="tab-pane fade <?= $tab === 'login' ? 'show active' : '' ?>" id="loginTab">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" name="email" placeholder="Email address"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" name="password" placeholder="Password" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-login mb-3">
                        <i class="bi bi-box-arrow-in-right"></i> Sign In
                    </button>
                    <div class="text-center">
                        <button type="button" class="link-btn" data-bs-toggle="modal" data-bs-target="#forgotModal">
                            Forgot password?
                        </button>
                    </div>
                </form>
            </div>

            <!-- Register Tab -->
            <div class="tab-pane fade <?= $tab === 'register' ? 'show active' : '' ?>" id="registerTab">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="register">
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" name="email" placeholder="Email address" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                            <input type="tel" class="form-control" name="phone" placeholder="Phone number (e.g. 254712345678)" pattern="[0-9+\s\-]{7,20}">
                        </div>
                        <small class="text-muted ms-1" style="font-size:11px;">For communication purposes only</small>
                    </div>
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" name="password" placeholder="Password (min 6 characters)" required minlength="6">
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" class="form-control" name="confirm_password" placeholder="Confirm password" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-login">
                        <i class="bi bi-person-plus"></i> Create Account
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title"><i class="bi bi-key"></i> Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Enter your email address and choose a new password.</p>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="forgot">
                        <div class="mb-3">
                            <input type="email" class="form-control" name="email" placeholder="Your email address" required>
                        </div>
                        <div class="mb-3">
                            <input type="password" class="form-control" name="new_password" placeholder="New password (min 6 characters)" required minlength="6">
                        </div>
                        <div class="mb-3">
                            <input type="password" class="form-control" name="confirm_new_password" placeholder="Confirm new password" required minlength="6">
                        </div>
                        <button type="submit" class="btn btn-login">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($tab === 'forgot' && $error): ?>
    <script>new bootstrap.Modal(document.getElementById('forgotModal')).show();</script>
    <?php endif; ?>
</body>
</html>
