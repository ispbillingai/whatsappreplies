<?php
/**
 * Database connection singleton
 */
require_once __DIR__ . '/config.php';

// Enable error logging
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

/**
 * Schema version. BUMP THIS whenever you add or change anything in
 * applyMigrations() below, otherwise existing installs will never pick it up:
 * migrations only run when the recorded version is lower than this number.
 */
define('WA_SCHEMA_VERSION', 5);

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            // Don't set MySQL timezone - DATETIME columns don't convert
            // PHP handles all timezone display via date_default_timezone_set()
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }
    return $pdo;
}

// Helper: check if column exists before adding
function columnExists($db, $table, $column) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return $stmt->fetchColumn() > 0;
}

// Helper: current SQL type of a column, e.g. "enum('a','b')". Null if absent.
function columnType($db, $table, $column) {
    $stmt = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    $type = $stmt->fetchColumn();
    return $type === false ? null : $type;
}

// Helper: does this named index exist?
function indexExists($db, $table, $indexName) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $indexName]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Decide whether the schema needs work, and do it at most once per version.
 *
 * The steady-state cost of this function is ONE primary-key SELECT. That
 * matters: this file is included by every entry point, and the fleet calls
 * sendWA.php a few hundred times a minute.
 *
 * History: this used to call applyMigrations() unconditionally on every single
 * request, guarded only by `static $ran` - which is per-request, so it guarded
 * nothing across requests. Every hit re-ran 4 CREATE TABLE IF NOT EXISTS, 16
 * information_schema probes and 2 unconditional ALTER TABLE ... MODIFY COLUMN.
 * MODIFY COLUMN rebuilds the entire table, and `messages` is ~354k rows /
 * ~115MB, so MySQL was rebuilding it several times a second forever. The
 * concurrent ALTERs then piled up on the table metadata lock (40 threads deep),
 * which starved every other database on the same MySQL instance - including
 * sms.ispledger.com, whose admin dashboard was taking ~10s to load.
 */
function runMigrations() {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    $db = getDB();

    // Fast path: one indexed read on a single-row table. No DDL, no
    // information_schema. This is what virtually every request will do.
    try {
        $installed = $db->query("SELECT version FROM schema_migrations WHERE id = 1")->fetchColumn();
        if ($installed !== false && (int)$installed >= WA_SCHEMA_VERSION) {
            return;
        }
    } catch (PDOException $e) {
        // schema_migrations doesn't exist yet (fresh install, or an install
        // predating versioning). Fall through and build it.
    }

    // Only one process may migrate. Without this, a deploy that bumps the
    // version would let every concurrent request stampede into the same DDL
    // and rebuild the metadata-lock pileup we just removed. Non-blocking:
    // losers simply serve this one request against the old schema.
    try {
        if (!$db->query("SELECT GET_LOCK('wa_schema_migrate', 0)")->fetchColumn()) {
            return;
        }
    } catch (PDOException $e) {
        return;
    }

    try {
        // Re-check under the lock: another process may have finished while we
        // were waiting for it.
        try {
            $installed = $db->query("SELECT version FROM schema_migrations WHERE id = 1")->fetchColumn();
            if ($installed !== false && (int)$installed >= WA_SCHEMA_VERSION) {
                return;
            }
        } catch (PDOException $e) {
            // still missing - proceed
        }

        applyMigrations($db);
        recordSchemaVersion($db);
    } finally {
        try { $db->query("SELECT RELEASE_LOCK('wa_schema_migrate')"); } catch (PDOException $e) { /* ignore */ }
    }
}

/**
 * Persist the applied version so subsequent requests take the fast path.
 */
function recordSchemaVersion($db) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            version INT NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $db->prepare("INSERT INTO schema_migrations (id, version, applied_at) VALUES (1, ?, NOW())
                              ON DUPLICATE KEY UPDATE version = VALUES(version), applied_at = VALUES(applied_at)");
        $stmt->execute([WA_SCHEMA_VERSION]);
    } catch (PDOException $e) {
        error_log('Could not record schema version: ' . $e->getMessage());
    }
}

/**
 * The actual schema work. Runs once per WA_SCHEMA_VERSION, not once per request.
 * Every statement here must stay idempotent - a version bump replays all of it.
 */
