<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isStudentLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_id = $_POST['exam_id'] ?? 0;
    $session_id = $_POST['session_id'] ?? 0;
    $answers_json = $_POST['answers'] ?? '{}';
    $answers = json_decode($answers_json, true);
    $student_id = $_SESSION['student_id'];

    // Log the submission for debugging
    error_log("Exam submission started - Exam ID: $exam_id, Session ID: $session_id, Student ID: $student_id");

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get exam details including total assigned questions
        $stmt = $pdo->prepare("
            SELECT e.*, s.subject_name 
            FROM exams e 
            JOIN subjects s ON e.subject_id = s.id 
            WHERE e.id = ?
        ");
        $stmt->execute([$exam_id]);
        $exam = $stmt->fetch();

        if (!$exam) {
            throw new Exception('Exam not found');
        }

        // Get total questions from exam
        $total_questions = (int)$exam['objective_count'];
        $correct_count = 0;
        $answered_questions = 0;

        error_log("Total questions: $total_questions");
        error_log("Answers received: " . print_r($answers, true));

        // Check each submitted answer
        if (is_array($answers) && !empty($answers)) {
            foreach ($answers as $question_id => $student_answer) {
                $answered_questions++;

                // Get the correct answer for this question
                $stmt = $pdo->prepare("SELECT correct_answer FROM objective_questions WHERE id = ?");
                $stmt->execute([$question_id]);
                $question = $stmt->fetch();

                if ($question) {
                    $correct_answer = $question['correct_answer'];
                    error_log("Question ID: $question_id - Student: $student_answer vs Correct: $correct_answer");

                    // Compare answers (case-insensitive)
                    if (strtoupper(trim($student_answer)) === strtoupper(trim($correct_answer))) {
                        $correct_count++;
                        error_log("✓ Correct answer for question $question_id");
                    } else {
                        error_log("✗ Wrong answer for question $question_id");
                    }
                } else {
                    error_log("Question not found: $question_id");
                }
            }
        } else {
            error_log("No answers submitted or answers array is empty");
        }

        // Calculate percentage
        $percentage = ($total_questions > 0) ? ($correct_count / $total_questions) * 100 : 0;

        // Determine grade based on percentage
        $grade = calculateGrade($percentage);

        error_log("Score calculation: $correct_count / $total_questions = $percentage% ($grade)");

        // UPDATE exam session - Check if session exists first
        $stmt = $pdo->prepare("SELECT id FROM exam_sessions WHERE id = ? AND student_id = ?");
        $stmt->execute([$session_id, $student_id]);
        $session_exists = $stmt->fetch();

        if ($session_exists) {
            // Update existing session with CORRECT DATA STRUCTURE
            $stmt = $pdo->prepare("
                UPDATE exam_sessions 
                SET status = 'completed', 
                    objective_answers = ?,
                    score = ?,  -- This should store the RAW SCORE (15), not percentage
                    percentage = ?,  
                    grade = ?,
                    submitted_at = NOW()
                WHERE id = ? AND student_id = ?
            ");

            // Store raw score (15) in score column, percentage in percentage column
            $update_result = $stmt->execute([
                $answers_json,
                $correct_count,      // RAW score (e.g., 15)
                $percentage,         // Percentage (e.g., 60)
                $grade,
                $session_id,
                $student_id
            ]);

            if (!$update_result) {
                $error_info = $stmt->errorInfo();
                throw new Exception("Failed to update exam session: " . $error_info[2]);
            }
            error_log("Exam session updated successfully - Raw score: $correct_count, Percentage: $percentage%");
        } else {
            error_log("Exam session not found, creating new one");
        }

        // INSERT INTO RESULTS TABLE - Store BOTH raw score and percentage
        // Check if result already exists for this exam and student
        $stmt = $pdo->prepare("SELECT id FROM results WHERE student_id = ? AND exam_id = ?");
        $stmt->execute([$student_id, $exam_id]);
        $existing_result = $stmt->fetch();

        // Calculate time taken if available
        $time_taken = 0;
        if ($session_exists) {
            // Get session start time to calculate time taken
            $stmt = $pdo->prepare("SELECT start_time FROM exam_sessions WHERE id = ?");
            $stmt->execute([$session_id]);
            $session_data = $stmt->fetch();
            if ($session_data && $session_data['start_time']) {
                $start_time = strtotime($session_data['start_time']);
                $end_time = time();
                $time_taken = $end_time - $start_time;
                error_log("Time taken calculation: Start: " . $session_data['start_time'] . ", Duration: $time_taken seconds");
            }
        }

        if ($existing_result) {
            // Update existing result - store raw scores correctly
            $stmt = $pdo->prepare("
                UPDATE results 
                SET objective_score = ?,  -- Store RAW objective score (e.g., 15)
                    theory_score = ?,
                    total_score = ?,      -- Store TOTAL raw score (e.g., 15)
                    percentage = ?,       -- Store percentage (e.g., 60)
                    grade = ?,
                    time_taken = ?,
                    submitted_at = NOW()
                WHERE id = ?
            ");

            $theory_score = 0; // Assuming no theory score for now
            $total_raw_score = $correct_count; // Total raw score = correct objective answers

            $update_result = $stmt->execute([
                $correct_count,   // objective_score as RAW count
                $theory_score,    // theory_score
                $total_raw_score, // total_score as RAW count  
                $percentage,      // percentage
                $grade,           // grade
                $time_taken,      // time taken in seconds
                $existing_result['id']
            ]);

            if (!$update_result) {
                $error_info = $stmt->errorInfo();
                throw new Exception("Failed to update result: " . $error_info[2]);
            }

            $result_id = $existing_result['id'];
            error_log("Updated existing result ID: $result_id - Raw: $correct_count, %: $percentage");
        } else {
            // Insert new result - store raw scores correctly
            $stmt = $pdo->prepare("
                INSERT INTO results 
                (student_id, exam_id, objective_score, theory_score, total_score, 
                 percentage, grade, time_taken, submitted_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $theory_score = 0; // Assuming no theory score for now
            $total_raw_score = $correct_count; // Total raw score = correct objective answers

            $insert_result = $stmt->execute([
                $student_id,
                $exam_id,
                $correct_count,   // objective_score as RAW count
                $theory_score,    // theory_score
                $total_raw_score, // total_score as RAW count
                $percentage,      // percentage
                $grade,           // grade
                $time_taken       // time taken in seconds
            ]);

            if (!$insert_result) {
                $error_info = $stmt->errorInfo();
                throw new Exception("Failed to insert into results table: " . $error_info[2]);
            }

            $result_id = $pdo->lastInsertId();
            error_log("Result inserted successfully with ID: $result_id - Raw: $correct_count, %: $percentage");
        }

        // Commit transaction
        $pdo->commit();

        // Check if there are theory questions
        $has_theory = ($exam['theory_count'] > 0);

        $response = [
            'success' => true,
            'score' => round($percentage, 2),     // Backward compatible
            'grade' => $grade,
            'correct' => $correct_count,          // Backward compatible
            'total' => $total_questions,          // Backward compatible
            'answered' => $answered_questions,
            'has_theory' => $has_theory,
            'exam_id' => $exam_id,
            'message' => 'Exam submitted successfully!',
            // New fields
            'raw_score' => $correct_count,
            'total_questions' => $total_questions,
            'percentage' => round($percentage, 2),
            'display_score' => "$correct_count/$total_questions",
            'display_percentage' => round($percentage, 2) . '%'
        ];

        error_log("Final response: " . print_r($response, true));
        echo json_encode($response);
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Exam submission error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());

        echo json_encode([
            'success' => false,
            'message' => 'Error submitting exam: ' . $e->getMessage(),
            'debug_info' => [
                'exam_id' => $exam_id,
                'session_id' => $session_id,
                'student_id' => $student_id
            ]
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method. Use POST.'
    ]);
}

function calculateGrade($percentage)
{
    if ($percentage >= 90) return 'A+';
    if ($percentage >= 80) return 'A';
    if ($percentage >= 70) return 'B';
    if ($percentage >= 60) return 'C';
    if ($percentage >= 50) return 'D';
    if ($percentage >= 40) return 'E';
    return 'F';
}
