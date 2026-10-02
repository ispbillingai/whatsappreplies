<?php
/**
 * Legacy-compatible WhatsApp Send Endpoint
 *
 * Format: /api/sendWA.php?to=PHONE&msg=TEXT&secret=APIKEY
 *
 * This is a thin wrapper around the main api.php to support
 * billing systems that use the old URL format with 'secret' param.
 */

// Map 'secret' param to 'apikey' for compatibility
if (isset($_GET['secret']) && !isset($_GET['apikey'])) {
    $_GET['apikey'] = $_GET['secret'];
}

// Forward to main api.php
require_once dirname(__DIR__) . '/api.php';
