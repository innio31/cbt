<?php
// admin/manage_questions.php - Manage Questions
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
$current_tab = $_GET['type'] ?? 'objective';

// Get topic ID from URL
$topic_id = $_GET['topic_id'] ?? 0;
$selected_topic = null;
$selected_subject = null;

// Get selected topic and subject details
if ($topic_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, s.subject_name, s.id as subject_id 
            FROM topics t 
            JOIN subjects s ON t.subject_id = s.id 
            WHERE t.id = ?
        ");
        $stmt->execute([$topic_id]);
        $selected_topic = $stmt->fetch();

        if ($selected_topic) {
            // Get the class for this subject from subject_classes table
            $class_stmt = $pdo->prepare("
                SELECT class 
                FROM subject_classes 
                WHERE subject_id = ? 
                LIMIT 1
            ");
            $class_stmt->execute([$selected_topic['subject_id']]);
            $class_row = $class_stmt->fetch();

            $selected_subject = [
                'id' => $selected_topic['subject_id'],
                'subject_name' => $selected_topic['subject_name'],
                'class' => $class_row['class'] ?? 'N/A'
            ];

            // Add class to selected_topic array for easier access
            $selected_topic['class'] = $selected_subject['class'];
        }
    } catch (Exception $e) {
        $message = "Error loading topic: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle Question Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_question'])) {
        $question_id = $_POST['question_id'];
        $question_type = $_POST['question_type'];

        try {
            $table_name = '';
            $activity_desc = '';

            switch ($question_type) {
                case 'objective':
                    $table_name = 'objective_questions';
                    $activity_desc = "Deleted objective question ID: $question_id";
                    break;
                case 'subjective':
                    $table_name = 'subjective_questions';
                    $activity_desc = "Deleted subjective question ID: $question_id";
                    break;
                case 'theory':
                    $table_name = 'theory_questions';
                    $activity_desc = "Deleted theory question ID: $question_id";
                    break;
            }

            if (!empty($table_name)) {
                try {
                    // Get file path if exists - use appropriate column based on question type
                    $file_column = '';
                    $file_path = '';

                    if ($question_type === 'objective') {
                        // For objective questions, check question_image column
                        $stmt = $pdo->prepare("SELECT question_image FROM $table_name WHERE id = ?");
                        $stmt->execute([$question_id]);
                        $question_info = $stmt->fetch();

                        if ($question_info && !empty($question_info['question_image'])) {
                            $file_path = '../' . $question_info['question_image'];
                        }
                    } elseif ($question_type === 'theory') {
                        // For theory questions, check question_file column
                        $stmt = $pdo->prepare("SELECT question_file FROM $table_name WHERE id = ?");
                        $stmt->execute([$question_id]);
                        $question_info = $stmt->fetch();

                        if ($question_info && !empty($question_info['question_file'])) {
                            $file_path = '../' . $question_info['question_file'];
                        }
                    } else {
                        // For subjective questions or others, just get basic info without file
                        $stmt = $pdo->prepare("SELECT id FROM $table_name WHERE id = ?");
                        $stmt->execute([$question_id]);
                        $question_info = $stmt->fetch();
                    }

                    // Delete the question
                    $pdo->prepare("DELETE FROM $table_name WHERE id = ?")->execute([$question_id]);

                    // Delete associated file if it exists
                    if (!empty($file_path) && file_exists($file_path)) {
                        unlink($file_path);
                    }

                    // Log activity
                    $log_activity = $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity) VALUES (?, ?, ?)");
                    $log_activity->execute([$_SESSION['admin_id'], 'admin', $activity_desc]);

                    $message = "Question deleted successfully!";
                    $message_type = "success";
                } catch (Exception $e) {
                    $message = "Error during deletion: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        } catch (Exception $e) {
            $message = "Error deleting question: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Get questions for selected topic
$objective_questions = [];
$subjective_questions = [];
$theory_questions = [];

if ($selected_topic) {
    try {
        // Get objective questions
        $stmt = $pdo->prepare("SELECT * FROM objective_questions WHERE topic_id = ? ORDER BY created_at DESC");
        $stmt->execute([$topic_id]);
        $objective_questions = $stmt->fetchAll();

        // Get subjective questions
        $stmt = $pdo->prepare("SELECT * FROM subjective_questions WHERE topic_id = ? ORDER BY created_at DESC");
        $stmt->execute([$topic_id]);
        $subjective_questions = $stmt->fetchAll();

        // Get theory questions
        $stmt = $pdo->prepare("SELECT * FROM theory_questions WHERE topic_id = ? ORDER BY created_at DESC");
        $stmt->execute([$topic_id]);
        $theory_questions = $stmt->fetchAll();
    } catch (Exception $e) {
        $message = "Error loading questions: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get all topics for dropdown
try {
    $topics = $pdo->query("
        SELECT 
            t.id,
            t.topic_name,
            t.description,
            s.subject_name,
            s.id as subject_id,
            sc.class
        FROM topics t 
        JOIN subjects s ON t.subject_id = s.id 
        LEFT JOIN subject_classes sc ON s.id = sc.subject_id
        ORDER BY 
            COALESCE(sc.class, 'N/A'), 
            s.subject_name, 
            t.topic_name
    ")->fetchAll();
} catch (Exception $e) {
    $message = "Error loading topics: " . $e->getMessage();
    $message_type = "error";
    $topics = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Questions - Digital CBT System</title>

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

        /* Add Questions Button */
        .add-questions-btn {
            background: linear-gradient(135deg, var(--success-color), #219653);
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

        .add-questions-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
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

        /* Tabs Navigation */
        .tabs-navigation {
            background: white;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .tab-buttons {
            display: flex;
            background: #f8f9fa;
        }

        .tab-button {
            flex: 1;
            padding: 15px 20px;
            border: none;
            background: none;
            font-size: 1rem;
            font-weight: 500;
            color: #666;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .tab-button:hover {
            background: #e9ecef;
        }

        .tab-button.active {
            background: white;
            color: var(--primary-color);
            border-bottom: 3px solid var(--secondary-color);
        }

        .tab-content {
            display: none;
            padding: 20px;
        }

        .tab-content.active {
            display: block;
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

        .action-buttons {
            display: flex;
            gap: 8px;
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

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #e67e22);
            color: white;
        }

        .btn-sm {
            padding: 8px 12px;
            font-size: 0.85rem;
            border-radius: 6px;
        }

        .btn-icon {
            padding: 8px;
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* Stats Cards */
        .stats-cards {
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
            transition: all 0.3s ease;
        }

        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .stats-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stats-label {
            font-size: 0.9rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stats-card.objective .stats-value {
            color: var(--secondary-color);
        }

        .stats-card.subjective .stats-value {
            color: var(--success-color);
        }

        .stats-card.theory .stats-value {
            color: var(--warning-color);
        }

        .stats-card.total .stats-value {
            color: var(--primary-color);
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

        /* Question Text Styles */
        .question-text {
            font-weight: 500;
            color: #333;
            line-height: 1.6;
        }

        .option-text {
            display: block;
            padding: 5px 0;
        }

        /* Difficulty Badges */
        .badge-easy {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .badge-medium {
            background: #fff3e0;
            color: #ef6c00;
        }

        .badge-hard {
            background: #ffebee;
            color: #c62828;
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

            .tab-buttons {
                flex-direction: column;
            }

            .tab-button {
                padding: 12px;
                font-size: 0.9rem;
            }

            .stats-cards {
                grid-template-columns: 1fr;
            }

            .mobile-menu-btn {
                display: block;
            }

            .action-buttons {
                flex-wrap: wrap;
            }

            .data-table th,
            .data-table td {
                padding: 10px 15px;
                font-size: 0.9rem;
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-y: auto;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 100%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px 30px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary-color), var(--dark-color));
            color: white;
            border-radius: 15px 15px 0 0;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.4rem;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
            max-height: 60vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 20px 30px;
            border-top: 2px solid var(--light-color);
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            background: #f8f9fa;
            border-radius: 0 0 15px 15px;
        }

        /* Question Preview Styles */
        .question-preview {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
        }

        .question-title {
            font-size: 1.3rem;
            color: var(--primary-color);
            font-weight: 600;
        }

        .question-content {
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 25px;
            color: #333;
        }

        .question-image {
            max-width: 100%;
            border-radius: 10px;
            margin: 15px 0;
            border: 2px solid #eee;
        }

        .options-container {
            margin: 25px 0;
        }

        .option-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #ddd;
            transition: all 0.3s ease;
        }

        .option-item.correct {
            background: #e8f5e9;
            border-left-color: var(--success-color);
        }

        .option-label {
            font-weight: bold;
            color: var(--primary-color);
            min-width: 30px;
            font-size: 1.1rem;
        }

        .option-text {
            flex: 1;
            font-size: 1rem;
            line-height: 1.5;
        }

        .question-meta {
            display: flex;
            gap: 20px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
            font-size: 0.9rem;
        }

        .meta-item i {
            color: var(--secondary-color);
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
            <li><a href="manage-topics.php"><i class="fas fa-list"></i> Manage Topics</a></li>
            <li><a href="manage_questions.php" class="active"><i class="fas fa-question-circle"></i> Manage Questions</a></li>
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
                <h1>Manage Questions</h1>
                <p>View and manage questions for topics</p>
            </div>
            <div class="header-actions">
                <?php if ($selected_topic): ?>
                    <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>" class="add-questions-btn">
                        <i class="fas fa-plus-circle"></i> Add Questions
                    </a>
                <?php endif; ?>
                <button class="logout-btn" onclick="window.location.href='../logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="manage-topics.php">Topics</a>
            <?php if ($selected_topic): ?>
                &rsaquo;
                <a href="manage_questions.php?topic_id=<?php echo $topic_id; ?>">
                    <?php echo htmlspecialchars($selected_topic['topic_name']); ?>
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

        <!-- Topic Info Card (if topic selected) -->
        <?php if ($selected_topic): ?>
            <div class="topic-info-card">
                <h2><?php echo htmlspecialchars($selected_topic['topic_name']); ?></h2>
                <p><i class="fas fa-book"></i> Subject: <?php echo htmlspecialchars($selected_topic['subject_name']); ?></p>
                <p><i class="fas fa-graduation-cap"></i> Class: <?php echo htmlspecialchars($selected_topic['class']); ?></p>
                <?php if ($selected_topic['description']): ?>
                    <p><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($selected_topic['description']); ?></p>
                <?php endif; ?>
                <div class="topic-meta">
                    <div class="meta-item">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo count($objective_questions); ?> Objective Questions</span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-edit"></i>
                        <span><?php echo count($subjective_questions); ?> Subjective Questions</span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-file-alt"></i>
                        <span><?php echo count($theory_questions); ?> Theory Questions</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Topic Selection -->
        <div class="content-card">
            <div class="card-header">
                <h2>Select Topic</h2>
            </div>
            <div class="form-section">
                <form method="GET" action="">
                    <div class="form-group">
                        <label for="topic_select">Choose Topic:</label>
                        <select id="topic_select" name="topic_id" class="form-control" onchange="this.form.submit()" required>
                            <option value="">Select a topic to manage questions</option>
                            <?php foreach ($topics as $topic): ?>
                                <?php
                                // Make sure class is available, if not use default
                                $class = !empty($topic['class']) ? $topic['class'] : 'N/A';
                                ?>
                                <option value="<?php echo $topic['id']; ?>"
                                    <?php echo ($topic_id == $topic['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($topic['subject_name']); ?>
                                    (<?php echo htmlspecialchars($class); ?>) -
                                    <?php echo htmlspecialchars($topic['topic_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selected_topic): ?>
            <!-- Stats Cards -->
            <div class="stats-cards">
                <div class="stats-card objective">
                    <div class="stats-value"><?php echo count($objective_questions); ?></div>
                    <div class="stats-label">Objective Questions</div>
                    <small>Multiple Choice</small>
                </div>
                <div class="stats-card subjective">
                    <div class="stats-value"><?php echo count($subjective_questions); ?></div>
                    <div class="stats-label">Subjective Questions</div>
                    <small>Short Answer</small>
                </div>
                <div class="stats-card theory">
                    <div class="stats-value"><?php echo count($theory_questions); ?></div>
                    <div class="stats-label">Theory Questions</div>
                    <small>Essay Type</small>
                </div>
                <div class="stats-card total">
                    <div class="stats-value"><?php echo count($objective_questions) + count($subjective_questions) + count($theory_questions); ?></div>
                    <div class="stats-label">Total Questions</div>
                    <small>All Types</small>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <div class="tabs-navigation">
                <div class="tab-buttons">
                    <button class="tab-button <?php echo $current_tab === 'objective' ? 'active' : ''; ?>"
                        onclick="switchTab('objective')">
                        <i class="fas fa-check-circle"></i> Objective Questions
                    </button>
                    <button class="tab-button <?php echo $current_tab === 'subjective' ? 'active' : ''; ?>"
                        onclick="switchTab('subjective')">
                        <i class="fas fa-edit"></i> Subjective Questions
                    </button>
                    <button class="tab-button <?php echo $current_tab === 'theory' ? 'active' : ''; ?>"
                        onclick="switchTab('theory')">
                        <i class="fas fa-file-alt"></i> Theory Questions
                    </button>
                </div>

                <!-- Objective Questions Tab -->
                <div class="tab-content <?php echo $current_tab === 'objective' ? 'active' : ''; ?>" id="objectiveTab">
                    <!-- Objective Questions List -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2>Objective Questions</h2>
                            <span class="badge badge-primary"><?php echo count($objective_questions); ?> Questions</span>
                        </div>

                        <div class="table-container">
                            <?php if (empty($objective_questions)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <h3>No Objective Questions Found</h3>
                                    <p>No objective questions have been added for this topic.</p>
                                    <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>&type=objective" class="btn btn-success">
                                        <i class="fas fa-plus-circle"></i> Add Objective Questions
                                    </a>
                                </div>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Question</th>
                                            <th>Options</th>
                                            <th>Correct</th>
                                            <th>Difficulty</th>
                                            <th>Marks</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($objective_questions as $index => $question): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <div class="question-text">
                                                        <?php echo htmlspecialchars(substr($question['question_text'], 0, 100)); ?>
                                                        <?php echo strlen($question['question_text']) > 100 ? '...' : ''; ?>
                                                    </div>
                                                    <?php if ($question['question_image']): ?>
                                                        <small><i class="fas fa-image"></i> Has Image</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="option-text">
                                                        <strong>A:</strong> <?php echo htmlspecialchars(substr($question['option_a'], 0, 30)); ?>
                                                    </span>
                                                    <span class="option-text">
                                                        <strong>B:</strong> <?php echo htmlspecialchars(substr($question['option_b'], 0, 30)); ?>
                                                    </span>
                                                    <?php if ($question['option_c']): ?>
                                                        <span class="option-text">
                                                            <strong>C:</strong> <?php echo htmlspecialchars(substr($question['option_c'], 0, 30)); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($question['option_d']): ?>
                                                        <span class="option-text">
                                                            <strong>D:</strong> <?php echo htmlspecialchars(substr($question['option_d'], 0, 30)); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-success"><?php echo $question['correct_answer']; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo $question['difficulty_level']; ?>">
                                                        <?php echo ucfirst($question['difficulty_level']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info"><?php echo $question['marks']; ?> mark(s)</span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn btn-primary btn-sm btn-icon"
                                                            onclick="viewObjectiveQuestion(<?php echo $question['id']; ?>)"
                                                            title="View Question">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <form method="POST" style="display: inline;"
                                                            onsubmit="return confirmDeleteQuestion()">
                                                            <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                                            <input type="hidden" name="question_type" value="objective">
                                                            <button type="submit" name="delete_question"
                                                                class="btn btn-danger btn-sm btn-icon"
                                                                title="Delete Question">
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
                </div>

                <!-- Subjective Questions Tab -->
                <div class="tab-content <?php echo $current_tab === 'subjective' ? 'active' : ''; ?>" id="subjectiveTab">
                    <!-- Subjective Questions List -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2>Subjective Questions</h2>
                            <span class="badge badge-success"><?php echo count($subjective_questions); ?> Questions</span>
                        </div>

                        <div class="table-container">
                            <?php if (empty($subjective_questions)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-edit"></i>
                                    <h3>No Subjective Questions Found</h3>
                                    <p>No subjective questions have been added for this topic.</p>
                                    <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>&type=subjective" class="btn btn-success">
                                        <i class="fas fa-plus-circle"></i> Add Subjective Questions
                                    </a>
                                </div>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Question</th>
                                            <th>Answer Preview</th>
                                            <th>Difficulty</th>
                                            <th>Marks</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($subjective_questions as $index => $question): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <div class="question-text">
                                                        <?php echo htmlspecialchars(substr($question['question_text'], 0, 100)); ?>
                                                        <?php echo strlen($question['question_text']) > 100 ? '...' : ''; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="question-text">
                                                        <?php echo htmlspecialchars(substr($question['correct_answer'], 0, 100)); ?>
                                                        <?php echo strlen($question['correct_answer']) > 100 ? '...' : ''; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo $question['difficulty_level']; ?>">
                                                        <?php echo ucfirst($question['difficulty_level']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info"><?php echo $question['marks']; ?> mark(s)</span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn btn-primary btn-sm btn-icon"
                                                            onclick="viewSubjectiveQuestion(<?php echo $question['id']; ?>)"
                                                            title="View Question">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <form method="POST" style="display: inline;"
                                                            onsubmit="return confirmDeleteQuestion()">
                                                            <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                                            <input type="hidden" name="question_type" value="subjective">
                                                            <button type="submit" name="delete_question"
                                                                class="btn btn-danger btn-sm btn-icon"
                                                                title="Delete Question">
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
                </div>

                <!-- Theory Questions Tab -->
                <div class="tab-content <?php echo $current_tab === 'theory' ? 'active' : ''; ?>" id="theoryTab">
                    <!-- Theory Questions List -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2>Theory Questions</h2>
                            <span class="badge badge-warning"><?php echo count($theory_questions); ?> Questions</span>
                        </div>

                        <div class="table-container">
                            <?php if (empty($theory_questions)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-file-alt"></i>
                                    <h3>No Theory Questions Found</h3>
                                    <p>No theory questions have been added for this topic.</p>
                                    <a href="add_questions.php?topic_id=<?php echo $topic_id; ?>&type=theory" class="btn btn-success">
                                        <i class="fas fa-plus-circle"></i> Add Theory Questions
                                    </a>
                                </div>
                            <?php else: ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Question</th>
                                            <th>File</th>
                                            <th>Marks</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($theory_questions as $index => $question): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <?php if ($question['question_text']): ?>
                                                        <div class="question-text">
                                                            <?php echo htmlspecialchars(substr($question['question_text'], 0, 100)); ?>
                                                            <?php echo strlen($question['question_text']) > 100 ? '...' : ''; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">No text content</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($question['question_file']): ?>
                                                        <span class="badge badge-primary">
                                                            <i class="fas fa-file"></i> File Attached
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">No file</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info"><?php echo $question['marks']; ?> marks</span>
                                                </td>
                                                <td>
                                                    <?php echo date('M d, Y', strtotime($question['created_at'])); ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <?php if ($question['question_file']): ?>
                                                            <a href="../<?php echo $question['question_file']; ?>"
                                                                class="btn btn-primary btn-sm btn-icon"
                                                                target="_blank" title="View File">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <form method="POST" style="display: inline;"
                                                            onsubmit="return confirmDeleteQuestion()">
                                                            <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                                            <input type="hidden" name="question_type" value="theory">
                                                            <button type="submit" name="delete_question"
                                                                class="btn btn-danger btn-sm btn-icon"
                                                                title="Delete Question">
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
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- View Objective Question Modal -->
    <div class="modal" id="viewObjectiveModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> View Objective Question</h3>
                <button class="modal-close" onclick="closeModal('viewObjectiveModal')">&times;</button>
            </div>
            <div class="modal-body" id="viewObjectiveContent">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('viewObjectiveModal')">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- View Subjective Question Modal -->
    <div class="modal" id="viewSubjectiveModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> View Subjective Question</h3>
                <button class="modal-close" onclick="closeModal('viewSubjectiveModal')">&times;</button>
            </div>
            <div class="modal-body" id="viewSubjectiveContent">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('viewSubjectiveModal')">
                    Close
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

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
            }
        });

        // Tab switching function
        function switchTab(tabName) {
            // Update URL without page reload
            const url = new URL(window.location);
            url.searchParams.set('type', tabName);
            window.history.pushState({}, '', url);

            // Remove active class from all tabs
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });

            // Add active class to selected tab
            document.querySelector(`.tab-button[onclick="switchTab('${tabName}')"]`).classList.add('active');
            document.getElementById(tabName + 'Tab').classList.add('active');
        }

        // Modal functions
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // AJAX functions for view questions
        async function viewObjectiveQuestion(questionId) {
            try {
                const response = await fetch(`ajax/get_objective_question.php?id=${questionId}`);
                const data = await response.json();

                if (data.success) {
                    const question = data.question;
                    let imageHtml = '';
                    if (question.question_image) {
                        imageHtml = `
                            <div class="question-image-container">
                                <img src="../${question.question_image}" alt="Question Image" class="question-image">
                            </div>
                        `;
                    }

                    let optionsHtml = '';
                    const options = [{
                            label: 'A',
                            text: question.option_a
                        },
                        {
                            label: 'B',
                            text: question.option_b
                        },
                        {
                            label: 'C',
                            text: question.option_c
                        },
                        {
                            label: 'D',
                            text: question.option_d
                        }
                    ];

                    options.forEach(option => {
                        if (option.text) {
                            const isCorrect = question.correct_answer === option.label;
                            optionsHtml += `
                                <div class="option-item ${isCorrect ? 'correct' : ''}">
                                    <span class="option-label">${option.label})</span>
                                    <span class="option-text">${option.text}</span>
                                    ${isCorrect ? '<i class="fas fa-check-circle" style="color: var(--success-color);"></i>' : ''}
                                </div>
                            `;
                        }
                    });

                    document.getElementById('viewObjectiveContent').innerHTML = `
                        <div class="question-preview">
                            <div class="question-header">
                                <div class="question-title">Question Preview</div>
                                <span class="badge badge-${question.difficulty_level}">
                                    ${question.difficulty_level.charAt(0).toUpperCase() + question.difficulty_level.slice(1)}
                                </span>
                            </div>
                            <div class="question-content">
                                ${question.question_text}
                            </div>
                            ${imageHtml}
                            <div class="options-container">
                                ${optionsHtml}
                            </div>
                            <div class="question-meta">
                                <div class="meta-item">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Correct Answer: <strong>${question.correct_answer}</strong></span>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-star"></i>
                                    <span>Marks: <strong>${question.marks}</strong></span>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-calendar"></i>
                                    <span>Created: ${new Date(question.created_at).toLocaleDateString()}</span>
                                </div>
                            </div>
                        </div>
                    `;

                    document.getElementById('viewObjectiveModal').classList.add('active');
                } else {
                    alert('Error loading question: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error loading question');
            }
        }

        async function viewSubjectiveQuestion(questionId) {
            try {
                const response = await fetch(`ajax/get_subjective_question.php?id=${questionId}`);
                const data = await response.json();

                if (data.success) {
                    const question = data.question;

                    document.getElementById('viewSubjectiveContent').innerHTML = `
                        <div class="question-preview">
                            <div class="question-header">
                                <div class="question-title">Question Preview</div>
                                <span class="badge badge-${question.difficulty_level}">
                                    ${question.difficulty_level.charAt(0).toUpperCase() + question.difficulty_level.slice(1)}
                                </span>
                            </div>
                            <div class="question-content">
                                <h4>Question:</h4>
                                <p>${question.question_text}</p>
                                
                                <h4>Correct Answer:</h4>
                                <div style="background: #e8f5e9; padding: 15px; border-radius: 8px; border-left: 4px solid var(--success-color);">
                                    ${question.correct_answer}
                                </div>
                            </div>
                            <div class="question-meta">
                                <div class="meta-item">
                                    <i class="fas fa-star"></i>
                                    <span>Marks: <strong>${question.marks}</strong></span>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-calendar"></i>
                                    <span>Created: ${new Date(question.created_at).toLocaleDateString()}</span>
                                </div>
                            </div>
                        </div>
                    `;

                    document.getElementById('viewSubjectiveModal').classList.add('active');
                } else {
                    alert('Error loading question: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error loading question');
            }
        }

        // Delete confirmation
        function confirmDeleteQuestion() {
            return confirm('Are you sure you want to delete this question? This action cannot be undone.');
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

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Focus on topic select if no topic selected
            <?php if (!$selected_topic): ?>
                document.getElementById('topic_select').focus();
            <?php endif; ?>

            // Auto-switch tab based on URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            const typeParam = urlParams.get('type');
            if (typeParam && ['objective', 'subjective', 'theory'].includes(typeParam)) {
                switchTab(typeParam);
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal('viewObjectiveModal');
                closeModal('viewSubjectiveModal');
            }
        });
    </script>
</body>

</html>