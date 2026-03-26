<?php
// admin/import-questions.php - Import Questions
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

// Initialize variables
$subjects = [];
$classes = [];
$topics = [];
$error_message = '';
$success_message = '';
$import_stats = null;

// Fetch all subjects for dropdown
try {
    $stmt = $pdo->query("SELECT id, subject_name, class FROM subjects ORDER BY class, subject_name");
    $subjects = $stmt->fetchAll();

    // Fetch all distinct classes
    $stmt = $pdo->query("SELECT DISTINCT class FROM subjects ORDER BY class");
    $classes = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (Exception $e) {
    error_log("Import questions error: " . $e->getMessage());
    $error_message = "Error loading subject data";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $class = $_POST['class'] ?? '';
    $question_type = $_POST['question_type'] ?? 'objective';
    $topic_id = intval($_POST['topic_id'] ?? 0);
    $difficulty = $_POST['difficulty'] ?? 'medium';
    $marks = intval($_POST['marks'] ?? 1);
    $passage_id = intval($_POST['passage_id'] ?? 0);

    // Validate inputs
    if ($subject_id <= 0 || empty($class)) {
        $error_message = "Please select subject and class";
    } elseif (!isset($_FILES['question_file']) || $_FILES['question_file']['error'] !== UPLOAD_ERR_OK) {
        $error_message = "Please select a file to upload";
    } else {
        $file = $_FILES['question_file'];
        $file_type = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Validate file type
        $allowed_types = ['csv', 'json'];
        if (!in_array($file_type, $allowed_types)) {
            $error_message = "Only CSV and JSON files are allowed";
        } elseif ($file['size'] > 5242880) { // 5MB limit
            $error_message = "File size must be less than 5MB";
        } else {
            try {
                $pdo->beginTransaction();

                // Get subject name
                $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
                $stmt->execute([$subject_id]);
                $subject = $stmt->fetch();
                $subject_name = $subject['subject_name'] ?? '';

                $import_stats = [
                    'total' => 0,
                    'success' => 0,
                    'failed' => 0,
                    'errors' => []
                ];

                if ($file_type === 'csv') {
                    processCSV($pdo, $file['tmp_name'], $subject_id, $subject_name, $class, $topic_id, $question_type, $difficulty, $marks, $passage_id, $import_stats);
                } elseif ($file_type === 'json') {
                    processJSON($pdo, $file['tmp_name'], $subject_id, $subject_name, $class, $topic_id, $question_type, $difficulty, $marks, $passage_id, $import_stats);
                }

                $pdo->commit();

                if ($import_stats['success'] > 0) {
                    $success_message = "Successfully imported {$import_stats['success']} questions. ";
                    if ($import_stats['failed'] > 0) {
                        $success_message .= "{$import_stats['failed']} questions failed to import.";
                    }
                } else {
                    $error_message = "No questions were imported. Please check your file format.";
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Import process error: " . $e->getMessage());
                $error_message = "Error importing questions: " . $e->getMessage();
            }
        }
    }
}

// Fetch topics for selected subject
if (isset($_POST['subject_id']) || isset($_GET['subject_id'])) {
    $subject_id = intval($_POST['subject_id'] ?? $_GET['subject_id'] ?? 0);
    if ($subject_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE subject_id = ? ORDER BY topic_name");
            $stmt->execute([$subject_id]);
            $topics = $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Fetch topics error: " . $e->getMessage());
        }
    }
}

// Function to process CSV file
function processCSV($pdo, $file_path, $subject_id, $subject_name, $class, $topic_id, $question_type, $difficulty, $marks, $passage_id, &$stats)
{
    $handle = fopen($file_path, 'r');
    if ($handle === false) {
        throw new Exception("Unable to open CSV file");
    }

    $header = fgetcsv($handle); // Read header
    $expected_columns = getExpectedColumns($question_type);

    // Validate header
    if (count($header) < count($expected_columns)) {
        fclose($handle);
        throw new Exception("Invalid CSV format. Expected columns: " . implode(', ', $expected_columns));
    }

    $row_number = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $row_number++;
        $stats['total']++;

        // Skip empty rows
        if (empty(array_filter($row))) {
            continue;
        }

        try {
            if ($question_type === 'objective') {
                importObjectiveQuestion($pdo, $header, $row, $subject_id, $subject_name, $class, $topic_id, $difficulty, $marks, $passage_id, $stats);
            } elseif ($question_type === 'subjective') {
                importSubjectiveQuestion($pdo, $header, $row, $subject_id, $subject_name, $class, $topic_id, $difficulty, $marks, $stats);
            } elseif ($question_type === 'theory') {
                importTheoryQuestion($pdo, $header, $row, $subject_id, $subject_name, $class, $topic_id, $marks, $stats);
            }
        } catch (Exception $e) {
            $stats['failed']++;
            $stats['errors'][] = "Row {$row_number}: " . $e->getMessage();
        }
    }

    fclose($handle);
}

