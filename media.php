<?php
// Dashboard view of a stored file: session login + ownership (admins see all).
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/media_lib.php';
requireLogin();

$media = mediaRow((string)($_GET['id'] ?? ''));
if (!$media) { http_response_code(404); exit('not found'); }
if (!isAdmin() && (int)$media['user_id'] !== (int)$_SESSION['user_id']) { http_response_code(403); exit('forbidden'); }
mediaServe($media, ($_GET['v'] ?? '') === 'thumb' ? 'thumb' : '');
