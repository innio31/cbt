<?php
// admin/manage-staff.php - Staff Management
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

// Check permission (only super_admin and admin can manage staff)
if ($admin_role === 'teacher') {
    header("Location: index.php?message=Access+denied&type=error");
    exit();
}

// Initialize variables
$staff_id = null;
$action = $_GET['action'] ?? 'list';
$message = $_GET['message'] ?? '';
$message_type = $_GET['type'] ?? 'success';
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_staff'])) {
        // Add new staff
        $staff_id_num = $_POST['staff_id'] ?? '';
        $full_name = $_POST['full_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $role = $_POST['role'] ?? 'staff';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Validate input
        $errors = [];

        if (empty($staff_id_num)) {
            $errors[] = "Staff ID is required";
        }

        if (empty($full_name)) {
            $errors[] = "Full name is required";
        }

        // Validate email - only if provided
        if (!empty($email)) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Invalid email format";
            }

            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM staff WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = "Email already registered";
            }
        } else {
            $email = null; // Set to NULL for database
        }

        if (empty($password)) {
            $errors[] = "Password is required";
        } elseif (strlen($password) < 6) {
            $errors[] = "Password must be at least 6 characters";
        }

        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }

        // Check if staff ID already exists
        $stmt = $pdo->prepare("SELECT id FROM staff WHERE staff_id = ?");
        $stmt->execute([$staff_id_num]);
        if ($stmt->fetch()) {
            $errors[] = "Staff ID already exists";
        }

        if (empty($errors)) {
            try {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                INSERT INTO staff (staff_id, password, full_name, email, role, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");

                $stmt->execute([
                    $staff_id_num,
                    $hashed_password,
                    $full_name,
                    $email,
                    $role,
                    $is_active
                ]);

                header("Location: manage-staff.php?action=list&message=Staff+added+successfully&type=success");
                exit();
            } catch (Exception $e) {
                $errors[] = "Error adding staff: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['edit_staff'])) {
        // Edit staff
        $staff_id = $_POST['id'] ?? '';
        $full_name = $_POST['full_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $role = $_POST['role'] ?? 'staff';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $change_password = isset($_POST['change_password']);

        $errors = [];

        if (empty($staff_id)) {
            $errors[] = "Staff ID is required";
        }

        if (empty($full_name)) {
            $errors[] = "Full name is required";
        }

        // Validate email - only if provided
        if (!empty($email)) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Invalid email format";
            }

            // Check if email already exists (excluding current staff)
            $stmt = $pdo->prepare("SELECT id FROM staff WHERE email = ? AND id != ?");
            $stmt->execute([$email, $staff_id]);
            if ($stmt->fetch()) {
                $errors[] = "Email already registered to another staff";
            }
        } else {
            $email = null; // Set to NULL for database
        }

        if ($change_password) {
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (empty($password)) {
                $errors[] = "Password is required";
            } elseif (strlen($password) < 6) {
                $errors[] = "Password must be at least 6 characters";
            }

            if ($password !== $confirm_password) {
                $errors[] = "Passwords do not match";
            }
        }

        if (empty($errors)) {
            try {
                if ($change_password) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare("
                    UPDATE staff 
                    SET full_name = ?, email = ?, role = ?, is_active = ?, password = ?
                    WHERE id = ?
                ");

                    $stmt->execute([
                        $full_name,
                        $email,
                        $role,
                        $is_active,
                        $hashed_password,
                        $staff_id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                    UPDATE staff 
                    SET full_name = ?, email = ?, role = ?, is_active = ?
                    WHERE id = ?
                ");

                    $stmt->execute([
                        $full_name,
                        $email,
                        $role,
                        $is_active,
                        $staff_id
                    ]);
                }

                header("Location: manage-staff.php?action=list&message=Staff+updated+successfully&type=success");
                exit();
            } catch (Exception $e) {
                $errors[] = "Error updating staff: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['assign_subjects'])) {
        // Assign subjects to staff
        $staff_id_num = $_POST['staff_id'] ?? ''; // This is the numeric ID

        if (empty($staff_id_num)) {
            header("Location: manage-staff.php?action=list&message=Staff+ID+required&type=error");
            exit();
        }

        // Get the staff_id string from database
        $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ?");
        $stmt->execute([$staff_id_num]);
        $staff_data = $stmt->fetch();

        if (!$staff_data) {
            header("Location: manage-staff.php?action=list&message=Staff+not+found&type=error");
            exit();
        }

        $staff_id_string = $staff_data['staff_id']; // This is what goes into staff_subjects
        $subjects = $_POST['subjects'] ?? [];

        try {
            // Begin transaction
            $pdo->beginTransaction();

            // Remove existing subject assignments - USE STRING ID
            $stmt = $pdo->prepare("DELETE FROM staff_subjects WHERE staff_id = ?");
            $stmt->execute([$staff_id_string]); // Use string ID here

            // Add new subject assignments - USE STRING ID
            if (!empty($subjects)) {
                $stmt = $pdo->prepare("INSERT INTO staff_subjects (staff_id, subject_id, created_at) VALUES (?, ?, NOW())");

                foreach ($subjects as $subject_id) {
                    if (!empty($subject_id)) {
                        $stmt->execute([$staff_id_string, $subject_id]); // Use string ID here
                    }
                }
            }

            $pdo->commit();

            header("Location: manage-staff.php?action=assign_subjects&id=$staff_id_num&message=Subjects+assigned+successfully&type=success");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: manage-staff.php?action=assign_subjects&id=$staff_id_num&message=Error+assigning+subjects&type=error");
            exit();
        }
    } elseif (isset($_POST['assign_classes'])) {
        // Assign classes to staff
        $staff_id_num = $_POST['staff_id'] ?? '';

        if (empty($staff_id_num)) {
            header("Location: manage-staff.php?action=list&message=Staff+ID+required&type=error");
            exit();
        }

        // Get the staff_id string from database
        $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ?");
        $stmt->execute([$staff_id_num]);
        $staff_data = $stmt->fetch();

        if (!$staff_data) {
            header("Location: manage-staff.php?action=list&message=Staff+not+found&type=error");
            exit();
        }

        $staff_id_string = $staff_data['staff_id']; // This is what goes into staff_classes
        $classes = $_POST['classes'] ?? [];

        try {
            // Begin transaction
            $pdo->beginTransaction();

            // Remove existing class assignments - USE STRING ID
            $stmt = $pdo->prepare("DELETE FROM staff_classes WHERE staff_id = ?");
            $stmt->execute([$staff_id_string]); // Use string ID here

            // Add new class assignments - USE STRING ID
            if (!empty($classes)) {
                $stmt = $pdo->prepare("INSERT INTO staff_classes (staff_id, class, created_at) VALUES (?, ?, NOW())");

                foreach ($classes as $class) {
                    if (!empty($class)) {
                        $stmt->execute([$staff_id_string, $class]); // Use string ID here
                    }
                }
            }

            $pdo->commit();

            header("Location: manage-staff.php?action=assign_classes&id=$staff_id_num&message=Classes+assigned+successfully&type=success");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: manage-staff.php?action=assign_classes&id=$staff_id_num&message=Error+assigning+classes&type=error");
            exit();
        }
    }
}

// Get staff data for editing/assigning
if (in_array($action, ['edit', 'assign_subjects', 'assign_classes', 'view']) && isset($_GET['id'])) {
    $staff_id = $_GET['id'];

    $stmt = $pdo->prepare("SELECT * FROM staff WHERE id = ?");
    $stmt->execute([$staff_id]);
    $staff = $stmt->fetch();

    if (!$staff) {
        header("Location: manage-staff.php?action=list&message=Staff+not+found&type=error");
        exit();
    }

    // Get assigned subjects - FIXED
    $stmt = $pdo->prepare("
    SELECT ss.subject_id, s.subject_name 
    FROM staff_subjects ss 
    JOIN subjects s ON ss.subject_id = s.id 
    JOIN staff st ON ss.staff_id = st.staff_id  -- Join with staff table using staff_id string
    WHERE st.id = ?  -- Use numeric ID from URL parameter
");
    $stmt->execute([$staff_id]);
    $assigned_subjects = $stmt->fetchAll();
    $assigned_subject_ids = array_column($assigned_subjects, 'subject_id');

    // Get assigned classes - FIXED
    $stmt = $pdo->prepare("
    SELECT sc.class 
    FROM staff_classes sc
    JOIN staff st ON sc.staff_id = st.staff_id  -- Join with staff table using staff_id string
    WHERE st.id = ?  -- Use numeric ID from URL parameter
");
    $stmt->execute([$staff_id]);
    $assigned_classes = $stmt->fetchAll();
    $assigned_class_names = array_column($assigned_classes, 'class');
}

// Handle delete action - SIMPLIFIED VERSION
if ($action === 'delete' && isset($_GET['id'])) {
    $staff_id = $_GET['id'];

    // Prevent deleting own account
    if ($staff_id == $_SESSION['admin_id']) {
        header("Location: manage-staff.php?action=list&message=Cannot+delete+your+own+account&type=error");
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Get staff_id string
        $stmt = $pdo->prepare("SELECT staff_id, email FROM staff WHERE id = ?");
        $stmt->execute([$staff_id]);
        $staff_data = $stmt->fetch();

        if (!$staff_data) {
            throw new Exception("Staff not found");
        }

        $staff_id_string = $staff_data['staff_id'];
        $staff_email = $staff_data['email'];

        // Delete from staff_classes and staff_subjects (these are the main dependencies)
        $pdo->prepare("DELETE FROM staff_classes WHERE staff_id = ?")->execute([$staff_id_string]);
        $pdo->prepare("DELETE FROM staff_subjects WHERE staff_id = ?")->execute([$staff_id_string]);

        // Delete the staff
        $pdo->prepare("DELETE FROM staff WHERE id = ?")->execute([$staff_id]);

        $pdo->commit();
        header("Location: manage-staff.php?action=list&message=Staff+deleted+successfully&type=success");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();

        // Try deactivation instead
        try {
            $stmt = $pdo->prepare("UPDATE staff SET is_active = 0 WHERE id = ?");
            $stmt->execute([$staff_id]);
            header("Location: manage-staff.php?action=list&message=Staff+could+not+be+deleted+but+was+deactivated&type=warning");
            exit();
        } catch (Exception $e2) {
            error_log("Delete error: " . $e->getMessage() . " | Deactivation error: " . $e2->getMessage());
            header("Location: manage-staff.php?action=list&message=Error+processing+staff+deletion&type=error");
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Staff - Digital CBT System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Include the same styles as index.php -->
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
            overflow-x: hidden;
        }

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
            /* Changed from default */
        }

        /* Add this new class for scrollable content */
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

        /* Form Styles */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
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
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            background-color: white;
            cursor: pointer;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .form-check-label {
            cursor: pointer;
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
            gap: 8px;
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

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #219a52);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #d68910);
            color: white;
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: var(--light-color);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--primary-color);
            border-bottom: 2px solid #ddd;
        }

        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
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

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .action-btn {
            padding: 6px 10px;
            border: none;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .action-btn.view {
            background: #d6eaf8;
            color: var(--secondary-color);
        }

        .action-btn.edit {
            background: #fef9e7;
            color: var(--warning-color);
        }

        .action-btn.delete {
            background: #fdedec;
            color: var(--danger-color);
        }

        .action-btn:hover {
            opacity: 0.8;
        }

        /* Search and Filter */
        .filter-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .filter-form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
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

            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }

            .action-buttons {
                flex-direction: column;
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
            <li><a href="manage-staff.php" class="active"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <!-- <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li> -->
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>Manage Staff</h1>
                <p>Add, edit, and manage staff accounts</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary" onclick="window.location.href='manage-staff.php?action=add'">
                    <i class="fas fa-user-plus"></i> Add New Staff
                </button>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php
                $icon = 'check-circle';
                if ($message_type === 'error') {
                    $icon = 'exclamation-triangle';
                } elseif ($message_type === 'warning') {
                    $icon = 'exclamation-circle';
                }
                ?>
                <i class="fas fa-<?php echo $icon; ?>"></i>
                <?php echo htmlspecialchars(urldecode($message)); ?>
            </div>
        <?php endif; ?>

        <!-- Display form errors if any -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Please fix the following errors:</strong>
                    <ul style="margin-top: 10px; padding-left: 20px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($action === 'list'): ?>
            <!-- Staff List -->
            <div class="filter-container">
                <form method="GET" class="filter-form">
                    <input type="hidden" name="action" value="list">
                    <div class="filter-group">
                        <label class="form-label">Search Staff</label>
                        <input type="text" name="search" class="form-control" placeholder="Search by name, staff ID, or email" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="filter-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='manage-staff.php?action=list'">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                    </div>
                </form>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Staff ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Build query for staff list
                        $query = "SELECT * FROM staff WHERE 1=1";
                        $params = [];

                        if ($search) {
                            $query .= " AND (full_name LIKE ? OR staff_id LIKE ? OR email LIKE ?)";
                            $search_term = "%$search%";
                            array_push($params, $search_term, $search_term, $search_term);
                        }

                        if ($status_filter === 'active') {
                            $query .= " AND is_active = 1";
                        } elseif ($status_filter === 'inactive') {
                            $query .= " AND is_active = 0";
                        }

                        $query .= " ORDER BY created_at DESC";

                        $stmt = $pdo->prepare($query);
                        $stmt->execute($params);
                        $staff_list = $stmt->fetchAll();

                        if (empty($staff_list)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 30px;">
                                    <i class="fas fa-users" style="font-size: 48px; color: #ddd; margin-bottom: 15px;"></i>
                                    <p>No staff members found</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($staff_list as $staff_member): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($staff_member['staff_id']); ?></strong></td>
                                    <td>
                                        <?php echo htmlspecialchars($staff_member['full_name']); ?>
                                        <?php if ($staff_member['role'] === 'admin'): ?>
                                            <span class="status-badge" style="background: #d6eaf8; color: #2980b9; margin-left: 5px;">Admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $email_value = isset($staff_member['email']) && !empty($staff_member['email'])
                                            ? htmlspecialchars($staff_member['email'])
                                            : '<span style="color: #999;">Not provided</span>';
                                        echo $email_value;
                                        ?>
                                    </td>
                                    <td><?php echo ucfirst($staff_member['role']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $staff_member['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $staff_member['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($staff_member['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="manage-staff.php?action=view&id=<?php echo $staff_member['id']; ?>" class="action-btn view">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="manage-staff.php?action=edit&id=<?php echo $staff_member['id']; ?>" class="action-btn edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="manage-staff.php?action=assign_subjects&id=<?php echo $staff_member['id']; ?>" class="action-btn" style="background: #e8f6f3; color: #16a085;">
                                                <i class="fas fa-book"></i> Subjects
                                            </a>
                                            <a href="manage-staff.php?action=assign_classes&id=<?php echo $staff_member['id']; ?>" class="action-btn" style="background: #f4ecf7; color: #8e44ad;">
                                                <i class="fas fa-chalkboard"></i> Classes
                                            </a>
                                            <a href="manage-staff.php?action=delete&id=<?php echo $staff_member['id']; ?>"
                                                class="action-btn delete"
                                                onclick="return confirm('Are you sure you want to delete this staff member? This action cannot be undone.')">
                                                <i class="fas fa-trash"></i> Delete
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Summary Statistics -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 20px;">
                <?php
                // Get statistics
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM staff");
                $total_staff = $stmt->fetch()['total'];

                $stmt = $pdo->query("SELECT COUNT(*) as active FROM staff WHERE is_active = 1");
                $active_staff = $stmt->fetch()['active'];

                $stmt = $pdo->query("SELECT COUNT(*) as admins FROM staff WHERE role = 'admin'");
                $admin_staff = $stmt->fetch()['admins'];
                ?>
                <div style="background: white; padding: 15px; border-radius: 10px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                    <div style="font-size: 2rem; font-weight: 700; color: var(--secondary-color);"><?php echo $total_staff; ?></div>
                    <div style="color: #666; font-size: 0.9rem;">Total Staff</div>
                </div>
                <div style="background: white; padding: 15px; border-radius: 10px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                    <div style="font-size: 2rem; font-weight: 700; color: var(--success-color);"><?php echo $active_staff; ?></div>
                    <div style="color: #666; font-size: 0.9rem;">Active Staff</div>
                </div>
                <div style="background: white; padding: 15px; border-radius: 10px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                    <div style="font-size: 2rem; font-weight: 700; color: var(--warning-color);"><?php echo $admin_staff; ?></div>
                    <div style="color: #666; font-size: 0.9rem;">Administrators</div>
                </div>
            </div>

        <?php elseif ($action === 'add' || $action === 'edit'): ?>
            <!-- Add/Edit Staff Form -->
            <div class="form-container">
                <h2 style="margin-bottom: 25px; color: var(--primary-color);">
                    <i class="fas fa-<?php echo $action === 'add' ? 'user-plus' : 'user-edit'; ?>"></i>
                    <?php echo $action === 'add' ? 'Add New Staff' : 'Edit Staff'; ?>
                </h2>

                <form method="POST" action="">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="id" value="<?php echo $staff['id']; ?>">
                        <input type="hidden" name="edit_staff" value="1">
                    <?php else: ?>
                        <input type="hidden" name="add_staff" value="1">
                    <?php endif; ?>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                        <div class="form-group">
                            <label class="form-label">Staff ID *</label>
                            <input type="text" name="staff_id" class="form-control"
                                value="<?php echo $action === 'edit' ? htmlspecialchars($staff['staff_id']) : ''; ?>"
                                <?php echo $action === 'edit' ? 'readonly' : ''; ?>
                                required>
                            <small style="color: #666; font-size: 0.85rem;">Unique identifier for staff</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="form-control"
                                value="<?php echo $action === 'edit' ? htmlspecialchars($staff['full_name']) : ''; ?>"
                                required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email Address (Optional)</label>
                            <input type="email" name="email" class="form-control"
                                value="<?php echo $action === 'edit' ? (isset($staff['email']) ? htmlspecialchars($staff['email']) : '') : ''; ?>">
                            <small style="color: #666; font-size: 0.85rem;">Optional - can be left blank</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Role *</label>
                            <select name="role" class="form-select" required>
                                <option value="staff" <?php echo ($action === 'edit' && $staff['role'] === 'staff') ? 'selected' : ''; ?>>Staff</option>
                                <option value="admin" <?php echo ($action === 'edit' && $staff['role'] === 'admin') ? 'selected' : ''; ?>>Administrator</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <div class="form-check">
                                <input type="checkbox" name="is_active" id="is_active" class="form-check-input"
                                    <?php echo ($action === 'add' || ($action === 'edit' && $staff['is_active'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">Active Account</label>
                            </div>
                        </div>
                    </div>

                    <!-- Password Section -->
                    <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 10px;">
                        <h3 style="margin-bottom: 15px; color: var(--primary-color);">Password</h3>

                        <?php if ($action === 'edit'): ?>
                            <div class="form-check" style="margin-bottom: 15px;">
                                <input type="checkbox" name="change_password" id="change_password" class="form-check-input"
                                    onclick="togglePasswordFields()">
                                <label class="form-check-label" for="change_password">Change Password</label>
                            </div>
                        <?php endif; ?>

                        <div id="password_fields" <?php echo $action === 'edit' ? 'style="display: none;"' : ''; ?>>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                                <div class="form-group">
                                    <label class="form-label"><?php echo $action === 'add' ? 'Password' : 'New Password'; ?> *</label>
                                    <input type="password" name="password" class="form-control"
                                        <?php echo $action === 'add' ? 'required' : ''; ?>>
                                    <small style="color: #666; font-size: 0.85rem;">Minimum 6 characters</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Confirm Password *</label>
                                    <input type="password" name="confirm_password" class="form-control"
                                        <?php echo $action === 'add' ? 'required' : ''; ?>>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 30px; display: flex; gap: 15px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Staff
                        </button>
                        <a href="manage-staff.php?action=list" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>

        <?php elseif ($action === 'assign_subjects' && isset($staff)): ?>
            <!-- Assign Subjects to Staff -->
            <div class="form-container">
                <h2 style="margin-bottom: 25px; color: var(--primary-color);">
                    <i class="fas fa-book"></i> Assign Subjects to <?php echo htmlspecialchars($staff['full_name']); ?>
                </h2>

                <div style="background: #f0f8ff; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <h4 style="color: var(--secondary-color); margin-bottom: 10px;">Current Subjects</h4>
                    <?php if (!empty($assigned_subjects)): ?>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                            <?php foreach ($assigned_subjects as $subject): ?>
                                <span style="background: #d6eaf8; color: #2980b9; padding: 8px 15px; border-radius: 20px; font-size: 0.9rem;">
                                    <i class="fas fa-book-open"></i> <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="color: #666;">No subjects assigned yet.</p>
                    <?php endif; ?>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="staff_id" value="<?php echo $staff['id']; ?>">
                    <input type="hidden" name="assign_subjects" value="1">

                    <div class="form-group">
                        <label class="form-label">Select Subjects</label>
                        <?php
                        // Get all subjects
                        $stmt = $pdo->query("SELECT * FROM subjects ORDER BY subject_name");
                        $all_subjects = $stmt->fetchAll();

                        if (empty($all_subjects)): ?>
                            <p style="color: #666;">No subjects available. <a href="manage-subjects.php">Add subjects first</a>.</p>
                        <?php else: ?>
                            <div style="max-height: 300px; overflow-y: auto; border: 2px solid #e0e0e0; border-radius: 8px; padding: 15px;">
                                <?php foreach ($all_subjects as $subject): ?>
                                    <div class="form-check">
                                        <input type="checkbox" name="subjects[]" id="subject_<?php echo $subject['id']; ?>"
                                            class="form-check-input" value="<?php echo $subject['id']; ?>"
                                            <?php echo in_array($subject['id'], $assigned_subject_ids) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="subject_<?php echo $subject['id']; ?>">
                                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            <?php if (!empty($subject['description'])): ?>
                                                <small style="color: #666; margin-left: 10px;"><?php echo htmlspecialchars($subject['description']); ?></small>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small style="color: #666; font-size: 0.85rem;">Hold Ctrl (Cmd on Mac) to select multiple subjects</small>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top: 30px; display: flex; gap: 15px;">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check-circle"></i> Assign Subjects
                        </button>
                        <a href="manage-staff.php?action=list" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Back to List
                        </a>
                    </div>
                </form>
            </div>

        <?php elseif ($action === 'assign_classes' && isset($staff)): ?>
            <!-- Assign Classes to Staff -->
            <div class="form-container">
                <h2 style="margin-bottom: 25px; color: var(--primary-color);">
                    <i class="fas fa-chalkboard"></i> Assign Classes to <?php echo htmlspecialchars($staff['full_name']); ?>
                </h2>

                <div style="background: #f0f8ff; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <h4 style="color: var(--secondary-color); margin-bottom: 10px;">Current Classes</h4>
                    <?php if (!empty($assigned_classes)): ?>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                            <?php foreach ($assigned_classes as $class): ?>
                                <span style="background: #d6eaf8; color: #2980b9; padding: 8px 15px; border-radius: 20px; font-size: 0.9rem;">
                                    <i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($class['class']); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="color: #666;">No classes assigned yet.</p>
                    <?php endif; ?>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="staff_id" value="<?php echo $staff['id']; ?>">
                    <input type="hidden" name="assign_classes" value="1">

                    <div class="form-group">
                        <label class="form-label">Select Classes</label>
                        <?php
                        // Get all distinct classes from students
                        $stmt = $pdo->query("SELECT DISTINCT class FROM students WHERE class != '' ORDER BY class");
                        $all_classes = $stmt->fetchAll();

                        if (empty($all_classes)): ?>
                            <p style="color: #666;">No classes available. <a href="manage-students.php">Add students with classes first</a>.</p>
                        <?php else: ?>
                            <div style="max-height: 300px; overflow-y: auto; border: 2px solid #e0e0e0; border-radius: 8px; padding: 15px;">
                                <?php foreach ($all_classes as $class_row): ?>
                                    <div class="form-check">
                                        <input type="checkbox" name="classes[]" id="class_<?php echo $class_row['class']; ?>"
                                            class="form-check-input" value="<?php echo htmlspecialchars($class_row['class']); ?>"
                                            <?php echo in_array($class_row['class'], $assigned_class_names) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="class_<?php echo $class_row['class']; ?>">
                                            <?php echo htmlspecialchars($class_row['class']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small style="color: #666; font-size: 0.85rem;">Hold Ctrl (Cmd on Mac) to select multiple classes</small>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top: 30px; display: flex; gap: 15px;">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check-circle"></i> Assign Classes
                        </button>
                        <a href="manage-staff.php?action=list" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Back to List
                        </a>
                    </div>
                </form>
            </div>

        <?php elseif ($action === 'view' && isset($staff)): ?>
            <!-- View Staff Details -->
            <div class="form-container">
                <h2 style="margin-bottom: 25px; color: var(--primary-color);">
                    <i class="fas fa-user"></i> Staff Details: <?php echo htmlspecialchars($staff['full_name']); ?>
                </h2>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px;">
                    <!-- Personal Information -->
                    <div>
                        <h3 style="color: var(--primary-color); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid var(--light-color);">
                            <i class="fas fa-id-card"></i> Personal Information
                        </h3>
                        <table style="width: 100%;">
                            <tr>
                                <td style="padding: 10px 0; color: #666; width: 120px;">Staff ID:</td>
                                <td style="padding: 10px 0; font-weight: 500;"><?php echo htmlspecialchars($staff['staff_id']); ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 10px 0; color: #666;">Full Name:</td>
                                <td style="padding: 10px 0; font-weight: 500;"><?php echo htmlspecialchars($staff['full_name']); ?></td>
                            </tr>
                            <tr>
                                <td style="padding: 10px 0; color: #666;">Email:</td>
                                <td style="padding: 10px 0; font-weight: 500;">
                                    <?php
                                    $email_display = isset($staff['email']) && !empty($staff['email'])
                                        ? htmlspecialchars($staff['email'])
                                        : 'Not provided';
                                    echo $email_display;
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 10px 0; color: #666;">Role:</td>
                                <td style="padding: 10px 0; font-weight: 500;">
                                    <?php echo ucfirst($staff['role']); ?>
                                    <?php if ($staff['role'] === 'admin'): ?>
                                        <span class="status-badge" style="background: #d6eaf8; color: #2980b9; margin-left: 10px;">Administrator</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 10px 0; color: #666;">Status:</td>
                                <td style="padding: 10px 0; font-weight: 500;">
                                    <span class="status-badge <?php echo $staff['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $staff['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 10px 0; color: #666;">Joined:</td>
                                <td style="padding: 10px 0; font-weight: 500;">
                                    <?php echo date('F j, Y', strtotime($staff['created_at'])); ?>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Assigned Subjects -->
                    <div>
                        <h3 style="color: var(--primary-color); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid var(--light-color);">
                            <i class="fas fa-book"></i> Assigned Subjects
                        </h3>
                        <?php if (!empty($assigned_subjects)): ?>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php foreach ($assigned_subjects as $subject): ?>
                                    <span style="background: #d6eaf8; color: #2980b9; padding: 8px 15px; border-radius: 20px; font-size: 0.9rem;">
                                        <i class="fas fa-book-open"></i> <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p style="color: #666;">No subjects assigned.</p>
                        <?php endif; ?>

                        <div style="margin-top: 20px;">
                            <a href="manage-staff.php?action=assign_subjects&id=<?php echo $staff['id']; ?>" class="btn btn-primary" style="padding: 8px 15px; font-size: 0.9rem;">
                                <i class="fas fa-edit"></i> Edit Subjects
                            </a>
                        </div>
                    </div>

                    <!-- Assigned Classes -->
                    <div>
                        <h3 style="color: var(--primary-color); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid var(--light-color);">
                            <i class="fas fa-chalkboard"></i> Assigned Classes
                        </h3>
                        <?php if (!empty($assigned_classes)): ?>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php foreach ($assigned_classes as $class): ?>
                                    <span style="background: #d6eaf8; color: #2980b9; padding: 8px 15px; border-radius: 20px; font-size: 0.9rem;">
                                        <i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($class['class']); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p style="color: #666;">No classes assigned.</p>
                        <?php endif; ?>

                        <div style="margin-top: 20px;">
                            <a href="manage-staff.php?action=assign_classes&id=<?php echo $staff['id']; ?>" class="btn btn-primary" style="padding: 8px 15px; font-size: 0.9rem;">
                                <i class="fas fa-edit"></i> Edit Classes
                            </a>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid var(--light-color); display: flex; gap: 15px;">
                    <a href="manage-staff.php?action=edit&id=<?php echo $staff['id']; ?>" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit Staff
                    </a>
                    <a href="manage-staff.php?action=list" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        <?php endif; ?>
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

        // Toggle password fields for edit form
        function togglePasswordFields() {
            const passwordFields = document.getElementById('password_fields');
            const passwordInputs = passwordFields.querySelectorAll('input[type="password"]');
            const changePasswordCheckbox = document.getElementById('change_password');

            if (changePasswordCheckbox.checked) {
                passwordFields.style.display = 'block';
                passwordInputs.forEach(input => input.required = true);
            } else {
                passwordFields.style.display = 'none';
                passwordInputs.forEach(input => input.required = false);
            }
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    // Skip validation for email field since it's optional
                    const requiredFields = form.querySelectorAll('[required]:not([name="email"])');
                    let isValid = true;

                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            isValid = false;
                            field.style.borderColor = 'var(--danger-color)';
                        } else {
                            field.style.borderColor = '';
                        }
                    });

                    if (!isValid) {
                        e.preventDefault();
                        alert('Please fill in all required fields.');
                    }
                });
            });
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
            }
        });

        // Auto-hide alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+N for new staff
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'manage-staff.php?action=add';
            }

            // Ctrl+F for search/filter focus
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.querySelector('input[name="search"]');
                if (searchInput) searchInput.focus();
            }

            // Escape to go back
            if (e.key === 'Escape') {
                if (window.location.search.includes('action=') && !window.location.search.includes('action=list')) {
                    window.location.href = 'manage-staff.php?action=list';
                }
            }
        });
    </script>
</body>

</html>