// Function to process JSON file
function processJSON($pdo, $file_path, $subject_id, $subject_name, $class, $topic_id, $question_type, $difficulty, $marks, $passage_id, &$stats)
{
    $content = file_get_contents($file_path);
    if ($content === false) {
        throw new Exception("Unable to read JSON file");
    }

    $questions = json_decode($content, true);
    if ($questions === null) {
        throw new Exception("Invalid JSON format");
    }

    if (!is_array($questions)) {
        throw new Exception("JSON must contain an array of questions");
    }

    foreach ($questions as $index => $question) {
        $stats['total']++;

        try {
            if ($question_type === 'objective') {
                importObjectiveQuestionJSON($pdo, $question, $subject_id, $subject_name, $class, $topic_id, $difficulty, $marks, $passage_id, $stats);
            } elseif ($question_type === 'subjective') {
                importSubjectiveQuestionJSON($pdo, $question, $subject_id, $subject_name, $class, $topic_id, $difficulty, $marks, $stats);
            } elseif ($question_type === 'theory') {
                importTheoryQuestionJSON($pdo, $question, $subject_id, $subject_name, $class, $topic_id, $marks, $stats);
            }
        } catch (Exception $e) {
            $stats['failed']++;
            $stats['errors'][] = "Question {$index}: " . $e->getMessage();
        }
    }
}

// Function to get expected columns based on question type
function getExpectedColumns($question_type)
{
    switch ($question_type) {
        case 'objective':
            return ['question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer'];
        case 'subjective':
            return ['question_text', 'correct_answer'];
        case 'theory':
            return ['question_text'];
        default:
            return [];
    }
}

// Function to import objective question from CSV
function importObjectiveQuestion($pdo, $header, $row, $subject_id, $subject_name, $class, $topic_id, $difficulty, $marks, $passage_id, &$stats)
{
    $data = array_combine($header, $row);

    // Validate required fields
    $required = ['question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer'];
    foreach ($required as $field) {
        if (empty($data[$field] ?? '')) {
            throw new Exception("Missing required field: {$field}");
        }
    }

    // Validate correct answer
    $correct_answer = strtoupper(trim($data['correct_answer']));
    if (!in_array($correct_answer, ['A', 'B', 'C', 'D'])) {
        throw new Exception("Correct answer must be A, B, C, or D");
    }

    // Insert question
    $sql = "INSERT INTO objective_questions 
            (question_text, option_a, option_b, option_c, option_d, correct_answer, 
             subject_id, topic_id, difficulty_level, marks, class, passage_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        trim($data['question_text']),
        trim($data['option_a']),
        trim($data['option_b']),
        trim($data['option_c']),
        trim($data['option_d']),
        $correct_answer,
        $subject_id,
        $topic_id ?: null,
        $difficulty,
        $marks,
        $class,
        $passage_id ?: null
    ]);

    $stats['success']++;
}

// Function to import objective question from JSON
function importObjectiveQuestionJSON($pdo, $question, $subject_id, $subject_name, $class, $topic_id, $difficulty, $marks, $passage_id, &$stats)
{
    // Validate required fields
    $required = ['question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer'];
    foreach ($required as $field) {
        if (empty($question[$field] ?? '')) {
            throw new Exception("Missing required field: {$field}");
        }
    }

    // Validate correct answer
    $correct_answer = strtoupper(trim($question['correct_answer']));
    if (!in_array($correct_answer, ['A', 'B', 'C', 'D'])) {
        throw new Exception("Correct answer must be A, B, C, or D");
    }

    // Insert question
    $sql = "INSERT INTO objective_questions 
            (question_text, option_a, option_b, option_c, option_d, correct_answer, 
             subject_id, topic_id, difficulty_level, marks, class, passage_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        trim($question['question_text']),
        trim($question['option_a']),
        trim($question['option_b']),
        trim($question['option_c']),
        trim($question['option_d']),
        $correct_answer,
        $subject_id,
        $topic_id ?: null,
        $difficulty,
        $marks,
        $class,
        $passage_id ?: null
    ]);

    $stats['success']++;
}

