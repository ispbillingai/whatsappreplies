<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];
$isAdminUser = isAdmin();
getCurrentUser(); // Sets timezone early so all date calculations use local time

// Save WhatsApp type preference (AJAX)
if (isset($_GET['set_wa_type']) && in_array($_GET['set_wa_type'], ['whatsapp', 'whatsapp_business', 'load_balance'])) {
    $db->prepare('UPDATE users SET whatsapp_type = ? WHERE id = ?')->execute([$_GET['set_wa_type'], $userId]);
    exit;
}

// Bulk retry was removed — re-queueing thousands of messages at once was a
// WhatsApp ban risk. Single-message retry lives in messages.php (POST
// retry_id=N) where each retry is a deliberate, individual action.

// Admin view toggle: ?view=admin or ?view=personal
$adminView = false;
if ($isAdminUser) {
    $view = $_GET['view'] ?? ($_SESSION['dashboard_view'] ?? 'admin');
    if ($view === 'admin' || $view === 'personal') {
        $_SESSION['dashboard_view'] = $view;
    }
    $adminView = ($_SESSION['dashboard_view'] ?? 'admin') === 'admin';
}

// Get user's WhatsApp type preference
$stmt = $db->prepare('SELECT whatsapp_type FROM users WHERE id = ?');
$stmt->execute([$userId]);
$userWaType = $stmt->fetchColumn() ?: 'whatsapp';

// Stats queries - admin overview sees all, personal view sees own
$showAll = $isAdminUser && $adminView;
$whereUser = $showAll ? '' : 'WHERE user_id = ?';
$params = $showAll ? [] : [$userId];

// Total messages
$stmt = $db->prepare("SELECT COUNT(*) as total FROM messages $whereUser");
$stmt->execute($params);
$totalMessages = $stmt->fetch()['total'];

// By status (including expired)
$statuses = ['pending', 'sent', 'delivered', 'failed', 'expired'];
$statusCounts = [];
foreach ($statuses as $s) {
    $where = $showAll ? "WHERE status = ?" : "WHERE status = ? AND user_id = ?";
    $p = $showAll ? [$s] : [$s, $userId];
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM messages $where");
    $stmt->execute($p);
    $statusCounts[$s] = $stmt->fetch()['cnt'];
}

// Today's messages
$where = $showAll
    ? "WHERE DATE(created_at) = CURDATE()"
    : "WHERE DATE(created_at) = CURDATE() AND user_id = ?";
$p = $showAll ? [] : [$userId];
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM messages $where");
$stmt->execute($p);
$todayCount = $stmt->fetch()['cnt'];

// Success rate
$totalAttempted = $statusCounts['delivered'] + $statusCounts['failed'];
$successRate = $totalAttempted > 0 ? round(($statusCounts['delivered'] / $totalAttempted) * 100, 1) : 0;

// Admin-only stats
$totalUsers = 0;
$activeUsers = 0;
if ($isAdminUser && $adminView) {
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM users');
    $totalUsers = $stmt->fetch()['cnt'];
    $stmt = $db->query('SELECT COUNT(*) as cnt FROM users WHERE is_active = 1');
    $activeUsers = $stmt->fetch()['cnt'];
}

// Recent messages
$where = $showAll ? '' : 'WHERE m.user_id = ?';
$stmt = $db->prepare("SELECT m.*, u.name as user_name, d.device_name FROM messages m LEFT JOIN users u ON m.user_id = u.id LEFT JOIN devices d ON m.device_id = d.device_id $where ORDER BY m.created_at DESC LIMIT 50");
$stmt->execute($params);
$recentMessages = $stmt->fetchAll();

