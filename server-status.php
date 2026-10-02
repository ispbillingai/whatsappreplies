<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
requireLogin();
requireAdmin();

$db = getDB();

// System info
$loadAvg = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
$memInfo = @file_get_contents('/proc/meminfo');
$memTotal = 0; $memFree = 0; $memAvailable = 0;
if ($memInfo) {
    preg_match('/MemTotal:\s+(\d+)/', $memInfo, $m); $memTotal = ($m[1] ?? 0) / 1024;
    preg_match('/MemFree:\s+(\d+)/', $memInfo, $m); $memFree = ($m[1] ?? 0) / 1024;
    preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $m); $memAvailable = ($m[1] ?? 0) / 1024;
}
$memUsed = $memTotal - $memAvailable;
$memPercent = $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 1) : 0;

// CPU info
$cpuInfo = @file_get_contents('/proc/cpuinfo');
$cpuCores = $cpuInfo ? preg_match_all('/^processor/m', $cpuInfo) : 1;

// Real CPU usage % — sample /proc/stat twice with a 200ms delta.
// sys_getloadavg() returns load average, not CPU%, so we compute it ourselves.
function readCpuStat() {
    $stat = @file_get_contents('/proc/stat');
    if (!$stat || !preg_match('/^cpu\s+(.+)$/m', $stat, $m)) return null;
    $v = array_map('intval', preg_split('/\s+/', trim($m[1])));
    return ['idle' => ($v[3] ?? 0) + ($v[4] ?? 0), 'total' => array_sum($v)];
}
$_a = readCpuStat();
usleep(200000);
$_b = readCpuStat();
$cpuPercent = 0;
if ($_a && $_b) {
    $dt = $_b['total'] - $_a['total'];
    if ($dt > 0) $cpuPercent = round(100 * (1 - ($_b['idle'] - $_a['idle']) / $dt), 1);
}

// Disk
$diskTotal = @disk_total_space('/') ?: 0;
$diskFree = @disk_free_space('/') ?: 0;
$diskUsed = $diskTotal - $diskFree;
$diskPercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0;

// Uptime
$uptime = @file_get_contents('/proc/uptime');
$uptimeSeconds = $uptime ? intval(explode(' ', $uptime)[0]) : 0;
$uptimeDays = floor($uptimeSeconds / 86400);
$uptimeHours = floor(($uptimeSeconds % 86400) / 3600);

// MySQL status
$mysqlVersion = $db->query("SELECT VERSION() as v")->fetch()['v'];
$mysqlUptime = $db->query("SHOW STATUS LIKE 'Uptime'")->fetch()['Value'] ?? 0;
$mysqlConnections = $db->query("SHOW STATUS LIKE 'Threads_connected'")->fetch()['Value'] ?? 0;
$mysqlQueries = $db->query("SHOW STATUS LIKE 'Questions'")->fetch()['Value'] ?? 0;
$mysqlUptimeDays = floor($mysqlUptime / 86400);
$mysqlUptimeHours = floor(($mysqlUptime % 86400) / 3600);

// Database size
$dbSize = $db->query("SELECT SUM(data_length + index_length) / 1024 / 1024 AS size FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "'")->fetch()['size'] ?? 0;

// Table sizes
$tables = $db->query("SELECT TABLE_NAME as table_name, TABLE_ROWS as table_rows, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024, 2) AS size_kb FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_TYPE = 'BASE TABLE' ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC")->fetchAll();

// Message stats
$totalMessages = $db->query("SELECT COUNT(*) as cnt FROM messages")->fetch()['cnt'];
$todayMessages = $db->query("SELECT COUNT(*) as cnt FROM messages WHERE DATE(created_at) = CURDATE()")->fetch()['cnt'];
$activeDevices = $db->query("SELECT COUNT(*) as cnt FROM devices WHERE is_active = 1")->fetch()['cnt'];
$totalUsers = $db->query("SELECT COUNT(*) as cnt FROM users")->fetch()['cnt'];

// Message activity over time (hourly for selected period)
$period = $_GET['period'] ?? '24h';
$periodMap = [
    '1h' => ['1 HOUR', 'MINUTE', '%H:%i', 60],
    '6h' => ['6 HOUR', 'HOUR', '%H:00', 6],
    '24h' => ['24 HOUR', 'HOUR', '%H:00', 24],
    '7d' => ['7 DAY', 'DAY', '%m/%d', 7],
    '30d' => ['30 DAY', 'DAY', '%m/%d', 30],
];
$p = $periodMap[$period] ?? $periodMap['24h'];

$activityStmt = $db->prepare(
    "SELECT DATE_FORMAT(created_at, '{$p[2]}') as period_label,
        COUNT(*) as total,
        SUM(status = 'delivered') as delivered,
        SUM(status = 'failed') as failed,
        SUM(status = 'expired') as expired,
        SUM(status = 'pending' OR status = 'sent') as pending
     FROM messages
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$p[0]})
     GROUP BY period_label
     ORDER BY MIN(created_at) ASC"
);
$activityStmt->execute();
$activity = $activityStmt->fetchAll();

