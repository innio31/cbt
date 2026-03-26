<?php
// admin/get-result-details.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

require_once '../includes/config.php';

$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;

if ($session_id === 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid session ID']);
    exit();
}

try {
    // Get session details
    $stmt = $pdo->prepare("
        SELECT es.*, s.full_name, s.admission_number, s.class
        FROM exam_sessions es
        INNER JOIN students s ON es.student_id = s.id
        WHERE es.id = ?
    ");
    $stmt->execute([$session_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Session not found']);
        exit();
    }
    
    $answers = [];
    
    // Get answers from objective_answers JSON field
    if (!empty($session['objective_answers'])) {
        $objective_answers = json_decode($session['objective_answers'], true);
        
        if (is_array($objective_answers) && !empty($objective_answers)) {
            $question_ids = array_keys($objective_answers);
            $placeholders = str_repeat('?,', count($question_ids) - 1) . '?';
            $answers_stmt = $pdo->prepare("
                SELECT id, question_text, correct_answer 
                FROM objective_questions 
                WHERE id IN ($placeholders)
            ");
            $answers_stmt->execute($question_ids);
            
            $questions = [];
            while ($q = $answers_stmt->fetch(PDO::FETCH_ASSOC)) {
                $questions[$q['id']] = $q;
            }
            
            foreach ($objective_answers as $q_id => $selected) {
                if (isset($questions[$q_id])) {
                    $answers[] = [
                        'question_text' => $questions[$q_id]['question_text'],
                        'selected_option' => $selected,
                        'correct_answer' => $questions[$q_id]['correct_answer'],
                        'is_correct' => ($selected === $questions[$q_id]['correct_answer'])
                    ];
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'score' => $session['score'],
        'total_questions' => $session['total_questions'],
        'percentage' => number_format($session['percentage'], 2),
        'grade' => $session['grade'],
        'correct_answers' => $session['correct_answers'],
        'submitted_at' => date('d/m/Y H:i', strtotime($session['submitted_at'])),
        'answers' => $answers
    ]);
    
} catch (Exception $e) {
    error_log("Get result details error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>