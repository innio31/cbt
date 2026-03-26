<?php
// staff/index.php - Staff Dashboard
session_start();

// Check if staff is logged in - FIXED CONDITION
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

// Get staff assigned subjects and classes
try {
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

    // Get staff assigned classes
    $stmt = $pdo->prepare("
        SELECT DISTINCT class 
        FROM staff_classes 
        WHERE staff_id = ?
        ORDER BY class
    ");
    $stmt->execute([$staff_id]);
    $assigned_classes = $stmt->fetchAll();

    // Get statistics for assigned classes only
    $total_students = 0;
    if (!empty($assigned_classes)) {
        $class_placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
        $class_values = array_column($assigned_classes, 'class');

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM students 
            WHERE status = 'active' 
            AND class IN ($class_placeholders)
        ");
        $stmt->execute($class_values);
        $total_students = $stmt->fetch()['total'];
    }

    // Total Exams for staff's subjects/classes
    $total_exams = 0;
    if (!empty($assigned_classes) && !empty($assigned_subjects)) {
        $class_placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
        $subject_ids = array_column($assigned_subjects, 'id');
        $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';

        // Parameters: subject_ids + classes
        $params = array_merge($subject_ids, array_column($assigned_classes, 'class'));

        $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM exams 
        WHERE subject_id IN ($subject_placeholders)
        AND class IN ($class_placeholders)
    ");
        $stmt->execute($params);
        $total_exams = $stmt->fetch()['total'];
    }

    // Total Assignments
    $total_assignments = 0;
    if (!empty($assigned_classes)) {
        $class_placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
        $params = array_merge([$staff_id], array_column($assigned_classes, 'class'));

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM assignments 
            WHERE staff_id = ? 
            AND class IN ($class_placeholders)
        ");
        $stmt->execute($params);
        $total_assignments = $stmt->fetch()['total'];
    }

    // Pending assignments to grade
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM assignment_submissions 
        WHERE status = 'submitted' 
        AND assignment_id IN (
            SELECT id FROM assignments WHERE staff_id = ?
        )
    ");
    $stmt->execute([$staff_id]);
    $pending_grading = $stmt->fetch()['total'];

    // Recent Activity Logs (staff's activities and their students)
    $recent_activities = [];
    if (!empty($assigned_classes)) {
        $class_placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
        $class_values = array_column($assigned_classes, 'class');

        $stmt = $pdo->prepare("
            SELECT al.*, s.full_name as student_name, s.class as student_class
            FROM activity_logs al
            LEFT JOIN students s ON al.user_id = s.id AND al.user_type = 'student'
            WHERE (al.user_id = ? AND al.user_type = 'staff')
               OR (al.user_type = 'student' AND s.class IN ($class_placeholders))
            ORDER BY al.created_at DESC 
            LIMIT 10
        ");
        $params = array_merge([$staff_id], $class_values);
        $stmt->execute($params);
        $recent_activities = $stmt->fetchAll();
    } else {
        // If no classes assigned, just show staff's own activities
        $stmt = $pdo->prepare("
            SELECT al.*, NULL as student_name, NULL as student_class
            FROM activity_logs al
            WHERE al.user_id = ? AND al.user_type = 'staff'
            ORDER BY al.created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$staff_id]);
        $recent_activities = $stmt->fetchAll();
    }

    // Recent Exams (for staff's subjects/classes)
    $recent_exams = [];
    if (!empty($assigned_classes) && !empty($assigned_subjects)) {
        $class_placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
        $subject_ids = array_column($assigned_subjects, 'id');
        $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';

        $params = array_merge([$staff_id], $subject_ids, array_column($assigned_classes, 'class'));

        $stmt = $pdo->prepare("
            SELECT e.*, s.subject_name 
            FROM exams e 
            LEFT JOIN subjects s ON e.subject_id = s.id 
            WHERE (e.created_by = ? OR e.subject_id IN ($subject_placeholders))
            AND e.class IN ($class_placeholders)
            ORDER BY e.created_at DESC 
            LIMIT 5
        ");
        $stmt->execute($params);
        $recent_exams = $stmt->fetchAll();
    }

    // Recent Results (for staff's classes)
    $recent_results = [];
    if (!empty($assigned_classes)) {
        $class_placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
        $class_values = array_column($assigned_classes, 'class');

        $stmt = $pdo->prepare("
            SELECT r.*, stu.full_name as student_name, e.exam_name, stu.class
            FROM results r 
            JOIN students stu ON r.student_id = stu.id 
            JOIN exams e ON r.exam_id = e.id 
            WHERE stu.class IN ($class_placeholders)
            ORDER BY r.submitted_at DESC 
            LIMIT 5
        ");
        $stmt->execute($class_values);
        $recent_results = $stmt->fetchAll();
    }

    // Upcoming deadlines for assignments
    $upcoming_deadlines = [];
    if (!empty($assigned_classes)) {
        $class_placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
        $params = array_merge([$staff_id], array_column($assigned_classes, 'class'));

        $stmt = $pdo->prepare("
            SELECT a.*, s.subject_name,
                   DATEDIFF(a.deadline, NOW()) as days_left
            FROM assignments a
            LEFT JOIN subjects s ON a.subject_id = s.id
            WHERE a.staff_id = ?
            AND a.class IN ($class_placeholders)
            AND a.deadline > NOW()
            ORDER BY a.deadline ASC
            LIMIT 5
        ");
        $stmt->execute($params);
        $upcoming_deadlines = $stmt->fetchAll();
    }
} catch (Exception $e) {
    error_log("Staff dashboard error: " . $e->getMessage());
    $error_message = "Error loading dashboard data";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - Digital CBT System</title>

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
            --header-height: 70px;
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

        .sidebar-content::-webkit-scrollbar {
            width: 6px;
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

        /* Main Content Styles */
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
            margin-bottom: 35px;
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

        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: white;
            padding: 30px;
            border-radius: 18px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            border-top: 5px solid;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: inherit;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }

        .stat-card.students {
            border-top-color: var(--secondary-color);
        }

        .stat-card.exams {
            border-top-color: var(--warning-color);
        }

        .stat-card.assignments {
            border-top-color: var(--accent-color);
        }

        .stat-card.grading {
            border-top-color: var(--danger-color);
        }

        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.students .stat-icon {
            background: linear-gradient(135deg, var(--secondary-color), #3182ce);
        }

        .stat-card.exams .stat-icon {
            background: linear-gradient(135deg, var(--warning-color), #d69e2e);
        }

        .stat-card.assignments .stat-icon {
            background: linear-gradient(135deg, var(--accent-color), #dd6b20);
        }

        .stat-card.grading .stat-icon {
            background: linear-gradient(135deg, var(--danger-color), #c53030);
        }

        .stat-value {
            font-size: 2.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--dark-color);
        }

        .stat-label {
            font-size: 1rem;
            color: #718096;
            font-weight: 500;
        }

        .stat-description {
            font-size: 0.9rem;
            color: #a0aec0;
            margin-top: 10px;
            line-height: 1.5;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 35px;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .content-card {
            background: white;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
            position: relative;
        }

        .content-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 18px 18px 0 0;
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
        }

        .activity-icon.student {
            background: #bee3f8;
            color: var(--secondary-color);
        }

        .activity-icon.staff {
            background: #fed7d7;
            color: var(--danger-color);
        }

        .activity-icon.admin {
            background: #c6f6d5;
            color: var(--success-color);
        }

        .activity-content {
            flex: 1;
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

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-active {
            background: #c6f6d5;
            color: var(--success-color);
        }

        .status-inactive {
            background: #fed7d7;
            color: var(--danger-color);
        }

        .status-pending {
            background: #feebc8;
            color: var(--warning-color);
        }

        .deadline-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .deadline-urgent {
            background: #fed7d7;
            color: #c53030;
        }

        .deadline-near {
            background: #feebc8;
            color: #d69e2e;
        }

        .deadline-far {
            background: #c6f6d5;
            color: #38a169;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .action-btn {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 15px;
            padding: 25px 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .action-btn:hover {
            border-color: var(--secondary-color);
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(66, 153, 225, 0.15);
        }

        .action-icon {
            font-size: 32px;
            color: var(--secondary-color);
            margin-bottom: 15px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .action-text {
            font-weight: 600;
            font-size: 1rem;
            color: var(--dark-color);
        }

        /* Assigned Info */
        .assigned-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .info-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
            border-left: 5px solid var(--secondary-color);
        }

        .info-card h3 {
            color: var(--dark-color);
            margin-bottom: 20px;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-card h3 i {
            color: var(--secondary-color);
        }

        .info-items {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .info-item {
            background: var(--light-color);
            padding: 10px 18px;
            border-radius: 12px;
            font-size: 0.95rem;
            color: var(--dark-color);
            font-weight: 500;
        }

        /* Footer */
        .dashboard-footer {
            text-align: center;
            padding: 25px;
            color: #718096;
            font-size: 0.95rem;
            border-top: 1px solid var(--light-color);
            margin-top: 40px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .top-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
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
                <li><a href="index.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
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
                <h1>Staff Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($staff_name); ?>! Manage your classes and track student progress.</p>
            </div>
            <div class="header-actions">
                <button class="logout-btn" onclick="window.location.href='../logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <!-- Assigned Info -->
        <div class="assigned-info">
            <div class="info-card">
                <h3><i class="fas fa-book"></i> Assigned Subjects</h3>
                <div class="info-items">
                    <?php if (!empty($assigned_subjects)): ?>
                        <?php foreach ($assigned_subjects as $subject): ?>
                            <span class="info-item"><?php echo htmlspecialchars($subject['subject_name']); ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: #a0aec0;">No subjects assigned</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-card">
                <h3><i class="fas fa-users-class"></i> Assigned Classes</h3>
                <div class="info-items">
                    <?php if (!empty($assigned_classes)): ?>
                        <?php foreach ($assigned_classes as $class): ?>
                            <span class="info-item"><?php echo htmlspecialchars($class['class']); ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: #a0aec0;">No classes assigned</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card students">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_students ?? 0; ?></div>
                        <div class="stat-label">My Students</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                </div>
                <p class="stat-description">Active students in your assigned classes</p>
            </div>

            <div class="stat-card exams">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_exams ?? 0; ?></div>
                        <div class="stat-label">My Exams</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                </div>
                <p class="stat-description">Exams created for your subjects</p>
            </div>

            <div class="stat-card assignments">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_assignments ?? 0; ?></div>
                        <div class="stat-label">Assignments</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                </div>
                <p class="stat-description">Assignments created by you</p>
            </div>

            <div class="stat-card grading">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $pending_grading ?? 0; ?></div>
                        <div class="stat-label">Pending Grading</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                </div>
                <p class="stat-description">Submissions awaiting your review</p>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Recent Activities -->
            <div class="content-card">
                <div class="card-header">
                    <h3>Recent Activities</h3>
                </div>
                <?php if (!empty($recent_activities)): ?>
                    <ul class="activity-list">
                        <?php foreach ($recent_activities as $activity): ?>
                            <li class="activity-item">
                                <div class="activity-icon <?php echo $activity['user_type']; ?>">
                                    <?php
                                    switch ($activity['user_type']) {
                                        case 'student':
                                            echo '<i class="fas fa-user-graduate"></i>';
                                            break;
                                        case 'staff':
                                            echo '<i class="fas fa-chalkboard-teacher"></i>';
                                            break;
                                        case 'admin':
                                            echo '<i class="fas fa-user-cog"></i>';
                                            break;
                                        default:
                                            echo '<i class="fas fa-user"></i>';
                                    }
                                    ?>
                                </div>
                                <div class="activity-content">
                                    <h4>
                                        <?php
                                        if ($activity['user_type'] === 'student' && $activity['student_name']) {
                                            echo htmlspecialchars($activity['student_name']) . ' (' . htmlspecialchars($activity['student_class']) . ')';
                                        } elseif ($activity['user_type'] === 'staff') {
                                            echo 'You';
                                        } else {
                                            echo ucfirst($activity['user_type']);
                                        }
                                        ?>
                                    </h4>
                                    <p><?php echo htmlspecialchars($activity['activity']); ?></p>
                                    <div class="activity-time">
                                        <i class="far fa-clock"></i>
                                        <?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-bell-slash"></i>
                        <p>No recent activities</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upcoming Deadlines -->
            <div class="content-card">
                <div class="card-header">
                    <h3>Upcoming Deadlines</h3>
                    <a href="assignments.php">View All</a>
                </div>
                <?php if (!empty($upcoming_deadlines)): ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Assignment</th>
                                    <th>Subject</th>
                                    <th>Deadline</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcoming_deadlines as $deadline):
                                    $days_left = $deadline['days_left'];
                                    $status_class = '';
                                    $status_text = '';
                                    if ($days_left <= 1) {
                                        $status_class = 'deadline-urgent';
                                        $status_text = 'Urgent';
                                    } elseif ($days_left <= 3) {
                                        $status_class = 'deadline-near';
                                        $status_text = 'Near';
                                    } else {
                                        $status_class = 'deadline-far';
                                        $status_text = 'Upcoming';
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($deadline['title']); ?></td>
                                        <td><?php echo htmlspecialchars($deadline['subject_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($deadline['deadline'])); ?></td>
                                        <td>
                                            <span class="deadline-badge <?php echo $status_class; ?>">
                                                <?php echo $status_text; ?> (<?php echo $days_left; ?> days)
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="far fa-calendar-check"></i>
                        <p>No upcoming deadlines</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="content-card">
            <div class="card-header">
                <h3>Quick Actions</h3>
            </div>
            <div class="quick-actions">
                <a href="manage-exams.php?action=create" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="action-text">Create Exam</div>
                </a>

                <a href="assignments.php?action=create" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="action-text">New Assignment</div>
                </a>

                <a href="questions.php?action=add" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <div class="action-text">Add Questions</div>
                </a>

                <a href="manage-students.php" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="action-text">View Students</div>
                </a>

                <a href="grading.php" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="action-text">Grade Submissions</div>
                </a>

                <a href="reports.php" class="action-btn">
                    <div class="action-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="action-text">Generate Reports</div>
                </a>
            </div>
        </div>

        <!-- Recent Results -->
        <div class="content-card">
            <div class="card-header">
                <h3>Recent Exam Results</h3>
            </div>
            <?php if (!empty($recent_results)): ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Exam</th>
                                <th>Score</th>
                                <th>Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_results as $result):
                                $percentage = $result['percentage'] ?? 0;
                                $grade_color = ($percentage >= 70) ? '#38a169' : (($percentage >= 50) ? '#d69e2e' : '#e53e3e');
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($result['class']); ?></td>
                                    <td><?php echo htmlspecialchars($result['exam_name']); ?></td>
                                    <td>
                                        <span style="color: <?php echo $grade_color; ?>; font-weight: 600;">
                                            <?php echo number_format($percentage, 1); ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; color: <?php echo $grade_color; ?>;">
                                            <?php echo $result['grade'] ?? 'N/A'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-chart-bar"></i>
                    <p>No recent exam results</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="dashboard-footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?> - Staff Portal</p>
            <p style="margin-top: 8px; font-size: 0.9rem; color: #a0aec0;">
                <i class="fas fa-clock"></i> Last updated: <?php echo date('F j, Y, g:i a'); ?>
                • <i class="fas fa-user-check"></i> You have access to
                <?php echo count($assigned_subjects); ?> subjects and
                <?php echo count($assigned_classes); ?> classes
            </p>
        </div>
    </div>

    <script>
        // Mobile menu toggle
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

        // Auto-refresh dashboard every 3 minutes
        setTimeout(function() {
            window.location.reload();
        }, 180000); // 3 minutes

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });

        // Display alert messages from URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const message = urlParams.get('message');
        const messageType = urlParams.get('type');

        if (message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${messageType || 'success'}`;

            let icon = 'check-circle';
            if (messageType === 'error') icon = 'exclamation-triangle';
            if (messageType === 'warning') icon = 'exclamation-circle';

            alertDiv.innerHTML = `
                <i class="fas fa-${icon}"></i>
                ${message}
            `;

            // Insert at the top of main content
            const mainContent = document.getElementById('mainContent');
            mainContent.insertBefore(alertDiv, mainContent.firstChild);

            // Remove alert after 5 seconds
            setTimeout(() => {
                alertDiv.style.opacity = '0';
                alertDiv.style.transition = 'opacity 0.5s ease';
                setTimeout(() => alertDiv.remove(), 500);
            }, 5000);
        }

        // Keyboard shortcuts for staff
        document.addEventListener('keydown', function(e) {
            // Ctrl+E for exam creation
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                window.location.href = 'manage-exams.php?action=create';
            }

            // Ctrl+A for assignment creation
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                window.location.href = 'assignments.php?action=create';
            }

            // Ctrl+G for grading
            if (e.ctrlKey && e.key === 'g') {
                e.preventDefault();
                window.location.href = 'grading.php';
            }

            // Ctrl+S for student view
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                window.location.href = 'manage-students.php';
            }

            // Ctrl+Q for question bank
            if (e.ctrlKey && e.key === 'q') {
                e.preventDefault();
                window.location.href = 'questions.php';
            }

            // Escape to close sidebar on mobile
            if (e.key === 'Escape' && window.innerWidth <= 768) {
                sidebar.classList.remove('active');
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });

        // Animate stat cards on scroll
        function animateOnScroll() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                const cardTop = card.getBoundingClientRect().top;
                const windowHeight = window.innerHeight;

                if (cardTop < windowHeight - 100) {
                    setTimeout(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, index * 100);
                }
            });
        }

        // Initialize animations
        document.addEventListener('DOMContentLoaded', function() {
            // Set initial state for animation
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'all 0.6s ease';
            });

            // Trigger animations
            setTimeout(animateOnScroll, 100);
            window.addEventListener('scroll', animateOnScroll);

            // Add hover effect to action buttons
            const actionBtns = document.querySelectorAll('.action-btn');
            actionBtns.forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px)';
                });

                btn.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });
    </script>
</body>

</html>