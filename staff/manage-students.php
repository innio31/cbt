<?php
// staff/manage-students.php - Staff Manage Students
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

// Get staff assigned classes
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT class 
        FROM staff_classes 
        WHERE staff_id = ? 
        ORDER BY class
    ");
    $stmt->execute([$staff_id]);
    $assigned_classes = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Staff classes error: " . $e->getMessage());
    $error_message = "Error loading assigned classes: " . $e->getMessage();
}

// Initialize variables
$class_filter = $_GET['class'] ?? '';
$search_query = $_GET['search'] ?? '';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// If no classes assigned, still show the page but with limited functionality
if (empty($assigned_classes)) {
    $students = [];
    $total_students = 0;
    $total_pages = 0;
    $performance_data = [];
} else {
    // Build WHERE conditions based on assigned classes
    $where_conditions = ["s.status = 'active'"];
    $params = [];

    // Apply class filter
    if ($class_filter && in_array($class_filter, array_column($assigned_classes, 'class'))) {
        $where_conditions[] = "s.class = ?";
        $params[] = $class_filter;
    } else {
        // If no specific class selected, show all assigned classes
        $class_placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
        $where_conditions[] = "s.class IN ($class_placeholders)";
        $params = array_merge($params, array_column($assigned_classes, 'class'));
    }

    // Apply search filter
    if (!empty($search_query)) {
        $where_conditions[] = "(s.full_name LIKE ? OR s.admission_number LIKE ?)";
        $search_param = "%$search_query%";
        $params[] = $search_param;
        $params[] = $search_param;
    }

    // Build complete WHERE clause
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

    // Get total count
    try {
        $count_sql = "SELECT COUNT(*) as total FROM students s $where_clause";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_students = $stmt->fetch()['total'];
        $total_pages = ceil($total_students / $limit);
    } catch (Exception $e) {
        error_log("Count error: " . $e->getMessage());
        $total_students = 0;
        $total_pages = 0;
    }

    // Get students with pagination - FIXED: Use only positional parameters
    try {
        $sql = "
            SELECT s.*, 
                   (SELECT COUNT(*) FROM exam_sessions es WHERE es.student_id = s.id AND es.status = 'completed') as total_exams,
                   (SELECT COUNT(*) FROM assignment_submissions asm WHERE asm.student_id = s.id) as total_assignments,
                   (SELECT AVG(percentage) FROM results r WHERE r.student_id = s.id) as avg_score
            FROM students s 
            $where_clause 
            ORDER BY s.class, s.full_name 
            LIMIT ? OFFSET ?
        ";

        // Add pagination parameters as integers
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);

        // Bind all parameters with explicit types
        foreach ($params as $key => $value) {
            // Determine parameter type
            $param_type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key + 1, $value, $param_type);
        }

        $stmt->execute();
        $students = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Students query error: " . $e->getMessage());
        $students = [];
        $error_message = "Error loading students: " . $e->getMessage();
    }

    // Get student performance data for charts
    $performance_data = [];
    if (!empty($students)) {
        try {
            $student_ids = array_column($students, 'id');
            $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';

            // Get recent exam scores for performance chart
            $stmt = $pdo->prepare("
                SELECT r.student_id, r.exam_id, e.exam_name, r.percentage, r.submitted_at
                FROM results r
                JOIN exams e ON r.exam_id = e.id
                WHERE r.student_id IN ($placeholders)
                AND e.class IN (SELECT class FROM staff_classes WHERE staff_id = ?)
                ORDER BY r.submitted_at DESC
                LIMIT 50
            ");
            $performance_params = array_merge($student_ids, [$staff_id]);
            $stmt->execute($performance_params);
            $performance_records = $stmt->fetchAll();

            // Group by student for chart data
            foreach ($performance_records as $record) {
                if (!isset($performance_data[$record['student_id']])) {
                    $performance_data[$record['student_id']] = [];
                }
                $performance_data[$record['student_id']][] = $record;
            }
        } catch (Exception $e) {
            error_log("Performance data error: " . $e->getMessage());
            $performance_data = [];
        }
    }
}

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['bulk_action']) && isset($_POST['selected_students'])) {
        $selected_students = $_POST['selected_students'];
        $action = $_POST['bulk_action'];

        if (!empty($selected_students)) {
            try {
                $placeholders = str_repeat('?,', count($selected_students) - 1) . '?';

                switch ($action) {
                    case 'export':
                        // Generate CSV for selected students
                        $stmt = $pdo->prepare("
                            SELECT s.admission_number, s.full_name, s.class, 
                                   COUNT(es.id) as total_exams,
                                   AVG(r.percentage) as avg_score
                            FROM students s
                            LEFT JOIN exam_sessions es ON s.id = es.student_id AND es.status = 'completed'
                            LEFT JOIN results r ON s.id = r.student_id
                            WHERE s.id IN ($placeholders)
                            GROUP BY s.id
                        ");
                        $stmt->execute($selected_students);
                        $export_data = $stmt->fetchAll();

                        // Generate CSV
                        $filename = "students_export_" . date('Y-m-d') . ".csv";
                        header('Content-Type: text/csv');
                        header('Content-Disposition: attachment; filename="' . $filename . '"');

                        $output = fopen('php://output', 'w');
                        fputcsv($output, ['Admission No', 'Full Name', 'Class', 'Exams Taken', 'Average Score']);

                        foreach ($export_data as $row) {
                            fputcsv($output, [
                                $row['admission_number'],
                                $row['full_name'],
                                $row['class'],
                                $row['total_exams'],
                                round($row['avg_score'] ?? 0, 2)
                            ]);
                        }
                        fclose($output);
                        exit();

                    case 'message':
                        $_SESSION['selected_students'] = $selected_students;
                        header("Location: send-message.php");
                        exit();

                    case 'assign_exam':
                        $_SESSION['selected_students'] = $selected_students;
                        header("Location: assign-exam.php");
                        exit();
                }

                $_SESSION['message'] = "Bulk action completed successfully";
                $_SESSION['message_type'] = "success";
                header("Location: manage-students.php");
                exit();
            } catch (Exception $e) {
                $error_message = "Error performing bulk action: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - Digital CBT System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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

        /* Reuse sidebar styles from index.php */
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

        /* Filters and Actions */
        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            font-weight: 600;
            color: var(--dark-color);
            font-size: 0.95rem;
        }

        .filter-select,
        .filter-input {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .filter-select:focus,
        .filter-input:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(66, 153, 225, 0.3);
        }

        .btn-secondary {
            background: var(--light-color);
            color: var(--dark-color);
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .bulk-select {
            transform: scale(1.2);
        }

        .bulk-select-label {
            font-weight: 600;
            color: var(--dark-color);
            margin-right: 10px;
        }

        /* Students Table */
        .students-table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            margin-bottom: 25px;
        }

        .table-header {
            background: linear-gradient(135deg, var(--light-color), #e2e8f0);
            padding: 20px 25px;
            border-bottom: 2px solid #cbd5e0;
        }

        .table-header h3 {
            color: var(--dark-color);
            font-size: 1.3rem;
            font-weight: 600;
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
        }

        .students-table th {
            padding: 18px 20px;
            text-align: left;
            font-weight: 600;
            color: var(--dark-color);
            border-bottom: 2px solid #e2e8f0;
            background: #f8fafc;
        }

        .students-table td {
            padding: 18px 20px;
            border-bottom: 1px solid #e2e8f0;
            color: #4a5568;
        }

        .students-table tr:hover {
            background: #f7fafc;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
        }

        .student-name {
            font-weight: 600;
            color: var(--dark-color);
        }

        .student-class {
            background: var(--light-color);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--dark-color);
        }

        .performance-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }

        .performance-excellent {
            background: #c6f6d5;
            color: var(--success-color);
        }

        .performance-good {
            background: #feebc8;
            color: var(--warning-color);
        }

        .performance-poor {
            background: #fed7d7;
            color: var(--danger-color);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 8px 12px;
            border-radius: 8px;
            border: none;
            background: var(--light-color);
            color: var(--dark-color);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9rem;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .action-btn.view {
            background: #bee3f8;
            color: var(--secondary-color);
        }

        .action-btn.exam {
            background: #fed7d7;
            color: var(--danger-color);
        }

        .action-btn.message {
            background: #c6f6d5;
            color: var(--success-color);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
        }

        .page-link {
            padding: 10px 16px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            color: var(--dark-color);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: var(--light-color);
            border-color: var(--secondary-color);
        }

        .page-link.active {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-color: transparent;
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border-top: 4px solid;
        }

        .stat-card.total {
            border-top-color: var(--primary-color);
        }

        .stat-card.exams {
            border-top-color: var(--warning-color);
        }

        .stat-card.performance {
            border-top-color: var(--success-color);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #718096;
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
            padding: 60px 20px;
            color: #a0aec0;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: var(--dark-color);
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

            .students-table {
                display: block;
                overflow-x: auto;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .bulk-actions {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .stats-cards {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        /* Checkbox styling */
        .student-checkbox {
            transform: scale(1.2);
            cursor: pointer;
        }

        /* Performance chart mini */
        .performance-chart-mini {
            width: 80px;
            height: 30px;
            position: relative;
        }

        .chart-bar {
            position: absolute;
            bottom: 0;
            background: var(--secondary-color);
            width: 8px;
            border-radius: 2px 2px 0 0;
            transition: all 0.3s ease;
        }

        .chart-bar:hover {
            background: var(--primary-color);
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
                <li><a href="manage-students.php" class="active"><i class="fas fa-users"></i> My Students</a></li>
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
                <h1>Manage Students</h1>
                <p>View and manage students in your assigned classes</p>
            </div>
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

        <?php if (empty($assigned_classes)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-circle"></i>
                <strong>No Classes Assigned</strong>
                <p>You don't have any classes assigned to you yet. Please contact the administrator to get assigned to classes.</p>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-cards">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $total_students ?? 0; ?></div>
                <div class="stat-label">Total Students</div>
            </div>

            <div class="stat-card exams">
                <div class="stat-value"><?php echo count($assigned_classes ?? []); ?></div>
                <div class="stat-label">Assigned Classes</div>
            </div>

            <div class="stat-card performance">
                <?php
                $avg_performance = 0;
                if (!empty($students)) {
                    $scores = array_filter(array_column($students, 'avg_score'));
                    $avg_performance = !empty($scores) ? round(array_sum($scores) / count($scores), 1) : 0;
                }
                ?>
                <div class="stat-value"><?php echo $avg_performance; ?>%</div>
                <div class="stat-label">Average Performance</div>
            </div>
        </div>

        <?php if (!empty($assigned_classes)): ?>
            <!-- Filters Section -->
            <div class="filters-section">
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="class_filter">Filter by Class:</label>
                            <select name="class" id="class_filter" class="filter-select">
                                <option value="">All Assigned Classes</option>
                                <?php foreach ($assigned_classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['class']); ?>"
                                        <?php echo $class_filter == $class['class'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="search_query">Search Students:</label>
                            <input type="text" name="search" id="search_query" class="filter-input"
                                placeholder="Search by name or admission number..."
                                value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="manage-students.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Bulk Actions -->
            <form method="POST" action="" class="bulk-actions" id="bulkForm">
                <input type="checkbox" id="selectAll" class="bulk-select">
                <span class="bulk-select-label">Select All</span>

                <select name="bulk_action" class="filter-select" style="flex: 1;">
                    <option value="">Bulk Actions</option>
                    <option value="export">Export Selected</option>
                    <option value="message">Send Message</option>
                    <option value="assign_exam">Assign Exam</option>
                </select>

                <button type="submit" class="btn btn-primary" onclick="return confirmBulkAction()">
                    <i class="fas fa-play"></i> Apply Action
                </button>
            </form>

            <!-- Students Table -->
            <div class="students-table-container">
                <div class="table-header">
                    <h3>Students List</h3>
                </div>

                <?php if (!empty($students)): ?>
                    <table class="students-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" id="selectAllTable" class="student-checkbox">
                                </th>
                                <th>Student</th>
                                <th>Admission No</th>
                                <th>Class</th>
                                <th>Exams Taken</th>
                                <th>Average Score</th>
                                <th>Performance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student):
                                $avg_score = $student['avg_score'] ?? 0;
                                $performance_class = '';
                                if ($avg_score >= 70) {
                                    $performance_class = 'performance-excellent';
                                } elseif ($avg_score >= 50) {
                                    $performance_class = 'performance-good';
                                } else {
                                    $performance_class = 'performance-poor';
                                }

                                // Get first letter of first name for avatar
                                $name_parts = explode(' ', $student['full_name']);
                                $first_letter = strtoupper(substr($name_parts[0], 0, 1));
                            ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_students[]"
                                            value="<?php echo $student['id']; ?>"
                                            class="student-checkbox student-select">
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div class="student-avatar">
                                                <?php echo $first_letter; ?>
                                            </div>
                                            <div>
                                                <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                    <td>
                                        <span class="student-class"><?php echo htmlspecialchars($student['class']); ?></span>
                                    </td>
                                    <td><?php echo $student['total_exams']; ?></td>
                                    <td>
                                        <span class="performance-badge <?php echo $performance_class; ?>">
                                            <?php echo number_format($avg_score, 1); ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <div class="performance-chart-mini" id="chart-<?php echo $student['id']; ?>">
                                            <!-- Chart will be generated by JavaScript -->
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn view"
                                                onclick="viewStudent(<?php echo $student['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-btn exam"
                                                onclick="assignExam(<?php echo $student['id']; ?>)">
                                                <i class="fas fa-file-alt"></i>
                                            </button>
                                            <button class="action-btn message"
                                                onclick="sendMessage(<?php echo $student['id']; ?>)">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-graduate"></i>
                        <h3>No Students Found</h3>
                        <p>No students found in your assigned classes with the current filters.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                            class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="page-link">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
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

        // Select All functionality
        const selectAllCheckbox = document.getElementById('selectAll');
        const selectAllTable = document.getElementById('selectAllTable');
        const studentCheckboxes = document.querySelectorAll('.student-select');

        function updateSelectAll() {
            const allChecked = Array.from(studentCheckboxes).every(cb => cb.checked);
            if (selectAllCheckbox) selectAllCheckbox.checked = allChecked;
            if (selectAllTable) selectAllTable.checked = allChecked;
        }

        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const isChecked = this.checked;
                studentCheckboxes.forEach(cb => cb.checked = isChecked);
                if (selectAllTable) selectAllTable.checked = isChecked;
            });
        }

        if (selectAllTable) {
            selectAllTable.addEventListener('change', function() {
                const isChecked = this.checked;
                studentCheckboxes.forEach(cb => cb.checked = isChecked);
                if (selectAllCheckbox) selectAllCheckbox.checked = isChecked;
            });
        }

        studentCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateSelectAll);
        });

        // Bulk action confirmation
        function confirmBulkAction() {
            const selectedStudents = document.querySelectorAll('.student-select:checked');
            const action = document.querySelector('select[name="bulk_action"]').value;

            if (selectedStudents.length === 0) {
                alert('Please select at least one student.');
                return false;
            }

            if (!action) {
                alert('Please select an action.');
                return false;
            }

            return confirm(`Apply ${action} to ${selectedStudents.length} selected student(s)?`);
        }

        // Student actions
        function viewStudent(studentId) {
            window.location.href = `student-details.php?id=${studentId}`;
        }

        function assignExam(studentId) {
            window.location.href = `assign-exam.php?student_id=${studentId}`;
        }

        function sendMessage(studentId) {
            window.location.href = `send-message.php?student_id=${studentId}`;
        }

        // Generate mini performance charts
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($students as $student):
                if (isset($performance_data[$student['id']])) {
                    $scores = array_column($performance_data[$student['id']], 'percentage');
                    $max_score = max($scores) ?: 100;
                    $scores = array_slice($scores, 0, 5); // Last 5 scores
                    $scores_json = json_encode($scores);
                } else {
                    $scores_json = '[]';
                }
            ?>
                generateMiniChart(<?php echo $student['id']; ?>, <?php echo $scores_json; ?>);
            <?php endforeach; ?>
        });

        function generateMiniChart(studentId, scores) {
            const container = document.getElementById(`chart-${studentId}`);
            if (!container || scores.length === 0) return;

            const maxScore = Math.max(...scores);
            const barCount = Math.min(scores.length, 5);
            const barWidth = 10;
            const spacing = 4;

            container.innerHTML = '';

            for (let i = 0; i < barCount; i++) {
                const bar = document.createElement('div');
                bar.className = 'chart-bar';
                bar.style.left = `${i * (barWidth + spacing)}px`;
                bar.style.height = `${(scores[i] / maxScore) * 30}px`;
                bar.title = `${scores[i]}%`;
                container.appendChild(bar);
            }
        }

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
            // Ctrl+F for focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('search_query');
                if (searchInput) searchInput.focus();
            }

            // Ctrl+A for select all
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = !selectAllCheckbox.checked;
                    selectAllCheckbox.dispatchEvent(new Event('change'));
                }
            }

            // Escape to close sidebar on mobile
            if (e.key === 'Escape' && window.innerWidth <= 768) {
                sidebar.classList.remove('active');
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });

        // Auto-refresh page every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000);
    </script>
</body>

</html>