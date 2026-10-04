<?php
/**
 * Media storage for the two-way bridge: photos, videos, voice notes and
 * documents that customers send to a phone (direction 'in') and files the
 * support panel sends out through a phone (direction 'out').
 *
 * Bytes live OUTSIDE the web root (MEDIA_DIR) and leave the server only
 * through api.php's GET /media/<id>, either with a signed, expiring URL or
 * with an API key, or through the dashboard's media.php (session login).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

if (!defined('MEDIA_DIR')) define('MEDIA_DIR', '/var/lib/whatsappreplies/media');

// Per-kind caps (bytes). WhatsApp itself caps video messages at 16 MB and
// re-encodes photos; the panel compresses images before upload.
const MEDIA_CAPS = [
    'image'    => 10 * 1024 * 1024,
    'sticker'  => 1 * 1024 * 1024,
    'video'    => 16 * 1024 * 1024,
    'audio'    => 16 * 1024 * 1024,
    'document' => 16 * 1024 * 1024,
];

// Sniffed MIME -> kind. Anything else is refused (415).
const MEDIA_MIMES = [
    'image/jpeg' => 'image', 'image/png' => 'image', 'image/webp' => 'image', 'image/gif' => 'image',
    'video/mp4' => 'video', 'video/3gpp' => 'video', 'video/quicktime' => 'video', 'video/webm' => 'video',
    'audio/ogg' => 'audio', 'audio/opus' => 'audio', 'audio/mpeg' => 'audio', 'audio/mp4' => 'audio',
    'audio/x-m4a' => 'audio', 'audio/aac' => 'audio', 'audio/amr' => 'audio', 'audio/amr-wb' => 'audio', 'audio/x-wav' => 'audio', 'audio/wav' => 'audio',
    'audio/webm' => 'audio', 'audio/flac' => 'audio', 'audio/aiff' => 'audio', 'audio/3gpp' => 'audio',
    'application/pdf' => 'document', 'text/plain' => 'document', 'text/csv' => 'document', 'application/zip' => 'document',
    'application/msword' => 'document',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'document',
    'application/vnd.ms-excel' => 'document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'document',
    'application/vnd.ms-powerpoint' => 'document',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'document',
    'application/rtf' => 'document', 'application/vnd.oasis.opendocument.text' => 'document',
    'application/vnd.oasis.opendocument.spreadsheet' => 'document', 'application/vnd.oasis.opendocument.presentation' => 'document',
    'application/vnd.rar' => 'document', 'application/x-7z-compressed' => 'document',
];

const MEDIA_EXT = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
    'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
    'audio/ogg' => 'ogg', 'audio/opus' => 'opus', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
    'audio/aac' => 'aac', 'audio/amr' => 'amr', 'audio/amr-wb' => 'amr', 'audio/x-wav' => 'wav', 'audio/wav' => 'wav',
    'audio/webm' => 'webm', 'audio/flac' => 'flac', 'audio/aiff' => 'aiff', 'audio/3gpp' => '3gp',
    'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv', 'application/zip' => 'zip',
];

function mediaDirWritable(): bool {
    if (!is_dir(MEDIA_DIR)) @mkdir(MEDIA_DIR, 0750, true);
    return is_dir(MEDIA_DIR) && is_writable(MEDIA_DIR);
}

function mediaPath(string $id, string $variant = ''): string {
    return MEDIA_DIR . '/' . $id . ($variant !== '' ? '.' . $variant : '');
}

function mediaValidId(string $id): bool {
    return (bool)preg_match('/^[a-f0-9]{32}$/', $id);
}

// Client filenames are metadata only: never a path.
function mediaCleanName(string $name, string $mime): string {
    $name = preg_replace('/[\x00-\x1f\/\\\\]+/', '', basename(str_replace('\\', '/', $name)));
    $name = trim(function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120));
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'file.' . (MEDIA_EXT[$mime] ?? 'bin');
    }
    return $name;
}

// Normalize libmagic aliases. MP4/WebM/3GP are shared audio/video containers:
// an audio kind hint may narrow one to audio, but cannot override unrelated bytes.
function mediaFileType(string $mime, ?string $kindHint = null): array {
    $mime = strtolower(trim(explode(';', $mime, 2)[0]));
    $aliases = [
        'application/ogg' => 'audio/ogg', 'audio/x-ogg' => 'audio/ogg',
        'audio/x-flac' => 'audio/flac', 'audio/x-aiff' => 'audio/aiff',
        'audio/x-hx-aac-adts' => 'audio/aac', 'audio/vnd.wave' => 'audio/wav',
        'application/x-zip-compressed' => 'application/zip',
        'application/x-rar' => 'application/vnd.rar', 'application/x-rar-compressed' => 'application/vnd.rar',
        'text/rtf' => 'application/rtf',
    ];
    $mime = $aliases[$mime] ?? $mime;
    if ($kindHint === 'audio') {
        $mime = ['video/mp4' => 'audio/mp4', 'video/webm' => 'audio/webm', 'video/3gpp' => 'audio/3gpp'][$mime] ?? $mime;
    }
    $kind = MEDIA_MIMES[$mime] ?? null;
    if ($mime === 'application/octet-stream' && $kindHint === 'document') $kind = 'document';
    if ($kindHint === 'sticker' && $kind === 'image') $kind = 'sticker';
    return ['mime' => $mime, 'kind' => $kind];
}

/**
 * Validate and store an uploaded file ($_FILES[$field]).
 * Returns ['ok' => true, 'id', 'kind', 'mime', 'size', 'filename', 'sha256']
 * or ['ok' => false, 'http' => int, 'error' => string]. Never throws.
 */
