<?php
// staff/view-results.php - View Student Results
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

// Get filters from GET parameters
$selected_class = $_GET['class'] ?? '';
$selected_subject = $_GET['subject'] ?? '';
$selected_exam = $_GET['exam'] ?? '';
$selected_session = $_GET['session'] ?? date('Y') . '/' . (date('Y') + 1);
$selected_term = $_GET['term'] ?? 'First';
$search = $_GET['search'] ?? '';

// Initialize variables
$assigned_classes = [];
$assigned_subjects = [];
$results = [];
$summary_stats = null;
$error_message = '';
$success_message = '';

try {
    // Get staff assigned classes
    $stmt = $pdo->prepare("
        SELECT DISTINCT class 
        FROM staff_classes 
        WHERE staff_id = ?
        ORDER BY class
    ");
    $stmt->execute([$staff_id]);
    $assigned_classes = $stmt->fetchAll();

    // Get staff assigned subjects with names
    $stmt = $pdo->prepare("
        SELECT s.id, s.subject_name 
        FROM subjects s
        INNER JOIN staff_subjects ss ON s.id = ss.subject_id
        WHERE ss.staff_id = ?
        ORDER BY s.subject_name
    ");
    $stmt->execute([$staff_id]);
    $assigned_subjects = $stmt->fetchAll();

    // Get available sessions (last 3 years and next year)
    $current_year = date('Y');
    $sessions = [];
    for ($i = 2; $i >= 0; $i--) {
        $year = $current_year - $i;
        $sessions[] = $year . '/' . ($year + 1);
    }
    $sessions[] = $current_year . '/' . ($current_year + 1);

    // If form submitted, fetch results based on filters
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['filter']) || $search)) {
        // Validate that staff has access to selected class and subject
        $valid_class = false;
        $valid_subject = false;

        // Check class access
        foreach ($assigned_classes as $class) {
            if ($class['class'] == $selected_class) {
                $valid_class = true;
                break;
            }
        }

        // Check subject access
        foreach ($assigned_subjects as $subject) {
            if ($subject['id'] == $selected_subject) {
                $valid_subject = true;
                break;
            }
        }

        // Only proceed if staff has access to both class and subject
        if (($selected_class && !$valid_class) || ($selected_subject && !$valid_subject)) {
            $error_message = "You don't have access to the selected class or subject.";
        } else {
            // Build query based on filters
            $query_params = [];
            $where_clauses = [];

            // Base query
            $query = "
                SELECT 
                    r.*,
                    s.full_name,
                    s.class,
                    s.admission_number,
                    e.exam_name,
                    e.subject_id,
                    e.class as exam_class,
                    sub.subject_name,
                    es.score as objective_score,
                    es.percentage,
                    es.grade,
                    DATE_FORMAT(r.submitted_at, '%M %d, %Y %h:%i %p') as formatted_date,
                    TIME_FORMAT(SEC_TO_TIME(r.time_taken), '%H:%i:%s') as time_taken_formatted
                FROM results r
                INNER JOIN students s ON r.student_id = s.id
                INNER JOIN exams e ON r.exam_id = e.id
                INNER JOIN subjects sub ON e.subject_id = sub.id
                LEFT JOIN exam_sessions es ON r.student_id = es.student_id AND r.exam_id = es.exam_id
                WHERE s.status = 'active'
            ";

            // Add filters
            if ($selected_class && $valid_class) {
                $where_clauses[] = "s.class = ?";
                $query_params[] = $selected_class;
            } else {
                // Limit to staff's assigned classes
                if (!empty($assigned_classes)) {
                    $class_placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
                    $where_clauses[] = "s.class IN ($class_placeholders)";
                    $query_params = array_merge($query_params, array_column($assigned_classes, 'class'));
                }
            }

            if ($selected_subject && $valid_subject) {
                $where_clauses[] = "e.subject_id = ?";
                $query_params[] = $selected_subject;
            } else {
                // Limit to staff's assigned subjects
                if (!empty($assigned_subjects)) {
                    $subject_ids = array_column($assigned_subjects, 'id');
                    $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';
                    $where_clauses[] = "e.subject_id IN ($subject_placeholders)";
                    $query_params = array_merge($query_params, $subject_ids);
                }
            }

            if ($selected_exam) {
                $where_clauses[] = "e.exam_name LIKE ?";
                $query_params[] = "%$selected_exam%";
            }

            if ($search) {
                $where_clauses[] = "(s.full_name LIKE ? OR s.admission_number LIKE ?)";
                $query_params[] = "%$search%";
                $query_params[] = "%$search%";
            }

            // Add WHERE clauses if any
            if (!empty($where_clauses)) {
                $query .= " AND " . implode(" AND ", $where_clauses);
            }

            $query .= " ORDER BY r.submitted_at DESC, s.class, s.full_name";

            $stmt = $pdo->prepare($query);
            $stmt->execute($query_params);
            $results = $stmt->fetchAll();

            // Calculate summary statistics if we have results
            if (!empty($results)) {
                $total_students = count($results);
                $total_score = 0;
                $highest_score = 0;
                $lowest_score = 100;
                $grades_count = [
                    'A' => 0,
                    'B' => 0,
                    'C' => 0,
                    'D' => 0,
                    'E' => 0,
                    'F' => 0
                ];

                foreach ($results as $result) {
                    $percentage = $result['percentage'] ?? 0;
                    $total_score += $percentage;

                    if ($percentage > $highest_score) {
                        $highest_score = $percentage;
                    }

                    if ($percentage < $lowest_score) {
                        $lowest_score = $percentage;
                    }

                    $grade = $result['grade'] ?? 'F';
                    if (isset($grades_count[$grade])) {
                        $grades_count[$grade]++;
                    } else {
                        $grades_count['F']++;
                    }
                }

                $average_score = $total_students > 0 ? $total_score / $total_students : 0;

                $summary_stats = [
                    'total_students' => $total_students,
                    'average_score' => round($average_score, 2),
                    'highest_score' => round($highest_score, 2),
                    'lowest_score' => round($lowest_score, 2),
                    'grades_count' => $grades_count
                ];
            }
        }
    }

    // Get available exams for filter dropdown
    $available_exams = [];
    if (!empty($assigned_classes) && !empty($assigned_subjects)) {
        $class_placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
        $subject_ids = array_column($assigned_subjects, 'id');
        $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';

        $params = array_merge($subject_ids, array_column($assigned_classes, 'class'));

        $stmt = $pdo->prepare("
            SELECT DISTINCT exam_name 
            FROM exams 
            WHERE subject_id IN ($subject_placeholders)
            AND class IN ($class_placeholders)
            ORDER BY exam_name
        ");
        $stmt->execute($params);
        $available_exams = $stmt->fetchAll();
    }
} catch (Exception $e) {
    error_log("View results error: " . $e->getMessage());
    $error_message = "Error loading results data";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Results - Staff Portal</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Chart.js for statistics -->
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

        /* Sidebar Styles (copied from index.php) */
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
            overflow-x: hidden;
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

        .staff-info h4 {
            margin-bottom: 8px;
            font-size: 1.1rem;
            font-weight: 500;
        }

        .staff-info p {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .staff-role {
            display: inline-block;
            background: rgba(66, 153, 225, 0.3);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-top: 5px;
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

        .nav-links i {
            width: 22px;
            text-align: center;
            font-size: 1.1rem;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 25px;
            min-height: 100vh;
            transition: all 0.3s ease;
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
            border-left: 5px solid var(--accent-color);
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
        }

        .logout-btn {
            background: linear-gradient(135deg, var(--danger-color), #c53030);
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
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.2);
        }

        .logout-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(229, 62, 62, 0.3);
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
        }

        .filter-section h3 {
            color: var(--dark-color);
            margin-bottom: 20px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-section h3 i {
            color: var(--secondary-color);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-weight: 500;
            color: var(--dark-color);
            font-size: 0.95rem;
        }

        .form-control {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }

        .search-box {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-box input {
            padding-right: 45px;
            width: 100%;
        }

        .search-icon {
            position: absolute;
            right: 15px;
            color: #a0aec0;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            box-shadow: 0 4px 12px rgba(66, 153, 225, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(66, 153, 225, 0.3);
        }

        .btn-secondary {
            background: white;
            color: var(--secondary-color);
            border: 2px solid var(--secondary-color);
        }

        .btn-secondary:hover {
            background: var(--light-color);
        }

        .btn-icon {
            font-size: 1.2rem;
        }

        /* Results Section */
        .results-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 25px;
            position: relative;
        }

        .results-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 15px 15px 0 0;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--light-color);
        }

        .section-header h3 {
            color: var(--dark-color);
            font-size: 1.4rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-header h3 i {
            color: var(--secondary-color);
        }

        .action-buttons {
            display: flex;
            gap: 15px;
        }

        /* Statistics Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #f7fafc, #edf2f7);
            padding: 25px;
            border-radius: 12px;
            border-left: 5px solid;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .stat-card.total {
            border-left-color: var(--primary-color);
        }

        .stat-card.average {
            border-left-color: var(--success-color);
        }

        .stat-card.highest {
            border-left-color: var(--accent-color);
        }

        .stat-card.lowest {
            border-left-color: var(--danger-color);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--dark-color);
        }

        .stat-label {
            font-size: 1rem;
            color: #718096;
            font-weight: 500;
        }

        .stat-subtext {
            font-size: 0.9rem;
            color: #a0aec0;
            margin-top: 5px;
        }

        /* Results Table */
        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid var(--light-color);
            margin-bottom: 30px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
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

        .grade-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
            min-width: 40px;
            text-align: center;
        }

        .grade-A {
            background: #c6f6d5;
            color: var(--success-color);
            border: 2px solid #9ae6b4;
        }

        .grade-B {
            background: #fed7e2;
            color: #d53f8c;
            border: 2px solid #fbb6ce;
        }

        .grade-C {
            background: #feebc8;
            color: var(--warning-color);
            border: 2px solid #fbd38d;
        }

        .grade-D {
            background: #e9d8fd;
            color: #9f7aea;
            border: 2px solid #d6bcfa;
        }

        .grade-E,
        .grade-F {
            background: #fed7d7;
            color: var(--danger-color);
            border: 2px solid #fc8181;
        }

        .score-cell {
            font-weight: 600;
            text-align: center;
        }

        .actions-cell {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            padding: 6px 12px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }

        .action-btn.view {
            background: var(--light-color);
            color: var(--secondary-color);
        }

        .action-btn.view:hover {
            background: #bee3f8;
        }

        .action-btn.print {
            background: #feebc8;
            color: var(--warning-color);
        }

        .action-btn.print:hover {
            background: #fbd38d;
        }

        /* No Results */
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #a0aec0;
        }

        .no-results i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .no-results h4 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: #718096;
        }

        .no-results p {
            font-size: 1.1rem;
            margin-bottom: 25px;
        }

        /* Chart Container */
        .chart-container {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 25px;
            height: 400px;
            position: relative;
        }

        .chart-title {
            color: var(--dark-color);
            margin-bottom: 20px;
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Export Options */
        .export-options {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        /* Responsive Design */
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

            .filter-form {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .stats-cards {
                grid-template-columns: 1fr;
            }

            .data-table {
                min-width: 800px;
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
                transition: all 0.3s ease;
            }

            .mobile-menu-btn:hover {
                transform: scale(1.1);
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        /* Alert Messages */
        .alert {
            padding: 18px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 1rem;
            border-left: 5px solid;
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

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 40px;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--secondary-color);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar (copied from index.php) -->
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
                <li><a href="view-results.php" class="active"><i class="fas fa-chart-bar"></i> View Results</a></li>
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
                <h1>View Results</h1>
                <p>View and analyze student exam results for your assigned classes</p>
            </div>
            <div class="header-actions">
                <button class="logout-btn" onclick="window.location.href='../logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="filter-section">
            <h3><i class="fas fa-filter"></i> Filter Results</h3>
            <form method="GET" action="view-results.php" id="filterForm">
                <div class="filter-form">
                    <div class="form-group">
                        <label for="class"><i class="fas fa-users-class"></i> Class</label>
                        <select name="class" id="class" class="form-control">
                            <option value="">All Classes</option>
                            <?php foreach ($assigned_classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class['class']); ?>"
                                    <?php echo ($selected_class == $class['class']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="subject"><i class="fas fa-book"></i> Subject</label>
                        <select name="subject" id="subject" class="form-control">
                            <option value="">All Subjects</option>
                            <?php foreach ($assigned_subjects as $subject): ?>
                                <option value="<?php echo htmlspecialchars($subject['id']); ?>"
                                    <?php echo ($selected_subject == $subject['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="exam"><i class="fas fa-file-alt"></i> Exam</label>
                        <select name="exam" id="exam" class="form-control">
                            <option value="">All Exams</option>
                            <?php foreach ($available_exams as $exam): ?>
                                <option value="<?php echo htmlspecialchars($exam['exam_name']); ?>"
                                    <?php echo ($selected_exam == $exam['exam_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($exam['exam_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group search-box">
                        <label for="search"><i class="fas fa-search"></i> Search Student</label>
                        <input type="text" name="search" id="search" class="form-control"
                            placeholder="Name or Admission Number" value="<?php echo htmlspecialchars($search); ?>">
                        <i class="fas fa-search search-icon"></i>
                    </div>

                    <div class="form-group">
                        <button type="submit" name="filter" class="btn btn-primary">
                            <i class="fas fa-filter btn-icon"></i> Apply Filters
                        </button>
                        <button type="button" onclick="resetFilters()" class="btn btn-secondary" style="margin-top: 10px;">
                            <i class="fas fa-redo btn-icon"></i> Reset
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Results Section -->
        <div class="results-section">
            <div class="section-header">
                <h3><i class="fas fa-chart-bar"></i> Exam Results</h3>
                <div class="action-buttons">
                    <button type="button" class="btn btn-primary" onclick="printResults()">
                        <i class="fas fa-print"></i> Print Results
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="exportToCSV()">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="exportToExcel()">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                </div>
            </div>

            <!-- Loading Spinner -->
            <div class="loading-spinner" id="loadingSpinner">
                <div class="spinner"></div>
                <p>Loading results...</p>
            </div>

            <?php if (empty($results) && isset($_GET['filter'])): ?>
                <div class="no-results">
                    <i class="fas fa-chart-line"></i>
                    <h4>No Results Found</h4>
                    <p>No exam results match your current filters.</p>
                    <button onclick="resetFilters()" class="btn btn-primary">
                        <i class="fas fa-redo"></i> Reset Filters
                    </button>
                </div>
            <?php elseif (!empty($results)): ?>
                <!-- Statistics Cards -->
                <?php if ($summary_stats): ?>
                    <div class="stats-cards">
                        <div class="stat-card total">
                            <div class="stat-value"><?php echo $summary_stats['total_students']; ?></div>
                            <div class="stat-label">Total Students</div>
                            <div class="stat-subtext">Students who took the exam</div>
                        </div>

                        <div class="stat-card average">
                            <div class="stat-value"><?php echo $summary_stats['average_score']; ?>%</div>
                            <div class="stat-label">Average Score</div>
                            <div class="stat-subtext">Class average performance</div>
                        </div>

                        <div class="stat-card highest">
                            <div class="stat-value"><?php echo $summary_stats['highest_score']; ?>%</div>
                            <div class="stat-label">Highest Score</div>
                            <div class="stat-subtext">Top performing student</div>
                        </div>

                        <div class="stat-card lowest">
                            <div class="stat-value"><?php echo $summary_stats['lowest_score']; ?>%</div>
                            <div class="stat-label">Lowest Score</div>
                            <div class="stat-subtext">Lowest score in class</div>
                        </div>
                    </div>

                    <!-- Grade Distribution Chart -->
                    <div class="chart-container">
                        <div class="chart-title">
                            <i class="fas fa-chart-pie"></i> Grade Distribution
                        </div>
                        <canvas id="gradeChart"></canvas>
                    </div>
                <?php endif; ?>

                <!-- Results Table -->
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student Name</th>
                                <th>Admission No.</th>
                                <th>Class</th>
                                <th>Exam</th>
                                <th>Subject</th>
                                <th>Score</th>
                                <th>Percentage</th>
                                <th>Grade</th>
                                <th>Date</th>
                                <th>Time Taken</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $index => $result):
                                $percentage = $result['percentage'] ?? 0;
                                $grade = $result['grade'] ?? 'F';
                                $grade_class = 'grade-' . strtoupper(substr($grade, 0, 1));
                            ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($result['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($result['admission_number']); ?></td>
                                    <td><?php echo htmlspecialchars($result['class']); ?></td>
                                    <td><?php echo htmlspecialchars($result['exam_name']); ?></td>
                                    <td><?php echo htmlspecialchars($result['subject_name']); ?></td>
                                    <td class="score-cell">
                                        <?php echo $result['objective_score'] ?? '0'; ?>/100
                                    </td>
                                    <td class="score-cell">
                                        <span style="font-weight: 600; color: <?php
                                                                                echo ($percentage >= 70) ? 'var(--success-color)' : (($percentage >= 50) ? 'var(--warning-color)' : 'var(--danger-color)');
                                                                                ?>;">
                                            <?php echo number_format($percentage, 1); ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <span class="grade-badge <?php echo $grade_class; ?>">
                                            <?php echo $grade; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $result['formatted_date']; ?></td>
                                    <td><?php echo $result['time_taken_formatted']; ?></td>
                                    <td class="actions-cell">
                                        <button class="action-btn view" onclick="viewResultDetails(<?php echo $result['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button class="action-btn print" onclick="printIndividualResult(<?php echo $result['id']; ?>)">
                                            <i class="fas fa-print"></i> Print
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination would go here in a real implementation -->
                <div style="text-align: center; margin-top: 20px;">
                    <p>Showing <?php echo count($results); ?> results</p>
                </div>
            <?php elseif (!isset($_GET['filter'])): ?>
                <div class="no-results">
                    <i class="fas fa-chart-bar"></i>
                    <h4>No Filters Applied</h4>
                    <p>Use the filters above to view exam results for your classes.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Mobile menu toggle (same as index.php)
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');

        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            mobileMenuBtn.innerHTML = sidebar.classList.contains('active') ?
                '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
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

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });

        // Reset filters
        function resetFilters() {
            document.getElementById('class').value = '';
            document.getElementById('subject').value = '';
            document.getElementById('exam').value = '';
            document.getElementById('search').value = '';
            document.getElementById('filterForm').submit();
        }

        // Print results
        function printResults() {
            window.print();
        }

        // Export to CSV
        function exportToCSV() {
            showLoading(true);
            // In a real implementation, this would make an AJAX call to generate CSV
            alert('CSV export functionality would be implemented here');
            showLoading(false);
        }

        // Export to Excel
        function exportToExcel() {
            showLoading(true);
            // In a real implementation, this would make an AJAX call to generate Excel
            alert('Excel export functionality would be implemented here');
            showLoading(false);
        }

        // View result details
        function viewResultDetails(resultId) {
            showLoading(true);
            // In a real implementation, this would open a modal or redirect to details page
            window.location.href = `result-details.php?id=${resultId}`;
        }

        // Print individual result
        function printIndividualResult(resultId) {
            showLoading(true);
            // In a real implementation, this would open print view for individual result
            const printWindow = window.open(`print-result.php?id=${resultId}`, '_blank');
            printWindow.focus();
            showLoading(false);
        }

        // Show/hide loading spinner
        function showLoading(show) {
            const spinner = document.getElementById('loadingSpinner');
            spinner.style.display = show ? 'block' : 'none';
        }

        // Initialize grade distribution chart if we have summary stats
        <?php if ($summary_stats): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const ctx = document.getElementById('gradeChart').getContext('2d');
                const gradeData = <?php echo json_encode($summary_stats['grades_count']); ?>;

                // Filter out zero values for better visualization
                const labels = [];
                const data = [];
                const backgroundColors = [
                    '#38a169', // A - green
                    '#d53f8c', // B - pink
                    '#d69e2e', // C - yellow
                    '#9f7aea', // D - purple
                    '#e53e3e', // E/F - red
                ];

                let index = 0;
                for (const [grade, count] of Object.entries(gradeData)) {
                    if (count > 0) {
                        labels.push(`Grade ${grade}`);
                        data.push(count);
                    }
                    index++;
                }

                new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: data,
                            backgroundColor: backgroundColors.slice(0, labels.length),
                            borderColor: 'white',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return `${label}: ${value} students (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            });
        <?php endif; ?>

        // Auto-submit form when filters change (optional)
        document.getElementById('class').addEventListener('change', function() {
            if (this.value) {
                document.getElementById('filterForm').submit();
            }
        });

        document.getElementById('subject').addEventListener('change', function() {
            if (this.value) {
                document.getElementById('filterForm').submit();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F to focus on search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('search').focus();
            }

            // Ctrl+R to reset filters
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                resetFilters();
            }

            // Ctrl+P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printResults();
            }
        });

        // Form submission handler
        document.getElementById('filterForm').addEventListener('submit', function(e) {
            showLoading(true);
            // Allow form to submit normally
        });
    </script>
</body>

</html>