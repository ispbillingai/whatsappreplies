<?php
/**
 * WhatsApp Relay Server API
 *
 * Endpoints:
 *   POST   /send         - Queue a new message
 *   POST   /send-bulk    - Queue multiple messages
 *   GET    /pending       - Get pending messages (APK polls this)
 *   POST   /status        - Update message status (APK reports back)
 *   GET    /messages      - List all messages with filters
 *   GET    /health        - Server health check
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// JSON response helper
function respond($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// Authenticate request via API key in database
// Returns ['user_id' => int, 'key_id' => int]
function authenticate() {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? ($_GET['apikey'] ?? null));
    if (!$apiKey) {
        respond(401, ['error' => 'Missing API key. Include X-API-Key header.']);
    }

    $db = getDB();
    $stmt = $db->prepare(
        'SELECT ak.id as key_id, ak.user_id, u.is_active as user_active
         FROM api_keys ak
         JOIN users u ON u.id = ak.user_id
         WHERE ak.api_key = ? AND ak.is_active = 1'
    );
    $stmt->execute([$apiKey]);
    $result = $stmt->fetch();

    if (!$result) {
        // Not an account key - maybe a DEVICE key. Each phone gets its own on
        // the Devices page, so the server knows which phone is calling without
        // the app having to identify itself.
        $dstmt = $db->prepare(
            'SELECT d.id, d.device_id, d.user_id, d.is_active AS device_active, u.is_active AS user_active
             FROM devices d JOIN users u ON u.id = d.user_id
             WHERE d.device_key = ?'
        );
        $dstmt->execute([$apiKey]);
        $dev = $dstmt->fetch();
        if (!$dev) {
            respond(401, ['error' => 'Invalid or disabled API key']);
        }
        if (!$dev['user_active']) respond(403, ['error' => 'User account is disabled']);
        if (!$dev['device_active']) respond(403, ['error' => 'This device is disabled on the dashboard']);
        $keyStmt = $db->prepare('SELECT id FROM api_keys WHERE user_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1');
        $keyStmt->execute([$dev['user_id']]);
        $keyId = (int)($keyStmt->fetchColumn() ?: 0);
        if (!$keyId) {
            // Messages reference an api_key_id; make sure the account has one.
            $db->prepare('INSERT INTO api_keys (user_id, api_key, label) VALUES (?, ?, ?)')
               ->execute([$dev['user_id'], bin2hex(random_bytes(24)), 'Account']);
            $keyId = (int)$db->lastInsertId();
        }
        return ['user_id' => (int)$dev['user_id'], 'key_id' => $keyId, 'device_id' => $dev['device_id']];
    }

    if (!$result['user_active']) {
        respond(403, ['error' => 'User account is disabled']);
    }

    // Update last used timestamp and request count
    $db->prepare('UPDATE api_keys SET last_used_at = NOW(), request_count = request_count + 1 WHERE id = ?')
       ->execute([$result['key_id']]);

    return ['user_id' => (int)$result['user_id'], 'key_id' => (int)$result['key_id'], 'device_id' => null];
}

// Check user subscription (placeholder for future billing)
// Returns ['active' => bool, 'plan' => string, 'remaining' => int]
function checkSubscription($userId) {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT * FROM subscriptions WHERE user_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $sub = $stmt->fetch();

    if (!$sub) {
        // No subscription record = unlimited (for now)
        // TODO: Change this to enforce subscription when billing is live
        return ['active' => true, 'plan' => 'unlimited', 'remaining' => -1];
    }

    // Check expiry
    if ($sub['expires_at'] && strtotime($sub['expires_at']) < time()) {
        return ['active' => false, 'plan' => $sub['plan_name'], 'remaining' => 0, 'reason' => 'Subscription expired'];
    }

    // Check message limit
    if ($sub['messages_limit'] > 0 && $sub['messages_used'] >= $sub['messages_limit']) {
        return ['active' => false, 'plan' => $sub['plan_name'], 'remaining' => 0, 'reason' => 'Monthly message limit reached'];
    }

    $remaining = $sub['messages_limit'] > 0 ? ($sub['messages_limit'] - $sub['messages_used']) : -1;
    return ['active' => true, 'plan' => $sub['plan_name'], 'remaining' => $remaining];
}

// Increment subscription usage counter
function incrementUsage($userId) {
    $db = getDB();
    $db->prepare(
        'UPDATE subscriptions SET messages_used = messages_used + 1 WHERE user_id = ? AND is_active = 1'
    )->execute([$userId]);
}

// Pick the next active device for round-robin assignment
// $waType: the whatsapp_type of the message being queued
// Returns device_id or null if no active devices
/**
 * Assign device using full rotation of device+type combinations.
 *
 * Example with: Device A (Both), Device B (Business)
 * Rotation: A-WA → A-Biz → B-Biz → A-WA → A-Biz → B-Biz ...
 *
 * Returns ['device_id' => string, 'wa_type' => string] or null
 */
function assignDevice($userId, $waType = null) {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT device_id, whatsapp_type FROM devices
         WHERE user_id = ? AND is_active = 1 AND last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         ORDER BY device_name ASC'
    );
    $stmt->execute([$userId]);
    $allDevices = $stmt->fetchAll();

    if (empty($allDevices)) return null;

    // Build full rotation list: each device+type slot
    $slots = [];
    foreach ($allDevices as $dev) {
        $devType = $dev['whatsapp_type'] ?? 'both';
        if ($devType === 'both') {
            $slots[] = ['device_id' => $dev['device_id'], 'wa_type' => 'whatsapp'];
            $slots[] = ['device_id' => $dev['device_id'], 'wa_type' => 'whatsapp_business'];
        } elseif ($devType === 'whatsapp') {
            $slots[] = ['device_id' => $dev['device_id'], 'wa_type' => 'whatsapp'];
        } elseif ($devType === 'whatsapp_business') {
            $slots[] = ['device_id' => $dev['device_id'], 'wa_type' => 'whatsapp_business'];
        }
    }

    if (empty($slots)) return ['device_id' => $allDevices[0]['device_id'], 'wa_type' => 'whatsapp'];
    if (count($slots) === 1) return $slots[0];

    // Find last sent message's device+type to determine next slot
    $lastStmt = $db->prepare(
        'SELECT device_id, whatsapp_type FROM messages WHERE user_id = ? AND device_id IS NOT NULL ORDER BY id DESC LIMIT 1'
    );
    $lastStmt->execute([$userId]);
    $last = $lastStmt->fetch();

    if ($last) {
        // Find matching slot and pick next
        $matchIdx = -1;
        foreach ($slots as $i => $slot) {
            if ($slot['device_id'] === $last['device_id'] && $slot['wa_type'] === $last['whatsapp_type']) {
                $matchIdx = $i;
                break;
            }
        }
        $nextIdx = ($matchIdx + 1) % count($slots);
        return $slots[$nextIdx];
    }

    return $slots[0];
}

