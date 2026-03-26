<?php
// staff/add-question.php - Add Questions for Staff with Image/File Upload Support
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
$staff_name = $_SESSION['staff_name'] ?? 'Staff Member';

// Get parameters
$question_type = $_GET['type'] ?? $_POST['question_type'] ?? 'objective';
$topic_id = isset($_GET['topic_id']) ? (int)$_GET['topic_id'] : 0;
$valid_types = ['objective', 'subjective', 'theory'];
if (!in_array($question_type, $valid_types)) {
    $question_type = 'objective';
}

$message = '';
$message_type = '';
$selected_topic = null;

// Create upload directories if they don't exist
$upload_dir = '../uploads/questions/';
$objective_img_dir = $upload_dir . 'objective_images/';
$theory_files_dir = $upload_dir . 'theory_files/';

if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}
if (!file_exists($objective_img_dir)) {
    mkdir($objective_img_dir, 0777, true);
}
if (!file_exists($theory_files_dir)) {
    mkdir($theory_files_dir, 0777, true);
}

// Get staff assigned subjects and topics
try {
    // Get assigned subjects
    $stmt = $pdo->prepare("
        SELECT s.id, s.subject_name, sc.class
        FROM subjects s
        INNER JOIN staff_subjects ss ON s.id = ss.subject_id
        LEFT JOIN subject_classes sc ON s.id = sc.subject_id
        WHERE ss.staff_id = ?
        ORDER BY s.subject_name
    ");
    $stmt->execute([$staff_id]);
    $assigned_subjects = $stmt->fetchAll();

    // If topic ID provided, get topic details
    if ($topic_id) {
        $stmt = $pdo->prepare("
            SELECT t.*, s.subject_name, s.id as subject_id
            FROM topics t
            JOIN subjects s ON t.subject_id = s.id
            WHERE t.id = ? AND t.subject_id IN (SELECT subject_id FROM staff_subjects WHERE staff_id = ?)
        ");
        $stmt->execute([$topic_id, $staff_id]);
        $selected_topic = $stmt->fetch();
    }
} catch (Exception $e) {
    $message = "Error loading data: " . $e->getMessage();
    $message_type = "error";
}

// Handle file upload function
function uploadFile($file, $target_dir, $allowed_types, $max_size = 5242880)
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error'];
    }

    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $file_size = $file['size'];

    // Check file size
    if ($file_size > $max_size) {
        return ['success' => false, 'message' => 'File size exceeds limit (5MB)'];
    }

    // Check file type
    if (!in_array($file_extension, $allowed_types)) {
        return ['success' => false, 'message' => 'File type not allowed. Allowed: ' . implode(', ', $allowed_types)];
    }

    // Generate unique filename
    $unique_filename = time() . '_' . uniqid() . '.' . $file_extension;
    $target_path = $target_dir . $unique_filename;

    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return ['success' => true, 'filename' => $unique_filename, 'path' => $target_path];
    } else {
        return ['success' => false, 'message' => 'Failed to save file'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject_id = $_POST['subject_id'] ?? 0;
    $topic_id = $_POST['topic_id'] ?? 0;
    $class = $_POST['class'] ?? '';

    // Handle Objective Question with Image Upload
    if ($question_type === 'objective' && isset($_POST['add_objective'])) {
        $question_text = trim($_POST['question_text'] ?? '');
        $option_a = trim($_POST['option_a'] ?? '');
        $option_b = trim($_POST['option_b'] ?? '');
        $option_c = trim($_POST['option_c'] ?? '');
        $option_d = trim($_POST['option_d'] ?? '');
        $correct_answer = strtoupper($_POST['correct_answer'] ?? '');
        $difficulty = $_POST['difficulty_level'] ?? 'medium';
        $marks = (int)($_POST['marks'] ?? 1);
        $question_image = '';

        // Handle image upload for objective question
        if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
            $upload_result = uploadFile(
                $_FILES['question_image'],
                $objective_img_dir,
                ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                5242880 // 5MB
            );

            if ($upload_result['success']) {
                $question_image = $upload_result['filename'];
            } else {
                $message = "Image upload failed: " . $upload_result['message'];
                $message_type = "error";
            }
        }

        if (empty($question_text) || empty($option_a) || empty($option_b) || empty($correct_answer)) {
            $message = "Please fill in all required fields (Question, Options A & B, Correct Answer).";
            $message_type = "error";
        } elseif (!in_array($correct_answer, ['A', 'B', 'C', 'D'])) {
            $message = "Correct answer must be A, B, C, or D.";
            $message_type = "error";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO objective_questions 
                    (question_text, option_a, option_b, option_c, option_d, correct_answer, 
                     difficulty_level, marks, subject_id, topic_id, class, question_image) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $question_text,
                    $option_a,
                    $option_b,
                    $option_c,
                    $option_d,
                    $correct_answer,
                    $difficulty,
                    $marks,
                    $subject_id,
                    $topic_id ?: null,
                    $class,
                    $question_image
                ]);

                $message = "Objective question added successfully!";
                $message_type = "success";

                // Log activity
                logActivity($staff_id, 'staff', "Added objective question for subject ID: $subject_id");

                // Clear form
                $_POST = [];
            } catch (Exception $e) {
                $message = "Error adding question: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }

    // Handle Subjective Question
    elseif ($question_type === 'subjective' && isset($_POST['add_subjective'])) {
        $question_text = trim($_POST['question_text'] ?? '');
        $correct_answer = trim($_POST['correct_answer'] ?? '');
        $difficulty = $_POST['difficulty_level'] ?? 'medium';
        $marks = (int)($_POST['marks'] ?? 1);

        if (empty($question_text) || empty($correct_answer)) {
            $message = "Please fill in both question text and correct answer.";
            $message_type = "error";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO subjective_questions 
                    (question_text, correct_answer, difficulty_level, marks, subject_id, topic_id, class) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $question_text,
                    $correct_answer,
                    $difficulty,
                    $marks,
                    $subject_id,
                    $topic_id ?: null,
                    $class
                ]);

                $message = "Subjective question added successfully!";
                $message_type = "success";

                logActivity($staff_id, 'staff', "Added subjective question for subject ID: $subject_id");

                $_POST = [];
            } catch (Exception $e) {
                $message = "Error adding question: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }

    // Handle Theory Question with File Upload
    elseif ($question_type === 'theory' && isset($_POST['add_theory'])) {
        $question_text = trim($_POST['question_text'] ?? '');
        $marks = (int)($_POST['marks'] ?? 5);
        $question_file = '';

        // Handle file upload for theory question
        if (isset($_FILES['theory_file']) && $_FILES['theory_file']['error'] === UPLOAD_ERR_OK) {
            $upload_result = uploadFile(
                $_FILES['theory_file'],
                $theory_files_dir,
                ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt'],
                10485760 // 10MB for theory files
            );

            if ($upload_result['success']) {
                $question_file = $upload_result['filename'];
            } else {
                $message = "File upload failed: " . $upload_result['message'];
                $message_type = "error";
            }
        }

        if (empty($question_text) && empty($question_file)) {
            $message = "Please enter question text or upload a file.";
            $message_type = "error";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO theory_questions 
                    (question_text, question_file, marks, subject_id, topic_id, class) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $question_text ?: null,
                    $question_file ?: null,
                    $marks,
                    $subject_id,
                    $topic_id ?: null,
                    $class
                ]);

                $message = "Theory question added successfully!";
                $message_type = "success";

                logActivity($staff_id, 'staff', "Added theory question for subject ID: $subject_id");

                $_POST = [];
            } catch (Exception $e) {
                $message = "Error adding question: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// Get topics for AJAX
if (isset($_GET['get_topics']) && isset($_GET['subject_id'])) {
    $subject_id = intval($_GET['subject_id']);
    $stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE subject_id = ? ORDER BY topic_name");
    $stmt->execute([$subject_id]);
    $topics = $stmt->fetchAll();

    header('Content-Type: application/json');
    echo json_encode($topics);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Question - Staff Portal</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- TinyMCE -->
    <script src="https://cdn.tiny.cloud/1/jjo6cr24xrrberxg1cezfwb80fq1xhkghq4pyu9eudbhg87j/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>

    <style>
        :root {
            --primary-color: #2c5282;
            --secondary-color: #4299e1;
            --success-color: #38a169;
            --danger-color: #e53e3e;
            --light-color: #edf2f7;
            --dark-color: #2d3748;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
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
            box-shadow: 3px 0 20px rgba(0, 0, 0, 0.15);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            margin-bottom: 25px;
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--secondary-color), #3182ce);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .logo-text h3 {
            font-size: 1.2rem;
            margin-bottom: 3px;
        }

        .logo-text p {
            font-size: 0.8rem;
            opacity: 0.9;
        }

        .staff-info {
            text-align: center;
            padding: 20px 15px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 15px;
            margin: 0 15px 25px;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
        }

        .nav-links li {
            margin-bottom: 8px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 18px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 25px;
            min-height: 100vh;
        }

        .top-header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title h1 {
            color: var(--dark-color);
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), #3182ce);
            color: white;
        }

        .btn-secondary {
            background: #718096;
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #2f855a);
            color: white;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #f0fff4;
            color: #22543d;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #fff5f5;
            color: #742a2a;
            border-left: 4px solid var(--danger-color);
        }

        .form-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark-color);
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .options-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .option-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .option-label {
            font-weight: bold;
            min-width: 30px;
        }

        .correct-answer {
            margin-bottom: 20px;
        }

        .correct-answer select {
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            margin-left: 10px;
        }

        .file-upload-area {
            border: 2px dashed #cbd5e0;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            background: #f7fafc;
        }

        .file-upload-area:hover {
            border-color: var(--secondary-color);
            background: #edf2f7;
        }

        .file-upload-area i {
            font-size: 2rem;
            color: #a0aec0;
            margin-bottom: 10px;
        }

        .file-upload-area input {
            display: none;
        }

        .file-info {
            margin-top: 10px;
            font-size: 0.85rem;
            color: #718096;
        }

        .image-preview {
            margin-top: 15px;
            text-align: center;
        }

        .image-preview img {
            max-width: 200px;
            max-height: 200px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            padding: 5px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
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
            }

            .options-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        .tox-tinymce {
            border-radius: 10px !important;
        }

        .info-text {
            font-size: 0.85rem;
            color: #718096;
            margin-top: 5px;
        }

        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <div class="logo-text">
                <h3><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?></h3>
                <p>Staff Portal</p>
            </div>
        </div>

        <div class="staff-info">
            <h4><?php echo htmlspecialchars($staff_name); ?></h4>
            <p>ID: <?php echo htmlspecialchars($staff_id); ?></p>
        </div>

        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> My Students</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="questions.php" class="active"><i class="fas fa-question-circle"></i> Question Bank</a></li>
            <li><a href="profile.php"><i class="fas fa-user-cog"></i> My Profile</a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Add <?php echo ucfirst($question_type); ?> Question</h1>
                <p>Create new questions for your question bank</p>
            </div>
            <div>
                <a href="questions.php?type=<?php echo $question_type; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Questions
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="form-container">
            <form method="POST" id="questionForm" enctype="multipart/form-data">
                <input type="hidden" name="question_type" value="<?php echo $question_type; ?>">

                <div class="form-group">
                    <label>Subject *</label>
                    <select name="subject_id" id="subject_id" class="form-select" required>
                        <option value="">Select Subject</option>
                        <?php foreach ($assigned_subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>"
                                <?php echo (isset($selected_topic) && $selected_topic['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name'] . ($subject['class'] ? ' (' . $subject['class'] . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Topic (Optional)</label>
                    <select name="topic_id" id="topic_id" class="form-select">
                        <option value="">Select Topic (Optional)</option>
                        <?php if ($selected_topic): ?>
                            <option value="<?php echo $selected_topic['id']; ?>" selected>
                                <?php echo htmlspecialchars($selected_topic['topic_name']); ?>
                            </option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Class (Optional)</label>
                    <input type="text" name="class" class="form-control" placeholder="e.g., SS1, SS2, JSS3" value="<?php echo htmlspecialchars($_POST['class'] ?? ''); ?>">
                </div>

                <?php if ($question_type === 'objective'): ?>
                    <div class="form-group">
                        <label>Question Text *</label>
                        <textarea id="question_text" name="question_text" class="form-control" rows="5" required><?php echo htmlspecialchars($_POST['question_text'] ?? ''); ?></textarea>
                    </div>

                    <!-- Image Upload for Objective Question -->
                    <div class="form-group">
                        <label>Question Image (Optional)</label>
                        <div class="file-upload-area" onclick="document.getElementById('question_image').click()">
                            <i class="fas fa-image"></i>
                            <p>Click to upload an image for the question</p>
                            <p class="info-text">Supports: JPG, PNG, GIF, WebP (Max: 5MB)</p>
                            <input type="file" id="question_image" name="question_image" accept="image/jpeg,image/png,image/gif,image/webp">
                        </div>
                        <div class="image-preview" id="imagePreview"></div>
                    </div>

                    <div class="options-grid">
                        <div class="option-group">
                            <span class="option-label">A)</span>
                            <input type="text" name="option_a" class="form-control" required placeholder="Option A" value="<?php echo htmlspecialchars($_POST['option_a'] ?? ''); ?>">
                        </div>
                        <div class="option-group">
                            <span class="option-label">B)</span>
                            <input type="text" name="option_b" class="form-control" required placeholder="Option B" value="<?php echo htmlspecialchars($_POST['option_b'] ?? ''); ?>">
                        </div>
                        <div class="option-group">
                            <span class="option-label">C)</span>
                            <input type="text" name="option_c" class="form-control" placeholder="Option C (Optional)" value="<?php echo htmlspecialchars($_POST['option_c'] ?? ''); ?>">
                        </div>
                        <div class="option-group">
                            <span class="option-label">D)</span>
                            <input type="text" name="option_d" class="form-control" placeholder="Option D (Optional)" value="<?php echo htmlspecialchars($_POST['option_d'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="correct-answer">
                        <label>Correct Answer *</label>
                        <select name="correct_answer" required>
                            <option value="">Select Correct Answer</option>
                            <option value="A" <?php echo ($_POST['correct_answer'] ?? '') === 'A' ? 'selected' : ''; ?>>A</option>
                            <option value="B" <?php echo ($_POST['correct_answer'] ?? '') === 'B' ? 'selected' : ''; ?>>B</option>
                            <option value="C" <?php echo ($_POST['correct_answer'] ?? '') === 'C' ? 'selected' : ''; ?>>C</option>
                            <option value="D" <?php echo ($_POST['correct_answer'] ?? '') === 'D' ? 'selected' : ''; ?>>D</option>
                        </select>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Difficulty Level</label>
                            <select name="difficulty_level" class="form-select">
                                <option value="easy" <?php echo ($_POST['difficulty_level'] ?? 'medium') === 'easy' ? 'selected' : ''; ?>>Easy</option>
                                <option value="medium" <?php echo ($_POST['difficulty_level'] ?? 'medium') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="hard" <?php echo ($_POST['difficulty_level'] ?? 'medium') === 'hard' ? 'selected' : ''; ?>>Hard</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Marks</label>
                            <input type="number" name="marks" class="form-control" value="<?php echo $_POST['marks'] ?? 1; ?>" min="1" max="10">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear
                        </button>
                        <button type="submit" name="add_objective" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Question
                        </button>
                    </div>

                <?php elseif ($question_type === 'subjective'): ?>
                    <div class="form-group">
                        <label>Question Text *</label>
                        <textarea id="question_text" name="question_text" class="form-control" rows="5" required><?php echo htmlspecialchars($_POST['question_text'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Correct Answer *</label>
                        <textarea id="correct_answer" name="correct_answer" class="form-control" rows="3" required><?php echo htmlspecialchars($_POST['correct_answer'] ?? ''); ?></textarea>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Difficulty Level</label>
                            <select name="difficulty_level" class="form-select">
                                <option value="easy" <?php echo ($_POST['difficulty_level'] ?? 'medium') === 'easy' ? 'selected' : ''; ?>>Easy</option>
                                <option value="medium" <?php echo ($_POST['difficulty_level'] ?? 'medium') === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="hard" <?php echo ($_POST['difficulty_level'] ?? 'medium') === 'hard' ? 'selected' : ''; ?>>Hard</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Marks</label>
                            <input type="number" name="marks" class="form-control" value="<?php echo $_POST['marks'] ?? 1; ?>" min="1" max="20">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear
                        </button>
                        <button type="submit" name="add_subjective" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Question
                        </button>
                    </div>

                <?php else: ?>
                    <div class="form-group">
                        <label>Question Text (Optional if file uploaded)</label>
                        <textarea id="question_text" name="question_text" class="form-control" rows="6"><?php echo htmlspecialchars($_POST['question_text'] ?? ''); ?></textarea>
                        <div class="info-text">You can either type the question or upload a file, or both.</div>
                    </div>

                    <!-- File Upload for Theory Question -->
                    <div class="form-group">
                        <label>Upload File (Optional)</label>
                        <div class="file-upload-area" onclick="document.getElementById('theory_file').click()">
                            <i class="fas fa-file-pdf"></i>
                            <p>Click to upload PDF, DOC, DOCX, or Image file</p>
                            <p class="info-text">Supports: PDF, DOC, DOCX, JPG, PNG, GIF, WebP, TXT (Max: 10MB)</p>
                            <input type="file" id="theory_file" name="theory_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.txt">
                        </div>
                        <div class="file-info" id="fileInfo"></div>
                    </div>

                    <div class="form-group">
                        <label>Marks</label>
                        <input type="number" name="marks" class="form-control" value="<?php echo $_POST['marks'] ?? 5; ?>" min="1" max="50">
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Clear
                        </button>
                        <button type="submit" name="add_theory" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Question
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <script>
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        mobileMenuBtn.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });

        // Load topics when subject changes
        const subjectSelect = document.getElementById('subject_id');
        const topicSelect = document.getElementById('topic_id');

        if (subjectSelect) {
            subjectSelect.addEventListener('change', function() {
                const subjectId = this.value;
                if (subjectId) {
                    fetch(`add-question.php?get_topics=1&subject_id=${subjectId}`)
                        .then(response => response.json())
                        .then(data => {
                            topicSelect.innerHTML = '<option value="">Select Topic (Optional)</option>';
                            data.forEach(topic => {
                                topicSelect.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
                            });
                        })
                        .catch(error => console.error('Error loading topics:', error));
                } else {
                    topicSelect.innerHTML = '<option value="">Select Topic (Optional)</option>';
                }
            });
        }

        // Image preview for objective questions
        const imageInput = document.getElementById('question_image');
        if (imageInput) {
            imageInput.addEventListener('change', function(e) {
                const preview = document.getElementById('imagePreview');
                const file = e.target.files[0];

                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                    }
                    reader.readAsDataURL(file);
                } else {
                    preview.innerHTML = '';
                }
            });
        }

        // File info for theory questions
        const theoryFileInput = document.getElementById('theory_file');
        if (theoryFileInput) {
            theoryFileInput.addEventListener('change', function(e) {
                const fileInfo = document.getElementById('fileInfo');
                const file = e.target.files[0];

                if (file) {
                    const fileSize = (file.size / 1024 / 1024).toFixed(2);
                    fileInfo.innerHTML = `
                        <strong>Selected file:</strong> ${file.name}<br>
                        <strong>Size:</strong> ${fileSize} MB<br>
                        <strong>Type:</strong> ${file.type || 'Unknown'}
                    `;
                } else {
                    fileInfo.innerHTML = '';
                }
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Initialize TinyMCE for textareas
        <?php if ($question_type !== 'theory' || ($question_type === 'theory' && $_POST['question_text'] ?? false)): ?>
            tinymce.init({
                selector: '#question_text, #correct_answer',
                height: 200,
                plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table help wordcount',
                toolbar: 'undo redo | blocks | bold italic | alignleft aligncenter alignright | bullist numlist | removeformat | help',
                content_style: 'body { font-family: "Poppins", Helvetica, Arial, sans-serif; font-size: 14px; }'
            });
        <?php endif; ?>

        // Close sidebar on outside click (mobile)
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Auto-hide messages
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>

</html>