<?php
// staff/manage-exams.php - Manage Exams (Staff Only)
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
$staff_id = $_SESSION['staff_id']; // This is numeric ID from staff table
$staff_code = $_SESSION['staff_code'] ?? $staff_id; // Check if you have staff_code in session
$staff_name = $_SESSION['staff_name'] ?? 'Staff Member';
$staff_role = $_SESSION['staff_role'] ?? 'staff';

// Initialize auth system
initAuthSystem($pdo);

// First, let's get the staff's actual staff_code from the staff table
try {
    $stmt = $pdo->prepare("SELECT staff_id FROM staff WHERE id = ?");
    $stmt->execute([$staff_id]);
    $staff_row = $stmt->fetch();

    if ($staff_row) {
        $staff_code = $staff_row['staff_id']; // This is the string code like "MSV0001"
    } else {
        $error = "Staff record not found!";
        // Fallback: try to use session staff_id as staff_code
        $staff_code = $_SESSION['staff_id'] ?? $staff_id;
    }
} catch (Exception $e) {
    error_log("Staff code fetch error: " . $e->getMessage());
    $staff_code = $_SESSION['staff_id'] ?? $staff_id;
}

// Get staff assigned subjects and classes using staff_code
$assigned_subjects = [];
$assigned_classes = [];

