<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
requireLogin();

$db = getDB();
$user = getCurrentUser();
$userTimezone = $user['timezone'] ?? 'Africa/Nairobi';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $timezone = trim($_POST['timezone'] ?? 'Africa/Nairobi');

        // Validate timezone
        if (!in_array($timezone, timezone_identifiers_list())) {
            $timezone = 'Africa/Nairobi';
        }

        if ($name && $email) {
            $check = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $check->execute([$email, $user['id']]);
            if ($check->fetch()) {
                flash('Email already in use by another account', 'error');
            } else {
                $db->prepare('UPDATE users SET name = ?, email = ?, timezone = ? WHERE id = ?')
                   ->execute([$name, $email, $timezone, $user['id']]);
                $_SESSION['user_name'] = $name;
                flash('Profile updated successfully');
            }
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        // Verify current password
        $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $userData = $stmt->fetch();

        if (!password_verify($current, $userData['password'])) {
            flash('Current password is incorrect', 'error');
        } elseif (strlen($new) < 6) {
            flash('New password must be at least 6 characters', 'error');
        } elseif ($new !== $confirm) {
            flash('New passwords do not match', 'error');
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $user['id']]);
            flash('Password changed successfully');
        }
    }

    if ($action === 'update_webhook') {
        $url = trim($_POST['webhook_url'] ?? '');
        $secret = trim($_POST['webhook_secret'] ?? '');
        $enabled = isset($_POST['webhook_enabled']) ? 1 : 0;
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            flash('Webhook URL is not a valid URL', 'error');
        } elseif ($enabled && $url === '') {
            flash('Enter the webhook URL before switching it on', 'error');
        } else {
            // An empty secret field keeps the one already saved (so it never has to be re-typed).
            if ($secret === '') {
                $db->prepare('UPDATE users SET webhook_url = ?, webhook_enabled = ? WHERE id = ?')
                   ->execute([$url !== '' ? $url : null, $enabled, $user['id']]);
            } else {
                $db->prepare('UPDATE users SET webhook_url = ?, webhook_secret = ?, webhook_enabled = ? WHERE id = ?')
                   ->execute([$url !== '' ? $url : null, $secret, $enabled, $user['id']]);
            }
            flash('Webhook settings saved');
        }
    }

    if ($action === 'test_webhook') {
        require_once __DIR__ . '/webhook_lib.php';
        $res = webhookTest((int)$user['id']);
        flash($res['ok'] ? 'Webhook answered HTTP ' . $res['http'] . ' - connected.' : 'Webhook failed: HTTP ' . $res['http'] . ' ' . $res['body'], $res['ok'] ? 'success' : 'error');
    }

    header('Location: settings.php');
    exit;
}

