<?php
// Temporary debug file - DELETE AFTER TESTING
session_start();

echo "<h2>Authentication Debug</h2>";
echo "<pre>";

echo "SESSION variables:\n";
print_r($_SESSION);

echo "\n\nCookie variables:\n";
print_r($_COOKIE);

echo "\n\nServer variables:\n";
echo "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "\n";
echo "SCRIPT_NAME: " . $_SERVER['SCRIPT_NAME'] . "\n";

echo "</pre>";

// Try to include auth files and see what happens
$files_to_check = [
    '../includes/auth.php',
    '../includes/functions.php',
    '../config.php',
    '../includes/config.php'
];

foreach ($files_to_check as $file) {
    echo "<h3>Checking $file:</h3>";
    if (file_exists($file)) {
        echo "✅ File exists<br>";
        // Try to include it and see if it sets anything
        include_once $file;
        echo "SESSION after include:\n";
        print_r($_SESSION);
    } else {
        echo "❌ File does not exist<br>";
    }
    echo "<br>";
}