// Historical server metrics for the selected period
$metricsInterval = ['1h' => '1 HOUR', '6h' => '6 HOUR', '24h' => '24 HOUR', '7d' => '7 DAY', '30d' => '30 DAY'];
$mInterval = $metricsInterval[$period] ?? '24 HOUR';

// For longer periods, aggregate to reduce data points
$metricsGroup = ['1h' => '', '6h' => 'MINUTE', '24h' => 'HOUR', '7d' => 'DAY', '30d' => 'DAY'];
$mGroup = $metricsGroup[$period] ?? 'HOUR';

if ($period === '1h' || $period === '6h') {
    // Show all data points (every 10 seconds)
    $metricsStmt = $db->prepare(
        "SELECT cpu_load, ram_percent, disk_percent, mysql_connections, messages_pending, messages_sent_minute, active_devices,
                DATE_FORMAT(created_at, '%H:%i:%s') as label
         FROM server_metrics
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL $mInterval)
         ORDER BY created_at ASC"
    );
} else {
    // Aggregate for longer periods
    $dateFmt = $period === '24h' ? '%H:00' : '%m/%d';
    $metricsStmt = $db->prepare(
        "SELECT ROUND(AVG(cpu_load),2) as cpu_load, ROUND(AVG(ram_percent),2) as ram_percent,
                ROUND(AVG(disk_percent),2) as disk_percent, ROUND(AVG(mysql_connections)) as mysql_connections,
                ROUND(AVG(messages_pending)) as messages_pending, SUM(messages_sent_minute) as messages_sent_minute,
                ROUND(AVG(active_devices)) as active_devices,
                DATE_FORMAT(created_at, '$dateFmt') as label
         FROM server_metrics
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL $mInterval)
         GROUP BY label
         ORDER BY MIN(created_at) ASC"
    );
}
$metricsStmt->execute();
$metrics = $metricsStmt->fetchAll();

renderHeader('Server Status', 'server-status');
?>

<?php if (empty($metrics)): ?>
<div class="alert alert-info mb-4">
    <i class="bi bi-info-circle"></i> <strong>No historical data yet.</strong> Set up the cron job to start collecting metrics:
    <code class="d-block mt-2 p-2 bg-dark text-light rounded">* * * * * php /var/www/html/whatsapp/cron-metrics.php</code>
    <small class="text-muted">This collects CPU, RAM, disk, and message stats every 10 seconds. Data appears here within 1 minute.</small>
</div>
<?php endif; ?>

<!-- Server Resource Charts -->
<?php if (!empty($metrics)): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-cpu"></i> CPU & RAM History</span>
                <div class="btn-group btn-group-sm">
                    <?php foreach (['1h' => '1H', '6h' => '6H', '24h' => '24H', '7d' => '7D', '30d' => '30D'] as $key => $label): ?>
                    <a href="?period=<?= $key ?>" class="btn <?= $period === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $label ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-body">
                <canvas id="cpuRamChart" height="180"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-3"><i class="bi bi-envelope"></i> Message Throughput</div>
            <div class="card-body">
                <canvas id="throughputChart" height="180"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
var mLabels = <?= json_encode(array_column($metrics, 'label')) ?>;
var mCpu = <?= json_encode(array_map(fn($r) => floatval($r['cpu_load']), $metrics)) ?>;
var mRam = <?= json_encode(array_map(fn($r) => floatval($r['ram_percent']), $metrics)) ?>;
var mPending = <?= json_encode(array_map(fn($r) => intval($r['messages_pending']), $metrics)) ?>;
var mSent = <?= json_encode(array_map(fn($r) => intval($r['messages_sent_minute']), $metrics)) ?>;
var mDevices = <?= json_encode(array_map(fn($r) => intval($r['active_devices']), $metrics)) ?>;
var mConn = <?= json_encode(array_map(fn($r) => intval($r['mysql_connections']), $metrics)) ?>;