function applyMigrations($db) {
    // CREATE TABLE migrations (safe with IF NOT EXISTS)
    $creates = [
        "CREATE TABLE IF NOT EXISTS remember_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_token (token_hash),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            plan_name VARCHAR(50) NOT NULL DEFAULT 'free',
            messages_limit INT DEFAULT 100,
            messages_used INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            starts_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            device_id VARCHAR(64) NOT NULL UNIQUE,
            device_name VARCHAR(100) DEFAULT 'Phone',
            whatsapp_type ENUM('whatsapp', 'whatsapp_business', 'both') DEFAULT 'whatsapp',
            is_active TINYINT(1) DEFAULT 1,
            last_seen DATETIME NULL,
            svc_accessibility TINYINT(1) DEFAULT 0,
            svc_notification TINYINT(1) DEFAULT 0,
            svc_battery TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_device_id (device_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS server_metrics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cpu_load DECIMAL(5,2) DEFAULT 0,
            ram_percent DECIMAL(5,2) DEFAULT 0,
            ram_used_mb INT DEFAULT 0,
            ram_total_mb INT DEFAULT 0,
            disk_percent DECIMAL(5,2) DEFAULT 0,
            disk_used_gb DECIMAL(8,2) DEFAULT 0,
            disk_total_gb DECIMAL(8,2) DEFAULT 0,
            mysql_connections INT DEFAULT 0,
            mysql_queries INT DEFAULT 0,
            messages_pending INT DEFAULT 0,
            messages_sent_minute INT DEFAULT 0,
            active_devices INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        // Messages the phones SAW arrive in WhatsApp (notification listener) and
        // forwarded to us. Each one is pushed on to the user's webhook (the
        // support panel). dedupe_key stops a re-posted notification doubling up.
        "CREATE TABLE IF NOT EXISTS incoming_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            device_id VARCHAR(64) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            sender_name VARCHAR(100) NULL,
            message TEXT NOT NULL,
            whatsapp_type ENUM('whatsapp', 'whatsapp_business') NOT NULL DEFAULT 'whatsapp',
            line VARCHAR(20) NULL,
            dedupe_key VARCHAR(64) NOT NULL,
            received_at DATETIME NOT NULL,
            webhook_status ENUM('pending', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
            webhook_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            webhook_response VARCHAR(500) NULL,
            webhook_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_device_key (device_id, dedupe_key),
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_user_phone (user_id, phone, id),
            INDEX idx_webhook (user_id, webhook_status, webhook_attempts),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($creates as $sql) {
        try { $db->exec($sql); } catch (PDOException $e) { /* table exists */ }
    }

    // ADD COLUMN migrations (check first, MySQL doesn't support IF NOT EXISTS)
    $columns = [
        ['users', 'phone', "ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER email"],
        ['users', 'must_change_password', "ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) DEFAULT 0 AFTER password"],
        ['users', 'whatsapp_type', "ALTER TABLE users ADD COLUMN whatsapp_type ENUM('whatsapp', 'whatsapp_business', 'load_balance') DEFAULT 'whatsapp' AFTER is_active"],
        ['users', 'timezone', "ALTER TABLE users ADD COLUMN timezone VARCHAR(50) DEFAULT 'Africa/Nairobi' AFTER whatsapp_type"],
        ['messages', 'device_id', "ALTER TABLE messages ADD COLUMN device_id VARCHAR(64) NULL AFTER api_key_id"],
        ['devices', 'svc_accessibility', "ALTER TABLE devices ADD COLUMN svc_accessibility TINYINT(1) DEFAULT 0"],
        ['devices', 'svc_notification', "ALTER TABLE devices ADD COLUMN svc_notification TINYINT(1) DEFAULT 0"],
        ['devices', 'svc_battery', "ALTER TABLE devices ADD COLUMN svc_battery TINYINT(1) DEFAULT 0"],
        // The phone's own WhatsApp number(s) - the "line" a customer wrote to.
        // A phone with both apps usually has two different numbers (two SIMs).
        ['devices', 'wa_number', "ALTER TABLE devices ADD COLUMN wa_number VARCHAR(20) NULL AFTER whatsapp_type"],
        ['devices', 'wa_business_number', "ALTER TABLE devices ADD COLUMN wa_business_number VARCHAR(20) NULL AFTER wa_number"],
        // One key per phone, made on the Devices page and typed into the app:
        // the server then knows exactly which phone is talking to it.
        ['devices', 'device_key', "ALTER TABLE devices ADD COLUMN device_key VARCHAR(64) NULL UNIQUE AFTER device_id"],
        // Forwarding diagnostics the app reports on every poll, so "nothing
        // arrived" can be read off the Devices page instead of the phone.
        ['devices', 'fwd_enabled', "ALTER TABLE devices ADD COLUMN fwd_enabled TINYINT(1) NULL"],
        ['devices', 'nl_bound', "ALTER TABLE devices ADD COLUMN nl_bound TINYINT(1) NULL"],
        ['devices', 'inbound_seen', "ALTER TABLE devices ADD COLUMN inbound_seen INT NULL"],
        ['devices', 'inbound_queued', "ALTER TABLE devices ADD COLUMN inbound_queued INT NULL"],
        ['devices', 'last_notif_at', "ALTER TABLE devices ADD COLUMN last_notif_at DATETIME NULL"],
        ['devices', 'last_notif_info', "ALTER TABLE devices ADD COLUMN last_notif_info VARCHAR(255) NULL"],
        // `phone` holds WhatsApp's internal LID, not a dialable number (saved contact whose number is hidden)
        ['incoming_messages', 'is_lid', "ALTER TABLE incoming_messages ADD COLUMN is_lid TINYINT(1) NOT NULL DEFAULT 0 AFTER phone"],
        // A reply to an incoming message must leave from the SAME phone+app it
        // arrived on, so pinned messages are never reassigned to another device.
        ['messages', 'pinned', "ALTER TABLE messages ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER priority"],
        ['messages', 'external_ref', "ALTER TABLE messages ADD COLUMN external_ref VARCHAR(128) NULL AFTER pinned"],
        // Where incoming messages and delivery reports are pushed.
        ['users', 'webhook_url', "ALTER TABLE users ADD COLUMN webhook_url VARCHAR(255) NULL AFTER timezone"],
        ['users', 'webhook_secret', "ALTER TABLE users ADD COLUMN webhook_secret VARCHAR(128) NULL AFTER webhook_url"],
        ['users', 'webhook_enabled', "ALTER TABLE users ADD COLUMN webhook_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER webhook_secret"],
    ];

    foreach ($columns as [$table, $column, $sql]) {
        try {
            if (!columnExists($db, $table, $column)) {
                $db->exec($sql);
            }
        } catch (PDOException $e) {
            // Ignore - column might already exist
        }
    }

    // MODIFY columns. NOT "always safe to run": MODIFY COLUMN rebuilds the
    // whole table, so only issue it when the type actually differs. Compare
    // against COLUMN_TYPE, which MySQL stores normalised (no spaces).
    $modifies = [
        ['users', 'whatsapp_type',
         "enum('whatsapp','whatsapp_business','load_balance')",
         "ALTER TABLE users MODIFY COLUMN whatsapp_type ENUM('whatsapp', 'whatsapp_business', 'load_balance') DEFAULT 'whatsapp'"],
        ['messages', 'status',
         "enum('pending','sent','delivered','failed','expired')",
         "ALTER TABLE messages MODIFY COLUMN status ENUM('pending', 'sent', 'delivered', 'failed', 'expired') DEFAULT 'pending'"],
    ];

    foreach ($modifies as [$table, $column, $wantType, $sql]) {
        try {
            $current = columnType($db, $table, $column);
            if ($current !== null && strcasecmp($current, $wantType) !== 0) {
                $db->exec($sql);
            }
        } catch (PDOException $e) {
            // Ignore
        }
    }

    // INDEX migrations - speed up dashboard queries
    // MySQL doesn't support CREATE INDEX IF NOT EXISTS, so check first
    $indexes = [
        // [table, index_name, CREATE statement]
        // For: SELECT * FROM messages WHERE user_id = ? ORDER BY created_at DESC LIMIT 50
        ['messages', 'idx_user_created', "CREATE INDEX idx_user_created ON messages (user_id, created_at)"],
        // For: SELECT COUNT(*) FROM messages WHERE device_id = ? AND status = ? (devices.php runs this 3x per device)
        ['messages', 'idx_device_status', "CREATE INDEX idx_device_status ON messages (device_id, status)"],
        // For: chart queries WHERE created_at >= ? AND user_id = ? GROUP BY status
        ['messages', 'idx_user_status_created', "CREATE INDEX idx_user_status_created ON messages (user_id, status, created_at)"],
        // For: SELECT * FROM messages WHERE device_id = ? ORDER BY id DESC LIMIT 1 (last_msg_status)
        ['messages', 'idx_device_id_only', "CREATE INDEX idx_device_id_only ON messages (device_id, id)"],
        // For: api_keys lookups WHERE user_id = ? AND is_active = 1
        ['api_keys', 'idx_user_active', "CREATE INDEX idx_user_active ON api_keys (user_id, is_active)"],
        // For: SELECT MAX(sent_at) FROM messages WHERE device_id = ? AND status = "delivered"
        ['messages', 'idx_device_status_sent', "CREATE INDEX idx_device_status_sent ON messages (device_id, status, sent_at)"],
        // For: devices listing ORDER BY last_seen DESC and active filter
        ['devices', 'idx_user_lastseen', "CREATE INDEX idx_user_lastseen ON devices (user_id, last_seen)"],
        ['devices', 'idx_active_lastseen', "CREATE INDEX idx_active_lastseen ON devices (is_active, last_seen)"],
    ];

    foreach ($indexes as [$table, $idxName, $sql]) {
        try {
            if (!indexExists($db, $table, $idxName)) {
                $db->exec($sql);
            }
        } catch (PDOException $e) {
            // Ignore - index may exist or table missing
        }
    }
}

// Auto-run migrations
runMigrations();
