<?php
// admin/reports.php - Reports Dashboard
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
$reports = [];
$error_message = '';
$success_message = '';

// Handle report generation requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $report_type = $_POST['report_type'] ?? '';
    $class = $_POST['class'] ?? '';
    $session = $_POST['session'] ?? '';
    $term = $_POST['term'] ?? '';
    $subject_id = $_POST['subject_id'] ?? '';
    $student_id = $_POST['student_id'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';

    try {
        switch ($report_type) {
            case 'exam_results':
                // Generate exam results report
                $stmt = $pdo->prepare("
                    SELECT 
                        s.full_name as student_name,
                        s.class,
                        e.exam_name,
                        sub.subject_name,
                        r.total_score,
                        r.percentage,
                        r.grade,
                        r.submitted_at,
                        es.start_time,
                        es.end_time
                    FROM results r
                    JOIN students s ON r.student_id = s.id
                    JOIN exams e ON r.exam_id = e.id
                    LEFT JOIN subjects sub ON e.subject_id = sub.id
                    LEFT JOIN exam_sessions es ON r.student_id = es.student_id AND r.exam_id = es.exam_id
                    WHERE (? = '' OR s.class = ?)
                    AND (? = '' OR e.subject_id = ?)
                    AND (? = '' OR r.student_id = ?)
                    AND (? = '' OR DATE(r.submitted_at) >= ?)
                    AND (? = '' OR DATE(r.submitted_at) <= ?)
                    ORDER BY r.submitted_at DESC
                ");

                $stmt->execute([$class, $class, $subject_id, $subject_id, $student_id, $student_id, $start_date, $start_date, $end_date, $end_date]);
                $reports['exam_results'] = $stmt->fetchAll();
                break;

            case 'student_performance':
                // Generate student performance report
                if ($student_id) {
                    // Individual student performance
                    $stmt = $pdo->prepare("
                        SELECT 
                            s.full_name,
                            s.class,
                            s.admission_number,
                            COUNT(r.id) as total_exams,
                            AVG(r.percentage) as average_percentage,
                            MIN(r.percentage) as lowest_percentage,
                            MAX(r.percentage) as highest_percentage,
                            GROUP_CONCAT(DISTINCT sub.subject_name ORDER BY sub.subject_name) as subjects_taken
                        FROM students s
                        LEFT JOIN results r ON s.id = r.student_id
                        LEFT JOIN exams e ON r.exam_id = e.id
                        LEFT JOIN subjects sub ON e.subject_id = sub.id
                        WHERE s.id = ?
                        AND s.status = 'active'
                        GROUP BY s.id
                    ");
                    $stmt->execute([$student_id]);
                } else {
                    // All students performance
                    $stmt = $pdo->prepare("
                        SELECT 
                            s.full_name,
                            s.class,
                            s.admission_number,
                            COUNT(r.id) as total_exams,
                            AVG(r.percentage) as average_percentage,
                            MIN(r.percentage) as lowest_percentage,
                            MAX(r.percentage) as highest_percentage,
                            GROUP_CONCAT(DISTINCT sub.subject_name ORDER BY sub.subject_name) as subjects_taken
                        FROM students s
                        LEFT JOIN results r ON s.id = r.student_id
                        LEFT JOIN exams e ON r.exam_id = e.id
                        LEFT JOIN subjects sub ON e.subject_id = sub.id
                        WHERE s.status = 'active'
                        AND (? = '' OR s.class = ?)
                        GROUP BY s.id
                        ORDER BY average_percentage DESC
                    ");
                    $stmt->execute([$class, $class]);
                }
                $reports['student_performance'] = $stmt->fetchAll();
                break;

            case 'class_summary':
                // Generate class summary report
                $stmt = $pdo->prepare("
                    SELECT 
                        s.class,
                        COUNT(DISTINCT s.id) as total_students,
                        COUNT(DISTINCT r.id) as total_exams_taken,
                        AVG(r.percentage) as class_average,
                        SUM(CASE WHEN r.percentage >= 75 THEN 1 ELSE 0 END) as distinction_count,
                        SUM(CASE WHEN r.percentage >= 50 AND r.percentage < 75 THEN 1 ELSE 0 END) as credit_count,
                        SUM(CASE WHEN r.percentage >= 40 AND r.percentage < 50 THEN 1 ELSE 0 END) as pass_count,
                        SUM(CASE WHEN r.percentage < 40 THEN 1 ELSE 0 END) as fail_count
                    FROM students s
                    LEFT JOIN results r ON s.id = r.student_id
                    WHERE s.status = 'active'
                    AND (? = '' OR s.class = ?)
                    GROUP BY s.class
                    ORDER BY s.class
                ");

                $stmt->execute([$class, $class]);
                $reports['class_summary'] = $stmt->fetchAll();
                break;

            case 'subject_analysis':
                // Generate subject analysis report - FIXED: removed sub.class reference
                $stmt = $pdo->prepare("
                    SELECT 
                        sub.subject_name,
                        COUNT(DISTINCT e.id) as total_exams,
                        COUNT(DISTINCT r.id) as total_attempts,
                        AVG(r.percentage) as subject_average,
                        MIN(r.percentage) as lowest_score,
                        MAX(r.percentage) as highest_score,
                        COUNT(DISTINCT s.id) as total_students
                    FROM subjects sub
                    LEFT JOIN exams e ON sub.id = e.subject_id
                    LEFT JOIN results r ON e.id = r.exam_id
                    LEFT JOIN students s ON r.student_id = s.id
                    WHERE (? = '' OR sub.id = ?)
                    GROUP BY sub.id
                    ORDER BY sub.subject_name
                ");

                $stmt->execute([$subject_id, $subject_id]);
                $reports['subject_analysis'] = $stmt->fetchAll();
                break;

            case 'activity_logs':
                // Generate activity logs report - FIXED: handle null values
                $stmt = $pdo->prepare("
                    SELECT 
                        al.*,
                        CASE 
                            WHEN al.user_type = 'student' THEN COALESCE(s.full_name, 'Unknown Student')
                            WHEN al.user_type = 'staff' THEN COALESCE(st.full_name, 'Unknown Staff')
                            WHEN al.user_type = 'admin' THEN COALESCE(a.full_name, 'Unknown Admin')
                            ELSE 'Unknown User'
                        END as user_name,
                        CASE
                            WHEN al.user_type = 'student' THEN COALESCE(s.admission_number, 'N/A')
                            WHEN al.user_type = 'staff' THEN COALESCE(st.staff_id, 'N/A')
                            WHEN al.user_type = 'admin' THEN COALESCE(a.username, 'N/A')
                            ELSE 'N/A'
                        END as user_identifier
                    FROM activity_logs al
                    LEFT JOIN students s ON al.user_id = s.id AND al.user_type = 'student'
                    LEFT JOIN staff st ON al.user_id = st.id AND al.user_type = 'staff'
                    LEFT JOIN admin_users a ON al.user_id = a.id AND al.user_type = 'admin'
                    WHERE (? = '' OR DATE(al.created_at) >= ?)
                    AND (? = '' OR DATE(al.created_at) <= ?)
                    ORDER BY al.created_at DESC
                    LIMIT 1000
                ");

                $stmt->execute([$start_date, $start_date, $end_date, $end_date]);
                $reports['activity_logs'] = $stmt->fetchAll();
                break;
        }

        $success_message = "Report generated successfully!";
    } catch (Exception $e) {
        error_log("Report generation error: " . $e->getMessage());
        $error_message = "Error generating report: " . $e->getMessage();
    }
}

// Get available classes, sessions, terms, and subjects for filters
try {
    $stmt = $pdo->query("SELECT DISTINCT class FROM students WHERE status = 'active' ORDER BY class");
    $classes = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT DISTINCT session FROM student_scores ORDER BY session DESC");
    $sessions = $stmt->fetchAll();

    // FIXED: Get subjects with proper class information
    $stmt = $pdo->prepare("
        SELECT s.id, s.subject_name, sc.class 
        FROM subjects s
        LEFT JOIN subject_classes sc ON s.id = sc.subject_id
        ORDER BY sc.class, s.subject_name
    ");
    $stmt->execute();
    $subjects = $stmt->fetchAll();

    // Get students for individual student filter
    $stmt = $pdo->query("SELECT id, full_name, admission_number, class FROM students WHERE status = 'active' ORDER BY full_name");
    $students = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching filter data: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Digital CBT System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- jsPDF and html2canvas for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    <style>
        /* Inherit all styles from index.php */
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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning-color);
        }

        /* Report Generator Styles */
        .report-generator {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
        }

        .report-generator h2 {
            color: var(--primary-color);
            margin-bottom: 25px;
            font-size: 1.5rem;
            border-bottom: 2px solid var(--light-color);
            padding-bottom: 15px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .btn {
            padding: 12px 25px;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.95rem;
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
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #219653);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }

        /* Report Display Styles */
        .report-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
        }

        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
        }

        .report-header h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
        }

        .report-actions {
            display: flex;
            gap: 10px;
        }

        .report-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .summary-card {
            text-align: center;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .summary-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .summary-label {
            font-size: 0.9rem;
            color: #666;
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            margin-bottom: 20px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .data-table th {
            background: var(--primary-color);
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }

        .data-table tr:nth-child(even) {
            background: #f9f9f9;
        }

        .data-table tr:hover {
            background: #f0f7ff;
        }

        .percentage-cell {
            font-weight: 600;
        }

        .percentage-cell.high {
            color: var(--success-color);
        }

        .percentage-cell.medium {
            color: var(--warning-color);
        }

        .percentage-cell.low {
            color: var(--danger-color);
        }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 400px;
            margin: 30px 0;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        /* No Data Message */
        .no-data {
            text-align: center;
            padding: 50px;
            color: #666;
            font-size: 1.1rem;
        }

        .no-data i {
            font-size: 3rem;
            margin-bottom: 20px;
            color: #ddd;
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .report-summary {
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

        /* Loading Spinner */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Export Options */
        .export-options {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        /* Print Styles */
        @media print {

            .sidebar,
            .top-header,
            .report-generator,
            .report-actions,
            .no-print {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }

            .report-container {
                box-shadow: none !important;
                padding: 0 !important;
            }

            .data-table {
                font-size: 12px !important;
            }

            .data-table th {
                background: #333 !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar (Same as index.php) -->
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
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="reports.php" class="active"><i class="fas fa-chart-line"></i> Reports</a></li>
            <!-- <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li> -->
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>Reports Dashboard</h1>
                <p>Generate and analyze system reports</p>
            </div>
            <div class="header-actions">
                <button class="logout-btn" onclick="window.location.href='../logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
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
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Report Generator -->
        <div class="report-generator">
            <h2>Generate Report</h2>
            <form method="POST" id="reportForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="report_type"><i class="fas fa-chart-bar"></i> Report Type</label>
                        <select name="report_type" id="report_type" class="form-control" required>
                            <option value="">Select Report Type</option>
                            <option value="exam_results" <?php echo (isset($_POST['report_type']) && $_POST['report_type'] == 'exam_results') ? 'selected' : ''; ?>>Exam Results</option>
                            <option value="student_performance" <?php echo (isset($_POST['report_type']) && $_POST['report_type'] == 'student_performance') ? 'selected' : ''; ?>>Student Performance</option>
                            <option value="class_summary" <?php echo (isset($_POST['report_type']) && $_POST['report_type'] == 'class_summary') ? 'selected' : ''; ?>>Class Summary</option>
                            <option value="subject_analysis" <?php echo (isset($_POST['report_type']) && $_POST['report_type'] == 'subject_analysis') ? 'selected' : ''; ?>>Subject Analysis</option>
                            <option value="activity_logs" <?php echo (isset($_POST['report_type']) && $_POST['report_type'] == 'activity_logs') ? 'selected' : ''; ?>>Activity Logs</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="class"><i class="fas fa-users"></i> Class</label>
                        <select name="class" id="class" class="form-control">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class['class']); ?>"
                                    <?php echo (isset($_POST['class']) && $_POST['class'] == $class['class']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="student_id"><i class="fas fa-user-graduate"></i> Student (Optional)</label>
                        <select name="student_id" id="student_id" class="form-control">
                            <option value="">All Students</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>"
                                    <?php echo (isset($_POST['student_id']) && $_POST['student_id'] == $student['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($student['full_name'] . ' (' . $student['admission_number'] . ' - ' . $student['class'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="subject_id"><i class="fas fa-book"></i> Subject</label>
                        <select name="subject_id" id="subject_id" class="form-control">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>"
                                    <?php echo (isset($_POST['subject_id']) && $_POST['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                                    <?php
                                    $subject_display = $subject['subject_name'];
                                    if (!empty($subject['class'])) {
                                        $subject_display .= ' (' . htmlspecialchars($subject['class']) . ')';
                                    }
                                    echo htmlspecialchars($subject_display);
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date"><i class="fas fa-calendar-alt"></i> Start Date</label>
                        <input type="date" name="start_date" id="start_date" class="form-control"
                            value="<?php echo isset($_POST['start_date']) ? htmlspecialchars($_POST['start_date']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="end_date"><i class="fas fa-calendar-alt"></i> End Date</label>
                        <input type="date" name="end_date" id="end_date" class="form-control"
                            value="<?php echo isset($_POST['end_date']) ? htmlspecialchars($_POST['end_date']) : ''; ?>">
                    </div>
                </div>

                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-chart-line"></i> Generate Report
                    </button>
                    <button type="reset" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Report Display Area -->
        <?php if (!empty($reports)): ?>
            <?php foreach ($reports as $report_type => $data): ?>
                <?php if (!empty($data)): ?>
                    <div class="report-container" id="report-container-<?php echo $report_type; ?>">
                        <div class="report-header">
                            <h3>
                                <?php
                                $titles = [
                                    'exam_results' => 'Exam Results Report',
                                    'student_performance' => 'Student Performance Report',
                                    'class_summary' => 'Class Summary Report',
                                    'subject_analysis' => 'Subject Analysis Report',
                                    'activity_logs' => 'Activity Logs Report'
                                ];
                                echo $titles[$report_type] ?? 'Report';
                                ?>
                            </h3>
                            <div class="report-actions">
                                <button class="btn btn-success" onclick="exportToExcel('<?php echo $report_type; ?>')">
                                    <i class="fas fa-file-excel"></i> Export Excel
                                </button>
                                <button class="btn btn-secondary" onclick="printReport()">
                                    <i class="fas fa-print"></i> Print
                                </button>
                                <button class="btn btn-primary" onclick="downloadPDF('<?php echo $report_type; ?>')">
                                    <i class="fas fa-file-pdf"></i> PDF
                                </button>
                            </div>
                        </div>

                        <!-- Report Summary -->
                        <div class="report-summary">
                            <?php if ($report_type === 'student_performance'): ?>
                                <div class="summary-card">
                                    <div class="summary-value"><?php echo count($data); ?></div>
                                    <div class="summary-label">Total Students</div>
                                </div>
                                <div class="summary-card">
                                    <div class="summary-value">
                                        <?php
                                        $avg_percentage = array_column($data, 'average_percentage');
                                        $avg_percentage = array_filter($avg_percentage, function ($val) {
                                            return $val !== null;
                                        });
                                        echo count($avg_percentage) > 0 ? number_format(array_sum($avg_percentage) / count($avg_percentage), 2) : '0';
                                        ?>%
                                    </div>
                                    <div class="summary-label">Average Performance</div>
                                </div>
                            <?php elseif ($report_type === 'class_summary'): ?>
                                <div class="summary-card">
                                    <div class="summary-value"><?php echo count($data); ?></div>
                                    <div class="summary-label">Classes Analyzed</div>
                                </div>
                                <div class="summary-card">
                                    <div class="summary-value">
                                        <?php echo array_sum(array_column($data, 'total_students')); ?>
                                    </div>
                                    <div class="summary-label">Total Students</div>
                                </div>
                            <?php elseif ($report_type === 'exam_results'): ?>
                                <div class="summary-card">
                                    <div class="summary-value"><?php echo count($data); ?></div>
                                    <div class="summary-label">Exam Attempts</div>
                                </div>
                                <div class="summary-card">
                                    <div class="summary-value">
                                        <?php
                                        $percentages = array_column($data, 'percentage');
                                        $percentages = array_filter($percentages, function ($val) {
                                            return $val !== null;
                                        });
                                        echo count($percentages) > 0 ? number_format(array_sum($percentages) / count($percentages), 2) : 0;
                                        ?>%
                                    </div>
                                    <div class="summary-label">Average Score</div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Report Data Table -->
                        <div class="table-container">
                            <table class="data-table" id="<?php echo $report_type; ?>Table">
                                <thead>
                                    <?php if ($report_type === 'exam_results'): ?>
                                        <tr>
                                            <th>Student Name</th>
                                            <th>Class</th>
                                            <th>Exam Name</th>
                                            <th>Subject</th>
                                            <th>Score</th>
                                            <th>Percentage</th>
                                            <th>Grade</th>
                                            <th>Date</th>
                                        </tr>
                                    <?php elseif ($report_type === 'student_performance'): ?>
                                        <tr>
                                            <th>Student Name</th>
                                            <th>Class</th>
                                            <th>Admission No.</th>
                                            <th>Total Exams</th>
                                            <th>Average %</th>
                                            <th>Lowest %</th>
                                            <th>Highest %</th>
                                            <th>Subjects</th>
                                        </tr>
                                    <?php elseif ($report_type === 'class_summary'): ?>
                                        <tr>
                                            <th>Class</th>
                                            <th>Total Students</th>
                                            <th>Exams Taken</th>
                                            <th>Class Average</th>
                                            <th>Distinction (75%+)</th>
                                            <th>Credit (50-74%)</th>
                                            <th>Pass (40-49%)</th>
                                            <th>Fail (<40%)< /th>
                                        </tr>
                                    <?php elseif ($report_type === 'subject_analysis'): ?>
                                        <tr>
                                            <th>Subject</th>
                                            <th>Total Exams</th>
                                            <th>Total Attempts</th>
                                            <th>Average Score</th>
                                            <th>Lowest Score</th>
                                            <th>Highest Score</th>
                                            <th>Total Students</th>
                                        </tr>
                                    <?php elseif ($report_type === 'activity_logs'): ?>
                                        <tr>
                                            <th>User</th>
                                            <th>User ID</th>
                                            <th>User Type</th>
                                            <th>Activity</th>
                                            <th>IP Address</th>
                                            <th>Timestamp</th>
                                        </tr>
                                    <?php endif; ?>
                                </thead>
                                <tbody>
                                    <?php foreach ($data as $row): ?>
                                        <tr>
                                            <?php if ($report_type === 'exam_results'): ?>
                                                <td><?php echo htmlspecialchars($row['student_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['class'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['exam_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['subject_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['total_score'] ?? '0'); ?></td>
                                                <td class="percentage-cell <?php
                                                                            $percentage = $row['percentage'] ?? 0;
                                                                            if ($percentage >= 75) echo 'high';
                                                                            elseif ($percentage >= 50) echo 'medium';
                                                                            else echo 'low';
                                                                            ?>">
                                                    <?php echo number_format($percentage, 2); ?>%
                                                </td>
                                                <td><?php echo htmlspecialchars($row['grade'] ?? 'N/A'); ?></td>
                                                <td><?php echo isset($row['submitted_at']) ? date('M d, Y', strtotime($row['submitted_at'])) : 'N/A'; ?></td>
                                            <?php elseif ($report_type === 'student_performance'): ?>
                                                <td><?php echo htmlspecialchars($row['full_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['class'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['admission_number'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['total_exams'] ?? '0'); ?></td>
                                                <td class="percentage-cell <?php
                                                                            $avg = $row['average_percentage'] ?? 0;
                                                                            if ($avg >= 75) echo 'high';
                                                                            elseif ($avg >= 50) echo 'medium';
                                                                            else echo 'low';
                                                                            ?>">
                                                    <?php echo number_format($avg, 2); ?>%
                                                </td>
                                                <td><?php echo number_format($row['lowest_percentage'] ?? 0, 2); ?>%</td>
                                                <td><?php echo number_format($row['highest_percentage'] ?? 0, 2); ?>%</td>
                                                <td><?php echo htmlspecialchars($row['subjects_taken'] ?? 'N/A'); ?></td>
                                            <?php elseif ($report_type === 'class_summary'): ?>
                                                <td><?php echo htmlspecialchars($row['class'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['total_students'] ?? '0'); ?></td>
                                                <td><?php echo htmlspecialchars($row['total_exams_taken'] ?? '0'); ?></td>
                                                <td class="percentage-cell <?php
                                                                            $avg = $row['class_average'] ?? 0;
                                                                            if ($avg >= 75) echo 'high';
                                                                            elseif ($avg >= 50) echo 'medium';
                                                                            else echo 'low';
                                                                            ?>">
                                                    <?php echo number_format($avg, 2); ?>%
                                                </td>
                                                <td><?php echo htmlspecialchars($row['distinction_count'] ?? '0'); ?></td>
                                                <td><?php echo htmlspecialchars($row['credit_count'] ?? '0'); ?></td>
                                                <td><?php echo htmlspecialchars($row['pass_count'] ?? '0'); ?></td>
                                                <td><?php echo htmlspecialchars($row['fail_count'] ?? '0'); ?></td>
                                            <?php elseif ($report_type === 'subject_analysis'): ?>
                                                <td><?php echo htmlspecialchars($row['subject_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['total_exams'] ?? '0'); ?></td>
                                                <td><?php echo htmlspecialchars($row['total_attempts'] ?? '0'); ?></td>
                                                <td class="percentage-cell <?php
                                                                            $avg = $row['subject_average'] ?? 0;
                                                                            if ($avg >= 75) echo 'high';
                                                                            elseif ($avg >= 50) echo 'medium';
                                                                            else echo 'low';
                                                                            ?>">
                                                    <?php echo number_format($avg, 2); ?>%
                                                </td>
                                                <td><?php echo number_format($row['lowest_score'] ?? 0, 2); ?>%</td>
                                                <td><?php echo number_format($row['highest_score'] ?? 0, 2); ?>%</td>
                                                <td><?php echo htmlspecialchars($row['total_students'] ?? '0'); ?></td>
                                            <?php elseif ($report_type === 'activity_logs'): ?>
                                                <td><?php echo htmlspecialchars($row['user_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['user_identifier'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span style="text-transform: capitalize;">
                                                        <?php echo htmlspecialchars($row['user_type'] ?? 'N/A'); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($row['activity'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($row['ip_address'] ?? 'N/A'); ?></td>
                                                <td><?php echo isset($row['created_at']) ? date('M d, Y H:i:s', strtotime($row['created_at'])) : 'N/A'; ?></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Chart for visual representation -->
                        <?php if (in_array($report_type, ['student_performance', 'class_summary', 'subject_analysis']) && count($data) > 1): ?>
                            <div class="chart-container">
                                <canvas id="<?php echo $report_type; ?>Chart"></canvas>
                            </div>
                        <?php endif; ?>

                        <!-- Export Options -->
                        <div class="export-options no-print">
                            <div class="alert alert-warning">
                                <i class="fas fa-info-circle"></i>
                                Report generated on <?php echo date('F j, Y, g:i a'); ?> |
                                Total Records: <?php echo count($data); ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="report-container">
                        <div class="no-data">
                            <i class="fas fa-chart-line"></i>
                            <h3>No Data Available</h3>
                            <p>No records found for the selected criteria.</p>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <div class="report-container">
                <div class="no-data">
                    <i class="fas fa-chart-line"></i>
                    <h3>No Data Available</h3>
                    <p>No records found for the selected criteria.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="dashboard-footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?> - Reports Dashboard</p>
            <p style="margin-top: 5px; font-size: 0.8rem; color: #888;">
                Report generation powered by Digital CBT System
            </p>
        </div>
    </div>

    <script>
        // Mobile menu toggle (same as index.php)
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

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
            }
        });

        // Form validation
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            const reportType = document.getElementById('report_type').value;
            if (!reportType) {
                e.preventDefault();
                alert('Please select a report type');
                document.getElementById('report_type').focus();
                return false;
            }

            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;

            if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
                e.preventDefault();
                alert('Start date cannot be after end date');
                return false;
            }

            // Show loading indicator
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<span class="spinner"></span> Generating...';
            submitBtn.disabled = true;
        });

        // Export to Excel function
        function exportToExcel(tableId) {
            const table = document.getElementById(tableId + 'Table');
            if (!table) return;

            let csv = [];
            const rows = table.querySelectorAll('tr');

            for (let i = 0; i < rows.length; i++) {
                const row = [],
                    cols = rows[i].querySelectorAll('td, th');

                for (let j = 0; j < cols.length; j++) {
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/(\s\s)/gm, ' ');
                    data = data.replace(/"/g, '""');
                    row.push('"' + data + '"');
                }

                csv.push(row.join(','));
            }

            const csvString = csv.join('\n');
            const filename = tableId + '_report_' + new Date().toISOString().slice(0, 10) + '.csv';

            const blob = new Blob([csvString], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');

            if (navigator.msSaveBlob) {
                navigator.msSaveBlob(blob, filename);
            } else {
                link.href = URL.createObjectURL(blob);
                link.download = filename;
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }

            alert('Report exported as ' + filename);
        }

        // Print report function
        function printReport() {
            window.print();
        }

        // Download PDF function - FIXED
        async function downloadPDF(reportType) {
            try {
                const element = document.getElementById('report-container-' + reportType);
                if (!element) {
                    alert('Report container not found');
                    return;
                }

                // Show loading message
                const originalContent = element.innerHTML;
                element.innerHTML = '<div style="text-align: center; padding: 50px;"><div class="spinner"></div><p>Generating PDF...</p></div>';

                // Use html2canvas to capture the report
                const canvas = await html2canvas(element, {
                    scale: 2,
                    logging: false,
                    useCORS: true
                });

                // Restore original content
                element.innerHTML = originalContent;

                const imgData = canvas.toDataURL('image/png');
                const {
                    jsPDF
                } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4');
                const imgWidth = 210; // A4 width in mm
                const pageHeight = 297; // A4 height in mm
                const imgHeight = (canvas.height * imgWidth) / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;

                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;

                while (heightLeft > 0) {
                    position = heightLeft - imgHeight;
                    pdf.addPage();
                    pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }

                pdf.save(reportType + '_report_' + new Date().toISOString().slice(0, 10) + '.pdf');
                alert('PDF generated successfully!');
            } catch (error) {
                console.error('PDF generation error:', error);
                alert('Error generating PDF. Please try exporting as Excel instead.');
            }
        }

        // Initialize charts
        function initCharts() {
            <?php if (!empty($reports)): ?>
                <?php foreach ($reports as $report_type => $data): ?>
                    <?php if (!empty($data) && in_array($report_type, ['student_performance', 'class_summary', 'subject_analysis']) && count($data) > 1): ?>
                        const ctx<?php echo $report_type; ?> = document.getElementById('<?php echo $report_type; ?>Chart');
                        if (ctx<?php echo $report_type; ?>) {
                            const ctx2d = ctx<?php echo $report_type; ?>.getContext('2d');

                            <?php if ($report_type === 'student_performance'): ?>
                                const labels<?php echo $report_type; ?> = <?php echo json_encode(array_column($data, 'full_name')); ?>;
                                const averages<?php echo $report_type; ?> = <?php echo json_encode(array_column($data, 'average_percentage')); ?>;

                                new Chart(ctx2d, {
                                    type: 'bar',
                                    data: {
                                        labels: labels<?php echo $report_type; ?>,
                                        datasets: [{
                                            label: 'Average Percentage',
                                            data: averages<?php echo $report_type; ?>,
                                            backgroundColor: 'rgba(52, 152, 219, 0.6)',
                                            borderColor: 'rgba(52, 152, 219, 1)',
                                            borderWidth: 1
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        scales: {
                                            y: {
                                                beginAtZero: true,
                                                max: 100,
                                                title: {
                                                    display: true,
                                                    text: 'Percentage (%)'
                                                }
                                            },
                                            x: {
                                                title: {
                                                    display: true,
                                                    text: 'Students'
                                                }
                                            }
                                        },
                                        plugins: {
                                            title: {
                                                display: true,
                                                text: 'Student Performance Analysis'
                                            }
                                        }
                                    }
                                });

                            <?php elseif ($report_type === 'class_summary'): ?>
                                const labels<?php echo $report_type; ?> = <?php echo json_encode(array_column($data, 'class')); ?>;
                                const averages<?php echo $report_type; ?> = <?php echo json_encode(array_column($data, 'class_average')); ?>;

                                new Chart(ctx2d, {
                                    type: 'line',
                                    data: {
                                        labels: labels<?php echo $report_type; ?>,
                                        datasets: [{
                                            label: 'Class Average',
                                            data: averages<?php echo $report_type; ?>,
                                            backgroundColor: 'rgba(39, 174, 96, 0.2)',
                                            borderColor: 'rgba(39, 174, 96, 1)',
                                            borderWidth: 2,
                                            tension: 0.1,
                                            fill: true
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        scales: {
                                            y: {
                                                beginAtZero: true,
                                                max: 100,
                                                title: {
                                                    display: true,
                                                    text: 'Average Percentage (%)'
                                                }
                                            },
                                            x: {
                                                title: {
                                                    display: true,
                                                    text: 'Classes'
                                                }
                                            }
                                        },
                                        plugins: {
                                            title: {
                                                display: true,
                                                text: 'Class Performance Summary'
                                            }
                                        }
                                    }
                                });

                            <?php elseif ($report_type === 'subject_analysis'): ?>
                                const labels<?php echo $report_type; ?> = <?php echo json_encode(array_column($data, 'subject_name')); ?>;
                                const averages<?php echo $report_type; ?> = <?php echo json_encode(array_column($data, 'subject_average')); ?>;

                                new Chart(ctx2d, {
                                    type: 'radar',
                                    data: {
                                        labels: labels<?php echo $report_type; ?>,
                                        datasets: [{
                                            label: 'Subject Average',
                                            data: averages<?php echo $report_type; ?>,
                                            backgroundColor: 'rgba(241, 196, 15, 0.2)',
                                            borderColor: 'rgba(241, 196, 15, 1)',
                                            borderWidth: 2
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        scales: {
                                            r: {
                                                beginAtZero: true,
                                                max: 100,
                                                ticks: {
                                                    stepSize: 20
                                                }
                                            }
                                        },
                                        plugins: {
                                            title: {
                                                display: true,
                                                text: 'Subject Performance Analysis'
                                            }
                                        }
                                    }
                                });
                            <?php endif; ?>
                        }
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        }

        // Auto-set date ranges
        document.addEventListener('DOMContentLoaded', function() {
            // Set default end date to today if not set
            const endDateInput = document.getElementById('end_date');
            if (endDateInput && !endDateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                endDateInput.value = today;
            }

            // Set default start date to 30 days ago if not set
            const startDateInput = document.getElementById('start_date');
            if (startDateInput && !startDateInput.value) {
                const thirtyDaysAgo = new Date();
                thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
                startDateInput.value = thirtyDaysAgo.toISOString().split('T')[0];
            }

            // Initialize charts
            setTimeout(initCharts, 100);

            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+G for generate report
                if (e.ctrlKey && e.key === 'g') {
                    e.preventDefault();
                    document.getElementById('reportForm').submit();
                }

                // Ctrl+P for print
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    printReport();
                }

                // Ctrl+E for export
                if (e.ctrlKey && e.key === 'e') {
                    e.preventDefault();
                    const reportType = document.getElementById('report_type').value;
                    if (reportType) {
                        exportToExcel(reportType);
                    }
                }
            });
        });
    </script>
</body>

</html>