// Log message action
function logAction($messageId, $action, $details = null) {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO message_log (message_id, action, details) VALUES (?, ?, ?)');
    $stmt->execute([$messageId, $action, $details]);
}

require_once __DIR__ . '/webhook_lib.php';
require_once __DIR__ . '/media_lib.php';

// Parse request path
// Support both /api.php/send and ?action=send styles
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$path = rtrim($path, '/');

// Remove script name from path (e.g. /api.php/send -> /send)
$scriptName = $_SERVER['SCRIPT_NAME'];
if (strpos($path, $scriptName) === 0) {
    $path = substr($path, strlen($scriptName));
}

// Also support ?action= query parameter as fallback
if (empty($path) || $path === '/' || $path === false) {
    $action = $_GET['action'] ?? '';
    $path = '/' . ltrim($action, '/');
}

$path = '/' . ltrim($path, '/');

$method = $_SERVER['REQUEST_METHOD'];

// =============================================
// GET /health - Server health check (no auth)
// =============================================
if ($path === '/health' && $method === 'GET') {
    try {
        $db = getDB();
        $db->query('SELECT 1');
        respond(200, [
            'status' => 'ok',
            'server_time' => date('Y-m-d H:i:s'),
            'version' => APP_VERSION,
            'media' => [
                'dir_writable' => mediaDirWritable(),
                'free_mb' => is_dir(MEDIA_DIR) ? (int)(@disk_free_space(MEDIA_DIR) / 1048576) : null,
                'gd' => function_exists('imagecreatefromstring'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
            ],
        ]);
    } catch (Exception $e) {
        respond(500, ['status' => 'error', 'message' => 'Database unavailable']);
    }
}

// =============================================
// GET /media/<id> - the bytes of a stored file. Either a signed, expiring
// URL (e, a, s from mediaSignedUrl: the support panel and the phones get
// these) or an API key whose account owns the file. ?v=thumb for the preview.
// =============================================
if ($method === 'GET' && preg_match('#^/media/([a-f0-9]{32})$#', $path, $mm)) {
    $media = mediaRow($mm[1]);
    if (!$media) respond(404, ['error' => 'media not found']);
    $variant = ($_GET['v'] ?? '') === 'thumb' ? 'thumb' : '';
    $ok = false;
    if (isset($_GET['e'], $_GET['a'], $_GET['s'])) {
        $ok = mediaVerify($media, (string)$_GET['a'], (int)$_GET['e'], (string)$_GET['s'], $variant);
        if (!$ok) respond(403, ['error' => 'link expired or invalid']);
    } else {
        $who = authenticate();   // respond()s 401 on a bad key
        $ok = (int)$who['user_id'] === (int)$media['user_id'];
        if (!$ok) respond(403, ['error' => 'not your media']);
    }
    mediaServe($media, $variant);
}

// =============================================
// GET /send - Simple URL-based send (for billing systems)
// Usage: api.php?action=send&to=254712345678&msg=Hello&apikey=YOURKEY
// =============================================
if ($method === 'GET' && ($path === '/send' || isset($_GET['to']))) {
    $phone = $_GET['to'] ?? null;
    $message = $_GET['msg'] ?? null;

    if (!$phone || !$message) {
        respond(400, ['error' => 'to and msg parameters are required', 'usage' => 'api.php?to=NUMBER&msg=TEXT&apikey=YOURKEY']);
    }

    // === Spam guard: reject obvious junk before authenticating or recording ===
    $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
    $msgTrim = trim($message);

    // Reject empty / placeholder / template tokens — these come from billing systems
    // that didn't substitute their variables (e.g. "[number]", "[text]", "{{phone}}")
    $isPlaceholder = function($v) {
        if ($v === '') return true;
        if (preg_match('/^\s*[\[\{<\(]+[a-z_\s]+[\]\}>\)]+\s*$/i', $v)) return true;
        return false;
    };

    if ($phoneDigits === '' || $isPlaceholder($phone) || strlen($phoneDigits) < 7) {
        respond(400, ['error' => 'Invalid phone number — placeholder or too short']);
    }
    if ($isPlaceholder($message) || strlen($msgTrim) < 1) {
        respond(400, ['error' => 'Invalid message — placeholder or empty']);
    }

    $auth = authenticate();
    $phone = $phoneDigits;

    // === Rate limit: max 30 sends per minute per API key ===
    $db = getDB();
    $rateStmt = $db->prepare(
        'SELECT COUNT(*) FROM messages WHERE api_key_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
    );
    $rateStmt->execute([$auth['key_id']]);
    if ($rateStmt->fetchColumn() >= 30) {
        respond(429, ['error' => 'Rate limit exceeded — max 30 messages per minute per API key']);
    }

    // === Duplicate guard: reject same phone+message within last 30 seconds ===
    $dupStmt = $db->prepare(
        'SELECT id FROM messages WHERE user_id = ? AND phone = ? AND message = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND) LIMIT 1'
    );
    $dupStmt->execute([$auth['user_id'], $phone, $message]);
    if ($dupStmt->fetchColumn()) {
        respond(429, ['error' => 'Duplicate message — same phone+text sent within the last 30 seconds']);
    }

    // Use the type param if given, otherwise fall back to the user's default preference
    $whatsappType = $_GET['type'] ?? null;
    if (!$whatsappType) {
        $db = getDB();
        $waStmt = $db->prepare('SELECT whatsapp_type FROM users WHERE id = ?');
        $waStmt->execute([$auth['user_id']]);
        $whatsappType = $waStmt->fetchColumn() ?: 'whatsapp';
    }

    // Load balance: alternate between whatsapp and whatsapp_business
    if ($whatsappType === 'load_balance') {
        $db = getDB();
        $lastStmt = $db->prepare('SELECT whatsapp_type FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $lastStmt->execute([$auth['user_id']]);
        $lastType = $lastStmt->fetchColumn();
        $whatsappType = ($lastType === 'whatsapp') ? 'whatsapp_business' : 'whatsapp';
    }

    if (!in_array($whatsappType, ['whatsapp', 'whatsapp_business'])) {
        $whatsappType = 'whatsapp';
    }

    // Check subscription
    $subscription = checkSubscription($auth['user_id']);
    if (!$subscription['active']) {
        respond(403, ['error' => $subscription['reason'] ?? 'Subscription inactive', 'plan' => $subscription['plan']]);
    }

    $db = getDB();
    $assignedDevice = null;
    $assignedDeviceId = null;
    try {
        $assigned = assignDevice($auth['user_id'], $whatsappType);
        if ($assigned) {
            $assignedDeviceId = $assigned['device_id'];
            $whatsappType = $assigned['wa_type'];
        }
    } catch (Exception $e) {}

    try {
        $stmt = $db->prepare(
            'INSERT INTO messages (user_id, api_key_id, device_id, phone, message, whatsapp_type, priority) VALUES (?, ?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([$auth['user_id'], $auth['key_id'], $assignedDeviceId, $phone, $message, $whatsappType]);
    } catch (Exception $e) {
        // Fallback: insert without device_id if column doesn't exist
        $stmt = $db->prepare(
            'INSERT INTO messages (user_id, api_key_id, phone, message, whatsapp_type, priority) VALUES (?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([$auth['user_id'], $auth['key_id'], $phone, $message, $whatsappType]);
    }
    $messageId = $db->lastInsertId();

    incrementUsage($auth['user_id']);
    $deviceLabel = $assignedDeviceId ? substr($assignedDeviceId, 0, 8) : 'any';
    logAction($messageId, 'created', "Queued via GET API for $whatsappType to $phone (device: $deviceLabel)");

    respond(201, [
        'success' => true,
        'message_id' => (int)$messageId,
        'status' => 'pending'
    ]);
}

// All other routes require auth
$auth = authenticate();
$authUserId = $auth['user_id'];
$authKeyId = $auth['key_id'];
$authDeviceId = $auth['device_id'] ?? null;   // set when the caller used a device key

// =============================================
// POST /send - Queue a new message (JSON body)
// =============================================
if ($path === '/send' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        respond(400, ['error' => 'Invalid JSON body']);
    }

    $phone = $input['phone'] ?? ($input['to'] ?? null);
    $message = $input['message'] ?? ($input['text'] ?? null);
    $whatsappType = $input['whatsapp_type'] ?? 'whatsapp';
    $priority = intval($input['priority'] ?? 0);
    // Reply routing (support panel): `line` = our number the customer wrote
    // to, or `reply` = true to route wherever their last message came in.
    // Either pins the message to that phone+app and reports delivery back
    // to the webhook.
    $line = preg_replace('/[^0-9]/', '', (string)($input['line'] ?? ''));
    $replyMode = !empty($input['reply']) || $line !== '' || ($input['whatsapp_type'] ?? '') === 'auto';
    $externalRef = isset($input['ref']) ? mb_substr((string)$input['ref'], 0, 128) : null;

    if (!$phone || !$message) {
        respond(400, ['error' => 'phone and message are required']);
    }

    // === Spam guard ===
    $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
    $msgTrim = trim($message);
    $isPlaceholder = function($v) {
        if ($v === '') return true;
        if (preg_match('/^\s*[\[\{<\(]+[a-z_\s]+[\]\}>\)]+\s*$/i', $v)) return true;
        return false;
    };
    if ($phoneDigits === '' || $isPlaceholder($phone) || strlen($phoneDigits) < 7) {
        respond(400, ['error' => 'Invalid phone number — placeholder or too short']);
    }
    if ($isPlaceholder($message) || strlen($msgTrim) < 1) {
        respond(400, ['error' => 'Invalid message — placeholder or empty']);
    }
    $phone = $phoneDigits;

    // === Rate limit: 30/min per API key ===
    $rateDb = getDB();
    $rateStmt = $rateDb->prepare(
        'SELECT COUNT(*) FROM messages WHERE api_key_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
    );
    $rateStmt->execute([$authKeyId]);
    if ($rateStmt->fetchColumn() >= 30) {
        respond(429, ['error' => 'Rate limit exceeded — max 30 messages per minute per API key']);
    }

    // === Duplicate guard: same phone+message within 30s ===
    $dupStmt = $rateDb->prepare(
        'SELECT id FROM messages WHERE user_id = ? AND phone = ? AND message = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND) LIMIT 1'
    );
    $dupStmt->execute([$authUserId, $phone, $message]);
    if ($dupStmt->fetchColumn()) {
        respond(429, ['error' => 'Duplicate message — same phone+text sent within the last 30 seconds']);
    }

    // Load balance: alternate between whatsapp and whatsapp_business
    if ($whatsappType === 'load_balance') {
        $db = getDB();
        $lastStmt = $db->prepare('SELECT whatsapp_type FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $lastStmt->execute([$authUserId]);
        $lastType = $lastStmt->fetchColumn();
        $whatsappType = ($lastType === 'whatsapp') ? 'whatsapp_business' : 'whatsapp';
    }

    if (!in_array($whatsappType, ['whatsapp', 'whatsapp_business'])) {
        $whatsappType = 'whatsapp';
    }

    // Check subscription
    $subscription = checkSubscription($authUserId);
    if (!$subscription['active']) {
        respond(403, ['error' => $subscription['reason'] ?? 'Subscription inactive', 'plan' => $subscription['plan']]);
    }

    $db = getDB();
    $assignedDeviceId = null;
    $pinned = 0;
    if ($replyMode) {
        // An explicit line is honoured first. With "reply" set as well, a line
        // nobody owns (number not filled in on the Devices page yet) falls back
        // to the phone+app the customer's last message came in on - still
        // "the number they wrote to", never some other phone.
        $route = $line !== '' ? findDeviceByLine($authUserId, $line) : null;
        if (!$route && ($line === '' || !empty($input['reply']))) $route = findRouteByIncoming($authUserId, $phone);
        if (!$route) {
            respond(409, ['error' => $line !== ''
                ? "No phone is registered with the number $line. Set the number in the FreeISP Replies app on that phone."
                : "No incoming message from $phone has been seen, so there is no line to reply from."]);
        }
        $assignedDeviceId = $route['device_id'];
        $whatsappType = $route['wa_type'];
        $pinned = 1;
        // Pinned replies need THAT phone online; tell the caller now rather than
        // letting the message quietly expire 5 minutes later.
        $on = $db->prepare('SELECT device_name, (last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS online FROM devices WHERE device_id = ? AND user_id = ?');
        $on->execute([$assignedDeviceId, $authUserId]);
        $dev = $on->fetch();
        if (!$dev || !(int)$dev['online']) {
            respond(503, ['error' => 'The phone "' . ($dev['device_name'] ?? 'unknown') . '" that holds this number is offline (no poll in 5 minutes).']);
        }
    } else {
        try {
            $assigned = assignDevice($authUserId, $whatsappType);
            if ($assigned) {
                $assignedDeviceId = $assigned['device_id'];
                $whatsappType = $assigned['wa_type'];
            }
        } catch (Exception $e) {}
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO messages (user_id, api_key_id, device_id, phone, message, whatsapp_type, priority, pinned, external_ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$authUserId, $authKeyId, $assignedDeviceId, $phone, $message, $whatsappType, $priority, $pinned, $externalRef]);
    } catch (Exception $e) {
        $stmt = $db->prepare(
            'INSERT INTO messages (user_id, api_key_id, phone, message, whatsapp_type, priority) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$authUserId, $authKeyId, $phone, $message, $whatsappType, $priority]);
    }
    $messageId = $db->lastInsertId();

    incrementUsage($authUserId);
    $deviceLabel = $assignedDeviceId ? substr($assignedDeviceId, 0, 8) : 'any';
    logAction($messageId, 'created', ($pinned ? 'Reply pinned' : 'Queued') . " for $whatsappType to $phone (device: $deviceLabel)");

    respond(201, [
        'success' => true,
        'message_id' => (int)$messageId,
        'id' => 'app-' . $messageId,
        'status' => 'pending',
        'pinned' => (bool)$pinned,
        'device_id' => $assignedDeviceId,
        'whatsapp_type' => $whatsappType,
        'info' => "Message queued for delivery via $whatsappType"
    ]);
}

// =============================================
// POST /send-media - the support panel sends a file through a phone.
// multipart/form-data: phone (or to), line, reply, ref, caption, file.
// Same routing as /send (line -> that phone+app, reply -> where the
// customer last wrote), same 201 {id:"app-<n>"} so status events match.
// =============================================
if ($path === '/send-media' && $method === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        respond(413, ['error' => 'Upload too large for the server (post_max_size ' . ini_get('post_max_size') . ')']);
    }
    $phone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ($_POST['to'] ?? '')));
    $caption = trim((string)($_POST['caption'] ?? ''));
    $line = preg_replace('/[^0-9]/', '', (string)($_POST['line'] ?? ''));
    $replyFlag = bridgeBoolean($_POST['reply'] ?? false);
    $externalRef = isset($_POST['ref']) ? mb_substr((string)$_POST['ref'], 0, 128) : null;
    if (strlen($phone) < 7) respond(400, ['error' => 'phone is required']);

    $db = getDB();
    // Rate limit shared with /send
    $rateStmt = $db->prepare('SELECT COUNT(*) FROM messages WHERE api_key_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
    $rateStmt->execute([$authKeyId]);
    if ($rateStmt->fetchColumn() >= 30) respond(429, ['error' => 'Rate limit exceeded — max 30 messages per minute per API key']);

    $route = $line !== '' ? findDeviceByLine($authUserId, $line) : null;
    if (!$route && ($line === '' || $replyFlag)) $route = findRouteByIncoming($authUserId, $phone);
    if (!$route) {
        respond(409, ['error' => $line !== ''
            ? "No phone is registered with the number $line."
            : "No incoming message from $phone has been seen, so there is no line to reply from."]);
    }
    $on = $db->prepare('SELECT device_name, caps, (last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS online FROM devices WHERE device_id = ? AND user_id = ? AND is_active = 1');
    $on->execute([$route['device_id'], $authUserId]);
    $dev = $on->fetch();
    if (!$dev || !(int)$dev['online']) respond(503, ['error' => 'The phone "' . ($dev['device_name'] ?? 'unknown') . '" that holds this number is offline (no poll in 5 minutes).']);
    if (strpos((string)$dev['caps'], 'media') === false) {
        respond(409, ['error' => 'The phone "' . $dev['device_name'] . '" runs an app version without media support. Update the app on that phone.']);
    }

    $stored = mediaStoreUpload($authUserId, $route['device_id'], 'out', 'file', $_POST['kind'] ?? null);
    if (!$stored['ok']) respond($stored['http'], ['error' => $stored['error']]);

    $stmt = $db->prepare(
        'INSERT INTO messages (user_id, api_key_id, device_id, phone, message, whatsapp_type, priority, pinned, external_ref, kind, media_id) VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?, ?, ?)'
    );
    $stmt->execute([$authUserId, $authKeyId, $route['device_id'], $phone, $caption, $route['wa_type'], $externalRef, $stored['kind'], $stored['id']]);
    $messageId = (int)$db->lastInsertId();
    incrementUsage($authUserId);
    logAction($messageId, 'created', "Reply {$stored['kind']} ({$stored['size']} B) pinned for {$route['wa_type']} to $phone (device: " . substr($route['device_id'], 0, 8) . ')');

    respond(201, [
        'success' => true, 'message_id' => $messageId, 'id' => 'app-' . $messageId, 'status' => 'pending', 'pinned' => true,
        'device_id' => $route['device_id'], 'whatsapp_type' => $route['wa_type'],
        'media' => ['id' => $stored['id'], 'kind' => $stored['kind'], 'mime' => $stored['mime'], 'size' => $stored['size'], 'filename' => $stored['filename']],
    ]);
}

// =============================================
// POST /send-bulk - Queue multiple messages
// =============================================
if ($path === '/send-bulk' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['messages']) || !is_array($input['messages'])) {
        respond(400, ['error' => 'messages array is required']);
    }

    $db = getDB();
    $results = [];

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO messages (user_id, api_key_id, phone, message, whatsapp_type, priority) VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($input['messages'] as $msg) {
            $phone = preg_replace('/[^0-9]/', '', $msg['phone'] ?? '');
            $message = $msg['message'] ?? '';
            $whatsappType = $msg['whatsapp_type'] ?? 'whatsapp';
            $priority = intval($msg['priority'] ?? 0);

            if (!$phone || !$message) {
                $results[] = ['error' => 'phone and message required', 'phone' => $msg['phone'] ?? ''];
                continue;
            }

            if (!in_array($whatsappType, ['whatsapp', 'whatsapp_business'])) {
                $whatsappType = 'whatsapp';
            }

            $stmt->execute([$authUserId, $authKeyId, $phone, $message, $whatsappType, $priority]);
            $id = $db->lastInsertId();
            logAction($id, 'created', "Bulk queued for $whatsappType to $phone");
            $results[] = ['message_id' => (int)$id, 'phone' => $phone, 'status' => 'pending'];
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        respond(500, ['error' => 'Bulk insert failed']);
    }

    respond(201, ['success' => true, 'results' => $results]);
}