// Chart period
$chartPeriod = $_GET['chart'] ?? 'today';
$chartData = [];
try {
    $chartConfigs = [
        'today'   => ['interval' => 'DATE(created_at) = CURDATE()', 'group' => 'HOUR(created_at)', 'format' => "CONCAT(HOUR(created_at), ':00')", 'title' => 'Today (Hourly)'],
        'week'    => ['interval' => 'created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)', 'group' => 'DATE(created_at)', 'format' => "DATE_FORMAT(created_at, '%a %d')", 'title' => 'This Week'],
        'month'   => ['interval' => 'created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)', 'group' => 'DATE(created_at)', 'format' => "DATE_FORMAT(created_at, '%b %d')", 'title' => 'This Month'],
    ];
    $cc = $chartConfigs[$chartPeriod] ?? $chartConfigs['today'];
    $userFilter = $showAll ? '' : 'AND user_id = ?';
    $chartParams2 = $showAll ? [] : [$userId];

    // Date ranges — server stores times as-is (local time)
    $todayStartUTC = date('Y-m-d 00:00:00');
    $weekStartUTC = date('Y-m-d 00:00:00', strtotime('-7 days'));
    $monthStartUTC = date('Y-m-d 00:00:00', strtotime('-30 days'));

    // DB stores local time, no conversion needed
    $localExpr = "created_at";

    $hourGrp = "HOUR($localExpr)";
    $dateGrp = "DATE($localExpr)";

    if ($chartPeriod === 'today') {
        $chartSql = "SELECT DATE_FORMAT(MIN($localExpr), '%H:00') as label,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
         FROM messages WHERE created_at >= ? $userFilter
         GROUP BY $hourGrp ORDER BY $hourGrp ASC";
        array_unshift($chartParams2, $todayStartUTC);
    } elseif ($chartPeriod === 'week') {
        $chartSql = "SELECT DATE_FORMAT(MIN($localExpr), '%a %d') as label,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
         FROM messages WHERE created_at >= ? $userFilter
         GROUP BY $dateGrp ORDER BY $dateGrp ASC";
        array_unshift($chartParams2, $weekStartUTC);
    } else {
        $chartSql = "SELECT DATE_FORMAT(MIN($localExpr), '%b %d') as label,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
         FROM messages WHERE created_at >= ? $userFilter
         GROUP BY $dateGrp ORDER BY $dateGrp ASC";
        array_unshift($chartParams2, $monthStartUTC);
    }
    $chartStmt = $db->prepare($chartSql);
    $chartStmt->execute($chartParams2);
    $chartData = $chartStmt->fetchAll();

    // Period-specific status counts for the stat boxes
    $dateParam = $chartPeriod === 'today' ? $todayStartUTC : ($chartPeriod === 'week' ? $weekStartUTC : $monthStartUTC);
    $periodCountSql = "SELECT
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
        SUM(CASE WHEN status IN ('pending','sent') THEN 1 ELSE 0 END) as pending
     FROM messages WHERE created_at >= ? $userFilter";
    $periodParams = $showAll ? [$dateParam] : [$dateParam, $userId];
    $pcStmt = $db->prepare($periodCountSql);
    $pcStmt->execute($periodParams);
    $periodCounts = $pcStmt->fetch();
    $pDelivered = (int)($periodCounts['delivered'] ?? 0);
    $pFailed = (int)($periodCounts['failed'] ?? 0);
    $pExpired = (int)($periodCounts['expired'] ?? 0);
    $pPending = (int)($periodCounts['pending'] ?? 0);
} catch (Exception $e) {
    error_log("Chart query error: " . $e->getMessage());
    $chartError = $e->getMessage();
    $chartData = [];
}

// Active API keys count
$where = $showAll ? "WHERE is_active = 1" : "WHERE is_active = 1 AND user_id = ?";
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM api_keys $where");
$stmt->execute($params);
$activeKeys = $stmt->fetch()['cnt'];

// Get or auto-create the user's API key (one key per account)
$stmt = $db->prepare('SELECT api_key FROM api_keys WHERE user_id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$userId]);
$userKey = $stmt->fetchColumn();

if (!$userKey) {
    $userKey = bin2hex(random_bytes(24));
    $keyName = 'Default Key';
    $db->prepare('INSERT INTO api_keys (user_id, api_key, label, is_active) VALUES (?, ?, ?, 1)')
       ->execute([$userId, $userKey, $keyName]);
    $activeKeys = 1;
}

// Build the server base URL for API examples
$serverBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST'];

renderHeader('Dashboard', 'dashboard');
?>

<?php if ($isAdminUser): ?>
<!-- Admin View Toggle -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <?php if ($adminView): ?>
            <h5 class="mb-0"><i class="bi bi-speedometer2 text-primary"></i> Admin Overview</h5>
            <small class="text-muted">Viewing all users' data</small>
        <?php else: ?>
            <h5 class="mb-0"><i class="bi bi-person-circle text-success"></i> My Dashboard</h5>
            <small class="text-muted">Viewing your personal data</small>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-success btn-sm" onclick="startTourManual()"><i class="bi bi-mortarboard"></i> Take a Tour</button>
        <div class="btn-group">
            <a href="?view=admin" class="btn btn-sm <?= $adminView ? 'btn-primary' : 'btn-outline-primary' ?>">
                <i class="bi bi-speedometer2"></i> Admin Overview
            </a>
            <a href="?view=personal" class="btn btn-sm <?= !$adminView ? 'btn-success' : 'btn-outline-success' ?>">
                <i class="bi bi-person"></i> My Dashboard
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$isAdminUser): ?>
<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-outline-success btn-sm" onclick="startTourManual()"><i class="bi bi-mortarboard"></i> Take a Tour</button>
</div>
<?php endif; ?>