// Function to import subjective question
function importSubjectiveQuestion($pdo, $header, $row, $subject_id, $subject_name, $class, $topic_id, $difficulty, $marks, &$stats)
{
    $data = array_combine($header, $row);

    // Validate required fields
    if (empty($data['question_text'] ?? '')) {
        throw new Exception("Missing required field: question_text");
    }

    $correct_answer = $data['correct_answer'] ?? '';

    // Insert question
    $sql = "INSERT INTO subjective_questions 
            (question_text, correct_answer, difficulty_level, marks, subject_id, topic_id, class, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        trim($data['question_text']),
        trim($correct_answer),
        $difficulty,
        $marks,
        $subject_id,
        $topic_id ?: null,
        $class
    ]);

    $stats['success']++;
}

// Function to import subjective question from JSON
function importSubjectiveQuestionJSON($pdo, $question, $subject_id, $subject_name, $class, $topic_id, $difficulty, $marks, &$stats)
{
    // Validate required fields
    if (empty($question['question_text'] ?? '')) {
        throw new Exception("Missing required field: question_text");
    }

    $correct_answer = $question['correct_answer'] ?? '';

    // Insert question
    $sql = "INSERT INTO subjective_questions 
            (question_text, correct_answer, difficulty_level, marks, subject_id, topic_id, class, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        trim($question['question_text']),
        trim($correct_answer),
        $difficulty,
        $marks,
        $subject_id,
        $topic_id ?: null,
        $class
    ]);

    $stats['success']++;
}

// Function to import theory question
function importTheoryQuestion($pdo, $header, $row, $subject_id, $subject_name, $class, $topic_id, $marks, &$stats)
{
    $data = array_combine($header, $row);

    // Validate required fields
    if (empty($data['question_text'] ?? '')) {
        throw new Exception("Missing required field: question_text");
    }

    // Insert question
    $sql = "INSERT INTO theory_questions 
            (question_text, subject_id, topic_id, class, marks, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        trim($data['question_text']),
        $subject_id,
        $topic_id ?: null,
        $class,
        $marks
    ]);

    $stats['success']++;
}

// Function to import theory question from JSON
function importTheoryQuestionJSON($pdo, $question, $subject_id, $subject_name, $class, $topic_id, $marks, &$stats)
{
    // Validate required fields
    if (empty($question['question_text'] ?? '')) {
        throw new Exception("Missing required field: question_text");
    }

    // Insert question
    $sql = "INSERT INTO theory_questions 
            (question_text, subject_id, topic_id, class, marks, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        trim($question['question_text']),
        $subject_id,
        $topic_id ?: null,
        $class,
        $marks
    ]);

    $stats['success']++;
}

