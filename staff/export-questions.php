<?php
// staff/export-questions.php - Export Questions
session_start();

// Check if staff is logged in
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Get staff information
$staff_id = $_SESSION['staff_id'];

// Get parameters
$question_type = $_GET['type'] ?? 'objective';
$current_subject = $_GET['subject'] ?? '';
$current_class = $_GET['class'] ?? '';
$current_topic = $_GET['topic'] ?? '';
$search_term = $_GET['search'] ?? '';

// Validate question type
$valid_types = ['objective', 'subjective', 'theory'];
if (!in_array($question_type, $valid_types)) {
    $question_type = 'objective';
}

try {
    // Build query based on type
    $where_conditions = ["q.subject_id IN (SELECT subject_id FROM staff_subjects WHERE staff_id = ?)"];
    $params = [$staff_id];

    if ($current_subject) {
        $where_conditions[] = "q.subject_id = ?";
        $params[] = $current_subject;
    }

    if ($current_class) {
        $where_conditions[] = "q.class = ?";
        $params[] = $current_class;
    }

    if ($current_topic) {
        $where_conditions[] = "q.topic_id = ?";
        $params[] = $current_topic;
    }

    if ($search_term) {
        if ($question_type === 'objective') {
            $where_conditions[] = "(q.question_text LIKE ? OR q.option_a LIKE ? OR q.option_b LIKE ? OR q.option_c LIKE ? OR q.option_d LIKE ?)";
            $search_param = "%$search_term%";
            for ($i = 0; $i < 5; $i++) {
                $params[] = $search_param;
            }
        } else {
            $where_conditions[] = "(q.question_text LIKE ? OR q.correct_answer LIKE ?)";
            $search_param = "%$search_term%";
            $params[] = $search_param;
            $params[] = $search_param;
        }
    }

    $where_clause = implode(" AND ", $where_conditions);

    // Fetch questions based on type
    if ($question_type === 'objective') {
        $query = "
            SELECT q.*, s.subject_name, t.topic_name 
            FROM objective_questions q
            LEFT JOIN subjects s ON q.subject_id = s.id
            LEFT JOIN topics t ON q.topic_id = t.id
            WHERE $where_clause
            ORDER BY q.created_at DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $questions = $stmt->fetchAll();
    } elseif ($question_type === 'subjective') {
        $query = "
            SELECT q.*, s.subject_name, t.topic_name 
            FROM subjective_questions q
            LEFT JOIN subjects s ON q.subject_id = s.id
            LEFT JOIN topics t ON q.topic_id = t.id
            WHERE $where_clause
            ORDER BY q.created_at DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $questions = $stmt->fetchAll();
    } else {
        $query = "
            SELECT q.*, s.subject_name, t.topic_name 
            FROM theory_questions q
            LEFT JOIN subjects s ON q.subject_id = s.id
            LEFT JOIN topics t ON q.topic_id = t.id
            WHERE $where_clause
            ORDER BY q.created_at DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $questions = $stmt->fetchAll();
    }

    // Create CSV content
    $filename = $question_type . '_questions_' . date('Y-m-d') . '.csv';

    // Set headers for download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // Create output stream
    $output = fopen('php://output', 'w');

    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Add headers based on question type
    if ($question_type === 'objective') {
        fputcsv($output, [
            'ID',
            'Question Text',
            'Option A',
            'Option B',
            'Option C',
            'Option D',
            'Correct Answer',
            'Subject',
            'Topic',
            'Class',
            'Difficulty',
            'Marks',
            'Created At'
        ]);

        foreach ($questions as $question) {
            fputcsv($output, [
                $question['id'],
                strip_tags($question['question_text']),
                strip_tags($question['option_a']),
                strip_tags($question['option_b']),
                strip_tags($question['option_c'] ?? ''),
                strip_tags($question['option_d'] ?? ''),
                $question['correct_answer'],
                $question['subject_name'] ?? 'N/A',
                $question['topic_name'] ?? 'N/A',
                $question['class'] ?? 'N/A',
                $question['difficulty_level'] ?? 'medium',
                $question['marks'] ?? 1,
                $question['created_at'] ?? ''
            ]);
        }
    } elseif ($question_type === 'subjective') {
        fputcsv($output, [
            'ID',
            'Question Text',
            'Correct Answer',
            'Subject',
            'Topic',
            'Class',
            'Difficulty',
            'Marks',
            'Created At'
        ]);

        foreach ($questions as $question) {
            fputcsv($output, [
                $question['id'],
                strip_tags($question['question_text']),
                strip_tags($question['correct_answer']),
                $question['subject_name'] ?? 'N/A',
                $question['topic_name'] ?? 'N/A',
                $question['class'] ?? 'N/A',
                $question['difficulty_level'] ?? 'medium',
                $question['marks'] ?? 1,
                $question['created_at'] ?? ''
            ]);
        }
    } else { // theory
        fputcsv($output, [
            'ID',
            'Question Text',
            'Subject',
            'Topic',
            'Class',
            'Marks',
            'Created At'
        ]);

        foreach ($questions as $question) {
            fputcsv($output, [
                $question['id'],
                strip_tags($question['question_text']),
                $question['subject_name'] ?? 'N/A',
                $question['topic_name'] ?? 'N/A',
                $question['class'] ?? 'N/A',
                $question['marks'] ?? 5,
                $question['created_at'] ?? ''
            ]);
        }
    }

    fclose($output);
    exit();
} catch (Exception $e) {
    error_log("Export error: " . $e->getMessage());
    header("Location: questions.php?error=export_failed");
    exit();
}