// =============================================
// GET /pending - Get pending messages (APK polls this)
// =============================================
if ($path === '/pending' && $method === 'GET') {
    $limit = min(intval($_GET['limit'] ?? 10), 50);
    // A device key names the phone; the app's own id is only a fallback for
    // phones still using an account key.
    $deviceId = $authDeviceId ?: ($_GET['device_id'] ?? null);
    $deviceName = $_GET['device_name'] ?? 'Unknown';

    $db = getDB();

    // Auto-register/update device if device_id is provided
    if ($deviceId) {
        $svcAccessibility = intval($_GET['svc_accessibility'] ?? 0);
        $svcNotification = intval($_GET['svc_notification'] ?? 0);
        $svcBattery = intval($_GET['svc_battery'] ?? 0);

        // The phone's own settings travel with every poll: which app(s) it
        // monitors and the number behind each. The phone wins over the
        // dashboard dropdown when it sends them; an app that predates these
        // fields sends nothing and the dashboard value stays.
        $phoneWaType = $_GET['whatsapp_type'] ?? null;
        if (!in_array($phoneWaType, ['whatsapp', 'whatsapp_business', 'both'], true)) $phoneWaType = null;
        $waNumber    = isset($_GET['wa_number']) ? preg_replace('/[^0-9]/', '', $_GET['wa_number']) : null;
        $waBizNumber = isset($_GET['wa_business_number']) ? preg_replace('/[^0-9]/', '', $_GET['wa_business_number']) : null;

        $devCheck = $db->prepare('SELECT id FROM devices WHERE device_id = ? AND user_id = ?');
        $devCheck->execute([$deviceId, $authUserId]);
        if ($devCheck->fetch()) {
            // Don't overwrite device_name — user may have renamed it manually
            $db->prepare('UPDATE devices SET last_seen = NOW(), svc_accessibility = ?, svc_notification = ?, svc_battery = ? WHERE device_id = ? AND user_id = ?')
               ->execute([$svcAccessibility, $svcNotification, $svcBattery, $deviceId, $authUserId]);
        } else {
            $db->prepare('INSERT INTO devices (user_id, device_id, device_name, svc_accessibility, svc_notification, svc_battery, last_seen) VALUES (?, ?, ?, ?, ?, ?, NOW())')
               ->execute([$authUserId, $deviceId, $deviceName, $svcAccessibility, $svcNotification, $svcBattery]);
        }
        $sets = []; $vals = [];
        if ($phoneWaType !== null) { $sets[] = 'whatsapp_type = ?'; $vals[] = $phoneWaType; }
        // Forwarding diagnostics (only when the app sends them)
        if (isset($_GET['app_ver']))  { $sets[] = 'app_version = ?';    $vals[] = mb_substr((string)$_GET['app_ver'], 0, 20); }
        if (isset($_GET['caps']))     { $sets[] = 'caps = ?';           $vals[] = mb_substr(preg_replace('/[^a-z,]/', '', (string)$_GET['caps']), 0, 100); }
        if (isset($_GET['fwd']))      { $sets[] = 'fwd_enabled = ?';    $vals[] = (int)$_GET['fwd']; }
        if (isset($_GET['nl_bound'])) { $sets[] = 'nl_bound = ?';       $vals[] = (int)$_GET['nl_bound']; }
        if (isset($_GET['in_seen']))  { $sets[] = 'inbound_seen = ?';   $vals[] = (int)$_GET['in_seen']; }
        if (isset($_GET['in_queue'])) { $sets[] = 'inbound_queued = ?'; $vals[] = (int)$_GET['in_queue']; }
        if (isset($_GET['last_media']) && $_GET['last_media'] !== '') {
            $parts = explode('|', (string)$_GET['last_media'], 2);
            $sets[] = 'last_media_at = FROM_UNIXTIME(?)'; $vals[] = (int)$parts[0];
            $sets[] = 'last_media_info = ?'; $vals[] = mb_substr($parts[1] ?? '', 0, 4000);
        }
        if (isset($_GET['last_notif']) && $_GET['last_notif'] !== '') {
            // "epochSeconds|package|why" - what the listener last saw from WhatsApp and what it did with it
            $parts = explode('|', (string)$_GET['last_notif'], 2);
            $sets[] = 'last_notif_at = FROM_UNIXTIME(?)'; $vals[] = (int)$parts[0];
            $sets[] = 'last_notif_info = ?'; $vals[] = mb_substr($parts[1] ?? '', 0, 4000);
        }
        if ($waNumber !== null)    { $sets[] = 'wa_number = ?'; $vals[] = $waNumber !== '' ? $waNumber : null; }
        if ($waBizNumber !== null) { $sets[] = 'wa_business_number = ?'; $vals[] = $waBizNumber !== '' ? $waBizNumber : null; }
        if ($sets) {
            $vals[] = $deviceId; $vals[] = $authUserId;
            $db->prepare('UPDATE devices SET ' . implode(', ', $sets) . ' WHERE device_id = ? AND user_id = ?')->execute($vals);
        }

        // Incoming messages whose webhook failed earlier get another go now.
        try { retryFailedWebhooks($authUserId); } catch (Exception $e) { error_log('webhook retry: ' . $e->getMessage()); }
        try { mediaCleanup(); } catch (Exception $e) { error_log('media cleanup: ' . $e->getMessage()); }
    }

    // Auto-expire messages pending for more than 5 minutes to avoid pile-up.
    // A pinned reply that expires is a failed reply as far as the support
    // panel is concerned, so tell it before the row changes.
    try {
        $exp = $db->prepare(
            'SELECT id FROM messages WHERE status = "pending" AND user_id = ? AND pinned = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
        );
        $exp->execute([$authUserId]);
        foreach ($exp->fetchAll(PDO::FETCH_COLUMN) as $pinnedId) {
            reportStatusWebhook($authUserId, (int)$pinnedId, 'failed', 'Phone was unreachable for too long');
        }
    } catch (Exception $e) { /* column may not exist yet on a half-migrated box */ }
    $expired = $db->prepare(
        'UPDATE messages SET status = "expired", error_message = "Expired - phone was unreachable for too long"
         WHERE status = "pending" AND user_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
    );
    $expired->execute([$authUserId]);
    $expiredCount = $expired->rowCount();
    if ($expiredCount > 0) {
        error_log("Auto-expired $expiredCount messages for user $authUserId");
    }

    // Reassign messages from offline devices (no poll in 2 minutes) back to pending pool.
    // Pinned replies stay on their phone: they must leave from the number the
    // customer wrote to, so another device may never pick them up.
    if ($deviceId) {
        $db->prepare(
            'UPDATE messages SET device_id = NULL
             WHERE status = "pending" AND user_id = ? AND device_id IS NOT NULL AND device_id != ? AND pinned = 0
             AND device_id IN (SELECT device_id FROM devices WHERE user_id = ? AND last_seen < DATE_SUB(NOW(), INTERVAL 2 MINUTE))'
        )->execute([$authUserId, $deviceId, $authUserId]);

        // Reassign stuck "sent" messages (dispatched but not delivered in 3 minutes) back to pending
        $reassigned = $db->prepare(
            'UPDATE messages SET status = "pending", device_id = NULL
             WHERE status = "sent" AND user_id = ? AND device_id != ? AND pinned = 0
             AND created_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE)'
        );
        $reassigned->execute([$authUserId, $deviceId]);
        if ($reassigned->rowCount() > 0) {
            error_log("Reassigned " . $reassigned->rowCount() . " stuck messages from other devices for user $authUserId");
        }
    }

    // Get this device's whatsapp_type so we only fetch compatible messages
    $deviceWaType = null;
    if ($deviceId) {
        $dtStmt = $db->prepare('SELECT whatsapp_type FROM devices WHERE device_id = ? AND user_id = ?');
        $dtStmt->execute([$deviceId, $authUserId]);
        $deviceWaType = $dtStmt->fetchColumn() ?: 'whatsapp';
    }

    // Build whatsapp_type filter: only fetch messages this device can handle
    if ($deviceId && $deviceWaType && $deviceWaType !== 'both') {
        // Device only supports one type — only fetch matching messages
        $stmt = $db->prepare(
            'SELECT id, phone, message, whatsapp_type, priority, retry_count, created_at, kind, media_id
             FROM messages
             WHERE status = "pending" AND retry_count < ? AND user_id = ?
             AND (device_id = ? OR device_id IS NULL)
             AND whatsapp_type = ?
             ORDER BY priority DESC, created_at ASC
             LIMIT ?'
        );
        $stmt->execute([MAX_RETRY_COUNT, $authUserId, $deviceId, $deviceWaType, $limit]);
    } elseif ($deviceId) {
        // Device supports "both" — fetch any type
        $stmt = $db->prepare(
            'SELECT id, phone, message, whatsapp_type, priority, retry_count, created_at, kind, media_id
             FROM messages
             WHERE status = "pending" AND retry_count < ? AND user_id = ?
             AND (device_id = ? OR device_id IS NULL)
             ORDER BY priority DESC, created_at ASC
             LIMIT ?'
        );
        $stmt->execute([MAX_RETRY_COUNT, $authUserId, $deviceId, $limit]);
    } else {
        $stmt = $db->prepare(
            'SELECT id, phone, message, whatsapp_type, priority, retry_count, created_at, kind, media_id
             FROM messages
             WHERE status = "pending" AND retry_count < ? AND user_id = ?
             ORDER BY priority DESC, created_at ASC
             LIMIT ?'
        );
        $stmt->execute([MAX_RETRY_COUNT, $authUserId, $limit]);
    }
    $messages = $stmt->fetchAll();

    // A media reply needs an app that can send files; an older app would try
    // to send the caption as text and report it delivered. Hold such rows for
    // this phone instead (they expire after 5 minutes like any other).
    $devCaps = '';
    if ($deviceId) {
        $cs = $db->prepare('SELECT caps FROM devices WHERE device_id = ?');
        $cs->execute([$deviceId]);
        $devCaps = (string)$cs->fetchColumn();
    }
    foreach ($messages as $i => &$m) {
        $m['kind'] = $m['kind'] ?? 'text';
        if ($m['kind'] !== 'text') {
            if (strpos($devCaps, 'media') === false || empty($m['media_id'])) { unset($messages[$i]); continue; }
            $media = mediaRow($m['media_id']);
            if (!$media) { unset($messages[$i]); continue; }
            $m['caption'] = $m['message'];
            $m['media_url'] = mediaSignedUrl($media, 'dev:' . $deviceId, 900);
            $m['media_mime'] = $media['mime'];
            $m['media_name'] = $media['filename'];
            $m['media_size'] = (int)$media['size'];
            $m['media_kind'] = $media['kind'];
        }
        unset($m['media_id']);
    }
    unset($m);
    $messages = array_values($messages);

    // Mark as sent (processing) and assign to this device
    if (!empty($messages)) {
        $ids = array_column($messages, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE messages SET status = 'sent', device_id = ? WHERE id IN ($placeholders)")
           ->execute(array_merge([$deviceId], $ids));
        foreach ($ids as $id) {
            logAction($id, 'dispatched', "Sent to device " . ($deviceId ? substr($deviceId, 0, 8) : 'unknown') . " ($deviceWaType)");
        }
    }

    respond(200, [
        'count' => count($messages),
        'messages' => $messages
    ]);
}

