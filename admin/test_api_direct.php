<?php
// Direct API test - with proper header debugging
$central_url = 'https://impactdigitalacademy.com.ng/school-central/api'; // CHANGE THIS
$api_key = '5387bba25f923a4d70faae4dddd58fb9'; // CHANGE THIS

echo "<h2>🔧 Direct API Test with Debug</h2>";

// Test 1: Just check if server is reachable
echo "<h3>📡 Test 1: Server Reachable?</h3>";
$ch = curl_init($central_url . '/?action=auth');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $http_code . "<br>";
if ($http_code > 0) {
    echo "✅ Server is reachable<br>";
} else {
    echo "❌ Cannot reach server - check URL and network<br>";
}

// Test 2: Test with API key in different ways
echo "<h3>🔑 Test 2: Authentication Test (Multiple Methods)</h3>";

// Method A: Header with X-API-Key
echo "<h4>Method A: X-API-Key Header</h4>";
$ch = curl_init($central_url . '/?action=auth');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-Key: ' . $api_key,
    'User-Agent: School-CBT-Test/1.0'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_VERBOSE, true); // Enable verbose output

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo "HTTP Code: " . $http_code . "<br>";
echo "Response: <pre>" . htmlspecialchars($response) . "</pre><br>";

// Method B: API key as GET parameter
echo "<h4>Method B: API Key in URL</h4>";
$ch = curl_init($central_url . '/?action=auth&api_key=' . $api_key);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $http_code . "<br>";
echo "Response: <pre>" . htmlspecialchars($response) . "</pre><br>";

// Method C: Both header and parameter
echo "<h4>Method C: Both Header and URL Parameter</h4>";
$ch = curl_init($central_url . '/?action=auth&api_key=' . $api_key);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-Key: ' . $api_key
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $http_code . "<br>";
echo "Response: <pre>" . htmlspecialchars($response) . "</pre><br>";

// Test 3: Test with the exact URL you used in browser
echo "<h3>🌐 Test 3: Browser-style URL</h3>";
$browser_url = $central_url . '/?action=auth&api_key=' . $api_key;
echo "URL: " . $browser_url . "<br>";

$ch = curl_init($browser_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $http_code . "<br>";
echo "Response: <pre>" . htmlspecialchars($response) . "</pre><br>";

echo "<hr>";
echo "<h3>🔍 Debug Info</h3>";
echo "API Key length: " . strlen($api_key) . " characters<br>";
echo "API Key first 4 chars: " . substr($api_key, 0, 4) . "...<br>";
echo "Central URL: " . $central_url . "<br>";
