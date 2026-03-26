<?php
session_start();
require_once '../includes/config.php';

echo "<h2>Session Debug Info</h2>";
echo "<pre>";
echo "admin_id: " . ($_SESSION['admin_id'] ?? 'NOT SET') . "\n";
echo "admin_username: " . ($_SESSION['admin_username'] ?? 'NOT SET') . "\n";
echo "admin_role: " . ($_SESSION['admin_role'] ?? 'NOT SET') . "\n";
echo "admin_name: " . ($_SESSION['admin_name'] ?? 'NOT SET') . "\n";
echo "</pre>";

// Check database for admin users
echo "<h2>Database Admin Users</h2>";
$stmt = $pdo->query("SELECT id, username, full_name, role FROM admin_users");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($admins);
echo "</pre>";
