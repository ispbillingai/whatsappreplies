<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];
$isAdminUser = isAdmin();


// Handle device actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $deviceId = $_POST['device_id'] ?? '';

    // Add a phone: the key it gets is what you type into the app. The row's
    // device_id is ours; the phone never has to identify itself.
    if ($action === 'add') {
        $name = trim($_POST['device_name'] ?? '');
        $waType = in_array($_POST['wa_type'] ?? '', ['whatsapp', 'whatsapp_business', 'both']) ? $_POST['wa_type'] : 'both';
        $num = preg_replace('/[^0-9]/', '', $_POST['wa_number'] ?? '');
        $biz = preg_replace('/[^0-9]/', '', $_POST['wa_business_number'] ?? '');
        $needNum = $waType !== 'whatsapp_business' && strlen($num) < 9;
        $needBiz = $waType !== 'whatsapp' && strlen($biz) < 9;
        if ($name === '') {
            flash('Give the phone a name', 'error');
        } elseif ($needNum || $needBiz) {
            flash('Enter the phone WhatsApp number(s) with country code - replies are sent from that number', 'error');
        } else {
            $newId = 'dev-' . bin2hex(random_bytes(8));
            $key = bin2hex(random_bytes(20));
            $db->prepare('INSERT INTO devices (user_id, device_id, device_key, device_name, whatsapp_type, wa_number, wa_business_number) VALUES (?, ?, ?, ?, ?, ?, ?)')
               ->execute([$userId, $newId, $key, $name, $waType, $num ?: null, $biz ?: null]);
            flash("Device \"$name\" added. Copy its key into the app on that phone.");
        }
    }

    // New key for a phone (old one stops working at once)
    if ($action === 'new_key' && $deviceId) {
        $key = bin2hex(random_bytes(20));
        $where = $isAdminUser ? '' : ' AND user_id = ?';
        $params = $isAdminUser ? [$key, $deviceId] : [$key, $deviceId, $userId];
        $db->prepare("UPDATE devices SET device_key = ? WHERE device_id = ? $where")->execute($params);
        flash('New key made. Update the app on that phone.');
    }

    if ($action === 'toggle' && $deviceId) {
        $where = $isAdminUser ? '' : ' AND user_id = ?';
        $params = $isAdminUser ? [$deviceId] : [$deviceId, $userId];
        $db->prepare("UPDATE devices SET is_active = NOT is_active WHERE device_id = ? $where")->execute($params);
        flash('Device status updated');
    }

    if ($action === 'rename' && $deviceId) {
        $newName = trim($_POST['new_name'] ?? '');
        if ($newName) {
            $where = $isAdminUser ? '' : ' AND user_id = ?';
            $params = $isAdminUser ? [$newName, $deviceId] : [$newName, $deviceId, $userId];
            $db->prepare("UPDATE devices SET device_name = ? WHERE device_id = ? $where")->execute($params);
            flash('Device renamed');
        }
    }

    if ($action === 'set_wa_type' && $deviceId) {
        $waType = $_POST['wa_type'] ?? 'both';
        if (in_array($waType, ['whatsapp', 'whatsapp_business', 'both'])) {
            $where = $isAdminUser ? '' : ' AND user_id = ?';
            $params = $isAdminUser ? [$waType, $deviceId] : [$waType, $deviceId, $userId];
            $db->prepare("UPDATE devices SET whatsapp_type = ? WHERE device_id = ? $where")->execute($params);
            flash('Device WhatsApp type updated');
        }
    }

    if ($action === 'set_numbers' && $deviceId) {
        // The phone's own WhatsApp number(s) - the line a customer writes to.
        // Normally set from the app itself; editable here as a fallback.
        $num = preg_replace('/[^0-9]/', '', $_POST['wa_number'] ?? '');
        $biz = preg_replace('/[^0-9]/', '', $_POST['wa_business_number'] ?? '');
        $where = $isAdminUser ? '' : ' AND user_id = ?';
        $params = $isAdminUser ? [$num ?: null, $biz ?: null, $deviceId] : [$num ?: null, $biz ?: null, $deviceId, $userId];
        $db->prepare("UPDATE devices SET wa_number = ?, wa_business_number = ? WHERE device_id = ? $where")->execute($params);
        flash('Device numbers updated');
    }

    if ($action === 'delete' && $deviceId) {
        $where = $isAdminUser ? '' : ' AND user_id = ?';
        $params = $isAdminUser ? [$deviceId] : [$deviceId, $userId];
        $db->prepare("DELETE FROM devices WHERE device_id = ? $where")->execute($params);
        flash('Device removed');
    }

    $redirView = ($_POST['view'] ?? '') === 'all' ? '?view=all' : '';
    header('Location: devices.php' . $redirView);
    exit;
}