function mediaStoreUpload(int $userId, ?string $deviceId, string $direction, string $field = 'file', ?string $kindHint = null): array {
    $id = null;
    try {
    // PHP silently empties $_POST and $_FILES when the body exceeds post_max_size.
    if (empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        return ['ok' => false, 'http' => 413, 'error' => 'Upload too large for the server (post_max_size ' . ini_get('post_max_size') . ')'];
    }
    $f = $_FILES[$field] ?? null;
    if (!$f || !isset($f['error'])) return ['ok' => false, 'http' => 400, 'error' => "No file in field '$field'"];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $why = [UPLOAD_ERR_INI_SIZE => 'file larger than upload_max_filesize (' . ini_get('upload_max_filesize') . ')',
                UPLOAD_ERR_FORM_SIZE => 'file larger than the form allows', UPLOAD_ERR_PARTIAL => 'upload was cut short',
                UPLOAD_ERR_NO_FILE => 'no file received', UPLOAD_ERR_NO_TMP_DIR => 'server upload temporary directory is missing',
                UPLOAD_ERR_CANT_WRITE => 'server could not write the upload', UPLOAD_ERR_EXTENSION => 'server extension stopped the upload'][$f['error']] ?? ('upload error ' . $f['error']);
        $http = in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 :
            (in_array($f['error'], [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true) ? 503 : 400);
        return ['ok' => false, 'http' => $http, 'error' => $why];
    }
    if (!is_uploaded_file($f['tmp_name'])) return ['ok' => false, 'http' => 400, 'error' => 'not an upload'];
    if (!mediaDirWritable()) return ['ok' => false, 'http' => 503, 'error' => 'media storage not writable on the server'];
    if (@disk_free_space(MEDIA_DIR) !== false && disk_free_space(MEDIA_DIR) < 1024 * 1024 * 1024) {
        return ['ok' => false, 'http' => 507, 'error' => 'server disk nearly full'];
    }

    if (!function_exists('finfo_open')) return ['ok' => false, 'http' => 503, 'error' => 'The server needs the PHP fileinfo extension to accept attachments'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) return ['ok' => false, 'http' => 503, 'error' => 'The server could not inspect the attachment type'];
    $mime  = (string)finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);
    $type = mediaFileType($mime, $kindHint);
    $mime = $type['mime'];
    $kind = $type['kind'];
    if ($kind === null) return ['ok' => false, 'http' => 415, 'error' => "Unsupported file type $mime"];
    $size = (int)$f['size'];
    if ($size <= 0) return ['ok' => false, 'http' => 400, 'error' => 'empty file'];
    if ($size > (MEDIA_CAPS[$kind] ?? MEDIA_CAPS['document'])) {
        return ['ok' => false, 'http' => 413, 'error' => ucfirst($kind) . ' larger than ' . round(MEDIA_CAPS[$kind] / 1048576) . ' MB'];
    }

    $id = bin2hex(random_bytes(16));
    $sha = hash_file('sha256', $f['tmp_name']);
    $name = mediaCleanName((string)($f['name'] ?? ''), $mime);
    $part = mediaPath($id, 'part');
    if (!@move_uploaded_file($f['tmp_name'], $part)) {
        return ['ok' => false, 'http' => 500, 'error' => 'could not store the file'];
    }
    @chmod($part, 0640);
    if ($kind === 'image' || $kind === 'sticker') mediaMakeThumb($part, mediaPath($id, 'thumb'), 240);
    if (!@rename($part, mediaPath($id))) {
        @unlink($part);
        @unlink(mediaPath($id, 'thumb'));
        return ['ok' => false, 'http' => 500, 'error' => 'could not finalise the file'];
    }
    @file_put_contents(mediaPath($id, 'meta'), json_encode([
        'id' => $id, 'user_id' => $userId, 'device_id' => $deviceId, 'direction' => $direction,
        'kind' => $kind, 'mime' => $mime, 'size' => $size, 'filename' => $name, 'sha256' => $sha, 'created' => date('c'),
    ]));

    $db = getDB();
    $db->prepare('INSERT INTO media (id, user_id, device_id, direction, kind, mime, size, filename, sha256) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$id, $userId, $deviceId, $direction, $kind, $mime, $size, $name, $sha]);

    return ['ok' => true, 'id' => $id, 'kind' => $kind, 'mime' => $mime, 'size' => $size, 'filename' => $name, 'sha256' => $sha];
    } catch (Throwable $e) {
        if ($id !== null) {
            foreach (['', 'part', 'thumb', 'meta'] as $variant) @unlink(mediaPath($id, $variant));
        }
        error_log('media upload failed: ' . $e->getMessage());
        return ['ok' => false, 'http' => 503, 'error' => 'The server could not save the attachment. Check media storage and database setup.'];
    }
}

