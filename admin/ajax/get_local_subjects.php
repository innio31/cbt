<?php
session_start();
require_once '../../includes/config.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'subjects' => [],
    'tables' => [],
    'error' => null
];

try {
    // Check what tables exist
    $stmt = $pdo->query("SHOW TABLES");
    $response['tables'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Check if subjects table exists
    if (in_array('subjects', $response['tables'])) {
        $stmt = $pdo->query("SELECT id, subject_name, subject_code FROM subjects ORDER BY subject_name");
        $response['subjects'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response['success'] = true;
    } else {
        $response['error'] = 'subjects table does not exist';
    }

    // Also check what your objective_questions table looks like
    if (in_array('objective_questions', $response['tables'])) {
        $stmt = $pdo->query("DESCRIBE objective_questions");
        $response['objective_columns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
