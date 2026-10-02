#!/usr/bin/env php
<?php
/**
 * Server Metrics Collector
 *
 * Collects CPU, RAM, disk, MySQL, and app metrics every minute.
 * Run via cron: * * * * * php /var/www/html/whatsapp/cron-metrics.php
 *
 * For more frequent collection (every 10 seconds), the cron runs this
 * script once per minute, and it loops 6 times with 10-second intervals.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// Run 6 iterations (every 10 seconds within the 1-minute cron window)
for ($i = 0; $i < 6; $i++) {
    collectMetrics();

    // Sleep 10 seconds (but not on the last iteration)
    if ($i < 5) {
        sleep(10);
    }
}

function readCpuStat() {
    $stat = @file_get_contents('/proc/stat');
    if (!$stat || !preg_match('/^cpu\s+(.+)$/m', $stat, $m)) return null;
    $v = array_map('intval', preg_split('/\s+/', trim($m[1])));
    return ['idle' => ($v[3] ?? 0) + ($v[4] ?? 0), 'total' => array_sum($v)];
}

function getCpuPercent($sampleMs = 500) {
    $a = readCpuStat();
    if ($a === null) return 0;
    usleep($sampleMs * 1000);
    $b = readCpuStat();
    if ($b === null) return 0;
    $dt = $b['total'] - $a['total'];
    if ($dt <= 0) return 0;
    return round(100 * (1 - ($b['idle'] - $a['idle']) / $dt), 2);
}

function collectMetrics() {
    try {
        $db = getDB();

        // Real CPU usage % from /proc/stat (idle vs total ticks delta).
        // Replaces sys_getloadavg() — load average ≠ CPU%.
        $cpuLoad = getCpuPercent();

        // RAM
        $memTotal = 0;
        $memAvailable = 0;
        $memInfo = @file_get_contents('/proc/meminfo');
        if ($memInfo) {
            preg_match('/MemTotal:\s+(\d+)/', $memInfo, $m);
            $memTotal = intval(($m[1] ?? 0) / 1024);
            preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $m);
            $memAvailable = intval(($m[1] ?? 0) / 1024);
        }
        $memUsed = $memTotal - $memAvailable;
        $memPercent = $memTotal > 0 ? round(($memUsed / $memTotal) * 100, 2) : 0;

        // Disk
        $diskTotal = @disk_total_space('/') ?: 0;
        $diskFree = @disk_free_space('/') ?: 0;
        $diskUsed = $diskTotal - $diskFree;
        $diskPercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 2) : 0;
        $diskUsedGb = round($diskUsed / 1073741824, 2);
        $diskTotalGb = round($diskTotal / 1073741824, 2);

        // MySQL
        $mysqlConnections = $db->query("SHOW STATUS LIKE 'Threads_connected'")->fetch()['Value'] ?? 0;
        $mysqlQueries = $db->query("SHOW STATUS LIKE 'Questions'")->fetch()['Value'] ?? 0;

        // App metrics
        $pendingCount = $db->query("SELECT COUNT(*) as cnt FROM messages WHERE status = 'pending'")->fetch()['cnt'];
        $sentLastMinute = $db->query("SELECT COUNT(*) as cnt FROM messages WHERE status = 'delivered' AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)")->fetch()['cnt'];
        $activeDevices = $db->query("SELECT COUNT(*) as cnt FROM devices WHERE is_active = 1 AND last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetch()['cnt'];

        // Insert
        $stmt = $db->prepare(
            'INSERT INTO server_metrics (cpu_load, ram_percent, ram_used_mb, ram_total_mb, disk_percent, disk_used_gb, disk_total_gb, mysql_connections, mysql_queries, messages_pending, messages_sent_minute, active_devices)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $cpuLoad, $memPercent, $memUsed, $memTotal,
            $diskPercent, $diskUsedGb, $diskTotalGb,
            $mysqlConnections, $mysqlQueries,
            $pendingCount, $sentLastMinute, $activeDevices
        ]);

        // Cleanup: keep only last 7 days of metrics
        $db->exec("DELETE FROM server_metrics WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");

    } catch (Exception $e) {
        error_log("Metrics collection error: " . $e->getMessage());
    }
}