// Small JPEG preview for the Inbox and the panel's chat list. GD only; never upscales.
function mediaMakeThumb(string $src, string $dest, int $maxSide = 240): bool {
    if (!function_exists('imagecreatefromstring')) return false;
    $info = @getimagesize($src);
    if (!$info || $info[0] * $info[1] > 25000000) return false;   // decompression-bomb guard
    $bin = @file_get_contents($src);
    if ($bin === false) return false;
    $im = @imagecreatefromstring($bin);
    unset($bin);
    if (!$im) return false;
    $w = imagesx($im); $h = imagesy($im);
    $scale = min(1, $maxSide / max($w, $h));
    $tw = max(1, (int)round($w * $scale)); $th = max(1, (int)round($h * $scale));
    $out = imagecreatetruecolor($tw, $th);
    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    $ok = @imagejpeg($out, $dest, 72);
    imagedestroy($im); imagedestroy($out);
    return (bool)$ok;
}

function mediaRow(string $id): ?array {
    if (!mediaValidId($id)) return null;
    $st = getDB()->prepare('SELECT * FROM media WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

// Per-user signing secret, made once.
function mediaSecret(int $userId): string {
    $db = getDB();
    $st = $db->prepare('SELECT media_secret FROM users WHERE id = ?');
    $st->execute([$userId]);
    $s = (string)$st->fetchColumn();
    if ($s === '') {
        $s = bin2hex(random_bytes(24));
        $db->prepare('UPDATE users SET media_secret = ? WHERE id = ?')->execute([$s, $userId]);
    }
    return $s;
}

// Signed, expiring URL for GET /media/<id>. $aud ties it to who may use it.
function mediaSignedUrl(array $media, string $aud, int $ttl, string $variant = ''): string {
    $exp = time() + $ttl;
    $sig = hash_hmac('sha256', $media['id'] . '|' . $aud . '|' . $exp . '|' . $variant, mediaSecret((int)$media['user_id']));
    $base = mediaBaseUrl();
    return $base . '/api.php/media/' . $media['id'] . '?e=' . $exp . '&a=' . rawurlencode($aud) . '&s=' . $sig . ($variant !== '' ? '&v=' . $variant : '');
}

// Public base of this server: the request's own host when there is one
// (works on the live box and under php -S), else the configured default.
function mediaBaseUrl(): string {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '') {
        $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/api.php'));
        // Some handlers include PATH_INFO in SCRIPT_NAME.
        $script = preg_replace('#\.php/.*$#', '.php', $script);
        $directory = rtrim(str_replace('\\', '/', dirname($script)), '/.');
        return ($https ? 'https' : 'http') . '://' . $host . $directory;
    }
    return rtrim(defined('SERVER_URL') ? SERVER_URL : 'https://whatsappreplies.ispledger.com', '/');
}

function mediaVerify(array $media, string $aud, int $exp, string $sig, string $variant = ''): bool {
    if ($exp < time()) return false;
    $want = hash_hmac('sha256', $media['id'] . '|' . $aud . '|' . $exp . '|' . $variant, mediaSecret((int)$media['user_id']));
    return hash_equals($want, $sig);
}

// null means no supported single-range request; false means unsatisfiable.
function mediaByteRange(string $header, int $size) {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $match) || ($match[1] === '' && $match[2] === '')) return null;
    if ($size <= 0) return false;
    if ($match[1] === '') {
        $length = (int)$match[2];
        return $length > 0 ? [max(0, $size - $length), $size - 1] : false;
    }
    $start = (int)$match[1];
    $end = $match[2] === '' ? $size - 1 : min((int)$match[2], $size - 1);
    return $start < $size && $end >= $start ? [$start, $end] : false;
}