renderHeader('Settings', 'settings');
// Re-read so the webhook card shows what was just saved.
$stmt = $db->prepare('SELECT webhook_url, webhook_secret, webhook_enabled FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$wh = $stmt->fetch() ?: ['webhook_url' => '', 'webhook_secret' => '', 'webhook_enabled' => 0];
?>

<div class="row g-4">
    <!-- Webhook: where incoming WhatsApp messages and delivery reports go -->
    <div class="col-12">
        <div class="card">
            <div class="card-header py-3">
                <i class="bi bi-arrow-left-right"></i> Incoming Messages Webhook
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    When a phone running the app sees a new WhatsApp message, it is stored under
                    <a href="inbox.php">Inbox</a> and pushed to this URL as JSON. Replies sent back through the
                    API with <code>"line"</code> or <code>"reply": true</code> leave from the same phone and app
                    the message arrived on, and their delivery report is pushed to the same URL.
                </p>
                <form method="POST" class="row g-3 align-items-end">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="update_webhook">
                    <div class="col-lg-6">
                        <label class="form-label">Webhook URL</label>
                        <input type="url" name="webhook_url" class="form-control" placeholder="https://support.ispledger.com/wa_inbound.php"
                               value="<?= htmlspecialchars($wh['webhook_url'] ?? '') ?>">
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label">Shared secret <small class="text-muted">(sent as X-Bridge-Secret)</small></label>
                        <input type="password" name="webhook_secret" class="form-control" autocomplete="new-password"
                               placeholder="<?= !empty($wh['webhook_secret']) ? 'Saved - leave blank to keep' : 'Paste the secret the receiver expects' ?>">
                    </div>
                    <div class="col-lg-2">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="webhook_enabled" id="webhookEnabled" <?= !empty($wh['webhook_enabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="webhookEnabled">Enabled</label>
                        </div>
                        <button type="submit" class="btn btn-wa w-100">Save</button>
                    </div>
                </form>
                <form method="POST" class="mt-3 d-flex gap-2 align-items-center">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="test_webhook">
                    <button type="submit" class="btn btn-outline-secondary btn-sm" <?= empty($wh['webhook_enabled']) ? 'disabled' : '' ?>>
                        <i class="bi bi-broadcast"></i> Send test event
                    </button>
                    <small class="text-muted">Posts <code>{"event":"ping"}</code> to the URL with the secret and shows the HTTP answer.</small>
                </form>
            </div>
        </div>
    </div>

    <!-- Profile -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-3">
                <i class="bi bi-person"></i> Profile
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Timezone</label>
                        <select name="timezone" class="form-select">
                            <?php
                            $regions = [
                                'Africa' => DateTimeZone::AFRICA,
                                'Asia' => DateTimeZone::ASIA,
                                'Europe' => DateTimeZone::EUROPE,
                                'America' => DateTimeZone::AMERICA,
                                'Pacific' => DateTimeZone::PACIFIC,
                                'Indian Ocean' => DateTimeZone::INDIAN,
                                'Atlantic' => DateTimeZone::ATLANTIC,
                            ];
                            foreach ($regions as $label => $region):
                                $tzList = DateTimeZone::listIdentifiers($region);
                            ?>
                                <optgroup label="<?= $label ?>">
                                <?php foreach ($tzList as $tz):
                                    $now = new DateTime('now', new DateTimeZone($tz));
                                    $offset = $now->format('P');
                                    $display = str_replace('_', ' ', str_replace('/', ' / ', $tz));
                                ?>
                                    <option value="<?= $tz ?>" <?= $userTimezone === $tz ? 'selected' : '' ?>>
                                        (UTC<?= $offset ?>) <?= $display ?>
                                    </option>
                                <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Times on the dashboard will display in your timezone</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control" value="<?= ucfirst($user['role']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Member Since</label>
                        <input type="text" class="form-control" value="<?= date('F j, Y', strtotime($user['created_at'])) ?>" disabled>
                    </div>
                    <button type="submit" class="btn btn-wa">Update Profile</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-3">
                <i class="bi bi-shield-lock"></i> Change Password
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-warning">Change Password</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Account Info -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-3">
                <i class="bi bi-info-circle"></i> Account Overview
            </div>
            <div class="card-body">
                <?php
                $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM api_keys WHERE user_id = ? AND is_active = 1');
                $stmt->execute([$user['id']]);
                $keyCount = $stmt->fetch()['cnt'];

                $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM messages WHERE user_id = ?');
                $stmt->execute([$user['id']]);
                $msgCount = $stmt->fetch()['cnt'];

                $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM messages WHERE user_id = ? AND status = 'delivered'");
                $stmt->execute([$user['id']]);
                $deliveredCount = $stmt->fetch()['cnt'];
                ?>
                <div class="row text-center">
                    <div class="col-4">
                        <h3 class="mb-0"><?= $keyCount ?></h3>
                        <small class="text-muted">API Keys</small>
                    </div>
                    <div class="col-4">
                        <h3 class="mb-0"><?= number_format($msgCount) ?></h3>
                        <small class="text-muted">Messages</small>
                    </div>
                    <div class="col-4">
                        <h3 class="mb-0 text-success"><?= number_format($deliveredCount) ?></h3>
                        <small class="text-muted">Delivered</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- App Info -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-3">
                <i class="bi bi-whatsapp"></i> System Info
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">App Version</td><td><?= APP_VERSION ?></td></tr>
                    <tr><td class="text-muted">PHP Version</td><td><?= phpversion() ?></td></tr>
                    <tr><td class="text-muted">Server Time</td><td><?= date('Y-m-d H:i:s T') ?></td></tr>
                    <tr><td class="text-muted">Last Login</td><td><?= $user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'N/A' ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
