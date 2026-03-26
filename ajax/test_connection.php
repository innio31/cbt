<?php
require_once '../includes/central_sync.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

// Override settings temporarily
$sync = new CentralSync($pdo);

// Test connection
$result = $sync->testConnection();

echo json_encode($result);