// Admin view toggle: 'mine' (default) or 'all'
$view = $_GET['view'] ?? 'mine';
$showAll = $isAdminUser && $view === 'all';

// Fetch devices (basic info only — we aggregate stats separately for performance)
try {
    if ($showAll) {
        $stmt = $db->query(
            'SELECT d.*, u.name as user_name, u.email
             FROM devices d
             LEFT JOIN users u ON d.user_id = u.id
             ORDER BY d.last_seen DESC'
        );
    } else {
        $stmt = $db->prepare(
            'SELECT d.* FROM devices d WHERE d.user_id = ? ORDER BY d.last_seen DESC'
        );
        $stmt->execute([$userId]);
    }
    $devices = $stmt->fetchAll();

    // Aggregate message stats for all devices in ONE query (was 4 correlated subqueries per device)
    if (!empty($devices)) {
        $deviceIds = array_column($devices, 'device_id');
        $deviceIds = array_filter($deviceIds, fn($id) => !empty($id));

        $statsByDevice = [];
        if (!empty($deviceIds)) {
            $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
            $statsStmt = $db->prepare(
                "SELECT device_id,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN status IN ('pending', 'sent') THEN 1 ELSE 0 END) as pending_msgs,
                    MAX(CASE WHEN status = 'delivered' THEN sent_at END) as last_delivered_at
                 FROM messages
                 WHERE device_id IN ($placeholders)
                 GROUP BY device_id"
            );
            $statsStmt->execute(array_values($deviceIds));
            foreach ($statsStmt->fetchAll() as $row) {
                $statsByDevice[$row['device_id']] = $row;
            }

            // Last message status per device — separate query, indexed lookup
            $lastStmt = $db->prepare(
                "SELECT m.device_id, m.status
                 FROM messages m
                 INNER JOIN (
                     SELECT device_id, MAX(id) as max_id
                     FROM messages WHERE device_id IN ($placeholders)
                     GROUP BY device_id
                 ) latest ON m.device_id = latest.device_id AND m.id = latest.max_id"
            );
            $lastStmt->execute(array_values($deviceIds));
            foreach ($lastStmt->fetchAll() as $row) {
                if (isset($statsByDevice[$row['device_id']])) {
                    $statsByDevice[$row['device_id']]['last_msg_status'] = $row['status'];
                } else {
                    $statsByDevice[$row['device_id']] = ['last_msg_status' => $row['status']];
                }
            }
        }

        // Merge stats into devices array
        foreach ($devices as &$dev) {
            $stats = $statsByDevice[$dev['device_id']] ?? [];
            $dev['delivered'] = (int)($stats['delivered'] ?? 0);
            $dev['pending_msgs'] = (int)($stats['pending_msgs'] ?? 0);
            $dev['last_delivered_at'] = $stats['last_delivered_at'] ?? null;
            $dev['last_msg_status'] = $stats['last_msg_status'] ?? '';
        }
        unset($dev);
    }
} catch (Exception $e) {
    error_log("devices.php fetch error: " . $e->getMessage());
    $devices = [];
}

renderHeader('Devices', 'devices');
?>