// =============================================
// POST /inbound - APK forwards messages it saw arrive in WhatsApp
// Body: { device_id, messages: [ { key, phone, sender_name, text, whatsapp_type, timestamp } ] }
// Each new one is stored and pushed to the user's webhook straight away.
// =============================================
if ($path === '/inbound' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) respond(400, ['error' => 'Invalid JSON body']);

    $deviceId = $authDeviceId ?: (string)($input['device_id'] ?? '');
    $items = $input['messages'] ?? null;
    if ($deviceId === '' || !is_array($items)) respond(400, ['error' => 'device_id and messages[] are required']);

    $db = getDB();
    $devStmt = $db->prepare('SELECT * FROM devices WHERE device_id = ? AND user_id = ?');
    $devStmt->execute([$deviceId, $authUserId]);
    $device = $devStmt->fetch();
    if (!$device) respond(404, ['error' => 'Unknown device. Let the app poll once first.']);

    $results = [];
    $newRows = [];
    foreach (array_slice($items, 0, 50) as $m) {
        $phone = preg_replace('/[^0-9]/', '', (string)($m['phone'] ?? ''));
        $text  = trim((string)($m['text'] ?? ''));
        $waType = ($m['whatsapp_type'] ?? '') === 'whatsapp_business' ? 'whatsapp_business' : 'whatsapp';
        $name  = mb_substr(trim((string)($m['sender_name'] ?? '')), 0, 100);
        $ts    = (int)($m['timestamp'] ?? 0);
        if ($ts > 20000000000) $ts = intdiv($ts, 1000);   // Android gives millis
        if ($ts <= 0 || $ts > time() + 300) $ts = time();
        $key = (string)($m['key'] ?? '');
        if ($key === '') $key = sha1($phone . '|' . $waType . '|' . $ts . '|' . $text);
        $key = substr($key, 0, 64);
        $isLid = bridgeBoolean($m['lid'] ?? false) ? 1 : 0;

        if (strlen($phone) < 7 || $text === '') {
            $results[] = ['key' => $key, 'accepted' => false, 'reason' => 'phone or text missing'];
            continue;
        }

        $ins = $db->prepare(
            'INSERT IGNORE INTO incoming_messages (user_id, device_id, phone, is_lid, sender_name, message, whatsapp_type, line, dedupe_key, received_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))'
        );
        $ins->execute([$authUserId, $deviceId, $phone, $isLid, $name !== '' ? $name : null, mb_substr($text, 0, 60000), $waType,
                       deviceLine($device, $waType) ?: null, $key, $ts]);
        if ($ins->rowCount() === 0) {
            $results[] = ['key' => $key, 'accepted' => true, 'duplicate' => true];
            continue;
        }
        $newId = (int)$db->lastInsertId();
        // Backlog guard: a message older than an hour is history the phone is
        // replaying, not a customer waiting. Keep it in the Inbox, never push it
        // (the support panel would auto-reply to a stale chat).
        if ($ts < time() - 3600) {
            $db->prepare("UPDATE incoming_messages SET webhook_status = 'skipped', webhook_response = 'too old to forward (backlog)', webhook_at = NOW() WHERE id = ?")->execute([$newId]);
            $results[] = ['key' => $key, 'accepted' => true, 'id' => $newId, 'stale' => true];
            continue;
        }
        $newRows[] = $newId;
        $results[] = ['key' => $key, 'accepted' => true, 'id' => $newId];
    }

    // Ack to the phone first so a slow webhook never holds the device, then push.
    $out = json_encode(['success' => true, 'accepted' => count($newRows), 'results' => $results]);
    http_response_code(200);
    header('Content-Length: ' . strlen($out));
    header('Connection: close');
    echo $out;
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); } else { @ob_end_flush(); @flush(); }

    foreach ($newRows as $newId) {
        $row = $db->prepare('SELECT * FROM incoming_messages WHERE id = ?');
        $row->execute([$newId]);
        if ($r = $row->fetch()) {
            try { deliverIncomingWebhook($r); } catch (Exception $e) { error_log('inbound webhook: ' . $e->getMessage()); }
        }
    }
    exit;
}

