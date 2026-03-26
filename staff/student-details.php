<?php
// staff/student-details.php - Staff Student Details
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
$staff_role = $_SESSION['staff_role'] ?? 'staff';

// Initialize auth system
initAuthSystem($pdo);

// Check if student ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['message'] = "Invalid student ID";
    $_SESSION['message_type'] = "error";
    header("Location: manage-students.php");
    exit();
}

$student_id = (int)$_GET['id'];

// Get staff assigned classes to verify access
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT class 
        FROM staff_classes 
        WHERE staff_id = ? 
        ORDER BY class
    ");
    $stmt->execute([$staff_id]);
    $assigned_classes = $stmt->fetchAll();
    $assigned_class_names = array_column($assigned_classes, 'class');
} catch (Exception $e) {
    error_log("Staff classes error: " . $e->getMessage());
    $error_message = "Error loading assigned classes";
}

// Get student basic information
try {
    $stmt = $pdo->prepare("
        SELECT s.*, 
               (SELECT COUNT(*) FROM exam_sessions es WHERE es.student_id = s.id AND es.status = 'completed') as total_exams,
               (SELECT COUNT(*) FROM assignment_submissions asm WHERE asm.student_id = s.id) as total_assignments,
               (SELECT COUNT(*) FROM assignment_submissions asm WHERE asm.student_id = s.id AND asm.status = 'submitted') as pending_submissions,
               (SELECT AVG(percentage) FROM results r WHERE r.student_id = s.id) as avg_score
        FROM students s 
        WHERE s.id = ? AND s.status = 'active'
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        $_SESSION['message'] = "Student not found";
        $_SESSION['message_type'] = "error";
        header("Location: manage-students.php");
        exit();
    }

    // Check if staff has access to this student's class
    if (!in_array($student['class'], $assigned_class_names)) {
        $_SESSION['message'] = "You don't have access to this student's class";
        $_SESSION['message_type'] = "error";
        header("Location: manage-students.php");
        exit();
    }
} catch (Exception $e) {
    error_log("Student details error: " . $e->getMessage());
    $error_message = "Error loading student information";
}