<!-- Info banner -->
<div class="card mb-4 border-0" style="background: linear-gradient(135deg, #075E54, #128C7E); border-radius: 12px;">
    <div class="card-body p-4 text-white">
        <div class="d-flex align-items-center gap-3 mb-3">
            <i class="bi bi-phone-fill" style="font-size: 32px;"></i>
            <div>
                <h5 class="mb-1">Multi-Device Relay</h5>
                <p class="mb-0 small opacity-75">Install the FreeISP Replies app on multiple phones to increase throughput and reliability. Devices register automatically when they start polling.</p>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="bg-dark bg-opacity-25 rounded-3 p-3 h-100">
                    <h6 class="small"><i class="bi bi-phone"></i> Per-Device WhatsApp</h6>
                    <p class="small opacity-75 mb-0">Set each device to use <strong>WhatsApp Personal</strong>, <strong>WhatsApp Business</strong>, or <strong>Both</strong>. Messages are routed to the right device based on this setting.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bg-dark bg-opacity-25 rounded-3 p-3 h-100">
                    <h6 class="small"><i class="bi bi-shuffle"></i> Load Balancing</h6>
                    <p class="small opacity-75 mb-0">When multiple devices are active, messages are <strong>distributed evenly</strong> across them. This reduces ban risk by spreading volume across different WhatsApp accounts.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="bg-dark bg-opacity-25 rounded-3 p-3 h-100">
                    <h6 class="small"><i class="bi bi-arrow-repeat"></i> Auto-Failover</h6>
                    <p class="small opacity-75 mb-0">If a device goes offline or fails to send within 3 minutes, messages are <strong>automatically reassigned</strong> to the next available device.</p>
                </div>
            </div>
        </div>
        <div class="mt-3 small opacity-50">
            <i class="bi bi-lightbulb"></i> <strong>Tip:</strong> For maximum protection, install both WhatsApp and WhatsApp Business on each phone with <strong>different phone numbers</strong>. Set one device to "Personal" and another to "Business" to spread messages across 2 accounts.
        </div>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <h3 class="mb-0 text-primary"><?= count($devices) ?></h3>
                <small class="text-muted">Total Devices</small>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <?php $online = count(array_filter($devices, fn($d) => $d['is_active'] && $d['last_seen'] && ($d['last_msg_status'] ?? '') !== 'failed')); ?>
                <h3 class="mb-0 text-success"><?= $online ?></h3>
                <small class="text-muted">Online Now</small>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <?php $totalDelivered = array_sum(array_column($devices, 'delivered')); ?>
                <h3 class="mb-0"><?= number_format($totalDelivered) ?></h3>
                <small class="text-muted">Messages Delivered</small>
            </div>
        </div>
    </div>
</div>

<!-- Add a phone -->
<div class="card mb-3">
    <div class="card-header py-3"><i class="bi bi-plus-circle"></i> Add a phone</div>
    <div class="card-body">
        <form method="POST" class="row g-2 align-items-end">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="view" value="<?= $showAll ? 'all' : 'mine' ?>">
            <div class="col-md-3">
                <label class="form-label small text-muted">Phone name</label>
                <input type="text" name="device_name" class="form-control form-control-sm" placeholder="e.g. Support Samsung" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Watches</label>
                <select name="wa_type" class="form-select form-select-sm" id="addWaType" onchange="toggleAddNumbers()">
                    <option value="both">Both apps</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="whatsapp_business">Business</option>
                </select>
            </div>
            <div class="col-md-2" id="addNumWrap">
                <label class="form-label small text-muted">WhatsApp number</label>
                <input type="text" name="wa_number" class="form-control form-control-sm" placeholder="2547XXXXXXXX">
            </div>
            <div class="col-md-2" id="addBizWrap">
                <label class="form-label small text-muted">Business number</label>
                <input type="text" name="wa_business_number" class="form-control form-control-sm" placeholder="2547XXXXXXXX">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-sm btn-wa w-100"><i class="bi bi-key"></i> Add &amp; make key</button>
            </div>
        </form>
        <small class="text-muted d-block mt-2">The number(s) are this phone's own WhatsApp numbers - the line customers write to and replies leave from. Then copy the key from the table into the app on that phone.</small>
    </div>
</div>
<script>
function toggleAddNumbers() {
    var t = document.getElementById('addWaType').value;
    document.getElementById('addNumWrap').style.display = t === 'whatsapp_business' ? 'none' : '';
    document.getElementById('addBizWrap').style.display = t === 'whatsapp' ? 'none' : '';
}
function copyDevKey(btn, key) {
    navigator.clipboard.writeText(key).then(function () {
        var i = btn.querySelector('i'); i.className = 'bi bi-check-lg';
        setTimeout(function () { i.className = 'bi bi-clipboard'; }, 1500);
    });
}
</script>