// Get message from URL
if (isset($_GET['message'])) {
    $success_message = urldecode($_GET['message']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Questions - Admin Dashboard</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* Reuse all styles from manage-exams.php */
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
        }

        /* Sidebar Styles - Same as manage-exams.php */
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
        }

        .sidebar-content {
            height: 100%;
            overflow-y: auto;
            padding: 0 0 20px 0;
        }

        .sidebar-content::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar-content::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }

        .sidebar-content::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 20px 20px;
            margin-bottom: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
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
            padding: 0 15px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            border-radius: 8px;
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

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
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
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.8rem;
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

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
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

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #e67e22);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(243, 156, 18, 0.3);
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }

        /* Import Form */
        .import-form-container {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .form-title {
            color: var(--primary-color);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-title i {
            color: var(--secondary-color);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
        }

        .form-group label i {
            margin-right: 8px;
            color: var(--secondary-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-control-file {
            padding: 10px;
            background: #f8f9fa;
            border: 2px dashed #ddd;
            border-radius: 8px;
            cursor: pointer;
        }

        .form-control-file:hover {
            border-color: var(--secondary-color);
        }

        .file-info {
            margin-top: 8px;
            font-size: 0.85rem;
            color: #666;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #f0f0f0;
        }

        /* Import Stats */
        .import-stats {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .stats-title {
            color: var(--primary-color);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        .stat-success .stat-value {
            color: var(--success-color);
        }

        .stat-failed .stat-value {
            color: var(--danger-color);
        }

        .stat-total .stat-value {
            color: var(--primary-color);
        }

        .errors-list {
            max-height: 200px;
            overflow-y: auto;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
        }

        .error-item {
            padding: 8px 12px;
            margin-bottom: 5px;
            background: #fff;
            border-left: 4px solid var(--danger-color);
            border-radius: 4px;
            font-size: 0.85rem;
        }

        /* File Format Guide */
        .format-guide {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .guide-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }

        .guide-tab {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            color: #666;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .guide-tab.active {
            background: var(--secondary-color);
            color: white;
        }

        .guide-tab:hover:not(.active) {
            background: #f0f0f0;
        }

        .guide-content {
            display: none;
        }

        .guide-content.active {
            display: block;
        }

        .code-block {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 15px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.9rem;
            overflow-x: auto;
            margin: 15px 0;
        }

        .download-sample {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--secondary-color);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 15px;
        }

        .download-sample:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        /* Responsive Design */
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

            .top-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            display: none;
        }

        @media (max-width: 768px) {
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
        <div class="sidebar-content">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="logo-text">
                    <h3><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?></h3>
                    <p>Admin Panel</p>
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
                <li><a href="import-questions.php" class="active"><i class="fas fa-file-import"></i> Import Questions</a></li>
                <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>Import Questions</h1>
                <p>Upload CSV or JSON files to import questions into the system</p>
            </div>
            <div class="header-actions">
                <a href="manage-exams.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Exams
                </a>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Import Form -->
        <div class="import-form-container">
            <h2 class="form-title">
                <i class="fas fa-upload"></i> Upload Questions File
            </h2>

            <form action="" method="POST" enctype="multipart/form-data" id="importForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="subject_id"><i class="fas fa-book"></i> Subject</label>
                        <select name="subject_id" id="subject_id" class="form-control" required>
                            <option value="">Select Subject</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>"
                                    <?php echo (isset($_POST['subject_id']) && $_POST['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name'] . ' (' . $subject['class'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="class"><i class="fas fa-school"></i> Class</label>
                        <select name="class" id="class" class="form-control" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class); ?>"
                                    <?php echo (isset($_POST['class']) && $_POST['class'] == $class) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="question_type"><i class="fas fa-question-circle"></i> Question Type</label>
                        <select name="question_type" id="question_type" class="form-control" required>
                            <option value="objective" <?php echo (isset($_POST['question_type']) && $_POST['question_type'] == 'objective') ? 'selected' : ''; ?>>Objective Questions</option>
                            <option value="subjective" <?php echo (isset($_POST['question_type']) && $_POST['question_type'] == 'subjective') ? 'selected' : ''; ?>>Subjective Questions</option>
                            <option value="theory" <?php echo (isset($_POST['question_type']) && $_POST['question_type'] == 'theory') ? 'selected' : ''; ?>>Theory Questions</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="topic_id"><i class="fas fa-tag"></i> Topic (Optional)</label>
                        <select name="topic_id" id="topic_id" class="form-control">
                            <option value="">Select Topic</option>
                            <?php foreach ($topics as $topic): ?>
                                <option value="<?php echo $topic['id']; ?>"
                                    <?php echo (isset($_POST['topic_id']) && $_POST['topic_id'] == $topic['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($topic['topic_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="difficulty"><i class="fas fa-chart-line"></i> Difficulty Level</label>
                        <select name="difficulty" id="difficulty" class="form-control">
                            <option value="easy" <?php echo (isset($_POST['difficulty']) && $_POST['difficulty'] == 'easy') ? 'selected' : 'selected'; ?>>Easy</option>
                            <option value="medium" <?php echo (isset($_POST['difficulty']) && $_POST['difficulty'] == 'medium') ? 'selected' : ''; ?>>Medium</option>
                            <option value="hard" <?php echo (isset($_POST['difficulty']) && $_POST['difficulty'] == 'hard') ? 'selected' : ''; ?>>Hard</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="marks"><i class="fas fa-star"></i> Marks per Question</label>
                        <input type="number" name="marks" id="marks" class="form-control" value="<?php echo isset($_POST['marks']) ? htmlspecialchars($_POST['marks']) : '1'; ?>" min="1" max="100">
                    </div>
                </div>

                <div class="form-group">
                    <label for="question_file"><i class="fas fa-file"></i> Select File</label>
                    <input type="file" name="question_file" id="question_file" class="form-control form-control-file" accept=".csv,.json" required>
                    <div class="file-info">
                        <p><i class="fas fa-info-circle"></i> Supported formats: CSV (.csv) and JSON (.json)</p>
                        <p><i class="fas fa-database"></i> Maximum file size: 5MB</p>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="reset" class="btn">
                        <i class="fas fa-redo"></i> Reset Form
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-upload"></i> Import Questions
                    </button>
                </div>
            </form>
        </div>

        <!-- Import Results -->
        <?php if ($import_stats): ?>
            <div class="import-stats">
                <h3 class="stats-title">
                    <i class="fas fa-chart-bar"></i> Import Results
                </h3>

                <div class="stats-grid">
                    <div class="stat-card stat-total">
                        <div class="stat-value"><?php echo $import_stats['total']; ?></div>
                        <div class="stat-label">Total Questions</div>
                    </div>
                    <div class="stat-card stat-success">
                        <div class="stat-value"><?php echo $import_stats['success']; ?></div>
                        <div class="stat-label">Successfully Imported</div>
                    </div>
                    <div class="stat-card stat-failed">
                        <div class="stat-value"><?php echo $import_stats['failed']; ?></div>
                        <div class="stat-label">Failed to Import</div>
                    </div>
                </div>

                <?php if (!empty($import_stats['errors'])): ?>
                    <div class="form-group">
                        <label><i class="fas fa-exclamation-triangle"></i> Import Errors</label>
                        <div class="errors-list">
                            <?php foreach ($import_stats['errors'] as $error): ?>
                                <div class="error-item"><?php echo htmlspecialchars($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- File Format Guide -->
        <div class="format-guide">
            <h3 class="form-title">
                <i class="fas fa-file-alt"></i> File Format Guide
            </h3>

            <div class="guide-tabs">
                <button type="button" class="guide-tab active" data-target="csv-guide">CSV Format</button>
                <button type="button" class="guide-tab" data-target="json-guide">JSON Format</button>
                <button type="button" class="guide-tab" data-target="tips-guide">Tips & Instructions</button>
            </div>

            <div id="csv-guide" class="guide-content active">
                <h4><i class="fas fa-file-csv"></i> CSV File Format</h4>
                <p>Your CSV file should have the following columns based on question type:</p>

                <div class="code-block">
                    <h5>Objective Questions (Required columns):</h5>
                    <pre>question_text,option_a,option_b,option_c,option_d,correct_answer</pre>

                    <h5>Optional columns:</h5>
                    <pre>topic_id,difficulty_level,marks</pre>

                    <h5>Example:</h5>
                    <pre>"What is 2+2?","4","5","6","7","A"</pre>
                    <pre>"Capital of France?","London","Berlin","Paris","Madrid","C"</pre>
                </div>

                <a href="download-sample.php?type=csv&format=objective" class="download-sample">
                    <i class="fas fa-download"></i> Download CSV Sample
                </a>
            </div>

            <div id="json-guide" class="guide-content">
                <h4><i class="fas fa-file-code"></i> JSON File Format</h4>
                <p>Your JSON file should contain an array of question objects:</p>

                <div class="code-block">
                    <h5>Objective Questions Format:</h5>
                    <pre>[
  {
    "question_text": "What is 2+2?",
    "option_a": "4",
    "option_b": "5",
    "option_c": "6",
    "option_d": "7",
    "correct_answer": "A"
  },
  {
    "question_text": "Capital of France?",
    "option_a": "London",
    "option_b": "Berlin",
    "option_c": "Paris",
    "option_d": "Madrid",
    "correct_answer": "C"
  }
]</pre>
                </div>

                <a href="download-sample.php?type=json&format=objective" class="download-sample">
                    <i class="fas fa-download"></i> Download JSON Sample
                </a>
            </div>

            <div id="tips-guide" class="guide-content">
                <h4><i class="fas fa-lightbulb"></i> Tips for Successful Import</h4>
                <ul style="padding-left: 20px; margin: 15px 0;">
                    <li>Ensure your file is in UTF-8 encoding</li>
                    <li>Remove any special characters that might cause issues</li>
                    <li>For CSV files, use commas as separators and quotes for text fields</li>
                    <li>Make sure correct_answer values are A, B, C, or D (uppercase)</li>
                    <li>Test with a small file first before importing large datasets</li>
                    <li>Backup your database before bulk imports</li>
                    <li>Check file size limit (5MB maximum)</li>
                </ul>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Note:</strong> The system will skip rows/questions with missing required fields or invalid data.
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');

        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // File input display
        const fileInput = document.getElementById('question_file');
        const fileInfo = document.querySelector('.file-info');

        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                const file = this.files[0];
                const fileSize = (file.size / (1024 * 1024)).toFixed(2); // MB
                fileInfo.innerHTML = `
                    <p><i class="fas fa-check-circle" style="color: var(--success-color);"></i> File selected: ${file.name}</p>
                    <p><i class="fas fa-database"></i> Size: ${fileSize} MB</p>
                `;
            }
        });

        // Form validation
        const importForm = document.getElementById('importForm');
        importForm.addEventListener('submit', function(e) {
            const subjectId = document.getElementById('subject_id').value;
            const classSelect = document.getElementById('class').value;
            const fileInput = document.getElementById('question_file');

            if (!subjectId || !classSelect) {
                e.preventDefault();
                alert('Please select both subject and class');
                return;
            }

            if (fileInput.files.length === 0) {
                e.preventDefault();
                alert('Please select a file to upload');
                return;
            }

            const file = fileInput.files[0];
            if (file.size > 5 * 1024 * 1024) { // 5MB
                e.preventDefault();
                alert('File size must be less than 5MB');
                return;
            }

            // Show loading
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
            submitBtn.disabled = true;
        });

        // Guide tabs
        document.querySelectorAll('.guide-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const target = this.getAttribute('data-target');

                // Remove active class from all tabs and contents
                document.querySelectorAll('.guide-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.guide-content').forEach(c => c.classList.remove('active'));

                // Add active class to clicked tab and target content
                this.classList.add('active');
                document.getElementById(target).classList.add('active');
            });
        });

        // Load topics when subject changes
        const subjectSelect = document.getElementById('subject_id');
        const topicSelect = document.getElementById('topic_id');

        subjectSelect.addEventListener('change', function() {
            const subjectId = this.value;

            if (!subjectId) {
                topicSelect.innerHTML = '<option value="">Select Topic</option>';
                return;
            }

            // Show loading
            topicSelect.innerHTML = '<option value="">Loading topics...</option>';
            topicSelect.disabled = true;

            // Fetch topics via AJAX
            fetch(`?subject_id=${subjectId}&ajax=1`)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const options = doc.querySelector('#topic_id')?.innerHTML;

                    if (options) {
                        topicSelect.innerHTML = options;
                    } else {
                        topicSelect.innerHTML = '<option value="">No topics found</option>';
                    }
                    topicSelect.disabled = false;
                })
                .catch(error => {
                    console.error('Error loading topics:', error);
                    topicSelect.innerHTML = '<option value="">Error loading topics</option>';
                    topicSelect.disabled = false;
                });
        });

        // Handle question type changes
        const questionTypeSelect = document.getElementById('question_type');
        const difficultySelect = document.getElementById('difficulty');
        const marksInput = document.getElementById('marks');

        questionTypeSelect.addEventListener('change', function() {
            const type = this.value;

            // Adjust form based on question type
            if (type === 'theory') {
                difficultySelect.disabled = true;
                marksInput.value = '5'; // Default for theory
            } else {
                difficultySelect.disabled = false;
                marksInput.value = '1'; // Default for objective/subjective
            }
        });

        // Initialize based on current selection
        if (questionTypeSelect.value === 'theory') {
            difficultySelect.disabled = true;
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to submit form
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                importForm.querySelector('button[type="submit"]').click();
            }

            // Escape to close sidebar on mobile
            if (e.key === 'Escape' && window.innerWidth <= 768) {
                sidebar.classList.remove('active');
            }
        });

        // Auto-focus first field
        window.addEventListener('load', function() {
            if (!subjectSelect.value) {
                subjectSelect.focus();
            }
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>

</html>