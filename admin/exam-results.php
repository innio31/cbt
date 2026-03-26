<?php
// admin/exam-results.php - View Exam Results
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Get admin information
$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';

// Get exam ID from URL
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
if ($exam_id === 0) {
    header("Location: manage-exams.php");
    exit();
}

// Get filters
$class_filter = isset($_GET['class']) ? trim($_GET['class']) : '';
$student_filter = isset($_GET['student']) ? trim($_GET['student']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

$message = '';
$message_type = '';

try {
    // Get exam details
    $stmt = $pdo->prepare("
        SELECT e.*, s.subject_name, sg.group_name
        FROM exams e
        LEFT JOIN subjects s ON e.subject_id = s.id
        LEFT JOIN subject_groups sg ON e.group_id = sg.id
        WHERE e.id = ?
    ");
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$exam) {
        header("Location: manage-exams.php");
        exit();
    }

    // Build query for results
    $query = "
        SELECT 
            es.id as session_id,
            es.student_id,
            es.start_time,
            es.end_time,
            es.submitted_at,
            es.score,
            es.percentage,
            es.grade,
            es.correct_answers,
            es.total_questions,
            es.status as session_status,
            s.admission_number,
            s.full_name as student_name,
            s.class as student_class
        FROM exam_sessions es
        INNER JOIN students s ON es.student_id = s.id
        WHERE es.exam_id = ?
    ";

    $params = [$exam_id];

    if (!empty($class_filter)) {
        $query .= " AND s.class = ?";
        $params[] = $class_filter;
    }

    if (!empty($student_filter)) {
        $query .= " AND (s.full_name LIKE ? OR s.admission_number LIKE ?)";
        $params[] = "%$student_filter%";
        $params[] = "%$student_filter%";
    }

    if (!empty($status_filter)) {
        $query .= " AND es.status = ?";
        $params[] = $status_filter;
    }

    $query .= " ORDER BY es.submitted_at DESC, s.full_name ASC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // Get distinct classes for filter dropdown
    $class_stmt = $pdo->prepare("
        SELECT DISTINCT s.class 
        FROM exam_sessions es
        INNER JOIN students s ON es.student_id = s.id
        WHERE es.exam_id = ?
        ORDER BY s.class
    ");
    $class_stmt->execute([$exam_id]);
    $classes = $class_stmt->fetchAll();

    // Get summary statistics for completed exams only
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT es.student_id) as total_students,
            COUNT(es.id) as total_attempts,
            AVG(es.percentage) as avg_percentage,
            MAX(es.percentage) as highest_score,
            MIN(es.percentage) as lowest_score,
            COUNT(CASE WHEN es.percentage >= 50 THEN 1 END) as passed_count,
            COUNT(CASE WHEN es.percentage < 50 THEN 1 END) as failed_count,
            COUNT(CASE WHEN es.status = 'completed' THEN 1 END) as completed_count,
            COUNT(CASE WHEN es.status = 'in_progress' THEN 1 END) as in_progress_count
        FROM exam_sessions es
        WHERE es.exam_id = ?
    ");
    $stats_stmt->execute([$exam_id]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Get grade distribution for completed exams only
    $grade_stmt = $pdo->prepare("
        SELECT 
            es.grade,
            COUNT(*) as count
        FROM exam_sessions es
        WHERE es.exam_id = ? AND es.status = 'completed' AND es.grade IS NOT NULL
        GROUP BY es.grade
        ORDER BY 
            FIELD(es.grade, 'A', 'B', 'C', 'D', 'E', 'F')
    ");
    $grade_stmt->execute([$exam_id]);
    $grade_distribution = $grade_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Exam results error: " . $e->getMessage());
    $message = "Error loading results: " . $e->getMessage();
    $message_type = "error";
    $results = [];
    $classes = [];
    $stats = null;
    $grade_distribution = [];
}

// Handle result export
if (isset($_GET['export'])) {
    try {
        $export_format = $_GET['export'];
        $class_filter_export = isset($_GET['class']) ? trim($_GET['class']) : '';
        $status_filter_export = isset($_GET['status']) ? trim($_GET['status']) : '';

        // Fetch results for export
        $export_query = "
            SELECT 
                s.admission_number,
                s.full_name as student_name,
                s.class,
                es.start_time,
                es.end_time,
                es.submitted_at,
                es.score,
                es.percentage,
                es.grade,
                es.correct_answers,
                es.total_questions,
                es.status
            FROM exam_sessions es
            INNER JOIN students s ON es.student_id = s.id
            WHERE es.exam_id = ?
        ";

        $export_params = [$exam_id];

        if (!empty($class_filter_export)) {
            $export_query .= " AND s.class = ?";
            $export_params[] = $class_filter_export;
        }

        if (!empty($status_filter_export)) {
            $export_query .= " AND es.status = ?";
            $export_params[] = $status_filter_export;
        }

        $export_query .= " ORDER BY s.full_name ASC";

        $export_stmt = $pdo->prepare($export_query);
        $export_stmt->execute($export_params);
        $export_results = $export_stmt->fetchAll();

        if ($export_format === 'csv') {
            // Export as CSV
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="exam_results_' . $exam_id . '_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');

            // Add headers
            fputcsv($output, ['Admission No', 'Student Name', 'Class', 'Status', 'Start Time', 'End Time', 'Submitted At', 'Score', 'Percentage', 'Grade', 'Correct Answers', 'Total Questions']);

            // Add data
            foreach ($export_results as $row) {
                fputcsv($output, [
                    $row['admission_number'],
                    $row['student_name'],
                    $row['class'],
                    $row['status'],
                    $row['start_time'],
                    $row['end_time'],
                    $row['submitted_at'],
                    $row['score'] ?? '--',
                    isset($row['percentage']) ? $row['percentage'] . '%' : '--',
                    $row['grade'] ?? '--',
                    $row['correct_answers'] ?? '--',
                    $row['total_questions'] ?? '--'
                ]);
            }

            fclose($output);
            exit();
        } elseif ($export_format === 'pdf') {
            // For PDF export, we'll output HTML that can be printed to PDF
            header('Content-Type: text/html');
            header('Content-Disposition: inline; filename="exam_results_' . $exam_id . '_' . date('Y-m-d') . '.html"');
?>
            <!DOCTYPE html>
            <html>

            <head>
                <title>Exam Results - <?php echo htmlspecialchars($exam['exam_name']); ?></title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        margin: 20px;
                    }

                    h1 {
                        color: #2c3e50;
                    }

                    .exam-info {
                        margin-bottom: 20px;
                        padding: 10px;
                        background: #f5f5f5;
                    }

                    table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-top: 20px;
                    }

                    th,
                    td {
                        border: 1px solid #ddd;
                        padding: 8px;
                        text-align: left;
                    }

                    th {
                        background-color: #2c3e50;
                        color: white;
                    }

                    tr:nth-child(even) {
                        background-color: #f9f9f9;
                    }
                </style>
            </head>

            <body>
                <h1><?php echo htmlspecialchars($exam['exam_name']); ?></h1>
                <div class="exam-info">
                    <p><strong>Class:</strong> <?php echo htmlspecialchars($exam['class']); ?></p>
                    <p><strong>Subject:</strong> <?php echo htmlspecialchars($exam['subject_name'] ?? 'N/A'); ?></p>
                    <p><strong>Date:</strong> <?php echo date('F d, Y'); ?></p>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Admission No</th>
                            <th>Student Name</th>
                            <th>Class</th>
                            <th>Status</th>
                            <th>Score</th>
                            <th>Percentage</th>
                            <th>Grade</th>
                            <th>Correct Answers</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($export_results as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['admission_number']); ?></td>
                                <td><?php echo htmlspecialchars($row['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['class']); ?></td>
                                <td><?php echo htmlspecialchars($row['status']); ?></td>
                                <td><?php echo htmlspecialchars($row['score'] ?? '--'); ?></td>
                                <td><?php echo isset($row['percentage']) ? htmlspecialchars($row['percentage']) . '%' : '--'; ?></td>
                                <td><?php echo htmlspecialchars($row['grade'] ?? '--'); ?></td>
                                <td><?php echo isset($row['correct_answers']) ? htmlspecialchars($row['correct_answers']) . '/' . htmlspecialchars($row['total_questions']) : '--'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </body>

            </html>
<?php
            exit();
        }
    } catch (Exception $e) {
        error_log("Export error: " . $e->getMessage());
        $message = "Error exporting results: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle delete result - UPDATED VERSION
if (isset($_GET['delete_result'])) {
    try {
        $session_id = intval($_GET['delete_result']);

        // Get session details for logging
        $stmt = $pdo->prepare("
            SELECT es.*, s.full_name, e.exam_name 
            FROM exam_sessions es
            INNER JOIN students s ON es.student_id = s.id
            INNER JOIN exams e ON es.exam_id = e.id
            WHERE es.id = ?
        ");
        $stmt->execute([$session_id]);
        $session = $stmt->fetch();

        if ($session) {
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Delete from exam_session_questions first (if the table exists and has foreign key)
                $check_table = $pdo->query("SHOW TABLES LIKE 'exam_session_questions'");
                if ($check_table->rowCount() > 0) {
                    $stmt = $pdo->prepare("DELETE FROM exam_session_questions WHERE session_id = ?");
                    $stmt->execute([$session_id]);
                }
                
                // Delete the exam session
                $stmt = $pdo->prepare("DELETE FROM exam_sessions WHERE id = ?");
                $stmt->execute([$session_id]);
                
                // Commit transaction
                $pdo->commit();
                
                $message = "Result for " . htmlspecialchars($session['full_name']) . " deleted successfully!";
                $message_type = "success";
                
                // Log activity
                if (function_exists('logActivity')) {
                    logActivity($pdo, $_SESSION['admin_id'], 'admin', "Deleted result for " . $session['full_name'] . " in exam: " . $session['exam_name']);
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } else {
            $message = "Result not found!";
            $message_type = "error";
        }

        // Redirect to remove delete parameter from URL
        $redirect_url = "exam-results.php?exam_id=$exam_id";
        if (!empty($class_filter)) {
            $redirect_url .= "&class=" . urlencode($class_filter);
        }
        if (!empty($student_filter)) {
            $redirect_url .= "&student=" . urlencode($student_filter);
        }
        if (!empty($status_filter)) {
            $redirect_url .= "&status=" . urlencode($status_filter);
        }
        
        header("Location: $redirect_url");
        exit();
        
    } catch (Exception $e) {
        error_log("Delete result error: " . $e->getMessage());
        $message = "Error deleting result: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle download individual result - UPDATED VERSION
if (isset($_GET['download_result'])) {
    try {
        $session_id = intval($_GET['download_result']);

        $stmt = $pdo->prepare("
            SELECT es.*, s.full_name, s.admission_number, s.class, e.exam_name, e.subject_id, sub.subject_name
            FROM exam_sessions es
            INNER JOIN students s ON es.student_id = s.id
            INNER JOIN exams e ON es.exam_id = e.id
            LEFT JOIN subjects sub ON e.subject_id = sub.id
            WHERE es.id = ?
        ");
        $stmt->execute([$session_id]);
        $result = $stmt->fetch();

        if ($result) {
            // Get answers from the objective_answers JSON field in exam_sessions
            $answers = [];
            if (!empty($result['objective_answers'])) {
                $objective_answers = json_decode($result['objective_answers'], true);
                
                // If we have stored answers as JSON, we need to get question details
                if (is_array($objective_answers) && !empty($objective_answers)) {
                    $question_ids = array_keys($objective_answers);
                    if (!empty($question_ids)) {
                        $placeholders = str_repeat('?,', count($question_ids) - 1) . '?';
                        $answers_stmt = $pdo->prepare("
                            SELECT id, question_text, option_a, option_b, option_c, option_d, correct_answer 
                            FROM objective_questions 
                            WHERE id IN ($placeholders)
                        ");
                        $answers_stmt->execute($question_ids);
                        $question_details = [];
                        while ($q = $answers_stmt->fetch(PDO::FETCH_ASSOC)) {
                            $question_details[$q['id']] = $q;
                        }
                        
                        foreach ($objective_answers as $q_id => $selected_option) {
                            if (isset($question_details[$q_id])) {
                                $q = $question_details[$q_id];
                                $is_correct = ($selected_option === $q['correct_answer']);
                                $answers[] = [
                                    'question_text' => $q['question_text'],
                                    'selected_option' => $selected_option,
                                    'correct_answer' => $q['correct_answer'],
                                    'is_correct' => $is_correct
                                ];
                            }
                        }
                    }
                }
            }
            
            // If no answers found in JSON, try the exam_session_questions table
            if (empty($answers)) {
                $check_table = $pdo->query("SHOW TABLES LIKE 'exam_session_questions'");
                if ($check_table->rowCount() > 0) {
                    $answers_stmt = $pdo->prepare("
                        SELECT oq.question_text, oq.option_a, oq.option_b, oq.option_c, oq.option_d, oq.correct_answer, 
                               esq.answer as selected_option, esq.is_correct
                        FROM exam_session_questions esq
                        INNER JOIN objective_questions oq ON esq.question_id = oq.id
                        WHERE esq.session_id = ?
                        ORDER BY esq.id
                    ");
                    $answers_stmt->execute([$session_id]);
                    $answers = $answers_stmt->fetchAll();
                }
            }

            // Output HTML for individual result
            header('Content-Type: text/html');
            header('Content-Disposition: inline; filename="result_' . $result['admission_number'] . '_' . $result['exam_name'] . '.html"');
?>
            <!DOCTYPE html>
            <html>
            <head>
                <title>Exam Result - <?php echo htmlspecialchars($result['full_name']); ?></title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        margin: 30px;
                    }
                    .header {
                        text-align: center;
                        margin-bottom: 30px;
                        border-bottom: 2px solid #2c3e50;
                        padding-bottom: 20px;
                    }
                    .student-info {
                        margin-bottom: 20px;
                        padding: 15px;
                        background: #f5f5f5;
                        border-radius: 5px;
                    }
                    .result-summary {
                        margin-bottom: 20px;
                        padding: 15px;
                        background: #e8f4f9;
                        border-radius: 5px;
                    }
                    .grade {
                        font-size: 24px;
                        font-weight: bold;
                        color: #27ae60;
                    }
                    .answers-table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-top: 20px;
                    }
                    .answers-table th,
                    .answers-table td {
                        border: 1px solid #ddd;
                        padding: 10px;
                        text-align: left;
                        vertical-align: top;
                    }
                    .answers-table th {
                        background: #2c3e50;
                        color: white;
                    }
                    .correct {
                        color: green;
                        font-weight: bold;
                    }
                    .incorrect {
                        color: red;
                        font-weight: bold;
                    }
                    .footer {
                        margin-top: 30px;
                        text-align: center;
                        font-size: 12px;
                        color: #666;
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1><?php echo htmlspecialchars($result['exam_name']); ?></h1>
                    <p>Examination Result Slip</p>
                </div>

                <div class="student-info">
                    <h3>Student Information</h3>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($result['full_name']); ?></p>
                    <p><strong>Admission Number:</strong> <?php echo htmlspecialchars($result['admission_number']); ?></p>
                    <p><strong>Class:</strong> <?php echo htmlspecialchars($result['class']); ?></p>
                    <p><strong>Subject:</strong> <?php echo htmlspecialchars($result['subject_name'] ?? 'N/A'); ?></p>
                    <p><strong>Date Completed:</strong> <?php echo date('F d, Y H:i', strtotime($result['submitted_at'])); ?></p>
                </div>

                <div class="result-summary">
                    <h3>Result Summary</h3>
                    <p><strong>Score:</strong> <?php echo $result['score']; ?> / <?php echo $result['total_questions']; ?></p>
                    <p><strong>Percentage:</strong> <?php echo number_format($result['percentage'], 2); ?>%</p>
                    <p><strong>Grade:</strong> <span class="grade"><?php echo $result['grade']; ?></span></p>
                    <p><strong>Correct Answers:</strong> <?php echo $result['correct_answers']; ?> out of <?php echo $result['total_questions']; ?></p>
                </div>

                <h3>Question Breakdown</h3>
                <table class="answers-table">
                    <thead>
                        <tr>
                            <th style="width: 5%">#</th>
                            <th style="width: 50%">Question</th>
                            <th style="width: 15%">Your Answer</th>
                            <th style="width: 15%">Correct Answer</th>
                            <th style="width: 15%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1;
                        foreach ($answers as $answer): ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><?php echo nl2br(htmlspecialchars($answer['question_text'])); ?></td>
                                <td><?php echo htmlspecialchars($answer['selected_option'] ?? 'Not answered'); ?></td>
                                <td><?php echo htmlspecialchars($answer['correct_answer']); ?></td>
                                <td class="<?php echo $answer['is_correct'] ? 'correct' : 'incorrect'; ?>">
                                    <?php echo $answer['is_correct'] ? 'Correct' : 'Incorrect'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="footer">
                    <p>This is a computer-generated result. No signature required.</p>
                </div>
            </body>
            </html>
<?php
            exit();
        }
    } catch (Exception $e) {
        error_log("Download result error: " . $e->getMessage());
        $message = "Error downloading result: " . $e->getMessage();
        $message_type = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Results - <?php echo htmlspecialchars($exam['exam_name']); ?> - Digital CBT System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
            transition: all 0.3s ease;
            z-index: 100;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0 0 20px 0;
        }

        .sidebar-header {
            padding: 0 20px;
            margin-bottom: 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--secondary-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .logo-text h3 {
            font-size: 1.2rem;
            margin-bottom: 2px;
        }

        .logo-text p {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
        }

        .admin-info h4 {
            margin-bottom: 5px;
            font-size: 1rem;
        }

        .admin-info p {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .nav-links {
            list-style: none;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--secondary-color);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: var(--secondary-color);
        }

        .nav-links i {
            width: 20px;
            text-align: center;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .top-header {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .header-title p {
            color: #666;
            font-size: 0.9rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logout-btn {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
        }

        .btn {
            padding: 10px 18px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), #2980b9);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #219653);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #d68910);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
            color: white;
        }

        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--primary-color);
            font-size: 0.9rem;
        }

        .form-control {
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            transition: border-color 0.3s ease;
            width: 100%;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card h3 {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--secondary-color);
        }

        .stat-card p {
            color: #666;
            font-size: 0.9rem;
        }

        .stat-card.passed h3 {
            color: var(--success-color);
        }

        .stat-card.failed h3 {
            color: var(--danger-color);
        }

        .stat-card.average h3 {
            color: var(--warning-color);
        }

        .stat-card.in-progress h3 {
            color: var(--warning-color);
        }

        .stat-card.completed h3 {
            color: var(--success-color);
        }

        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        .data-table th {
            background: var(--primary-color);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-completed {
            background: #d5f4e6;
            color: #27ae60;
        }

        .status-in-progress {
            background: #fff3cd;
            color: #f39c12;
        }

        .grade-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }

        .grade-A {
            background: #d5f4e6;
            color: #27ae60;
        }

        .grade-B {
            background: #e8f4f9;
            color: #2980b9;
        }

        .grade-C {
            background: #fff3cd;
            color: #f39c12;
        }

        .grade-D {
            background: #ffe5e5;
            color: #e67e22;
        }

        .grade-E {
            background: #f8d7da;
            color: #e74c3c;
        }

        .grade-F {
            background: #f8d7da;
            color: #c0392b;
        }

        .action-icons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            color: white;
            text-decoration: none;
            font-size: 0.8rem;
            border: none;
        }

        .action-icon.view {
            background: var(--secondary-color);
        }

        .action-icon.view:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .action-icon.delete {
            background: var(--danger-color);
        }

        .action-icon.delete:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

        .action-icon.download {
            background: var(--success-color);
        }

        .action-icon.download:hover {
            background: #219653;
            transform: translateY(-2px);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 500;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: #d5f4e6;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 15px;
        }

        .grade-distribution {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .grade-distribution h3 {
            margin-bottom: 15px;
            color: var(--primary-color);
        }

        .grade-bars {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .grade-bar {
            flex: 1;
            min-width: 60px;
            text-align: center;
        }

        .bar {
            height: 100px;
            background: var(--secondary-color);
            margin-bottom: 5px;
            border-radius: 5px;
            transition: height 0.3s ease;
            position: relative;
        }

        .bar-label {
            font-size: 0.8rem;
            font-weight: 500;
        }

        .bar-count {
            font-size: 0.7rem;
            color: #666;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: block;
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 101;
                background: var(--primary-color);
                color: white;
                border: none;
                width: 45px;
                height: 45px;
                border-radius: 10px;
                font-size: 20px;
                cursor: pointer;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            }

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-form {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="logo-text">
                    <h3><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?></h3>
                    <p>Admin Panel</p>
                </div>
            </div>
        </div>

        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
        </div>

        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="manage_questions.php"><i class="fas fa-question-circle"></i> Manage Questions</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <a href="manage-exams.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Exams
                </a>
                <h1><?php echo htmlspecialchars($exam['exam_name']); ?></h1>
                <p>
                    <i class="fas fa-school"></i> Class: <?php echo htmlspecialchars($exam['class']); ?> |
                    <i class="fas fa-book"></i> Subject: <?php echo htmlspecialchars($exam['subject_name'] ?? 'N/A'); ?> |
                    <i class="fas fa-clock"></i> Duration: <?php echo $exam['duration_minutes']; ?> mins
                </p>
            </div>
            <div class="header-actions">
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <?php if ($stats && ($stats['total_attempts'] > 0 || $stats['completed_count'] > 0 || $stats['in_progress_count'] > 0)): ?>
            <div class="stats-container">
                <div class="stat-card">
                    <h3><?php echo $stats['total_students'] ?? 0; ?></h3>
                    <p>Total Students</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo $stats['total_attempts'] ?? 0; ?></h3>
                    <p>Total Attempts</p>
                </div>
                <div class="stat-card completed">
                    <h3><?php echo $stats['completed_count'] ?? 0; ?></h3>
                    <p>Completed</p>
                </div>
                <div class="stat-card in-progress">
                    <h3><?php echo $stats['in_progress_count'] ?? 0; ?></h3>
                    <p>In Progress</p>
                </div>
                <div class="stat-card average">
                    <h3><?php echo isset($stats['avg_percentage']) ? number_format($stats['avg_percentage'], 1) : '0'; ?>%</h3>
                    <p>Average Score</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo isset($stats['highest_score']) ? number_format($stats['highest_score'], 1) : '0'; ?>%</h3>
                    <p>Highest Score</p>
                </div>
                <div class="stat-card passed">
                    <h3><?php echo $stats['passed_count'] ?? 0; ?></h3>
                    <p>Passed (≥50%)</p>
                </div>
                <div class="stat-card failed">
                    <h3><?php echo $stats['failed_count'] ?? 0; ?></h3>
                    <p>Failed (&lt;50%)</p>
                </div>
            </div>

            <!-- Grade Distribution -->
            <?php if (!empty($grade_distribution)): ?>
                <div class="grade-distribution">
                    <h3><i class="fas fa-chart-bar"></i> Grade Distribution (Completed Exams Only)</h3>
                    <div class="grade-bars">
                        <?php
                        $max_count = max(array_column($grade_distribution, 'count'));
                        foreach ($grade_distribution as $grade):
                            $height = $max_count > 0 ? ($grade['count'] / $max_count) * 100 : 0;
                        ?>
                            <div class="grade-bar">
                                <div class="bar" style="height: <?php echo $height; ?>px; background: 
                        <?php
                            switch ($grade['grade']) {
                                case 'A':
                                    echo '#27ae60';
                                    break;
                                case 'B':
                                    echo '#3498db';
                                    break;
                                case 'C':
                                    echo '#f39c12';
                                    break;
                                case 'D':
                                    echo '#e67e22';
                                    break;
                                default:
                                    echo '#e74c3c';
                            }
                        ?>">
                                </div>
                                <div class="bar-label"><?php echo $grade['grade']; ?></div>
                                <div class="bar-count"><?php echo $grade['count']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="filter-form">
                <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">

                <div class="form-group">
                    <label for="class"><i class="fas fa-school"></i> Class</label>
                    <select id="class" name="class" class="form-control">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class']); ?>"
                                <?php echo $class_filter === $class['class'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status"><i class="fas fa-chart-line"></i> Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="student"><i class="fas fa-user-graduate"></i> Student</label>
                    <input type="text" id="student" name="student" class="form-control"
                        placeholder="Search by name or admission number..."
                        value="<?php echo htmlspecialchars($student_filter); ?>">
                </div>

                <div class="form-group" style="display: flex; flex-direction: row; gap: 10px; align-items: center;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="exam-results.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-secondary" style="flex: 1;">
                        <i class="fas fa-redo"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Export Buttons -->
        <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
            <a href="?exam_id=<?php echo $exam_id; ?>&export=csv<?php echo $class_filter ? '&class=' . urlencode($class_filter) : ''; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?>" class="btn btn-success">
                <i class="fas fa-file-csv"></i> Export as CSV
            </a>
            <a href="?exam_id=<?php echo $exam_id; ?>&export=pdf<?php echo $class_filter ? '&class=' . urlencode($class_filter) : ''; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?>" class="btn btn-danger" target="_blank">
                <i class="fas fa-file-pdf"></i> Export as PDF
            </a>
        </div>

        <!-- Results Table -->
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>S/N</th>
                        <th>Admission No</th>
                        <th>Student Name</th>
                        <th>Class</th>
                        <th>Status</th>
                        <th>Score</th>
                        <th>Percentage</th>
                        <th>Grade</th>
                        <th>Completed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($results)): ?>
                        <?php $sn = 1;
                        foreach ($results as $result): ?>
                            <tr>
                                <td><?php echo $sn++; ?></td>
                                <td><?php echo htmlspecialchars($result['admission_number']); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($result['student_name']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($result['student_class']); ?></td>
                                <td>
                                    <?php if ($result['session_status'] === 'completed'): ?>
                                        <span class="status-badge status-completed">
                                            <i class="fas fa-check-circle"></i> Completed
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-in-progress">
                                            <i class="fas fa-spinner fa-pulse"></i> In Progress
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($result['session_status'] === 'completed'): ?>
                                        <?php echo isset($result['score']) ? $result['score'] : '0'; ?> / <?php echo isset($result['total_questions']) ? $result['total_questions'] : '0'; ?>
                                    <?php else: ?>
                                        --
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($result['session_status'] === 'completed' && isset($result['percentage'])): ?>
                                        <?php echo number_format($result['percentage'], 2); ?>%
                                    <?php else: ?>
                                        --
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($result['session_status'] === 'completed' && isset($result['grade']) && $result['grade']): ?>
                                        <span class="grade-badge grade-<?php echo $result['grade']; ?>">
                                            <?php echo $result['grade']; ?>
                                        </span>
                                    <?php else: ?>
                                        --
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (isset($result['submitted_at']) && $result['submitted_at']): ?>
                                        <?php echo date('d/m/Y H:i', strtotime($result['submitted_at'])); ?>
                                    <?php else: ?>
                                        --
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-icons">
                                        <?php if ($result['session_status'] === 'completed'): ?>
                                            <a href="?exam_id=<?php echo $exam_id; ?>&download_result=<?php echo $result['session_id']; ?><?php echo $class_filter ? '&class=' . urlencode($class_filter) : ''; ?><?php echo $student_filter ? '&student=' . urlencode($student_filter) : ''; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?>"
                                                class="action-icon download" title="Download Result">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <a href="javascript:void(0);"
                                                onclick="viewResultDetails(<?php echo $result['session_id']; ?>, '<?php echo htmlspecialchars($result['student_name']); ?>')"
                                                class="action-icon view" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="?exam_id=<?php echo $exam_id; ?>&delete_result=<?php echo $result['session_id']; ?><?php echo $class_filter ? '&class=' . urlencode($class_filter) : ''; ?><?php echo $student_filter ? '&student=' . urlencode($student_filter) : ''; ?><?php echo $status_filter ? '&status=' . urlencode($status_filter) : ''; ?>"
                                            class="action-icon delete" title="Delete Result"
                                            onclick="return confirm('Are you sure you want to delete this result? This action cannot be undone.');">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="empty-state">
                                <i class="fas fa-chart-bar"></i>
                                <h3>No Results Found</h3>
                                <p>No students have taken this exam yet or no results match your filters.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- View Result Details Modal -->
    <div class="modal" id="resultModal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3 id="resultModalTitle">Result Details</h3>
                <button class="modal-close" onclick="closeResultModal()">&times;</button>
            </div>
            <div class="modal-body" id="resultModalBody">
                <div style="text-align: center; padding: 20px;">
                    <div class="loading"></div>
                    <p>Loading result details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeResultModal()">Close</button>
            </div>
        </div>
    </div>

    <style>
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 10px;
            width: 100%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            padding: 20px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }

        .modal-header h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
            transition: color 0.3s ease;
        }

        .modal-close:hover {
            color: var(--danger-color);
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 20px;
            border-top: 2px solid var(--light-color);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
            background: white;
        }

        .loading {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 3px solid #f3f3f3;
            border-radius: 50%;
            border-top-color: var(--secondary-color);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .result-detail-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .result-detail-table th,
        .result-detail-table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
            vertical-align: top;
        }

        .result-detail-table th {
            background: var(--light-color);
        }

        .correct-answer {
            color: green;
        }

        .incorrect-answer {
            color: red;
        }
    </style>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function() {
                sidebar.classList.toggle('active');
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (sidebar && !sidebar.contains(event.target) && mobileMenuBtn && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // View result details via AJAX
        async function viewResultDetails(sessionId, studentName) {
            const modal = document.getElementById('resultModal');
            const modalTitle = document.getElementById('resultModalTitle');
            const modalBody = document.getElementById('resultModalBody');

            modalTitle.textContent = `Result Details - ${studentName}`;
            modalBody.innerHTML = '<div style="text-align: center; padding: 20px;"><div class="loading"></div><p>Loading result details...</p></div>';
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';

            try {
                const response = await fetch(`get-result-details.php?session_id=${sessionId}`);
                const data = await response.json();

                if (data.success) {
                    let html = `
                        <div class="result-summary" style="background: #f5f5f5; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                            <h4>Result Summary</h4>
                            <p><strong>Score:</strong> ${data.score} / ${data.total_questions}</p>
                            <p><strong>Percentage:</strong> ${data.percentage}%</p>
                            <p><strong>Grade:</strong> <span class="grade-badge grade-${data.grade}">${data.grade}</span></p>
                            <p><strong>Correct Answers:</strong> ${data.correct_answers} out of ${data.total_questions}</p>
                            <p><strong>Completed:</strong> ${data.submitted_at}</p>
                        </div>
                        <h4>Question Details</h4>
                        <table class="result-detail-table">
                            <thead>
                                <tr>
                                    <th style="width: 5%">#</th>
                                    <th style="width: 50%">Question</th>
                                    <th style="width: 20%">Your Answer</th>
                                    <th style="width: 15%">Correct Answer</th>
                                    <th style="width: 10%">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    data.answers.forEach((answer, index) => {
                        const statusClass = answer.is_correct ? 'correct-answer' : 'incorrect-answer';
                        const statusText = answer.is_correct ? '✓ Correct' : '✗ Incorrect';
                        html += `
                            <tr>
                                <td>${index + 1}</td>
                                <td>${escapeHtml(answer.question_text)}</td>
                                <td>${escapeHtml(answer.selected_option || 'Not answered')}</td>
                                <td>${escapeHtml(answer.correct_answer)}</td>
                                <td class="${statusClass}">${statusText}</td>
                            </tr>
                        `;
                    });

                    html += `</tbody></table>`;
                    modalBody.innerHTML = html;
                } else {
                    modalBody.innerHTML = `<div style="text-align: center; padding: 20px; color: red;">Error: ${data.error || 'Could not load result details'}</div>`;
                }
            } catch (error) {
                console.error('Error:', error);
                modalBody.innerHTML = '<div style="text-align: center; padding: 20px; color: red;">Error loading result details. Please try again.</div>';
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function closeResultModal() {
            const modal = document.getElementById('resultModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        }

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeResultModal();
            }
        });

        // Close modal when clicking outside
        document.getElementById('resultModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeResultModal();
            }
        });
    </script>
</body>

</html>