<?php if (!$isAdminUser || !$adminView): ?>
<!-- API Integration Box (personal view only) -->
<div class="card mb-4 border-0" style="background: linear-gradient(135deg, #075E54, #128C7E); border-radius: 16px;">
    <div class="card-body p-4 text-white">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h5 class="mb-1"><i class="bi bi-lightning-charge-fill"></i> Quick API Integration</h5>
                <p class="mb-0 small opacity-75">Copy this URL into your billing system to send WhatsApp messages</p>
            </div>
            <span class="badge bg-light text-dark"><i class="bi bi-check-circle-fill text-success"></i> Active</span>
        </div>

        <div class="bg-dark bg-opacity-50 rounded-3 p-3 mb-3 position-relative" style="font-family: monospace; font-size: 13px; word-break: break-all;">
            <input type="hidden" id="fullApiUrl" value="<?= htmlspecialchars($serverBase) ?>/api/sendWA.php?to=[number]&msg=[text]&secret=<?= htmlspecialchars($userKey) ?>">
            <span class="text-warning"><?= htmlspecialchars($serverBase) ?>/api/sendWA.php?to=</span><span class="text-info">[number]</span><span class="text-warning">&msg=</span><span class="text-info">[text]</span><span class="text-warning">&secret=</span><span class="text-success"><?= htmlspecialchars($userKey) ?></span>
            <button class="btn btn-sm btn-light position-absolute top-0 end-0 m-2" onclick="copyUrl()" title="Copy Full URL">
                <i class="bi bi-clipboard" id="copyUrlIcon"></i> <span id="copyUrlText" class="small">Copy URL</span>
            </button>
        </div>

        <!-- Where each goes -->
        <div class="bg-dark bg-opacity-25 rounded-3 p-3 mb-3">
            <div class="row g-2 small">
                <div class="col-md-6">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-pc-display text-info" style="font-size:18px;"></i>
                        <strong>For your Billing System:</strong>
                    </div>
                    <p class="mb-0 opacity-75">Copy the <strong>full URL above</strong> <i class="bi bi-arrow-up"></i> then go to your billing system: <strong>Settings > General Settings > WhatsApp Notification</strong> and paste it there.</p>
                </div>
                <div class="col-md-6">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-phone text-warning" style="font-size:18px;"></i>
                        <strong>For the FreeISP Replies App:</strong>
                    </div>
                    <p class="mb-0 opacity-75">Sign in inside the app with the <strong>same email and password</strong> you use for this dashboard. Nothing to copy.</p>
                </div>
            </div>
        </div>

        <!-- Device setup prompt -->
        <div class="bg-dark bg-opacity-25 rounded-3 p-3 mb-3">
            <div class="d-flex align-items-center justify-content-between">
                <div class="small opacity-75">
                    <i class="bi bi-phone-fill"></i> Configure which WhatsApp each device uses and enable load balancing from the <strong>Devices</strong> page.
                </div>
                <a href="devices.php" class="btn btn-sm btn-outline-light ms-3"><i class="bi bi-phone-fill"></i> Manage Devices</a>
            </div>
        </div>

        <div class="small opacity-75">
            <strong>Example:</strong>
            <code class="text-light"><?= htmlspecialchars($serverBase) ?>/api/sendWA.php?to=254712345678&msg=Hello+World&secret=<?= htmlspecialchars($userKey) ?></code>
        </div>
    </div>
</div>

<!-- Important Notes -->
<div class="card mb-4 border-0">
    <div class="card-body">
        <h6 class="mb-3"><i class="bi bi-info-circle-fill text-primary"></i> Important Notes</h6>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="d-flex gap-2">
                    <i class="bi bi-wifi text-warning mt-1"></i>
                    <div>
                        <strong class="small">Phone Must Be Online</strong>
                        <p class="text-muted small mb-0">Your phone needs an active internet connection at all times. Messages queue on the server and are delivered when the phone is reachable.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="d-flex gap-2">
                    <i class="bi bi-phone text-info mt-1"></i>
                    <div>
                        <strong class="small">WhatsApp Opens Briefly</strong>
                        <p class="text-muted small mb-0">For new contacts, WhatsApp opens on your phone for ~1-2 seconds to send each message. We recommend using a dedicated phone.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="d-flex gap-2">
                    <i class="bi bi-shield-check text-success mt-1"></i>
                    <div>
                        <strong class="small">Low Ban Risk</strong>
                        <p class="text-muted small mb-0">Messages are sent from your real WhatsApp account, so ban risk is low with moderate volumes.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="d-flex gap-2">
                    <i class="bi bi-clock-history text-purple mt-1"></i>
                    <div>
                        <strong class="small">Auto-Expire After 5 Minutes</strong>
                        <p class="text-muted small mb-0">Messages pending for more than 5 minutes are automatically expired to prevent pile-up. You can retry them from below.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Dedicated Phone Warning -->
