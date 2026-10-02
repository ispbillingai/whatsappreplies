<?php
/**
 * Two-way bridge helpers: incoming messages -> webhook, replies pinned to a
 * line, delivery reports. Shared by api.php (the phones and the support panel
 * call it) and the dashboard pages (Inbox retry, Settings test button).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

if (!function_exists('logAction')) {
    function logAction($messageId, $action, $details = null) {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO message_log (message_id, action, details) VALUES (?, ?, ?)');
        $stmt->execute([$messageId, $action, $details]);
    }
}

// The phone's own number for one of its apps - what we call the "line".
function deviceLine(array $device, string $waType): string {
    $col = $waType === 'whatsapp_business' ? 'wa_business_number' : 'wa_number';
    return preg_replace('/[^0-9]/', '', (string)($device[$col] ?? ''));
}

// Every (device, app) pair this user owns, with its number. Used by /lines
// and by the dashboard. online = polled in the last 5 minutes.
function userLines(int $userId): array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT device_id, device_name, whatsapp_type, wa_number, wa_business_number, is_active,
                (last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS online
         FROM devices WHERE user_id = ? ORDER BY device_name ASC'
    );
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $dev) {
        $types = $dev['whatsapp_type'] === 'both' ? ['whatsapp', 'whatsapp_business'] : [$dev['whatsapp_type']];
        foreach ($types as $t) {
            $line = deviceLine($dev, $t);
            $out[] = [
                'line'          => $line,
                'device_id'     => $dev['device_id'],
                'device_name'   => $dev['device_name'],
                'whatsapp_type' => $t,
                'label'         => $dev['device_name'] . ($t === 'whatsapp_business' ? ' (Business)' : ''),
                'online'        => (bool)$dev['online'],
                'active'        => (bool)$dev['is_active'],
            ];
        }
    }
    return $out;
}

// Which device+app owns a line. Returns ['device_id', 'wa_type'] or null.
function findDeviceByLine(int $userId, string $line): ?array {
    $line = preg_replace('/[^0-9]/', '', $line);
    if ($line === '') return null;
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT device_id, wa_number, wa_business_number, whatsapp_type FROM devices
         WHERE user_id = ? AND is_active = 1 AND (wa_number = ? OR wa_business_number = ?)
         ORDER BY last_seen DESC LIMIT 1'
    );
    $stmt->execute([$userId, $line, $line]);
    $dev = $stmt->fetch();
    if (!$dev) return null;
    // Business number first: if both columns hold the same number, prefer the app the device monitors.
    if (preg_replace('/[^0-9]/', '', (string)$dev['wa_business_number']) === $line && $dev['whatsapp_type'] !== 'whatsapp') {
        return ['device_id' => $dev['device_id'], 'wa_type' => 'whatsapp_business'];
    }
    return ['device_id' => $dev['device_id'], 'wa_type' => 'whatsapp'];
}

// Where the customer last wrote to us: the device+app of their newest incoming message.
function findRouteByIncoming(int $userId, string $phone): ?array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT device_id, whatsapp_type FROM incoming_messages WHERE user_id = ? AND phone = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$userId, $phone]);
    $row = $stmt->fetch();
    return $row ? ['device_id' => $row['device_id'], 'wa_type' => $row['whatsapp_type']] : null;
}

// The user's webhook settings, or null when the webhook is off / unset.
function webhookConfig(int $userId): ?array {
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    $db = getDB();
    $stmt = $db->prepare('SELECT webhook_url, webhook_secret, webhook_enabled FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u || !(int)$u['webhook_enabled'] || !filter_var($u['webhook_url'], FILTER_VALIDATE_URL)) {
        return $cache[$userId] = null;
    }
    return $cache[$userId] = ['url' => $u['webhook_url'], 'secret' => (string)$u['webhook_secret']];
}

// POST a JSON event to the user's webhook. The payload shape is the one the
// support panel's wa_inbound.php already understands (X-Bridge-Secret header,
// {event: message|status, line, message:{...}}), so the phone is just another
// linked number to it. Returns ['ok' => bool, 'http' => int, 'body' => string].
function fireWebhook(int $userId, array $payload, int $timeout = 10): array {
    $cfg = webhookConfig($userId);
    if (!$cfg) return ['ok' => false, 'http' => 0, 'body' => 'webhook not configured'];
    $ch = curl_init($cfg['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Bridge-Secret: ' . $cfg['secret'],
            'X-Webhook-Secret: ' . $cfg['secret'],
            'User-Agent: FreeISP-Replies/' . APP_VERSION,
        ],
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) $body = $err ?: 'no response';
    $ok = $http >= 200 && $http < 300;
    return ['ok' => $ok, 'http' => $http, 'body' => mb_substr((string)$body, 0, 500)];
}

// Push one incoming_messages row to the webhook and record the outcome.
function deliverIncomingWebhook(array $row): bool {
    $db = getDB();
    $userId = (int)$row['user_id'];
    if (!webhookConfig($userId)) {
        $db->prepare("UPDATE incoming_messages SET webhook_status = 'skipped', webhook_response = 'webhook not configured', webhook_at = NOW() WHERE id = ?")
           ->execute([$row['id']]);
        return false;
    }
    // received_at was written with FROM_UNIXTIME in MySQL's session zone, so
    // read it back the same way instead of guessing the zone in PHP.
    $tsStmt = $db->prepare('SELECT UNIX_TIMESTAMP(received_at) FROM incoming_messages WHERE id = ?');
    $tsStmt->execute([$row['id']]);
    $ts = (int)$tsStmt->fetchColumn() ?: time();
    $payload = [
        'event'     => 'message',
        'source'    => 'app',
        'line'      => (string)($row['line'] ?? ''),
        'device_id' => $row['device_id'],
        'wa_type'   => $row['whatsapp_type'],
        'message'   => [
            'id'        => 'app-in-' . $row['id'],
            // A LID is WhatsApp's internal id, not a number: "@lid" tells the panel so.
            'chatId'    => $row['phone'] . (!empty($row['is_lid']) ? '@lid' : '@c.us'),
            'fromMe'    => false,
            'timestamp' => $ts,
            'pushName'  => (string)($row['sender_name'] ?? ''),
            'type'      => 'text',
            'text'      => $row['message'],
        ],
    ];
    $res = fireWebhook($userId, $payload);
    $db->prepare('UPDATE incoming_messages SET webhook_status = ?, webhook_attempts = webhook_attempts + 1, webhook_response = ?, webhook_at = NOW() WHERE id = ?')
       ->execute([$res['ok'] ? 'sent' : 'failed', 'HTTP ' . $res['http'] . ' ' . $res['body'], $row['id']]);
    return $res['ok'];
}

// Retry webhooks that failed earlier (network blip, support box busy). Called
// from /pending so it runs every few seconds while a phone is online, without
// a cron. Backs off: attempt n waits 2^n minutes, gives up after 6.
function retryFailedWebhooks(int $userId, int $max = 3): void {
    if (!webhookConfig($userId)) return;
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT * FROM incoming_messages
         WHERE user_id = ? AND webhook_status IN ('pending', 'failed') AND webhook_attempts < 6
           AND (webhook_at IS NULL OR webhook_at < DATE_SUB(NOW(), INTERVAL POW(2, webhook_attempts) MINUTE))
         ORDER BY id ASC LIMIT ?"
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $max, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        deliverIncomingWebhook($row);
    }
}

// Delivery report for a reply we were asked to send: tells the support panel
// to move the ticks (delivered) or paint the bubble red (failed).
function reportStatusWebhook(int $userId, int $messageId, string $status, ?string $error = null): void {
    if (!webhookConfig($userId)) return;
    $res = fireWebhook($userId, [
        'event'  => 'status',
        'source' => 'app',
        'id'     => 'app-' . $messageId,
        'status' => $status,
        'error'  => $error,
    ], 8);
    logAction($messageId, 'webhook', ($res['ok'] ? 'Reported ' : 'Report FAILED for ') . $status . ' (HTTP ' . $res['http'] . ')');
}


// Settings page "Send test event": a ping the receiver can ignore, to prove
// the URL and secret are right.
function webhookTest(int $userId): array {
    if (!webhookConfig($userId)) return ['ok' => false, 'http' => 0, 'body' => 'webhook not configured or disabled'];
    return fireWebhook($userId, ['event' => 'ping', 'source' => 'app', 'time' => time()], 10);
}
