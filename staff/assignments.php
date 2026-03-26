<?php
// staff/assignments.php - Staff Assignments Management
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

// Initialize auth system
initAuthSystem($pdo);

// Handle actions
$action = $_GET['action'] ?? 'view';
$assignment_id = $_GET['id'] ?? 0;
$message = $_GET['message'] ?? '';
$message_type = $_GET['type'] ?? '';

// Get staff assigned classes for filtering
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
    error_log("Error fetching assigned classes: " . $e->getMessage());
    $assigned_classes = [];
}

// Get staff assigned subjects
try {
    $stmt = $pdo->prepare("
        SELECT s.id, s.subject_name 
        FROM subjects s
        INNER JOIN staff_subjects ss ON s.id = ss.subject_id
        WHERE ss.staff_id = ?
        ORDER BY s.subject_name
    ");
    $stmt->execute([$staff_id]);
    $assigned_subjects = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching assigned subjects: " . $e->getMessage());
    $assigned_subjects = [];
}

// Handle different actions
switch ($action) {
    case 'create':
        // Handle assignment creation
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title = $_POST['title'] ?? '';
            $subject_id = $_POST['subject_id'] ?? null;
            $class = $_POST['class'] ?? '';
            $instructions = $_POST['instructions'] ?? '';
            $max_marks = $_POST['max_marks'] ?? 100;
            $deadline = $_POST['deadline'] ?? '';

            // File upload handling
            $file_path = null;
            if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/assignments/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $file_name = time() . '_' . basename($_FILES['assignment_file']['name']);
                $target_file = $upload_dir . $file_name;

                // Validate file type
                $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
                $file_ext = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

                if (in_array($file_ext, $allowed_types)) {
                    if (move_uploaded_file($_FILES['assignment_file']['tmp_name'], $target_file)) {
                        $file_path = 'uploads/assignments/' . $file_name;
                    }
                }
            }

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO assignments (title, subject_id, class, instructions, file_path, deadline, max_marks, staff_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $title,
                    $subject_id ?: null,
                    $class,
                    $instructions,
                    $file_path,
                    $deadline,
                    $max_marks,
                    $staff_id,
                    $staff_id
                ]);

                // Log activity
                logActivity($pdo, $staff_id, 'staff', "Created assignment: $title for class $class");

                header("Location: assignments.php?message=Assignment+created+successfully&type=success");
                exit();
            } catch (Exception $e) {
                error_log("Error creating assignment: " . $e->getMessage());
                $error_message = "Failed to create assignment. Please try again.";
            }
        }
        break;

    case 'edit':
        // Handle assignment editing
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title = $_POST['title'] ?? '';
            $subject_id = $_POST['subject_id'] ?? null;
            $class = $_POST['class'] ?? '';
            $instructions = $_POST['instructions'] ?? '';
            $max_marks = $_POST['max_marks'] ?? 100;
            $deadline = $_POST['deadline'] ?? '';

            // Check if assignment belongs to this staff member
            $stmt = $pdo->prepare("SELECT id FROM assignments WHERE id = ? AND staff_id = ?");
            $stmt->execute([$assignment_id, $staff_id]);

            if ($stmt->rowCount() > 0) {
                try {
                    $update_sql = "
                        UPDATE assignments 
                        SET title = ?, subject_id = ?, class = ?, instructions = ?, 
                            deadline = ?, max_marks = ?, created_by = ?
                        WHERE id = ?
                    ";

                    $stmt = $pdo->prepare($update_sql);
                    $stmt->execute([
                        $title,
                        $subject_id ?: null,
                        $class,
                        $instructions,
                        $deadline,
                        $max_marks,
                        $staff_id,
                        $assignment_id
                    ]);

                    // Log activity
                    logActivity($pdo, $staff_id, 'staff', "Updated assignment: $title");

                    header("Location: assignments.php?message=Assignment+updated+successfully&type=success");
                    exit();
                } catch (Exception $e) {
                    error_log("Error updating assignment: " . $e->getMessage());
                    $error_message = "Failed to update assignment. Please try again.";
                }
            } else {
                $error_message = "You don't have permission to edit this assignment.";
            }
        } else {
            // Load assignment data for editing
            try {
                $stmt = $pdo->prepare("
                    SELECT a.*, s.subject_name 
                    FROM assignments a
                    LEFT JOIN subjects s ON a.subject_id = s.id
                    WHERE a.id = ? AND a.staff_id = ?
                ");
                $stmt->execute([$assignment_id, $staff_id]);
                $assignment = $stmt->fetch();

                if (!$assignment) {
                    header("Location: assignments.php?message=Assignment+not+found&type=error");
                    exit();
                }
            } catch (Exception $e) {
                error_log("Error loading assignment: " . $e->getMessage());
                header("Location: assignments.php?message=Error+loading+assignment&type=error");
                exit();
            }
        }
        break;

    case 'delete':
        // Handle assignment deletion
        try {
            // Check if assignment belongs to this staff member
            $stmt = $pdo->prepare("SELECT title FROM assignments WHERE id = ? AND staff_id = ?");
            $stmt->execute([$assignment_id, $staff_id]);
            $assignment = $stmt->fetch();

            if ($assignment) {
                // Delete associated submissions first
                $stmt = $pdo->prepare("DELETE FROM assignment_submissions WHERE assignment_id = ?");
                $stmt->execute([$assignment_id]);

                // Delete the assignment
                $stmt = $pdo->prepare("DELETE FROM assignments WHERE id = ?");
                $stmt->execute([$assignment_id]);

                // Log activity
                logActivity($pdo, $staff_id, 'staff', "Deleted assignment: " . $assignment['title']);

                header("Location: assignments.php?message=Assignment+deleted+successfully&type=success");
                exit();
            } else {
                header("Location: assignments.php?message=You+don't+have+permission+to+delete+this+assignment&type=error");
                exit();
            }
        } catch (Exception $e) {
            error_log("Error deleting assignment: " . $e->getMessage());
            header("Location: assignments.php?message=Failed+to+delete+assignment&type=error");
            exit();
        }
        break;

    case 'grade':
        // Handle assignment grading
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $submission_id = $_POST['submission_id'] ?? 0;
            $grade = $_POST['grade'] ?? '';
            $feedback = $_POST['feedback'] ?? '';

            try {
                $stmt = $pdo->prepare("
                    UPDATE assignment_submissions 
                    SET grade = ?, teacher_feedback = ?, status = 'graded', graded_at = NOW()
                    WHERE id = ? AND assignment_id IN (
                        SELECT id FROM assignments WHERE staff_id = ?
                    )
                ");

                $stmt->execute([$grade, $feedback, $submission_id, $staff_id]);

                if ($stmt->rowCount() > 0) {
                    // Get student info for logging
                    $stmt = $pdo->prepare("
                        SELECT s.full_name, a.title 
                        FROM assignment_submissions asub
                        JOIN students s ON asub.student_id = s.id
                        JOIN assignments a ON asub.assignment_id = a.id
                        WHERE asub.id = ?
                    ");
                    $stmt->execute([$submission_id]);
                    $submission_info = $stmt->fetch();

                    if ($submission_info) {
                        logActivity(
                            $pdo,
                            $staff_id,
                            'staff',
                            "Graded assignment '{$submission_info['title']}' for student {$submission_info['full_name']} with grade: $grade"
                        );
                    }

                    header("Location: assignments.php?action=submissions&id=$assignment_id&message=Submission+graded+successfully&type=success");
                    exit();
                } else {
                    $error_message = "Failed to grade submission or you don't have permission.";
                }
            } catch (Exception $e) {
                error_log("Error grading submission: " . $e->getMessage());
                $error_message = "Failed to grade submission. Please try again.";
            }
        }
        break;

    case 'submissions':
        // View assignment submissions
        try {
            // Verify assignment belongs to staff
            $stmt = $pdo->prepare("
                SELECT a.*, s.subject_name 
                FROM assignments a
                LEFT JOIN subjects s ON a.subject_id = s.id
                WHERE a.id = ? AND a.staff_id = ?
            ");
            $stmt->execute([$assignment_id, $staff_id]);
            $assignment = $stmt->fetch();

            if (!$assignment) {
                header("Location: assignments.php?message=Assignment+not+found&type=error");
                exit();
            }

            // Get submissions for this assignment
            $stmt = $pdo->prepare("
                SELECT asub.*, stu.full_name, stu.class, stu.admission_number
                FROM assignment_submissions asub
                JOIN students stu ON asub.student_id = stu.id
                WHERE asub.assignment_id = ?
                ORDER BY asub.submitted_at DESC
            ");
            $stmt->execute([$assignment_id]);
            $submissions = $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error loading submissions: " . $e->getMessage());
            $submissions = [];
        }
        break;

    default:
        // View all assignments (default action)
        $filter_class = $_GET['class'] ?? '';
        $filter_subject = $_GET['subject'] ?? '';
        $filter_status = $_GET['status'] ?? '';

        // Build filter conditions
        $conditions = ["a.staff_id = ?"];
        $params = [$staff_id];

        if ($filter_class) {
            $conditions[] = "a.class = ?";
            $params[] = $filter_class;
        }

        if ($filter_subject) {
            $conditions[] = "a.subject_id = ?";
            $params[] = $filter_subject;
        }

        if ($filter_status === 'active') {
            $conditions[] = "a.deadline >= NOW()";
        } elseif ($filter_status === 'expired') {
            $conditions[] = "a.deadline < NOW()";
        }

        $where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        try {
            $stmt = $pdo->prepare("
                SELECT a.*, s.subject_name, 
                       (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) as submission_count,
                       (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id AND status = 'graded') as graded_count
                FROM assignments a
                LEFT JOIN subjects s ON a.subject_id = s.id
                $where_clause
                ORDER BY a.deadline DESC
            ");
            $stmt->execute($params);
            $assignments = $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Error loading assignments: " . $e->getMessage());
            $assignments = [];
        }
        break;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Assignments - Staff Portal</title>

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

        /* Sidebar Styles (reused from dashboard) */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
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
        }

        /* Header */
        .page-header {
            background: white;
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 5px solid var(--accent-color);
        }

        .page-header h1 {
            color: var(--dark-color);
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .page-header p {
            color: #4a5568;
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), #3182ce);
            color: white;
            box-shadow: 0 4px 12px rgba(66, 153, 225, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(66, 153, 225, 0.3);
        }

        .btn-secondary {
            background: white;
            color: var(--dark-color);
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            border-color: var(--secondary-color);
            transform: translateY(-3px);
        }

        /* Content Card */
        .content-card {
            background: white;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
            margin-bottom: 25px;
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

        /* Filter Bar */
        .filter-bar {
            background: var(--light-color);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--dark-color);
        }

        .form-select,
        .form-input {
            padding: 12px 16px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            font-size: 1rem;
            background: white;
            min-width: 180px;
        }

        .form-select:focus,
        .form-input:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .btn-filter {
            background: var(--secondary-color);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            align-self: flex-end;
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
            min-width: 800px;
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

        /* Status Badges */
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

        .status-expired {
            background: #fed7d7;
            color: var(--danger-color);
        }

        .status-submitted {
            background: #bee3f8;
            color: var(--secondary-color);
        }

        .status-graded {
            background: #c6f6d5;
            color: var(--success-color);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            color: white;
        }

        .btn-view {
            background: var(--secondary-color);
        }

        .btn-edit {
            background: var(--warning-color);
        }

        .btn-delete {
            background: var(--danger-color);
        }

        .btn-grade {
            background: var(--success-color);
        }

        .btn-icon:hover {
            transform: translateY(-2px);
            opacity: 0.9;
        }

        /* Forms */
        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark-color);
        }

        .form-control {
            width: 100%;
            padding: 14px 18px;
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
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
            padding: 60px 20px;
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

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 300px;
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

    <!-- Sidebar (reused from dashboard) -->
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
            </div>

            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-students.php"><i class="fas fa-users"></i> My Students</a></li>
                <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
                <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
                <li><a href="assignments.php" class="active"><i class="fas fa-tasks"></i> Assignments</a></li>
                <li><a href="questions.php"><i class="fas fa-question-circle"></i> Question Bank</a></li>
                <li><a href="profile.php"><i class="fas fa-user-cog"></i> My Profile</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>
                    <?php
                    switch ($action) {
                        case 'create':
                            echo 'Create New Assignment';
                            break;
                        case 'edit':
                            echo 'Edit Assignment';
                            break;
                        case 'submissions':
                            echo 'Assignment Submissions';
                            break;
                        case 'grade':
                            echo 'Grade Submission';
                            break;
                        default:
                            echo 'Manage Assignments';
                            break;
                    }
                    ?>
                </h1>
                <p>
                    <?php
                    switch ($action) {
                        case 'create':
                            echo 'Create a new assignment for your students';
                            break;
                        case 'edit':
                            echo 'Edit assignment details';
                            break;
                        case 'submissions':
                            echo 'View and grade student submissions';
                            break;
                        default:
                            echo 'Create, manage, and grade assignments';
                            break;
                    }
                    ?>
                </p>
            </div>
            <div class="header-actions">
                <?php if ($action == 'view'): ?>
                    <a href="assignments.php?action=create" class="btn btn-primary">
                        <i class="fas fa-plus"></i> New Assignment
                    </a>
                <?php elseif ($action == 'create' || $action == 'edit'): ?>
                    <a href="assignments.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                <?php elseif ($action == 'submissions'): ?>
                    <a href="assignments.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Assignments
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type ?: 'success'; ?>">
                <i class="fas fa-<?php
                                    if ($message_type == 'error') echo 'exclamation-triangle';
                                    elseif ($message_type == 'warning') echo 'exclamation-circle';
                                    else echo 'check-circle';
                                    ?>"></i>
                <?php echo htmlspecialchars(urldecode($message)); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Content based on action -->
        <?php if ($action == 'create' || $action == 'edit'): ?>
            <!-- Create/Edit Assignment Form -->
            <div class="content-card">
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="title">Assignment Title *</label>
                            <input type="text" id="title" name="title" class="form-control"
                                value="<?php echo isset($assignment) ? htmlspecialchars($assignment['title']) : ''; ?>"
                                required>
                        </div>

                        <div class="form-group">
                            <label for="class">Class *</label>
                            <select id="class" name="class" class="form-control" required>
                                <option value="">Select Class</option>
                                <?php foreach ($assigned_classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['class']); ?>"
                                        <?php if (isset($assignment) && $assignment['class'] == $class['class']) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($class['class']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="subject_id">Subject</label>
                            <select id="subject_id" name="subject_id" class="form-control">
                                <option value="">Select Subject (Optional)</option>
                                <?php foreach ($assigned_subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>"
                                        <?php if (isset($assignment) && $assignment['subject_id'] == $subject['id']) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="max_marks">Maximum Marks</label>
                            <input type="number" id="max_marks" name="max_marks" class="form-control"
                                value="<?php echo isset($assignment) ? $assignment['max_marks'] : 100; ?>"
                                min="1" max="1000">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="deadline">Deadline *</label>
                            <input type="datetime-local" id="deadline" name="deadline" class="form-control"
                                value="<?php echo isset($assignment) ? date('Y-m-d\TH:i', strtotime($assignment['deadline'])) : ''; ?>"
                                required>
                        </div>

                        <?php if ($action == 'create'): ?>
                            <div class="form-group">
                                <label for="assignment_file">Attachment (Optional)</label>
                                <input type="file" id="assignment_file" name="assignment_file" class="form-control"
                                    accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png">
                                <small class="text-muted">Max file size: 10MB. Allowed: PDF, DOC, DOCX, TXT, JPG, PNG</small>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="instructions">Instructions *</label>
                        <textarea id="instructions" name="instructions" class="form-control" required><?php echo isset($assignment) ? htmlspecialchars($assignment['instructions']) : ''; ?></textarea>
                    </div>

                    <div class="form-group" style="text-align: right;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <?php echo $action == 'create' ? 'Create Assignment' : 'Update Assignment'; ?>
                        </button>
                        <a href="assignments.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>

        <?php elseif ($action == 'submissions'): ?>
            <!-- Submissions List -->
            <?php if (isset($assignment)): ?>
                <div class="content-card">
                    <div class="card-header">
                        <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
                        <div>
                            <span class="status-badge <?php echo strtotime($assignment['deadline']) > time() ? 'status-active' : 'status-expired'; ?>">
                                <?php echo strtotime($assignment['deadline']) > time() ? 'Active' : 'Expired'; ?>
                            </span>
                        </div>
                    </div>

                    <div style="margin-bottom: 25px; padding: 20px; background: var(--light-color); border-radius: 12px;">
                        <p><strong>Deadline:</strong> <?php echo date('F j, Y, g:i a', strtotime($assignment['deadline'])); ?></p>
                        <p><strong>Instructions:</strong> <?php echo nl2br(htmlspecialchars($assignment['instructions'])); ?></p>
                        <?php if ($assignment['file_path']): ?>
                            <p><strong>Attachment:</strong>
                                <a href="../<?php echo $assignment['file_path']; ?>" target="_blank" style="color: var(--secondary-color);">
                                    <i class="fas fa-download"></i> Download File
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($submissions)): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Admission No.</th>
                                        <th>Class</th>
                                        <th>Submitted On</th>
                                        <th>Status</th>
                                        <th>Grade</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($submissions as $submission): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($submission['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($submission['admission_number']); ?></td>
                                            <td><?php echo htmlspecialchars($submission['class']); ?></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($submission['submitted_at'])); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $submission['status'] == 'graded' ? 'status-graded' : 'status-submitted'; ?>">
                                                    <?php echo ucfirst($submission['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($submission['grade']): ?>
                                                    <strong style="color: var(--success-color);"><?php echo $submission['grade']; ?></strong>
                                                <?php else: ?>
                                                    <span style="color: #a0aec0;">Not graded</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-icon btn-view" onclick="viewSubmission(<?php echo $submission['id']; ?>)"
                                                        title="View Submission">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($submission['status'] != 'graded'): ?>
                                                        <button class="btn-icon btn-grade" onclick="gradeSubmission(<?php echo $submission['id']; ?>)"
                                                            title="Grade Submission">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No submissions yet</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- View Submission Modal -->
                <div id="viewModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
                    <div style="background: white; margin: 5% auto; padding: 30px; border-radius: 15px; width: 80%; max-width: 800px; max-height: 80vh; overflow-y: auto;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                            <h3>Submission Details</h3>
                            <button onclick="closeModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #a0aec0;">&times;</button>
                        </div>
                        <div id="modalContent"></div>
                    </div>
                </div>

                <!-- Grade Submission Modal -->
                <div id="gradeModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
                    <div style="background: white; margin: 5% auto; padding: 30px; border-radius: 15px; width: 80%; max-width: 500px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                            <h3>Grade Submission</h3>
                            <button onclick="closeGradeModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #a0aec0;">&times;</button>
                        </div>
                        <form id="gradeForm" method="POST">
                            <input type="hidden" name="submission_id" id="gradeSubmissionId">
                            <input type="hidden" name="assignment_id" value="<?php echo $assignment_id; ?>">

                            <div class="form-group">
                                <label for="grade">Grade</label>
                                <select id="grade" name="grade" class="form-control" required>
                                    <option value="">Select Grade</option>
                                    <option value="A">A (Excellent)</option>
                                    <option value="B">B (Good)</option>
                                    <option value="C">C (Average)</option>
                                    <option value="D">D (Below Average)</option>
                                    <option value="E">E (Poor)</option>
                                    <option value="F">F (Fail)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="feedback">Feedback</label>
                                <textarea id="feedback" name="feedback" class="form-control" rows="4"
                                    placeholder="Provide feedback to the student..."></textarea>
                            </div>

                            <div style="text-align: right; margin-top: 25px;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-check"></i> Submit Grade
                                </button>
                                <button type="button" onclick="closeGradeModal()" class="btn btn-secondary">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                    function viewSubmission(submissionId) {
                        // AJAX call to get submission details
                        fetch(`../api/get_submission.php?id=${submissionId}`)
                            .then(response => response.json())
                            .then(data => {
                                let content = `
                                    <div style="margin-bottom: 20px;">
                                        <h4>Student: ${data.student_name}</h4>
                                        <p><strong>Submitted:</strong> ${data.submitted_at}</p>
                                    </div>
                                `;

                                if (data.submitted_text) {
                                    content += `
                                        <div style="margin-bottom: 20px;">
                                            <h5>Text Submission:</h5>
                                            <div style="padding: 15px; background: #f7fafc; border-radius: 8px;">
                                                ${data.submitted_text}
                                            </div>
                                        </div>
                                    `;
                                }

                                if (data.file_path) {
                                    content += `
                                        <div style="margin-bottom: 20px;">
                                            <h5>Attached File:</h5>
                                            <a href="../${data.file_path}" target="_blank" style="color: var(--secondary-color);">
                                                <i class="fas fa-download"></i> Download File
                                            </a>
                                        </div>
                                    `;
                                }

                                if (data.teacher_feedback) {
                                    content += `
                                        <div style="margin-bottom: 20px;">
                                            <h5>Feedback:</h5>
                                            <div style="padding: 15px; background: #f0fff4; border-radius: 8px;">
                                                <p><strong>Grade:</strong> ${data.grade}</p>
                                                <p><strong>Feedback:</strong> ${data.teacher_feedback}</p>
                                            </div>
                                        </div>
                                    `;
                                }

                                document.getElementById('modalContent').innerHTML = content;
                                document.getElementById('viewModal').style.display = 'block';
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                alert('Error loading submission details');
                            });
                    }

                    function gradeSubmission(submissionId) {
                        document.getElementById('gradeSubmissionId').value = submissionId;
                        document.getElementById('gradeForm').action = 'assignments.php?action=grade&id=<?php echo $assignment_id; ?>';
                        document.getElementById('gradeModal').style.display = 'block';
                    }

                    function closeModal() {
                        document.getElementById('viewModal').style.display = 'none';
                    }

                    function closeGradeModal() {
                        document.getElementById('gradeModal').style.display = 'none';
                    }

                    // Close modals when clicking outside
                    window.onclick = function(event) {
                        const viewModal = document.getElementById('viewModal');
                        const gradeModal = document.getElementById('gradeModal');

                        if (event.target == viewModal) {
                            closeModal();
                        }
                        if (event.target == gradeModal) {
                            closeGradeModal();
                        }
                    }
                </script>

            <?php endif; ?>

        <?php else: ?>
            <!-- Main Assignments List -->
            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-group">
                    <label>Filter by Class:</label>
                    <select class="form-select" id="filterClass" onchange="applyFilters()">
                        <option value="">All Classes</option>
                        <?php foreach ($assigned_classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class']); ?>"
                                <?php if ($filter_class == $class['class']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($class['class']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Filter by Subject:</label>
                    <select class="form-select" id="filterSubject" onchange="applyFilters()">
                        <option value="">All Subjects</option>
                        <?php foreach ($assigned_subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>"
                                <?php if ($filter_subject == $subject['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Filter by Status:</label>
                    <select class="form-select" id="filterStatus" onchange="applyFilters()">
                        <option value="">All Status</option>
                        <option value="active" <?php if ($filter_status == 'active') echo 'selected'; ?>>Active</option>
                        <option value="expired" <?php if ($filter_status == 'expired') echo 'selected'; ?>>Expired</option>
                    </select>
                </div>

                <button class="btn-filter" onclick="clearFilters()">
                    <i class="fas fa-times"></i> Clear Filters
                </button>
            </div>

            <!-- Assignments Table -->
            <div class="content-card">
                <?php if (!empty($assignments)): ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Subject</th>
                                    <th>Class</th>
                                    <th>Deadline</th>
                                    <th>Submissions</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment):
                                    $is_expired = strtotime($assignment['deadline']) < time();
                                    $submission_count = $assignment['submission_count'] ?? 0;
                                    $graded_count = $assignment['graded_count'] ?? 0;
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($assignment['title']); ?></strong>
                                            <?php if ($assignment['file_path']): ?>
                                                <br><small><i class="fas fa-paperclip"></i> Has attachment</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($assignment['subject_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['class']); ?></td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($assignment['deadline'])); ?>
                                            <br>
                                            <small><?php echo date('g:i a', strtotime($assignment['deadline'])); ?></small>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div>
                                                    <strong><?php echo $submission_count; ?></strong> total
                                                    <br>
                                                    <small><?php echo $graded_count; ?> graded</small>
                                                </div>
                                                <?php if ($submission_count > 0): ?>
                                                    <span class="status-badge <?php echo $graded_count == $submission_count ? 'status-graded' : 'status-submitted'; ?>">
                                                        <?php echo $graded_count == $submission_count ? 'All graded' : 'Pending'; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $is_expired ? 'status-expired' : 'status-active'; ?>">
                                                <?php echo $is_expired ? 'Expired' : 'Active'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="assignments.php?action=submissions&id=<?php echo $assignment['id']; ?>"
                                                    class="btn-icon btn-view" title="View Submissions">
                                                    <i class="fas fa-list-check"></i>
                                                </a>
                                                <a href="assignments.php?action=edit&id=<?php echo $assignment['id']; ?>"
                                                    class="btn-icon btn-edit" title="Edit Assignment">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button onclick="confirmDelete(<?php echo $assignment['id']; ?>)"
                                                    class="btn-icon btn-delete" title="Delete Assignment">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-tasks"></i>
                        <p>No assignments found</p>
                        <p style="margin-top: 15px;">
                            <a href="assignments.php?action=create" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Create your first assignment
                            </a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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

        // Filter functions
        function applyFilters() {
            const classFilter = document.getElementById('filterClass').value;
            const subjectFilter = document.getElementById('filterSubject').value;
            const statusFilter = document.getElementById('filterStatus').value;

            let url = 'assignments.php?';
            const params = [];

            if (classFilter) params.push(`class=${encodeURIComponent(classFilter)}`);
            if (subjectFilter) params.push(`subject=${encodeURIComponent(subjectFilter)}`);
            if (statusFilter) params.push(`status=${encodeURIComponent(statusFilter)}`);

            window.location.href = url + params.join('&');
        }

        function clearFilters() {
            window.location.href = 'assignments.php';
        }

        // Delete confirmation
        function confirmDelete(assignmentId) {
            if (confirm('Are you sure you want to delete this assignment? This will also delete all submissions.')) {
                window.location.href = `assignments.php?action=delete&id=${assignmentId}`;
            }
        }

        // Set minimum datetime for deadline input
        const deadlineInput = document.getElementById('deadline');
        if (deadlineInput) {
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            deadlineInput.min = now.toISOString().slice(0, 16);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+N for new assignment
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'assignments.php?action=create';
            }

            // Escape to go back
            if (e.key === 'Escape' && window.location.href.includes('action=')) {
                window.location.href = 'assignments.php';
            }
        });
    </script>
</body>

</html>