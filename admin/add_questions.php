<?php
// admin/add_questions.php - Add Questions with Rich Text Editor
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Get admin information
$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';

// Get topic ID from URL
$topic_id = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$question_type = isset($_GET['type']) ? $_GET['type'] : 'objective';

// Validate topic
$selected_topic = null;
if ($topic_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, s.subject_name, s.id as subject_id 
            FROM topics t 
            JOIN subjects s ON t.subject_id = s.id 
            WHERE t.id = ?
        ");
        $stmt->execute([$topic_id]);
        $selected_topic = $stmt->fetch();

        if ($selected_topic) {
            // Get the class for this subject
            $class_stmt = $pdo->prepare("SELECT class FROM subject_classes WHERE subject_id = ? LIMIT 1");
            $class_stmt->execute([$selected_topic['subject_id']]);
            $class_row = $class_stmt->fetch();
            $selected_topic['class'] = $class_row['class'] ?? 'N/A';
        }
    } catch (Exception $e) {
        die("Error loading topic: " . $e->getMessage());
    }
}

if (!$selected_topic) {
    header("Location: manage_questions.php");
    exit();
}

$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle Objective Question Submission
    if (isset($_POST['add_objective_question'])) {
        $question_text = isset($_POST['question_text']) ? trim($_POST['question_text']) : '';
        $option_a = isset($_POST['option_a']) ? trim($_POST['option_a']) : '';
        $option_b = isset($_POST['option_b']) ? trim($_POST['option_b']) : '';
        $option_c = isset($_POST['option_c']) ? trim($_POST['option_c']) : '';
        $option_d = isset($_POST['option_d']) ? trim($_POST['option_d']) : '';
        $correct_answer = isset($_POST['correct_answer']) ? $_POST['correct_answer'] : '';
        $difficulty_level = isset($_POST['difficulty_level']) ? $_POST['difficulty_level'] : 'medium';
        $marks = isset($_POST['marks']) ? (int)$_POST['marks'] : 1;

        if (!empty($question_text) && !empty($option_a) && !empty($option_b) && !empty($correct_answer)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO objective_questions 
                    (question_text, option_a, option_b, option_c, option_d, correct_answer, 
                     difficulty_level, marks, subject_id, topic_id, class) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $question_text,
                    $option_a,
                    $option_b,
                    $option_c,
                    $option_d,
                    $correct_answer,
                    $difficulty_level,
                    $marks,
                    $selected_topic['subject_id'],
                    $topic_id,
                    $selected_topic['class']
                ]);

                $message = "Objective question added successfully!";
                $message_type = "success";

                // Log activity
                $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity) VALUES (?, ?, ?)")
                    ->execute([$_SESSION['admin_id'], 'admin', "Added objective question for topic ID: $topic_id"]);

                // Clear form on success if not in bulk mode
                if (!isset($_POST['bulk_mode'])) {
                    unset(
                        $_POST['question_text'],
                        $_POST['option_a'],
                        $_POST['option_b'],
                        $_POST['option_c'],
                        $_POST['option_d'],
                        $_POST['correct_answer'],
                        $_POST['difficulty_level'],
                        $_POST['marks']
                    );
                }
            } catch (Exception $e) {
                $message = "Error adding question: " . $e->getMessage();
                $message_type = "error";
            }
        } else {
            $message = "Please fill in all required fields!";
            $message_type = "error";
        }
    }

    // Handle Subjective Question Submission
    if (isset($_POST['add_subjective_question'])) {
        $question_text = isset($_POST['subjective_question_text']) ? trim($_POST['subjective_question_text']) : '';
        $correct_answer = isset($_POST['subjective_correct_answer']) ? trim($_POST['subjective_correct_answer']) : '';
        $difficulty_level = isset($_POST['subjective_difficulty_level']) ? $_POST['subjective_difficulty_level'] : 'medium';
        $marks = isset($_POST['subjective_marks']) ? (int)$_POST['subjective_marks'] : 1;

        if (!empty($question_text) && !empty($correct_answer)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO subjective_questions 
                    (question_text, correct_answer, difficulty_level, marks, subject_id, topic_id, class) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $question_text,
                    $correct_answer,
                    $difficulty_level,
                    $marks,
                    $selected_topic['subject_id'],
                    $topic_id,
                    $selected_topic['class']
                ]);

                $message = "Subjective question added successfully!";
                $message_type = "success";

                // Log activity
                $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity) VALUES (?, ?, ?)")
                    ->execute([$_SESSION['admin_id'], 'admin', "Added subjective question for topic ID: $topic_id"]);

                // Clear form on success if not in bulk mode
                if (!isset($_POST['bulk_mode'])) {
                    unset(
                        $_POST['subjective_question_text'],
                        $_POST['subjective_correct_answer'],
                        $_POST['subjective_difficulty_level'],
                        $_POST['subjective_marks']
                    );
                }
            } catch (Exception $e) {
                $message = "Error adding question: " . $e->getMessage();
                $message_type = "error";
            }
        } else {
            $message = "Please fill in all required fields!";
            $message_type = "error";
        }
    }

    // Handle Theory Question Submission
    if (isset($_POST['add_theory_question'])) {
        $question_text = isset($_POST['theory_question_text']) ? trim($_POST['theory_question_text']) : '';
        $marks = isset($_POST['theory_marks']) ? (int)$_POST['theory_marks'] : 5;

        if (!empty($question_text)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO theory_questions 
                    (question_text, marks, subject_id, topic_id, class) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $question_text,
                    $marks,
                    $selected_topic['subject_id'],
                    $topic_id,
                    $selected_topic['class']
                ]);

                $message = "Theory question added successfully!";
                $message_type = "success";

                // Log activity
                $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity) VALUES (?, ?, ?)")
                    ->execute([$_SESSION['admin_id'], 'admin', "Added theory question for topic ID: $topic_id"]);

                // Clear form on success if not in bulk mode
                if (!isset($_POST['bulk_mode'])) {
                    unset($_POST['theory_question_text'], $_POST['theory_marks']);
                }
            } catch (Exception $e) {
                $message = "Error adding question: " . $e->getMessage();
                $message_type = "error";
            }
        } else {
            $message = "Please enter the question text!";
            $message_type = "error";
        }
    }

    // Handle Bulk Import from Word/CSV
    if (isset($_POST['bulk_import'])) {
        $import_type = isset($_POST['import_type']) ? $_POST['import_type'] : '';
        $file_content = '';

        if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $file_path = $_FILES['import_file']['tmp_name'];
            $file_name = $_FILES['import_file']['name'];
            $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            // Process Word Document
            if ($file_extension === 'docx') {
                try {
                    // Method 1: Try using a simple DOCX text extractor
                    $file_content = extractTextFromDocx($file_path);

                    // Method 2: If empty, try with PhpWord
                    if (empty(trim($file_content))) {
                        if (class_exists('PhpOffice\PhpWord\IOFactory')) {
                            $file_content = extractTextWithPhpWord($file_path);
                        }
                    }

                    // Method 3: If still empty, use ZIP extraction
                    if (empty(trim($file_content))) {
                        $file_content = extractTextFromDocxZip($file_path);
                    }

                    if (empty(trim($file_content))) {
                        throw new Exception("Could not extract text from Word document. The file may be corrupted or in an unsupported format.");
                    }

                    // Clean and normalize the content
                    $file_content = cleanWordContent($file_content);
                } catch (Exception $e) {
                    $message = "Error processing Word document: " . $e->getMessage();
                    $message_type = "error";
                    error_log("Word processing error: " . $e->getMessage());
                }
            }
            // Process CSV file
            elseif ($file_extension === 'csv') {
                $file_content = file_get_contents($file_path);
                // Convert to UTF-8 if needed
                if (!mb_check_encoding($file_content, 'UTF-8')) {
                    $file_content = mb_convert_encoding($file_content, 'UTF-8');
                }
            } else {
                $message = "Only CSV and Word (.docx) files are supported for bulk import!";
                $message_type = "error";
            }

            // Debug: Log what we got from the file
            error_log("File content length: " . strlen($file_content));
            error_log("File content preview: " . substr($file_content, 0, 200));

            // Process the content if we have it
            if (!empty($file_content) && empty($message)) {
                $success_count = 0;
                $error_count = 0;
                $errors = [];

                // Process based on import type
                if ($import_type === 'objective') {
                    $lines = preg_split('/\r\n|\r|\n/', $file_content);
                    error_log("Number of lines in file: " . count($lines));

                    foreach ($lines as $line_index => $line) {
                        $line = trim($line);
                        if (empty($line)) continue;

                        error_log("Processing line $line_index: " . substr($line, 0, 100));

                        // Try different delimiters
                        if (strpos($line, '|') !== false) {
                            $parts = array_map('trim', explode('|', $line));
                        } elseif (strpos($line, ',') !== false) {
                            $parts = str_getcsv($line, ',');
                            $parts = array_map('trim', $parts);
                        } else {
                            $parts = preg_split('/\t+/', $line); // Tab delimited
                            $parts = array_map('trim', $parts);
                        }

                        error_log("Number of parts: " . count($parts));

                        // Minimum required: question, option A, option B, correct answer
                        if (count($parts) >= 3) {
                            try {
                                $stmt = $pdo->prepare("
                INSERT INTO objective_questions 
                (question_text, option_a, option_b, option_c, option_d, 
                 correct_answer, difficulty_level, marks, subject_id, topic_id, class) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

                                // Validate and clean data - use array_key_exists to avoid undefined index errors
                                $question = isset($parts[0]) ? $parts[0] : '';
                                $option_a = isset($parts[1]) ? $parts[1] : '';
                                $option_b = isset($parts[2]) ? $parts[2] : '';
                                $option_c = isset($parts[3]) ? $parts[3] : '';
                                $option_d = isset($parts[4]) ? $parts[4] : '';
                                $correct_answer = isset($parts[5]) ? strtoupper($parts[5]) : 'A';
                                $difficulty = isset($parts[6]) && in_array($parts[6], ['easy', 'medium', 'hard']) ? $parts[6] : 'medium';
                                $marks = isset($parts[7]) ? intval($parts[7]) : 1;

                                // Validate required fields
                                if (empty($question) || empty($option_a) || empty($option_b)) {
                                    throw new Exception("Missing required fields in line: " . substr($line, 0, 50));
                                }

                                $stmt->execute([
                                    $question,
                                    $option_a,
                                    $option_b,
                                    $option_c,
                                    $option_d,
                                    $correct_answer,
                                    $difficulty,
                                    $marks,
                                    $selected_topic['subject_id'],
                                    $topic_id,
                                    $selected_topic['class']
                                ]);
                                $success_count++;
                            } catch (Exception $e) {
                                $error_count++;
                                $errors[] = "Error on line " . ($line_index + 1) . ": " . substr($line, 0, 100) . " - " . $e->getMessage();
                                error_log("Import error: " . $e->getMessage());
                            }
                        } else {
                            $error_count++;
                            $errors[] = "Line " . ($line_index + 1) . ": Invalid format (needs at least 3 columns): " . substr($line, 0, 100);
                        }
                    }
                }
                // Process subjective questions
                elseif ($import_type === 'subjective') {
                    $lines = preg_split('/\r\n|\r|\n/', $file_content);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;

                        if (strpos($line, '|') !== false) {
                            $parts = array_map('trim', explode('|', $line));
                        } else {
                            $parts = array_map('trim', str_getcsv($line));
                        }

                        if (count($parts) >= 2) {
                            try {
                                $stmt = $pdo->prepare("
                                    INSERT INTO subjective_questions 
                                    (question_text, correct_answer, difficulty_level, marks, subject_id, topic_id, class) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?)
                                ");
                                $stmt->execute([
                                    $parts[0],
                                    $parts[1],
                                    $parts[2] ?? 'medium',
                                    intval($parts[3] ?? 1),
                                    $selected_topic['subject_id'],
                                    $topic_id,
                                    $selected_topic['class']
                                ]);
                                $success_count++;
                            } catch (Exception $e) {
                                $error_count++;
                                $errors[] = "Error: " . $e->getMessage();
                            }
                        }
                    }
                }
                // Process theory questions
                elseif ($import_type === 'theory') {
                    $lines = preg_split('/\r\n|\r|\n/', $file_content);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;

                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO theory_questions 
                                (question_text, marks, subject_id, topic_id, class) 
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $line,
                                intval($parts[1] ?? 5),
                                $selected_topic['subject_id'],
                                $topic_id,
                                $selected_topic['class']
                            ]);
                            $success_count++;
                        } catch (Exception $e) {
                            $error_count++;
                            $errors[] = "Error: " . $e->getMessage();
                        }
                    }
                }

                if ($success_count > 0) {
                    $message = "Bulk import completed successfully! $success_count questions imported.";
                    $message_type = "success";

                    if ($error_count > 0) {
                        $message .= " $error_count questions failed to import.";
                        $_SESSION['import_errors'] = $errors;
                    }
                } else {
                    $message = "No questions were imported. Please check your file format.";
                    $message_type = "error";
                }

                // Log activity
                $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity) VALUES (?, ?, ?)")
                    ->execute([$_SESSION['admin_id'], 'admin', "Bulk imported $success_count $import_type questions for topic ID: $topic_id"]);
            }
        } else {
            $message = "Please select a file to import!";
            $message_type = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Questions - Digital CBT System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- TinyMCE Rich Text Editor -->
    <script src="https://cdn.tiny.cloud/1/jjo6cr24xrrberxg1cezfwb80fq1xhkghq4pyu9eudbhg87j/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>

    <!-- MathJax -->
    <script src="https://polyfill.io/v3/polyfill.min.js?features=es6"></script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

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

        /* Sidebar Styles */
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

        .sidebar-header {
            padding: 0 20px 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
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
            flex: 1;
            overflow-y: auto;
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
            border-radius: 5px;
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
            padding: 20px 30px;
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
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--secondary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        /* Message Alerts */
        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.success {
            background: #d5f4e6;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        /* Topic Info */
        .topic-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
        }

        .topic-info-card h2 {
            margin-bottom: 10px;
            font-size: 1.8rem;
        }

        /* Tabs */
        .tabs-navigation {
            background: white;
            border-radius: 10px;
            margin-bottom: 20px;
            overflow: hidden;
        }

        .tab-buttons {
            display: flex;
            background: #f8f9fa;
            border-bottom: 2px solid #eee;
        }

        .tab-button {
            flex: 1;
            padding: 15px 20px;
            border: none;
            background: none;
            font-size: 1rem;
            color: #666;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .tab-button:hover {
            background: #e9ecef;
        }

        .tab-button.active {
            background: white;
            color: var(--primary-color);
            border-bottom: 3px solid var(--secondary-color);
        }

        .tab-content {
            display: none;
            padding: 30px;
        }

        .tab-content.active {
            display: block;
        }

        /* Form Styles */
        .form-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .form-section h3 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-size: 1.3rem;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        /* Rich Text Editor Container */
        .editor-container {
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .tox-tinymce {
            border: none !important;
        }

        /* Options Grid */
        .options-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .options-grid {
                grid-template-columns: 1fr;
            }
        }

        .option-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .option-label {
            font-weight: bold;
            color: var(--primary-color);
            min-width: 30px;
        }

        .option-input {
            flex: 1;
        }

        /* Correct Answer Selector */
        .correct-answer {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .correct-answer select {
            padding: 10px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            min-width: 100px;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        /* Bulk Import */
        .bulk-import-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .file-upload {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .file-upload:hover {
            border-color: var(--secondary-color);
            background: #f8f9fa;
        }

        .file-upload i {
            font-size: 3rem;
            color: #ccc;
            margin-bottom: 15px;
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-upload label {
            background: var(--secondary-color);
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            display: inline-block;
            margin-top: 10px;
        }

        .file-info {
            margin-top: 10px;
            color: #666;
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            display: none;
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
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .mobile-menu-btn {
                display: block;
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
            <li><a href="manage_topics.php"><i class="fas fa-list"></i> Manage Topics</a></li>
            <li><a href="manage_questions.php" class="active"><i class="fas fa-question-circle"></i> Manage Questions</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>Add Questions</h1>
                <p>Create questions for: <strong><?php echo htmlspecialchars($selected_topic['topic_name']); ?></strong></p>
            </div>
            <div class="header-actions">
                <a href="manage_questions.php?topic_id=<?php echo $topic_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Questions
                </a>
            </div>
        </div>

        <!-- Message -->
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Topic Info -->
        <div class="topic-info-card">
            <h2><?php echo htmlspecialchars($selected_topic['topic_name']); ?></h2>
            <p><i class="fas fa-book"></i> Subject: <?php echo htmlspecialchars($selected_topic['subject_name']); ?></p>
            <p><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($selected_topic['class']); ?></p>
            <?php if ($selected_topic['description']): ?>
                <p><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($selected_topic['description']); ?></p>
            <?php endif; ?>
        </div>

        <!-- Tabs -->
        <div class="tabs-navigation">
            <div class="tab-buttons">
                <button class="tab-button <?php echo $question_type === 'objective' ? 'active' : ''; ?>"
                    onclick="switchTab('objective')">
                    <i class="fas fa-check-circle"></i> Objective
                </button>
                <button class="tab-button <?php echo $question_type === 'subjective' ? 'active' : ''; ?>"
                    onclick="switchTab('subjective')">
                    <i class="fas fa-edit"></i> Subjective
                </button>
                <button class="tab-button <?php echo $question_type === 'theory' ? 'active' : ''; ?>"
                    onclick="switchTab('theory')">
                    <i class="fas fa-file-alt"></i> Theory
                </button>
                <button class="tab-button <?php echo $question_type === 'bulk' ? 'active' : ''; ?>"
                    onclick="switchTab('bulk')">
                    <i class="fas fa-upload"></i> Bulk Import
                </button>
            </div>

            <!-- Objective Tab -->
            <div class="tab-content <?php echo $question_type === 'objective' ? 'active' : ''; ?>" id="objectiveTab">
                <div class="form-section">
                    <form method="POST" action="" id="objectiveForm">
                        <div class="form-group">
                            <label>Question Text *</label>
                            <div class="editor-container">
                                <textarea id="question_text" name="question_text" required>
                                    <?php echo isset($_POST['question_text']) ? htmlspecialchars($_POST['question_text']) : ''; ?>
                                </textarea>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Options *</label>
                            <div class="options-grid">
                                <div class="option-group">
                                    <span class="option-label">A)</span>
                                    <input type="text" class="option-input" name="option_a" required
                                        placeholder="Option A" value="<?php echo $_POST['option_a'] ?? ''; ?>">
                                </div>
                                <div class="option-group">
                                    <span class="option-label">B)</span>
                                    <input type="text" class="option-input" name="option_b" required
                                        placeholder="Option B" value="<?php echo $_POST['option_b'] ?? ''; ?>">
                                </div>
                                <div class="option-group">
                                    <span class="option-label">C)</span>
                                    <input type="text" class="option-input" name="option_c"
                                        placeholder="Option C (Optional)" value="<?php echo $_POST['option_c'] ?? ''; ?>">
                                </div>
                                <div class="option-group">
                                    <span class="option-label">D)</span>
                                    <input type="text" class="option-input" name="option_d"
                                        placeholder="Option D (Optional)" value="<?php echo $_POST['option_d'] ?? ''; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="correct-answer">
                            <label>Correct Answer *</label>
                            <select name="correct_answer" required>
                                <option value="">Select option</option>
                                <option value="A" <?php echo ($_POST['correct_answer'] ?? '') === 'A' ? 'selected' : ''; ?>>A</option>
                                <option value="B" <?php echo ($_POST['correct_answer'] ?? '') === 'B' ? 'selected' : ''; ?>>B</option>
                                <option value="C" <?php echo ($_POST['correct_answer'] ?? '') === 'C' ? 'selected' : ''; ?>>C</option>
                                <option value="D" <?php echo ($_POST['correct_answer'] ?? '') === 'D' ? 'selected' : ''; ?>>D</option>
                            </select>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label>Difficulty Level</label>
                                <select name="difficulty_level">
                                    <option value="easy" <?php echo ($_POST['difficulty_level'] ?? 'medium') === 'easy' ? 'selected' : ''; ?>>Easy</option>
                                    <option value="medium" <?php echo ($_POST['difficulty_level'] ?? 'medium') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="hard" <?php echo ($_POST['difficulty_level'] ?? 'medium') === 'hard' ? 'selected' : ''; ?>>Hard</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Marks</label>
                                <input type="number" name="marks" value="<?php echo $_POST['marks'] ?? 1; ?>" min="1" max="10">
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Clear
                            </button>
                            <button type="submit" name="add_objective_question" class="btn btn-success">
                                <i class="fas fa-plus-circle"></i> Add Question
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Subjective Tab -->
            <div class="tab-content <?php echo $question_type === 'subjective' ? 'active' : ''; ?>" id="subjectiveTab">
                <div class="form-section">
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>Question Text *</label>
                            <div class="editor-container">
                                <textarea id="subjective_question_text" name="subjective_question_text" required>
                                    <?php echo isset($_POST['subjective_question_text']) ? htmlspecialchars($_POST['subjective_question_text']) : ''; ?>
                                </textarea>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Correct Answer *</label>
                            <div class="editor-container">
                                <textarea id="subjective_correct_answer" name="subjective_correct_answer" required>
                                    <?php echo isset($_POST['subjective_correct_answer']) ? htmlspecialchars($_POST['subjective_correct_answer']) : ''; ?>
                                </textarea>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label>Difficulty Level</label>
                                <select name="subjective_difficulty_level">
                                    <option value="easy" <?php echo ($_POST['subjective_difficulty_level'] ?? 'medium') === 'easy' ? 'selected' : ''; ?>>Easy</option>
                                    <option value="medium" <?php echo ($_POST['subjective_difficulty_level'] ?? 'medium') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="hard" <?php echo ($_POST['subjective_difficulty_level'] ?? 'medium') === 'hard' ? 'selected' : ''; ?>>Hard</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Marks</label>
                                <input type="number" name="subjective_marks" value="<?php echo $_POST['subjective_marks'] ?? 1; ?>" min="1" max="20">
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Clear
                            </button>
                            <button type="submit" name="add_subjective_question" class="btn btn-success">
                                <i class="fas fa-plus-circle"></i> Add Question
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Theory Tab -->
            <div class="tab-content <?php echo $question_type === 'theory' ? 'active' : ''; ?>" id="theoryTab">
                <div class="form-section">
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>Question Text *</label>
                            <div class="editor-container">
                                <textarea id="theory_question_text" name="theory_question_text" required>
                                    <?php echo isset($_POST['theory_question_text']) ? htmlspecialchars($_POST['theory_question_text']) : ''; ?>
                                </textarea>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Marks</label>
                            <input type="number" name="theory_marks" value="<?php echo $_POST['theory_marks'] ?? 5; ?>" min="1" max="50">
                        </div>

                        <div class="form-actions">
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Clear
                            </button>
                            <button type="submit" name="add_theory_question" class="btn btn-success">
                                <i class="fas fa-plus-circle"></i> Add Question
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Bulk Import Tab -->
            <div class="tab-content <?php echo $question_type === 'bulk' ? 'active' : ''; ?>" id="bulkTab">
                <div class="bulk-import-section">
                    <h3><i class="fas fa-upload"></i> Bulk Import Questions</h3>
                    <p>Upload a Word document (.docx) or CSV file containing multiple questions.</p>

                    <form method="POST" action="" enctype="multipart/form-data">
                        <div class="form-group">
                            <label>Question Type *</label>
                            <select name="import_type" required>
                                <option value="">Select type</option>
                                <option value="objective">Objective Questions</option>
                                <option value="subjective">Subjective Questions</option>
                                <option value="theory">Theory Questions</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Upload File *</label>
                            <div class="file-upload">
                                <i class="fas fa-file-word"></i>
                                <p>Click to select Word (.docx) or CSV file</p>
                                <input type="file" id="import_file" name="import_file" accept=".docx,.csv" required>
                                <label for="import_file">Choose File</label>
                                <div class="file-info">Supports .docx and .csv files (Max: 10MB)</div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Clear
                            </button>
                            <button type="submit" name="bulk_import" class="btn btn-success">
                                <i class="fas fa-upload"></i> Import Questions
                            </button>
                            <a href="generate_templates.php" class="btn btn-success">
                                <i class="fas fa-upload"></i> Download Templates
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Tab switching
        function switchTab(tabName) {
            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('type', tabName);
            window.history.pushState({}, '', url);

            // Update active tab
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });

            document.querySelector(`[onclick="switchTab('${tabName}')"]`).classList.add('active');
            document.getElementById(tabName + 'Tab').classList.add('active');
        }

        // Initialize TinyMCE editors
        tinymce.init({
            selector: '#question_text',
            height: 300,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'help', 'wordcount', 'mathjax'
            ],
            toolbar: 'undo redo | blocks | ' +
                'bold italic backcolor | alignleft aligncenter ' +
                'alignright alignjustify | bullist numlist outdent indent | ' +
                'removeformat | help | mathjax',
            mathjax: {
                lib: 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js',
                symbols: {
                    start: '\\(',
                    end: '\\)'
                }
            },
            content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:16px }'
        });

        tinymce.init({
            selector: '#subjective_question_text',
            height: 200,
            plugins: ['advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'help', 'wordcount', 'mathjax'
            ],
            toolbar: 'undo redo | blocks | ' +
                'bold italic backcolor | alignleft aligncenter ' +
                'alignright alignjustify | bullist numlist outdent indent | ' +
                'removeformat | help | mathjax',
            mathjax: {
                lib: 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js',
                symbols: {
                    start: '\\(',
                    end: '\\)'
                }
            }
        });

        tinymce.init({
            selector: '#subjective_correct_answer',
            height: 150,
            plugins: ['advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'help', 'wordcount', 'mathjax'
            ],
            toolbar: 'undo redo | blocks | ' +
                'bold italic backcolor | alignleft aligncenter ' +
                'alignright alignjustify | bullist numlist outdent indent | ' +
                'removeformat | help | mathjax',
            mathjax: {
                lib: 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js',
                symbols: {
                    start: '\\(',
                    end: '\\)'
                }
            }
        });

        tinymce.init({
            selector: '#theory_question_text',
            height: 250,
            plugins: ['advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'help', 'wordcount', 'mathjax'
            ],
            toolbar: 'undo redo | blocks | ' +
                'bold italic backcolor | alignleft aligncenter ' +
                'alignright alignjustify | bullist numlist outdent indent | ' +
                'removeformat | help | mathjax',
            mathjax: {
                lib: 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js',
                symbols: {
                    start: '\\(',
                    end: '\\)'
                }
            }
        });

        // File upload preview
        document.getElementById('import_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const fileInfo = document.querySelector('.file-info');
                if (fileInfo) {
                    fileInfo.innerHTML = `
                        <strong>Selected file:</strong> ${file.name}<br>
                        <strong>Size:</strong> ${(file.size / 1024 / 1024).toFixed(2)} MB<br>
                        <strong>Type:</strong> ${file.type}
                    `;
                }
            }
        });

        // Auto-hide messages
        setTimeout(() => {
            const messages = document.querySelectorAll('.message');
            messages.forEach(msg => {
                msg.style.opacity = '0';
                msg.style.transition = 'opacity 0.5s';
                setTimeout(() => msg.remove(), 500);
            });
        }, 5000);

        // Initialize MathJax
        if (window.MathJax) {
            MathJax.typesetPromise();
        }
    </script>
</body>

</html>