<!-- Devices Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center py-3 flex-wrap gap-2">
        <span><i class="bi bi-phone-fill"></i> Registered Devices</span>
        <div class="d-flex align-items-center gap-2">
            <?php if ($isAdminUser): ?>
            <div class="btn-group btn-group-sm" role="group" aria-label="View toggle">
                <a href="devices.php?view=mine" class="btn <?= !$showAll ? 'btn-success' : 'btn-outline-secondary' ?>">
                    <i class="bi bi-person"></i> My Devices
                </a>
                <a href="devices.php?view=all" class="btn <?= $showAll ? 'btn-success' : 'btn-outline-secondary' ?>">
                    <i class="bi bi-people"></i> All Users
                </a>
            </div>
            <?php endif; ?>
            <span class="badge bg-secondary"><?= count($devices) ?> device(s)</span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($devices)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-phone" style="font-size: 48px;"></i>
            <h5 class="mt-3">No Devices Registered</h5>
            <p>Install the FreeISP Replies app on a phone and start the relay service. The device will appear here automatically.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Device</th>
                        <th>Device key</th>
                        <th>WhatsApp</th>
                        <th>Numbers</th>
                        <?php if ($showAll): ?><th>User</th><?php endif; ?>
                        <th>Delivered</th>
                        <th>Pending</th>
                        <th>Last Seen</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($devices as $dev): try {
                        $lastStatus = $dev['last_msg_status'] ?? '';
                        $isActive = $dev['is_active'] && $dev['last_seen'];
                        $shortId = substr($dev['device_id'] ?? '', 0, 8);
                    ?>
                    <tr>
                        <td>
                            <?php if (!$dev['is_active']): ?>
                                <span class="badge bg-secondary">Disabled</span>
                            <?php elseif ($lastStatus === 'failed'): ?>
                                <span class="badge bg-danger">Failed</span>
                                <small class="d-block text-danger" style="font-size:9px;">Last msg failed</small>
                            <?php elseif ($isActive): ?>
                                <span class="badge bg-success">Online</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark" title="Key not used yet - put it in the app">Waiting for app</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($dev['device_name']) ?></strong>
                            <div class="d-flex gap-1 mt-1">
                                <span class="badge bg-<?= ($dev['svc_accessibility'] ?? 0) ? 'success' : 'danger' ?> bg-opacity-75" style="font-size:9px;" title="Accessibility Service">
                                    <i class="bi bi-hand-index"></i> <?= ($dev['svc_accessibility'] ?? 0) ? 'ON' : 'OFF' ?>
                                </span>
                                <span class="badge bg-<?= ($dev['svc_notification'] ?? 0) ? 'success' : 'danger' ?> bg-opacity-75" style="font-size:9px;" title="Notification Listener">
                                    <i class="bi bi-bell"></i> <?= ($dev['svc_notification'] ?? 0) ? 'ON' : 'OFF' ?>
                                </span>
                                <span class="badge bg-<?= ($dev['svc_battery'] ?? 0) ? 'success' : 'secondary' ?> bg-opacity-75" style="font-size:9px;" title="Battery Optimization Disabled">
                                    <i class="bi bi-battery-charging"></i> <?= ($dev['svc_battery'] ?? 0) ? 'OK' : 'OFF' ?>
                                </span>
                                <?php if (!empty($dev['app_version'])): ?>
                                <span class="badge bg-dark bg-opacity-75" style="font-size:9px;" title="App version on the phone">v<?= htmlspecialchars($dev['app_version']) ?></span>
                                <?php endif; ?>
                                <?php if (isset($dev['fwd_enabled'])): ?>
                                <span class="badge bg-<?= $dev['fwd_enabled'] ? 'success' : 'danger' ?> bg-opacity-75" style="font-size:9px;" title="Forward incoming messages (Monitor card in the app)">
                                    <i class="bi bi-arrow-up-right-circle"></i> FWD <?= $dev['fwd_enabled'] ? 'ON' : 'OFF' ?>
                                </span>
                                <span class="badge bg-<?= $dev['nl_bound'] ? 'success' : 'danger' ?> bg-opacity-75" style="font-size:9px;" title="Notification listener actually bound by Android (if OFF while access is granted: toggle Notification access off/on)">
                                    <i class="bi bi-bell"></i> LISTENER <?= $dev['nl_bound'] ? 'OK' : 'NOT BOUND' ?>
                                </span>
                                <span class="badge bg-secondary bg-opacity-75" style="font-size:9px;" title="Messages seen / waiting to upload">
                                    <i class="bi bi-inbox"></i> <?= (int)$dev['inbound_seen'] ?> seen · <?= (int)$dev['inbound_queued'] ?> queued
                                </span>
                                <?php if (!empty($dev['last_notif_at'])): ?>
                                <div class="text-muted" style="font-size:10px;" title="Last WhatsApp notification the listener looked at">
                                    Last notif <?= fmtTime($dev['last_notif_at'], 'M d H:i:s') ?>: <span style="word-break:break-all;"><?= htmlspecialchars($dev['last_notif_info'] ?? '') ?></span>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($dev['device_key'])): ?>
                            <div class="d-flex align-items-center gap-1">
                                <code class="small" title="<?= htmlspecialchars($dev['device_key']) ?>"><?= htmlspecialchars(substr($dev['device_key'], 0, 8)) ?>…</code>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Copy key for the app"
                                        onclick="copyDevKey(this, '<?= htmlspecialchars($dev['device_key']) ?>')"><i class="bi bi-clipboard"></i></button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Make a new key? The old one stops working.')">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="view" value="<?= $showAll ? 'all' : 'mine' ?>">
                                    <input type="hidden" name="action" value="new_key">
                                    <input type="hidden" name="device_id" value="<?= htmlspecialchars($dev['device_id']) ?>">
                                    <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="New key"><i class="bi bi-arrow-repeat"></i></button>
                                </form>
                            </div>
                            <?php else: ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                <input type="hidden" name="view" value="<?= $showAll ? 'all' : 'mine' ?>">
                                <input type="hidden" name="action" value="new_key">
                                <input type="hidden" name="device_id" value="<?= htmlspecialchars($dev['device_id']) ?>">
                                <button class="btn btn-sm btn-outline-primary py-0 px-1" title="Make a key for this phone"><i class="bi bi-key"></i> Make key</button>
                            </form>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                <input type="hidden" name="view" value="<?= $showAll ? 'all' : 'mine' ?>">
                                <input type="hidden" name="action" value="set_wa_type">
                                <input type="hidden" name="device_id" value="<?= htmlspecialchars($dev['device_id']) ?>">
                                <select name="wa_type" class="form-select form-select-sm" style="width:130px;" onchange="this.form.submit()">
                                    <option value="both" <?= ($dev['whatsapp_type'] ?? 'both') === 'both' ? 'selected' : '' ?>>Both</option>
                                    <option value="whatsapp" <?= ($dev['whatsapp_type'] ?? '') === 'whatsapp' ? 'selected' : '' ?>>Personal</option>
                                    <option value="whatsapp_business" <?= ($dev['whatsapp_type'] ?? '') === 'whatsapp_business' ? 'selected' : '' ?>>Business</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <?php $wt = $dev['whatsapp_type'] ?? 'both'; ?>
                            <form method="POST" class="d-flex flex-column gap-1" style="width:150px;">
                                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                <input type="hidden" name="view" value="<?= $showAll ? 'all' : 'mine' ?>">
                                <input type="hidden" name="action" value="set_numbers">
                                <input type="hidden" name="device_id" value="<?= htmlspecialchars($dev['device_id']) ?>">
                                <?php if ($wt !== 'whatsapp_business'): ?>
                                <input type="text" name="wa_number" class="form-control form-control-sm" placeholder="WhatsApp no."
                                       title="Number of the WhatsApp app on this phone" value="<?= htmlspecialchars($dev['wa_number'] ?? '') ?>" onchange="this.form.submit()">
                                <?php else: ?><input type="hidden" name="wa_number" value="<?= htmlspecialchars($dev['wa_number'] ?? '') ?>"><?php endif; ?>
                                <?php if ($wt !== 'whatsapp'): ?>
                                <input type="text" name="wa_business_number" class="form-control form-control-sm" placeholder="Business no."
                                       title="Number of WhatsApp Business on this phone" value="<?= htmlspecialchars($dev['wa_business_number'] ?? '') ?>" onchange="this.form.submit()">
                                <?php else: ?><input type="hidden" name="wa_business_number" value="<?= htmlspecialchars($dev['wa_business_number'] ?? '') ?>"><?php endif; ?>
                            </form>
                        </td>
                        <?php if ($showAll): ?>
                        <td class="small"><?= htmlspecialchars($dev['user_name'] ?? 'N/A') ?></td>
                        <?php endif; ?>
                        <td class="text-success"><?= number_format($dev['delivered']) ?></td>
                        <td><?= $dev['pending_msgs'] ?></td>
                        <td class="small text-muted">
                            <?= $dev['last_seen'] ? fmtTime($dev['last_seen'], 'M d H:i:s') : 'Never' ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <!-- Rename -->
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="view" value="<?= $showAll ? 'all' : 'mine' ?>">
                                    <input type="hidden" name="action" value="rename">
                                    <input type="hidden" name="device_id" value="<?= htmlspecialchars($dev['device_id']) ?>">
                                    <input type="text" name="new_name" placeholder="New name" class="form-control form-control-sm d-inline-block" style="width:110px;" value="<?= htmlspecialchars($dev['device_name']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Rename"><i class="bi bi-pencil"></i></button>
                                </form>

                                <!-- Toggle -->
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="view" value="<?= $showAll ? 'all' : 'mine' ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="device_id" value="<?= htmlspecialchars($dev['device_id']) ?>">
                                    <button type="submit" class="btn btn-sm <?= $dev['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $dev['is_active'] ? 'Disable' : 'Enable' ?>">
                                        <i class="bi bi-<?= $dev['is_active'] ? 'pause' : 'play' ?>-fill"></i>
                                    </button>
                                </form>

                                <!-- Delete -->
                                <form method="POST" class="d-inline" onsubmit="return confirm('Remove this device?')">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="view" value="<?= $showAll ? 'all' : 'mine' ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="device_id" value="<?= htmlspecialchars($dev['device_id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php } catch (Exception $e) { error_log("Device row error: " . $e->getMessage() . " | device_id=" . ($dev['device_id'] ?? 'null')); echo '<tr><td colspan="9" class="text-danger small">Error rendering device: ' . htmlspecialchars($e->getMessage()) . '</td></tr>'; } endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body small">
        <h6 class="fw-bold mb-3"><i class="bi bi-info-circle"></i> Status & Badge Guide</h6>
        <div class="row g-3">
            <div class="col-md-6">
                <p class="fw-bold mb-2">Device Status</p>
                <table class="table table-sm mb-0">
                    <tr><td><span class="badge bg-success">Online</span></td><td>Device is active. Last message was delivered successfully.</td></tr>
                    <tr><td><span class="badge bg-danger">Failed</span></td><td>Last message failed. Check the phone's WhatsApp and permissions.</td></tr>
                    <tr><td><span class="badge bg-secondary">Disabled</span></td><td>Manually disabled. Will not receive any messages.</td></tr>
                    <tr><td><span class="badge bg-warning text-dark" title="Key not used yet - put it in the app">Waiting for app</span></td><td>Just registered. Start the relay service on the phone.</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <p class="fw-bold mb-2">Service Badges (under device name)</p>
                <table class="table table-sm mb-0">
                    <tr><td><span class="badge bg-success" style="font-size:10px;"><i class="bi bi-hand-index"></i> ON</span></td><td><strong>Accessibility Service</strong> - required for new contacts. If OFF, can't auto-tap send.</td></tr>
                    <tr><td><span class="badge bg-success" style="font-size:10px;"><i class="bi bi-bell"></i> ON</span></td><td><strong>Notification Listener</strong> - enables silent background sending. If OFF, WhatsApp opens on screen.</td></tr>
                    <tr><td><span class="badge bg-success" style="font-size:10px;"><i class="bi bi-battery-charging"></i> OK</span></td><td><strong>Battery Optimization</strong> - keeps app running in background. If OFF, service may stop.</td></tr>
                    <tr><td><span class="badge bg-danger" style="font-size:10px;"><i class="bi bi-hand-index"></i> OFF</span></td><td>Service not enabled. Open the app on the phone to enable it.</td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
