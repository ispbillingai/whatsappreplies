-- Performance indexes for dashboard queries
-- Run with: mysql -u whatsapp -pwhatsapp whatsapp < add_indexes.sql
-- These will be auto-created on the next page load via database.php migrations,
-- but running this manually applies them immediately.
--
-- Each statement uses a procedure to skip if the index already exists (MySQL
-- doesn't support CREATE INDEX IF NOT EXISTS).

DELIMITER //

DROP PROCEDURE IF EXISTS create_index_if_missing //
CREATE PROCEDURE create_index_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @s = p_sql;
        PREPARE stmt FROM @s;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
        SELECT CONCAT('Created index ', p_index, ' on ', p_table) AS result;
    ELSE
        SELECT CONCAT('Skipped (exists): ', p_index, ' on ', p_table) AS result;
    END IF;
END //

DELIMITER ;

-- Messages: user history queries (recent messages on dashboard)
CALL create_index_if_missing('messages', 'idx_user_created',
    'CREATE INDEX idx_user_created ON messages (user_id, created_at)');

-- Messages: per-device status counts (devices.php runs these per device)
CALL create_index_if_missing('messages', 'idx_device_status',
    'CREATE INDEX idx_device_status ON messages (device_id, status)');

-- Messages: chart queries with status grouping
CALL create_index_if_missing('messages', 'idx_user_status_created',
    'CREATE INDEX idx_user_status_created ON messages (user_id, status, created_at)');

-- Messages: last message lookup per device
CALL create_index_if_missing('messages', 'idx_device_id_only',
    'CREATE INDEX idx_device_id_only ON messages (device_id, id)');

-- Messages: MAX(sent_at) per device for delivered messages
CALL create_index_if_missing('messages', 'idx_device_status_sent',
    'CREATE INDEX idx_device_status_sent ON messages (device_id, status, sent_at)');

-- API keys: user_id + is_active lookups (dashboard auto-creates default key)
CALL create_index_if_missing('api_keys', 'idx_user_active',
    'CREATE INDEX idx_user_active ON api_keys (user_id, is_active)');

-- Devices: per-user listings with last_seen ordering
CALL create_index_if_missing('devices', 'idx_user_lastseen',
    'CREATE INDEX idx_user_lastseen ON devices (user_id, last_seen)');

-- Devices: active + last_seen for online filtering
CALL create_index_if_missing('devices', 'idx_active_lastseen',
    'CREATE INDEX idx_active_lastseen ON devices (is_active, last_seen)');

DROP PROCEDURE IF EXISTS create_index_if_missing;

-- Show all indexes on the messages table to verify
SHOW INDEX FROM messages;
