<?php
// admin/manage-topics.php - Manage Topics with Enhanced Question Viewing
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

$message = '';
$message_type = '';

// Get subject ID from URL
$subject_id = $_GET['subject_id'] ?? 0;
$selected_subject = null;

// Get topic ID for viewing questions
$view_topic_id = $_GET['view_topic'] ?? 0;
$view_question_type = $_GET['question_type'] ?? 'all';

// Get search query from URL
$search_query = $_GET['search'] ?? '';

// Get selected subject details
if ($subject_id) {
    $stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ?");
    $stmt->execute([$subject_id]);
    $selected_subject = $stmt->fetch();
}

// Get topic details if viewing questions
$view_topic = null;
$objective_questions = [];
$subjective_questions = [];
$theory_questions = [];

if ($view_topic_id) {
    $stmt = $pdo->prepare("
        SELECT t.*, s.subject_name 
        FROM topics t 
        JOIN subjects s ON t.subject_id = s.id 
        WHERE t.id = ?
    ");
    $stmt->execute([$view_topic_id]);
    $view_topic = $stmt->fetch();
    
    if ($view_topic) {
        // Get objective questions for this topic
        $obj_stmt = $pdo->prepare("
            SELECT * FROM objective_questions 
            WHERE topic_id = ? 
            ORDER BY id DESC
        ");
        $obj_stmt->execute([$view_topic_id]);
        $objective_questions = $obj_stmt->fetchAll();
        
        // Get subjective questions for this topic
        $sub_stmt = $pdo->prepare("
            SELECT * FROM subjective_questions 
            WHERE topic_id = ? 
            ORDER BY id DESC
        ");
        $sub_stmt->execute([$view_topic_id]);
        $subjective_questions = $sub_stmt->fetchAll();
        
        // Check if theory_questions table exists
        $table_check = $pdo->query("SHOW TABLES LIKE 'theory_questions'");
        if ($table_check->rowCount() > 0) {
            $theory_stmt = $pdo->prepare("
                SELECT * FROM theory_questions 
                WHERE topic_id = ? 
                ORDER BY id DESC
            ");
            $theory_stmt->execute([$view_topic_id]);
            $theory_questions = $theory_stmt->fetchAll();
        }
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_topic'])) {
        $topic_name = trim($_POST['topic_name']);
        $subject_id = $_POST['subject_id'];
        $description = trim($_POST['description']);

        if (!empty($topic_name) && !empty($subject_id)) {
            try {
                // Check if topic already exists for this subject
                $check_stmt = $pdo->prepare("SELECT id FROM topics WHERE topic_name = ? AND subject_id = ?");
                $check_stmt->execute([$topic_name, $subject_id]);

                if ($check_stmt->rowCount() > 0) {
                    $message = "Topic already exists for this subject!";
                    $message_type = "error";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO topics (topic_name, subject_id, description) VALUES (?, ?, ?)");
                    $stmt->execute([$topic_name, $subject_id, $description]);
                    $message = "Topic added successfully!";
                    $message_type = "success";

                    // Log activity
                    $log_activity = $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity) VALUES (?, ?, ?)");
                    $log_activity->execute([$_SESSION['admin_id'], 'admin', "Added topic: $topic_name"]);
                }
            } catch (Exception $e) {
                $message = "Error adding topic: " . $e->getMessage();
                $message_type = "error";
            }
        } else {
            $message = "Please fill in all required fields!";
            $message_type = "error";
        }
    }

    // Handle topic deletion
    if (isset($_POST['delete_topic'])) {
        $topic_id = $_POST['topic_id'];

        try {
            // Get topic info for logging
            $stmt = $pdo->prepare("SELECT topic_name FROM topics WHERE id = ?");
            $stmt->execute([$topic_id]);
            $topic_info = $stmt->fetch();

            // Delete questions first
            $pdo->prepare("DELETE FROM objective_questions WHERE topic_id = ?")->execute([$topic_id]);
            $pdo->prepare("DELETE FROM subjective_questions WHERE topic_id = ?")->execute([$topic_id]);
            
            if ($pdo->query("SHOW TABLES LIKE 'theory_questions'")->rowCount() > 0) {
                $pdo->prepare("DELETE FROM theory_questions WHERE topic_id = ?")->execute([$topic_id]);
            }

            // Delete the topic
            $pdo->prepare("DELETE FROM topics WHERE id = ?")->execute([$topic_id]);

            if ($topic_info) {
                $log_activity = $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity) VALUES (?, ?, ?)");
                $log_activity->execute([$_SESSION['admin_id'], 'admin', "Deleted topic: {$topic_info['topic_name']}"]);
            }

            $message = "Topic and all associated questions deleted successfully!";
            $message_type = "success";
            
            // Redirect to remove view_topic parameter if it was the deleted topic
            if ($view_topic_id == $topic_id) {
                header("Location: manage-topics.php?subject_id=$subject_id&message=Topic deleted successfully");
                exit();
            }
        } catch (Exception $e) {
            $message = "Error deleting topic: " . $e->getMessage();
            $message_type = "error";
        }
    }

    // Handle topic edit
    if (isset($_POST['edit_topic'])) {
        $topic_id = $_POST['edit_topic_id'];
        $topic_name = trim($_POST['edit_topic_name']);
        $description = trim($_POST['edit_description']);

        if (!empty($topic_name)) {
            try {
                // Check if another topic with same name exists for this subject
                $subject_id = $_POST['edit_subject_id'];
                $check_stmt = $pdo->prepare("SELECT id FROM topics WHERE topic_name = ? AND subject_id = ? AND id != ?");
                $check_stmt->execute([$topic_name, $subject_id, $topic_id]);

                if ($check_stmt->rowCount() > 0) {
                    $message = "Another topic with this name already exists for this subject!";
                    $message_type = "error";
                } else {
                    $stmt = $pdo->prepare("UPDATE topics SET topic_name = ?, description = ? WHERE id = ?");
                    $stmt->execute([$topic_name, $description, $topic_id]);
                    $message = "Topic updated successfully!";
                    $message_type = "success";

                    $log_activity = $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity) VALUES (?, ?, ?)");
                    $log_activity->execute([$_SESSION['admin_id'], 'admin', "Updated topic: $topic_name"]);
                }
            } catch (Exception $e) {
                $message = "Error updating topic: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
    
    // Handle delete individual question
    if (isset($_POST['delete_question'])) {
        $question_id = $_POST['question_id'];
        $question_type = $_POST['question_type'];
        $topic_id = $_POST['topic_id'];
        
        try {
            if ($question_type == 'objective') {
                $pdo->prepare("DELETE FROM objective_questions WHERE id = ?")->execute([$question_id]);
            } elseif ($question_type == 'subjective') {
                $pdo->prepare("DELETE FROM subjective_questions WHERE id = ?")->execute([$question_id]);
            } elseif ($question_type == 'theory') {
                $pdo->prepare("DELETE FROM theory_questions WHERE id = ?")->execute([$question_id]);
            }
            
            $message = "Question deleted successfully!";
            $message_type = "success";
            
            // Refresh the page
            header("Location: manage-topics.php?view_topic=$topic_id&subject_id=$subject_id");
            exit();
        } catch (Exception $e) {
            $message = "Error deleting question: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Get all subjects for dropdown
try {
    $stmt = $pdo->prepare("SELECT * FROM subjects ORDER BY subject_name ASC");
    $stmt->execute();
    $subjects = $stmt->fetchAll();
} catch (PDOException $e) {
    // Fallback: try without ORDER BY
    try {
        $subjects = $pdo->query("SELECT * FROM subjects")->fetchAll();
        // Sort manually in PHP
        usort($subjects, function ($a, $b) {
            return strcmp($a['subject_name'], $b['subject_name']);
        });
    } catch (PDOException $e2) {
        $subjects = [];
        error_log("Failed to load subjects: " . $e2->getMessage());
    }
}

// Initialize query variables
$where_conditions = [];
$query_params = [];

// Build where conditions based on filters
if ($selected_subject) {
    $where_conditions[] = "t.subject_id = ?";
    $query_params[] = $subject_id;
}

if ($search_query) {
    $where_conditions[] = "(t.topic_name LIKE ? OR t.description LIKE ?)";
    $search_param = "%" . $search_query . "%";
    $query_params[] = $search_param;
    $query_params[] = $search_param;
}

// Get topics based on filters
if ($selected_subject) {
    $query = "
        SELECT t.*, 
               COUNT(DISTINCT oq.id) as objective_count,
               COUNT(DISTINCT sq.id) as subjective_count,
               COUNT(DISTINCT tq.id) as theory_count,
               (COUNT(DISTINCT oq.id) + COUNT(DISTINCT sq.id) + COUNT(DISTINCT tq.id)) as question_count
        FROM topics t
        LEFT JOIN objective_questions oq ON t.id = oq.topic_id
        LEFT JOIN subjective_questions sq ON t.id = sq.topic_id
        LEFT JOIN theory_questions tq ON t.id = tq.topic_id
    ";

    if (!empty($where_conditions)) {
        $query .= " WHERE " . implode(' AND ', $where_conditions);
    }

    $query .= " GROUP BY t.id ORDER BY t.topic_name";

    $stmt = $pdo->prepare($query);
    $stmt->execute($query_params);
    $topics = $stmt->fetchAll();
} else {
    $query = "
        SELECT t.*, 
               s.subject_name,
               COUNT(DISTINCT oq.id) as objective_count,
               COUNT(DISTINCT sq.id) as subjective_count,
               COUNT(DISTINCT tq.id) as theory_count,
               (COUNT(DISTINCT oq.id) + COUNT(DISTINCT sq.id) + COUNT(DISTINCT tq.id)) as question_count
        FROM topics t
        JOIN subjects s ON t.subject_id = s.id
        LEFT JOIN objective_questions oq ON t.id = oq.topic_id
        LEFT JOIN subjective_questions sq ON t.id = sq.topic_id
        LEFT JOIN theory_questions tq ON t.id = tq.topic_id
    ";

    if (!empty($where_conditions)) {
        $query .= " WHERE " . implode(' AND ', $where_conditions);
    }

    $query .= " GROUP BY t.id ORDER BY s.subject_name, t.topic_name";

    $stmt = $pdo->prepare($query);
    $stmt->execute($query_params);
    $topics = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Topics - Digital CBT System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- MathJax for rendering mathematical equations -->
    <script src="https://polyfill.io/v3/polyfill.min.js?features=es6"></script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

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
            overflow: hidden;
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
            padding: 0 20px;
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

        /* Breadcrumb */
        .breadcrumb {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .breadcrumb a {
            color: var(--secondary-color);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Content Cards */
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
        }

        .card-header h2 {
            color: var(--primary-color);
            font-size: 1.5rem;
        }

        /* Message Alerts */
        .message {
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
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.success {
            background: #d5f4e6;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        /* Form Styles */
        .form-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
        }

        .form-section h3 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-size: 1.3rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
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

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #219653);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #e67e22);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
        }

        .btn-sm {
            padding: 8px 15px;
            font-size: 0.85rem;
        }

        .btn-icon {
            padding: 8px;
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .data-table thead {
            background: linear-gradient(135deg, var(--primary-color), var(--dark-color));
        }

        .data-table th {
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            color: white;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #eee;
            transition: all 0.3s ease;
        }

        .data-table tbody tr:hover {
            background: #f8f9fa;
        }

        .data-table td {
            padding: 15px 20px;
            color: #333;
        }

        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .badge-primary {
            background: #e3f2fd;
            color: #1565c0;
        }

        .badge-success {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .badge-warning {
            background: #fff3e0;
            color: #ef6c00;
        }

        .badge-danger {
            background: #ffebee;
            color: #c62828;
        }

        .badge-info {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-purple {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        /* Question Preview Styles */
        .question-preview {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid var(--secondary-color);
        }

        .question-preview .question-text {
            font-size: 1rem;
            margin-bottom: 10px;
            line-height: 1.5;
        }

        .question-preview .options-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .question-preview .option-item {
            padding: 8px 12px;
            background: white;
            border-radius: 5px;
            border: 1px solid #ddd;
        }

        .question-preview .correct-option {
            background: #e8f5e9;
            border-color: var(--success-color);
            font-weight: 600;
            color: #2e7d32;
        }

        .math-content {
            font-family: 'Times New Roman', serif;
        }

        /* Question Type Tabs */
        .question-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }

        .tab-btn {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            color: #666;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .tab-btn:hover {
            background: #f8f9fa;
            color: var(--secondary-color);
        }

        .tab-btn.active {
            background: var(--secondary-color);
            color: white;
        }

        .question-panel {
            display: none;
        }

        .question-panel.active {
            display: block;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .filter-controls {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 10px 15px 10px 40px !important;
            border: 2px solid #ddd !important;
            border-radius: 8px !important;
            width: 300px !important;
            font-size: 0.95rem !important;
            transition: all 0.3s ease !important;
        }

        .search-box input:focus {
            border-color: var(--secondary-color) !important;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1) !important;
            outline: none !important;
        }

        .search-box i.fa-search {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            z-index: 1;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stats-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            text-align: center;
        }

        .stats-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--secondary-color);
            margin-bottom: 5px;
        }

        .stats-label {
            font-size: 0.9rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Topic Info Card */
        .topic-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .topic-info-card h2 {
            margin-bottom: 10px;
            font-size: 1.8rem;
        }

        .topic-info-card p {
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .topic-meta {
            display: flex;
            gap: 20px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .meta-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 8px 15px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Subject Info Card */
        .subject-info-card {
            background: linear-gradient(135deg, #3498db 0%, #2c3e50 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: #333;
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 20px;
        }

        /* Action Buttons Group */
        .action-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Back Button */
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 20px;
        }

        .back-btn:hover {
            text-decoration: underline;
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            display: none;
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
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            color: var(--primary-color);
            margin: 0;
            font-size: 1.3rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: #f8f9fa;
            color: #333;
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #eee;
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
                padding: 15px;
            }

            .top-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .filter-section {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box input {
                width: 100% !important;
            }

            .mobile-menu-btn {
                display: block;
            }

            .question-tabs {
                flex-wrap: wrap;
            }

            .stats-grid {
                grid-template-columns: 1fr;
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
            <li><a href="manage-topics.php" class="active"><i class="fas fa-list"></i> Manage Topics</a></li>
            <li><a href="manage_questions.php"><i class="fas fa-file-alt"></i> Manage Questions</a></li>
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
                <h1>
                    <?php if ($view_topic): ?>
                        View Questions: <?php echo htmlspecialchars($view_topic['topic_name']); ?>
                    <?php else: ?>
                        Manage Topics
                    <?php endif; ?>
                </h1>
                <p>
                    <?php if ($view_topic): ?>
                        <?php echo htmlspecialchars($view_topic['subject_name']); ?> - 
                        <?php echo count($objective_questions) + count($subjective_questions) + count($theory_questions); ?> total questions
                    <?php else: ?>
                        Add, edit, and manage topics for subjects
                    <?php endif; ?>
                </p>
            </div>
            <div class="header-actions">
                <button class="logout-btn" onclick="window.location.href='../logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="manage-subjects.php">Subjects</a>
            <?php if ($selected_subject): ?>
                &rsaquo; <a href="manage-topics.php?subject_id=<?php echo $subject_id; ?>">
                    <?php echo htmlspecialchars($selected_subject['subject_name']); ?>
                </a>
            <?php endif; ?>
            <?php if ($view_topic): ?>
                &rsaquo; <a href="manage-topics.php?view_topic=<?php echo $view_topic_id; ?>&subject_id=<?php echo $subject_id; ?>">
                    <?php echo htmlspecialchars($view_topic['topic_name']); ?>
                </a>
            <?php endif; ?>
        </div>

        <!-- Message Alerts -->
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($view_topic): ?>
            <!-- Topic Questions View -->
            
            <!-- Back Button -->
            <a href="manage-topics.php?subject_id=<?php echo $subject_id; ?>" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Topics
            </a>
            
            <!-- Topic Info Card -->
            <div class="topic-info-card">
                <h2><i class="fas fa-bookmark"></i> <?php echo htmlspecialchars($view_topic['topic_name']); ?></h2>
                <p><i class="fas fa-book"></i> Subject: <?php echo htmlspecialchars($view_topic['subject_name']); ?></p>
                <?php if ($view_topic['description']): ?>
                    <p><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($view_topic['description']); ?></p>
                <?php endif; ?>
                <div class="topic-meta">
                    <div class="meta-item">
                        <i class="fas fa-check-circle"></i>
                        <span>Objective: <?php echo count($objective_questions); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-pen"></i>
                        <span>Subjective: <?php echo count($subjective_questions); ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-file-alt"></i>
                        <span>Theory: <?php echo count($theory_questions); ?></span>
                    </div>
                </div>
                <div style="margin-top: 20px;">
                    <a href="manage_questions.php?topic_id=<?php echo $view_topic_id; ?>" class="btn btn-success">
                        <i class="fas fa-plus-circle"></i> Add New Question
                    </a>
                    <button class="btn btn-primary" onclick="window.location.href='manage-topics.php?subject_id=<?php echo $subject_id; ?>'">
                        <i class="fas fa-arrow-left"></i> Back to Topics
                    </button>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stats-card">
                    <div class="stats-value"><?php echo count($objective_questions); ?></div>
                    <div class="stats-label">Objective Questions</div>
                </div>
                <div class="stats-card">
                    <div class="stats-value"><?php echo count($subjective_questions); ?></div>
                    <div class="stats-label">Subjective Questions</div>
                </div>
                <div class="stats-card">
                    <div class="stats-value"><?php echo count($theory_questions); ?></div>
                    <div class="stats-label">Theory Questions</div>
                </div>
            </div>

            <!-- Question Type Tabs -->
            <div class="content-card">
                <div class="question-tabs">
                    <button class="tab-btn active" onclick="showQuestionPanel('objective')">
                        <i class="fas fa-check-circle"></i> Objective Questions (<?php echo count($objective_questions); ?>)
                    </button>
                    <button class="tab-btn" onclick="showQuestionPanel('subjective')">
                        <i class="fas fa-pen"></i> Subjective Questions (<?php echo count($subjective_questions); ?>)
                    </button>
                    <?php if (count($theory_questions) > 0): ?>
                    <button class="tab-btn" onclick="showQuestionPanel('theory')">
                        <i class="fas fa-file-alt"></i> Theory Questions (<?php echo count($theory_questions); ?>)
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Objective Questions Panel -->
                <div id="objective-panel" class="question-panel active">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3><i class="fas fa-check-circle"></i> Objective Questions</h3>
                        <a href="manage_questions.php?topic_id=<?php echo $view_topic_id; ?>&type=objective" class="btn btn-success btn-sm">
                            <i class="fas fa-plus"></i> Add Objective Question
                        </a>
                    </div>
                    
                    <?php if (empty($objective_questions)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h3>No Objective Questions</h3>
                            <p>This topic doesn't have any objective questions yet.</p>
                            <a href="manage_questions.php?topic_id=<?php echo $view_topic_id; ?>&type=objective" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add First Objective Question
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Question</th>
                                        <th>Options</th>
                                        <th>Correct</th>
                                        <th>Marks</th>
                                        <th>Difficulty</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($objective_questions as $q): ?>
                                    <tr>
                                        <td><?php echo $q['id']; ?></td>
                                        <td>
                                            <div class="math-content">
                                                <?php 
                                                $question_text = htmlspecialchars($q['question_text']);
                                                $question_text = preg_replace('/\$\$(.*?)\$\$/s', '\\\\(\\1\\\\)', $question_text);
                                                $question_text = preg_replace('/\$(.*?)\$/s', '\\(\\1\\)', $question_text);
                                                echo $question_text;
                                                ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 5px;">
                                                <span class="badge <?php echo $q['correct_answer'] == 'A' ? 'badge-success' : ''; ?>">A: <?php echo htmlspecialchars($q['option_a']); ?></span>
                                                <span class="badge <?php echo $q['correct_answer'] == 'B' ? 'badge-success' : ''; ?>">B: <?php echo htmlspecialchars($q['option_b']); ?></span>
                                                <span class="badge <?php echo $q['correct_answer'] == 'C' ? 'badge-success' : ''; ?>">C: <?php echo htmlspecialchars($q['option_c']); ?></span>
                                                <span class="badge <?php echo $q['correct_answer'] == 'D' ? 'badge-success' : ''; ?>">D: <?php echo htmlspecialchars($q['option_d']); ?></span>
                                            </div>
                                        </td>
                                        <td><span class="badge badge-success"><?php echo $q['correct_answer']; ?></span></td>
                                        <td><?php echo $q['marks']; ?></td>
                                        <td>
                                            <span class="badge <?php 
                                                echo $q['difficulty_level'] == 'easy' ? 'badge-success' : 
                                                    ($q['difficulty_level'] == 'medium' ? 'badge-warning' : 'badge-danger'); 
                                            ?>">
                                                <?php echo ucfirst($q['difficulty_level']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <a href="edit_question.php?id=<?php echo $q['id']; ?>&type=objective&topic_id=<?php echo $view_topic_id; ?>" 
                                                   class="btn btn-primary btn-sm btn-icon" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form method="POST" style="display: inline;" 
                                                      onsubmit="return confirm('Are you sure you want to delete this question?');">
                                                    <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                                    <input type="hidden" name="question_type" value="objective">
                                                    <input type="hidden" name="topic_id" value="<?php echo $view_topic_id; ?>">
                                                    <button type="submit" name="delete_question" 
                                                            class="btn btn-danger btn-sm btn-icon" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Subjective Questions Panel -->
                <div id="subjective-panel" class="question-panel">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3><i class="fas fa-pen"></i> Subjective Questions</h3>
                        <a href="manage_questions.php?topic_id=<?php echo $view_topic_id; ?>&type=subjective" class="btn btn-success btn-sm">
                            <i class="fas fa-plus"></i> Add Subjective Question
                        </a>
                    </div>
                    
                    <?php if (empty($subjective_questions)): ?>
                        <div class="empty-state">
                            <i class="fas fa-pen"></i>
                            <h3>No Subjective Questions</h3>
                            <p>This topic doesn't have any subjective questions yet.</p>
                            <a href="manage_questions.php?topic_id=<?php echo $view_topic_id; ?>&type=subjective" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add First Subjective Question
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Question</th>
                                        <th>Answer Guide</th>
                                        <th>Marks</th>
                                        <th>Difficulty</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subjective_questions as $q): ?>
                                    <tr>
                                        <td><?php echo $q['id']; ?></td>
                                        <td>
                                            <div class="math-content">
                                                <?php 
                                                $question_text = htmlspecialchars($q['question_text']);
                                                $question_text = preg_replace('/\$\$(.*?)\$\$/s', '\\\\(\\1\\\\)', $question_text);
                                                $question_text = preg_replace('/\$(.*?)\$/s', '\\(\\1\\)', $question_text);
                                                echo $question_text;
                                                ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($q['answer_guide'])): ?>
                                                <span class="badge badge-info">Guide Available</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">No Guide</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $q['marks']; ?></td>
                                        <td>
                                            <span class="badge <?php 
                                                echo $q['difficulty_level'] == 'easy' ? 'badge-success' : 
                                                    ($q['difficulty_level'] == 'medium' ? 'badge-warning' : 'badge-danger'); 
                                            ?>">
                                                <?php echo ucfirst($q['difficulty_level']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <a href="edit_question.php?id=<?php echo $q['id']; ?>&type=subjective&topic_id=<?php echo $view_topic_id; ?>" 
                                                   class="btn btn-primary btn-sm btn-icon" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form method="POST" style="display: inline;"
                                                      onsubmit="return confirm('Are you sure you want to delete this question?');">
                                                    <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                                    <input type="hidden" name="question_type" value="subjective">
                                                    <input type="hidden" name="topic_id" value="<?php echo $view_topic_id; ?>">
                                                    <button type="submit" name="delete_question" 
                                                            class="btn btn-danger btn-sm btn-icon" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Theory Questions Panel -->
                <?php if (count($theory_questions) > 0): ?>
                <div id="theory-panel" class="question-panel">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3><i class="fas fa-file-alt"></i> Theory Questions</h3>
                        <a href="manage_questions.php?topic_id=<?php echo $view_topic_id; ?>&type=theory" class="btn btn-success btn-sm">
                            <i class="fas fa-plus"></i> Add Theory Question
                        </a>
                    </div>
                    
                    <?php if (empty($theory_questions)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h3>No Theory Questions</h3>
                            <p>This topic doesn't have any theory questions yet.</p>
                            <a href="manage_questions.php?topic_id=<?php echo $view_topic_id; ?>&type=theory" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add First Theory Question
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Question</th>
                                        <th>Marks</th>
                                        <th>Difficulty</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($theory_questions as $q): ?>
                                    <tr>
                                        <td><?php echo $q['id']; ?></td>
                                        <td>
                                            <div class="math-content">
                                                <?php 
                                                $question_text = htmlspecialchars($q['question_text']);
                                                $question_text = preg_replace('/\$\$(.*?)\$\$/s', '\\\\(\\1\\\\)', $question_text);
                                                $question_text = preg_replace('/\$(.*?)\$/s', '\\(\\1\\)', $question_text);
                                                echo $question_text;
                                                ?>
                                            </div>
                                        </td>
                                        <td><?php echo $q['marks']; ?></td>
                                        <td>
                                            <span class="badge <?php 
                                                echo $q['difficulty_level'] == 'easy' ? 'badge-success' : 
                                                    ($q['difficulty_level'] == 'medium' ? 'badge-warning' : 'badge-danger'); 
                                            ?>">
                                                <?php echo ucfirst($q['difficulty_level']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <a href="edit_question.php?id=<?php echo $q['id']; ?>&type=theory&topic_id=<?php echo $view_topic_id; ?>" 
                                                   class="btn btn-primary btn-sm btn-icon" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form method="POST" style="display: inline;"
                                                      onsubmit="return confirm('Are you sure you want to delete this question?');">
                                                    <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                                    <input type="hidden" name="question_type" value="theory">
                                                    <input type="hidden" name="topic_id" value="<?php echo $view_topic_id; ?>">
                                                    <button type="submit" name="delete_question" 
                                                            class="btn btn-danger btn-sm btn-icon" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- Regular Topics Management View -->
            
            <!-- Subject Info Card (if subject selected) -->
            <?php if ($selected_subject): ?>
                <div class="subject-info-card">
                    <h2><?php echo htmlspecialchars($selected_subject['subject_name']); ?></h2>
                    <?php if ($selected_subject['description']): ?>
                        <p><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($selected_subject['description']); ?></p>
                    <?php endif; ?>
                    <div class="topic-meta">
                        <div class="meta-item">
                            <i class="fas fa-list"></i>
                            <span><?php echo count($topics); ?> Topics</span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-question-circle"></i>
                            <span>
                                <?php
                                $total_questions = array_sum(array_column($topics, 'question_count'));
                                echo $total_questions . ' Questions';
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Add Topic Form -->
            <div class="content-card">
                <div class="card-header">
                    <h2>Add New Topic</h2>
                </div>
                <div class="form-section">
                    <form method="POST" action="" onsubmit="return validateTopicForm()">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="topic_name">Topic Name *</label>
                                <input type="text" id="topic_name" name="topic_name" required
                                    placeholder="e.g., Algebra, Trigonometry, Geometry" maxlength="255">
                            </div>
                            <div class="form-group">
                                <label for="subject_id">Subject *</label>
                                <select id="subject_id" name="subject_id" required>
                                    <option value="">Select Subject</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>"
                                            <?php echo ($subject_id == $subject['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="description">Description (Optional)</label>
                            <textarea id="description" name="description"
                                placeholder="Enter topic description..."></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Clear
                            </button>
                            <button type="submit" name="add_topic" class="btn btn-success">
                                <i class="fas fa-plus-circle"></i> Add Topic
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Topics List -->
            <div class="content-card">
                <div class="card-header">
                    <div>
                        <h2>
                            <?php if ($selected_subject): ?>
                                Topics for <?php echo htmlspecialchars($selected_subject['subject_name']); ?>
                                <?php if ($search_query): ?>
                                    <span style="font-size: 1rem; color: #666; margin-left: 10px;">
                                        (Search results for "<?php echo htmlspecialchars($search_query); ?>")
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                All Topics
                                <?php if ($search_query): ?>
                                    <span style="font-size: 1rem; color: #666; margin-left: 10px;">
                                        (Search results for "<?php echo htmlspecialchars($search_query); ?>")
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </h2>
                        <?php if ($search_query): ?>
                            <p style="color: #666; margin-top: 5px;">
                                Found <?php echo count($topics); ?> topic(s) matching your search
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="stats-card" style="display: inline-block; margin: 0; padding: 10px 20px;">
                        <div class="stats-value"><?php echo count($topics); ?></div>
                        <div class="stats-label">
                            <?php echo $search_query ? 'Results' : 'Total Topics'; ?>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <?php if (!$selected_subject): ?>
                    <div class="filter-section">
                        <div class="filter-controls">
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <label for="subjectFilter">Filter by Subject:</label>
                                <select id="subjectFilter" onchange="filterTopics()">
                                    <option value="">All Subjects</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>"
                                            <?php echo ($subject_id == $subject['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Search Box -->
                            <div class="search-box">
                                <form method="GET" action="" id="searchForm">
                                    <?php if ($subject_id): ?>
                                        <input type="hidden" name="subject_id" value="<?php echo $subject_id; ?>">
                                    <?php endif; ?>
                                    <input type="text"
                                        name="search"
                                        id="searchInput"
                                        value="<?php echo htmlspecialchars($search_query); ?>"
                                        placeholder="Search topics..."
                                        onkeyup="searchTopics(event)">
                                    <i class="fas fa-search"></i>
                                    <?php if ($search_query): ?>
                                        <button type="button"
                                            class="search-clear-btn"
                                            onclick="clearSearch()"
                                            title="Clear search">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                        <div>
                            <button class="btn btn-primary" onclick="window.location.href='manage-topics.php'">
                                <i class="fas fa-eye"></i> View All Topics
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Topics Table -->
                <div class="table-container">
                    <?php if (empty($topics)): ?>
                        <div class="empty-state">
                            <i class="fas fa-list"></i>
                            <h3>No Topics Found</h3>
                            <p>
                                <?php if ($selected_subject): ?>
                                    <?php if ($search_query): ?>
                                        No topics found for "<?php echo htmlspecialchars($search_query); ?>" in <?php echo htmlspecialchars($selected_subject['subject_name']); ?>.
                                    <?php else: ?>
                                        No topics found for <?php echo htmlspecialchars($selected_subject['subject_name']); ?>. Add your first topic above.
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($search_query): ?>
                                        No topics found for "<?php echo htmlspecialchars($search_query); ?>".
                                    <?php else: ?>
                                        No topics have been added yet. Add your first topic above.
                                    <?php endif; ?>
                                <?php endif; ?>
                            </p>
                            <button class="btn btn-primary" onclick="document.querySelector('#topic_name').focus()">
                                <i class="fas fa-plus"></i> Add Your First Topic
                            </button>
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <?php if (!$selected_subject): ?>
                                        <th>Subject</th>
                                    <?php endif; ?>
                                    <th>Topic Name</th>
                                    <th>Questions</th>
                                    <th>Description</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topics as $topic): ?>
                                    <tr>
                                        <?php if (!$selected_subject): ?>
                                            <td>
                                                <a href="manage-topics.php?subject_id=<?php echo $topic['subject_id']; ?>&search=<?php echo urlencode($search_query); ?>"
                                                    class="badge badge-primary">
                                                    <?php echo htmlspecialchars($topic['subject_name']); ?>
                                                </a>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <strong><?php echo htmlspecialchars($topic['topic_name']); ?></strong>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                                <span class="badge badge-info" title="Objective">
                                                    <i class="fas fa-check-circle"></i> <?php echo $topic['objective_count']; ?>
                                                </span>
                                                <span class="badge badge-warning" title="Subjective">
                                                    <i class="fas fa-pen"></i> <?php echo $topic['subjective_count']; ?>
                                                </span>
                                                <?php if ($topic['theory_count'] > 0): ?>
                                                <span class="badge badge-purple" title="Theory">
                                                    <i class="fas fa-file-alt"></i> <?php echo $topic['theory_count']; ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($topic['description'] ?: 'No description'); ?>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <a href="manage-topics.php?view_topic=<?php echo $topic['id']; ?>&subject_id=<?php echo $topic['subject_id']; ?>" 
                                                   class="btn btn-info btn-sm" title="View Questions">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <button class="btn btn-primary btn-sm btn-icon"
                                                    onclick="editTopic(<?php echo $topic['id']; ?>)"
                                                    title="Edit Topic">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="manage_questions.php?topic_id=<?php echo $topic['id']; ?>"
                                                    class="btn btn-success btn-sm btn-icon"
                                                    title="Add Questions">
                                                    <i class="fas fa-plus-circle"></i>
                                                </a>
                                                <form method="POST" style="display: inline;"
                                                    onsubmit="return confirmDeleteTopic()">
                                                    <input type="hidden" name="topic_id" value="<?php echo $topic['id']; ?>">
                                                    <button type="submit" name="delete_topic"
                                                        class="btn btn-danger btn-sm btn-icon"
                                                        title="Delete Topic">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Edit Topic Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Topic</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="" onsubmit="return validateEditTopicForm()">
                <div class="modal-body">
                    <input type="hidden" id="edit_topic_id" name="edit_topic_id">
                    <input type="hidden" id="edit_subject_id" name="edit_subject_id">
                    <div class="form-group">
                        <label for="edit_topic_name">Topic Name *</label>
                        <input type="text" id="edit_topic_name" name="edit_topic_name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_subject_display">Subject</label>
                        <input type="text" id="edit_subject_display" readonly class="readonly-field">
                    </div>
                    <div class="form-group">
                        <label for="edit_description">Description</label>
                        <textarea id="edit_description" name="edit_description"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                        Cancel
                    </button>
                    <button type="submit" name="edit_topic" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // MathJax configuration
        MathJax = {
            tex: {
                inlineMath: [['\\(', '\\)']],
                displayMath: [['\\[', '\\]']]
            },
            svg: {
                fontCache: 'global'
            }
        };

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

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
            }
        });

        // Filter topics by subject
        function filterTopics() {
            const subjectFilter = document.getElementById('subjectFilter').value;
            const searchInput = document.getElementById('searchInput')?.value || '';

            let url = 'manage-topics.php?';
            if (subjectFilter) {
                url += `subject_id=${subjectFilter}`;
            }
            if (searchInput) {
                url += `${subjectFilter ? '&' : ''}search=${encodeURIComponent(searchInput)}`;
            }

            window.location.href = url;
        }

        // Search topics with debounce
        let searchTimeout;

        function searchTopics(event) {
            clearTimeout(searchTimeout);

            // If Enter key is pressed, submit immediately
            if (event.key === 'Enter') {
                document.getElementById('searchForm')?.submit();
                return;
            }

            // Debounce the search
            searchTimeout = setTimeout(() => {
                document.getElementById('searchForm')?.submit();
            }, 500);
        }

        // Clear search
        function clearSearch() {
            document.getElementById('searchInput').value = '';
            const form = document.getElementById('searchForm');
            const input = form.querySelector('input[name="search"]');
            if (input) {
                input.remove();
            }
            form.submit();
        }

        // Open edit modal
        function editTopic(topicId) {
            // Fetch topic data via AJAX
            fetch(`get_topic.php?id=${topicId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('edit_topic_id').value = data.id;
                        document.getElementById('edit_subject_id').value = data.subject_id;
                        document.getElementById('edit_topic_name').value = data.topic_name;
                        document.getElementById('edit_subject_display').value = data.subject_name;
                        document.getElementById('edit_description').value = data.description || '';
                        
                        document.getElementById('editModal').classList.add('active');
                    } else {
                        alert('Error loading topic data');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load topic data');
                });
        }

        // Close edit modal
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        // Form validation
        function validateTopicForm() {
            const topicName = document.getElementById('topic_name').value.trim();
            const subjectId = document.getElementById('subject_id').value;

            if (!topicName) {
                alert('Please enter a topic name.');
                return false;
            }

            if (!subjectId) {
                alert('Please select a subject.');
                return false;
            }

            return true;
        }

        function validateEditTopicForm() {
            const topicName = document.getElementById('edit_topic_name').value.trim();

            if (!topicName) {
                alert('Please enter a topic name.');
                return false;
            }

            return true;
        }

        // Delete confirmation
        function confirmDeleteTopic() {
            return confirm('Are you sure you want to delete this topic and all its questions? This action cannot be undone.');
        }

        // Question panel switching
        function showQuestionPanel(type) {
            // Hide all panels
            document.querySelectorAll('.question-panel').forEach(panel => {
                panel.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected panel
            document.getElementById(type + '-panel').classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
            
            // Re-render MathJax
            if (typeof MathJax !== 'undefined') {
                MathJax.typesetPromise();
            }
        }

        // Auto-hide message after 5 seconds
        setTimeout(function() {
            const message = document.querySelector('.message');
            if (message) {
                message.style.opacity = '0';
                message.style.transform = 'translateY(-20px)';
                setTimeout(() => message.remove(), 300);
            }
        }, 5000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F to focus on search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const searchInput = document.getElementById('searchInput');
                if (searchInput) searchInput.focus();
            }

            // Ctrl+T to focus on new topic form
            if (e.ctrlKey && e.key === 't') {
                e.preventDefault();
                const topicInput = document.getElementById('topic_name');
                if (topicInput) topicInput.focus();
            }

            // Escape to close modal
            if (e.key === 'Escape') {
                closeEditModal();

                // Close mobile menu
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-select subject filter if viewing specific subject
            const subjectFilter = document.getElementById('subjectFilter');
            if (subjectFilter && <?php echo $subject_id ?: 0; ?>) {
                subjectFilter.value = <?php echo $subject_id ?: '""'; ?>;
            }

            // Auto-focus subject dropdown if no subject is selected
            <?php if (!$subject_id && !$view_topic_id): ?>
                setTimeout(() => {
                    const subjectSelect = document.getElementById('subject_id');
                    if (subjectSelect) subjectSelect.focus();
                }, 100);
            <?php endif; ?>
            
            // Re-render MathJax
            if (typeof MathJax !== 'undefined') {
                MathJax.typesetPromise();
            }
        });
    </script>
</body>

</html>