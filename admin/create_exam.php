<?php
// create-exam.php - Create Single Exam
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

$message = '';
$message_type = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_name = trim($_POST['exam_name']);
    $class = $_POST['class'];
    $subject_id = $_POST['subject_id'];
    $exam_type = $_POST['exam_type'];
    $duration_minutes = $_POST['duration_minutes'];
    $objective_count = $_POST['objective_count'] ?? 0;
    $subjective_count = $_POST['subjective_count'] ?? 0;
    $theory_count = $_POST['theory_count'] ?? 0;

    // Handle topics - can be array if multiple selected
    if (isset($_POST['topics']) && is_array($_POST['topics'])) {
        $topics = implode(', ', array_map('trim', $_POST['topics']));
    } else {
        $topics = trim($_POST['topics'] ?? '');
    }

    $instructions = trim($_POST['instructions'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Set durations based on exam type
    if ($exam_type === 'objective') {
        $objective_duration = $duration_minutes;
        $theory_duration = 0;
        $subjective_duration = 0;
    } elseif ($exam_type === 'theory') {
        $objective_duration = 0;
        $theory_duration = $duration_minutes;
        $subjective_duration = 0;
    } elseif ($exam_type === 'subjective') {
        $objective_duration = 0;
        $theory_duration = 0;
        $subjective_duration = $duration_minutes;
    } else {
        // Combined exam - set all durations
        $objective_duration = $_POST['objective_duration'] ?? 60;
        $theory_duration = $_POST['theory_duration'] ?? 60;
        $subjective_duration = $_POST['subjective_duration'] ?? 60;
    }

    try {
        // Insert exam into database
        $stmt = $pdo->prepare("
            INSERT INTO exams (
                exam_name, class, subject_id, exam_type, topics,
                objective_count, subjective_count, theory_count,
                duration_minutes, objective_duration, theory_duration, subjective_duration,
                instructions, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $exam_name,
            $class,
            $subject_id,
            $exam_type,
            $topics,
            $objective_count,
            $subjective_count,
            $theory_count,
            $duration_minutes,
            $objective_duration,
            $theory_duration,
            $subjective_duration,
            $instructions,
            $is_active
        ]);

        $exam_id = $pdo->lastInsertId();

        // Log activity
        $subject_name = $pdo->query("SELECT subject_name FROM subjects WHERE id = $subject_id")->fetchColumn();
        $activity = "Created exam: {$exam_name} ({$subject_name}) for class {$class}";
        $logStmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, user_type, activity, ip_address, user_agent)
            VALUES (?, 'admin', ?, ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['admin_id'],
            $activity,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);

        $message = "Exam created successfully! Exam ID: {$exam_id}";
        $message_type = "success";
    } catch (Exception $e) {
        error_log("Create exam error: " . $e->getMessage());
        $message = "Error creating exam: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get subjects for dropdown
$subjects = $pdo->query("SELECT id, subject_name FROM subjects ORDER BY subject_name")->fetchAll();

// Get distinct classes
$classes = $pdo->query("SELECT DISTINCT class FROM students WHERE class != '' ORDER BY class")->fetchAll();

// Get topics for AJAX request (will be fetched based on subject)
if (isset($_GET['subject_id']) && is_numeric($_GET['subject_id'])) {
    $subject_id = $_GET['subject_id'];
    $topics = $pdo->prepare("SELECT id, topic_name FROM topics WHERE subject_id = ? ORDER BY topic_name");
    $topics->execute([$subject_id]);
    echo json_encode($topics->fetchAll());
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Exam - Digital CBT System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
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
            overflow: hidden;
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
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

        .sidebar-content::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.4);
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

        /* Main Content Styles */
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

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-color);
        }

        .form-section h2 {
            color: var(--primary-color);
            font-size: 1.4rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section h2 i {
            color: var(--secondary-color);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
        }

        input[type="text"],
        input[type="number"],
        input[type="datetime-local"],
        select,
        textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        input[type="text"]:focus,
        input[type="number"]:focus,
        select:focus,
        textarea:focus {
            border-color: var(--secondary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: var(--secondary-color);
        }

        /* Topics Section */
        .topics-container {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 15px;
            display: none;
        }

        .topics-container.active {
            display: block;
        }

        .topics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .topic-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: white;
            border: 2px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .topic-checkbox:hover {
            border-color: var(--secondary-color);
            background: #e8f4fc;
        }

        .topic-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--secondary-color);
        }

        .topic-checkbox label {
            margin: 0;
            cursor: pointer;
            flex: 1;
        }

        .topic-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .topic-action-btn {
            padding: 8px 15px;
            border: 2px solid var(--secondary-color);
            background: white;
            color: var(--secondary-color);
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .topic-action-btn:hover {
            background: var(--secondary-color);
            color: white;
        }

        .topic-action-btn.select-all {
            background: var(--secondary-color);
            color: white;
        }

        .topic-action-btn.select-all:hover {
            opacity: 0.9;
        }

        /* Question Type Section */
        .question-type-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .question-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .question-type-card {
            background: white;
            border: 2px solid #ddd;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .question-type-card:hover {
            border-color: var(--secondary-color);
            transform: translateY(-2px);
        }

        .question-type-card.selected {
            border-color: var(--secondary-color);
            background: #e8f4fc;
        }

        .question-type-card i {
            font-size: 24px;
            color: var(--secondary-color);
            margin-bottom: 10px;
        }

        .question-type-card h4 {
            margin-bottom: 5px;
            color: var(--primary-color);
        }

        .question-type-card p {
            font-size: 0.85rem;
            color: #666;
        }

        .duration-inputs {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
            display: none;
        }

        .duration-inputs.active {
            display: grid;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid var(--light-color);
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
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

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 20px;
            color: var(--secondary-color);
        }

        .loading-spinner.active {
            display: block;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--secondary-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
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
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Responsive */
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
            }

            .top-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .question-type-grid,
            .topics-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Footer */
        .dashboard-footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.9rem;
            border-top: 1px solid var(--light-color);
            margin-top: 30px;
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
                    <h3><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'Digital CBT System'; ?></h3>
                    <p>Admin Panel</p>
                </div>
            </div>
        </div>

        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
        </div>

        <div class="sidebar-content">
            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
                <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
                <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
                <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
                <li><a href="create-exam.php" class="active"><i class="fas fa-plus-circle"></i> Create Exam</a></li>
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
                <h1>Create New Exam</h1>
                <p>Create a single exam for a specific class and subject</p>
            </div>
            <div class="header-actions">
                <button class="logout-btn" onclick="window.location.href='../logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <!-- Alert Message -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Form Container -->
        <div class="form-container">
            <form method="POST" action="" id="examForm">
                <!-- Exam Information Section -->
                <div class="form-section">
                    <h2><i class="fas fa-info-circle"></i> Exam Information</h2>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="exam_name">Exam Name *</label>
                            <input type="text" id="exam_name" name="exam_name" required
                                placeholder="e.g., First Term Mathematics Exam">
                        </div>

                        <div class="form-group">
                            <label for="class">Class *</label>
                            <select id="class" name="class" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['class']); ?>">
                                        <?php echo htmlspecialchars($class['class']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="custom">Custom Class...</option>
                            </select>
                            <input type="text" id="custom_class" name="custom_class"
                                placeholder="Enter custom class" style="display: none; margin-top: 10px;">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="subject_id">Subject *</label>
                            <select id="subject_id" name="subject_id" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="duration_minutes">Total Duration (minutes) *</label>
                            <input type="number" id="duration_minutes" name="duration_minutes"
                                required min="1" value="60" placeholder="e.g., 60">
                        </div>
                    </div>

                    <!-- Topics Selection Section -->
                    <div class="form-group">
                        <label>Select Topics for Questions *</label>
                        <div class="topics-container" id="topicsContainer">
                            <div class="loading-spinner" id="topicsLoading">
                                <div class="spinner"></div>
                                <p>Loading topics...</p>
                            </div>

                            <div class="topics-grid" id="topicsGrid">
                                <!-- Topics will be loaded here via AJAX -->
                            </div>

                            <div class="topic-actions" id="topicActions" style="display: none;">
                                <button type="button" class="topic-action-btn select-all" onclick="selectAllTopics()">
                                    <i class="fas fa-check-square"></i> Select All
                                </button>
                                <button type="button" class="topic-action-btn" onclick="deselectAllTopics()">
                                    <i class="fas fa-square"></i> Deselect All
                                </button>
                            </div>

                            <div id="noTopicsMessage" style="display: none; text-align: center; padding: 20px; color: #666;">
                                <i class="fas fa-book-open" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.5;"></i>
                                <h4>No Topics Found</h4>
                                <p>No topics available for this subject. You can still create the exam without specific topics.</p>
                                <p style="font-size: 0.9rem; margin-top: 10px;">
                                    <a href="manage-topics.php?subject_id=" id="addTopicsLink" target="_blank">
                                        <i class="fas fa-plus-circle"></i> Add Topics for this Subject
                                    </a>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="instructions">Exam Instructions (Optional)</label>
                        <textarea id="instructions" name="instructions"
                            placeholder="Enter specific instructions for this exam"></textarea>
                    </div>
                </div>

                <!-- Exam Type Section -->
                <div class="form-section">
                    <h2><i class="fas fa-tasks"></i> Exam Type Configuration</h2>

                    <div class="question-type-grid" id="examTypeSelector">
                        <div class="question-type-card" data-type="objective">
                            <i class="fas fa-list-ul"></i>
                            <h4>Objective Only</h4>
                            <p>Multiple choice questions</p>
                        </div>
                        <div class="question-type-card" data-type="theory">
                            <i class="fas fa-file-alt"></i>
                            <h4>Theory Only</h4>
                            <p>Written/descriptive questions</p>
                        </div>
                        <div class="question-type-card" data-type="subjective">
                            <i class="fas fa-question-circle"></i>
                            <h4>Subjective Only</h4>
                            <p>Short answer questions</p>
                        </div>
                        <div class="question-type-card" data-type="combined">
                            <i class="fas fa-layer-group"></i>
                            <h4>Combined</h4>
                            <p>Mix of different question types</p>
                        </div>
                    </div>

                    <input type="hidden" id="exam_type" name="exam_type" value="objective">

                    <!-- Question Counts -->
                    <div class="form-row" id="questionCounts">
                        <div class="form-group">
                            <label for="objective_count">Objective Questions</label>
                            <input type="number" id="objective_count" name="objective_count"
                                min="0" value="0" placeholder="0">
                        </div>
                        <div class="form-group">
                            <label for="subjective_count">Subjective Questions</label>
                            <input type="number" id="subjective_count" name="subjective_count"
                                min="0" value="0" placeholder="0">
                        </div>
                        <div class="form-group">
                            <label for="theory_count">Theory Questions</label>
                            <input type="number" id="theory_count" name="theory_count"
                                min="0" value="0" placeholder="0">
                        </div>
                    </div>

                    <!-- Duration Settings for Combined Exam -->
                    <div class="duration-inputs" id="durationSettings">
                        <div class="form-group">
                            <label for="objective_duration">Objective Duration (min)</label>
                            <input type="number" id="objective_duration" name="objective_duration"
                                min="0" value="60" placeholder="60">
                        </div>
                        <div class="form-group">
                            <label for="subjective_duration">Subjective Duration (min)</label>
                            <input type="number" id="subjective_duration" name="subjective_duration"
                                min="0" value="60" placeholder="60">
                        </div>
                        <div class="form-group">
                            <label for="theory_duration">Theory Duration (min)</label>
                            <input type="number" id="theory_duration" name="theory_duration"
                                min="0" value="60" placeholder="60">
                        </div>
                    </div>
                </div>

                <!-- Status Section -->
                <div class="form-section">
                    <h2><i class="fas fa-toggle-on"></i> Exam Status</h2>

                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="is_active" name="is_active" value="1" checked>
                            <label for="is_active">Make exam active immediately</label>
                        </div>
                        <p style="font-size: 0.9rem; color: #666; margin-top: 8px;">
                            Active exams are available for students to take
                        </p>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Exam
                    </button>
                    <a href="manage-exams.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- Footer -->
        <div class="dashboard-footer">
            <p>&copy; <?php echo date('Y'); ?> Digital CBT System - Create Exam Module</p>
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

        // Handle class selection
        document.getElementById('class').addEventListener('change', function() {
            const customClassInput = document.getElementById('custom_class');
            if (this.value === 'custom') {
                customClassInput.style.display = 'block';
                customClassInput.required = true;
            } else {
                customClassInput.style.display = 'none';
                customClassInput.required = false;
                customClassInput.value = '';
            }
        });

        // Handle custom class input
        document.getElementById('custom_class').addEventListener('input', function() {
            document.getElementById('class').value = this.value;
        });

        // Handle subject selection - load topics
        document.getElementById('subject_id').addEventListener('change', function() {
            const subjectId = this.value;
            const topicsContainer = document.getElementById('topicsContainer');
            const topicsGrid = document.getElementById('topicsGrid');
            const topicsLoading = document.getElementById('topicsLoading');
            const topicActions = document.getElementById('topicActions');
            const noTopicsMessage = document.getElementById('noTopicsMessage');
            const addTopicsLink = document.getElementById('addTopicsLink');

            if (!subjectId) {
                topicsContainer.classList.remove('active');
                return;
            }

            // Show loading and container
            topicsContainer.classList.add('active');
            topicsLoading.classList.add('active');
            topicsGrid.innerHTML = '';
            topicActions.style.display = 'none';
            noTopicsMessage.style.display = 'none';

            // Update "Add Topics" link
            addTopicsLink.href = `manage-topics.php?subject_id=${subjectId}`;

            // Fetch topics via AJAX
            fetch(`create-exam.php?subject_id=${subjectId}`)
                .then(response => response.json())
                .then(topics => {
                    topicsLoading.classList.remove('active');

                    if (topics.length === 0) {
                        noTopicsMessage.style.display = 'block';
                        return;
                    }

                    // Display topics as checkboxes
                    topics.forEach(topic => {
                        const topicDiv = document.createElement('div');
                        topicDiv.className = 'topic-checkbox';
                        topicDiv.innerHTML = `
                            <input type="checkbox" id="topic_${topic.id}" name="topics[]" value="${topic.id}">
                            <label for="topic_${topic.id}">${topic.topic_name}</label>
                        `;
                        topicsGrid.appendChild(topicDiv);
                    });

                    topicActions.style.display = 'flex';
                })
                .catch(error => {
                    console.error('Error loading topics:', error);
                    topicsLoading.classList.remove('active');
                    topicsGrid.innerHTML = `
                        <div style="text-align: center; color: #e74c3c; padding: 20px;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 10px;"></i>
                            <p>Error loading topics. Please try again.</p>
                        </div>
                    `;
                });
        });

        // Topic selection functions
        function selectAllTopics() {
            const checkboxes = document.querySelectorAll('#topicsGrid input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
        }

        function deselectAllTopics() {
            const checkboxes = document.querySelectorAll('#topicsGrid input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
        }

        // Exam type selection
        const examTypeCards = document.querySelectorAll('.question-type-card');
        const examTypeInput = document.getElementById('exam_type');
        const questionCounts = document.getElementById('questionCounts');
        const durationSettings = document.getElementById('durationSettings');

        examTypeCards.forEach(card => {
            card.addEventListener('click', function() {
                // Remove selected class from all cards
                examTypeCards.forEach(c => c.classList.remove('selected'));

                // Add selected class to clicked card
                this.classList.add('selected');

                // Update hidden input
                const type = this.dataset.type;
                examTypeInput.value = type;

                // Update UI based on exam type
                updateExamTypeUI(type);
            });
        });

        function updateExamTypeUI(type) {
            const objectiveCount = document.getElementById('objective_count');
            const subjectiveCount = document.getElementById('subjective_count');
            const theoryCount = document.getElementById('theory_count');

            // Reset all inputs
            objectiveCount.value = '0';
            subjectiveCount.value = '0';
            theoryCount.value = '0';

            // Hide duration settings initially
            durationSettings.classList.remove('active');

            switch (type) {
                case 'objective':
                    objectiveCount.value = '20';
                    objectiveCount.readOnly = false;
                    subjectiveCount.readOnly = true;
                    theoryCount.readOnly = true;
                    break;

                case 'theory':
                    theoryCount.value = '5';
                    objectiveCount.readOnly = true;
                    subjectiveCount.readOnly = true;
                    theoryCount.readOnly = false;
                    break;

                case 'subjective':
                    subjectiveCount.value = '10';
                    objectiveCount.readOnly = true;
                    subjectiveCount.readOnly = false;
                    theoryCount.readOnly = true;
                    break;

                case 'combined':
                    objectiveCount.value = '15';
                    subjectiveCount.value = '5';
                    theoryCount.value = '3';
                    objectiveCount.readOnly = false;
                    subjectiveCount.readOnly = false;
                    theoryCount.readOnly = false;
                    durationSettings.classList.add('active');
                    break;
            }
        }

        // Initialize with objective selected
        document.querySelector('.question-type-card[data-type="objective"]').classList.add('selected');
        updateExamTypeUI('objective');

        // Form validation
        document.getElementById('examForm').addEventListener('submit', function(e) {
            const examName = document.getElementById('exam_name').value.trim();
            const subject = document.getElementById('subject_id').value;
            const duration = document.getElementById('duration_minutes').value;
            const examType = examTypeInput.value;
            const topicsContainer = document.getElementById('topicsContainer');
            const topicsChecked = document.querySelectorAll('#topicsGrid input[type="checkbox"]:checked');

            let isValid = true;
            let errorMessage = '';

            if (!examName) {
                isValid = false;
                errorMessage = 'Exam name is required';
            } else if (!subject) {
                isValid = false;
                errorMessage = 'Please select a subject';
            } else if (!duration || duration < 1) {
                isValid = false;
                errorMessage = 'Please enter a valid duration';
            } else if (topicsContainer.classList.contains('active') && topicsChecked.length === 0) {
                isValid = false;
                errorMessage = 'Please select at least one topic for the exam questions';
            }

            // Validate question counts based on exam type
            if (examType === 'combined') {
                const objectiveCount = parseInt(document.getElementById('objective_count').value) || 0;
                const subjectiveCount = parseInt(document.getElementById('subjective_count').value) || 0;
                const theoryCount = parseInt(document.getElementById('theory_count').value) || 0;

                if (objectiveCount + subjectiveCount + theoryCount === 0) {
                    isValid = false;
                    errorMessage = 'Please enter at least one question for combined exam';
                }
            }

            if (!isValid) {
                e.preventDefault();
                alert(errorMessage);
            }
        });

        // Auto-calculate total duration for combined exams
        const durationInputs = ['objective_duration', 'subjective_duration', 'theory_duration'];
        durationInputs.forEach(inputId => {
            document.getElementById(inputId)?.addEventListener('input', updateTotalDuration);
        });

        function updateTotalDuration() {
            if (examTypeInput.value === 'combined') {
                let total = 0;
                durationInputs.forEach(inputId => {
                    const value = parseInt(document.getElementById(inputId)?.value) || 0;
                    total += value;
                });
                document.getElementById('duration_minutes').value = total;
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('examForm').submit();
            }

            // Ctrl+Q to go back
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                window.location.href = 'manage-exams.php';
            }

            // Escape to clear form
            if (e.key === 'Escape') {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Initialize tooltips
        const tooltips = document.querySelectorAll('[title]');
        tooltips.forEach(el => {
            el.addEventListener('mouseenter', showTooltip);
            el.addEventListener('mouseleave', hideTooltip);
        });

        function showTooltip(e) {
            // You can implement tooltip display logic here
        }

        function hideTooltip(e) {
            // You can implement tooltip hide logic here
        }
    </script>
</body>

</html>