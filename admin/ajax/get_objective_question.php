<?php
// ajax/get_objective_question.php
session_start();
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$question_id = $_GET['id'] ?? 0;

if ($question_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM objective_questions WHERE id = ?");
        $stmt->execute([$question_id]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($question) {
            echo json_encode(['success' => true, 'question' => $question]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Question not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid question ID']);
}