// =============================================
// POST /inbound-media - the phone forwards a photo / video / voice note /
// document it saw arrive. multipart: key, phone, lid, sender_name,
// whatsapp_type, timestamp, text (WhatsApp's caption line), kind, file.
// Stored like /inbound, pushed to the webhook with the media block.
// =============================================
if ($path === '/inbound-media' && $method === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        respond(413, ['error' => 'Upload too large for the server (post_max_size ' . ini_get('post_max_size') . ')']);
    }
    $deviceId = $authDeviceId ?: (string)($_POST['device_id'] ?? '');
    if ($deviceId === '') respond(400, ['error' => 'device key required']);
    $db = getDB();
    $devStmt = $db->prepare('SELECT * FROM devices WHERE device_id = ? AND user_id = ?');
    $devStmt->execute([$deviceId, $authUserId]);
    $device = $devStmt->fetch();
    if (!$device) respond(404, ['error' => 'Unknown device']);

    $phone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
    $waType = ($_POST['whatsapp_type'] ?? '') === 'whatsapp_business' ? 'whatsapp_business' : 'whatsapp';
    $name = mb_substr(trim((string)($_POST['sender_name'] ?? '')), 0, 100);
    $isLid = bridgeBoolean($_POST['lid'] ?? false) ? 1 : 0;
    $ts = (int)($_POST['timestamp'] ?? 0);
    if ($ts > 20000000000) $ts = intdiv($ts, 1000);
    if ($ts <= 0 || $ts > time() + 300) $ts = time();
    $text = trim((string)($_POST['text'] ?? ''));
    $key = substr((string)($_POST['key'] ?? ''), 0, 64);
    if (strlen($phone) < 7) respond(400, ['error' => 'phone missing']);

    // Already have it (retry after a lost response)? Say so without touching disk.
    if ($key !== '') {
        $chk = $db->prepare('SELECT id, media_id FROM incoming_messages WHERE device_id = ? AND dedupe_key = ?');
        $chk->execute([$deviceId, $key]);
        if ($have = $chk->fetch()) {
            if (!empty($have['media_id'])) respond(200, ['success' => true, 'accepted' => true, 'duplicate' => true, 'id' => (int)$have['id']]);
            // The text placeholder went out first (older app or a race) and the
            // panel already has that bubble; the file gets a row of its own.
            $key = substr($key, 0, 60) . '-m';
        }
    }

    $stored = mediaStoreUpload($authUserId, $deviceId, 'in', 'file', $_POST['kind'] ?? null);
    if (!$stored['ok']) respond($stored['http'], ['error' => $stored['error']]);
    if ($key === '') $key = sha1($phone . '|' . $waType . '|' . $ts . '|' . $stored['sha256']);
    $caption = mediaCaptionFromText($text, $stored['kind']);

    // WhatsApp's "You" twin of the same notification carries the same picture:
    // same bytes, same sender, within two minutes. A customer re-sending a
    // photo later, or another customer sending the same file, still lands.
    $twin = $db->prepare('SELECT m.id FROM media m JOIN incoming_messages i ON i.media_id = m.id
        WHERE m.device_id = ? AND m.sha256 = ? AND m.id <> ? AND m.direction = "in" AND i.phone = ?
          AND m.created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE) LIMIT 1');
    $twin->execute([$deviceId, $stored['sha256'], $stored['id'], $phone]);
    if ($twin->fetchColumn()) {
        mediaDelete($stored['id']);
        respond(200, ['success' => true, 'accepted' => true, 'duplicate' => true]);
    }

    {
        $ins = $db->prepare(
            'INSERT IGNORE INTO incoming_messages (user_id, device_id, phone, is_lid, sender_name, message, whatsapp_type, line, dedupe_key, received_at, kind, media_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?)'
        );
        $ins->execute([$authUserId, $deviceId, $phone, $isLid, $name !== '' ? $name : null, $caption !== '' ? $caption : $text, $waType,
                       deviceLine($device, $waType) ?: null, $key, $ts, $stored['kind'], $stored['id']]);
        if ($ins->rowCount() === 0) {
            mediaDelete($stored['id']);
            respond(200, ['success' => true, 'accepted' => true, 'duplicate' => true]);
        }
        $newId = (int)$db->lastInsertId();
    }
    if ($ts < time() - 3600) {
        $db->prepare("UPDATE incoming_messages SET webhook_status = 'skipped', webhook_response = 'too old to forward (backlog)', webhook_at = NOW() WHERE id = ?")->execute([$newId]);
        respond(200, ['success' => true, 'accepted' => true, 'id' => $newId, 'media_id' => $stored['id'], 'stale' => true]);
    }

    $out = json_encode(['success' => true, 'accepted' => true, 'id' => $newId, 'media_id' => $stored['id'], 'key' => $key]);
    http_response_code(200);
    header('Content-Length: ' . strlen($out));
    header('Connection: close');
    echo $out;
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); } else { @ob_end_flush(); @flush(); }
    $row = $db->prepare('SELECT * FROM incoming_messages WHERE id = ?');
    $row->execute([$newId]);
    if ($r = $row->fetch()) {
        try { deliverIncomingWebhook($r); } catch (Exception $e) { error_log('inbound-media webhook: ' . $e->getMessage()); }
    }
    exit;
}

