<?php
// admin/manage-exams.php - Manage Exams
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

// Add new exam
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_exam'])) {
    try {
        $exam_name = trim($_POST['exam_name']);
        $class = trim($_POST['class']);
        $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
        $topics = isset($_POST['topics']) ? json_encode($_POST['topics']) : '[]';
        $duration_minutes = intval($_POST['duration_minutes']);
        $objective_count = isset($_POST['objective_count']) ? intval($_POST['objective_count']) : 0;
        $subjective_count = isset($_POST['subjective_count']) ? intval($_POST['subjective_count']) : 0;
        $theory_count = isset($_POST['theory_count']) ? intval($_POST['theory_count']) : 0;
        $exam_type = trim($_POST['exam_type']);
        $instructions = trim($_POST['instructions']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $group_id = isset($_POST['group_id']) && !empty($_POST['group_id']) ? intval($_POST['group_id']) : null;

        // Validate required fields
        if (empty($exam_name)) {
            throw new Exception("Exam name is required");
        }
        if (empty($class)) {
            throw new Exception("Class is required");
        }
        if (empty($duration_minutes)) {
            throw new Exception("Duration is required");
        }

        // Insert exam with all required columns
        $stmt = $pdo->prepare("
            INSERT INTO exams (
                exam_name, 
                class, 
                subject_id, 
                topics, 
                duration_minutes,
                objective_count, 
                subjective_count, 
                theory_count, 
                exam_type,
                instructions, 
                is_active, 
                group_id,
                objective_duration,
                theory_duration,
                subjective_duration,
                theory_display,
                created_at
            ) VALUES (
                ?, ?, ?, ?, ?, 
                ?, ?, ?, ?, 
                ?, ?, ?,
                60, 60, 60, 'separate',
                NOW()
            )
        ");

        $result = $stmt->execute([
            $exam_name,
            $class,
            $subject_id,
            $topics,
            $duration_minutes,
            $objective_count,
            $subjective_count,
            $theory_count,
            $exam_type,
            $instructions,
            $is_active,
            $group_id
        ]);

        if ($result) {
            $message = "Exam added successfully!";
            $message_type = "success";

            // Log activity
            logActivity($pdo, $_SESSION['admin_id'], 'admin', "Added new exam: $exam_name");
        } else {
            throw new Exception("Failed to insert exam");
        }
    } catch (Exception $e) {
        $message = "Error adding exam: " . $e->getMessage();
        $message_type = "error";
        error_log("Add exam error: " . $e->getMessage());

        // Log detailed error
        if (isset($stmt) && $stmt->errorInfo()[2]) {
            error_log("SQL Error: " . $stmt->errorInfo()[2]);
        }
    }
}

// Update exam
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_exam'])) {
    try {
        $exam_id = intval($_POST['exam_id']);
        $exam_name = trim($_POST['exam_name']);
        $class = trim($_POST['class']);
        $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
        $topics = isset($_POST['topics']) ? json_encode($_POST['topics']) : '[]';
        $duration_minutes = intval($_POST['duration_minutes']);
        $objective_count = isset($_POST['objective_count']) ? intval($_POST['objective_count']) : 0;
        $subjective_count = isset($_POST['subjective_count']) ? intval($_POST['subjective_count']) : 0;
        $theory_count = isset($_POST['theory_count']) ? intval($_POST['theory_count']) : 0;
        $exam_type = trim($_POST['exam_type']);
        $instructions = trim($_POST['instructions']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $group_id = isset($_POST['group_id']) && !empty($_POST['group_id']) ? intval($_POST['group_id']) : null;

        // Validate required fields
        if (empty($exam_name)) {
            throw new Exception("Exam name is required");
        }
        if (empty($class)) {
            throw new Exception("Class is required");
        }
        if (empty($duration_minutes)) {
            throw new Exception("Duration is required");
        }

        // Update exam
        $stmt = $pdo->prepare("
            UPDATE exams SET
                exam_name = ?,
                class = ?,
                subject_id = ?,
                topics = ?,
                duration_minutes = ?,
                objective_count = ?,
                subjective_count = ?,
                theory_count = ?,
                exam_type = ?,
                instructions = ?,
                is_active = ?,
                group_id = ?
            WHERE id = ?
        ");

        $result = $stmt->execute([
            $exam_name,
            $class,
            $subject_id,
            $topics,
            $duration_minutes,
            $objective_count,
            $subjective_count,
            $theory_count,
            $exam_type,
            $instructions,
            $is_active,
            $group_id,
            $exam_id
        ]);

        if ($result) {
            $message = "Exam updated successfully!";
            $message_type = "success";

            // Log activity
            logActivity($pdo, $_SESSION['admin_id'], 'admin', "Updated exam: $exam_name");
        } else {
            throw new Exception("Failed to update exam");
        }
    } catch (Exception $e) {
        $message = "Error updating exam: " . $e->getMessage();
        $message_type = "error";
        error_log("Update exam error: " . $e->getMessage());

        if (isset($stmt) && $stmt->errorInfo()[2]) {
            error_log("SQL Error: " . $stmt->errorInfo()[2]);
        }
    }
}

// Delete exam
if (isset($_GET['delete_exam'])) {
    try {
        $exam_id = intval($_GET['delete_exam']);

        // First, get exam name for logging
        $stmt = $pdo->prepare("SELECT exam_name FROM exams WHERE id = ?");
        $stmt->execute([$exam_id]);
        $exam = $stmt->fetch();

        if ($exam) {
            // Delete exam
            $stmt = $pdo->prepare("DELETE FROM exams WHERE id = ?");
            $result = $stmt->execute([$exam_id]);

            if ($result) {
                $message = "Exam deleted successfully!";
                $message_type = "success";

                // Log activity
                logActivity($pdo, $_SESSION['admin_id'], 'admin', "Deleted exam: " . $exam['exam_name']);
            } else {
                throw new Exception("Failed to delete exam");
            }
        }
    } catch (Exception $e) {
        $message = "Error deleting exam: " . $e->getMessage();
        $message_type = "error";
        error_log("Delete exam error: " . $e->getMessage());
    }
}

// Toggle exam status
if (isset($_GET['toggle_status'])) {
    try {
        $exam_id = intval($_GET['toggle_status']);

        // Get current status
        $stmt = $pdo->prepare("SELECT is_active, exam_name FROM exams WHERE id = ?");
        $stmt->execute([$exam_id]);
        $exam = $stmt->fetch();

        if ($exam) {
            $new_status = $exam['is_active'] ? 0 : 1;

            // Update status
            $stmt = $pdo->prepare("UPDATE exams SET is_active = ? WHERE id = ?");
            $result = $stmt->execute([$new_status, $exam_id]);

            if ($result) {
                $status_text = $new_status ? "activated" : "deactivated";
                $message = "Exam {$status_text} successfully!";
                $message_type = "success";

                // Log activity
                logActivity($pdo, $_SESSION['admin_id'], 'admin', "{$status_text} exam: " . $exam['exam_name']);
            } else {
                throw new Exception("Failed to toggle exam status");
            }
        }
    } catch (Exception $e) {
        $message = "Error toggling exam status: " . $e->getMessage();
        $message_type = "error";
        error_log("Toggle exam status error: " . $e->getMessage());
    }
}

// Fetch exams with filters
$search = $_GET['search'] ?? '';
$class_filter = $_GET['class'] ?? '';
$subject_filter = $_GET['subject'] ?? '';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';

try {
    // Build query with filters
    $query = "
        SELECT e.*, 
               s.subject_name, 
               sg.group_name,
               COUNT(DISTINCT oq.id) as objective_questions_count,
               COUNT(DISTINCT sq.id) as subjective_questions_count,
               COUNT(DISTINCT tq.id) as theory_questions_count,
               COUNT(DISTINCT es.id) as exam_sessions_count
        FROM exams e
        LEFT JOIN subjects s ON e.subject_id = s.id
        LEFT JOIN subject_groups sg ON e.group_id = sg.id
        LEFT JOIN objective_questions oq ON e.subject_id = oq.subject_id 
            AND (e.class = oq.class OR oq.class IS NULL OR oq.class = '')
        LEFT JOIN subjective_questions sq ON e.subject_id = sq.subject_id 
            AND (e.class = sq.class OR sq.class IS NULL OR sq.class = '')
        LEFT JOIN theory_questions tq ON e.subject_id = tq.subject_id 
            AND (e.class = tq.class OR tq.class IS NULL OR tq.class = '')
        LEFT JOIN exam_sessions es ON e.id = es.exam_id
        WHERE 1=1
    ";

    $params = [];

    if (!empty($search)) {
        $query .= " AND (e.exam_name LIKE ? OR e.class LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($class_filter)) {
        $query .= " AND e.class = ?";
        $params[] = $class_filter;
    }

    if (!empty($subject_filter)) {
        $query .= " AND e.subject_id = ?";
        $params[] = $subject_filter;
    }

    if ($status_filter === 'active') {
        $query .= " AND e.is_active = 1";
    } elseif ($status_filter === 'inactive') {
        $query .= " AND e.is_active = 0";
    }

    if (!empty($type_filter)) {
        $query .= " AND e.exam_type = ?";
        $params[] = $type_filter;
    }

    $query .= " GROUP BY e.id ORDER BY e.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $exams = $stmt->fetchAll();

    // Fetch all subjects for filter dropdown
    $subjects = $pdo->query("SELECT id, subject_name FROM subjects ORDER BY subject_name")->fetchAll();

    // Fetch all classes for filter dropdown
    $classes = $pdo->query("SELECT DISTINCT class FROM exams WHERE class IS NOT NULL AND class != '' ORDER BY class")->fetchAll();

    // Fetch all subject groups
    $subject_groups = $pdo->query("SELECT id, group_name FROM subject_groups WHERE is_active = 1 ORDER BY group_name")->fetchAll();

    // Fetch all topics for topics selection
    $topics = $pdo->query("SELECT id, topic_name, subject_id FROM topics ORDER BY topic_name")->fetchAll();

    // Fetch exam types
    $exam_types = [
        'objective' => 'Objective Only',
        'subjective' => 'Subjective Only',
        'theory' => 'Theory Only'
    ];
} catch (Exception $e) {
    error_log("Fetch exams error: " . $e->getMessage());
    $message = "Error loading exams data: " . $e->getMessage();
    $message_type = "error";
    $exams = [];
    $subjects = [];
    $classes = [];
    $subject_groups = [];
    $topics = [];
}

// Get single exam for editing via AJAX
if (isset($_GET['get_exam'])) {
    try {
        $exam_id = intval($_GET['get_exam']);
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
        $stmt->execute([$exam_id]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($exam) {
            // Decode topics
            $exam['topics'] = json_decode($exam['topics'], true);
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'exam' => $exam]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Exam not found']);
        }
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Exams - Digital CBT System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0 0 20px 0;
        }

        .sidebar-header {
            padding: 0 20px;
            margin-bottom: 20px;
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
            text-decoration: none;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
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

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #219653);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #d68910);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(243, 156, 18, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(149, 165, 166, 0.3);
        }

        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--primary-color);
            font-size: 0.9rem;
        }

        .form-control {
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            transition: border-color 0.3s ease;
            width: 100%;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .data-table th {
            background: var(--primary-color);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.95rem;
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

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-active {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .status-inactive {
            background: #f8d7da;
            color: var(--danger-color);
        }

        .type-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
        }

        .type-objective {
            background: #e3f2fd;
            color: #1976d2;
        }

        .type-subjective {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .type-theory {
            background: #e8f5e8;
            color: #388e3c;
        }

        .action-icons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-icon {
            width: 36px;
            height: 36px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            color: white;
            text-decoration: none;
            font-size: 0.9rem;
            border: none;
        }

        .action-icon.edit {
            background: var(--secondary-color);
        }

        .action-icon.edit:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .action-icon.delete {
            background: var(--danger-color);
        }

        .action-icon.delete:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

        .action-icon.toggle {
            background: var(--warning-color);
        }

        .action-icon.toggle:hover {
            background: #d68910;
            transform: translateY(-2px);
        }

        .action-icon.view {
            background: var(--success-color);
        }

        .action-icon.view:hover {
            background: #219653;
            transform: translateY(-2px);
        }

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
            border-radius: 10px;
            width: 100%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            padding: 20px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }

        .modal-header h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
            transition: color 0.3s ease;
        }

        .modal-close:hover {
            color: var(--danger-color);
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 20px;
            border-top: 2px solid var(--light-color);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
            background: white;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-row {
            grid-column: 1 / -1;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin: 0;
            cursor: pointer;
        }

        .topics-container {
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            padding: 15px;
            max-height: 200px;
            overflow-y: auto;
        }

        .topics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }

        .topic-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .topic-checkbox label {
            cursor: pointer;
        }

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

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 15px;
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, .3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

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

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .filter-form {
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
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-exams.php" class="active"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="manage_questions.php"><i class="fas fa-question-circle"></i> Manage Questions</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>Manage Exams</h1>
                <p>Create, edit, and manage examination schedules</p>
            </div>
            <div class="header-actions">
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="btn btn-primary" onclick="openAddExamModal()">
                <i class="fas fa-plus"></i> Add New Exam
            </button>
            <a href="manage_questions.php" class="btn btn-success">
                <i class="fas fa-question-circle"></i> Manage Questions
            </a>
            <a href="exam-groups.php" class="btn btn-warning">
                <i class="fas fa-layer-group"></i> Exam Groups
            </a>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label for="search"><i class="fas fa-search"></i> Search</label>
                    <input type="text" id="search" name="search" class="form-control"
                        placeholder="Search by exam name or class..." value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="form-group">
                    <label for="class"><i class="fas fa-school"></i> Class</label>
                    <select id="class" name="class" class="form-control">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class']); ?>"
                                <?php echo $class_filter === $class['class'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="subject"><i class="fas fa-book"></i> Subject</label>
                    <select id="subject" name="subject" class="form-control">
                        <option value="">All Subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>"
                                <?php echo $subject_filter == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status"><i class="fas fa-toggle-on"></i> Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="type"><i class="fas fa-file-alt"></i> Exam Type</label>
                    <select id="type" name="type" class="form-control">
                        <option value="">All Types</option>
                        <?php foreach ($exam_types as $key => $value): ?>
                            <option value="<?php echo $key; ?>"
                                <?php echo $type_filter === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($value); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="display: flex; flex-direction: row; gap: 10px; align-items: center;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="manage-exams.php" class="btn btn-secondary" style="flex: 1;">
                        <i class="fas fa-redo"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Exams Table -->
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Exam Name</th>
                        <th>Class</th>
                        <th>Subject</th>
                        <th>Type</th>
                        <th>Duration</th>
                        <th>Questions</th>
                        <th>Sessions</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($exams)): ?>
                        <?php foreach ($exams as $exam): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($exam['exam_name']); ?></strong>
                                    <?php if (!empty($exam['group_name'])): ?>
                                        <br><small class="type-badge type-objective">Group: <?php echo htmlspecialchars($exam['group_name']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($exam['class']); ?></td>
                                <td><?php echo htmlspecialchars($exam['subject_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php
                                    $type_class = 'type-' . $exam['exam_type'];
                                    $type_display = $exam_types[$exam['exam_type']] ?? ucfirst($exam['exam_type']);
                                    ?>
                                    <span class="type-badge <?php echo $type_class; ?>">
                                        <?php echo htmlspecialchars($type_display); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($exam['duration_minutes']); ?> mins</td>
                                <td>
                                    <small>
                                        Obj: <?php echo $exam['objective_questions_count'] ?? 0; ?><br>
                                        Sub: <?php echo $exam['subjective_questions_count'] ?? 0; ?><br>
                                        Thy: <?php echo $exam['theory_questions_count'] ?? 0; ?>
                                    </small>
                                </td>
                                <td><?php echo $exam['exam_sessions_count'] ?? 0; ?></td>
                                <td>
                                    <span class="status-badge <?php echo $exam['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $exam['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-icons">
                                        <button onclick="editExam(<?php echo $exam['id']; ?>)"
                                            class="action-icon edit" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?toggle_status=<?php echo $exam['id']; ?>"
                                            class="action-icon toggle" title="<?php echo $exam['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                            onclick="return confirm('Are you sure you want to <?php echo $exam['is_active'] ? 'deactivate' : 'activate'; ?> this exam?');">
                                            <i class="fas fa-toggle-<?php echo $exam['is_active'] ? 'on' : 'off'; ?>"></i>
                                        </a>
                                        <a href="exam-results.php?exam_id=<?php echo $exam['id']; ?>"
                                            class="action-icon view" title="View Results">
                                            <i class="fas fa-chart-bar"></i>
                                        </a>
                                        <a href="?delete_exam=<?php echo $exam['id']; ?>"
                                            class="action-icon delete" title="Delete"
                                            onclick="return confirm('Are you sure you want to delete this exam? This action cannot be undone.');">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="empty-state">
                                <i class="fas fa-file-alt"></i>
                                <h3>No Exams Found</h3>
                                <p>Click "Add New Exam" to create your first exam.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Export Button -->
        <?php if (!empty($exams)): ?>
            <div style="text-align: center; margin-top: 20px;">
                <a href="export-exams.php" class="btn btn-success">
                    <i class="fas fa-download"></i> Export Exams
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Exam Modal -->
    <div class="modal" id="examModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Exam</h3>
                <button class="modal-close" onclick="closeModal('examModal')">&times;</button>
            </div>
            <form method="POST" id="examForm">
                <input type="hidden" name="exam_id" id="exam_id">

                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="exam_name">Exam Name *</label>
                            <input type="text" id="exam_name" name="exam_name" class="form-control" required
                                placeholder="e.g., First Term Examination">
                        </div>

                        <div class="form-group">
                            <label for="class_input">Class *</label>
                            <input type="text" id="class_input" name="class" class="form-control" required
                                placeholder="e.g., JSS 1 EMERALD">
                        </div>

                        <div class="form-group">
                            <label for="subject_id">Subject</label>
                            <select id="subject_id" name="subject_id" class="form-control">
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="exam_type">Exam Type *</label>
                            <select id="exam_type" name="exam_type" class="form-control" required onchange="updateQuestionCounts()">
                                <?php foreach ($exam_types as $key => $value): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($value); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="duration_minutes">Duration (minutes) *</label>
                            <input type="number" id="duration_minutes" name="duration_minutes" class="form-control"
                                min="1" max="360" value="60" required>
                        </div>

                        <div class="form-group">
                            <label for="objective_count">Objective Questions</label>
                            <input type="number" id="objective_count" name="objective_count" class="form-control"
                                min="0" max="200" value="0">
                        </div>

                        <div class="form-group">
                            <label for="subjective_count">Subjective Questions</label>
                            <input type="number" id="subjective_count" name="subjective_count" class="form-control"
                                min="0" max="50" value="0">
                        </div>

                        <div class="form-group">
                            <label for="theory_count">Theory Questions</label>
                            <input type="number" id="theory_count" name="theory_count" class="form-control"
                                min="0" max="20" value="0">
                        </div>

                        <div class="form-group">
                            <label for="group_id">Subject Group</label>
                            <select id="group_id" name="group_id" class="form-control">
                                <option value="">No Group</option>
                                <?php foreach ($subject_groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>">
                                        <?php echo htmlspecialchars($group['group_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <label>Topics Covered</label>
                            <div class="topics-container">
                                <div class="topics-grid" id="topicsGrid">
                                    <?php if (!empty($topics)): ?>
                                        <?php foreach ($topics as $topic): ?>
                                            <div class="topic-checkbox" data-subject="<?php echo $topic['subject_id']; ?>">
                                                <input type="checkbox" name="topics[]"
                                                    value="<?php echo $topic['id']; ?>"
                                                    id="topic_<?php echo $topic['id']; ?>">
                                                <label for="topic_<?php echo $topic['id']; ?>">
                                                    <?php echo htmlspecialchars($topic['topic_name']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p style="color: #666;">No topics available. Please add topics first.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <small style="color: #666;">Select topics relevant to this exam</small>
                        </div>

                        <div class="form-row">
                            <label for="instructions">Instructions</label>
                            <textarea id="instructions" name="instructions" class="form-control"
                                rows="4" placeholder="Enter exam instructions for students..."></textarea>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" id="is_active" name="is_active" value="1" checked>
                            <label for="is_active">Active (Students can take this exam)</label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('examModal')">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" name="add_exam" id="submitBtn">
                        <i class="fas fa-save"></i> Save Exam
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function() {
                sidebar.classList.toggle('active');
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (sidebar && !sidebar.contains(event.target) && mobileMenuBtn && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Modal functions
        function openAddExamModal() {
            resetForm();
            document.getElementById('modalTitle').textContent = 'Add New Exam';
            document.getElementById('examForm').action = '';
            document.getElementById('submitBtn').name = 'add_exam';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Save Exam';
            openModal('examModal');
        }

        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
                resetForm();
            }
        }

        // Filter topics by subject
        document.getElementById('subject_id')?.addEventListener('change', function() {
            const subjectId = this.value;
            const topicDivs = document.querySelectorAll('.topic-checkbox');

            topicDivs.forEach(div => {
                if (!subjectId || div.dataset.subject === subjectId) {
                    div.style.display = 'flex';
                } else {
                    div.style.display = 'none';
                    const checkbox = div.querySelector('input[type="checkbox"]');
                    if (checkbox) checkbox.checked = false;
                }
            });
        });

        // Update question counts based on exam type
        function updateQuestionCounts() {
            const examType = document.getElementById('exam_type').value;
            const objInput = document.getElementById('objective_count');
            const subInput = document.getElementById('subjective_count');
            const thyInput = document.getElementById('theory_count');

            if (!objInput || !subInput || !thyInput) return;

            // Enable/disable based on exam type
            objInput.disabled = examType !== 'objective';
            subInput.disabled = examType !== 'subjective';
            thyInput.disabled = examType !== 'theory';

            // Clear values if disabled
            if (examType !== 'objective') objInput.value = 0;
            if (examType !== 'subjective') subInput.value = 0;
            if (examType !== 'theory') thyInput.value = 0;
        }

        // Edit exam function - AJAX implementation
        async function editExam(examId) {
            try {
                // Show loading state on button
                const editBtn = event.currentTarget;
                const originalHTML = editBtn.innerHTML;
                editBtn.innerHTML = '<div class="loading"></div>';
                editBtn.disabled = true;

                // Fetch exam data
                const response = await fetch(`?get_exam=${examId}`);
                const data = await response.json();

                // Reset button
                editBtn.innerHTML = originalHTML;
                editBtn.disabled = false;

                if (data.success) {
                    const exam = data.exam;

                    // Reset and populate form
                    resetForm();

                    document.getElementById('modalTitle').textContent = 'Edit Exam';
                    document.getElementById('exam_id').value = exam.id;
                    document.getElementById('exam_name').value = exam.exam_name || '';
                    document.getElementById('class_input').value = exam.class || '';
                    document.getElementById('subject_id').value = exam.subject_id || '';
                    document.getElementById('duration_minutes').value = exam.duration_minutes || 60;
                    document.getElementById('objective_count').value = exam.objective_count || 0;
                    document.getElementById('subjective_count').value = exam.subjective_count || 0;
                    document.getElementById('theory_count').value = exam.theory_count || 0;
                    document.getElementById('exam_type').value = exam.exam_type || 'objective';
                    document.getElementById('instructions').value = exam.instructions || '';
                    document.getElementById('group_id').value = exam.group_id || '';
                    document.getElementById('is_active').checked = exam.is_active == 1;

                    // Update submit button for editing
                    document.getElementById('submitBtn').name = 'update_exam';
                    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-edit"></i> Update Exam';

                    // Check selected topics
                    if (exam.topics) {
                        const topics = typeof exam.topics === 'string' ? JSON.parse(exam.topics) : exam.topics;
                        if (Array.isArray(topics)) {
                            topics.forEach(topicId => {
                                const checkbox = document.getElementById(`topic_${topicId}`);
                                if (checkbox) checkbox.checked = true;
                            });
                        }
                    }

                    // Update question counts based on exam type
                    updateQuestionCounts();

                    // Trigger subject change to filter topics
                    const subjectSelect = document.getElementById('subject_id');
                    if (subjectSelect) {
                        subjectSelect.dispatchEvent(new Event('change'));
                    }

                    openModal('examModal');
                } else {
                    alert('Error loading exam: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error loading exam data. Please try again.');
            }
        }

        // Reset form
        function resetForm() {
            const form = document.getElementById('examForm');
            if (form) form.reset();

            document.getElementById('exam_id').value = '';
            document.getElementById('modalTitle').textContent = 'Add New Exam';
            document.getElementById('submitBtn').name = 'add_exam';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Save Exam';

            // Reset topics
            document.querySelectorAll('input[name="topics[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });

            // Show all topics
            document.querySelectorAll('.topic-checkbox').forEach(div => {
                div.style.display = 'flex';
            });

            // Set default values
            document.getElementById('duration_minutes').value = '60';
            document.getElementById('objective_count').value = '0';
            document.getElementById('subjective_count').value = '0';
            document.getElementById('theory_count').value = '0';
            document.getElementById('is_active').checked = true;

            updateQuestionCounts();
        }

        // Form validation
        document.getElementById('examForm')?.addEventListener('submit', function(e) {
            const examName = document.getElementById('exam_name').value.trim();
            const className = document.getElementById('class_input').value.trim();
            const duration = document.getElementById('duration_minutes').value;

            if (!examName) {
                e.preventDefault();
                alert('Please enter an exam name.');
                return false;
            }

            if (!className) {
                e.preventDefault();
                alert('Please enter a class.');
                return false;
            }

            if (!duration || duration < 1 || duration > 360) {
                e.preventDefault();
                alert('Duration must be between 1 and 360 minutes.');
                return false;
            }

            return true;
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateQuestionCounts();

            // Close modal on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeModal('examModal');
                }
            });
        });
    </script>
</body>

</html>