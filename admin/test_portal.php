<?php
// admin/test_portal.php - Simple script to test portal connection
session_start();

// Check admin login
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    die("Unauthorized");
}

require_once '../includes/config.php';

// Portal configuration
if (!defined('PORTAL_API_URL')) {
    define('PORTAL_API_URL', 'https://impactdigitalacademy.com.ng/result-checker/api/sync.php');
}
if (!defined('PORTAL_API_KEY')) {
    define('PORTAL_API_KEY', '8d1910fa39812e0077acfc629741b96b1580836edaf9dacc19fa95b64155c5bf');
}
if (!defined('SCHOOL_CODE')) {
    define('SCHOOL_CODE', 'TCBA001');
}

header('Content-Type: text/html');

echo "<h1>Portal Connection Test</h1>";
echo "<hr>";

echo "<h2>Configuration:</h2>";
echo "<pre>";
echo "API URL: " . PORTAL_API_URL . "\n";
echo "API Key: " . (empty(PORTAL_API_KEY) ? 'NOT SET' : substr(PORTAL_API_KEY, 0, 10) . '...') . "\n";
echo "School Code: " . (empty(SCHOOL_CODE) ? 'NOT SET' : SCHOOL_CODE) . "\n";
echo "</pre>";

if (empty(PORTAL_API_KEY) || empty(SCHOOL_CODE)) {
    echo "<p style='color: red;'>Error: API Key or School Code not configured!</p>";
    exit();
}

echo "<h2>Testing Connection...</h2>";

$payload = [
    'api_key' => PORTAL_API_KEY,
    'school_code' => SCHOOL_CODE,
    'action' => 'test_connection'
];

echo "<pre>";
echo "Sending payload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

$ch = curl_init(PORTAL_API_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_VERBOSE, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
$info = curl_getinfo($ch);

curl_close($ch);

echo "HTTP Status Code: " . $httpCode . "\n";
echo "Response: \n" . $response . "\n";

if ($curl_error) {
    echo "CURL Error: " . $curl_error . "\n";
}

echo "\nConnection Info:\n";
print_r($info);
echo "</pre>";

if ($httpCode == 200) {
    $data = json_decode($response, true);
    if ($data && $data['success']) {
        echo "<p style='color: green;'>✓ Connection successful! School: " . htmlspecialchars($data['data']['school'] ?? 'Unknown') . "</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Response received but not successful: " . htmlspecialchars($data['error'] ?? 'Unknown error') . "</p>";
    }
} else {
    echo "<p style='color: red;'>✗ Connection failed with HTTP $httpCode</p>";

    // Try to see if there's HTML in response
    if (strpos($response, '<') !== false) {
        echo "<p>Response appears to be HTML (likely a PHP error page). Check the portal server error logs.</p>";
        echo "<hr>";
        echo "<h3>HTML Response Preview:</h3>";
        echo "<pre>" . htmlspecialchars(substr($response, 0, 1000)) . "</pre>";
    }
}
