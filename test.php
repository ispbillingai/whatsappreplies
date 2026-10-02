<?php
/**
 * Quick test script - run from browser or CLI to send a test message.
 *
 * Usage:
 *   Browser: http://yourserver.com/test.php
 *   CLI:     php test.php
 *
 * Or use cURL:
 *   curl -X POST http://yourserver.com/send \
 *     -H "Content-Type: application/json" \
 *     -H "X-API-Key: your-secret-api-key-change-this" \
 *     -d '{"phone": "27812345678", "message": "Hello from the relay!", "whatsapp_type": "whatsapp"}'
 */

$serverUrl = 'http://localhost/whatsapp_relay/api.php';  // Change to your server URL
$apiKey = 'your-secret-api-key-change-this';              // Change to your API key

// Test 1: Health check
echo "=== Health Check ===\n";
$ch = curl_init($serverUrl . '/health');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
echo $response . "\n\n";
curl_close($ch);

// Test 2: Send a message
echo "=== Send Message ===\n";
$data = json_encode([
    'phone' => '27812345678',          // Change to target phone number
    'message' => 'Hello! This is a test message from WA Relay.',
    'whatsapp_type' => 'whatsapp',     // or 'whatsapp_business'
    'priority' => 0
]);

$ch = curl_init($serverUrl . '/send');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey
    ]
]);
$response = curl_exec($ch);
echo $response . "\n\n";
curl_close($ch);

// Test 3: Check pending messages
echo "=== Pending Messages ===\n";
$ch = curl_init($serverUrl . '/pending?limit=5');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'X-API-Key: ' . $apiKey
    ]
]);
$response = curl_exec($ch);
echo $response . "\n\n";
curl_close($ch);

echo "Done!\n";