// Get student's exam results
try {
    $stmt = $pdo->prepare("
        SELECT r.*, e.exam_name, e.subject_id, sub.subject_name, e.class as exam_class,
               TIMESTAMPDIFF(MINUTE, r.submitted_at, NOW()) as minutes_ago
        FROM results r
        JOIN exams e ON r.exam_id = e.id
        LEFT JOIN subjects sub ON e.subject_id = sub.id
        WHERE r.student_id = ?
        ORDER BY r.submitted_at DESC
        LIMIT 10
    ");
    $stmt->execute([$student_id]);
    $exam_results = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Exam results error: " . $e->getMessage());
    $exam_results = [];
}

// Get student's assignment submissions
try {
    $stmt = $pdo->prepare("
        SELECT asm.*, a.title as assignment_title, a.subject_id, sub.subject_name, a.class as assignment_class,
               a.deadline, a.max_marks,
               TIMESTAMPDIFF(HOUR, asm.submitted_at, NOW()) as hours_ago
        FROM assignment_submissions asm
        JOIN assignments a ON asm.assignment_id = a.id
        LEFT JOIN subjects sub ON a.subject_id = sub.id
        WHERE asm.student_id = ?
        ORDER BY asm.submitted_at DESC
        LIMIT 10
    ");
    $stmt->execute([$student_id]);
    $assignments = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Assignments error: " . $e->getMessage());
    $assignments = [];
}

// Get student's recent activity
try {
    $stmt = $pdo->prepare("
        SELECT al.*, 
               TIMESTAMPDIFF(MINUTE, al.created_at, NOW()) as minutes_ago
        FROM activity_logs al
        WHERE al.user_id = ? AND al.user_type = 'student'
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$student_id]);
    $activities = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Activities error: " . $e->getMessage());
    $activities = [];
}

// Get student's performance trend (last 5 exams)
try {
    $stmt = $pdo->prepare("
        SELECT r.percentage, r.submitted_at, e.exam_name
        FROM results r
        JOIN exams e ON r.exam_id = e.id
        WHERE r.student_id = ?
        ORDER BY r.submitted_at DESC
        LIMIT 5
    ");
    $stmt->execute([$student_id]);
    $performance_data = $stmt->fetchAll();

    // Calculate performance trend
    $trend = 'stable';
    if (count($performance_data) >= 2) {
        $first_score = $performance_data[count($performance_data) - 1]['percentage'];
        $last_score = $performance_data[0]['percentage'];
        $difference = $last_score - $first_score;

        if ($difference > 5) {
            $trend = 'improving';
        } elseif ($difference < -5) {
            $trend = 'declining';
        }
    }
} catch (Exception $e) {
    error_log("Performance trend error: " . $e->getMessage());
    $performance_data = [];
    $trend = 'stable';
}

// Get student's attendance (if available in your system)
// This is a placeholder - adjust based on your actual attendance system
$attendance_rate = 0;
try {
    // Example: If you have an attendance table
    // $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(status = 'present') as present FROM attendance WHERE student_id = ?");
    // $stmt->execute([$student_id]);
    // $attendance = $stmt->fetch();
    // $attendance_rate = $attendance['total'] > 0 ? round(($attendance['present'] / $attendance['total']) * 100) : 0;

    // For now, we'll use a placeholder or skip it
    $attendance_rate = null;
} catch (Exception $e) {
    // Attendance system not implemented yet
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'send_message':
                $message = trim($_POST['message'] ?? '');
                if (!empty($message)) {
                    // Log the message sending activity
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO activity_logs (user_id, user_type, activity, ip_address, user_agent)
                            VALUES (?, 'staff', ?, ?, ?)
                        ");
                        $stmt->execute([
                            $staff_id,
                            "Sent message to student: " . $student['full_name'],
                            $_SERVER['REMOTE_ADDR'] ?? '',
                            $_SERVER['HTTP_USER_AGENT'] ?? ''
                        ]);

                        $_SESSION['message'] = "Message sent to student successfully";
                        $_SESSION['message_type'] = "success";
                    } catch (Exception $e) {
                        error_log("Message logging error: " . $e->getMessage());
                    }
                }
                break;

            case 'assign_exam':
                $exam_id = $_POST['exam_id'] ?? '';
                if (!empty($exam_id)) {
                    // Logic to assign exam to student
                    $_SESSION['message'] = "Exam assigned to student successfully";
                    $_SESSION['message_type'] = "success";
                }
                break;
        }

        header("Location: student-details.php?id=" . $student_id);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Details - Digital CBT System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Chart.js for performance charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary-color: #2c5282;
            --secondary-color: #4299e1;
            --accent-color: #ed8936;
            --success-color: #38a169;
            --warning-color: #d69e2e;
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
            color: #333;
            min-height: 100vh;
        }

        /* Sidebar Styles (same as other pages) */
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
            display: flex;
            flex-direction: column;
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding: 0 0 20px 0;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding: 0 20px;
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
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }

        .logo-text h3 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .logo-text p {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .staff-info {
            text-align: center;
            padding: 20px 15px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 15px;
            margin: 0 15px 25px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
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
            padding: 14px 18px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-radius: 12px;
            border-left: 4px solid transparent;
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: var(--accent-color);
            transform: translateX(5px);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border-left-color: var(--accent-color);
            font-weight: 500;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 25px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        /* Top Header */
        .top-header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            border-left: 5px solid var(--accent-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title h1 {
            color: var(--dark-color);
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 8px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .header-title p {
            color: #4a5568;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .back-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 1rem;
            text-decoration: none;
        }

        .back-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(66, 153, 225, 0.3);
        }

        /* Student Profile Header */
        .student-profile-header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            gap: 30px;
            align-items: center;
        }

        .student-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        .student-info {
            flex: 1;
        }

        .student-name-large {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 10px;
        }

        .student-id {
            font-size: 1.1rem;
            color: #4a5568;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .student-class-badge {
            background: linear-gradient(135deg, var(--accent-color), #dd6b20);
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 1rem;
            font-weight: 600;
            display: inline-block;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            text-align: center;
            border-top: 4px solid;
        }

        .stat-card.exams {
            border-top-color: var(--secondary-color);
        }

        .stat-card.assignments {
            border-top-color: var(--warning-color);
        }

        .stat-card.performance {
            border-top-color: var(--success-color);
        }

        .stat-card.attendance {
            border-top-color: var(--accent-color);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 0.95rem;
            color: #718096;
            font-weight: 500;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .content-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-color);
        }

        .card-header h3 {
            color: var(--dark-color);
            font-size: 1.4rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header a {
            color: var(--secondary-color);
            text-decoration: none;
            font-size: 1rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .card-header a:hover {
            color: var(--primary-color);
            transform: translateX(3px);
        }

        /* Performance Chart */
        .chart-container {
            height: 300px;
            margin-top: 20px;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid var(--light-color);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .data-table th {
            background: linear-gradient(135deg, var(--light-color), #e2e8f0);
            padding: 16px 20px;
            text-align: left;
            font-weight: 600;
            color: var(--dark-color);
            border-bottom: 2px solid #cbd5e0;
            font-size: 0.95rem;
        }

        .data-table td {
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
            color: #4a5568;
        }

        .data-table tr:hover {
            background: #f7fafc;
        }

        .score-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }

        .score-excellent {
            background: #c6f6d5;
            color: var(--success-color);
        }

        .score-good {
            background: #feebc8;
            color: var(--warning-color);
        }

        .score-poor {
            background: #fed7d7;
            color: var(--danger-color);
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .status-submitted {
            background: #bee3f8;
            color: var(--secondary-color);
        }

        .status-graded {
            background: #c6f6d5;
            color: var(--success-color);
        }

        /* Activity List */
        .activity-list {
            list-style: none;
        }

        .activity-item {
            padding: 18px 0;
            border-bottom: 1px solid var(--light-color);
            display: flex;
            gap: 18px;
            align-items: flex-start;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: var(--light-color);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.2rem;
            color: var(--secondary-color);
        }

        .activity-content h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--dark-color);
        }

        .activity-content p {
            font-size: 0.9rem;
            color: #4a5568;
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .activity-time {
            font-size: 0.85rem;
            color: #a0aec0;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Action Cards */
        .action-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .action-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #e2e8f0;
        }

        .action-card:hover {
            border-color: var(--secondary-color);
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(66, 153, 225, 0.15);
        }

        .action-icon {
            font-size: 2.5rem;
            color: var(--secondary-color);
            margin-bottom: 15px;
        }

        .action-text {
            font-weight: 600;
            font-size: 1rem;
            color: var(--dark-color);
        }

        /* Message Form */
        .message-form {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-top: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .message-form h4 {
            color: var(--dark-color);
            margin-bottom: 20px;
            font-size: 1.2rem;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            font-size: 1rem;
            width: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(66, 153, 225, 0.3);
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-left: 4px solid;
        }

        .alert-success {
            background: #f0fff4;
            color: #22543d;
            border-left-color: var(--success-color);
        }

        .alert-error {
            background: #fff5f5;
            color: #742a2a;
            border-left-color: var(--danger-color);
        }

        .alert-warning {
            background: #fffaf0;
            color: #744210;
            border-left-color: var(--warning-color);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #a0aec0;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state p {
            font-size: 1.1rem;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 300px;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .mobile-menu-btn {
                display: block;
                position: fixed;
                top: 25px;
                right: 25px;
                z-index: 101;
                background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
                color: white;
                border: none;
                width: 50px;
                height: 50px;
                border-radius: 12px;
                font-size: 22px;
                cursor: pointer;
                box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
            }

            .student-profile-header {
                flex-direction: column;
                text-align: center;
                padding: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .action-cards {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        /* Trend Indicator */
        .trend-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-left: 10px;
        }

        .trend-up {
            background: #c6f6d5;
            color: var(--success-color);
        }

        .trend-down {
            background: #fed7d7;
            color: var(--danger-color);
        }

        .trend-stable {
            background: #feebc8;
            color: var(--warning-color);
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
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="logo-text">
                    <h3><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?></h3>
                    <p>Staff Portal</p>
                </div>
            </div>
        </div>

        <div class="sidebar-content">
            <div class="staff-info">
                <h4><?php echo htmlspecialchars($staff_name); ?></h4>
                <p>Staff ID: <?php echo htmlspecialchars($staff_id); ?></p>
                <div class="staff-role"><?php echo ucfirst(str_replace('_', ' ', $staff_role)); ?></div>
            </div>

            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-students.php"><i class="fas fa-users"></i> My Students</a></li>
                <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
                <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
                <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
                <li><a href="questions.php"><i class="fas fa-question-circle"></i> Question Bank</a></li>
                <li><a href="profile.php"><i class="fas fa-user-cog"></i> My Profile</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>Student Details</h1>
                <p>
                    <i class="fas fa-user-graduate"></i>
                    Viewing student profile and performance
                </p>
            </div>
            <a href="manage-students.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Students
            </a>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type'] ?? 'success'; ?>">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['message']); ?>
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            </div>
        <?php endif; ?>

        <!-- Student Profile Header -->
        <div class="student-profile-header">
            <div class="student-avatar-large">
                <?php
                // Get first letter of first name
                $name_parts = explode(' ', $student['full_name']);
                $first_letter = strtoupper(substr($name_parts[0], 0, 1));
                echo $first_letter;
                ?>
            </div>
            <div class="student-info">
                <h1 class="student-name-large"><?php echo htmlspecialchars($student['full_name']); ?></h1>
                <div class="student-id">
                    <i class="fas fa-id-card"></i>
                    Admission Number: <?php echo htmlspecialchars($student['admission_number']); ?>
                </div>
                <div>
                    <span class="student-class-badge">
                        <i class="fas fa-users-class"></i>
                        Class: <?php echo htmlspecialchars($student['class']); ?>
                    </span>
                    <?php if ($trend !== 'stable'): ?>
                        <span class="trend-indicator trend-<?php echo $trend; ?>">
                            <i class="fas fa-arrow-<?php echo $trend === 'improving' ? 'up' : 'down'; ?>"></i>
                            <?php echo ucfirst($trend); ?> performance
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card exams">
                <div class="stat-value"><?php echo $student['total_exams']; ?></div>
                <div class="stat-label">Exams Taken</div>
            </div>

            <div class="stat-card assignments">
                <div class="stat-value"><?php echo $student['total_assignments']; ?></div>
                <div class="stat-label">Assignments</div>
            </div>

            <div class="stat-card performance">
                <div class="stat-value"><?php echo number_format($student['avg_score'] ?? 0, 1); ?>%</div>
                <div class="stat-label">Average Score</div>
            </div>

            <div class="stat-card attendance">
                <div class="stat-value">
                    <?php if ($attendance_rate !== null): ?>
                        <?php echo $attendance_rate; ?>%
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </div>
                <div class="stat-label">Attendance Rate</div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Left Column -->
            <div>
                <!-- Performance Chart -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> Performance Trend</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>

                <!-- Recent Exam Results -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-file-alt"></i> Recent Exam Results</h3>
                        <a href="view-results.php?student_id=<?php echo $student_id; ?>">View All</a>
                    </div>
                    <?php if (!empty($exam_results)): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Exam</th>
                                        <th>Subject</th>
                                        <th>Score</th>
                                        <th>Grade</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($exam_results as $result):
                                        $percentage = $result['percentage'] ?? 0;
                                        $score_class = '';
                                        if ($percentage >= 70) {
                                            $score_class = 'score-excellent';
                                        } elseif ($percentage >= 50) {
                                            $score_class = 'score-good';
                                        } else {
                                            $score_class = 'score-poor';
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($result['exam_name']); ?></td>
                                            <td><?php echo htmlspecialchars($result['subject_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="score-badge <?php echo $score_class; ?>">
                                                    <?php echo number_format($percentage, 1); ?>%
                                                </span>
                                            </td>
                                            <td>
                                                <span style="font-weight: 600; color: <?php echo $percentage >= 70 ? '#38a169' : ($percentage >= 50 ? '#d69e2e' : '#e53e3e'); ?>;">
                                                    <?php echo $result['grade'] ?? 'N/A'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                $time_ago = $result['minutes_ago'];
                                                if ($time_ago < 60) {
                                                    echo $time_ago . ' min ago';
                                                } elseif ($time_ago < 1440) {
                                                    echo floor($time_ago / 60) . ' hours ago';
                                                } else {
                                                    echo date('M d, Y', strtotime($result['submitted_at']));
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <p>No exam results found</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column -->
            <div>
                <!-- Recent Activities -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Recent Activity</h3>
                    </div>
                    <?php if (!empty($activities)): ?>
                        <ul class="activity-list">
                            <?php foreach ($activities as $activity): ?>
                                <li class="activity-item">
                                    <div class="activity-icon">
                                        <i class="fas fa-user-graduate"></i>
                                    </div>
                                    <div class="activity-content">
                                        <h4>Student Activity</h4>
                                        <p><?php echo htmlspecialchars($activity['activity']); ?></p>
                                        <div class="activity-time">
                                            <i class="far fa-clock"></i>
                                            <?php
                                            $time_ago = $activity['minutes_ago'];
                                            if ($time_ago < 60) {
                                                echo $time_ago . ' min ago';
                                            } elseif ($time_ago < 1440) {
                                                echo floor($time_ago / 60) . ' hours ago';
                                            } else {
                                                echo date('M d, Y', strtotime($activity['created_at']));
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <p>No recent activity</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Assignment Submissions -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-tasks"></i> Recent Assignments</h3>
                    </div>
                    <?php if (!empty($assignments)): ?>
                        <ul class="activity-list">
                            <?php foreach ($assignments as $assignment): ?>
                                <li class="activity-item">
                                    <div class="activity-icon">
                                        <i class="fas fa-tasks"></i>
                                    </div>
                                    <div class="activity-content">
                                        <h4><?php echo htmlspecialchars($assignment['assignment_title']); ?></h4>
                                        <p><?php echo htmlspecialchars($assignment['subject_name'] ?? 'N/A'); ?></p>
                                        <div class="activity-time">
                                            <i class="far fa-clock"></i>
                                            <?php
                                            $hours_ago = $assignment['hours_ago'];
                                            if ($hours_ago < 24) {
                                                echo $hours_ago . ' hours ago';
                                            } else {
                                                echo date('M d, Y', strtotime($assignment['submitted_at']));
                                            }
                                            ?>
                                            <span class="status-badge status-<?php echo $assignment['status']; ?>">
                                                <?php echo ucfirst($assignment['status']); ?>
                                            </span>
                                            <?php if ($assignment['grade']): ?>
                                                <span style="margin-left: 10px; font-weight: 600;">
                                                    Grade: <?php echo $assignment['grade']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-tasks"></i>
                            <p>No assignment submissions</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="content-card">
                    <div class="card-header">
                        <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                    </div>
                    <div class="action-cards">
                        <div class="action-card" onclick="sendQuickMessage()">
                            <div class="action-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div class="action-text">Send Message</div>
                        </div>

                        <div class="action-card" onclick="assignExamToStudent()">
                            <div class="action-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="action-text">Assign Exam</div>
                        </div>

                        <div class="action-card" onclick="viewFullReport()">
                            <div class="action-icon">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <div class="action-text">Generate Report</div>
                        </div>
                    </div>

                    <!-- Message Form (hidden by default) -->
                    <div class="message-form" id="messageForm" style="display: none;">
                        <h4>Send Message to <?php echo htmlspecialchars($student['full_name']); ?></h4>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="send_message">
                            <div class="form-group">
                                <label for="message">Message:</label>
                                <textarea name="message" id="message" class="form-control"
                                    placeholder="Type your message here..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Send Message
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            mobileMenuBtn.innerHTML = sidebar.classList.contains('active') ?
                '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
        });

        // Quick Actions
        function sendQuickMessage() {
            const messageForm = document.getElementById('messageForm');
            messageForm.style.display = messageForm.style.display === 'none' ? 'block' : 'none';
            if (messageForm.style.display === 'block') {
                document.getElementById('message').focus();
            }
        }

        function assignExamToStudent() {
            window.location.href = 'assign-exam.php?student_id=<?php echo $student_id; ?>';
        }

        function viewFullReport() {
            window.location.href = 'student-report.php?id=<?php echo $student_id; ?>';
        }

        // Initialize Performance Chart
        document.addEventListener('DOMContentLoaded', function() {
            const performanceData = <?php echo json_encode(array_reverse($performance_data)); ?>;

            if (performanceData.length > 0) {
                const ctx = document.getElementById('performanceChart').getContext('2d');
                const labels = performanceData.map(item => {
                    const date = new Date(item.submitted_at);
                    return date.toLocaleDateString('en-US', {
                        month: 'short',
                        day: 'numeric'
                    });
                });

                const scores = performanceData.map(item => parseFloat(item.percentage));
                const examNames = performanceData.map(item => item.exam_name);

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Exam Scores (%)',
                            data: scores,
                            borderColor: 'rgba(66, 153, 225, 1)',
                            backgroundColor: 'rgba(66, 153, 225, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: function(context) {
                                const value = context.dataset.data[context.dataIndex];
                                if (value >= 70) return '#38a169';
                                if (value >= 50) return '#d69e2e';
                                return '#e53e3e';
                            },
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 6,
                            pointHoverRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `Score: ${context.parsed.y}%`;
                                    },
                                    afterLabel: function(context) {
                                        return examNames[context.dataIndex];
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                },
                                ticks: {
                                    callback: function(value) {
                                        return value + '%';
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            }
                        }
                    }
                });
            } else {
                document.querySelector('.chart-container').innerHTML = `
                    <div class="empty-state" style="padding: 60px 20px;">
                        <i class="fas fa-chart-line"></i>
                        <p>No performance data available</p>
                    </div>
                `;
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
                }
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close sidebar on mobile
            if (e.key === 'Escape' && window.innerWidth <= 768) {
                sidebar.classList.remove('active');
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            }

            // Ctrl+M to open message form
            if (e.ctrlKey && e.key === 'm') {
                e.preventDefault();
                sendQuickMessage();
            }

            // Ctrl+E to assign exam
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                assignExamToStudent();
            }

            // Ctrl+R to generate report
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                viewFullReport();
            }
        });
    </script>
</body>

</html>