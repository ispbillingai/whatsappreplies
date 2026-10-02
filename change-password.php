<?php
require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$error = '';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($newPass) < 6) {
        $error = 'Password must be at least 6 characters';
    } elseif ($newPass !== $confirm) {
        $error = 'Passwords do not match';
    } else {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?')
           ->execute([$hash, $_SESSION['user_id']]);
        $_SESSION['must_change_password'] = false;
        header('Location: dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - <?= APP_NAME ?></title>
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
        .card-box {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .btn-save {
            background: #25D366; border: none; color: white;
            padding: 12px; font-size: 16px; font-weight: 600;
            border-radius: 10px; width: 100%;
        }
        .btn-save:hover { background: #128C7E; color: white; }
        .form-control:focus { border-color: #25D366; box-shadow: 0 0 0 0.2rem rgba(37,211,102,0.15); }
    </style>
</head>
<body>
    <div class="card-box">
        <div class="text-center mb-4">
            <i class="bi bi-shield-lock text-success" style="font-size: 48px;"></i>
            <h4 class="mt-2" style="color: #075E54;">Change Your Password</h4>
            <p class="text-muted small">You must set a new password before continuing.</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small text-center">
            <i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label small text-muted">New Password</label>
                <input type="password" class="form-control" name="new_password" placeholder="Min 6 characters" required minlength="6" autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label small text-muted">Confirm Password</label>
                <input type="password" class="form-control" name="confirm_password" placeholder="Re-enter password" required minlength="6">
            </div>
            <button type="submit" class="btn btn-save">
                <i class="bi bi-check-lg"></i> Set Password & Continue
            </button>
        </form>
    </div>
</body>
</html>
