<?php
// Redirect to dashboard (or login if not authenticated)
require_once __DIR__ . '/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
