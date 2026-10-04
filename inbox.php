<?php
// Incoming WhatsApp messages the phones forwarded to us, with the state of
// each one's push to the webhook (the support panel). Retry re-sends one push.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/webhook_lib.php';
require_once __DIR__ . '/media_preview.php';
requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];
$isAdminUser = isAdmin();

// Filters
$filterPhone  = trim($_GET['phone'] ?? '');
$filterType   = $_GET['type'] ?? '';
$filterHook   = $_GET['webhook'] ?? '';
$filterDate   = $_GET['date'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? '')) {
    $id = intval($_POST['retry_id'] ?? 0);
    if ($id) {
        $sel = $isAdminUser
            ? $db->prepare('SELECT * FROM incoming_messages WHERE id = ?')
            : $db->prepare('SELECT * FROM incoming_messages WHERE id = ? AND user_id = ?');
        $sel->execute($isAdminUser ? [$id] : [$id, $userId]);
        if ($row = $sel->fetch()) {
            $ok = deliverIncomingWebhook($row);
            flash($ok ? "Message #$id pushed to the webhook" : "Webhook refused message #$id - see the response column", $ok ? 'success' : 'error');
        }
    }
    header('Location: inbox.php?' . http_build_query($_GET));
    exit;
}

$where = []; $params = [];
if (!$isAdminUser) { $where[] = 'i.user_id = ?'; $params[] = $userId; }
if ($filterPhone !== '') { $where[] = 'i.phone LIKE ?'; $params[] = '%' . preg_replace('/[^0-9]/', '', $filterPhone) . '%'; }
if (in_array($filterType, ['whatsapp', 'whatsapp_business'], true)) { $where[] = 'i.whatsapp_type = ?'; $params[] = $filterType; }
if (in_array($filterHook, ['pending', 'sent', 'failed', 'skipped'], true)) { $where[] = 'i.webhook_status = ?'; $params[] = $filterHook; }
if ($filterDate) { $where[] = 'DATE(i.received_at) = ?'; $params[] = $filterDate; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = $db->prepare("SELECT COUNT(*) FROM incoming_messages i $whereSql");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();

$stmt = $db->prepare(
    "SELECT i.*, d.device_name, media.filename AS media_filename
     FROM incoming_messages i LEFT JOIN devices d ON d.device_id = i.device_id
     LEFT JOIN media ON media.id = i.media_id AND media.user_id = i.user_id
     $whereSql ORDER BY i.id DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$totalPages = max(1, (int)ceil($total / $perPage));

renderHeader('Inbox', 'inbox');
?>

<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small text-muted">Phone</label>
                <input type="text" name="phone" class="form-control form-control-sm" placeholder="Search phone..." value="<?= htmlspecialchars($filterPhone) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">WhatsApp</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="whatsapp" <?= $filterType === 'whatsapp' ? 'selected' : '' ?>>Personal</option>
                    <option value="whatsapp_business" <?= $filterType === 'whatsapp_business' ? 'selected' : '' ?>>Business</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Webhook</label>
                <select name="webhook" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <option value="sent" <?= $filterHook === 'sent' ? 'selected' : '' ?>>Delivered</option>
                    <option value="failed" <?= $filterHook === 'failed' ? 'selected' : '' ?>>Failed</option>
                    <option value="pending" <?= $filterHook === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="skipped" <?= $filterHook === 'skipped' ? 'selected' : '' ?>>Skipped</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Date</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDate) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-wa w-100"><i class="bi bi-funnel"></i> Filter</button>
            </div>
            <div class="col-md-2">
                <a href="inbox.php" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-x"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="text-muted small">Showing <?= count($rows) ?> of <?= number_format($total) ?> incoming messages</span>
    <a href="settings.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left-right"></i> Webhook settings</a>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <h5>No incoming messages yet</h5>
            <p>Once a phone running the app has Notification access on and is monitoring WhatsApp, new chats show up here.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Received</th>
                        <th>From</th>
                        <th>Message</th>
                        <th>Via</th>
                        <th>Webhook</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $hookClass = ['sent' => 'success', 'failed' => 'danger', 'pending' => 'warning', 'skipped' => 'secondary'][$r['webhook_status']] ?? 'secondary';
                    $hookLabel = $r['webhook_status'] === 'sent' ? 'delivered' : $r['webhook_status'];
                ?>
                    <tr>
                        <td class="text-muted small"><?= (int)$r['id'] ?></td>
                        <td class="small text-nowrap"><?= fmtTime($r['received_at'], 'M d H:i:s') ?></td>
                        <td>
                            <div class="fw-semibold small"><?= htmlspecialchars($r['sender_name'] ?: 'Unknown') ?></div>
                            <code class="small"><?= htmlspecialchars($r['phone']) ?></code>
                            <?php if (!empty($r['is_lid'])): ?><span class="badge bg-secondary" title="WhatsApp hid this contact's number; this is its internal id">number hidden</span><?php endif; ?>
                        </td>
                        <td class="small" style="max-width:420px; word-break:break-word;">
                            <?php renderMessageMedia($r); ?>
                            <div style="white-space:pre-wrap"><?= htmlspecialchars(mb_strimwidth($r['message'], 0, 400, '…')) ?></div>
                        </td>
                        <td class="small">
                            <span class="badge <?= $r['whatsapp_type'] === 'whatsapp_business' ? 'bg-primary' : 'bg-success' ?> bg-opacity-75">
                                <?= $r['whatsapp_type'] === 'whatsapp_business' ? 'Business' : 'WhatsApp' ?>
                            </span>
                            <div class="text-muted"><?= htmlspecialchars($r['device_name'] ?? substr($r['device_id'], 0, 8)) ?><?= $r['line'] ? ' · +' . htmlspecialchars($r['line']) : '' ?></div>
                        </td>
                        <td class="small">
                            <span class="badge bg-<?= $hookClass ?>"><?= htmlspecialchars($hookLabel) ?></span>
                            <?php if ($r['webhook_attempts']): ?><span class="text-muted">×<?= (int)$r['webhook_attempts'] ?></span><?php endif; ?>
                            <?php if ($r['webhook_response'] && $r['webhook_status'] !== 'sent'): ?>
                                <div class="text-muted" style="max-width:220px; word-break:break-word;" title="<?= htmlspecialchars($r['webhook_response']) ?>">
                                    <?= htmlspecialchars(mb_strimwidth($r['webhook_response'], 0, 90, '…')) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['webhook_status'] !== 'sent'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                <input type="hidden" name="retry_id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-primary" title="Push to webhook again"><i class="bi bi-arrow-repeat"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php renderFooter(); ?>