try {
    // Get staff assigned subjects using staff_code
    $stmt = $pdo->prepare("
        SELECT s.id, s.subject_name 
        FROM subjects s
        INNER JOIN staff_subjects ss ON s.id = ss.subject_id
        WHERE ss.staff_id = ?
        ORDER BY s.subject_name
    ");
    $stmt->execute([$staff_code]);
    $assigned_subjects = $stmt->fetchAll();

    // Get staff assigned classes using staff_code
    $stmt = $pdo->prepare("
        SELECT DISTINCT class 
        FROM staff_classes 
        WHERE staff_id = ?
        ORDER BY class
    ");
    $stmt->execute([$staff_code]);
    $assigned_classes = $stmt->fetchAll();

    // Debug: Log what we found
    error_log("Staff Code: " . $staff_code);
    error_log("Assigned Classes: " . json_encode($assigned_classes));
    error_log("Assigned Subjects: " . json_encode($assigned_subjects));

    // Get subject groups that staff has access to
    $subject_groups = [];
    if (!empty($assigned_classes)) {
        $class_placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
        $class_values = array_column($assigned_classes, 'class');

        // Get groups from exams that staff has access to
        // First get all subject IDs that staff teaches
        $subject_ids = array_column($assigned_subjects, 'id');

        if (!empty($subject_ids)) {
            $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';
            $params = array_merge($class_values, $subject_ids);

            $stmt = $pdo->prepare("
                SELECT DISTINCT sg.* 
                FROM subject_groups sg
                JOIN exams e ON e.group_id = sg.id
                WHERE e.class IN ($class_placeholders)
                AND e.subject_id IN ($subject_placeholders)
                AND sg.is_active = 1
                ORDER BY sg.group_name
            ");
        } else {
            $params = $class_values;

            $stmt = $pdo->prepare("
                SELECT DISTINCT sg.* 
                FROM subject_groups sg
                JOIN exams e ON e.group_id = sg.id
                WHERE e.class IN ($class_placeholders)
                AND sg.is_active = 1
                ORDER BY sg.group_name
            ");
        }

        $stmt->execute($params);
        $subject_groups = $stmt->fetchAll();
    }
} catch (Exception $e) {
    error_log("Manage exams init error: " . $e->getMessage());
    $error = "Error loading your assigned classes and subjects: " . $e->getMessage();
}

// Handle form actions
$action = $_GET['action'] ?? '';
$exam_id = $_GET['exam_id'] ?? 0;

// Initialize variables
$error = '';
$success = '';
$exam_data = null;
$topics_list = [];

// Get topics for assigned subjects
if (!empty($assigned_subjects)) {
    $subject_ids = array_column($assigned_subjects, 'id');
    $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';

    try {
        $stmt = $pdo->prepare("
            SELECT t.*, s.subject_name 
            FROM topics t
            JOIN subjects s ON t.subject_id = s.id
            WHERE t.subject_id IN ($subject_placeholders)
            ORDER BY s.subject_name, t.topic_name
        ");
        $stmt->execute($subject_ids);
        $topics_list = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Topics fetch error: " . $e->getMessage());
    }
}

// Handle create/edit form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_action = $_POST['action'] ?? '';

    if ($form_action === 'create' || $form_action === 'edit') {
        $exam_name = trim($_POST['exam_name'] ?? '');
        $class = trim($_POST['class'] ?? '');
        $subject_id = $_POST['subject_id'] ?? 0;
        $group_id = $_POST['group_id'] ?? 0;
        $exam_type = $_POST['exam_type'] ?? 'objective';
        $duration_minutes = $_POST['duration_minutes'] ?? 60;
        $objective_duration = $_POST['objective_duration'] ?? 60;
        $theory_duration = $_POST['theory_duration'] ?? 60;
        $subjective_duration = $_POST['subjective_duration'] ?? 60;
        $objective_count = $_POST['objective_count'] ?? 0;
        $theory_count = $_POST['theory_count'] ?? 0;
        $subjective_count = $_POST['subjective_count'] ?? 0;
        $topics = $_POST['topics'] ?? [];
        $instructions = trim($_POST['instructions'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $theory_display = $_POST['theory_display'] ?? 'separate';

        // Validate that staff has access to this class
        $has_class_access = false;
        foreach ($assigned_classes as $ac) {
            if ($ac['class'] === $class) {
                $has_class_access = true;
                break;
            }
        }

        // Validate that staff has access to this subject (if subject is selected)
        $has_subject_access = true; // Default to true if no subject selected
        if ($subject_id) {
            $has_subject_access = false;
            foreach ($assigned_subjects as $as) {
                if ($as['id'] == $subject_id) {
                    $has_subject_access = true;
                    break;
                }
            }
        }

        if (!$has_class_access) {
            $error = "You don't have permission to create exams for class: $class";
        } elseif (!$has_subject_access) {
            $error = "You don't have permission to create exams for the selected subject.";
        } elseif (empty($exam_name) || empty($class)) {
            $error = "Exam name and class are required.";
        } else {
            try {
                // Convert topics array to string
                $topics_json = !empty($topics) ? json_encode($topics) : null;

                if ($form_action === 'create') {
                    // Create new exam - NO created_by field since it doesn't exist
                    $stmt = $pdo->prepare("
                        INSERT INTO exams (
                            exam_name, class, subject_id, group_id, exam_type,
                            duration_minutes, objective_duration, theory_duration, subjective_duration,
                            objective_count, theory_count, subjective_count,
                            topics, instructions, is_active, created_at,
                            theory_display
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                    ");

                    $stmt->execute([
                        $exam_name,
                        $class,
                        $subject_id ?: null,
                        $group_id ?: null,
                        $exam_type,
                        $duration_minutes,
                        $objective_duration,
                        $theory_duration,
                        $subjective_duration,
                        $objective_count,
                        $theory_count,
                        $subjective_count,
                        $topics_json,
                        $instructions,
                        $is_active,
                        $theory_display
                    ]);

                    $exam_id = $pdo->lastInsertId();
                    $success = "Exam created successfully!";

                    // Log activity
                    logActivity($staff_id, 'staff', "Created exam: $exam_name for class $class");

                    // Redirect to edit page for question assignment
                    header("Location: manage-exams.php?action=edit&exam_id=$exam_id&message=Exam+created+successfully&type=success");
                    exit();
                } else { // Edit
                    // Verify staff has access to edit this exam
                    // Since there's no created_by, staff can only edit exams for subjects/classes they teach
                    $stmt = $pdo->prepare("
                        SELECT e.* 
                        FROM exams e
                        WHERE e.id = ?
                        AND e.subject_id IN (
                            SELECT subject_id FROM staff_subjects WHERE staff_id = ?
                        )
                        AND e.class IN (
                            SELECT class FROM staff_classes WHERE staff_id = ?
                        )
                    ");
                    $stmt->execute([$exam_id, $staff_code, $staff_code]);
                    $owned_exam = $stmt->fetch();

                    if (!$owned_exam) {
                        $error = "You don't have permission to edit this exam.";
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE exams SET
                                exam_name = ?, class = ?, subject_id = ?, group_id = ?, exam_type = ?,
                                duration_minutes = ?, objective_duration = ?, theory_duration = ?, subjective_duration = ?,
                                objective_count = ?, theory_count = ?, subjective_count = ?,
                                topics = ?, instructions = ?, is_active = ?, theory_display = ?
                            WHERE id = ? 
                            AND subject_id IN (SELECT subject_id FROM staff_subjects WHERE staff_id = ?)
                            AND class IN (SELECT class FROM staff_classes WHERE staff_id = ?)
                        ");

                        $stmt->execute([
                            $exam_name,
                            $class,
                            $subject_id ?: null,
                            $group_id ?: null,
                            $exam_type,
                            $duration_minutes,
                            $objective_duration,
                            $theory_duration,
                            $subjective_duration,
                            $objective_count,
                            $theory_count,
                            $subjective_count,
                            $topics_json,
                            $instructions,
                            $is_active,
                            $theory_display,
                            $exam_id,
                            $staff_code,
                            $staff_code
                        ]);

                        $success = "Exam updated successfully!";

                        // Log activity
                        logActivity($staff_id, 'staff', "Updated exam: $exam_name (ID: $exam_id)");
                    }
                }
            } catch (Exception $e) {
                error_log("Exam save error: " . $e->getMessage());
                $error = "Error saving exam: " . $e->getMessage();
            }
        }
    }

    // Handle delete action
    if ($form_action === 'delete' && isset($_POST['confirm_delete'])) {
        try {
            // Verify staff has access to delete this exam
            $stmt = $pdo->prepare("
                SELECT e.exam_name 
                FROM exams e
                WHERE e.id = ?
                AND e.subject_id IN (SELECT subject_id FROM staff_subjects WHERE staff_id = ?)
                AND e.class IN (SELECT class FROM staff_classes WHERE staff_id = ?)
            ");
            $stmt->execute([$exam_id, $staff_code, $staff_code]);
            $exam = $stmt->fetch();

            if (!$exam) {
                $error = "You don't have permission to delete this exam.";
            } else {
                // Check if exam has any sessions/results
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM exam_sessions WHERE exam_id = ?");
                $stmt->execute([$exam_id]);
                $has_sessions = $stmt->fetch()['count'] > 0;

                if ($has_sessions) {
                    $error = "Cannot delete exam that has been taken by students. Deactivate it instead.";
                } else {
                    // Delete exam
                    $stmt = $pdo->prepare("
                        DELETE FROM exams 
                        WHERE id = ? 
                        AND subject_id IN (SELECT subject_id FROM staff_subjects WHERE staff_id = ?)
                        AND class IN (SELECT class FROM staff_classes WHERE staff_id = ?)
                    ");
                    $deleted = $stmt->execute([$exam_id, $staff_code, $staff_code]);

                    if ($deleted && $stmt->rowCount() > 0) {
                        $success = "Exam deleted successfully!";

                        // Log activity
                        logActivity($staff_id, 'staff', "Deleted exam: {$exam['exam_name']} (ID: $exam_id)");

                        // Redirect to exam list
                        header("Location: manage-exams.php?message=Exam+deleted+successfully&type=success");
                        exit();
                    } else {
                        $error = "You don't have permission to delete this exam.";
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Exam delete error: " . $e->getMessage());
            $error = "Error deleting exam: " . $e->getMessage();
        }
    }

    // Handle toggle active status
    if ($form_action === 'toggle_active' && isset($_POST['exam_id'])) {
        try {
            // Verify staff has access to modify this exam
            $stmt = $pdo->prepare("
                SELECT e.is_active 
                FROM exams e
                WHERE e.id = ?
                AND e.subject_id IN (SELECT subject_id FROM staff_subjects WHERE staff_id = ?)
                AND e.class IN (SELECT class FROM staff_classes WHERE staff_id = ?)
            ");
            $stmt->execute([$exam_id, $staff_code, $staff_code]);
            $exam = $stmt->fetch();

            if (!$exam) {
                $error = "You don't have permission to modify this exam.";
            } else {
                $new_status = $exam['is_active'] ? 0 : 1;
                $stmt = $pdo->prepare("
                    UPDATE exams SET is_active = ? 
                    WHERE id = ? 
                    AND subject_id IN (SELECT subject_id FROM staff_subjects WHERE staff_id = ?)
                    AND class IN (SELECT class FROM staff_classes WHERE staff_id = ?)
                ");
                $stmt->execute([$new_status, $exam_id, $staff_code, $staff_code]);

                $status_text = $new_status ? 'activated' : 'deactivated';
                $success = "Exam $status_text successfully!";

                // Log activity
                logActivity($staff_id, 'staff', "Exam $status_text (ID: $exam_id)");
            }
        } catch (Exception $e) {
            error_log("Toggle active error: " . $e->getMessage());
            $error = "Error updating exam status.";
        }
    }
}

// Fetch exam data for edit/view
if ($action === 'edit' || $action === 'view') {
    try {
        $stmt = $pdo->prepare("
            SELECT e.*, s.subject_name, sg.group_name
            FROM exams e
            LEFT JOIN subjects s ON e.subject_id = s.id
            LEFT JOIN subject_groups sg ON e.group_id = sg.id
            WHERE e.id = ?
            AND e.subject_id IN (SELECT subject_id FROM staff_subjects WHERE staff_id = ?)
            AND e.class IN (SELECT class FROM staff_classes WHERE staff_id = ?)
        ");
        $stmt->execute([$exam_id, $staff_code, $staff_code]);
        $exam_data = $stmt->fetch();

        if (!$exam_data) {
            $error = "Exam not found or you don't have permission to access it.";
            $action = 'list';
        } else {
            // Decode topics JSON
            if (!empty($exam_data['topics'])) {
                $exam_data['topics_array'] = json_decode($exam_data['topics'], true) ?? [];
            } else {
                $exam_data['topics_array'] = [];
            }

            // Since there's no created_by field, staff can edit any exam for their subjects/classes
            $can_edit = true;
        }
    } catch (Exception $e) {
        error_log("Fetch exam error: " . $e->getMessage());
        $error = "Error loading exam data.";
        $action = 'list';
    }
}

// Get exams list for staff
$exams_list = [];
try {
    if (!empty($assigned_classes)) {
        $class_placeholders = str_repeat('?,', count($assigned_classes) - 1) . '?';
        $class_values = array_column($assigned_classes, 'class');

        // Get subject IDs that staff teaches
        $subject_ids = array_column($assigned_subjects, 'id');

        if (!empty($subject_ids)) {
            $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';

            $params = array_merge($subject_ids, $class_values);

            $stmt = $pdo->prepare("
                SELECT e.*, s.subject_name, sg.group_name,
                       (SELECT COUNT(*) FROM exam_sessions es WHERE es.exam_id = e.id) as attempt_count,
                       (SELECT COUNT(*) FROM results r WHERE r.exam_id = e.id) as result_count
                FROM exams e
                LEFT JOIN subjects s ON e.subject_id = s.id
                LEFT JOIN subject_groups sg ON e.group_id = sg.id
                WHERE e.subject_id IN ($subject_placeholders)
                AND e.class IN ($class_placeholders)
                ORDER BY e.created_at DESC
            ");
            $stmt->execute($params);
        } else {
            // If staff has no subjects assigned, show no exams
            // Since exams require a subject, staff with no subjects can't see any exams
            $exams_list = [];
        }

        $exams_list = $stmt->fetchAll();

        // Debug: Log what exams were found
        error_log("Found " . count($exams_list) . " exams for staff");
    } else {
        error_log("No assigned classes found for staff code: " . $staff_code);
    }
} catch (Exception $e) {
    error_log("Exams list error: " . $e->getMessage());
    $error = "Error loading exams list: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Exams - Staff Dashboard</title>

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

        /* Sidebar Styles (same as index.php) */
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

        .header-actions {
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            transition: all 0.3s ease;
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
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c53030);
            color: white;
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.2);
        }

        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(229, 62, 62, 0.3);
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

        /* Exam Form */
        .exam-form-container {
            background: white;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
            margin-bottom: 30px;
        }

        .form-section {
            margin-bottom: 30px;
            padding-bottom: 25px;
            border-bottom: 2px solid var(--light-color);
        }

        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .form-section h3 {
            color: var(--dark-color);
            font-size: 1.4rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section h3 i {
            color: var(--secondary-color);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark-color);
        }

        .required::after {
            content: ' *';
            color: var(--danger-color);
        }

        .form-control {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%234a5568' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 18px center;
            background-size: 16px;
            padding-right: 50px;
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: var(--secondary-color);
        }

        .topics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            max-height: 300px;
            overflow-y: auto;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
        }

        .topic-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            background: var(--light-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .topic-checkbox:hover {
            background: #e2e8f0;
        }

        .topic-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .topic-label {
            font-size: 0.95rem;
            color: var(--dark-color);
        }

        .topic-subject {
            font-size: 0.8rem;
            color: #718096;
            margin-top: 2px;
        }

        /* Exam List Table */
        .table-container {
            background: white;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
            overflow-x: auto;
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

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 8px 12px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .action-btn-edit {
            background: #bee3f8;
            color: var(--secondary-color);
        }

        .action-btn-edit:hover {
            background: #90cdf4;
            color: #2b6cb0;
        }

        .action-btn-view {
            background: #c6f6d5;
            color: var(--success-color);
        }

        .action-btn-view:hover {
            background: #9ae6b4;
            color: #276749;
        }

        .action-btn-delete {
            background: #fed7d7;
            color: var(--danger-color);
        }

        .action-btn-delete:hover {
            background: #fc8181;
            color: #9b2c2c;
        }

        .action-btn-toggle {
            background: #feebc8;
            color: var(--warning-color);
        }

        .action-btn-toggle:hover {
            background: #fbd38d;
            color: #975a16;
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
            color: #718096;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 30px;
        }

        /* Modal */
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
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 18px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
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
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
        }

        .modal-header h3 {
            color: var(--dark-color);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-body {
            margin-bottom: 25px;
        }

        .modal-body p {
            color: #4a5568;
            line-height: 1.6;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            display: none;
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

        /* Responsive */
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
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .top-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
                padding: 20px;
            }

            .header-actions {
                width: 100%;
                justify-content: center;
            }

            .btn {
                padding: 10px 20px;
                font-size: 0.95rem;
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        /* Info Box */
        .info-box {
            background: linear-gradient(135deg, #ebf8ff, #e6fffa);
            border: 2px dashed #b2f5ea;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .info-box i {
            color: var(--secondary-color);
            font-size: 1.5rem;
        }

        .info-box p {
            color: #2d3748;
            line-height: 1.6;
        }

        /* Add these styles to your existing CSS */

        /* Debug Info (Remove in production) */
        .debug-info {
            background: #f7fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            font-family: 'Courier New', monospace;
        }

        .debug-info h4 {
            color: var(--dark-color);
            margin-bottom: 15px;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .debug-info p {
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: #4a5568;
        }

        .debug-info strong {
            color: var(--primary-color);
        }

        /* Access Information */
        .access-info {
            background: linear-gradient(135deg, #fffaf0, #feebc8);
            border: 2px solid #fbd38d;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .access-info h4 {
            color: var(--dark-color);
            font-size: 1.2rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .access-info ul {
            list-style: none;
            padding-left: 10px;
        }

        .access-info li {
            margin-bottom: 10px;
            padding-left: 30px;
            position: relative;
            color: #4a5568;
        }

        .access-info li i {
            position: absolute;
            left: 0;
            top: 2px;
            color: var(--secondary-color);
        }

        .access-info strong {
            color: var(--primary-color);
            font-weight: 600;
        }

        /* Exam stats in list view */
        .exam-stats {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 5px;
            background: var(--light-color);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            color: #4a5568;
        }

        .stat-item i {
            color: var(--secondary-color);
        }

        /* Card layout for exams (alternative to table) */
        .exams-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .exam-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border-left: 4px solid var(--secondary-color);
            transition: all 0.3s ease;
        }

        .exam-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
        }

        .exam-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .exam-card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 5px;
        }

        .exam-card-class {
            font-size: 0.9rem;
            color: #718096;
            background: var(--light-color);
            padding: 3px 10px;
            border-radius: 12px;
            display: inline-block;
        }

        .exam-card-body {
            margin-bottom: 20px;
        }

        .exam-card-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }

        .exam-card-detail {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 0.8rem;
            color: #a0aec0;
            margin-bottom: 2px;
        }

        .detail-value {
            font-weight: 500;
            color: var(--dark-color);
        }

        .exam-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

        /* Form validation styles */
        .form-control.invalid {
            border-color: var(--danger-color);
            background-color: #fff5f5;
        }

        .form-control.valid {
            border-color: var(--success-color);
        }

        .validation-message {
            font-size: 0.85rem;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .validation-message.error {
            color: var(--danger-color);
        }

        .validation-message.success {
            color: var(--success-color);
        }

        /* Loading spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Tab navigation */
        .tab-navigation {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 25px;
        }

        .tab-button {
            padding: 12px 24px;
            background: none;
            border: none;
            font-size: 1rem;
            color: #718096;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }

        .tab-button:hover {
            color: var(--secondary-color);
        }

        .tab-button.active {
            color: var(--secondary-color);
            font-weight: 500;
        }

        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--secondary-color);
        }

        /* Badge variants */
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .badge-primary {
            background: #bee3f8;
            color: var(--secondary-color);
        }

        .badge-success {
            background: #c6f6d5;
            color: var(--success-color);
        }

        .badge-warning {
            background: #feebc8;
            color: var(--warning-color);
        }

        .badge-danger {
            background: #fed7d7;
            color: var(--danger-color);
        }

        .badge-info {
            background: #c3dafe;
            color: #4c51bf;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
        }

        .pagination-button {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            background: white;
            color: var(--dark-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .pagination-button:hover {
            background: var(--light-color);
            border-color: var(--secondary-color);
        }

        .pagination-button.active {
            background: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }

        .pagination-button.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Tooltip */
        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip .tooltip-text {
            visibility: hidden;
            width: 200px;
            background-color: var(--dark-color);
            color: white;
            text-align: center;
            border-radius: 6px;
            padding: 8px 12px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.85rem;
            font-weight: normal;
        }

        .tooltip .tooltip-text::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: var(--dark-color) transparent transparent transparent;
        }

        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }

        /* Responsive improvements */
        @media (max-width: 480px) {
            .main-content {
                padding: 15px;
            }

            .exam-form-container {
                padding: 20px;
            }

            .form-section h3 {
                font-size: 1.2rem;
            }

            .action-buttons {
                flex-direction: column;
                align-items: flex-start;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
            }

            .exams-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Print styles */
        @media print {

            .sidebar,
            .mobile-menu-btn,
            .header-actions,
            .action-buttons {
                display: none !important;
            }

            .main-content {
                margin-left: 0;
                padding: 0;
            }

            .table-container {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .data-table {
                min-width: auto;
            }
        }

        /* Animation for alerts */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert {
            animation: slideIn 0.3s ease;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--secondary-color);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }

        /* Focus styles for accessibility */
        :focus-visible {
            outline: 2px solid var(--secondary-color);
            outline-offset: 2px;
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            :root {
                --primary-color: #000080;
                --secondary-color: #0000cd;
                --accent-color: #ff4500;
                --success-color: #006400;
                --warning-color: #8b4513;
                --danger-color: #8b0000;
            }
        }

        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
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
                <p>Staff ID: <?php echo htmlspecialchars($staff_code); ?></p>
                <div class="staff-role"><?php echo ucfirst(str_replace('_', ' ', $staff_role)); ?></div>
            </div>

            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-students.php"><i class="fas fa-users"></i> My Students</a></li>
                <li><a href="manage-exams.php" class="active"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
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
                <h1>Manage Exams</h1>
                <p>Create, edit, and manage exams for your assigned classes</p>
            </div>
            <div class="header-actions">
                <a href="manage-exams.php" class="btn btn-secondary">
                    <i class="fas fa-list"></i> All Exams
                </a>
                <a href="manage-exams.php?action=create" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Create Exam
                </a>
            </div>
        </div>

        <!-- Debug Info (Remove in production) -->
        <?php if (isset($_GET['debug'])): ?>
            <div class="debug-info">
                <h4><i class="fas fa-bug"></i> Debug Information</h4>
                <p><strong>Session Staff ID:</strong> <?php echo htmlspecialchars($staff_id); ?></p>
                <p><strong>Staff Code:</strong> <?php echo htmlspecialchars($staff_code); ?></p>
                <p><strong>Assigned Classes:</strong> <?php echo json_encode($assigned_classes); ?></p>
                <p><strong>Assigned Subjects:</strong> <?php echo json_encode($assigned_subjects); ?></p>
                <p><strong>Found Exams:</strong> <?php echo count($exams_list); ?></p>
            </div>
        <?php endif; ?>

        <!-- Access Information -->
        <div class="access-info">
            <h4><i class="fas fa-user-check"></i> Your Access Information</h4>
            <ul>
                <li><i class="fas fa-users-class"></i> Assigned Classes:
                    <?php if (!empty($assigned_classes)): ?>
                        <?php foreach ($assigned_classes as $index => $class): ?>
                            <strong><?php echo htmlspecialchars($class['class']); ?></strong><?php echo ($index < count($assigned_classes) - 1) ? ', ' : ''; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span style="color: #e53e3e;">No classes assigned</span>
                    <?php endif; ?>
                </li>
                <li><i class="fas fa-book"></i> Assigned Subjects:
                    <?php if (!empty($assigned_subjects)): ?>
                        <?php foreach ($assigned_subjects as $index => $subject): ?>
                            <strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong><?php echo ($index < count($assigned_subjects) - 1) ? ', ' : ''; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span style="color: #e53e3e;">No subjects assigned</span>
                    <?php endif; ?>
                </li>
                <li><i class="fas fa-info-circle"></i> You can view and manage exams for your assigned classes and subjects</li>
            </ul>
        </div>

        <!-- Alert Messages -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($action === 'create' || $action === 'edit'): ?>
            <!-- Exam Form -->
            <div class="exam-form-container">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="<?php echo $action; ?>">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="exam_id" value="<?php echo $exam_id; ?>">
                    <?php endif; ?>

                    <!-- Basic Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="required">Exam Name</label>
                                <input type="text" name="exam_name" class="form-control" required
                                    value="<?php echo htmlspecialchars($exam_data['exam_name'] ?? ''); ?>"
                                    placeholder="e.g., First Term Mathematics Exam">
                            </div>

                            <div class="form-group">
                                <label class="required">Class</label>
                                <select name="class" class="form-control" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($assigned_classes as $class): ?>
                                        <option value="<?php echo htmlspecialchars($class['class']); ?>"
                                            <?php echo (($exam_data['class'] ?? '') === $class['class']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['class']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Subject</label>
                                <select name="subject_id" class="form-control">
                                    <option value="">Select Subject (Optional)</option>
                                    <?php foreach ($assigned_subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>"
                                            <?php echo (($exam_data['subject_id'] ?? 0) == $subject['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Subject Group</label>
                                <select name="group_id" class="form-control">
                                    <option value="">Select Group (Optional)</option>
                                    <?php foreach ($subject_groups as $group): ?>
                                        <option value="<?php echo $group['id']; ?>"
                                            <?php echo (($exam_data['group_id'] ?? 0) == $group['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($group['group_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color: #718096; font-size: 0.9rem;">Group multiple subjects together</small>
                            </div>
                        </div>
                    </div>

                    <!-- Exam Configuration -->
                    <div class="form-section">
                        <h3><i class="fas fa-cogs"></i> Exam Configuration</h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="required">Exam Type</label>
                                <select name="exam_type" class="form-control" required>
                                    <option value="objective" <?php echo (($exam_data['exam_type'] ?? 'objective') === 'objective') ? 'selected' : ''; ?>>Objective Only</option>
                                    <option value="theory" <?php echo (($exam_data['exam_type'] ?? '') === 'theory') ? 'selected' : ''; ?>>Theory Only</option>
                                    <option value="subjective" <?php echo (($exam_data['exam_type'] ?? '') === 'subjective') ? 'selected' : ''; ?>>Subjective Only</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Total Duration (minutes)</label>
                                <input type="number" name="duration_minutes" class="form-control" min="1" max="300"
                                    value="<?php echo $exam_data['duration_minutes'] ?? 60; ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Objective Questions Count</label>
                                <input type="number" name="objective_count" class="form-control" min="0" max="200"
                                    value="<?php echo $exam_data['objective_count'] ?? 0; ?>">
                                <small style="color: #718096; font-size: 0.9rem;">Leave as 0 for no objective section</small>
                            </div>

                            <div class="form-group">
                                <label>Objective Duration (minutes)</label>
                                <input type="number" name="objective_duration" class="form-control" min="1" max="300"
                                    value="<?php echo $exam_data['objective_duration'] ?? 60; ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Theory Questions Count</label>
                                <input type="number" name="theory_count" class="form-control" min="0" max="50"
                                    value="<?php echo $exam_data['theory_count'] ?? 0; ?>">
                                <small style="color: #718096; font-size: 0.9rem;">Leave as 0 for no theory section</small>
                            </div>

                            <div class="form-group">
                                <label>Theory Duration (minutes)</label>
                                <input type="number" name="theory_duration" class="form-control" min="1" max="300"
                                    value="<?php echo $exam_data['theory_duration'] ?? 60; ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Subjective Questions Count</label>
                                <input type="number" name="subjective_count" class="form-control" min="0" max="50"
                                    value="<?php echo $exam_data['subjective_count'] ?? 0; ?>">
                                <small style="color: #718096; font-size: 0.9rem;">Leave as 0 for no subjective section</small>
                            </div>

                            <div class="form-group">
                                <label>Subjective Duration (minutes)</label>
                                <input type="number" name="subjective_duration" class="form-control" min="1" max="300"
                                    value="<?php echo $exam_data['subjective_duration'] ?? 60; ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Theory Display</label>
                            <select name="theory_display" class="form-control">
                                <option value="separate" <?php echo (($exam_data['theory_display'] ?? 'separate') === 'separate') ? 'selected' : ''; ?>>Separate Questions</option>
                                <option value="combined" <?php echo (($exam_data['theory_display'] ?? '') === 'combined') ? 'selected' : ''; ?>>Combined Questions</option>
                            </select>
                        </div>
                    </div>

                    <!-- Topics -->
                    <div class="form-section">
                        <h3><i class="fas fa-tags"></i> Topics Covered</h3>
                        <p style="color: #718096; margin-bottom: 15px;">Select topics that will be covered in this exam (optional)</p>

                        <?php if (!empty($topics_list)): ?>
                            <div class="topics-grid">
                                <?php foreach ($topics_list as $topic):
                                    $checked = in_array($topic['id'], $exam_data['topics_array'] ?? []);
                                ?>
                                    <label class="topic-checkbox">
                                        <input type="checkbox" name="topics[]" value="<?php echo $topic['id']; ?>"
                                            <?php echo $checked ? 'checked' : ''; ?>>
                                        <div>
                                            <div class="topic-label"><?php echo htmlspecialchars($topic['topic_name']); ?></div>
                                            <div class="topic-subject"><?php echo htmlspecialchars($topic['subject_name']); ?></div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p style="color: #a0aec0; text-align: center; padding: 20px; background: var(--light-color); border-radius: 10px;">
                                No topics available for your assigned subjects. Add topics first.
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Instructions & Settings -->
                    <div class="form-section">
                        <h3><i class="fas fa-file-alt"></i> Instructions & Settings</h3>

                        <div class="form-group">
                            <label>Instructions for Students</label>
                            <textarea name="instructions" class="form-control" rows="4"
                                placeholder="Enter exam instructions..."><?php echo htmlspecialchars($exam_data['instructions'] ?? ''); ?></textarea>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" name="is_active" id="is_active"
                                <?php echo ($exam_data['is_active'] ?? 0) ? 'checked' : ''; ?>>
                            <label for="is_active">Activate this exam (students can take it)</label>
                        </div>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="form-section">
                        <div style="display: flex; gap: 15px;">
                            <button type="submit" class="btn btn-primary" style="flex: 1;">
                                <i class="fas fa-save"></i>
                                <?php echo $action === 'create' ? 'Create Exam' : 'Update Exam'; ?>
                            </button>
                            <a href="manage-exams.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($action === 'edit' && $exam_data): ?>
                <!-- Additional Actions for Edit -->
                <div class="info-box">
                    <i class="fas fa-lightbulb"></i>
                    <div>
                        <p><strong>Next Steps:</strong> After creating the exam, you can:</p>
                        <div style="display: flex; gap: 20px; margin-top: 10px; flex-wrap: wrap;">
                            <a href="questions.php?exam_id=<?php echo $exam_id; ?>" class="action-btn action-btn-edit">
                                <i class="fas fa-question-circle"></i> Add Questions
                            </a>
                            <a href="view-results.php?exam_id=<?php echo $exam_id; ?>" class="action-btn action-btn-view">
                                <i class="fas fa-chart-bar"></i> View Results
                            </a>
                            <a href="manage-exams.php?action=preview&exam_id=<?php echo $exam_id; ?>" class="action-btn action-btn-view" target="_blank">
                                <i class="fas fa-eye"></i> Preview Exam
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- Exams List -->
            <div class="table-container">
                <?php if (!empty($exams_list)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Exam Name</th>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Questions</th>
                                <th>Attempts</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($exams_list as $exam):
                                $questions_count = ($exam['objective_count'] ?? 0) + ($exam['theory_count'] ?? 0) + ($exam['subjective_count'] ?? 0);
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($exam['exam_name']); ?></strong>
                                        <?php if ($exam['group_name']): ?>
                                            <br><small style="color: #718096;">Group: <?php echo htmlspecialchars($exam['group_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($exam['class']); ?></td>
                                    <td><?php echo htmlspecialchars($exam['subject_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php
                                        $type_icons = [
                                            'objective' => 'fa-check-circle',
                                            'theory' => 'fa-pen-fancy',
                                            'subjective' => 'fa-comment-alt'
                                        ];
                                        $icon = $type_icons[$exam['exam_type']] ?? 'fa-file-alt';
                                        ?>
                                        <i class="fas <?php echo $icon; ?>" style="color: var(--secondary-color);"></i>
                                        <?php echo ucfirst($exam['exam_type']); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $exam['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $exam['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($questions_count > 0): ?>
                                            <span style="font-weight: 500;"><?php echo $questions_count; ?></span>
                                            <small style="color: #718096;">
                                                (O:<?php echo $exam['objective_count'] ?? 0; ?>
                                                T:<?php echo $exam['theory_count'] ?? 0; ?>
                                                S:<?php echo $exam['subjective_count'] ?? 0; ?>)
                                            </small>
                                        <?php else: ?>
                                            <span style="color: #a0aec0;">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($exam['attempt_count'] > 0): ?>
                                            <span style="font-weight: 500;"><?php echo $exam['attempt_count']; ?></span>
                                            <br>
                                            <small style="color: #718096;">
                                                Results: <?php echo $exam['result_count']; ?>
                                            </small>
                                        <?php else: ?>
                                            <span style="color: #a0aec0;">No attempts</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($exam['created_at'])): ?>
                                            <?php echo date('M d, Y', strtotime($exam['created_at'])); ?>
                                            <br>
                                            <small style="color: #718096;">
                                                <?php echo date('h:i A', strtotime($exam['created_at'])); ?>
                                            </small>
                                        <?php else: ?>
                                            <span style="color: #a0aec0;">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="manage-exams.php?action=edit&exam_id=<?php echo $exam['id']; ?>" class="action-btn action-btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="manage-exams.php?action=view&exam_id=<?php echo $exam['id']; ?>" class="action-btn action-btn-view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="exam_id" value="<?php echo $exam['id']; ?>">
                                                <button type="submit" class="action-btn action-btn-toggle" title="<?php echo $exam['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                    <i class="fas fa-power-off"></i>
                                                </button>
                                            </form>
                                            <?php if ($exam['attempt_count'] == 0): ?>
                                                <button type="button" class="action-btn action-btn-delete"
                                                    onclick="showDeleteModal(<?php echo $exam['id']; ?>, '<?php echo htmlspecialchars(addslashes($exam['exam_name'])); ?>')" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-file-alt"></i>
                        <h3>No Exams Found</h3>
                        <p>
                            <?php if (empty($assigned_classes)): ?>
                                You don't have any classes assigned to you. Please contact the administrator.
                            <?php elseif (empty($assigned_subjects)): ?>
                                You don't have any subjects assigned to you. Please contact the administrator.
                            <?php else: ?>
                                There are no existing exams for your assigned classes and subjects.
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($assigned_classes) && !empty($assigned_subjects)): ?>
                            <a href="manage-exams.php?action=create" class="btn btn-primary">
                                <i class="fas fa-plus-circle"></i> Create Your First Exam
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h3>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the exam: <strong id="deleteExamName"></strong>?</p>
                <p style="color: var(--danger-color); margin-top: 10px;">
                    <i class="fas fa-exclamation-circle"></i> This action cannot be undone!
                </p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="exam_id" id="deleteExamId">
                    <input type="hidden" name="confirm_delete" value="1">
                    <button type="button" class="btn btn-secondary" onclick="hideDeleteModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Exam</button>
                </form>
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

        // Delete modal functions
        function showDeleteModal(examId, examName) {
            document.getElementById('deleteExamId').value = examId;
            document.getElementById('deleteExamName').textContent = examName;
            document.getElementById('deleteModal').classList.add('active');
        }

        function hideDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(event) {
            if (event.target === this) {
                hideDeleteModal();
            }
        });

        // Handle exam type changes
        const examTypeSelect = document.querySelector('select[name="exam_type"]');
        if (examTypeSelect && !examTypeSelect.disabled) {
            function updateQuestionCounts() {
                const type = examTypeSelect.value;
                const objectiveCount = document.querySelector('input[name="objective_count"]');
                const theoryCount = document.querySelector('input[name="theory_count"]');
                const subjectiveCount = document.querySelector('input[name="subjective_count"]');

                if (type === 'objective') {
                    if (objectiveCount) objectiveCount.disabled = false;
                    if (theoryCount) theoryCount.disabled = true;
                    if (subjectiveCount) subjectiveCount.disabled = true;
                    if (theoryCount) theoryCount.value = 0;
                    if (subjectiveCount) subjectiveCount.value = 0;
                } else if (type === 'theory') {
                    if (objectiveCount) objectiveCount.disabled = true;
                    if (theoryCount) theoryCount.disabled = false;
                    if (subjectiveCount) subjectiveCount.disabled = true;
                    if (objectiveCount) objectiveCount.value = 0;
                    if (subjectiveCount) subjectiveCount.value = 0;
                } else if (type === 'subjective') {
                    if (objectiveCount) objectiveCount.disabled = true;
                    if (theoryCount) theoryCount.disabled = true;
                    if (subjectiveCount) subjectiveCount.disabled = false;
                    if (objectiveCount) objectiveCount.value = 0;
                    if (theoryCount) theoryCount.value = 0;
                }
            }

            examTypeSelect.addEventListener('change', updateQuestionCounts);
            // Initialize on page load
            updateQuestionCounts();
        }

        // Auto-save form data (optional)
        const form = document.querySelector('form');
        if (form && window.location.search.includes('action=create')) {
            const formData = {};

            form.addEventListener('input', function(event) {
                if (event.target.name) {
                    formData[event.target.name] = event.target.value;
                    localStorage.setItem('examFormDraft', JSON.stringify(formData));
                }
            });

            // Load draft on page load
            const draft = localStorage.getItem('examFormDraft');
            if (draft) {
                const formData = JSON.parse(draft);
                Object.keys(formData).forEach(key => {
                    const element = form.querySelector(`[name="${key}"]`);
                    if (element && !element.disabled) {
                        element.value = formData[key];
                    }
                });
            }

            // Clear draft on successful submit
            form.addEventListener('submit', function() {
                localStorage.removeItem('examFormDraft');
            });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+N for new exam
            if (e.ctrlKey && e.key === 'n' && !window.location.search.includes('action=')) {
                e.preventDefault();
                window.location.href = 'manage-exams.php?action=create';
            }

            // Escape to close modal
            if (e.key === 'Escape') {
                hideDeleteModal();
            }

            // Escape to close sidebar on mobile
            if (e.key === 'Escape' && window.innerWidth <= 768) {
                sidebar.classList.remove('active');
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });
    </script>
</body>

</html>