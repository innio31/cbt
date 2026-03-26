<?php
// admin/view-exam.php - View Exam Details
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
$exam = null;
$questions = [];
$results = [];
$error_message = '';
$success_message = '';

// Get exam ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage-exams.php?message=Invalid exam ID&type=error");
    exit();
}

$exam_id = intval($_GET['id']);

// Fetch exam details
try {
    $stmt = $pdo->prepare("
        SELECT e.*, s.subject_name, sg.group_name 
        FROM exams e 
        LEFT JOIN subjects s ON e.subject_id = s.id 
        LEFT JOIN subject_groups sg ON e.group_id = sg.id 
        WHERE e.id = ?
    ");
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch();

    if (!$exam) {
        header("Location: manage-exams.php?message=Exam not found&type=error");
        exit();
    }

    // Fetch objective questions for this exam
    $stmt = $pdo->prepare("
        SELECT oq.*, t.topic_name 
        FROM objective_questions oq 
        LEFT JOIN topics t ON oq.topic_id = t.id 
        WHERE oq.subject_id = ? AND (oq.class = ? OR oq.class IS NULL)
        ORDER BY oq.difficulty_level, oq.created_at
        LIMIT 100
    ");
    $stmt->execute([$exam['subject_id'], $exam['class']]);
    $objective_questions = $stmt->fetchAll();

    // Fetch theory questions for this exam
    $stmt = $pdo->prepare("
        SELECT tq.*, tp.topic_name 
        FROM theory_questions tq 
        LEFT JOIN topics tp ON tq.topic_id = tp.id 
        WHERE tq.subject_id = ? AND (tq.class = ? OR tq.class IS NULL)
        ORDER BY tq.created_at
        LIMIT 50
    ");
    $stmt->execute([$exam['subject_id'], $exam['class']]);
    $theory_questions = $stmt->fetchAll();

    // Fetch subjective questions for this exam
    $stmt = $pdo->prepare("
        SELECT sq.*, t.topic_name 
        FROM subjective_questions sq 
        LEFT JOIN topics t ON sq.topic_id = t.id 
        WHERE sq.subject_id = ? AND (sq.class = ? OR sq.class IS NULL)
        ORDER BY sq.created_at
        LIMIT 50
    ");
    $stmt->execute([$exam['subject_id'], $exam['class']]);
    $subjective_questions = $stmt->fetchAll();

    // Fetch exam results
    $stmt = $pdo->prepare("
        SELECT r.*, s.full_name, s.admission_number, s.class 
        FROM results r 
        JOIN students s ON r.student_id = s.id 
        WHERE r.exam_id = ? 
        ORDER BY r.total_score DESC
        LIMIT 20
    ");
    $stmt->execute([$exam_id]);
    $results = $stmt->fetchAll();

    // Fetch exam sessions
    $stmt = $pdo->prepare("
        SELECT es.*, s.full_name, s.admission_number 
        FROM exam_sessions es 
        JOIN students s ON es.student_id = s.id 
        WHERE es.exam_id = ? 
        ORDER BY es.submitted_at DESC
        LIMIT 10
    ");
    $stmt->execute([$exam_id]);
    $exam_sessions = $stmt->fetchAll();

    // Count total attempts
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_attempts FROM exam_sessions WHERE exam_id = ?");
    $stmt->execute([$exam_id]);
    $total_attempts = $stmt->fetch()['total_attempts'];

    // Calculate average score
    $stmt = $pdo->prepare("SELECT AVG(percentage) as avg_score FROM results WHERE exam_id = ?");
    $stmt->execute([$exam_id]);
    $avg_score = $stmt->fetch()['avg_score'];
} catch (Exception $e) {
    error_log("View exam error: " . $e->getMessage());
    $error_message = "Error loading exam details";
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
    <title>View Exam - Admin Dashboard</title>

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

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(149, 165, 166, 0.3);
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

        /* Exam Header */
        .exam-header {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            border-left: 6px solid var(--secondary-color);
        }

        .exam-title {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .exam-title h2 {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .exam-status {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .status-active {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .status-inactive {
            background: #f8d7da;
            color: var(--danger-color);
        }

        .exam-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .meta-icon {
            width: 40px;
            height: 40px;
            background: var(--light-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--secondary-color);
        }

        .meta-content h4 {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 3px;
        }

        .meta-content p {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        /* Exam Stats */
        .exam-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            border-top: 4px solid;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card.students {
            border-top-color: var(--secondary-color);
        }

        .stat-card.attempts {
            border-top-color: var(--warning-color);
        }

        .stat-card.avg-score {
            border-top-color: var(--success-color);
        }

        .stat-card.questions {
            border-top-color: var(--accent-color);
        }

        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .stat-card.students .stat-icon {
            background: var(--secondary-color);
        }

        .stat-card.attempts .stat-icon {
            background: var(--warning-color);
        }

        .stat-card.avg-score .stat-icon {
            background: var(--success-color);
        }

        .stat-card.questions .stat-icon {
            background: var(--accent-color);
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
        }

        /* Tabs */
        .tabs {
            background: white;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .tab-headers {
            display: flex;
            background: var(--light-color);
            border-bottom: 1px solid #ddd;
        }

        .tab-header {
            padding: 15px 25px;
            cursor: pointer;
            font-weight: 500;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .tab-header:hover {
            background: rgba(255, 255, 255, 0.5);
            color: var(--primary-color);
        }

        .tab-header.active {
            color: var(--secondary-color);
            border-bottom-color: var(--secondary-color);
            background: white;
        }

        .tab-content {
            padding: 25px;
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Questions Section */
        .questions-section {
            margin-bottom: 25px;
        }

        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
        }

        .section-title h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
        }

        .questions-count {
            background: var(--light-color);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            color: var(--primary-color);
        }

        .questions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }

        .question-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .question-card:hover {
            border-color: var(--secondary-color);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.1);
            transform: translateY(-3px);
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .question-number {
            font-weight: 600;
            color: var(--primary-color);
        }

        .question-difficulty {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .difficulty-easy {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .difficulty-medium {
            background: #fff3cd;
            color: var(--warning-color);
        }

        .difficulty-hard {
            background: #f8d7da;
            color: var(--danger-color);
        }

        .question-text {
            margin-bottom: 15px;
            line-height: 1.6;
        }

        .question-text img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin-top: 10px;
        }

        .question-options {
            margin-left: 20px;
        }

        .option {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 8px;
            padding: 8px 12px;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .option.correct {
            background: #d5f4e6;
            border-left: 4px solid var(--success-color);
        }

        .option:hover {
            background: #f5f5f5;
        }

        .option-letter {
            font-weight: 600;
            color: var(--primary-color);
            min-width: 20px;
        }

        .option-text {
            flex: 1;
        }

        .option-correct {
            color: var(--success-color);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .question-topic {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #eee;
            font-size: 0.85rem;
            color: #666;
        }

        .topic-label {
            color: var(--primary-color);
            font-weight: 500;
        }

        /* Tables */
        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead {
            background: var(--primary-color);
        }

        .data-table th {
            padding: 15px;
            text-align: left;
            color: white;
            font-weight: 500;
        }

        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        .data-table tbody tr:hover {
            background: #f9f9f9;
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .score-good {
            color: var(--success-color);
            font-weight: 600;
        }

        .score-average {
            color: var(--warning-color);
            font-weight: 600;
        }

        .score-poor {
            color: var(--danger-color);
            font-weight: 600;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .empty-state-icon {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 15px;
        }

        .empty-state-text h3 {
            color: #666;
            margin-bottom: 10px;
        }

        .empty-state-text p {
            color: #999;
            margin-bottom: 20px;
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

            .exam-title {
                flex-direction: column;
                gap: 15px;
            }

            .exam-meta {
                grid-template-columns: 1fr;
            }

            .exam-stats {
                grid-template-columns: 1fr;
            }

            .tab-headers {
                flex-wrap: wrap;
            }

            .tab-header {
                flex: 1;
                text-align: center;
                padding: 12px 15px;
                font-size: 0.9rem;
            }

            .questions-grid {
                grid-template-columns: 1fr;
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

        /* Theory Question Styles */
        .theory-question {
            border-left: 4px solid var(--warning-color);
        }

        .question-marks {
            background: var(--warning-color);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        /* Exam Instructions */
        .exam-instructions {
            background: #fff8e1;
            border-left: 4px solid var(--warning-color);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .instructions-title {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--warning-color);
            margin-bottom: 10px;
            font-weight: 600;
        }

        .instructions-list {
            padding-left: 20px;
        }

        .instructions-list li {
            margin-bottom: 8px;
            color: #5d4037;
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
                <h1>Exam Details</h1>
                <p>View and manage exam information</p>
            </div>
            <div class="header-actions">
                <a href="manage-exams.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Exams
                </a>
                <a href="edit-exam.php?id=<?php echo $exam_id; ?>" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Edit Exam
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

        <!-- Exam Header -->
        <div class="exam-header">
            <div class="exam-title">
                <div>
                    <h2><?php echo htmlspecialchars($exam['exam_name']); ?></h2>
                    <?php if ($exam['group_name']): ?>
                        <p style="color: #666; margin-bottom: 5px;">Group: <?php echo htmlspecialchars($exam['group_name']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="exam-status">
                    <span class="status-badge <?php echo $exam['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                        <?php echo $exam['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
            </div>

            <div class="exam-meta">
                <div class="meta-item">
                    <div class="meta-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="meta-content">
                        <h4>Subject</h4>
                        <p><?php echo htmlspecialchars($exam['subject_name'] ?? 'N/A'); ?></p>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-icon">
                        <i class="fas fa-school"></i>
                    </div>
                    <div class="meta-content">
                        <h4>Class</h4>
                        <p><?php echo htmlspecialchars($exam['class']); ?></p>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="meta-content">
                        <h4>Duration</h4>
                        <p><?php echo htmlspecialchars($exam['duration_minutes'] ?? 'N/A'); ?> minutes</p>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-icon">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <div class="meta-content">
                        <h4>Exam Type</h4>
                        <p><?php echo ucfirst(htmlspecialchars($exam['exam_type'] ?? 'objective')); ?></p>
                    </div>
                </div>
            </div>

            <!-- Exam Instructions -->
            <?php if ($exam['instructions']): ?>
                <div class="exam-instructions">
                    <div class="instructions-title">
                        <i class="fas fa-info-circle"></i>
                        <span>Exam Instructions</span>
                    </div>
                    <div class="instructions-content">
                        <?php echo nl2br(htmlspecialchars($exam['instructions'])); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Exam Stats -->
        <div class="exam-stats">
            <div class="stat-card students">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value">
                            <?php
                            $total_questions = ($exam['objective_count'] ?? 0) +
                                ($exam['subjective_count'] ?? 0) +
                                ($exam['theory_count'] ?? 0);
                            echo $total_questions;
                            ?>
                        </div>
                        <div class="stat-label">Total Questions</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-question-circle"></i>
                    </div>
                </div>
                <div style="font-size: 0.9rem; color: #666;">
                    <?php if ($exam['objective_count']): ?>Objective: <?php echo $exam['objective_count']; ?><br><?php endif; ?>
                <?php if ($exam['theory_count']): ?>Theory: <?php echo $exam['theory_count']; ?><br><?php endif; ?>
            <?php if ($exam['subjective_count']): ?>Subjective: <?php echo $exam['subjective_count']; ?><?php endif; ?>
                </div>
            </div>

            <div class="stat-card attempts">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_attempts ?? 0; ?></div>
                        <div class="stat-label">Total Attempts</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>
                <p style="font-size: 0.9rem; color: #666;">Students who took this exam</p>
            </div>

            <div class="stat-card avg-score">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo number_format($avg_score ?? 0, 1); ?>%</div>
                        <div class="stat-label">Average Score</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
                <p style="font-size: 0.9rem; color: #666;">Based on all attempts</p>
            </div>

            <div class="stat-card questions">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value">
                            <?php
                            $available_questions = count($objective_questions) + count($theory_questions) + count($subjective_questions);
                            echo $available_questions;
                            ?>
                        </div>
                        <div class="stat-label">Available Questions</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-database"></i>
                    </div>
                </div>
                <p style="font-size: 0.9rem; color: #666;">Questions in database for this subject/class</p>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab-headers">
                <div class="tab-header active" data-tab="questions">Questions</div>
                <div class="tab-header" data-tab="results">Results</div>
                <div class="tab-header" data-tab="sessions">Exam Sessions</div>
                <div class="tab-header" data-tab="analysis">Analysis</div>
            </div>

            <!-- Questions Tab -->
            <div class="tab-content active" id="questions-tab">
                <!-- Objective Questions -->
                <?php if (!empty($objective_questions) && $exam['exam_type'] !== 'theory'): ?>
                    <div class="questions-section">
                        <div class="section-title">
                            <h3>Objective Questions</h3>
                            <span class="questions-count"><?php echo count($objective_questions); ?> questions</span>
                        </div>

                        <div class="questions-grid">
                            <?php foreach ($objective_questions as $index => $question): ?>
                                <div class="question-card">
                                    <div class="question-header">
                                        <span class="question-number">Q<?php echo $index + 1; ?></span>
                                        <span class="question-difficulty difficulty-<?php echo $question['difficulty_level'] ?? 'medium'; ?>">
                                            <?php echo ucfirst($question['difficulty_level'] ?? 'medium'); ?>
                                        </span>
                                    </div>

                                    <div class="question-text">
                                        <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                                        <?php if ($question['question_image']): ?>
                                            <img src="../uploads/questions/<?php echo htmlspecialchars($question['question_image']); ?>"
                                                alt="Question Image"
                                                style="max-width: 100%; margin-top: 10px;">
                                        <?php endif; ?>
                                    </div>

                                    <div class="question-options">
                                        <div class="option <?php echo $question['correct_answer'] === 'A' ? 'correct' : ''; ?>">
                                            <span class="option-letter">A.</span>
                                            <span class="option-text"><?php echo htmlspecialchars($question['option_a']); ?></span>
                                            <?php if ($question['correct_answer'] === 'A'): ?>
                                                <span class="option-correct">✓ Correct</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="option <?php echo $question['correct_answer'] === 'B' ? 'correct' : ''; ?>">
                                            <span class="option-letter">B.</span>
                                            <span class="option-text"><?php echo htmlspecialchars($question['option_b']); ?></span>
                                            <?php if ($question['correct_answer'] === 'B'): ?>
                                                <span class="option-correct">✓ Correct</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="option <?php echo $question['correct_answer'] === 'C' ? 'correct' : ''; ?>">
                                            <span class="option-letter">C.</span>
                                            <span class="option-text"><?php echo htmlspecialchars($question['option_c']); ?></span>
                                            <?php if ($question['correct_answer'] === 'C'): ?>
                                                <span class="option-correct">✓ Correct</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="option <?php echo $question['correct_answer'] === 'D' ? 'correct' : ''; ?>">
                                            <span class="option-letter">D.</span>
                                            <span class="option-text"><?php echo htmlspecialchars($question['option_d']); ?></span>
                                            <?php if ($question['correct_answer'] === 'D'): ?>
                                                <span class="option-correct">✓ Correct</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($question['topic_name']): ?>
                                        <div class="question-topic">
                                            <span class="topic-label">Topic:</span> <?php echo htmlspecialchars($question['topic_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($exam['exam_type'] !== 'theory'): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <div class="empty-state-text">
                            <h3>No Objective Questions Found</h3>
                            <p>No objective questions available for this subject and class.</p>
                        </div>
                        <a href="add-questions.php?subject_id=<?php echo $exam['subject_id']; ?>&class=<?php echo urlencode($exam['class']); ?>"
                            class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Questions
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Theory Questions -->
                <?php if (!empty($theory_questions) && $exam['exam_type'] !== 'objective'): ?>
                    <div class="questions-section">
                        <div class="section-title">
                            <h3>Theory Questions</h3>
                            <span class="questions-count"><?php echo count($theory_questions); ?> questions</span>
                        </div>

                        <div class="questions-grid">
                            <?php foreach ($theory_questions as $index => $question): ?>
                                <div class="question-card theory-question">
                                    <div class="question-header">
                                        <span class="question-number">Q<?php echo $index + 1; ?></span>
                                        <span class="question-marks"><?php echo $question['marks'] ?? 5; ?> marks</span>
                                    </div>

                                    <div class="question-text">
                                        <?php if ($question['question_file']): ?>
                                            <p><strong>File:</strong> <?php echo htmlspecialchars($question['question_file']); ?></p>
                                        <?php endif; ?>
                                        <?php if ($question['question_text']): ?>
                                            <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($question['topic_name']): ?>
                                        <div class="question-topic">
                                            <span class="topic-label">Topic:</span> <?php echo htmlspecialchars($question['topic_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($exam['exam_type'] !== 'objective'): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="empty-state-text">
                            <h3>No Theory Questions Found</h3>
                            <p>No theory questions available for this subject and class.</p>
                        </div>
                        <a href="add-theory-questions.php?subject_id=<?php echo $exam['subject_id']; ?>&class=<?php echo urlencode($exam['class']); ?>"
                            class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Theory Questions
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Subjective Questions -->
                <?php if (!empty($subjective_questions) && $exam['exam_type'] === 'subjective'): ?>
                    <div class="questions-section">
                        <div class="section-title">
                            <h3>Subjective Questions</h3>
                            <span class="questions-count"><?php echo count($subjective_questions); ?> questions</span>
                        </div>

                        <div class="questions-grid">
                            <?php foreach ($subjective_questions as $index => $question): ?>
                                <div class="question-card">
                                    <div class="question-header">
                                        <span class="question-number">Q<?php echo $index + 1; ?></span>
                                        <span class="question-difficulty difficulty-<?php echo $question['difficulty_level'] ?? 'medium'; ?>">
                                            <?php echo ucfirst($question['difficulty_level'] ?? 'medium'); ?>
                                        </span>
                                    </div>

                                    <div class="question-text">
                                        <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                                    </div>

                                    <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 6px;">
                                        <strong>Answer:</strong> <?php echo htmlspecialchars($question['correct_answer']); ?>
                                    </div>

                                    <?php if ($question['topic_name']): ?>
                                        <div class="question-topic">
                                            <span class="topic-label">Topic:</span> <?php echo htmlspecialchars($question['topic_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Results Tab -->
            <div class="tab-content" id="results-tab">
                <?php if (!empty($results)): ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student</th>
                                    <th>Admission No.</th>
                                    <th>Class</th>
                                    <th>Objective Score</th>
                                    <th>Theory Score</th>
                                    <th>Total Score</th>
                                    <th>Percentage</th>
                                    <th>Grade</th>
                                    <th>Submitted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $index => $result): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($result['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($result['admission_number']); ?></td>
                                        <td><?php echo htmlspecialchars($result['class']); ?></td>
                                        <td><?php echo htmlspecialchars($result['objective_score'] ?? 0); ?></td>
                                        <td><?php echo htmlspecialchars($result['theory_score'] ?? 0); ?></td>
                                        <td><?php echo htmlspecialchars($result['total_score'] ?? 0); ?></td>
                                        <td>
                                            <span class="score-<?php echo ($result['percentage'] ?? 0) >= 50 ? 'good' : (($result['percentage'] ?? 0) >= 40 ? 'average' : 'poor'); ?>">
                                                <?php echo number_format($result['percentage'] ?? 0, 2); ?>%
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($result['grade'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php
                                            $submitted_date = $result['submitted_at'] ?? null;
                                            if ($submitted_date && strtotime($submitted_date) !== false) {
                                                echo date('M d, Y', strtotime($submitted_date));
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="header-actions" style="justify-content: center; margin-top: 20px;">
                        <a href="export-results.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-success">
                            <i class="fas fa-file-export"></i> Export Results
                        </a>
                        <a href="detailed-results.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-primary">
                            <i class="fas fa-chart-bar"></i> View Detailed Analysis
                        </a>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div class="empty-state-text">
                            <h3>No Results Yet</h3>
                            <p>No students have taken this exam yet.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Exam Sessions Tab -->
            <div class="tab-content" id="sessions-tab">
                <?php if (!empty($exam_sessions)): ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Admission No.</th>
                                    <th>Start Time</th>
                                    <th>End Time</th>
                                    <th>Status</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($exam_sessions as $session): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($session['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($session['admission_number']); ?></td>
                                        <td>
                                            <?php
                                            $start_date = $session['start_time'] ?? null;
                                            if ($start_date && strtotime($start_date) !== false) {
                                                echo date('M d, Y H:i', strtotime($start_date));
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            $end_date = $session['end_time'] ?? null;
                                            if ($end_date && strtotime($end_date) !== false) {
                                                echo date('M d, Y H:i', strtotime($end_date));
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $session['status'] === 'completed' ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo ucfirst($session['status'] ?? 'in_progress'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($session['score'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($session['percentage']): ?>
                                                <span class="score-<?php echo $session['percentage'] >= 50 ? 'good' : ($session['percentage'] >= 40 ? 'average' : 'poor'); ?>">
                                                    <?php echo number_format($session['percentage'], 2); ?>%
                                                </span>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <div class="empty-state-text">
                            <h3>No Exam Sessions</h3>
                            <p>No exam sessions recorded for this exam.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Analysis Tab -->
            <div class="tab-content" id="analysis-tab">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="empty-state-text">
                        <h3>Analysis Coming Soon</h3>
                        <p>Detailed exam analysis and statistics will be available in a future update.</p>
                    </div>
                    <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                        <button class="btn btn-primary" onclick="alert('Feature coming soon!')">
                            <i class="fas fa-chart-bar"></i> Performance Analysis
                        </button>
                        <button class="btn btn-success" onclick="alert('Feature coming soon!')">
                            <i class="fas fa-chart-line"></i> Trend Analysis
                        </button>
                    </div>
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

        // Tab functionality
        const tabHeaders = document.querySelectorAll('.tab-header');
        const tabContents = document.querySelectorAll('.tab-content');

        tabHeaders.forEach(header => {
            header.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');

                // Remove active class from all headers and contents
                tabHeaders.forEach(h => h.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));

                // Add active class to clicked header
                this.classList.add('active');

                // Show corresponding content
                document.getElementById(tabId + '-tab').classList.add('active');
            });
        });

        // Print exam details
        function printExamDetails() {
            const printContent = document.querySelector('.exam-header').outerHTML +
                document.querySelector('.exam-stats').outerHTML;

            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Exam Details - <?php echo htmlspecialchars($exam['exam_name']); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        .exam-header { border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; }
                        .exam-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
                        .stat-card { border: 1px solid #ddd; padding: 15px; border-radius: 8px; }
                        .stat-value { font-size: 24px; font-weight: bold; }
                        @media print {
                            body { padding: 0; }
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h1>Exam Details</h1>
                    ${printContent}
                    <div style="margin-top: 30px; font-size: 12px; color: #666;">
                        Printed on: ${new Date().toLocaleString()}
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printExamDetails();
            }

            // Ctrl+B to go back
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                window.location.href = 'manage-exams.php';
            }

            // Escape to close sidebar on mobile
            if (e.key === 'Escape' && window.innerWidth <= 768) {
                sidebar.classList.remove('active');
            }

            // Number keys for tabs (1-4)
            if (e.key >= '1' && e.key <= '4') {
                e.preventDefault();
                const tabIndex = parseInt(e.key) - 1;
                if (tabHeaders[tabIndex]) {
                    tabHeaders[tabIndex].click();
                }
            }
        });

        // Search in questions
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Search questions...';
        searchInput.style.cssText = `
            padding: 10px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
            width: 100%;
            max-width: 400px;
            display: block;
            margin-left: auto;
            margin-right: auto;
        `;

        // Add search to questions tab
        const questionsTab = document.getElementById('questions-tab');
        if (questionsTab) {
            questionsTab.insertBefore(searchInput, questionsTab.firstChild);

            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const questionCards = document.querySelectorAll('.question-card');

                questionCards.forEach(card => {
                    const questionText = card.textContent.toLowerCase();
                    card.style.display = questionText.includes(searchTerm) ? '' : 'none';
                });
            });
        }

        // Auto-refresh data every 2 minutes
        setTimeout(function() {
            // Only refresh if not in edit mode
            if (!window.location.href.includes('edit')) {
                window.location.reload();
            }
        }, 120000);

        // Initialize tooltips
        document.querySelectorAll('[title]').forEach(element => {
            element.addEventListener('mouseenter', function(e) {
                const tooltip = document.createElement('div');
                tooltip.className = 'tooltip';
                tooltip.textContent = this.getAttribute('title');
                tooltip.style.position = 'absolute';
                tooltip.style.background = 'var(--dark-color)';
                tooltip.style.color = 'white';
                tooltip.style.padding = '5px 10px';
                tooltip.style.borderRadius = '4px';
                tooltip.style.fontSize = '0.8rem';
                tooltip.style.zIndex = '1000';
                tooltip.style.top = (e.clientY + 10) + 'px';
                tooltip.style.left = (e.clientX + 10) + 'px';
                document.body.appendChild(tooltip);

                this._tooltip = tooltip;
            });

            element.addEventListener('mouseleave', function() {
                if (this._tooltip) {
                    this._tooltip.remove();
                    this._tooltip = null;
                }
            });
        });
    </script>
</body>

</html>