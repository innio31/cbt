<?php
// admin/index.php - Admin Dashboard
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

// Get statistics
try {
    // Total Students
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM students WHERE status = 'active'");
    $total_students = $stmt->fetch()['total'];

    // Total Staff
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM staff WHERE is_active = TRUE");
    $total_staff = $stmt->fetch()['total'];

    // Total Exams
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM exams");
    $total_exams = $stmt->fetch()['total'];

    // Total Subjects
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM subjects");
    $total_subjects = $stmt->fetch()['total'];

    // Recent Activity Logs
    $stmt = $pdo->query("
        SELECT al.*, 
               CASE 
                   WHEN al.user_type = 'student' THEN s.full_name
                   WHEN al.user_type = 'staff' THEN st.full_name
                   WHEN al.user_type = 'admin' THEN a.full_name
                   ELSE 'Unknown User'
               END as user_name
        FROM activity_logs al
        LEFT JOIN students s ON al.user_id = s.id AND al.user_type = 'student'
        LEFT JOIN staff st ON al.user_id = st.id AND al.user_type = 'staff'
        LEFT JOIN admin_users a ON al.user_id = a.id AND al.user_type = 'admin'
        ORDER BY al.created_at DESC 
        LIMIT 10
    ");
    $recent_activities = $stmt->fetchAll();

    // Recent Exams
    $stmt = $pdo->query("
        SELECT e.*, s.subject_name 
        FROM exams e 
        LEFT JOIN subjects s ON e.subject_id = s.id 
        ORDER BY e.created_at DESC 
        LIMIT 5
    ");
    $recent_exams = $stmt->fetchAll();

    // Recent Results
    $stmt = $pdo->query("
        SELECT r.*, s.full_name as student_name, e.exam_name 
        FROM results r 
        JOIN students s ON r.student_id = s.id 
        JOIN exams e ON r.exam_id = e.id 
        ORDER BY r.submitted_at DESC 
        LIMIT 5
    ");
    $recent_results = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Admin dashboard error: " . $e->getMessage());
    $error_message = "Error loading dashboard data";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Dashboard - Digital CBT System</title>

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
            --header-height: 70px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.12);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
            overflow-x: hidden;
            font-size: 16px;
            line-height: 1.5;
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
        }

        .mobile-menu-toggle:hover {
            background: #1a252f;
            transform: scale(1.05);
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            height: -webkit-fill-available;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0 0 0;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 44px;
            height: 44px;
            background: var(--secondary-color);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .logo-text h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 2px;
            line-height: 1.2;
        }

        .logo-text p {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .admin-info {
            padding: 16px 20px;
            margin: 16px 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-md);
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
        }

        .admin-info h4 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .admin-info p {
            font-size: 0.8rem;
            opacity: 0.8;
            text-transform: capitalize;
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0 0 20px 0;
        }

        .sidebar-content::-webkit-scrollbar {
            width: 4px;
        }

        .sidebar-content::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
        }

        .sidebar-content::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        .nav-links {
            list-style: none;
            padding: 0 16px;
        }

        .nav-links li {
            margin-bottom: 4px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: var(--transition);
            border-radius: var(--radius-sm);
            border-left: 3px solid transparent;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--secondary-color);
            transform: translateX(4px);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: var(--secondary-color);
            font-weight: 600;
        }

        .nav-links i {
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        /* Main Content Styles */
        .main-content {
            min-height: 100vh;
            padding: 20px;
            transition: var(--transition);
            position: relative;
        }

        /* Overlay for mobile sidebar */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
            backdrop-filter: blur(2px);
        }

        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Top Header */
        .top-header {
            background: white;
            padding: 20px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .header-title p {
            color: #666;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            justify-content: flex-end;
        }

        .logout-btn {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: var(--transition);
            font-size: 0.95rem;
            min-height: 44px;
            white-space: nowrap;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(231, 76, 60, 0.25);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border-top: 4px solid;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: inherit;
            opacity: 0;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card.students {
            border-top-color: var(--secondary-color);
        }

        .stat-card.staff {
            border-top-color: var(--warning-color);
        }

        .stat-card.exams {
            border-top-color: var(--success-color);
        }

        .stat-card.subjects {
            border-top-color: var(--accent-color);
        }

        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
            flex-shrink: 0;
        }

        .stat-card.students .stat-icon {
            background: var(--secondary-color);
        }

        .stat-card.staff .stat-icon {
            background: var(--warning-color);
        }

        .stat-card.exams .stat-icon {
            background: var(--success-color);
        }

        .stat-card.subjects .stat-icon {
            background: var(--accent-color);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 4px;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
            font-weight: 500;
        }

        .stat-card p {
            font-size: 0.85rem;
            color: #777;
            margin-top: 8px;
            line-height: 1.4;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .content-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .content-card:hover {
            box-shadow: var(--shadow-md);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--light-color);
            flex-wrap: wrap;
            gap: 12px;
        }

        .card-header h3 {
            color: var(--primary-color);
            font-size: 1.2rem;
            font-weight: 600;
        }

        .card-header a {
            color: var(--secondary-color);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .card-header a:hover {
            text-decoration: underline;
        }

        /* Activity List */
        .activity-list {
            list-style: none;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 8px;
        }

        .activity-list::-webkit-scrollbar {
            width: 4px;
        }

        .activity-list::-webkit-scrollbar-track {
            background: var(--light-color);
            border-radius: 10px;
        }

        .activity-list::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 10px;
        }

        .activity-item {
            padding: 16px 0;
            border-bottom: 1px solid var(--light-color);
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            background: var(--light-color);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 18px;
        }

        .activity-icon.student {
            color: var(--secondary-color);
            background: rgba(52, 152, 219, 0.1);
        }

        .activity-icon.staff {
            color: var(--warning-color);
            background: rgba(243, 156, 18, 0.1);
        }

        .activity-icon.admin {
            color: var(--accent-color);
            background: rgba(231, 76, 60, 0.1);
        }

        .activity-content {
            flex: 1;
            min-width: 0;
        }

        .activity-content h4 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--dark-color);
        }

        .activity-content p {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 4px;
            line-height: 1.4;
        }

        .activity-time {
            font-size: 0.8rem;
            color: #999;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .activity-time::before {
            content: '•';
            color: #ccc;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
            border-radius: var(--radius-sm);
            margin: -5px;
            padding: 5px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .data-table th {
            background: var(--light-color);
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 0.9rem;
            white-space: nowrap;
            border-bottom: 2px solid #ddd;
        }

        .data-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #eee;
            font-size: 0.9rem;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            min-width: 70px;
            text-align: center;
        }

        .status-active {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .status-inactive {
            background: #f8d7da;
            color: var(--danger-color);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
        }

        .action-btn {
            background: white;
            border: 2px solid var(--light-color);
            border-radius: var(--radius-sm);
            padding: 20px 12px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .action-btn:hover {
            border-color: var(--secondary-color);
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            background: #f8fbff;
        }

        .action-icon {
            font-size: 24px;
            color: var(--secondary-color);
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(52, 152, 219, 0.1);
            border-radius: 50%;
        }

        .action-text {
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--dark-color);
            text-align: center;
            line-height: 1.3;
        }

        /* Footer */
        .dashboard-footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 0.9rem;
            border-top: 1px solid var(--light-color);
            margin-top: 30px;
            background: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
        }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideDown 0.3s ease;
            border-left: 4px solid;
            background: white;
            box-shadow: var(--shadow-sm);
        }

        .alert i {
            font-size: 20px;
            margin-top: 2px;
        }

        .alert-success {
            border-left-color: var(--success-color);
            color: #155724;
            background: #d5f4e6;
        }

        .alert-error {
            border-left-color: var(--danger-color);
            color: #721c24;
            background: #f8d7da;
        }

        .alert-warning {
            border-left-color: var(--warning-color);
            color: #856404;
            background: #fff3cd;
        }

        /* Animations */
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        /* Responsive Design */
        @media (min-width: 768px) {
            .mobile-menu-toggle {
                display: none;
            }

            .sidebar {
                transform: translateX(0);
            }

            .sidebar-overlay {
                display: none;
            }

            .main-content {
                margin-left: var(--sidebar-width);
            }

            .top-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .content-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (max-width: 767px) {
            .main-content {
                padding: 16px;
                padding-top: 70px;
            }

            .sidebar {
                width: 280px;
            }

            .mobile-menu-toggle {
                display: flex;
            }

            .top-header {
                padding: 16px;
            }

            .header-title h1 {
                font-size: 1.3rem;
            }

            .logout-btn {
                padding: 10px 16px;
                font-size: 0.9rem;
            }

            .stat-value {
                font-size: 1.8rem;
            }

            .card-header h3 {
                font-size: 1.1rem;
            }

            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }

            .action-btn {
                padding: 16px 10px;
            }

            .action-icon {
                width: 40px;
                height: 40px;
                font-size: 20px;
            }

            .activity-item {
                padding: 14px 0;
            }

            .activity-icon {
                width: 36px;
                height: 36px;
                font-size: 16px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 16px;
            }

            .content-card {
                padding: 16px;
            }

            .activity-list {
                max-height: 300px;
            }

            .logout-btn span {
                display: none;
            }

            .logout-btn {
                width: 44px;
                height: 44px;
                padding: 0;
                justify-content: center;
                border-radius: 50%;
            }
        }

        @media (max-width: 360px) {
            .main-content {
                padding: 12px;
                padding-top: 65px;
            }

            .stat-value {
                font-size: 1.6rem;
            }

            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 20px;
            }
        }

        /* Landscape Orientation */
        @media (max-height: 600px) and (orientation: landscape) {
            .sidebar-content {
                max-height: 70vh;
            }

            .activity-list {
                max-height: 200px;
            }
        }

        /* Safe area support */
        @supports (padding: max(0px)) {
            .main-content {
                padding-left: max(16px, env(safe-area-inset-left));
                padding-right: max(16px, env(safe-area-inset-right));
                padding-top: max(70px, calc(env(safe-area-inset-top) + 50px));
            }

            .mobile-menu-toggle {
                top: max(15px, env(safe-area-inset-top));
                left: max(15px, env(safe-area-inset-left));
            }
        }

        /* Loading skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 4px;
        }

        @keyframes loading {
            0% {
                background-position: 200% 0;
            }

            100% {
                background-position: -200% 0;
            }
        }

        /* Touch improvements */
        @media (hover: none) and (pointer: coarse) {
            .stat-card:hover {
                transform: none;
            }

            .action-btn:hover {
                transform: none;
            }

            .nav-links a:hover {
                transform: none;
            }

            /* Increase touch targets */
            .nav-links a {
                min-height: 48px;
            }

            .logout-btn {
                min-height: 48px;
            }
        }

        /* Print styles */
        @media print {

            .sidebar,
            .mobile-menu-toggle,
            .logout-btn,
            .card-header a {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }

            .content-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

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

        <div class="sidebar-content">
            <ul class="nav-links">
                <li><a href="index.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
                <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
                <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
                <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
                <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="report_card_dashboard.php"><i class="fas fa-cog"></i> Process Result</a></li>
                <li><a href="central_dashboard.php"><i class="fas fa-cog"></i> Sync</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>Admin Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($admin_name); ?>! Here's what's happening today.</p>
            </div>
            <div class="header-actions">
                <button class="logout-btn" onclick="window.location.href='../logout.php'" aria-label="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card students">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_students ?? 0; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                </div>
                <p>Active student accounts</p>
            </div>

            <div class="stat-card staff">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_staff ?? 0; ?></div>
                        <div class="stat-label">Total Staff</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                </div>
                <p>Teaching and administrative staff</p>
            </div>

            <div class="stat-card exams">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_exams ?? 0; ?></div>
                        <div class="stat-label">Total Exams</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                </div>
                <p>Exams created in the system</p>
            </div>

            <div class="stat-card subjects">
                <div class="stat-card-header">
                    <div>
                        <div class="stat-value"><?php echo $total_subjects ?? 0; ?></div>
                        <div class="stat-label">Total Subjects</div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-book"></i>
                    </div>
                </div>
                <p>Subjects across all classes</p>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Recent Activities -->
            <div class="content-card">
                <div class="card-header">
                    <h3>Recent Activities</h3>
                    <a href="reports.php?tab=activities">
                        <i class="fas fa-external-link-alt"></i> View All
                    </a>
                </div>
                <ul class="activity-list">
                    <?php if (!empty($recent_activities)): ?>
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
                                    <h4><?php echo htmlspecialchars($activity['user_name'] ?? 'Unknown User'); ?></h4>
                                    <p><?php echo htmlspecialchars($activity['activity']); ?></p>
                                    <div class="activity-time">
                                        <?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="activity-item">
                            <div class="activity-content">
                                <p style="text-align: center; color: #999; padding: 10px 0;">
                                    <i class="fas fa-inbox"></i> No recent activities
                                </p>
                            </div>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Recent Exams -->
            <div class="content-card">
                <div class="card-header">
                    <h3>Recent Exams</h3>
                    <a href="manage-exams.php">
                        <i class="fas fa-external-link-alt"></i> View All
                    </a>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Exam Name</th>
                                <th>Subject</th>
                                <th>Class</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_exams)): ?>
                                <?php foreach ($recent_exams as $exam): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($exam['exam_name']); ?></td>
                                        <td><?php echo htmlspecialchars($exam['subject_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($exam['class']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $exam['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo $exam['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #999; padding: 20px;">
                                        <i class="fas fa-file-alt"></i> No exams found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="content-card">
            <div class="card-header">
                <h3>Quick Actions</h3>
            </div>
            <div class="quick-actions">
                <a href="manage-students.php" class="action-btn" aria-label="Add Student">
                    <div class="action-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="action-text">Add Student</div>
                </a>

                <a href="manage-staff.php" class="action-btn" aria-label="Add Staff">
                    <div class="action-icon">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="action-text">Add Staff</div>
                </a>

                <a href="manage-exams.php" class="action-btn" aria-label="Create Exam">
                    <div class="action-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="action-text">Create Exam</div>
                </a>

                <a href="reports.php" class="action-btn" aria-label="Generate Report">
                    <div class="action-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="action-text">Generate Report</div>
                </a>

                <a href="view-results.php" class="action-btn" aria-label="View Results">
                    <div class="action-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="action-text">View Results</div>
                </a>

                <a href="report_card_dashboard.php" class="action-btn" aria-label="Process Result">
                    <div class="action-icon">
                        <i class="fas fa-cogs"></i>
                    </div>
                    <div class="action-text">Process Result</div>
                </a>
            </div>
        </div>

        <!-- Recent Results -->
        <div class="content-card">
            <div class="card-header">
                <h3>Recent Exam Results</h3>
                <a href="view-results.php">
                    <i class="fas fa-external-link-alt"></i> View All
                </a>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Exam</th>
                            <th>Score</th>
                            <th>Percentage</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_results)): ?>
                            <?php foreach ($recent_results as $result): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($result['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($result['exam_name']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($result['total_score'] ?? 0); ?></strong></td>
                                    <td>
                                        <span style="color: <?php echo ($result['percentage'] ?? 0) >= 50 ? '#27ae60' : '#e74c3c'; ?>; font-weight: 600;">
                                            <?php echo number_format($result['percentage'] ?? 0, 2); ?>%
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($result['submitted_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #999; padding: 20px;">
                                    <i class="fas fa-chart-bar"></i> No results found
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <div class="dashboard-footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?> - Digital CBT System</p>
            <p style="margin-top: 5px; font-size: 0.8rem; color: #888;">Last updated: <?php echo date('F j, Y, g:i a'); ?></p>
        </div>
    </div>

    <script>
        // Mobile menu functionality
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mainContent = document.getElementById('mainContent');

        // Toggle sidebar
        function toggleSidebar() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        // Close sidebar
        function closeSidebar() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Event listeners
        mobileMenuToggle.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', closeSidebar);

        // Close sidebar when clicking a nav link on mobile
        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 767) {
                    closeSidebar();
                }
            });
        });

        // Close sidebar with escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeSidebar();
            }
        });

        // Handle window resize
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (window.innerWidth > 767) {
                    closeSidebar();
                }
            }, 250);
        });

        // Display alert messages from URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const message = urlParams.get('message');
        const messageType = urlParams.get('type');

        if (message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${messageType || 'success'}`;
            alertDiv.innerHTML = `
                <i class="fas fa-${messageType === 'error' ? 'exclamation-triangle' : 
                                  messageType === 'warning' ? 'exclamation-circle' : 'check-circle'}"></i>
                <div>${decodeURIComponent(message)}</div>
            `;

            // Insert at the top of main content
            const mainContent = document.getElementById('mainContent');
            mainContent.insertBefore(alertDiv, mainContent.firstChild);

            // Remove alert after 5 seconds
            setTimeout(() => {
                alertDiv.style.opacity = '0';
                alertDiv.style.transform = 'translateY(-10px)';
                setTimeout(() => alertDiv.remove(), 300);
            }, 5000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Don't trigger shortcuts when typing in inputs
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
                return;
            }

            // Ctrl+S for student management
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                window.location.href = 'manage-students.php';
            }

            // Ctrl+E for exam management
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                window.location.href = 'manage-exams.php';
            }

            // Ctrl+L for logout
            if (e.ctrlKey && e.key === 'l') {
                e.preventDefault();
                window.location.href = '../logout.php';
            }

            // Space for quick actions
            if (e.key === ' ' && !e.ctrlKey && !e.altKey) {
                e.preventDefault();
                document.querySelector('.quick-actions').scrollIntoView({
                    behavior: 'smooth'
                });
            }
        });

        // Touch improvements
        document.addEventListener('touchstart', function() {}, {
            passive: true
        });

        // Prevent zoom on double-tap
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(e) {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) {
                e.preventDefault();
            }
            lastTouchEnd = now;
        }, false);

        // Add touch feedback to interactive elements
        document.querySelectorAll('.action-btn, .logout-btn, .nav-links a').forEach(element => {
            element.addEventListener('touchstart', function() {
                this.style.opacity = '0.8';
            });

            element.addEventListener('touchend', function() {
                this.style.opacity = '1';
            });
        });

        // Auto-refresh dashboard every 5 minutes (optional)
        // setTimeout(function() {
        //     window.location.reload();
        // }, 300000); // 5 minutes

        // Performance optimization for mobile
        let isScrolling;
        window.addEventListener('scroll', function() {
            clearTimeout(isScrolling);

            // Add/remove visual effects during scroll for better performance
            document.body.classList.add('scrolling');

            isScrolling = setTimeout(function() {
                document.body.classList.remove('scrolling');
            }, 66); // ~15fps
        }, false);

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            // Add animation to stat cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.style.animation = 'fadeIn 0.5s ease forwards';
            });

            // Add CSS for fadeIn animation if not already in style
            if (!document.querySelector('style#animations')) {
                const style = document.createElement('style');
                style.id = 'animations';
                style.textContent = `
                    @keyframes fadeIn {
                        from { opacity: 0; transform: translateY(20px); }
                        to { opacity: 1; transform: translateY(0); }
                    }
                    .scrolling * {
                        animation: none !important;
                        transition: none !important;
                    }
                `;
                document.head.appendChild(style);
            }
        });

        // Handle page visibility
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                // Page became visible again
                console.log('Dashboard visible');
            }
        });

        // Print functionality
        window.printDashboard = function() {
            window.print();
        };
    </script>
</body>

</html>