<?php
/**
 * Shared layout components
 */

function renderHeader($title, $activePage = '') {
    $user = getCurrentUser();
    $flash = getFlash();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - <?= APP_NAME ?></title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --wa-green: #075E54;
            --wa-teal: #128C7E;
            --wa-light-green: #25D366;
            --wa-chat-bg: #ECE5DD;
        }
        body { background: #f0f2f5; font-family: 'Segoe UI', system-ui, sans-serif; }
        .sidebar {
            position: fixed; top: 0; left: 0; bottom: 0; width: 250px;
            background: var(--wa-green); color: white; z-index: 100;
            transition: transform 0.3s;
        }
        .sidebar .brand {
            padding: 20px; font-size: 22px; font-weight: 700;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar .brand i { color: var(--wa-light-green); }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.7); padding: 12px 20px;
            border-left: 3px solid transparent; transition: all 0.2s;
        }
        .sidebar .nav-link:hover { color: white; background: rgba(255,255,255,0.05); }
        .sidebar .nav-link.active {
            color: white; background: rgba(255,255,255,0.1);
            border-left-color: var(--wa-light-green);
        }
        .sidebar .nav-link i { width: 24px; text-align: center; margin-right: 10px; }
        .main-content { margin-left: 250px; padding: 0; }
        .topbar {
            background: white; padding: 15px 30px;
            border-bottom: 1px solid #e0e0e0;
            display: flex; justify-content: space-between; align-items: center;
        }
        .topbar h4 { margin: 0; color: #333; }
        .content-body { padding: 30px; }
        .stat-card {
            border: none; border-radius: 12px; overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card .card-body { padding: 20px; }
        .stat-card .stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
        }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; }
        .stat-card .stat-label { font-size: 13px; color: #888; }
        .badge-status {
            padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;
        }
        .badge-pending { background: #fff3e0; color: #e65100; }
        .badge-sent { background: #e3f2fd; color: #1565c0; }
        .badge-delivered { background: #e8f5e9; color: #2e7d32; }
        .badge-failed { background: #ffebee; color: #c62828; }
        .badge-expired { background: #f3e5f5; color: #6a1b9a; }
        .table th { font-size: 12px; text-transform: uppercase; color: #888; letter-spacing: 0.5px; border-bottom-width: 1px; }
        .table td { vertical-align: middle; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        .card-header { background: white; border-bottom: 1px solid #f0f0f0; font-weight: 600; }
        .btn-wa { background: var(--wa-light-green); color: white; border: none; }
        .btn-wa:hover { background: var(--wa-teal); color: white; }
        .sidebar-footer { position: absolute; bottom: 0; width: 100%; padding: 15px 20px; border-top: 1px solid rgba(255,255,255,0.1); }
        .user-info { font-size: 13px; }
        .user-info .name { color: white; font-weight: 600; }
        .user-info .role { color: rgba(255,255,255,0.5); font-size: 11px; text-transform: uppercase; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .main-content { margin-left: 0; }
        }
        .msg-preview { max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state i { font-size: 48px; margin-bottom: 15px; display: block; }

        /* Dark mode */
        body.dark-mode { background: #1a1a2e; color: #e0e0e0; }
        body.dark-mode .main-content { background: #1a1a2e; }
        body.dark-mode .topbar { background: #16213e; border-bottom-color: #2a2a4a; }
        body.dark-mode .topbar h4 { color: #e0e0e0; }
        body.dark-mode .content-body { background: #1a1a2e; }
        body.dark-mode .card { background: #16213e !important; border-color: #2a2a4a !important; box-shadow: 0 2px 12px rgba(0,0,0,0.3); color: #e0e0e0; }
        body.dark-mode .card-header { background: #1a1a3e !important; border-bottom-color: #2a2a4a !important; color: #e0e0e0 !important; }
        body.dark-mode .card-body { color: #e0e0e0; }
        body.dark-mode .table { color: #ddd; --bs-table-bg: transparent; --bs-table-striped-bg: rgba(255,255,255,0.03); --bs-table-hover-bg: rgba(255,255,255,0.06); }
        body.dark-mode .table th { color: #999; border-bottom-color: #2a2a4a; }
        body.dark-mode .table td { border-bottom-color: #2a2a4a; color: #ddd; }
        body.dark-mode .stat-card { background: #16213e; }
        body.dark-mode .stat-card .stat-value { color: #fff; }
        body.dark-mode .stat-card .stat-label { color: #999; }
        body.dark-mode .text-muted { color: #999 !important; }
        body.dark-mode .text-dark { color: #ddd !important; }
        body.dark-mode p, body.dark-mode li, body.dark-mode span, body.dark-mode label, body.dark-mode small { color: #ccc; }
        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3, body.dark-mode h4, body.dark-mode h5, body.dark-mode h6 { color: #eee; }
        body.dark-mode strong { color: #fff; }
        body.dark-mode .form-control, body.dark-mode .form-select { background: #0f3460; border-color: #2a2a4a; color: #e0e0e0; }
        body.dark-mode .form-label { color: #ccc; }
        body.dark-mode .input-group-text { background: #0f3460; border-color: #2a2a4a; color: #aaa; }
        body.dark-mode .sidebar { background: #0f3460; }
        body.dark-mode .sidebar .nav-link { color: rgba(255,255,255,0.7); }
        body.dark-mode .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.1); }
        body.dark-mode .btn-outline-secondary { color: #bbb; border-color: #555; }
        body.dark-mode .btn-outline-secondary:hover { background: rgba(255,255,255,0.1); color: #fff; }
        body.dark-mode .alert { background: #1a1a3e; border-color: #2a2a4a; color: #ddd; }
        body.dark-mode .alert-warning { background: #2d2215; border-color: #4d3d20; color: #ffcc80; }
        body.dark-mode .alert-info { background: #152030; border-color: #203850; color: #90caf9; }
        body.dark-mode .border-danger { border-color: #5a2020 !important; }
        body.dark-mode .bg-light { background: #1a1a3e !important; color: #ddd; }
        body.dark-mode .bg-white { background: #16213e !important; }
        body.dark-mode .badge-pending { background: #e65100; color: #fff; }
        body.dark-mode .badge-sent { background: #1565c0; color: #fff; }
        body.dark-mode .badge-delivered { background: #2e7d32; color: #fff; }
        body.dark-mode .badge-failed { background: #c62828; color: #fff; }
        body.dark-mode .badge-expired { background: #6a1b9a; color: #fff; }
        body.dark-mode .accordion-button { background: #16213e; color: #ddd; }
        body.dark-mode .accordion-body { background: #1a1a2e; color: #ccc; }
        body.dark-mode code { color: #7ec8e3; }
        body.dark-mode pre { background: #0a0a1a !important; }
        body.dark-mode .modal-content { background: #16213e; color: #e0e0e0; }
        body.dark-mode .modal-header { border-bottom-color: #2a2a4a; }
        body.dark-mode .btn-close { filter: invert(1); }
        body.dark-mode a:not(.btn):not(.nav-link) { color: #64b5f6; }
        body.dark-mode .page-link { background: #16213e; border-color: #2a2a4a; color: #ddd; }
    </style>
    <script>
    // Dark mode persistence
    (function() {
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark-mode-preload');
        }
    })();
    </script>
    <style>
        html.dark-mode-preload body { background: #1a1a2e; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="brand">
            <img src="logo.png" alt="FreeISP" style="height:32px; margin-right:8px; vertical-align:middle;">
            <?= APP_NAME ?>
        </div>
        <div class="mt-3">
            <a href="dashboard.php" class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>
            <a href="messages.php" class="nav-link <?= $activePage === 'messages' ? 'active' : '' ?>">
                <i class="bi bi-chat-dots-fill"></i> Messages
            </a>
            <a href="inbox.php" class="nav-link <?= $activePage === 'inbox' ? 'active' : '' ?>">
                <i class="bi bi-inbox-fill"></i> Inbox
            </a>
            <a href="send.php" class="nav-link <?= $activePage === 'send' ? 'active' : '' ?>">
                <i class="bi bi-send-fill"></i> Send Message
            </a>
            <a href="devices.php" class="nav-link <?= $activePage === 'devices' ? 'active' : '' ?>">
                <i class="bi bi-phone-fill"></i> Devices
            </a>
            <?php if (isAdmin()): ?>
            <a href="users.php" class="nav-link <?= $activePage === 'users' ? 'active' : '' ?>">
                <i class="bi bi-people-fill"></i> Users
            </a>
            <a href="server-status.php" class="nav-link <?= $activePage === 'server-status' ? 'active' : '' ?>">
                <i class="bi bi-hdd-rack"></i> Server Status
            </a>
            <?php endif; ?>
            <a href="installation.php" class="nav-link <?= $activePage === 'installation' ? 'active' : '' ?>">
                <i class="bi bi-download"></i> Installation Guide
            </a>
            <a href="settings.php" class="nav-link <?= $activePage === 'settings' ? 'active' : '' ?>">
                <i class="bi bi-gear-fill"></i> Settings
            </a>
        </div>
        <div class="sidebar-footer">
            <div class="user-info">
                <div class="name"><i class="bi bi-person-circle"></i> <?= htmlspecialchars($user['name'] ?? 'User') ?></div>
                <div class="role"><?= htmlspecialchars($user['role'] ?? 'user') ?></div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="topbar">
            <div>
                <button class="btn btn-sm d-md-none me-2" onclick="document.getElementById('sidebar').classList.toggle('show')">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <h4 class="d-inline"><?= htmlspecialchars($title) ?></h4>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted small"><?= date('M d, Y H:i') ?></span>
                <button class="btn btn-sm btn-outline-secondary" onclick="toggleDarkMode()" title="Toggle Dark/Light Mode" id="darkModeBtn">
                    <i class="bi bi-moon-fill" id="darkModeIcon"></i>
                </button>
                <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>

        <div class="content-body">
            <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
    <?php
}

function renderFooter() {
    ?>
        </div><!-- /content-body -->
    </div><!-- /main-content -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function toggleDarkMode() {
        var isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('darkMode', isDark);
        var icon = document.getElementById('darkModeIcon');
        icon.className = isDark ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
    }
    // Apply on load
    (function() {
        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark-mode');
            var icon = document.getElementById('darkModeIcon');
            if (icon) icon.className = 'bi bi-sun-fill';
        }
    })();
    </script>
</body>
</html>
    <?php
}
