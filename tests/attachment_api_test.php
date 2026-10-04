<?php
// Local-only HTTP integration test. Never connects to the configured relay DB
// or WhatsApp. Requires curl, fileinfo, mbstring, and pdo_sqlite.
foreach (['curl', 'fileinfo', 'mbstring', 'pdo_sqlite'] as $extension) {
    if (!extension_loaded($extension)) exit("Missing test extension: $extension\n");
}
$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/wa-attachment-test-' . bin2hex(random_bytes(6));
mkdir($temp, 0700, true);
mkdir($temp . '/media');
$server = null;
$checks = 0;
function check($condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function request(string $path, ?array $fields = null, array $headers = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => array_merge(['X-API-Key: test-account-key'], $headers),
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
            $responseHeaders[] = trim($line); return strlen($line);
        },
    ]);
    if ($fields !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields]);
    $body = curl_exec($ch);
    if ($body === false) throw new RuntimeException(curl_error($ch));
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body, $responseHeaders];
}
try {
    foreach (['api.php', 'media_lib.php', 'webhook_lib.php'] as $file) copy($root . '/' . $file, $temp . '/' . $file);
    file_put_contents($temp . '/config.php', '<?php define("ALLOWED_ORIGIN", "*"); define("APP_VERSION", "test"); define("MEDIA_DIR", __DIR__ . "/media");');
    // Real SQLite persistence; adapt the small MySQL syntax surface used by
    // these endpoints. All business logic runs from the real source files.
    file_put_contents($temp . '/database.php', <<<'PHP'
<?php
class TestDB extends PDO {
    public static function sql(string $sql): string {
        $sql = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql);
        return preg_replace_callback('/DATE_SUB\(NOW\(\), INTERVAL (\d+) MINUTE\)/i',
            fn($m) => "datetime('now', '-{$m[1]} minutes')", $sql);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(self::sql($query), $options);
    }
}
function getDB() {
    static $db;
    if (!$db) {
        $db = new TestDB('sqlite:' . __DIR__ . '/test.sqlite', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->sqliteCreateFunction('NOW', fn() => gmdate('Y-m-d H:i:s'));
        $db->sqliteCreateFunction('FROM_UNIXTIME', fn($ts) => gmdate('Y-m-d H:i:s', $ts));
        $db->sqliteCreateFunction('UNIX_TIMESTAMP', fn($date) => strtotime($date . ' UTC'));
    }
    return $db;
}
PHP);
    require $temp . '/database.php';
    $db = getDB();
    $db->exec(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY, is_active INT, media_secret TEXT, webhook_url TEXT, webhook_secret TEXT, webhook_enabled INT);
CREATE TABLE api_keys (id INTEGER PRIMARY KEY, user_id INT, api_key TEXT, is_active INT, last_used_at TEXT, request_count INT DEFAULT 0);
CREATE TABLE devices (id INTEGER PRIMARY KEY, device_id TEXT, user_id INT, device_key TEXT, device_name TEXT, whatsapp_type TEXT, wa_number TEXT, wa_business_number TEXT, caps TEXT, is_active INT, last_seen TEXT);
CREATE TABLE subscriptions (user_id INT, is_active INT, subscriptions_used INT, messages_used INT DEFAULT 0);
CREATE TABLE media (id TEXT PRIMARY KEY, user_id INT, device_id TEXT, direction TEXT, kind TEXT, mime TEXT, size INT, filename TEXT, sha256 TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE incoming_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, device_id TEXT, phone TEXT, is_lid INT, sender_name TEXT, message TEXT, whatsapp_type TEXT, line TEXT, dedupe_key TEXT, received_at TEXT, kind TEXT, media_id TEXT, webhook_status TEXT DEFAULT 'pending', webhook_response TEXT, webhook_at TEXT, webhook_attempts INT DEFAULT 0, UNIQUE(device_id, dedupe_key));
CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, api_key_id INT, device_id TEXT, phone TEXT, message TEXT, whatsapp_type TEXT, priority INT, pinned INT, external_ref TEXT, kind TEXT, media_id TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE message_log (message_id INT, action TEXT, details TEXT);
INSERT INTO users VALUES (1,1,'test-media-secret','','',0);
INSERT INTO api_keys (id,user_id,api_key,is_active) VALUES (1,1,'test-account-key',1);
INSERT INTO devices VALUES (1,'test-device',1,'test-device-key','Test phone','whatsapp','15550000001','','media',1,CURRENT_TIMESTAMP);
SQL);
    copy($root . '/logo.png', $temp . '/photo.png');
    file_put_contents($temp . '/document.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
    file_put_contents($temp . '/audio.wav', 'RIFF' . pack('V', 40) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', 4) . "\0\0\0\0");
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$listener) throw new RuntimeException($error);
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $base = 'http://' . $address;
    $command = [PHP_BINARY];
    foreach (['curl', 'fileinfo', 'mbstring', 'pdo_sqlite'] as $ext) {
        // Reuse explicitly enabled CLI modules in the server subprocess.
        $command[] = '-d'; $command[] = 'extension=' . $ext;
    }
    array_push($command, '-d', 'upload_max_filesize=16M', '-d', 'post_max_size=20M', '-S', $address, '-t', $temp);
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Could not start test server');
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, .1);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    foreach (['image' => ['photo.png', 'image/png'], 'document' => ['document.pdf', 'application/pdf'], 'audio' => ['audio.wav', 'audio/wav']] as $kind => [$name, $mime]) {
        $fields = ['device_id' => 'test-device', 'key' => 'test-' . $kind, 'phone' => '15550000002', 'lid' => 'false', 'sender_name' => 'Test sender', 'text' => $kind, 'whatsapp_type' => 'whatsapp', 'timestamp' => (string)time(), 'kind' => $kind,
            'file' => new CURLFile($temp . '/' . $name, $mime, $name)];
        [$status, $body] = request('/api.php/inbound-media', $fields);
        check($status === 200, "Incoming $kind failed: HTTP $status $body");
        $result = json_decode($body, true);
        check(!empty($result['media_id']), "Incoming $kind has no media ID: $body");
        $row = $db->query("SELECT * FROM incoming_messages WHERE dedupe_key = 'test-$kind'")->fetch();
        check((int)$row['is_lid'] === 0, "Multipart lid=false changed $kind phone number into a LID");
        check($row['kind'] === $kind, "Wrong incoming kind for $kind");
        [$status, $bytes] = request('/api.php/media/' . $result['media_id']);
        check($status === 200 && $bytes === file_get_contents($temp . '/' . $name), "Incoming $kind download differs");
        [$status, $body] = request('/api.php/send-media', ['phone' => '15550000002', 'line' => '15550000001', 'reply' => 'false', 'kind' => $kind, 'file' => new CURLFile($temp . '/' . $name, $mime, $name)]);
        check($status === 201, "Outgoing $kind failed: HTTP $status $body");
        $result = json_decode($body, true);
        check($result['media']['kind'] === $kind && $result['device_id'] === 'test-device', "Outgoing $kind route/kind wrong");
    }
    [$status, $body] = request('/api.php/send-media', ['phone' => '15550000002', 'line' => '15550000999', 'reply' => 'false', 'file' => new CURLFile($temp . '/photo.png', 'image/png', 'photo.png')]);
    check($status === 409, 'reply=false silently switched to another phone line');
    $db->exec('UPDATE devices SET is_active = 0');
    [$status] = request('/api.php/send-media', ['phone' => '15550000002', 'reply' => 'true', 'file' => new CURLFile($temp . '/photo.png', 'image/png', 'photo.png')]);
    check($status === 503, 'Media was queued to a disabled reply phone');
    echo "PASS: $checks attachment API checks (image, document, audio; both directions; phone identity and routing)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    if (is_file($temp . '/server.log')) fwrite(STDERR, file_get_contents($temp . '/server.log'));
    $failed = true;
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $db = null;
    // Delete only the unique temporary directory created by this test.
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname()); }
    @rmdir($temp);
}
exit(!empty($failed) ? 1 : 0);