// Reduce labels for readability (show every Nth)
var step = Math.max(1, Math.floor(mLabels.length / 20));
var displayLabels = mLabels.map((l, i) => i % step === 0 ? l : '');

new Chart(document.getElementById('cpuRamChart'), {
    type: 'line',
    data: {
        labels: displayLabels,
        datasets: [
            { label: 'CPU %', data: mCpu, borderColor: '#FF9800', backgroundColor: 'rgba(255,152,0,0.1)', fill: true, tension: 0.3, pointRadius: 0 },
            { label: 'RAM %', data: mRam, borderColor: '#2196F3', backgroundColor: 'rgba(33,150,243,0.1)', fill: true, tension: 0.3, pointRadius: 0 },
            { label: 'MySQL Conn', data: mConn, borderColor: '#9C27B0', fill: false, tension: 0.3, pointRadius: 0, borderDash: [3,3] }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 10 } } },
        scales: { y: { beginAtZero: true }, x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 15 } } },
        interaction: { intersect: false, mode: 'index' }
    }
});

new Chart(document.getElementById('throughputChart'), {
    type: 'line',
    data: {
        labels: displayLabels,
        datasets: [
            { label: 'Sent/min', data: mSent, borderColor: '#25D366', backgroundColor: 'rgba(37,211,102,0.1)', fill: true, tension: 0.3, pointRadius: 0 },
            { label: 'Pending', data: mPending, borderColor: '#FF9800', fill: false, tension: 0.3, pointRadius: 0 },
            { label: 'Active Devices', data: mDevices, borderColor: '#2196F3', fill: false, tension: 0.3, pointRadius: 0, borderDash: [3,3] }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 10 } } },
        scales: { y: { beginAtZero: true }, x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 15 } } },
        interaction: { intersect: false, mode: 'index' }
    }
});
</script>
<?php endif; ?>

<!-- Activity Chart -->
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-activity"></i> Message Activity</span>
        <div class="btn-group btn-group-sm">
            <?php foreach (['1h' => '1 Hour', '6h' => '6 Hours', '24h' => '24 Hours', '7d' => '7 Days', '30d' => '30 Days'] as $key => $label): ?>
            <a href="?period=<?= $key ?>" class="btn <?= $period === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-body">
        <canvas id="activityChart" height="120"></canvas>
    </div>
</div>

<script>
new Chart(document.getElementById('activityChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($activity, 'period_label')) ?>,
        datasets: [
            { label: 'Delivered', data: <?= json_encode(array_map(fn($r) => (int)$r['delivered'], $activity)) ?>, borderColor: '#25D366', backgroundColor: 'rgba(37,211,102,0.1)', fill: true, tension: 0.3 },
            { label: 'Failed', data: <?= json_encode(array_map(fn($r) => (int)$r['failed'], $activity)) ?>, borderColor: '#F44336', backgroundColor: 'rgba(244,67,54,0.1)', fill: true, tension: 0.3 },
            { label: 'Expired', data: <?= json_encode(array_map(fn($r) => (int)$r['expired'], $activity)) ?>, borderColor: '#9C27B0', backgroundColor: 'rgba(156,39,176,0.1)', fill: true, tension: 0.3 },
            { label: 'Total', data: <?= json_encode(array_map(fn($r) => (int)$r['total'], $activity)) ?>, borderColor: '#2196F3', borderDash: [5,5], fill: false, tension: 0.3 }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true } } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
        interaction: { intersect: false, mode: 'index' }
    }
});
</script>

