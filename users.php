<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
requireAdmin();

$db = getDB();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? '')) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'user';

            if ($name && $email && $password) {
                // Check duplicate
                $check = $db->prepare('SELECT id FROM users WHERE email = ?');
                $check->execute([$email]);
                if ($check->fetch()) {
                    flash('Email already exists', 'error');
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $db->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)')
                       ->execute([$name, $email, $hash, $role]);
                    $newUserId = $db->lastInsertId();

                    // Create a default API key for the user
                    $db->prepare('INSERT INTO api_keys (user_id, api_key, label) VALUES (?, ?, ?)')
                       ->execute([$newUserId, generateApiKey(), 'Default Key']);

                    flash("User '$name' created with a default API key");
                }
            } else {
                flash('All fields are required', 'error');
            }
            break;

        case 'toggle':
            $toggleId = intval($_POST['user_id'] ?? 0);
            if ($toggleId !== $_SESSION['user_id']) { // Can't disable yourself
                $db->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?')->execute([$toggleId]);
                flash('User status updated');
            }
            break;

        case 'delete':
            $deleteId = intval($_POST['user_id'] ?? 0);
            if ($deleteId !== $_SESSION['user_id']) {
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$deleteId]);
                flash('User deleted');
            }
            break;

        case 'reset_password':
            $resetId = intval($_POST['user_id'] ?? 0);
            $newPass = $_POST['new_password'] ?? '';
            if ($newPass) {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $resetId]);
                flash('Password reset successfully');
            }
            break;
    }

    header('Location: users.php');
    exit;
}

// Fetch users with their stats
$users = $db->query("
    SELECT u.*,
           COUNT(DISTINCT ak.id) as key_count,
           COUNT(DISTINCT m.id) as message_count,
           SUM(CASE WHEN m.status = 'delivered' THEN 1 ELSE 0 END) as delivered_count
    FROM users u
    LEFT JOIN api_keys ak ON ak.user_id = u.id
    LEFT JOIN messages m ON m.user_id = u.id
    GROUP BY u.id
    ORDER BY u.created_at DESC
")->fetchAll();

renderHeader('Users', 'users');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <span class="text-muted"><?= count($users) ?> users</span>
    <button class="btn btn-wa" data-bs-toggle="modal" data-bs-target="#createUserModal">
        <i class="bi bi-person-plus"></i> Add User
    </button>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>API Keys</th>
                        <th>Messages</th>
                        <th>Delivered</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($u['name']) ?></strong>
                            <?php if ($u['id'] == $_SESSION['user_id']): ?>
                            <span class="badge bg-info">You</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td>
                            <span class="badge bg-<?= $u['role'] === 'admin' ? 'danger' : 'secondary' ?>">
                                <?= ucfirst($u['role']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?= $u['is_active'] ? 'success' : 'secondary' ?>">
                                <?= $u['is_active'] ? 'Active' : 'Disabled' ?>
                            </span>
                        </td>
                        <td><?= $u['key_count'] ?></td>
                        <td><?= number_format($u['message_count']) ?></td>
                        <td><?= number_format($u['delivered_count']) ?></td>
                        <td class="small text-muted"><?= $u['last_login'] ? date('M d H:i', strtotime($u['last_login'])) : 'Never' ?></td>
                        <td>
                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                            <div class="btn-group btn-group-sm">
                                <!-- Toggle -->
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-outline-<?= $u['is_active'] ? 'warning' : 'success' ?>" title="<?= $u['is_active'] ? 'Disable' : 'Enable' ?>">
                                        <i class="bi bi-<?= $u['is_active'] ? 'pause' : 'play' ?>"></i>
                                    </button>
                                </form>
                                <!-- Reset password -->
                                <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#resetModal-<?= $u['id'] ?>" title="Reset Password">
                                    <i class="bi bi-key"></i>
                                </button>
                                <!-- Delete -->
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this user and all their data?')">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>

                            <!-- Reset Password Modal -->
                            <div class="modal fade" id="resetModal-<?= $u['id'] ?>">
                                <div class="modal-dialog modal-sm">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <div class="modal-header">
                                                <h6 class="modal-title">Reset Password</h6>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p class="small text-muted">Reset password for <?= htmlspecialchars($u['name']) ?></p>
                                                <input type="password" name="new_password" class="form-control" placeholder="New password" required minlength="6">
                                            </div>
                                            <div class="modal-footer">
                                                <button type="submit" class="btn btn-warning btn-sm">Reset</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal fade" id="createUserModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select">
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-wa">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