// =============================================
// GET /lines - Our numbers: every phone+app pair and whether it is online.
// The support panel reads this to know which numbers answer through the app.
// =============================================
if ($path === '/lines' && $method === 'GET') {
    respond(200, ['lines' => userLines($authUserId)]);
}

// =============================================
// POST /status - APK reports message status
// =============================================
if ($path === '/status' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $messageId = $input['message_id'] ?? null;
    $status = $input['status'] ?? null;
    // Devices sometimes report multi-KB stack traces; the column is varchar(500) and the fatal left messages dangling (19k/day on 2026-09-28).
    $errorMessage = isset($input['error_message']) ? mb_substr((string)$input['error_message'], 0, 500) : null;

    if (!$messageId || !$status) {
        respond(400, ['error' => 'message_id and status are required']);
    }

    if (!in_array($status, ['delivered', 'failed'])) {
        respond(400, ['error' => 'status must be delivered or failed']);
    }

    $db = getDB();

    // Verify the message belongs to this user
    $check = $db->prepare('SELECT id, retry_count, pinned, device_id FROM messages WHERE id = ? AND user_id = ?');
    $check->execute([$messageId, $authUserId]);
    $msg = $check->fetch();

    if (!$msg) {
        respond(404, ['error' => 'Message not found']);
    }
    $isPinned = (int)($msg['pinned'] ?? 0) === 1;

    if ($status === 'delivered') {
        $stmt = $db->prepare(
            'UPDATE messages SET status = "delivered", sent_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$messageId]);
        logAction($messageId, 'delivered', 'Message sent via WhatsApp');
        if ($isPinned) reportStatusWebhook($authUserId, (int)$messageId, 'delivered');
    } elseif ($isPinned) {
        // A reply must leave from the same phone+app the customer wrote to, so
        // never hop to another device. One retry on the same phone (WhatsApp UI
        // hiccups are common), then report the failure to the support panel.
        $errLower = strtolower((string)$errorMessage);
        $permanent = strpos($errLower, 'not on whatsapp') !== false || strpos($errLower, 'invalid-phone') !== false
            || strpos($errLower, 'not-installed') !== false;
        if (!$permanent && (int)($msg['retry_count'] ?? 0) == 0) {
            $db->prepare('UPDATE messages SET status = "pending", retry_count = 1, error_message = NULL WHERE id = ?')
               ->execute([$messageId]);
            logAction($messageId, 'auto-retry', "Failed on its own phone, retrying once there: $errorMessage");
        } else {
            $db->prepare('UPDATE messages SET status = "failed", error_message = ?, retry_count = ? WHERE id = ?')
               ->execute([$errorMessage, MAX_RETRY_COUNT, $messageId]);
            logAction($messageId, 'failed', "Reply failed: $errorMessage");
            reportStatusWebhook($authUserId, (int)$messageId, 'failed', $errorMessage ? strtok($errorMessage, "\n") : 'Send failed on the phone');
        }
    } else {
        // Detect permanent-failure reasons reported by the APK. These are
        // failures where retrying — on the same or a different device —
        // can't possibly succeed, so skip the auto-retry path and mark
        // final-failed immediately. Without this, "phone number isn't on
        // WhatsApp" gets one auto-retry on another device, wasting battery
        // and screen wakes for nothing.
        $errLower = strtolower((string)$errorMessage);
        $isPermanentFailure = (
            strpos($errLower, 'recipient-not-on-whatsapp') !== false
            || strpos($errLower, 'invalid-phone') !== false
            || strpos($errLower, "isn't on whatsapp") !== false
            || strpos($errLower, 'is not on whatsapp') !== false
            || strpos($errLower, 'not on whatsapp') !== false
        );

        if ($isPermanentFailure) {
            // Set retry_count = MAX_RETRY_COUNT so the /pending query
            // (retry_count < MAX_RETRY_COUNT) won't re-pick this up. The
            // dashboard's "Retry failed" button resets retry_count to 0
            // and remains a deliberate manual override.
            $db->prepare('UPDATE messages SET status = "failed", error_message = ?, retry_count = ? WHERE id = ?')
               ->execute([$errorMessage, MAX_RETRY_COUNT, $messageId]);
            logAction($messageId, 'failed', "Permanent failure (no retry): $errorMessage");
        }
        // Failed — auto-retry ONCE on a different device if retry_count is 0
        elseif (($msg['retry_count'] ?? 0) == 0) {
            // Get the current device that failed
            $failedDevice = $db->prepare('SELECT device_id FROM messages WHERE id = ?');
            $failedDevice->execute([$messageId]);
            $failedDeviceId = $failedDevice->fetchColumn();

            // Find another active device
            $otherDevice = $db->prepare(
                'SELECT device_id FROM devices WHERE user_id = ? AND is_active = 1 AND device_id != ? ORDER BY last_seen DESC LIMIT 1'
            );
            $otherDevice->execute([$authUserId, $failedDeviceId ?: '']);
            $newDeviceId = $otherDevice->fetchColumn();

            if ($newDeviceId) {
                // Get the new device's whatsapp_type so we don't send biz to a personal-only device
                $newDevTypeStmt = $db->prepare('SELECT whatsapp_type FROM devices WHERE device_id = ?');
                $newDevTypeStmt->execute([$newDeviceId]);
                $newDevType = $newDevTypeStmt->fetchColumn() ?: 'whatsapp';
                if ($newDevType === 'both') $newDevType = 'whatsapp'; // default to personal for retry

                // Retry once on another device with correct type
                $db->prepare('UPDATE messages SET status = "pending", device_id = ?, whatsapp_type = ?, retry_count = 1, error_message = NULL WHERE id = ?')
                   ->execute([$newDeviceId, $newDevType, $messageId]);
                logAction($messageId, 'auto-retry', "Failed on device " . substr($failedDeviceId ?? '', 0, 8) . ", retrying on " . substr($newDeviceId, 0, 8) . " as $newDevType");
            } else {
                // No other device available — mark as failed
                $db->prepare('UPDATE messages SET status = "failed", error_message = ?, retry_count = 1 WHERE id = ?')
                   ->execute([$errorMessage, $messageId]);
                logAction($messageId, 'failed', "Failed: $errorMessage (no other device available)");
            }
        } else {
            // Already retried once — mark as final failed
            $db->prepare('UPDATE messages SET status = "failed", error_message = ? WHERE id = ?')
               ->execute([$errorMessage, $messageId]);
            logAction($messageId, 'failed', "Failed after retry: $errorMessage");
        }
    }

    respond(200, ['success' => true]);
}

// =============================================
// GET /messages - List user's messages
// =============================================
if ($path === '/messages' && $method === 'GET') {
    $status = $_GET['status'] ?? null;
    $phone = $_GET['phone'] ?? null;
    $limit = min(intval($_GET['limit'] ?? 50), 200);
    $offset = intval($_GET['offset'] ?? 0);

    $where = ['user_id = ?'];
    $params = [$authUserId];

    if ($status) {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($phone) {
        $where[] = 'phone = ?';
        $params[] = preg_replace('/[^0-9]/', '', $phone);
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $db = getDB();

    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM messages $whereClause");
    $countStmt->execute($params);
    $total = $countStmt->fetch()['total'];

    $params[] = $limit;
    $params[] = $offset;
    $stmt = $db->prepare(
        "SELECT id, phone, message, whatsapp_type, status, retry_count, priority, created_at, updated_at, sent_at, error_message
         FROM messages $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute($params);
    $messages = $stmt->fetchAll();

    respond(200, [
        'total' => (int)$total,
        'limit' => $limit,
        'offset' => $offset,
        'messages' => $messages
    ]);
}

// No route matched
respond(404, ['error' => 'Endpoint not found. Available: /send, /send-bulk, /pending, /status, /messages, /health']);