<div class="card mb-4 border-danger border-opacity-50">
    <div class="card-body">
        <div class="d-flex align-items-start gap-3">
            <div class="rounded-circle bg-danger bg-opacity-10 d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;">
                <i class="bi bi-unlock text-danger" style="font-size:22px;"></i>
            </div>
            <div>
                <h6 class="mb-1 text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Important: Remove Screen Lock on Dedicated Phone</h6>
                <p class="small mb-2">For messages to send reliably <strong>even when the screen is off</strong>, you must remove the screen lock (PIN, pattern, password, fingerprint) on your dedicated relay phone.</p>
                <div class="bg-light rounded-3 p-3 mb-2">
                    <p class="small fw-bold mb-1">How to remove screen lock:</p>
                    <ol class="small mb-0">
                        <li>Go to <strong>Settings > Security > Screen Lock</strong></li>
                        <li>Enter your current PIN/pattern</li>
                        <li>Select <strong>"None"</strong> or <strong>"Swipe"</strong></li>
                    </ol>
                </div>
                <div class="small text-muted">
                    <i class="bi bi-info-circle"></i> <strong>Why?</strong> When the phone is locked with a PIN/password, Android blocks apps from opening WhatsApp in the background. Without a screen lock, messages can be sent automatically even when the screen turns off. This phone should be a <strong>dedicated device</strong> — not your personal phone.
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function copyUrl() {
    var url = document.getElementById('fullApiUrl').value;
    navigator.clipboard.writeText(url).then(function() {
        var icon = document.getElementById('copyUrlIcon');
        var text = document.getElementById('copyUrlText');
        icon.className = 'bi bi-check-lg text-success';
        text.textContent = 'Copied!';
        setTimeout(function() { icon.className = 'bi bi-clipboard'; text.textContent = 'Copy URL'; }, 2000);
    });
}
function copyKey() {
    var input = document.getElementById('apiKeyDisplay');
    input.select();
    document.execCommand('copy');
    var icon = document.getElementById('copyIcon');
    icon.className = 'bi bi-check-lg text-success';
    setTimeout(function() { icon.className = 'bi bi-clipboard'; }, 2000);
}
</script>

<!-- Stats Cards -->
<div class="row g-4 mb-4">
    <?php if ($isAdminUser && $adminView): ?>
    <!-- Admin-only: Total Users -->
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value text-primary"><?= number_format($totalUsers) ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
                <div class="small text-muted mt-1"><?= $activeUsers ?> active</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value"><?= number_format($totalMessages) ?></div>
                        <div class="stat-label">Total Messages</div>
                    </div>
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="bi bi-chat-dots"></i>
                    </div>
                </div>
                <div class="small text-muted mt-1"><?= $todayCount ?> today</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value text-success"><?= number_format($statusCounts['delivered']) ?></div>
                        <div class="stat-label">Delivered</div>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                </div>
                <div class="small text-muted mt-1"><?= $successRate ?>% success rate</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value text-danger"><?= number_format($statusCounts['failed'] + $statusCounts['expired']) ?></div>
                        <div class="stat-label">Failed / Expired</div>
                    </div>
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-x-circle"></i>
                    </div>
                </div>
                <div class="small text-muted mt-1"><?= $statusCounts['failed'] ?> failed, <?= $statusCounts['expired'] ?> expired</div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- User view stats -->
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value"><?= number_format($totalMessages) ?></div>
                        <div class="stat-label">Total Messages</div>
                    </div>
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-chat-dots"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value text-success"><?= number_format($statusCounts['delivered']) ?></div>
                        <div class="stat-label">Delivered</div>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value text-warning"><?= number_format($statusCounts['pending']) ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-clock"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value text-danger"><?= number_format($statusCounts['failed'] + $statusCounts['expired']) ?></div>
                        <div class="stat-label">Failed / Expired</div>
                    </div>
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-x-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Second row stats -->
<div class="row g-4 mb-4">
    <div class="col-sm-6 col-xl-4">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value"><?= $todayCount ?></div>
                        <div class="stat-label">Messages Today</div>
                    </div>
                    <div class="stat-icon bg-info bg-opacity-10 text-info">
                        <i class="bi bi-calendar-day"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-4">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value"><?= $successRate ?>%</div>
                        <div class="stat-label">Success Rate</div>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-graph-up"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-4">
        <div class="card stat-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="stat-value"><?= $activeKeys ?></div>
                        <div class="stat-label">Active API Keys</div>
                    </div>
                    <div class="stat-icon bg-secondary bg-opacity-10 text-secondary">
                        <i class="bi bi-key"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Retry section - show if there are failed or expired messages (personal view only, or admin personal)
$retryableCount = 0;
if (!$showAll) {
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM messages WHERE user_id = ? AND status IN ("failed", "expired")');
    $stmt->execute([$userId]);
    $retryableCount = $stmt->fetch()['cnt'];
}
?>