// Stream the bytes (or the thumb). Ends the request. Byte ranges allow audio
// players to probe duration and seek without downloading the entire recording.
function mediaServe(array $media, string $variant = ''): void {
    $isThumb = $variant === 'thumb' && is_file(mediaPath($media['id'], 'thumb'));
    $path = $isThumb ? mediaPath($media['id'], 'thumb') : mediaPath($media['id']);
    if (!is_file($path)) { http_response_code(404); header('Content-Type: text/plain'); echo 'media expired'; exit; }
    $stream = @fopen($path, 'rb');
    if ($stream === false) { http_response_code(503); header('Content-Type: text/plain'); echo 'media unavailable'; exit; }
    $size = (int)filesize($path);
    $range = mediaByteRange((string)($_SERVER['HTTP_RANGE'] ?? ''), $size);
    // A changed/unknown If-Range validator requires the complete response.
    if (isset($_SERVER['HTTP_IF_RANGE'])) $range = null;
    $mime = $isThumb ? 'image/jpeg' : (string)$media['mime'];
    header_remove('Content-Type');
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    $disp = ($media['kind'] === 'document') ? 'attachment' : 'inline';
    $name = str_replace(['"', "\r", "\n"], '', (string)$media['filename']);
    header('Content-Disposition: ' . $disp . '; filename="' . $name . '"');
    if ($range === false) {
        fclose($stream);
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        header('Content-Length: 0');
        exit;
    }
    $start = 0;
    $end = $size - 1;
    if (is_array($range)) {
        [$start, $end] = $range;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    $remaining = max(0, $end - $start + 1);
    header('Content-Length: ' . $remaining);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        fseek($stream, $start);
        while ($remaining > 0 && !feof($stream)) {
            $chunk = fread($stream, min(65536, $remaining));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $remaining -= strlen($chunk);
        }
    }
    fclose($stream);
    exit;
}

function mediaDelete(string $id): void {
    foreach (['', 'thumb', 'meta', 'part'] as $v) @unlink(mediaPath($id, $v));
    try { getDB()->prepare('DELETE FROM media WHERE id = ?')->execute([$id]); } catch (Exception $e) {}
}

// Retention: incoming 30 days, outgoing 7 days (the panel keeps its own copy).
// Throttled to one pass per 10 minutes, at most 200 files, so it can ride on /pending.
function mediaCleanup(): void {
    $stamp = MEDIA_DIR . '/.last_cleanup';
    if (is_file($stamp) && time() - filemtime($stamp) < 600) return;
    @touch($stamp);
    $db = getDB();
    try {
        $lock = $db->query("SELECT GET_LOCK('wa_media_cleanup', 0)")->fetchColumn();
        if (!$lock) return;
        $st = $db->query("SELECT id FROM media WHERE (direction = 'in' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
                             OR (direction = 'out' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) LIMIT 200");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) mediaDelete($id);
        // Leftover .part files from interrupted uploads
        foreach (glob(MEDIA_DIR . '/*.part') ?: [] as $p) if (filemtime($p) < time() - 3600) @unlink($p);
    } catch (Exception $e) {
        error_log('media cleanup: ' . $e->getMessage());
    } finally {
        try { $db->query("SELECT RELEASE_LOCK('wa_media_cleanup')"); } catch (Exception $e) {}
    }
}

// Caption out of WhatsApp's notification text: "📷 Photo" -> '', "📷 our router" -> "our router".
function mediaCaptionFromText(string $text, string $kind): string {
    $t = trim(preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\s]+/u', '', $text));
    $placeholders = ['Photo', 'Video', 'GIF', 'Sticker', 'Audio', 'Document', 'Sent a photo', 'Sent a video', 'Image'];
    foreach ($placeholders as $p) if (strcasecmp($t, $p) === 0) return '';
    if (preg_match('/^Voice message/i', $t)) return '';
    return $t;
}

// The media fields the webhook carries for the support panel (bridge shape).
function mediaWebhookBlock(array $media): array {
    return [
        'id'       => 'app-m-' . $media['id'],
        'mime'     => $media['mime'],
        'size'     => (int)$media['size'],
        'filename' => $media['filename'],
        'url'      => mediaSignedUrl($media, 'support', 86400),
    ];
}