<!-- Server Health -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center py-3">
                <?php
                    $cpuColor = $cpuPercent > 90 ? 'danger' : ($cpuPercent > 70 ? 'warning' : 'success');
                    $loadColor = $loadAvg[0] > $cpuCores ? 'danger' : ($loadAvg[0] > $cpuCores * 0.7 ? 'warning' : 'success');
                ?>
                <h3 class="mb-0 text-<?= $cpuColor ?>"><?= $cpuPercent ?>%</h3>
                <small class="text-muted">CPU Usage</small>
                <div class="small text-muted mt-1"><?= $cpuCores ?> core(s) &middot; load <?= number_format($loadAvg[0], 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center py-3">
                <?php $memColor = $memPercent > 90 ? 'danger' : ($memPercent > 70 ? 'warning' : 'success'); ?>
                <h3 class="mb-0 text-<?= $memColor ?>"><?= $memPercent ?>%</h3>
                <small class="text-muted">RAM Usage</small>
                <div class="small text-muted mt-1"><?= number_format($memUsed) ?> / <?= number_format($memTotal) ?> MB</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center py-3">
                <?php $diskColor = $diskPercent > 90 ? 'danger' : ($diskPercent > 70 ? 'warning' : 'success'); ?>
                <h3 class="mb-0 text-<?= $diskColor ?>"><?= $diskPercent ?>%</h3>
                <small class="text-muted">Disk Usage</small>
                <div class="small text-muted mt-1"><?= number_format($diskUsed / 1073741824, 1) ?> / <?= number_format($diskTotal / 1073741824, 1) ?> GB</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center py-3">
                <h3 class="mb-0 text-info"><?= $uptimeDays ?>d <?= $uptimeHours ?>h</h3>
                <small class="text-muted">Server Uptime</small>
            </div>
        </div>
    </div>
</div>

<!-- Progress bars -->
<div class="card mb-4">
    <div class="card-header py-3"><i class="bi bi-speedometer2"></i> Resource Usage</div>
    <div class="card-body">
        <div class="mb-3">
            <div class="d-flex justify-content-between small mb-1">
                <span>CPU Usage</span>
                <span><?= $cpuPercent ?>% &middot; load avg <?= number_format($loadAvg[0], 2) ?> / <?= number_format($loadAvg[1], 2) ?> / <?= number_format($loadAvg[2], 2) ?> (1m/5m/15m, <?= $cpuCores ?> cores)</span>
            </div>
            <div class="progress" style="height:8px;">
                <div class="progress-bar bg-<?= $cpuColor ?>" style="width:<?= $cpuPercent ?>%"></div>
            </div>
        </div>
        <div class="mb-3">
            <div class="d-flex justify-content-between small mb-1">
                <span>RAM</span>
                <span><?= number_format($memUsed) ?> MB / <?= number_format($memTotal) ?> MB</span>
            </div>
            <div class="progress" style="height:8px;">
                <div class="progress-bar bg-<?= $memColor ?>" style="width:<?= $memPercent ?>%"></div>
            </div>
        </div>
        <div>
            <div class="d-flex justify-content-between small mb-1">
                <span>Disk</span>
                <span><?= number_format($diskUsed / 1073741824, 1) ?> GB / <?= number_format($diskTotal / 1073741824, 1) ?> GB</span>
            </div>
            <div class="progress" style="height:8px;">
                <div class="progress-bar bg-<?= $diskColor ?>" style="width:<?= $diskPercent ?>%"></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- MySQL Status -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-3"><i class="bi bi-database"></i> MySQL Status</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted ps-3">Version</td><td><?= htmlspecialchars($mysqlVersion) ?></td></tr>
                    <tr><td class="text-muted ps-3">Uptime</td><td><?= $mysqlUptimeDays ?>d <?= $mysqlUptimeHours ?>h</td></tr>
                    <tr><td class="text-muted ps-3">Active Connections</td><td><?= $mysqlConnections ?></td></tr>
                    <tr><td class="text-muted ps-3">Total Queries</td><td><?= number_format($mysqlQueries) ?></td></tr>
                    <tr><td class="text-muted ps-3">Database Size</td><td><?= number_format($dbSize, 2) ?> MB</td></tr>
                    <tr><td class="text-muted ps-3">PHP Version</td><td><?= phpversion() ?></td></tr>
                    <tr><td class="text-muted ps-3">Server Time</td><td><?= date('Y-m-d H:i:s T') ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <!-- App Stats -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header py-3"><i class="bi bi-graph-up"></i> Application Stats</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted ps-3">Total Users</td><td><?= $totalUsers ?></td></tr>
                    <tr><td class="text-muted ps-3">Active Devices</td><td><?= $activeDevices ?></td></tr>
                    <tr><td class="text-muted ps-3">Total Messages</td><td><?= number_format($totalMessages) ?></td></tr>
                    <tr><td class="text-muted ps-3">Messages Today</td><td><?= number_format($todayMessages) ?></td></tr>
                    <tr><td class="text-muted ps-3">App Version</td><td><?= APP_VERSION ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Table Sizes -->
<div class="card mb-4">
    <div class="card-header py-3"><i class="bi bi-table"></i> Database Tables</div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th class="ps-3">Table</th><th>Rows</th><th>Size</th></tr></thead>
            <tbody>
                <?php foreach ($tables as $t): ?>
                <tr>
                    <td class="ps-3"><code><?= htmlspecialchars($t['table_name']) ?></code></td>
                    <td><?= number_format($t['table_rows']) ?></td>
                    <td><?= $t['size_kb'] ?> KB</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php renderFooter(); ?>
