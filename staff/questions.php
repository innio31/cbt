<?php
// staff/questions.php - Staff Question Bank Management
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

// Initialize all variables with default values
$assigned_subjects = [];
$assigned_classes = [];
$questions = [];
$topics = [];
$stats = [];
$message = '';
$message_type = '';
$total_questions = 0;
$total_questions_all = 0;
$total_pages = 1;
$current_page = 1;
$items_per_page = 20;

// Handle form submissions and query parameters
$question_type = $_GET['type'] ?? 'objective';
$current_subject = $_GET['subject'] ?? '';
$current_class = $_GET['class'] ?? '';
$current_topic = $_GET['topic'] ?? '';
$search_term = $_GET['search'] ?? '';
$current_page = $_GET['page'] ?? 1;

// Validate and sanitize inputs
$current_page = max(1, (int)$current_page);
$items_per_page = (int)$items_per_page;

// Validate question type
$valid_types = ['objective', 'subjective', 'theory'];
if (!in_array($question_type, $valid_types)) {
    $question_type = 'objective';
}

// Get staff assigned subjects and classes
try {
    // Get staff assigned subjects
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

    // Handle question deletion
    if (isset($_GET['delete']) && isset($_GET['id'])) {
        $question_id = $_GET['id'];

        // Determine table name based on question type
        if ($question_type === 'objective') {
            $table = 'objective_questions';
        } elseif ($question_type === 'subjective') {
            $table = 'subjective_questions';
        } else {
            $table = 'theory_questions';
        }

        try {
            // Verify staff has access to this question (through subject assignment)
            $stmt = $pdo->prepare("
                DELETE FROM $table 
                WHERE id = ? AND subject_id IN (
                    SELECT subject_id FROM staff_subjects WHERE staff_id = ?
                )
            ");
            $stmt->execute([$question_id, $staff_id]);

            if ($stmt->rowCount() > 0) {
                $message = "Question deleted successfully!";
                $message_type = "success";

                // Log activity
                logActivity($staff_id, 'staff', "Deleted $question_type question ID: $question_id");
            } else {
                $message = "Question not found or you don't have permission to delete it.";
                $message_type = "error";
            }
        } catch (Exception $e) {
            $message = "Error deleting question: " . $e->getMessage();
            $message_type = "error";
        }
    }

    // Handle bulk delete
    if (isset($_POST['bulk_delete']) && isset($_POST['question_ids'])) {
        $question_ids = $_POST['question_ids'];

        // Determine table name based on question type
        if ($question_type === 'objective') {
            $table = 'objective_questions';
        } elseif ($question_type === 'subjective') {
            $table = 'subjective_questions';
        } else {
            $table = 'theory_questions';
        }

        try {
            $placeholders = str_repeat('?,', count($question_ids) - 1) . '?';
            $params = array_merge($question_ids, [$staff_id]);

            $stmt = $pdo->prepare("
                DELETE FROM $table 
                WHERE id IN ($placeholders) 
                AND subject_id IN (
                    SELECT subject_id FROM staff_subjects WHERE staff_id = ?
                )
            ");
            $stmt->execute($params);

            $message = count($question_ids) . " questions deleted successfully!";
            $message_type = "success";

            // Log activity
            logActivity($staff_id, 'staff', "Bulk deleted " . count($question_ids) . " $question_type questions");
        } catch (Exception $e) {
            $message = "Error deleting questions: " . $e->getMessage();
            $message_type = "error";
        }
    }

    // Get topics for selected subject
    if ($current_subject) {
        $stmt = $pdo->prepare("
            SELECT id, topic_name 
            FROM topics 
            WHERE subject_id = ?
            ORDER BY topic_name
        ");
        $stmt->execute([$current_subject]);
        $topics = $stmt->fetchAll();
    }

    // Get questions based on type and filters
    $where_conditions = ["q.subject_id IN (SELECT subject_id FROM staff_subjects WHERE staff_id = ?)"];
    $params = [$staff_id];

    if ($current_subject) {
        $where_conditions[] = "q.subject_id = ?";
        $params[] = $current_subject;
    }

    if ($current_class) {
        $where_conditions[] = "q.class = ?";
        $params[] = $current_class;
    }

    if ($current_topic) {
        $where_conditions[] = "q.topic_id = ?";
        $params[] = $current_topic;
    }

    if ($search_term) {
        if ($question_type === 'objective') {
            $where_conditions[] = "(q.question_text LIKE ? OR q.option_a LIKE ? OR q.option_b LIKE ? OR q.option_c LIKE ? OR q.option_d LIKE ?)";
            $search_param = "%$search_term%";
            for ($i = 0; $i < 5; $i++) {
                $params[] = $search_param;
            }
        } else {
            $where_conditions[] = "(q.question_text LIKE ? OR q.correct_answer LIKE ?)";
            $search_param = "%$search_term%";
            $params[] = $search_param;
            $params[] = $search_param;
        }
    }

    $where_clause = implode(" AND ", $where_conditions);

    // Count total questions - use proper table name
    if ($question_type === 'objective') {
        $table = 'objective_questions';
    } elseif ($question_type === 'subjective') {
        $table = 'subjective_questions';
    } else {
        $table = 'theory_questions';
    }

    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM $table q 
        WHERE $where_clause
    ");
    $count_stmt->execute($params);
    $count_result = $count_stmt->fetch();
    $total_questions = $count_result ? (int)$count_result['total'] : 0;
    $total_pages = ceil($total_questions / $items_per_page);

    // Only fetch questions if there are any
    if ($total_questions > 0) {
        // Calculate offset
        $offset = ($current_page - 1) * $items_per_page;

        // Create modified WHERE clause for LIMIT queries
        // We need to append LIMIT and OFFSET directly to the query, not as parameters
        if ($question_type === 'objective') {
            $query = "
                SELECT q.*, s.subject_name, t.topic_name 
                FROM objective_questions q
                LEFT JOIN subjects s ON q.subject_id = s.id
                LEFT JOIN topics t ON q.topic_id = t.id
                WHERE $where_clause
                ORDER BY q.created_at DESC
                LIMIT $items_per_page OFFSET $offset
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
        } elseif ($question_type === 'subjective') {
            $query = "
                SELECT q.*, s.subject_name, t.topic_name 
                FROM subjective_questions q
                LEFT JOIN subjects s ON q.subject_id = s.id
                LEFT JOIN topics t ON q.topic_id = t.id
                WHERE $where_clause
                ORDER BY q.created_at DESC
                LIMIT $items_per_page OFFSET $offset
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
        } else { // theory
            $query = "
                SELECT q.*, s.subject_name, t.topic_name 
                FROM theory_questions q
                LEFT JOIN subjects s ON q.subject_id = s.id
                LEFT JOIN topics t ON q.topic_id = t.id
                WHERE $where_clause
                ORDER BY q.created_at DESC
                LIMIT $items_per_page OFFSET $offset
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
        }

        $questions = $stmt->fetchAll();
    }

    // Get question statistics - include theory questions
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_questions,
            COUNT(CASE WHEN difficulty_level = 'easy' THEN 1 END) as easy_count,
            COUNT(CASE WHEN difficulty_level = 'medium' THEN 1 END) as medium_count,
            COUNT(CASE WHEN difficulty_level = 'hard' THEN 1 END) as hard_count,
            COUNT(DISTINCT subject_id) as subject_count,
            COUNT(DISTINCT topic_id) as topic_count
        FROM objective_questions 
        WHERE subject_id IN (SELECT subject_id FROM staff_subjects WHERE staff_id = ?)
        UNION ALL
        SELECT 
            COUNT(*) as total_questions,
            COUNT(CASE WHEN difficulty_level = 'easy' THEN 1 END) as easy_count,
            COUNT(CASE WHEN difficulty_level = 'medium' THEN 1 END) as medium_count,
            COUNT(CASE WHEN difficulty_level = 'hard' THEN 1 END) as hard_count,
            COUNT(DISTINCT subject_id) as subject_count,
            COUNT(DISTINCT topic_id) as topic_count
        FROM subjective_questions 
        WHERE subject_id IN (SELECT subject_id FROM staff_subjects WHERE staff_id = ?)
        UNION ALL
        SELECT 
            COUNT(*) as total_questions,
            0 as easy_count,
            0 as medium_count,
            0 as hard_count,
            COUNT(DISTINCT subject_id) as subject_count,
            COUNT(DISTINCT topic_id) as topic_count
        FROM theory_questions 
        WHERE subject_id IN (SELECT subject_id FROM staff_subjects WHERE staff_id = ?)
    ");
    $stmt->execute([$staff_id, $staff_id, $staff_id]);
    $stats = $stmt->fetchAll();

    // Calculate total questions for all types
    $total_questions_all = 0;
    foreach ($stats as $stat) {
        $total_questions_all += $stat['total_questions'] ?? 0;
    }
} catch (Exception $e) {
    // Log detailed error for debugging
    error_log("Question bank error details: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    // For debugging - show actual error
    $message = "Database Error: " . htmlspecialchars($e->getMessage());
    $message_type = "error";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Bank - Staff Portal</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* Reuse the dashboard styles with some additions */
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
            overflow-x: hidden;
        }

        /* Sidebar Styles (same as dashboard) */
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
            font-size: 1.8rem;
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
            gap: 15px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.95rem;
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

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #2f855a);
            color: white;
            box-shadow: 0 4px 12px rgba(56, 161, 105, 0.2);
        }

        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(56, 161, 105, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #b7791f);
            color: white;
            box-shadow: 0 4px 12px rgba(214, 158, 46, 0.2);
        }

        .btn-warning:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(214, 158, 46, 0.3);
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

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--dark-color);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
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

        .form-control-sm {
            padding: 8px 12px;
            font-size: 0.9rem;
        }

        /* Question Type Tabs */
        .question-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            background: white;
            padding: 15px;
            border-radius: 15px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
        }

        .tab-btn {
            padding: 12px 25px;
            background: var(--light-color);
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 500;
            color: #4a5568;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .tab-btn:hover {
            background: #e2e8f0;
            color: var(--dark-color);
        }

        .tab-btn.active {
            background: linear-gradient(135deg, var(--secondary-color), #3182ce);
            color: white;
            box-shadow: 0 4px 12px rgba(66, 153, 225, 0.2);
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
            text-align: center;
            border-top: 4px solid var(--secondary-color);
        }

        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 5px;
        }

        .stat-card .stat-label {
            font-size: 0.9rem;
            color: #718096;
        }

        /* Questions Table */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 25px;
        }

        .table-header {
            padding: 20px 25px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            color: var(--dark-color);
            font-size: 1.3rem;
            font-weight: 600;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
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
            vertical-align: top;
        }

        .data-table tr:hover {
            background: #f7fafc;
        }

        .question-text {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 6px 12px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .action-btn.view {
            background: var(--light-color);
            color: var(--dark-color);
        }

        .action-btn.edit {
            background: #bee3f8;
            color: var(--secondary-color);
        }

        .action-btn.delete {
            background: #fed7d7;
            color: var(--danger-color);
        }

        .action-btn:hover {
            transform: translateY(-2px);
        }

        /* Difficulty Badges */
        .difficulty-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .difficulty-easy {
            background: #c6f6d5;
            color: var(--success-color);
        }

        .difficulty-medium {
            background: #feebc8;
            color: var(--warning-color);
        }

        .difficulty-hard {
            background: #fed7d7;
            color: var(--danger-color);
        }

        /* Checkbox for bulk actions */
        .bulk-checkbox {
            transform: scale(1.2);
        }

        /* Bulk Actions */
        .bulk-actions {
            background: white;
            padding: 15px 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 25px;
        }

        .page-link {
            padding: 8px 16px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            color: #4a5568;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .page-link:hover {
            border-color: var(--secondary-color);
            color: var(--secondary-color);
        }

        .page-link.active {
            background: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.95rem;
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
            padding: 50px 20px;
            color: #a0aec0;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 15px;
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

            .top-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
                padding: 15px;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .question-tabs {
                flex-wrap: wrap;
            }

            .data-table {
                display: block;
                overflow-x: auto;
            }

            .mobile-menu-btn {
                display: block;
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 101;
                background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
                color: white;
                border: none;
                width: 45px;
                height: 45px;
                border-radius: 10px;
                font-size: 20px;
                cursor: pointer;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
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
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
        }

        .modal-header h3 {
            color: var(--dark-color);
            font-size: 1.4rem;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #a0aec0;
            transition: color 0.3s ease;
        }

        .modal-close:hover {
            color: var(--danger-color);
        }

        /* Question Preview */
        .question-preview {
            background: #f7fafc;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .question-preview h4 {
            color: var(--dark-color);
            margin-bottom: 10px;
            font-size: 1.1rem;
        }

        .options-list {
            list-style: none;
            margin-top: 15px;
        }

        .options-list li {
            padding: 8px 12px;
            margin-bottom: 8px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid #e2e8f0;
        }

        .options-list li.correct {
            border-left-color: var(--success-color);
            background: #f0fff4;
        }

        /* Add/modify these styles in the questions.php CSS section */

        /* Table Container - Make it scrollable horizontally */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow-x: auto;
            overflow-y: visible;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 25px;
            position: relative;
        }

        /* Add a scroll indicator for better UX */
        .table-container::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            width: 30px;
            background: linear-gradient(to right, transparent, rgba(0, 0, 0, 0.05));
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .table-container.has-scroll::after {
            opacity: 1;
        }

        /* Ensure the table doesn't break layout */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
            /* Minimum width to ensure readability */
        }

        .data-table th,
        .data-table td {
            padding: 16px 20px;
            text-align: left;
            vertical-align: top;
            white-space: normal;
            word-wrap: break-word;
        }

        /* Specific column widths for better display */
        .data-table th:first-child,
        .data-table td:first-child {
            width: 50px;
            text-align: center;
        }

        .data-table th:nth-child(2),
        .data-table td:nth-child(2) {
            min-width: 250px;
            max-width: 350px;
        }

        .data-table th:nth-child(3),
        .data-table td:nth-child(3),
        .data-table th:nth-child(4),
        .data-table td:nth-child(4),
        .data-table th:nth-child(5),
        .data-table td:nth-child(5) {
            min-width: 120px;
        }

        .data-table th:nth-child(6),
        .data-table td:nth-child(6) {
            min-width: 100px;
        }

        .data-table th:nth-child(7),
        .data-table td:nth-child(7) {
            min-width: 80px;
        }

        .data-table th:last-child,
        .data-table td:last-child {
            min-width: 150px;
            white-space: nowrap;
        }

        /* Question text styling */
        .question-text {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: normal;
            line-height: 1.5;
            word-break: break-word;
        }

        /* Add a custom scrollbar for better appearance */
        .table-container::-webkit-scrollbar {
            height: 8px;
            background: #f1f1f1;
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: var(--secondary-color);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }

        /* Action buttons should stay together */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Responsive table adjustments */
        @media (max-width: 768px) {
            .table-container {
                margin: 0 -10px 20px -10px;
                border-radius: 0;
            }

            .data-table th,
            .data-table td {
                padding: 12px 15px;
            }

            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
            }

            .question-text {
                max-width: 200px;
            }
        }

        /* For very small screens */
        @media (max-width: 480px) {

            .data-table th,
            .data-table td {
                padding: 10px 12px;
                font-size: 0.85rem;
            }

            .question-text {
                max-width: 150px;
                font-size: 0.85rem;
            }

            .difficulty-badge {
                font-size: 0.7rem;
                padding: 3px 8px;
            }

            .action-btn {
                padding: 4px 8px;
                font-size: 0.75rem;
            }
        }

        /* Sticky header for horizontal scrolling */
        .data-table th {
            background: linear-gradient(135deg, var(--light-color), #e2e8f0);
            padding: 16px 20px;
            text-align: left;
            font-weight: 600;
            color: var(--dark-color);
            border-bottom: 2px solid #cbd5e0;
            font-size: 0.95rem;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        /* Different background for sticky header to ensure readability */
        .data-table th:first-child {
            left: 0;
            z-index: 11;
        }

        /* Make first column sticky for better context */
        .data-table td:first-child,
        .data-table th:first-child {
            position: sticky;
            left: 0;
            background: inherit;
            z-index: 5;
        }

        .data-table th:first-child {
            background: linear-gradient(135deg, var(--light-color), #e2e8f0);
            z-index: 11;
        }

        .data-table tr:hover td:first-child {
            background: #f7fafc;
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
                <li><a href="manage-students.php"><i class="fas fa-users"></i> My Students</a></li>
                <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
                <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
                <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
                <li><a href="questions.php" class="active"><i class="fas fa-question-circle"></i> Question Bank</a></li>
                <li><a href="profile.php"><i class="fas fa-user-cog"></i> My Profile</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : ($message_type === 'warning' ? 'exclamation-circle' : 'check-circle'); ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>Question Bank</h1>
                <p>Manage and organize your question repository</p>
            </div>
            <div class="header-actions">
                <a href="add-question.php?type=<?php echo $question_type; ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Question
                </a>
                <button class="btn btn-success" onclick="exportQuestions()">
                    <i class="fas fa-upload"></i> Export
                </button>
            </div>
        </div>

        <!-- Question Type Tabs -->
        <div class="question-tabs">
            <button class="tab-btn <?php echo $question_type === 'objective' ? 'active' : ''; ?>" onclick="changeQuestionType('objective')">
                <i class="fas fa-list-ul"></i> Objective
            </button>
            <button class="tab-btn <?php echo $question_type === 'subjective' ? 'active' : ''; ?>" onclick="changeQuestionType('subjective')">
                <i class="fas fa-pen-alt"></i> Subjective
            </button>
            <button class="tab-btn <?php echo $question_type === 'theory' ? 'active' : ''; ?>" onclick="changeQuestionType('theory')">
                <i class="fas fa-file-alt"></i> Theory
            </button>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_questions_all; ?></div>
                <div class="stat-label">Total Questions</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($assigned_subjects); ?></div>
                <div class="stat-label">Subjects</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($assigned_classes); ?></div>
                <div class="stat-label">Classes</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_questions; ?></div>
                <div class="stat-label">Filtered Questions</div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label>Subject</label>
                    <select name="subject" class="form-control" onchange="this.form.submit()">
                        <option value="">All Subjects</option>
                        <?php foreach ($assigned_subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>" <?php echo $current_subject == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Class</label>
                    <select name="class" class="form-control" onchange="this.form.submit()">
                        <option value="">All Classes</option>
                        <?php foreach ($assigned_classes as $class): ?>
                            <option value="<?php echo $class['class']; ?>" <?php echo $current_class == $class['class'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Topic</label>
                    <select name="topic" class="form-control" onchange="this.form.submit()">
                        <option value="">All Topics</option>
                        <?php foreach ($topics as $topic): ?>
                            <option value="<?php echo $topic['id']; ?>" <?php echo $current_topic == $topic['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($topic['topic_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search questions..." value="<?php echo htmlspecialchars($search_term); ?>">
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-search"></i> Filter
                    </button>
                </div>
            </form>
        </div>

        <!-- Bulk Actions -->
        <?php if ($total_questions > 0): ?>
            <form method="POST" class="bulk-actions" id="bulkForm">
                <input type="hidden" name="question_type" value="<?php echo $question_type; ?>">
                <input type="checkbox" id="selectAll" class="bulk-checkbox">
                <label for="selectAll" style="font-weight: 500;">Select All</label>
                <button type="button" class="btn btn-danger" onclick="confirmBulkDelete()" style="margin-left: auto;">
                    <i class="fas fa-trash"></i> Delete Selected
                </button>
            </form>
        <?php endif; ?>

        <!-- In the table container section, modify the table structure -->
        <div class="table-container">
            <div class="table-header">
                <h3><?php echo ucfirst($question_type); ?> Questions (<?php echo $total_questions; ?>)</h3>
                <div>
                    <button class="btn btn-warning" onclick="importQuestions()">
                        <i class="fas fa-download"></i> Import
                    </button>
                </div>
            </div>

            <?php if ($total_questions > 0): ?>
                <div style="overflow-x: auto; position: relative;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th width="50">
                                    <input type="checkbox" id="selectAllTable" class="bulk-checkbox">
                                </th>
                                <th>Question</th>
                                <th>Subject</th>
                                <th>Class</th>
                                <th>Topic</th>
                                <th>Difficulty</th>
                                <th>Marks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $question): ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="question_ids[]" value="<?php echo $question['id']; ?>" class="bulk-checkbox question-checkbox">
                                    </td>
                                    <td>
                                        <div class="question-text" title="<?php echo htmlspecialchars($question['question_text']); ?>">
                                            <?php
                                            $display_text = htmlspecialchars(strip_tags($question['question_text']));
                                            echo strlen($display_text) > 100 ? substr($display_text, 0, 100) . '...' : $display_text;
                                            ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($question['subject_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($question['class'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($question['topic_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="difficulty-badge difficulty-<?php echo $question['difficulty_level'] ?? 'medium'; ?>">
                                            <?php echo ucfirst($question['difficulty_level'] ?? 'Medium'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $question['marks'] ?? 1; ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn view" onclick="viewQuestion(<?php echo $question['id']; ?>, '<?php echo $question_type; ?>')">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <a href="edit-question.php?id=<?php echo $question['id']; ?>&type=<?php echo $question_type; ?>" class="action-btn edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <button class="action-btn delete" onclick="confirmDelete(<?php echo $question['id']; ?>, '<?php echo $question_type; ?>')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <!-- Pagination links here -->
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-question-circle"></i>
                    <p>No questions found matching your criteria.</p>
                    <a href="add-question.php?type=<?php echo $question_type; ?>" class="btn btn-primary" style="margin-top: 15px;">
                        <i class="fas fa-plus"></i> Add Your First Question
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Question Preview Modal -->
        <div class="modal" id="questionModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Question Preview</h3>
                    <button class="modal-close" onclick="closeModal()">&times;</button>
                </div>
                <div id="questionPreview"></div>
            </div>
        </div>

        <!-- Bulk Delete Confirmation Modal -->
        <div class="modal" id="confirmModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Confirm Delete</h3>
                    <button class="modal-close" onclick="closeConfirmModal()">&times;</button>
                </div>
                <div style="padding: 20px 0;">
                    <p>Are you sure you want to delete the selected questions? This action cannot be undone.</p>
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button class="btn btn-danger" onclick="submitBulkDelete()">Delete</button>
                        <button class="btn" onclick="closeConfirmModal()" style="background: var(--light-color);">Cancel</button>
                    </div>
                </div>
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

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });

        // Change question type
        function changeQuestionType(type) {
            const url = new URL(window.location.href);
            url.searchParams.set('type', type);
            window.location.href = url.toString();
        }

        // View question details
        function viewQuestion(questionId, questionType) {
            fetch(`get-question.php?id=${questionId}&type=${questionType}`)
                .then(response => response.json())
                .then(data => {
                    let html = '<div class="question-preview">';

                    if (questionType === 'objective') {
                        html += `
                            <h4>${escapeHtml(data.question_text)}</h4>
                            <ul class="options-list">
                                <li class="${data.correct_answer === 'A' ? 'correct' : ''}">A. ${escapeHtml(data.option_a)}</li>
                                <li class="${data.correct_answer === 'B' ? 'correct' : ''}">B. ${escapeHtml(data.option_b)}</li>
                                <li class="${data.correct_answer === 'C' ? 'correct' : ''}">C. ${escapeHtml(data.option_c)}</li>
                                <li class="${data.correct_answer === 'D' ? 'correct' : ''}">D. ${escapeHtml(data.option_d)}</li>
                            </ul>
                            <p><strong>Correct Answer:</strong> ${data.correct_answer}</p>
                        `;
                    } else {
                        html += `
                            <h4>${escapeHtml(data.question_text)}</h4>
                            <p><strong>Correct Answer:</strong> ${escapeHtml(data.correct_answer || 'N/A')}</p>
                        `;
                    }

                    html += `
                        <p><strong>Subject:</strong> ${escapeHtml(data.subject_name || 'N/A')}</p>
                        <p><strong>Class:</strong> ${escapeHtml(data.class || 'N/A')}</p>
                        <p><strong>Topic:</strong> ${escapeHtml(data.topic_name || 'N/A')}</p>
                        <p><strong>Difficulty:</strong> ${data.difficulty_level || 'Medium'}</p>
                        <p><strong>Marks:</strong> ${data.marks || 1}</p>
                    </div>`;

                    document.getElementById('questionPreview').innerHTML = html;
                    document.getElementById('questionModal').style.display = 'flex';
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading question details.');
                });
        }

        // Close modal
        function closeModal() {
            document.getElementById('questionModal').style.display = 'none';
        }

        // Confirm question deletion
        function confirmDelete(questionId, questionType) {
            if (confirm('Are you sure you want to delete this question? This action cannot be undone.')) {
                window.location.href = `?delete=1&id=${questionId}&type=${questionType}`;
            }
        }

        // Bulk selection
        document.getElementById('selectAll')?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.question-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        document.getElementById('selectAllTable')?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.question-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Bulk delete confirmation
        function confirmBulkDelete() {
            const selected = document.querySelectorAll('.question-checkbox:checked');
            if (selected.length === 0) {
                alert('Please select at least one question to delete.');
                return;
            }

            document.getElementById('confirmModal').style.display = 'flex';
        }

        function closeConfirmModal() {
            document.getElementById('confirmModal').style.display = 'none';
        }

        function submitBulkDelete() {
            document.getElementById('bulkForm').submit();
        }

        // Export questions
        function exportQuestions() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', '1');
            window.location.href = `export-questions.php?${params.toString()}`;
        }

        // Import questions
        function importQuestions() {
            window.location.href = 'import-questions.php';
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+A to select all
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                const selectAll = document.getElementById('selectAll');
                if (selectAll) {
                    selectAll.click();
                }
            }

            // Delete key to delete selected
            if (e.key === 'Delete') {
                e.preventDefault();
                confirmBulkDelete();
            }

            // Escape to close modals
            if (e.key === 'Escape') {
                closeModal();
                closeConfirmModal();

                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('active');
                    mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
                }
            }
        });

        // Add this to the existing JavaScript section in questions.php
        document.addEventListener('DOMContentLoaded', function() {
            // Detect if table container has scroll
            const tableContainer = document.querySelector('.table-container');
            if (tableContainer) {
                function checkScroll() {
                    if (tableContainer.scrollWidth > tableContainer.clientWidth) {
                        tableContainer.classList.add('has-scroll');
                    } else {
                        tableContainer.classList.remove('has-scroll');
                    }
                }

                checkScroll();
                window.addEventListener('resize', checkScroll);

                // Optional: Show a hint on first load if scrollable
                if (tableContainer.scrollWidth > tableContainer.clientWidth) {
                    const scrollHint = document.createElement('div');
                    scrollHint.className = 'scroll-hint';
                    scrollHint.innerHTML = '<i class="fas fa-arrow-right"></i> Scroll to see more <i class="fas fa-arrow-right"></i>';
                    scrollHint.style.cssText = `
                position: absolute;
                right: 10px;
                top: 10px;
                background: var(--secondary-color);
                color: white;
                padding: 5px 12px;
                border-radius: 20px;
                font-size: 0.8rem;
                animation: fadeOut 3s forwards;
                pointer-events: none;
                z-index: 10;
            `;
                    tableContainer.style.position = 'relative';
                    tableContainer.appendChild(scrollHint);

                    setTimeout(() => {
                        scrollHint.remove();
                    }, 3000);
                }
            }
        });

        // Add fadeOut animation
        const style = document.createElement('style');
        style.textContent = `
    @keyframes fadeOut {
        0% { opacity: 1; transform: translateX(0); }
        70% { opacity: 1; transform: translateX(0); }
        100% { opacity: 0; transform: translateX(-10px); display: none; }
    }
    
    .scroll-hint {
        animation: fadeOut 3s forwards;
    }
`;
        document.head.appendChild(style);
    </script>
</body>

</html>