<?php if ($retryableCount > 0): ?>
<!-- Failed / Expired summary. Bulk retry was removed because re-queueing
     thousands at once is a WhatsApp ban risk. Review individually in
     messages.php — the per-row Retry button there sends one at a time. -->
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-exclamation-triangle text-warning"></i> <strong><?= $retryableCount ?></strong> message(s) need attention</span>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3">These messages were not delivered. Review them and retry individually — bulk retry is disabled to avoid sending many messages at once (WhatsApp ban risk).</p>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($statusCounts['failed'] > 0): ?>
            <a class="btn btn-outline-danger btn-sm" href="messages.php?status=failed">
                <i class="bi bi-list-ul"></i> Review <?= $statusCounts['failed'] ?> Failed
            </a>
            <?php endif; ?>
            <?php if ($statusCounts['expired'] > 0): ?>
            <a class="btn btn-outline-secondary btn-sm" href="messages.php?status=expired">
                <i class="bi bi-list-ul"></i> Review <?= $statusCounts['expired'] ?> Expired
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isAdminUser && $adminView): ?>
<!-- Admin: Users Overview -->
<?php
$stmt = $db->query('SELECT u.id, u.name, u.email, u.role, u.is_active, u.last_login, u.created_at,
    (SELECT COUNT(*) FROM messages WHERE user_id = u.id) as total_messages,
    (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND status = "delivered") as delivered,
    (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND status = "pending") as pending_msgs
    FROM users u ORDER BY u.created_at DESC LIMIT 10');
$usersList = $stmt->fetchAll();
?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center py-3">
        <span><i class="bi bi-people-fill"></i> Users Overview</span>
        <a href="users.php" class="btn btn-sm btn-outline-secondary">Manage Users</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Messages</th>
                        <th>Delivered</th>
                        <th>Last Login</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usersList as $u): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                        <td class="small"><?= htmlspecialchars($u['email']) ?></td>
                        <td><span class="badge bg-<?= $u['role'] === 'admin' ? 'primary' : 'secondary' ?>"><?= ucfirst($u['role']) ?></span></td>
                        <td><?= number_format($u['total_messages']) ?></td>
                        <td class="text-success"><?= number_format($u['delivered']) ?></td>
                        <td class="small text-muted"><?= $u['last_login'] ? fmtTime($u['last_login']) : 'Never' ?></td>
                        <td>
                            <?php if ($u['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Disabled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Message Analytics -->
<?php $periodLabel = $cc['title']; $pTotal = $pDelivered + $pFailed + $pExpired + $pPending; ?>
<div class="card mb-4" style="border:none; border-radius:16px; overflow:hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.06);">
    <div class="card-header py-3 d-flex justify-content-between align-items-center" style="background:white; border-bottom:1px solid #f0f0f0;">
        <div>
            <h6 class="mb-0 fw-bold"><i class="bi bi-graph-up text-success"></i> Message Analytics</h6>
            <small class="text-muted"><?= $periodLabel ?> — <?= number_format($pTotal) ?> messages</small>
        </div>
        <div class="btn-group btn-group-sm">
            <?php $vp = $isAdminUser && $adminView ? '&view=admin' : ''; ?>
            <a href="?chart=today<?= $vp ?>" class="btn <?= $chartPeriod === 'today' ? 'btn-success' : 'btn-outline-secondary' ?> rounded-start-pill">Today</a>
            <a href="?chart=week<?= $vp ?>" class="btn <?= $chartPeriod === 'week' ? 'btn-success' : 'btn-outline-secondary' ?>">This Week</a>
            <a href="?chart=month<?= $vp ?>" class="btn <?= $chartPeriod === 'month' ? 'btn-success' : 'btn-outline-secondary' ?> rounded-end-pill">This Month</a>
        </div>
    </div>

    <!-- Period stat summary row -->
    <div class="row g-0 text-center" style="border-bottom:1px solid #f0f0f0;">
        <div class="col-3 py-3" style="border-right:1px solid #f0f0f0;">
            <div class="fs-4 fw-bold text-success"><?= number_format($pDelivered) ?></div>
            <small class="text-muted"><i class="bi bi-check-circle-fill text-success"></i> Delivered</small>
        </div>
        <div class="col-3 py-3" style="border-right:1px solid #f0f0f0;">
            <div class="fs-4 fw-bold text-danger"><?= number_format($pFailed) ?></div>
            <small class="text-muted"><i class="bi bi-x-circle-fill text-danger"></i> Failed</small>
        </div>
        <div class="col-3 py-3" style="border-right:1px solid #f0f0f0;">
            <div class="fs-4 fw-bold" style="color:#9C27B0;"><?= number_format($pExpired) ?></div>
            <small class="text-muted"><i class="bi bi-clock-fill" style="color:#9C27B0;"></i> Expired</small>
        </div>
        <div class="col-3 py-3">
            <div class="fs-4 fw-bold text-warning"><?= number_format($pPending) ?></div>
            <small class="text-muted"><i class="bi bi-hourglass-split text-warning"></i> Pending</small>
        </div>
    </div>

    <div class="card-body p-4">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <div style="position:relative; height:280px;">
                    <canvas id="mainChart"></canvas>
                </div>
            </div>
            <div class="col-lg-4">
                <div style="position:relative; height:250px;">
                    <canvas id="statusChart"></canvas>
                </div>
                <?php if ($pTotal > 0): $successRate = round(($pDelivered / $pTotal) * 100, 1); ?>
                <div class="text-center mt-2">
                    <span class="badge bg-success bg-opacity-10 text-success px-3 py-2 fs-6"><?= $successRate ?>% success rate</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    var labels = <?= json_encode(array_column($chartData, 'label')) ?>;
    var delivered = <?= json_encode(array_map(fn($r) => (int)$r['delivered'], $chartData)) ?>;
    var failed = <?= json_encode(array_map(fn($r) => (int)$r['failed'], $chartData)) ?>;
    var expired = <?= json_encode(array_map(fn($r) => (int)$r['expired'], $chartData)) ?>;

    var ctx = document.getElementById('mainChart').getContext('2d');

    // Gradient fills for bars
    var greenGrad = ctx.createLinearGradient(0, 0, 0, 280);
    greenGrad.addColorStop(0, '#25D366');
    greenGrad.addColorStop(1, '#128C7E');

    var redGrad = ctx.createLinearGradient(0, 0, 0, 280);
    redGrad.addColorStop(0, '#F44336');
    redGrad.addColorStop(1, '#C62828');

    var purpleGrad = ctx.createLinearGradient(0, 0, 0, 280);
    purpleGrad.addColorStop(0, '#AB47BC');
    purpleGrad.addColorStop(1, '#7B1FA2');

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Delivered',
                    data: delivered,
                    backgroundColor: greenGrad,
                    hoverBackgroundColor: '#25D366',
                    borderRadius: { topLeft: 6, topRight: 6 },
                    borderSkipped: false,
                    barPercentage: 0.7,
                    categoryPercentage: 0.75
                },
                {
                    label: 'Failed',
                    data: failed,
                    backgroundColor: redGrad,
                    hoverBackgroundColor: '#F44336',
                    borderRadius: { topLeft: 6, topRight: 6 },
                    borderSkipped: false,
                    barPercentage: 0.7,
                    categoryPercentage: 0.75
                },
                {
                    label: 'Expired',
                    data: expired,
                    backgroundColor: purpleGrad,
                    hoverBackgroundColor: '#9C27B0',
                    borderRadius: { topLeft: 6, topRight: 6 },
                    borderSkipped: false,
                    barPercentage: 0.7,
                    categoryPercentage: 0.75
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: { usePointStyle: true, pointStyle: 'rectRounded', padding: 16, font: { size: 12, weight: '500' }, boxWidth: 12, boxHeight: 12 }
                },
                tooltip: {
                    backgroundColor: 'rgba(255,255,255,0.95)',
                    titleColor: '#333',
                    bodyColor: '#555',
                    borderColor: '#e0e0e0',
                    borderWidth: 1,
                    titleFont: { size: 13, weight: 'bold' },
                    bodyFont: { size: 12 },
                    padding: 14,
                    cornerRadius: 10,
                    displayColors: true,
                    boxPadding: 6,
                    callbacks: {
                        label: function(item) { return ' ' + item.dataset.label + ': ' + item.formattedValue; }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.06)', drawBorder: false },
                    ticks: { font: { size: 11, weight: '500' }, padding: 10, callback: function(v) { return Number.isInteger(v) ? v : ''; } },
                    border: { display: false }
                },
                x: {
                    grid: { display: false },
                    ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 14, font: { size: 11 }, padding: 6 },
                    border: { display: false }
                }
            }
        }
    });

    var dData = [<?= $pDelivered ?>, <?= $pFailed ?>, <?= $pExpired ?>, <?= $pPending ?>];
    var hasData = dData.some(function(v) { return v > 0; });

    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: ['Delivered', 'Failed', 'Expired', 'Pending'],
            datasets: [{
                data: hasData ? dData : [1],
                backgroundColor: hasData ? ['#25D366', '#F44336', '#9C27B0', '#FF9800'] : ['#e0e0e0'],
                borderWidth: 0,
                cutout: '65%'
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: hasData }
            }
        }
    });
})();
</script>

