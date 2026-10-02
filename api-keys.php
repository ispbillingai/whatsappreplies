<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $label = trim($_POST['label'] ?? 'New Key');
            if (empty($label)) $label = 'New Key';
            $newKey = generateApiKey();
            $db->prepare('INSERT INTO api_keys (user_id, api_key, label) VALUES (?, ?, ?)')
               ->execute([$userId, $newKey, $label]);
            flash("API Key created! Key: $newKey — copy it now, it won't be shown again in full.");
            break;

        case 'toggle':
            $keyId = intval($_POST['key_id'] ?? 0);
            $db->prepare('UPDATE api_keys SET is_active = NOT is_active WHERE id = ? AND user_id = ?')
               ->execute([$keyId, $userId]);
            flash('API Key status updated');
            break;

        case 'delete':
            $keyId = intval($_POST['key_id'] ?? 0);
            $db->prepare('DELETE FROM api_keys WHERE id = ? AND user_id = ?')
               ->execute([$keyId, $userId]);
            flash('API Key deleted');
            break;

        case 'rename':
            $keyId = intval($_POST['key_id'] ?? 0);
            $label = trim($_POST['label'] ?? '');
            if (!empty($label)) {
                $db->prepare('UPDATE api_keys SET label = ? WHERE id = ? AND user_id = ?')
                   ->execute([$label, $keyId, $userId]);
                flash('API Key renamed');
            }
            break;
    }

    header('Location: api-keys.php');
    exit;
}

// Fetch keys
$stmt = $db->prepare('SELECT * FROM api_keys WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$userId]);
$apiKeys = $stmt->fetchAll();

renderHeader('API Keys', 'apikeys');
?>

<div class="row">
    <div class="col-lg-8">
        <!-- Create new key -->
        <div class="card mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-key"></i> Your API Keys</span>
                <button class="btn btn-sm btn-wa" data-bs-toggle="modal" data-bs-target="#createKeyModal">
                    <i class="bi bi-plus"></i> Create New Key
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($apiKeys)): ?>
                <div class="empty-state">
                    <i class="bi bi-key"></i>
                    <h5>No API keys yet</h5>
                    <p>Create an API key to start sending messages via the API.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>API Key</th>
                                <th>Status</th>
                                <th>Requests</th>
                                <th>Last Used</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($apiKeys as $key): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($key['label']) ?></strong>
                                </td>
                                <td>
                                    <code class="user-select-all" id="key-<?= $key['id'] ?>"><?= substr($key['api_key'], 0, 12) ?>...<?= substr($key['api_key'], -4) ?></code>
                                    <button class="btn btn-sm btn-link p-0 ms-1" onclick="copyKey('<?= htmlspecialchars($key['api_key']) ?>')" title="Copy full key">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </td>
                                <td>
                                    <?php if ($key['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Disabled</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($key['request_count']) ?></td>
                                <td class="small text-muted"><?= $key['last_used_at'] ? date('M d H:i', strtotime($key['last_used_at'])) : 'Never' ?></td>
                                <td class="small text-muted"><?= date('M d, Y', strtotime($key['created_at'])) ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <!-- Toggle -->
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                                            <button type="submit" class="btn btn-outline-<?= $key['is_active'] ? 'warning' : 'success' ?>" title="<?= $key['is_active'] ? 'Disable' : 'Enable' ?>">
                                                <i class="bi bi-<?= $key['is_active'] ? 'pause' : 'play' ?>"></i>
                                            </button>
                                        </form>
                                        <!-- Delete -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this API key? Any apps using it will stop working.')">
                                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- API Usage Guide -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header py-3">
                <i class="bi bi-code-slash"></i> Quick API Guide
            </div>
            <div class="card-body">
                <h6>Send a message</h6>
                <pre class="bg-dark text-light p-3 rounded small"><code>curl -X POST <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'yourserver.com') ?>/api.php/send \
  -H "Content-Type: application/json" \
  -H "X-API-Key: YOUR_KEY" \
  -d '{
    "phone": "27812345678",
    "message": "Hello!",
    "whatsapp_type": "whatsapp"
  }'</code></pre>

                <h6 class="mt-3">Check pending</h6>
                <pre class="bg-dark text-light p-3 rounded small"><code>curl -H "X-API-Key: YOUR_KEY" \
  <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'yourserver.com') ?>/api.php/pending</code></pre>

                <h6 class="mt-3">Headers</h6>
                <p class="small text-muted mb-1">All requests need:</p>
                <code class="small">X-API-Key: your-api-key</code>

                <h6 class="mt-3">Response</h6>
                <pre class="bg-dark text-light p-3 rounded small"><code>{
  "success": true,
  "message_id": 42,
  "status": "pending"
}</code></pre>
            </div>
        </div>
    </div>
</div>

<!-- Create Key Modal -->
<div class="modal fade" id="createKeyModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Create API Key</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Label</label>
                        <input type="text" name="label" class="form-control" placeholder="e.g. Production Server, My App" required>
                        <div class="form-text">A friendly name to identify this key.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-wa">Create Key</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function copyKey(key) {
    navigator.clipboard.writeText(key).then(() => {
        alert('API Key copied to clipboard!');
    });
}
</script>

<?php renderFooter(); ?>
