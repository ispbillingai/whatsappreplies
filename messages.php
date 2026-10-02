<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];
$isAdminUser = isAdmin();


// Filters
$filterStatus = $_GET['status'] ?? '';
$filterPhone = $_GET['phone'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterDate = $_GET['date'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Build query
$where = [];
$params = [];

if (!$isAdminUser) {
    $where[] = 'm.user_id = ?';
    $params[] = $userId;
}
if ($filterStatus) {
    $where[] = 'm.status = ?';
    $params[] = $filterStatus;
}
if ($filterPhone) {
    $where[] = 'm.phone LIKE ?';
    $params[] = '%' . preg_replace('/[^0-9]/', '', $filterPhone) . '%';
}
if ($filterType) {
    $where[] = 'm.whatsapp_type = ?';
    $params[] = $filterType;
}
if ($filterDate) {
    $where[] = 'DATE(m.created_at) = ?';
    $params[] = $filterDate;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$stmt = $db->prepare("SELECT COUNT(*) as total FROM messages m $whereClause");
$stmt->execute($params);
$total = $stmt->fetch()['total'];
$totalPages = ceil($total / $perPage);

// Fetch messages
$params[] = $perPage;
$params[] = $offset;
$stmt = $db->prepare("SELECT m.*, u.name as user_name, d.device_name FROM messages m LEFT JOIN users u ON m.user_id = u.id LEFT JOIN devices d ON m.device_id = d.device_id $whereClause ORDER BY m.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute($params);
$messages = $stmt->fetchAll();

// Handle retry action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retry_id'])) {
    if (verifyCsrf($_POST['csrf'] ?? '')) {
        $retryId = intval($_POST['retry_id']);
        $check = $isAdminUser
            ? $db->prepare("SELECT id FROM messages WHERE id = ? AND status IN ('failed', 'expired')")
            : $db->prepare("SELECT id FROM messages WHERE id = ? AND status IN ('failed', 'expired') AND user_id = ?");
        $checkParams = $isAdminUser ? [$retryId] : [$retryId, $userId];
        $check->execute($checkParams);
        if ($check->fetch()) {
            $db->prepare("UPDATE messages SET status = 'pending', retry_count = 0, error_message = NULL, created_at = NOW() WHERE id = ?")->execute([$retryId]);
            flash('Message #' . $retryId . ' queued for retry');
        }
    }
    header('Location: messages.php?' . http_build_query($_GET));
    exit;
}

renderHeader('Messages', 'messages');
?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small text-muted">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="sent" <?= $filterStatus === 'sent' ? 'selected' : '' ?>>Sent</option>
                    <option value="delivered" <?= $filterStatus === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                    <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
                    <option value="expired" <?= $filterStatus === 'expired' ? 'selected' : '' ?>>Expired</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Phone</label>
                <input type="text" name="phone" class="form-control form-control-sm" placeholder="Search phone..."
                       value="<?= htmlspecialchars($filterPhone) ?>">
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
                <label class="form-label small text-muted">Date</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDate) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-wa w-100"><i class="bi bi-funnel"></i> Filter</button>
            </div>
            <div class="col-md-2">
                <a href="messages.php" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-x"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Results info -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="text-muted small">Showing <?= count($messages) ?> of <?= number_format($total) ?> messages</span>
    <a href="send.php" class="btn btn-sm btn-wa"><i class="bi bi-plus"></i> Send New</a>
</div>

<!-- Messages Table -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($messages)): ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <h5>No messages found</h5>
            <p>Try adjusting your filters or send a new message.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Phone</th>
                        <th>Message</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Retries</th>
                        <th>Device</th>
                        <th>Created</th>
                        <th>Sent At</th>
                        <?php if ($isAdminUser): ?><th>User</th><?php endif; ?>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $msg): try { ?>
                    <tr>
                        <td><strong>#<?= $msg['id'] ?></strong></td>
                        <td class="text-nowrap"><i class="bi bi-telephone"></i> <?= htmlspecialchars($msg['phone']) ?></td>
                        <td>
                            <div class="msg-preview" title="<?= htmlspecialchars($msg['message']) ?>">
                                <?= htmlspecialchars($msg['message']) ?>
                            </div>
                            <?php if ($msg['error_message']): ?>
                            <small class="text-danger d-block"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($msg['error_message']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $msg['whatsapp_type'] === 'whatsapp_business' ? 'info' : 'success' ?> bg-opacity-75">
                                <?= $msg['whatsapp_type'] === 'whatsapp_business' ? 'Biz' : 'WA' ?>
                            </span>
                        </td>
                        <td><span class="badge-status badge-<?= $msg['status'] ?>"><?= ucfirst($msg['status']) ?></span></td>
                        <td class="text-center"><?= $msg['retry_count'] ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($msg['device_name'] ?? '-') ?></td>
                        <td class="text-muted small text-nowrap"><?= fmtTime($msg['created_at']) ?></td>
                        <td class="text-muted small"><?= fmtTime($msg['sent_at']) ?></td>
                        <?php if ($isAdminUser): ?>
                        <td class="small"><?= htmlspecialchars($msg['user_name'] ?? 'N/A') ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if ($msg['status'] === 'failed' || $msg['status'] === 'expired'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                <input type="hidden" name="retry_id" value="<?= $msg['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Retry">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php } catch (Exception $e) { error_log("Message row error: " . $e->getMessage() . " | msg_id=" . ($msg['id'] ?? 'null')); echo '<tr><td colspan="10" class="text-danger small">Error: ' . htmlspecialchars($e->getMessage()) . '</td></tr>'; } endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php renderFooter(); ?>
