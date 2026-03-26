<?php
// admin/edit_question.php - Edit Questions
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Get parameters from URL
$question_id = $_GET['id'] ?? 0;
$question_type = $_GET['type'] ?? '';
$topic_id = $_GET['topic_id'] ?? 0;
$subject_id = $_GET['subject_id'] ?? 0;

$message = '';
$message_type = '';
$question_data = null;
$topics = [];

// Validate required parameters
if (!$question_id || !$question_type || !$topic_id) {
    header("Location: manage-topics.php?error=missing_parameters");
    exit();
}

// Get topic and subject information
$topic_stmt = $pdo->prepare("
    SELECT t.*, s.subject_name, s.id as subject_id 
    FROM topics t 
    JOIN subjects s ON t.subject_id = s.id 
    WHERE t.id = ?
");
$topic_stmt->execute([$topic_id]);
$topic_info = $topic_stmt->fetch();

if (!$topic_info) {
    header("Location: manage-topics.php?error=topic_not_found");
    exit();
}

// Get all topics for this subject (for reassigning questions)
$topics_stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE subject_id = ? ORDER BY topic_name");
$topics_stmt->execute([$topic_info['subject_id']]);
$topics = $topics_stmt->fetchAll();

// Fetch question data based on type
if ($question_type == 'objective') {
    $stmt = $pdo->prepare("SELECT * FROM objective_questions WHERE id = ?");
} elseif ($question_type == 'subjective') {
    $stmt = $pdo->prepare("SELECT * FROM subjective_questions WHERE id = ?");
} elseif ($question_type == 'theory') {
    $stmt = $pdo->prepare("SELECT * FROM theory_questions WHERE id = ?");
} else {
    header("Location: manage-topics.php?error=invalid_question_type");
    exit();
}

$stmt->execute([$question_id]);
$question_data = $stmt->fetch();

if (!$question_data) {
    header("Location: manage-topics.php?view_topic=$topic_id&subject_id={$topic_info['subject_id']}&error=question_not_found");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_question'])) {
        try {
            $pdo->beginTransaction();

            if ($question_type == 'objective') {
                // Update objective question
                $question_text = trim($_POST['question_text']);
                $option_a = trim($_POST['option_a']);
                $option_b = trim($_POST['option_b']);
                $option_c = trim($_POST['option_c']);
                $option_d = trim($_POST['option_d']);
                $correct_answer = $_POST['correct_answer'];
                $marks = $_POST['marks'] ?? 1;
                $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
                $new_topic_id = $_POST['topic_id'] ?? $topic_id;
                $class = $_POST['class'] ?? '';
                $passage_id = !empty($_POST['passage_id']) ? $_POST['passage_id'] : null;

                // Handle image upload
                $question_image = $question_data['question_image']; // Keep existing image
                if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] == 0) {
                    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                    $filename = $_FILES['question_image']['name'];
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    
                    if (in_array($ext, $allowed)) {
                        $upload_dir = '../uploads/questions/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $new_filename = 'question_' . time() . '_' . $question_id . '.' . $ext;
                        $upload_path = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($_FILES['question_image']['tmp_name'], $upload_path)) {
                            // Delete old image if exists
                            if ($question_image && file_exists('../' . $question_image)) {
                                unlink('../' . $question_image);
                            }
                            $question_image = 'uploads/questions/' . $new_filename;
                        }
                    }
                }

                $update_stmt = $pdo->prepare("
                    UPDATE objective_questions SET
                        question_text = ?,
                        option_a = ?,
                        option_b = ?,
                        option_c = ?,
                        option_d = ?,
                        correct_answer = ?,
                        marks = ?,
                        difficulty_level = ?,
                        topic_id = ?,
                        class = ?,
                        question_image = ?,
                        passage_id = ?
                    WHERE id = ?
                ");
                
                $update_stmt->execute([
                    $question_text, $option_a, $option_b, $option_c, $option_d,
                    $correct_answer, $marks, $difficulty_level, $new_topic_id,
                    $class, $question_image, $passage_id, $question_id
                ]);

            } elseif ($question_type == 'subjective') {
                // Update subjective question
                $question_text = trim($_POST['question_text']);
                $answer_guide = trim($_POST['answer_guide'] ?? '');
                $marks = $_POST['marks'] ?? 1;
                $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
                $new_topic_id = $_POST['topic_id'] ?? $topic_id;
                $class = $_POST['class'] ?? '';

                $update_stmt = $pdo->prepare("
                    UPDATE subjective_questions SET
                        question_text = ?,
                        correct_answer = ?,
                        marks = ?,
                        difficulty_level = ?,
                        topic_id = ?,
                        class = ?
                    WHERE id = ?
                ");
                
                $update_stmt->execute([
                    $question_text, $answer_guide, $marks, 
                    $difficulty_level, $new_topic_id, $class, $question_id
                ]);

            } elseif ($question_type == 'theory') {
                // Update theory question
                $question_text = trim($_POST['question_text']);
                $marks = $_POST['marks'] ?? 5;
                $difficulty_level = $_POST['difficulty_level'] ?? 'medium';
                $new_topic_id = $_POST['topic_id'] ?? $topic_id;
                $class = $_POST['class'] ?? '';

                // Handle file upload for theory questions
                $question_file = $question_data['question_file']; // Keep existing file
                if (isset($_FILES['question_file']) && $_FILES['question_file']['error'] == 0) {
                    $allowed = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
                    $filename = $_FILES['question_file']['name'];
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    
                    if (in_array($ext, $allowed)) {
                        $upload_dir = '../uploads/theory/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $new_filename = 'theory_' . time() . '_' . $question_id . '.' . $ext;
                        $upload_path = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($_FILES['question_file']['tmp_name'], $upload_path)) {
                            // Delete old file if exists
                            if ($question_file && file_exists('../' . $question_file)) {
                                unlink('../' . $question_file);
                            }
                            $question_file = 'uploads/theory/' . $new_filename;
                        }
                    }
                }

                $update_stmt = $pdo->prepare("
                    UPDATE theory_questions SET
                        question_text = ?,
                        question_file = ?,
                        marks = ?,
                        topic_id = ?,
                        class = ?
                    WHERE id = ?
                ");
                
                $update_stmt->execute([
                    $question_text, $question_file, $marks, 
                    $new_topic_id, $class, $question_id
                ]);
            }

            // Log activity
            $log_activity = $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity) VALUES (?, ?, ?)");
            $log_activity->execute([
                $_SESSION['admin_id'], 
                'admin', 
                "Updated {$question_type} question ID: {$question_id}"
            ]);

            $pdo->commit();
            
            $message = "Question updated successfully!";
            $message_type = "success";

            // Refresh question data
            $stmt->execute([$question_id]);
            $question_data = $stmt->fetch();

        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Error updating question: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Get passages for objective questions (if table exists)
$passages = [];
if ($question_type == 'objective') {
    try {
        $passages_stmt = $pdo->prepare("
            SELECT id, title, passage_text 
            FROM passages 
            WHERE subject_id = ? 
            ORDER BY title
        ");
        $passages_stmt->execute([$topic_info['subject_id']]);
        $passages = $passages_stmt->fetchAll();
    } catch (Exception $e) {
        // Passages table might not exist
        $passages = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit <?php echo ucfirst($question_type); ?> Question - Digital CBT System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- MathJax for rendering mathematical equations -->
    <script src="https://polyfill.io/v3/polyfill.min.js?features=es6"></script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

    <!-- TinyMCE for rich text editing -->
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>

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
            --header-height: 70px;
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

        /* Sidebar Styles (copy from manage-topics.php) */
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
            overflow-y: auto;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding: 0 20px;
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
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
        }

        /* Breadcrumb */
        .breadcrumb {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .breadcrumb a {
            color: var(--secondary-color);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Content Card */
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
        }

        .card-header h2 {
            color: var(--primary-color);
            font-size: 1.5rem;
        }

        /* Message Alerts */
        .message {
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

        /* Form Styles */
        .form-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 12px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .options-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 10px;
        }

        .option-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #ddd;
        }

        .option-item label {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .option-item input[type="radio"] {
            width: auto;
            margin-right: 5px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .btn {
            padding: 12px 25px;
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

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
            color: white;
        }

        .btn-sm {
            padding: 8px 15px;
            font-size: 0.85rem;
        }

        /* Preview Section */
        .preview-section {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .preview-section h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        .question-preview {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid var(--secondary-color);
        }

        .math-content {
            font-family: 'Times New Roman', serif;
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed #ddd;
            padding: 20px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload:hover {
            border-color: var(--secondary-color);
            background: #f8f9fa;
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-upload label {
            cursor: pointer;
            display: block;
        }

        .file-upload i {
            font-size: 2rem;
            color: #999;
            margin-bottom: 10px;
        }

        .current-file {
            margin-top: 10px;
            padding: 10px;
            background: #e8f5e9;
            border-radius: 5px;
        }

        /* Badge */
        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .badge-info {
            background: #e3f2fd;
            color: #1976d2;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .options-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
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
            <h4><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Administrator'); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $_SESSION['admin_role'] ?? 'admin')); ?></p>
        </div>

        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-topics.php"><i class="fas fa-list"></i> Manage Topics</a></li>
            <li><a href="manage_questions.php" class="active"><i class="fas fa-file-alt"></i> Manage Questions</a></li>
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
                <h1>Edit <?php echo ucfirst($question_type); ?> Question</h1>
                <p><?php echo htmlspecialchars($topic_info['topic_name']); ?> - <?php echo htmlspecialchars($topic_info['subject_name']); ?></p>
            </div>
            <button class="logout-btn" onclick="window.location.href='../logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </div>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="manage-subjects.php">Subjects</a> &rsaquo;
            <a href="manage-topics.php?subject_id=<?php echo $topic_info['subject_id']; ?>">
                <?php echo htmlspecialchars($topic_info['subject_name']); ?>
            </a> &rsaquo;
            <a href="manage-topics.php?view_topic=<?php echo $topic_id; ?>&subject_id=<?php echo $topic_info['subject_id']; ?>">
                <?php echo htmlspecialchars($topic_info['topic_name']); ?>
            </a> &rsaquo;
            <span>Edit <?php echo ucfirst($question_type); ?> Question</span>
        </div>

        <!-- Message Alerts -->
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Edit Form -->
        <div class="content-card">
            <div class="card-header">
                <h2><i class="fas fa-edit"></i> Edit Question Details</h2>
                <a href="manage-topics.php?view_topic=<?php echo $topic_id; ?>&subject_id=<?php echo $topic_info['subject_id']; ?>" 
                   class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Back to Questions
                </a>
            </div>

            <div class="form-section">
                <form method="POST" action="" enctype="multipart/form-data">
                    <?php if ($question_type == 'objective'): ?>
                        <!-- Objective Question Form -->
                        <div class="form-grid">
                            <div class="form-group" style="grid-column: span 2;">
                                <label for="question_text">Question Text *</label>
                                <textarea id="question_text" name="question_text" class="tinymce" required><?php echo htmlspecialchars($question_data['question_text']); ?></textarea>
                            </div>
                        </div>

                        <div class="options-grid">
                            <div class="option-item">
                                <label>
                                    <input type="radio" name="correct_answer" value="A" <?php echo $question_data['correct_answer'] == 'A' ? 'checked' : ''; ?> required>
                                    Option A *
                                </label>
                                <textarea name="option_a" class="tinymce-small" required><?php echo htmlspecialchars($question_data['option_a']); ?></textarea>
                            </div>
                            <div class="option-item">
                                <label>
                                    <input type="radio" name="correct_answer" value="B" <?php echo $question_data['correct_answer'] == 'B' ? 'checked' : ''; ?> required>
                                    Option B *
                                </label>
                                <textarea name="option_b" class="tinymce-small" required><?php echo htmlspecialchars($question_data['option_b']); ?></textarea>
                            </div>
                            <div class="option-item">
                                <label>
                                    <input type="radio" name="correct_answer" value="C" <?php echo $question_data['correct_answer'] == 'C' ? 'checked' : ''; ?> required>
                                    Option C *
                                </label>
                                <textarea name="option_c" class="tinymce-small" required><?php echo htmlspecialchars($question_data['option_c']); ?></textarea>
                            </div>
                            <div class="option-item">
                                <label>
                                    <input type="radio" name="correct_answer" value="D" <?php echo $question_data['correct_answer'] == 'D' ? 'checked' : ''; ?> required>
                                    Option D *
                                </label>
                                <textarea name="option_d" class="tinymce-small" required><?php echo htmlspecialchars($question_data['option_d']); ?></textarea>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="topic_id">Topic *</label>
                                <select id="topic_id" name="topic_id" required>
                                    <?php foreach ($topics as $topic): ?>
                                        <option value="<?php echo $topic['id']; ?>" 
                                            <?php echo ($topic['id'] == $question_data['topic_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($topic['topic_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="marks">Marks *</label>
                                <input type="number" id="marks" name="marks" min="1" value="<?php echo $question_data['marks'] ?? 1; ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="difficulty_level">Difficulty Level</label>
                                <select id="difficulty_level" name="difficulty_level">
                                    <option value="easy" <?php echo ($question_data['difficulty_level'] ?? '') == 'easy' ? 'selected' : ''; ?>>Easy</option>
                                    <option value="medium" <?php echo ($question_data['difficulty_level'] ?? '') == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="hard" <?php echo ($question_data['difficulty_level'] ?? '') == 'hard' ? 'selected' : ''; ?>>Hard</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="class">Class</label>
                                <input type="text" id="class" name="class" value="<?php echo htmlspecialchars($question_data['class'] ?? ''); ?>" placeholder="e.g., JSS1, SS2">
                            </div>

                            <?php if (!empty($passages)): ?>
                            <div class="form-group">
                                <label for="passage_id">Associated Passage</label>
                                <select id="passage_id" name="passage_id">
                                    <option value="">No Passage</option>
                                    <?php foreach ($passages as $passage): ?>
                                        <option value="<?php echo $passage['id']; ?>" 
                                            <?php echo ($question_data['passage_id'] ?? '') == $passage['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($passage['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="question_image">Question Image (Optional)</label>
                                <div class="file-upload">
                                    <input type="file" id="question_image" name="question_image" accept="image/*">
                                    <label for="question_image">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p>Click to upload or drag and drop</p>
                                        <p class="small">(JPG, PNG, GIF)</p>
                                    </label>
                                </div>
                                <?php if (!empty($question_data['question_image'])): ?>
                                    <div class="current-file">
                                        <i class="fas fa-image"></i> Current image: 
                                        <a href="../<?php echo $question_data['question_image']; ?>" target="_blank">View</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php elseif ($question_type == 'subjective'): ?>
                        <!-- Subjective Question Form -->
                        <div class="form-group">
                            <label for="question_text">Question Text *</label>
                            <textarea id="question_text" name="question_text" class="tinymce" required><?php echo htmlspecialchars($question_data['question_text']); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="answer_guide">Answer Guide / Correct Answer *</label>
                            <textarea id="answer_guide" name="answer_guide" class="tinymce" required><?php echo htmlspecialchars($question_data['correct_answer'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="topic_id">Topic *</label>
                                <select id="topic_id" name="topic_id" required>
                                    <?php foreach ($topics as $topic): ?>
                                        <option value="<?php echo $topic['id']; ?>" 
                                            <?php echo ($topic['id'] == $question_data['topic_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($topic['topic_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="marks">Marks *</label>
                                <input type="number" id="marks" name="marks" min="1" value="<?php echo $question_data['marks'] ?? 1; ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="difficulty_level">Difficulty Level</label>
                                <select id="difficulty_level" name="difficulty_level">
                                    <option value="easy" <?php echo ($question_data['difficulty_level'] ?? '') == 'easy' ? 'selected' : ''; ?>>Easy</option>
                                    <option value="medium" <?php echo ($question_data['difficulty_level'] ?? '') == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="hard" <?php echo ($question_data['difficulty_level'] ?? '') == 'hard' ? 'selected' : ''; ?>>Hard</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="class">Class</label>
                                <input type="text" id="class" name="class" value="<?php echo htmlspecialchars($question_data['class'] ?? ''); ?>" placeholder="e.g., JSS1, SS2">
                            </div>
                        </div>

                    <?php elseif ($question_type == 'theory'): ?>
                        <!-- Theory Question Form -->
                        <div class="form-group">
                            <label for="question_text">Question Text *</label>
                            <textarea id="question_text" name="question_text" class="tinymce" required><?php echo htmlspecialchars($question_data['question_text'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="topic_id">Topic *</label>
                                <select id="topic_id" name="topic_id" required>
                                    <?php foreach ($topics as $topic): ?>
                                        <option value="<?php echo $topic['id']; ?>" 
                                            <?php echo ($topic['id'] == $question_data['topic_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($topic['topic_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="marks">Marks *</label>
                                <input type="number" id="marks" name="marks" min="1" value="<?php echo $question_data['marks'] ?? 5; ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="class">Class</label>
                                <input type="text" id="class" name="class" value="<?php echo htmlspecialchars($question_data['class'] ?? ''); ?>" placeholder="e.g., JSS1, SS2">
                            </div>

                            <div class="form-group">
                                <label for="question_file">Question File (Optional)</label>
                                <div class="file-upload">
                                    <input type="file" id="question_file" name="question_file" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png">
                                    <label for="question_file">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p>Click to upload or drag and drop</p>
                                        <p class="small">(PDF, DOC, DOCX, TXT, JPG, PNG)</p>
                                    </label>
                                </div>
                                <?php if (!empty($question_data['question_file'])): ?>
                                    <div class="current-file">
                                        <i class="fas fa-file"></i> Current file: 
                                        <a href="../<?php echo $question_data['question_file']; ?>" target="_blank">View</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <a href="manage-topics.php?view_topic=<?php echo $topic_id; ?>&subject_id=<?php echo $topic_info['subject_id']; ?>" 
                           class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" name="update_question" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Question
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Preview Section -->
        <div class="preview-section">
            <h3><i class="fas fa-eye"></i> Current Question Preview</h3>
            <div class="question-preview">
                <?php if ($question_type == 'objective'): ?>
                    <div class="math-content">
                        <strong>Q:</strong> <?php echo $question_data['question_text']; ?>
                    </div>
                    <div style="margin-top: 15px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
                        <div class="<?php echo $question_data['correct_answer'] == 'A' ? 'badge-success' : ''; ?>">
                            A. <?php echo $question_data['option_a']; ?>
                        </div>
                        <div class="<?php echo $question_data['correct_answer'] == 'B' ? 'badge-success' : ''; ?>">
                            B. <?php echo $question_data['option_b']; ?>
                        </div>
                        <div class="<?php echo $question_data['correct_answer'] == 'C' ? 'badge-success' : ''; ?>">
                            C. <?php echo $question_data['option_c']; ?>
                        </div>
                        <div class="<?php echo $question_data['correct_answer'] == 'D' ? 'badge-success' : ''; ?>">
                            D. <?php echo $question_data['option_d']; ?>
                        </div>
                    </div>
                    <div style="margin-top: 10px">
                        <span class="badge badge-info">Correct: <?php echo $question_data['correct_answer']; ?></span>
                        <span class="badge badge-info">Marks: <?php echo $question_data['marks']; ?></span>
                        <span class="badge badge-info">Difficulty: <?php echo ucfirst($question_data['difficulty_level']); ?></span>
                    </div>
                <?php elseif ($question_type == 'subjective'): ?>
                    <div class="math-content">
                        <strong>Q:</strong> <?php echo $question_data['question_text']; ?>
                    </div>
                    <div style="margin-top: 15px; background: #e8f5e9; padding: 15px; border-radius: 5px;">
                        <strong>Answer Guide:</strong>
                        <div class="math-content"><?php echo $question_data['correct_answer'] ?? 'No answer guide provided.'; ?></div>
                    </div>
                    <div style="margin-top: 10px">
                        <span class="badge badge-info">Marks: <?php echo $question_data['marks']; ?></span>
                        <span class="badge badge-info">Difficulty: <?php echo ucfirst($question_data['difficulty_level']); ?></span>
                    </div>
                <?php elseif ($question_type == 'theory'): ?>
                    <div class="math-content">
                        <strong>Q:</strong> <?php echo $question_data['question_text'] ?? 'No question text.'; ?>
                    </div>
                    <?php if (!empty($question_data['question_file'])): ?>
                        <div style="margin-top: 15px">
                            <i class="fas fa-file"></i> <a href="../<?php echo $question_data['question_file']; ?>" target="_blank">View attached file</a>
                        </div>
                    <?php endif; ?>
                    <div style="margin-top: 10px">
                        <span class="badge badge-info">Marks: <?php echo $question_data['marks']; ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Initialize TinyMCE
        tinymce.init({
            selector: '.tinymce',
            height: 300,
            menubar: true,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'help', 'wordcount', 'mathjax'
            ],
            toolbar: 'undo redo | blocks | ' +
                'bold italic backcolor | alignleft aligncenter ' +
                'alignright alignjustify | bullist numlist outdent indent | ' +
                'removeformat | help',
            content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:16px }'
        });

        tinymce.init({
            selector: '.tinymce-small',
            height: 200,
            menubar: false,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code',
                'insertdatetime', 'media', 'help', 'wordcount'
            ],
            toolbar: 'undo redo | bold italic | bullist numlist | link',
            content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px }'
        });

        // Auto-hide message after 5 seconds
        setTimeout(function() {
            const message = document.querySelector('.message');
            if (message) {
                message.style.opacity = '0';
                message.style.transform = 'translateY(-20px)';
                setTimeout(() => message.remove(), 300);
            }
        }, 5000);

        // Confirm before leaving with unsaved changes
        let formChanged = false;
        const form = document.querySelector('form');
        
        form.addEventListener('input', function() {
            formChanged = true;
        });

        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });

        form.addEventListener('submit', function() {
            formChanged = false;
        });

        // File upload preview
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function() {
                const label = this.nextElementSibling;
                if (this.files && this.files[0]) {
                    label.innerHTML = `
                        <i class="fas fa-file"></i>
                        <p>${this.files[0].name}</p>
                        <p class="small">${(this.files[0].size / 1024).toFixed(2)} KB</p>
                    `;
                }
            });
        });
    </script>
</body>
</html>