<!-- Recent Messages -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center py-3">
        <span><i class="bi bi-clock-history"></i> Recent Messages</span>
        <a href="messages.php" class="btn btn-sm btn-outline-secondary">View All</a>
    </div>
    <div class="card-body">
        <?php if (empty($recentMessages)): ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <h5>No messages yet</h5>
            <p>Send your first message via the API or the Send page.</p>
            <a href="send.php" class="btn btn-wa btn-sm">Send a Message</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Phone</th>
                        <th>Message</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Device</th>
                        <th>Time</th>
                        <?php if ($showAll): ?><th>User</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentMessages as $msg): ?>
                    <tr>
                        <td><strong>#<?= $msg['id'] ?></strong></td>
                        <td><i class="bi bi-telephone"></i> <?= htmlspecialchars($msg['phone']) ?></td>
                        <td class="msg-preview"><?= htmlspecialchars($msg['message']) ?></td>
                        <td>
                            <span class="badge bg-<?= $msg['whatsapp_type'] === 'whatsapp_business' ? 'info' : 'success' ?>">
                                <?= $msg['whatsapp_type'] === 'whatsapp_business' ? 'Biz' : 'WA' ?>
                            </span>
                        </td>
                        <td><span class="badge-status badge-<?= $msg['status'] ?>"><?= ucfirst($msg['status']) ?></span></td>
                        <td class="small text-muted"><?= htmlspecialchars($msg['device_name'] ?? '-') ?></td>
                        <td class="text-muted small"><?= fmtTime($msg['created_at']) ?></td>
                        <?php if ($showAll): ?>
                        <td class="small"><?= htmlspecialchars($msg['user_name'] ?? 'N/A') ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php $isFirstLogin = !empty($_SESSION['is_first_login']); if ($isFirstLogin) $_SESSION['is_first_login'] = false; ?>
