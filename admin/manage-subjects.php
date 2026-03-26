<?php
// admin/manage-subjects.php - Manage Subjects
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

// Handle form submissions
$message = '';
$message_type = '';

// Check if subject_classes table exists, if not create it
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'subject_classes'");
    if (!$stmt->fetch()) {
        // Create subject_classes table
        $pdo->exec("CREATE TABLE subject_classes (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            subject_id INT(11) NOT NULL,
            class VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_subject_class (subject_id, class),
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
        )");
    }
} catch (Exception $e) {
    error_log("Table check error: " . $e->getMessage());
}

// Add new subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    try {
        $subject_name = trim($_POST['subject_name']);
        $description = trim($_POST['description'] ?? '');
        $classes = $_POST['classes'] ?? [];

        // Validate
        if (empty($subject_name)) {
            throw new Exception("Subject name is required");
        }

        // Check if subject already exists
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_name = ?");
        $stmt->execute([$subject_name]);
        if ($stmt->fetch()) {
            throw new Exception("Subject already exists");
        }

        // Insert subject
        $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, description) VALUES (?, ?)");
        $stmt->execute([$subject_name, $description]);
        $subject_id = $pdo->lastInsertId();

        // Insert class associations if provided
        if (!empty($classes)) {
            foreach ($classes as $class) {
                $stmt = $pdo->prepare("INSERT INTO subject_classes (subject_id, class) VALUES (?, ?)");
                $stmt->execute([$subject_id, $class]);
            }
        }

        // Log activity
        logActivity($_SESSION['admin_id'], "Added new subject: $subject_name", 'admin');

        $message = "Subject added successfully";
        $message_type = "success";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Update subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_subject'])) {
    try {
        $subject_id = $_POST['subject_id'];
        $subject_name = trim($_POST['subject_name']);
        $description = trim($_POST['description'] ?? '');
        $classes = $_POST['classes'] ?? [];

        // Validate
        if (empty($subject_name)) {
            throw new Exception("Subject name is required");
        }

        // Check if subject name already exists (excluding current subject)
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_name = ? AND id != ?");
        $stmt->execute([$subject_name, $subject_id]);
        if ($stmt->fetch()) {
            throw new Exception("Subject name already exists");
        }

        // Update subject
        $stmt = $pdo->prepare("UPDATE subjects SET subject_name = ?, description = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$subject_name, $description, $subject_id]);

        // Update class associations
        // First, remove existing associations
        $stmt = $pdo->prepare("DELETE FROM subject_classes WHERE subject_id = ?");
        $stmt->execute([$subject_id]);

        // Then add new ones
        if (!empty($classes)) {
            foreach ($classes as $class) {
                $stmt = $pdo->prepare("INSERT INTO subject_classes (subject_id, class) VALUES (?, ?)");
                $stmt->execute([$subject_id, $class]);
            }
        }

        // Log activity
        logActivity($_SESSION['admin_id'], "Updated subject: $subject_name", 'admin');

        $message = "Subject updated successfully";
        $message_type = "success";
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Delete subject
if (isset($_GET['delete'])) {
    try {
        $subject_id = $_GET['delete'];

        // Check if subject has associated questions
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM objective_questions WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
        $objective_count = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjective_questions WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
        $subjective_count = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM theory_questions WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
        $theory_count = $stmt->fetchColumn();

        // Check if subject has associated topics
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM topics WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
        $topic_count = $stmt->fetchColumn();

        $total_questions = $objective_count + $subjective_count + $theory_count;

        if ($total_questions > 0 || $topic_count > 0) {
            $message = "Cannot delete subject. It has $total_questions associated questions and $topic_count topics.";
            $message_type = "error";
        } else {
            // Get subject name for logging
            $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
            $stmt->execute([$subject_id]);
            $subject_name = $stmt->fetchColumn();

            // Delete the subject (cascade will handle subject_classes)
            $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
            $stmt->execute([$subject_id]);

            // Log activity
            logActivity($_SESSION['admin_id'], "Deleted subject: $subject_name", 'admin');

            $message = "Subject deleted successfully";
            $message_type = "success";
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Fetch all subjects
try {
    $stmt = $pdo->query("
        SELECT s.*, 
               GROUP_CONCAT(DISTINCT sc.class ORDER BY sc.class) as assigned_classes,
               (SELECT COUNT(*) FROM objective_questions WHERE subject_id = s.id) as objective_count,
               (SELECT COUNT(*) FROM subjective_questions WHERE subject_id = s.id) as subjective_count,
               (SELECT COUNT(*) FROM theory_questions WHERE subject_id = s.id) as theory_count,
               (SELECT COUNT(*) FROM exams WHERE subject_id = s.id) as exam_count,
               (SELECT COUNT(*) FROM topics WHERE subject_id = s.id) as topic_count
        FROM subjects s
        LEFT JOIN subject_classes sc ON s.id = sc.subject_id
        GROUP BY s.id
        ORDER BY s.subject_name
    ");
    $subjects = $stmt->fetchAll();

    // Fetch all unique classes from students table
    $stmt = $pdo->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class != '' ORDER BY class");
    $available_classes = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // If no classes found in students table, check exams table
    if (empty($available_classes)) {
        $stmt = $pdo->query("SELECT DISTINCT class FROM exams WHERE class IS NOT NULL AND class != '' ORDER BY class");
        $available_classes = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }
} catch (Exception $e) {
    error_log("Manage subjects error: " . $e->getMessage());
    $subjects = [];
    $available_classes = [];
}

// Fetch subject for editing
$edit_subject = null;
if (isset($_GET['edit'])) {
    try {
        $subject_id = $_GET['edit'];
        $stmt = $pdo->prepare("
            SELECT s.*, 
                   GROUP_CONCAT(DISTINCT sc.class) as assigned_classes
            FROM subjects s
            LEFT JOIN subject_classes sc ON s.id = sc.subject_id
            WHERE s.id = ?
            GROUP BY s.id
        ");
        $stmt->execute([$subject_id]);
        $edit_subject = $stmt->fetch();

        if ($edit_subject && $edit_subject['assigned_classes']) {
            $edit_subject['assigned_classes'] = explode(',', $edit_subject['assigned_classes']);
        } else {
            $edit_subject['assigned_classes'] = [];
        }
    } catch (Exception $e) {
        error_log("Edit subject error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects - Digital CBT System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* Reuse the same CSS from index.php */
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

        /* Form Styles */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
        }

        .form-header h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-control[readonly] {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }

        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 10px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--secondary-color);
        }

        .checkbox-item label {
            margin-bottom: 0;
            cursor: pointer;
            user-select: none;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid var(--light-color);
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #219653);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }

        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .table-header h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
        }

        .table-actions {
            display: flex;
            gap: 10px;
        }

        .search-box {
            position: relative;
            width: 300px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .data-table th {
            background: var(--light-color);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--primary-color);
            border-bottom: 2px solid #ddd;
            white-space: nowrap;
        }

        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            text-decoration: none;
        }

        .action-btn-sm {
            padding: 5px 10px;
            font-size: 0.8rem;
        }

        .edit-btn {
            background: #3498db;
            color: white;
        }

        .edit-btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .delete-btn {
            background: #e74c3c;
            color: white;
        }

        .delete-btn:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

        .view-btn {
            background: #2ecc71;
            color: white;
        }

        .view-btn:hover {
            background: #27ae60;
            transform: translateY(-2px);
        }

        .topics-btn {
            background: #9b59b6;
            color: white;
        }

        .topics-btn:hover {
            background: #8e44ad;
            transform: translateY(-2px);
        }

        .count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 25px;
            height: 25px;
            padding: 0 8px;
            background: var(--light-color);
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary-color);
            margin: 0 2px;
        }

        .count-badge.objective {
            background: #e3f2fd;
            color: #1976d2;
        }

        .count-badge.subjective {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .count-badge.theory {
            background: #e8f5e9;
            color: #388e3c;
        }

        .count-badge.exam {
            background: #fff3e0;
            color: #f57c00;
        }

        .count-badge.topic {
            background: #fff0f3;
            color: #e91e63;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #7f8c8d;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #bdc3c7;
        }

        /* Alert Messages */
        .alert {
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
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px 25px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #7f8c8d;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: var(--light-color);
            color: var(--primary-color);
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 2px solid var(--light-color);
            display: flex;
            justify-content: flex-end;
            gap: 15px;
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

            .table-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }

            .table-actions {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .action-buttons {
                flex-wrap: wrap;
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
            <li><a href="manage-subjects.php" class="active"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-topics.php"><i class="fas fa-tags"></i> Manage Topics</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>Manage Subjects</h1>
                <p>Add, edit, or remove subjects from the system</p>
            </div>
            <div class="header-actions">
                <button class="logout-btn" onclick="window.location.href='../logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : ($message_type === 'warning' ? 'exclamation-circle' : 'check-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Add/Edit Subject Form -->
        <div class="form-container">
            <div class="form-header">
                <h3><?php echo $edit_subject ? 'Edit Subject' : 'Add New Subject'; ?></h3>
                <?php if ($edit_subject): ?>
                    <a href="manage-subjects.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel Edit
                    </a>
                <?php endif; ?>
            </div>

            <form method="POST" action="">
                <?php if ($edit_subject): ?>
                    <input type="hidden" name="subject_id" value="<?php echo $edit_subject['id']; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="subject_name">Subject Name *</label>
                    <input type="text"
                        id="subject_name"
                        name="subject_name"
                        class="form-control"
                        value="<?php echo $edit_subject ? htmlspecialchars($edit_subject['subject_name']) : ''; ?>"
                        required
                        placeholder="Enter subject name (e.g., Mathematics, English)">
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description"
                        name="description"
                        class="form-control"
                        rows="3"
                        placeholder="Enter subject description"><?php echo $edit_subject ? htmlspecialchars($edit_subject['description']) : ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label>Assign to Classes (Optional)</label>
                    <div class="checkbox-group">
                        <?php if (!empty($available_classes)): ?>
                            <?php foreach ($available_classes as $class): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox"
                                        id="class_<?php echo htmlspecialchars($class); ?>"
                                        name="classes[]"
                                        value="<?php echo htmlspecialchars($class); ?>"
                                        <?php echo $edit_subject && in_array($class, $edit_subject['assigned_classes']) ? 'checked' : ''; ?>>
                                    <label for="class_<?php echo htmlspecialchars($class); ?>"><?php echo htmlspecialchars($class); ?></label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color: #7f8c8d; font-style: italic;">No classes found in system. Add students or exams with class information first.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <?php if ($edit_subject): ?>
                        <button type="submit" name="update_subject" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Subject
                        </button>
                    <?php else: ?>
                        <button type="submit" name="add_subject" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> Add Subject
                        </button>
                    <?php endif; ?>
                    <button type="reset" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset Form
                    </button>
                </div>
            </form>
        </div>

        <!-- Subjects List -->
        <div class="table-container">
            <div class="table-header">
                <h3>All Subjects (<?php echo count($subjects); ?>)</h3>
                <div class="table-actions">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search subjects...">
                    </div>
                </div>
            </div>

            <?php if (empty($subjects)): ?>
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <h3>No Subjects Found</h3>
                    <p>Start by adding your first subject using the form above.</p>
                </div>
            <?php else: ?>
                <table class="data-table" id="subjectsTable">
                    <thead>
                        <tr>
                            <th>Subject Name</th>
                            <th>Description</th>
                            <th>Assigned Classes</th>
                            <th>Questions/Exams/Topics</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $subject): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong>
                                    <?php if ($edit_subject && $edit_subject['id'] == $subject['id']): ?>
                                        <span style="color: #3498db; font-size: 0.8rem; margin-left: 5px;">(Editing)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $subject['description'] ? htmlspecialchars(substr($subject['description'], 0, 50)) . (strlen($subject['description']) > 50 ? '...' : '') : '<span style="color: #7f8c8d; font-style: italic;">No description</span>'; ?>
                                </td>
                                <td>
                                    <?php if ($subject['assigned_classes']): ?>
                                        <?php
                                        $classes = explode(',', $subject['assigned_classes']);
                                        foreach ($classes as $class): ?>
                                            <span style="display: inline-block; background: #e3f2fd; color: #1976d2; padding: 2px 8px; border-radius: 12px; margin: 2px; font-size: 0.85rem;">
                                                <?php echo htmlspecialchars($class); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span style="color: #7f8c8d; font-style: italic;">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                        <span class="count-badge objective" title="Objective Questions">O: <?php echo $subject['objective_count']; ?></span>
                                        <span class="count-badge subjective" title="Subjective Questions">S: <?php echo $subject['subjective_count']; ?></span>
                                        <span class="count-badge theory" title="Theory Questions">T: <?php echo $subject['theory_count']; ?></span>
                                        <span class="count-badge exam" title="Exams">E: <?php echo $subject['exam_count']; ?></span>
                                        <span class="count-badge topic" title="Topics">TP: <?php echo $subject['topic_count']; ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($subject['created_at'])); ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?edit=<?php echo $subject['id']; ?>" class="action-btn edit-btn" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="manage-topics.php?subject_id=<?php echo $subject['id']; ?>" class="action-btn topics-btn" title="Manage Topics">
                                            <i class="fas fa-tags"></i>
                                        </a>
                                        <button class="action-btn delete-btn"
                                            onclick="confirmDelete(<?php echo $subject['id']; ?>, '<?php echo htmlspecialchars(addslashes($subject['subject_name'])); ?>')"
                                            title="Delete"
                                            <?php echo ($subject['objective_count'] + $subject['subjective_count'] + $subject['theory_count'] + $subject['exam_count'] + $subject['topic_count']) > 0 ? 'disabled' : ''; ?>>
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <a href="manage_questions.php?subject_id=<?php echo $subject['id']; ?>" class="action-btn view-btn" title="Manage Questions">
                                            <i class="fas fa-question-circle"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the subject "<strong id="deleteSubjectName"></strong>"?</p>
                <p style="color: #e74c3c; font-weight: 500;">
                    <i class="fas fa-exclamation-triangle"></i> This action cannot be undone.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash"></i> Delete
                </button>
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

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const subjectsTable = document.getElementById('subjectsTable');

        if (searchInput && subjectsTable) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = subjectsTable.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

                for (let row of rows) {
                    const subjectName = row.cells[0].textContent.toLowerCase();
                    const description = row.cells[1].textContent.toLowerCase();

                    if (subjectName.includes(searchTerm) || description.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
        }

        // Delete confirmation modal
        let subjectToDelete = null;

        function confirmDelete(subjectId, subjectName) {
            subjectToDelete = subjectId;
            document.getElementById('deleteSubjectName').textContent = subjectName;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('deleteModal').classList.remove('active');
            subjectToDelete = null;
        }

        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            if (subjectToDelete) {
                window.location.href = `manage-subjects.php?delete=${subjectToDelete}`;
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Form validation
        const subjectForm = document.querySelector('form');
        if (subjectForm) {
            subjectForm.addEventListener('submit', function(e) {
                const subjectName = document.getElementById('subject_name').value.trim();
                if (!subjectName) {
                    e.preventDefault();
                    alert('Please enter a subject name');
                    document.getElementById('subject_name').focus();
                }
            });
        }

        // Auto-focus on subject name field when editing
        <?php if ($edit_subject): ?>
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('subject_name').focus();
                document.getElementById('subject_name').select();
            });
        <?php endif; ?>

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F for search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }

            // Escape to close modal or clear search
            if (e.key === 'Escape') {
                if (document.getElementById('deleteModal').classList.contains('active')) {
                    closeModal();
                } else if (searchInput && searchInput.value) {
                    searchInput.value = '';
                    searchInput.dispatchEvent(new Event('input'));
                }
            }

            // Ctrl+N for new subject (focus on form)
            if (e.ctrlKey && e.key === 'n' && !<?php echo $edit_subject ? 'true' : 'false'; ?>) {
                e.preventDefault();
                document.getElementById('subject_name').focus();
            }
        });

        // Remove alert message after 5 seconds
        const alert = document.querySelector('.alert');
        if (alert) {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>

</html>