<!-- Tour overlay + highlight system -->
<style>
.tour-overlay { position: fixed; top:0; left:0; right:0; bottom:0; background: rgba(0,0,0,0.7); z-index: 9998; }
.tour-highlight { position: relative; z-index: 9999; box-shadow: 0 0 0 4px #25D366, 0 0 20px rgba(37,211,102,0.5); border-radius: 12px; transition: box-shadow 0.3s; }
.tour-tooltip {
    position: fixed; z-index: 10000; background: white; border-radius: 16px;
    padding: 24px; max-width: 420px; box-shadow: 0 10px 40px rgba(0,0,0,0.3);
}
.tour-tooltip .tour-arrow {
    font-size: 28px; color: #25D366; animation: bounce 1s infinite;
}
@keyframes bounce {
    0%,100% { transform: translateY(0); }
    50% { transform: translateY(-8px); }
}
.tour-tooltip .step-badge {
    display: inline-flex; align-items: center; justify-content: center;
    width: 32px; height: 32px; border-radius: 50%; background: #25D366; color: white; font-weight: 700; font-size: 14px;
}
.tour-tooltip .tour-title { font-size: 16px; font-weight: 700; color: #075E54; }
.tour-tooltip .tour-desc { font-size: 13px; color: #555; }
</style>

<!-- Welcome modal first -->
<div class="modal fade" id="tourWelcome" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; overflow: hidden;">
            <div class="text-center p-5" style="background: linear-gradient(135deg, #075E54, #25D366); color: white;">
                <i class="bi bi-whatsapp" style="font-size: 64px;"></i>
                <h3 class="mt-3 fw-bold">Welcome to FreeISP Whatsapp Messaging!</h3>
                <p class="opacity-75 mb-0">Let me show you around your dashboard.</p>
            </div>
            <div class="p-4 text-center">
                <p class="text-muted">I'll walk you through each section and point at exactly where to click. Takes about 1 minute.</p>
                <button class="btn btn-success btn-lg px-5" onclick="startTour()">Show Me Around <i class="bi bi-hand-index-thumb"></i></button>
                <div class="mt-2">
                    <button class="btn btn-link text-muted small" onclick="skipTour()">Skip, I'll figure it out</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="tourOverlay" class="tour-overlay d-none"></div>
<div id="tourTooltip" class="tour-tooltip d-none"></div>

<script>
var isMobile = window.innerWidth < 768;
var tourSteps = [
    {
        target: '#fullApiUrl',
        targetParent: true,
        arrow: 'bi-arrow-up-circle-fill',
        step: 1,
        title: 'Billing System URL',
        desc: 'This is the <strong>full URL</strong> for your billing system. Click <strong>"Copy URL"</strong> then go to your billing system:<br><strong>Settings > General Settings > WhatsApp Notification</strong> and paste it there.',
    },
    {
        target: isMobile ? null : '.sidebar a[href="devices.php"]',
        arrow: 'bi-phone-fill',
        step: 2,
        title: 'Manage Devices',
        desc: 'Go to <strong>Devices</strong> in the menu to configure which WhatsApp each phone uses (Personal, Business, or Both).<br><br>You can also see device status, service permissions, and enable load balancing from there.',
        openSidebar: true,
    },
    {
        target: isMobile ? null : '.sidebar a[href="installation.php"]',
        arrow: 'bi-download',
        step: 3,
        title: 'Installation Guide',
        desc: 'Go to <strong>Installation Guide</strong> in the menu to download the FreeISP Replies Android app and follow step-by-step setup instructions.<br><br><span class="text-warning"><i class="bi bi-exclamation-triangle"></i> After installing: <strong>Settings > Apps > FreeISP Replies > three dots > Allow restricted settings</strong></span>',
        openSidebar: true,
    },
    {
        target: isMobile ? null : '.sidebar a[href="messages.php"]',
        arrow: 'bi-chat-dots-fill',
        step: 4,
        title: 'Message History',
        desc: 'Go to <strong>Messages</strong> in the menu to view all sent messages. You can filter by status, search by phone number, retry failed messages, and track delivery.',
        openSidebar: true,
    },
];

var currentTourStep = 0;

function startTour() {
    bootstrap.Modal.getInstance(document.getElementById('tourWelcome')).hide();
    setTimeout(function() {
        document.getElementById('tourOverlay').classList.remove('d-none');
        showTourStep(0);
    }, 400);
}

function skipTour() {
    bootstrap.Modal.getInstance(document.getElementById('tourWelcome')).hide();
}

function showTourStep(index) {
    document.querySelectorAll('.tour-highlight').forEach(function(el) { el.classList.remove('tour-highlight'); });

    if (index >= tourSteps.length) { endTour(); return; }
    currentTourStep = index;
    var step = tourSteps[index];
    var tooltip = document.getElementById('tourTooltip');
    var isLast = index === tourSteps.length - 1;

    // Build tooltip content
    var tooltipHtml = '<div class="d-flex align-items-start gap-3">' +
        '<span class="step-badge">' + step.step + '</span>' +
        '<div class="flex-grow-1">' +
            '<div class="d-flex align-items-center gap-2 mb-2">' +
                '<i class="bi ' + step.arrow + ' tour-arrow"></i>' +
                '<span class="tour-title">' + step.title + '</span>' +
            '</div>' +
            '<div class="tour-desc mb-3">' + step.desc + '</div>' +
            '<div class="d-flex justify-content-between align-items-center">' +
                '<span class="text-muted small">Step ' + step.step + ' of ' + tourSteps.length + '</span>' +
                '<div class="d-flex gap-2">' +
                    (index > 0 ? '<button class="btn btn-outline-secondary btn-sm" onclick="showTourStep(' + (index-1) + ')"><i class="bi bi-arrow-left"></i> Back</button>' : '') +
                    (isLast
                        ? '<button class="btn btn-success btn-sm px-3" onclick="endTour()"><i class="bi bi-check-circle"></i> Done!</button>'
                        : '<button class="btn btn-success btn-sm px-3" onclick="showTourStep(' + (index+1) + ')">Next <i class="bi bi-arrow-right"></i></button>') +
                '</div>' +
            '</div>' +
        '</div>' +
    '</div>';

    // If no target (mobile sidebar items) or target not found — show centered card
    var el = step.target ? document.querySelector(step.target) : null;

    if (step.openSidebar && isMobile && el) {
        // Open sidebar on mobile to show the target
        document.getElementById('sidebar').classList.add('show');
        setTimeout(function() { finishShowStep(el, step, tooltip, tooltipHtml, isLast); }, 400);
    } else if (!el) {
        // No target — show tooltip centered on screen
        tooltip.innerHTML = tooltipHtml;
        tooltip.style.top = '50%';
        tooltip.style.left = '50%';
        tooltip.style.transform = 'translate(-50%, -50%)';
        tooltip.style.maxWidth = Math.min(400, window.innerWidth - 40) + 'px';
        tooltip.classList.remove('d-none');
    } else {
        finishShowStep(el, step, tooltip, tooltipHtml, isLast);
    }
}

function finishShowStep(el, step, tooltip, tooltipHtml, isLast) {
    var highlightEl = step.targetParent ? el.closest('.bg-dark, .rounded-3, div') : el;
    highlightEl.classList.add('tour-highlight');
    highlightEl.scrollIntoView({ behavior: 'smooth', block: 'center' });

    setTimeout(function() {
        var rect = highlightEl.getBoundingClientRect();
        tooltip.innerHTML = tooltipHtml;
        tooltip.style.transform = 'none';
        tooltip.style.maxWidth = Math.min(420, window.innerWidth - 32) + 'px';

        var top = rect.bottom + 16;
        var left = Math.max(16, Math.min(rect.left, window.innerWidth - 440));

        if (top + 200 > window.innerHeight) {
            top = Math.max(16, rect.top - 220);
        }

        // Mobile: always center horizontally
        if (window.innerWidth < 768) {
            left = Math.max(8, (window.innerWidth - 380) / 2);
        }

        tooltip.style.top = top + 'px';
        tooltip.style.left = left + 'px';
        tooltip.classList.remove('d-none');
    }, 300);
}

function endTour() {
    document.querySelectorAll('.tour-highlight').forEach(function(el) { el.classList.remove('tour-highlight'); });
    document.getElementById('tourOverlay').classList.add('d-none');
    document.getElementById('tourTooltip').classList.add('d-none');
    // Close sidebar on mobile
    document.getElementById('sidebar').classList.remove('show');
}

function startTourManual() {
    new bootstrap.Modal(document.getElementById('tourWelcome')).show();
}

<?php if ($isFirstLogin): ?>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('tourWelcome')).show();
});
<?php endif; ?>
</script>

<?php renderFooter(); ?>
