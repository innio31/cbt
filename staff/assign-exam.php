<?php
// staff/assign-exam.php - Staff Assign Exam with Create Exam Option
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

// Get student IDs from various sources
$student_ids = [];

// Priority 1: GET parameter student_id (single student)
if (isset($_GET['student_id']) && is_numeric($_GET['student_id'])) {
    $student_ids = [(int)$_GET['student_id']];
}
// Priority 2: GET parameter student_ids (multiple students as comma-separated)
elseif (isset($_GET['student_ids'])) {
    $ids = explode(',', $_GET['student_ids']);
    $student_ids = array_filter(array_map('intval', $ids));
}
// Priority 3: Session selected_students
elseif (isset($_SESSION['selected_students']) && is_array($_SESSION['selected_students'])) {
    $student_ids = array_map('intval', $_SESSION['selected_students']);
    unset($_SESSION['selected_students']);
}
// Priority 4: POST student_ids (from form)
elseif (isset($_POST['student_ids']) && is_array($_POST['student_ids'])) {
    $student_ids = array_map('intval', $_POST['student_ids']);
}

// Debug: Log student IDs for troubleshooting
error_log("Student IDs found: " . json_encode($student_ids));

// If no students selected, redirect back
if (empty($student_ids)) {
    $_SESSION['message'] = "No students selected for exam assignment";
    $_SESSION['message_type'] = "warning";
    header("Location: manage-students.php");
    exit();
}

// Get staff assigned classes and subjects
try {
    // Get assigned classes
    $stmt = $pdo->prepare("
        SELECT DISTINCT class 
        FROM staff_classes 
        WHERE staff_id = ? 
        ORDER BY class
    ");
    $stmt->execute([$staff_id]);
    $assigned_classes = $stmt->fetchAll();
    $assigned_class_names = array_column($assigned_classes, 'class');

    // Get assigned subjects
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
    error_log("Staff assignments error: " . $e->getMessage());
    $error_message = "Error loading assigned classes and subjects";
}

// Get selected students' information
$students = [];
$student_classes = []; // Track unique student classes
try {
    $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT id, admission_number, full_name, class, status
        FROM students 
        WHERE id IN ($placeholders) AND status = 'active'
    ");
    $stmt->execute($student_ids);
    $students = $stmt->fetchAll();

    // Collect unique classes
    foreach ($students as $student) {
        if (!in_array($student['class'], $student_classes)) {
            $student_classes[] = $student['class'];
        }
    }

    // Verify all selected students are in staff's assigned classes
    foreach ($students as $student) {
        if (!in_array($student['class'], $assigned_class_names)) {
            $_SESSION['message'] = "You don't have access to one or more selected students' classes";
            $_SESSION['message_type'] = "error";
            header("Location: manage-students.php");
            exit();
        }
    }
} catch (Exception $e) {
    error_log("Students error: " . $e->getMessage());
    $error_message = "Error loading student information";
}

// Get available exams for staff's classes and subjects
$available_exams = [];
try {
    if (!empty($student_classes) && !empty($assigned_subjects)) {
        // Get class-specific exams
        $class_placeholders = str_repeat('?,', count($student_classes) - 1) . '?';
        $subject_ids = array_column($assigned_subjects, 'id');
        $subject_placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';

        $params = array_merge($subject_ids, $student_classes);

        // Query 1: General exams for the student's class
        $stmt = $pdo->prepare("
            SELECT DISTINCT e.*, s.subject_name,
                   (SELECT COUNT(*) FROM exam_sessions es WHERE es.exam_id = e.id) as total_attempts,
                   (SELECT AVG(percentage) FROM results r WHERE r.exam_id = e.id) as avg_score,
                   'general' as exam_source
            FROM exams e 
            LEFT JOIN subjects s ON e.subject_id = s.id 
            WHERE e.subject_id IN ($subject_placeholders)
            AND e.class IN ($class_placeholders)
            AND e.is_active = 1
        ");

        $general_exams = $stmt->execute($params) ? $stmt->fetchAll() : [];

        // Query 2: Already assigned exams to these specific students
        $student_placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
        $params2 = $student_ids;

        $stmt2 = $pdo->prepare("
            SELECT DISTINCT e.*, s.subject_name,
                   ea.assigned_by, ea.assignment_type, ea.status as assignment_status,
                   (SELECT COUNT(*) FROM exam_sessions es WHERE es.exam_id = e.id AND es.student_id IN ($student_placeholders)) as student_attempts,
                   'assigned' as exam_source
            FROM exams e 
            LEFT JOIN subjects s ON e.subject_id = s.id 
            INNER JOIN exam_assignments ea ON e.id = ea.exam_id
            WHERE ea.student_id IN ($student_placeholders)
            AND e.is_active = 1
        ");

        // Double the student IDs for the IN clause
        $params2 = array_merge($student_ids, $student_ids);
        $assigned_exams = $stmt2->execute($params2) ? $stmt2->fetchAll() : [];

        // Combine both sets of exams, removing duplicates
        $all_exams = array_merge($general_exams, $assigned_exams);

        // Remove duplicates by exam ID
        $unique_exams = [];
        foreach ($all_exams as $exam) {
            $unique_exams[$exam['id']] = $exam;
        }

        $available_exams = array_values($unique_exams);

        // Sort by creation date
        usort($available_exams, function ($a, $b) {
            $timeA = !empty($a['created_at']) ? strtotime($a['created_at']) : 0;
            $timeB = !empty($b['created_at']) ? strtotime($b['created_at']) : 0;
            return $timeB - $timeA;
        });
    }
} catch (Exception $e) {
    error_log("Available exams error: " . $e->getMessage());
    $available_exams = [];
}

// Handle exam creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_exam') {
    $exam_name = trim($_POST['exam_name'] ?? '');
    // Auto-detect class from selected students (use the first student's class)
    $class = !empty($student_classes) ? $student_classes[0] : '';
    $subject_id = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
    $exam_type = $_POST['exam_type'] ?? 'objective';
    $duration_minutes = (int)$_POST['duration_minutes'] ?? 60;
    $instructions = trim($_POST['instructions'] ?? '');
    $objective_count = (int)$_POST['objective_count'] ?? 0;
    $theory_count = (int)$_POST['theory_count'] ?? 0;
    $subjective_count = (int)$_POST['subjective_count'] ?? 0;

    // Validate
    if (empty($exam_name)) {
        $error_message = "Exam name is required.";
    } elseif (empty($class)) {
        $error_message = "Unable to determine student class.";
    } elseif (!in_array($class, $assigned_class_names)) {
        $error_message = "You don't have access to this class.";
    } else {
        try {
            // Convert topics array to JSON if exists
            $topics_json = null;
            if (isset($_POST['topics']) && is_array($_POST['topics'])) {
                $topics_json = json_encode($_POST['topics']);
            }

            // Create new exam
            $stmt = $pdo->prepare("
                INSERT INTO exams (
                    exam_name, class, subject_id, exam_type, duration_minutes,
                    instructions, objective_count, theory_count, subjective_count,
                    is_active, created_at, topics, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, ?)
            ");

            $stmt->execute([
                $exam_name,
                $class,
                $subject_id,
                $exam_type,
                $duration_minutes,
                $instructions,
                $objective_count,
                $theory_count,
                $subjective_count,
                $topics_json,
                $staff_id
            ]);

            $new_exam_id = $pdo->lastInsertId();

            // Log activity
            logActivity($staff_id, 'staff', "Created exam '$exam_name' for class $class");

            // If we're creating for specific students, automatically assign it to them
            if (!empty($student_ids)) {
                foreach ($student_ids as $student_id) {
                    $stmt = $pdo->prepare("
                        INSERT INTO exam_assignments 
                        (student_id, exam_id, assigned_by, assignment_type, status, created_at)
                        VALUES (?, ?, ?, 'immediate', 'assigned', NOW())
                    ");
                    $stmt->execute([$student_id, $new_exam_id, $staff_id]);

                    // Log assignment
                    logActivity($staff_id, 'staff', "Auto-assigned exam '$exam_name' to student ID: $student_id");
                }

                $_SESSION['message'] = "Exam created and automatically assigned to selected students!";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "Exam created successfully! You can now assign it to students.";
                $_SESSION['message_type'] = "success";
            }

            header("Location: manage-students.php");
            exit();
        } catch (Exception $e) {
            error_log("Create exam error: " . $e->getMessage());
            $error_message = "Error creating exam: " . $e->getMessage();
        }
    }
}

// Handle exam assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_exam') {
    $exam_id = (int)$_POST['exam_id'];
    $assignment_type = $_POST['assignment_type'] ?? 'immediate'; // immediate or scheduled
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;
    $allow_retake = isset($_POST['allow_retake']) ? 1 : 0;

    // Validate exam exists and staff has access
    $valid_exam = false;
    foreach ($available_exams as $exam) {
        if ($exam['id'] == $exam_id) {
            $valid_exam = true;
            $selected_exam = $exam;
            break;
        }
    }

    if (!$valid_exam) {
        $error_message = "Invalid exam selected";
    } else {
        try {
            $pdo->beginTransaction();

            $success_count = 0;
            $error_count = 0;
            $error_details = [];

            foreach ($students as $student) {
                try {
                    // Check if exam is already assigned to this student
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as count 
                        FROM exam_assignments 
                        WHERE student_id = ? AND exam_id = ? AND status != 'completed'
                    ");
                    $stmt->execute([$student['id'], $exam_id]);
                    $existing_assignment = $stmt->fetch();

                    if ($existing_assignment['count'] > 0 && !$allow_retake) {
                        $error_details[] = $student['full_name'] . ": Exam already assigned";
                        $error_count++;
                        continue;
                    }

                    // Insert exam assignment
                    $stmt = $pdo->prepare("
                        INSERT INTO exam_assignments 
                        (student_id, exam_id, assigned_by, assignment_type, start_date, end_date, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'assigned', NOW())
                    ");

                    $start_datetime = $assignment_type === 'scheduled' && $start_date ? $start_date . ' 00:00:00' : null;
                    $end_datetime = $assignment_type === 'scheduled' && $end_date ? $end_date . ' 23:59:59' : null;

                    $stmt->execute([
                        $student['id'],
                        $exam_id,
                        $staff_id,
                        $assignment_type,
                        $start_datetime,
                        $end_datetime
                    ]);

                    $assignment_id = $pdo->lastInsertId();

                    // Log activity
                    logActivity($staff_id, 'staff', "Assigned exam '{$selected_exam['exam_name']}' to student: {$student['full_name']}");

                    $success_count++;
                } catch (Exception $e) {
                    error_log("Assignment error for student {$student['id']}: " . $e->getMessage());
                    $error_details[] = $student['full_name'] . ": " . $e->getMessage();
                    $error_count++;
                }
            }

            $pdo->commit();

            // Prepare success message
            $message = "Exam assigned successfully to {$success_count} student(s)";
            if ($error_count > 0) {
                $message .= ". Failed for {$error_count} student(s)";
                $_SESSION['message_details'] = $error_details;
            }

            $_SESSION['message'] = $message;
            $_SESSION['message_type'] = $success_count > 0 ? "success" : "warning";

            header("Location: manage-students.php");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Transaction error: " . $e->getMessage());
            $error_message = "Error assigning exam: " . $e->getMessage();
        }
    }
}

// Get topics for assigned subjects
$topics_list = [];
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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Exam - Digital CBT System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Flatpickr for date picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

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
            transform: translateX(5px);
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
            transition: all 0.3s ease;
        }

        /* Top Header */
        .top-header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            border-left: 5px solid var(--accent-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .back-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
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
            text-decoration: none;
        }

        .back-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(66, 153, 225, 0.3);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .content-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Selected Students */
        .students-list {
            max-height: 400px;
            overflow-y: auto;
            border-radius: 10px;
            border: 1px solid var(--light-color);
        }

        .student-item {
            padding: 15px 20px;
            border-bottom: 1px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .student-item:last-child {
            border-bottom: none;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 4px;
        }

        .student-details {
            font-size: 0.9rem;
            color: #718096;
            display: flex;
            gap: 15px;
        }

        /* Exam Selection */
        .exam-list {
            max-height: 500px;
            overflow-y: auto;
        }

        .exam-item {
            padding: 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .exam-item:hover {
            border-color: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(66, 153, 225, 0.1);
        }

        .exam-item.selected {
            border-color: var(--secondary-color);
            background: linear-gradient(135deg, rgba(66, 153, 225, 0.05), rgba(44, 82, 130, 0.05));
        }

        .exam-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .exam-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 5px;
        }

        .exam-subject {
            background: var(--light-color);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--dark-color);
        }

        .exam-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-size: 0.85rem;
            color: #718096;
        }

        .detail-value {
            font-weight: 600;
            color: var(--dark-color);
        }

        /* Assignment Options */
        .options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .option-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .option-group label {
            font-weight: 600;
            color: var(--dark-color);
            font-size: 1rem;
        }

        .option-select,
        .option-input {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
            font-family: 'Poppins', sans-serif;
        }

        .option-select:focus,
        .option-input:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            transform: scale(1.2);
        }

        .checkbox-group label {
            font-weight: normal;
            color: #4a5568;
        }

        /* Date inputs */
        .date-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 15px;
        }

        .date-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 2px solid var(--light-color);
        }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            font-size: 1rem;
            flex: 1;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(66, 153, 225, 0.3);
        }

        .btn-secondary {
            background: var(--light-color);
            color: var(--dark-color);
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-left: 4px solid;
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
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: var(--dark-color);
        }

        /* Summary Section */
        .summary-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
        }

        .summary-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .summary-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            background: linear-gradient(135deg, var(--accent-color), #dd6b20);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .summary-content h3 {
            color: var(--dark-color);
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        /* Mobile Responsive */
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

            .content-grid {
                grid-template-columns: 1fr;
            }

            .date-inputs {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        /* Exam stats */
        .exam-stats {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }

        .stat-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .stat-attempts {
            background: #bee3f8;
            color: var(--secondary-color);
        }

        .stat-score {
            background: #c6f6d5;
            color: var(--success-color);
        }

        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 20px;
            z-index: 1000;
            display: none;
        }

        .spinner {
            width: 60px;
            height: 60px;
            border: 5px solid var(--light-color);
            border-top-color: var(--secondary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Exam type indicator */
        .exam-type {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-objective {
            background: #bee3f8;
            color: var(--secondary-color);
        }

        .type-subjective {
            background: #feebc8;
            color: var(--warning-color);
        }

        .type-theory {
            background: #fed7d7;
            color: var(--danger-color);
        }

        /* Modal styles for create exam */
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
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .modal-header .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #718096;
            transition: color 0.3s ease;
        }

        .modal-header .close-btn:hover {
            color: var(--danger-color);
        }

        .modal-body {
            margin-bottom: 25px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            padding-top: 20px;
            border-top: 2px solid var(--light-color);
        }

        /* Form styles */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            color: var(--dark-color);
        }

        .form-group .required::after {
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

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%234a5568' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 18px center;
            background-size: 16px;
            padding-right: 50px;
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

        /* Exam source badge */
        .exam-source-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .source-general {
            background: #bee3f8;
            color: var(--secondary-color);
        }

        .source-assigned {
            background: #c6f6d5;
            color: var(--success-color);
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <p>Assigning exam, please wait...</p>
    </div>

    <!-- Create Exam Modal -->
    <div class="modal" id="createExamModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Create New Exam for Selected Students</h3>
                <button type="button" class="close-btn" onclick="hideCreateExamModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="createExamForm">
                    <input type="hidden" name="action" value="create_exam">
                    <input type="hidden" name="class" id="autoClass" value="<?php echo !empty($student_classes) ? htmlspecialchars($student_classes[0]) : ''; ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="required">Exam Name</label>
                            <input type="text" name="exam_name" class="form-control" required
                                placeholder="e.g., Individual Assessment for Selected Students">
                        </div>

                        <div class="form-group">
                            <label>Class (Auto-detected)</label>
                            <input type="text" class="form-control" value="<?php echo !empty($student_classes) ? htmlspecialchars($student_classes[0]) : 'Not available'; ?>" readonly>
                            <small style="color: #718096; font-size: 0.9rem;">Automatically detected from selected students</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Subject</label>
                            <select name="subject_id" class="form-control">
                                <option value="">Select Subject (Optional)</option>
                                <?php foreach ($assigned_subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Exam Type</label>
                            <select name="exam_type" class="form-control">
                                <option value="objective">Objective</option>
                                <option value="theory">Theory</option>
                                <option value="subjective">Subjective</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Duration (minutes)</label>
                            <input type="number" name="duration_minutes" class="form-control" value="60" min="1" max="300">
                        </div>

                        <div class="form-group">
                            <label>Objective Questions Count</label>
                            <input type="number" name="objective_count" class="form-control" value="0" min="0" max="200">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Theory Questions Count</label>
                            <input type="number" name="theory_count" class="form-control" value="0" min="0" max="50">
                        </div>

                        <div class="form-group">
                            <label>Subjective Questions Count</label>
                            <input type="number" name="subjective_count" class="form-control" value="0" min="0" max="50">
                        </div>
                    </div>

                    <!-- Topics Section -->
                    <?php if (!empty($topics_list)): ?>
                        <div class="form-group">
                            <label>Topics Covered</label>
                            <div class="topics-grid">
                                <?php foreach ($topics_list as $topic): ?>
                                    <label class="topic-checkbox">
                                        <input type="checkbox" name="topics[]" value="<?php echo $topic['id']; ?>">
                                        <div>
                                            <div class="topic-label"><?php echo htmlspecialchars($topic['topic_name']); ?></div>
                                            <div class="topic-subject"><?php echo htmlspecialchars($topic['subject_name']); ?></div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Instructions for Students</label>
                        <textarea name="instructions" class="form-control" rows="4"
                            placeholder="Enter exam instructions for students..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideCreateExamModal()">Cancel</button>
                <button type="submit" form="createExamForm" class="btn btn-primary" id="createExamBtn">
                    <i class="fas fa-save"></i> Create & Auto-Assign Exam
                </button>
            </div>
        </div>
    </div>

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
                <h1>Assign Exam to Students</h1>
                <p>
                    <i class="fas fa-file-alt"></i>
                    Assign exams to selected students or create a new one
                </p>
            </div>
            <a href="manage-students.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Students
            </a>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type'] ?? 'success'; ?>">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['message']); ?>
                <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
            </div>
        <?php endif; ?>

        <!-- Summary Section -->
        <div class="summary-section">
            <div class="summary-header">
                <div class="summary-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="summary-content">
                    <h3>Assigning Exam to <?php echo count($students); ?> Student(s)</h3>
                    <p>
                        Student(s) Class:
                        <?php if (!empty($student_classes)): ?>
                            <?php foreach ($student_classes as $class): ?>
                                <strong><?php echo htmlspecialchars($class); ?></strong><?php echo ($class !== end($student_classes)) ? ', ' : ''; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span style="color: #e53e3e;">Not available</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Left Column: Selected Students -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Selected Students</h3>
                    <span class="student-count"><?php echo count($students); ?> students</span>
                </div>

                <?php if (!empty($students)): ?>
                    <div class="students-list">
                        <?php foreach ($students as $student):
                            // Get first letter of first name for avatar
                            $name_parts = explode(' ', $student['full_name']);
                            $first_letter = strtoupper(substr($name_parts[0], 0, 1));
                        ?>
                            <div class="student-item">
                                <div class="student-avatar">
                                    <?php echo $first_letter; ?>
                                </div>
                                <div class="student-info">
                                    <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                    <div class="student-details">
                                        <span><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($student['admission_number']); ?></span>
                                        <span><i class="fas fa-users-class"></i> <?php echo htmlspecialchars($student['class']); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-graduate"></i>
                        <h3>No Students Selected</h3>
                        <p>Please select students to assign exams to.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Column: Exam Selection -->
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-file-alt"></i> Available Exams</h3>
                    <button type="button" class="btn btn-primary" onclick="showCreateExamModal()">
                        <i class="fas fa-plus-circle"></i> Create New Exam
                    </button>
                </div>

                <form method="POST" action="" id="assignExamForm">
                    <input type="hidden" name="action" value="assign_exam">
                    <input type="hidden" name="exam_id" id="selected_exam_id" value="">

                    <?php if (!empty($available_exams)): ?>
                        <div class="exam-list" id="examList">
                            <?php foreach ($available_exams as $exam):
                                $exam_type_class = '';
                                switch ($exam['exam_type']) {
                                    case 'objective':
                                        $exam_type_class = 'type-objective';
                                        break;
                                    case 'subjective':
                                        $exam_type_class = 'type-subjective';
                                        break;
                                    case 'theory':
                                        $exam_type_class = 'type-theory';
                                        break;
                                }

                                $source_badge_class = $exam['exam_source'] === 'assigned' ? 'source-assigned' : 'source-general';
                                $source_text = $exam['exam_source'] === 'assigned' ? 'Already Assigned' : 'General Exam';

                                // Safely format dates
                                $created_date = !empty($exam['created_at']) ? date('M d, Y', strtotime($exam['created_at'])) : 'Not available';
                                $start_date_formatted = !empty($exam['start_date']) ? date('M d, Y', strtotime($exam['start_date'])) : null;
                                $end_date_formatted = !empty($exam['end_date']) ? date('M d, Y', strtotime($exam['end_date'])) : null;
                            ?>
                                <div class="exam-item" data-exam-id="<?php echo $exam['id']; ?>">
                                    <span class="exam-source-badge <?php echo $source_badge_class; ?>">
                                        <?php echo $source_text; ?>
                                    </span>

                                    <div class="exam-header">
                                        <div>
                                            <div class="exam-title"><?php echo htmlspecialchars($exam['exam_name']); ?></div>
                                            <div style="display: flex; gap: 10px; margin-top: 8px;">
                                                <span class="exam-subject"><?php echo htmlspecialchars($exam['subject_name'] ?? 'General'); ?></span>
                                                <span class="exam-subject"><?php echo htmlspecialchars($exam['class']); ?></span>
                                                <span class="exam-type <?php echo $exam_type_class; ?>">
                                                    <?php echo ucfirst($exam['exam_type']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="exam-stats">
                                            <?php if (isset($exam['student_attempts']) && $exam['student_attempts'] > 0): ?>
                                                <span class="stat-badge stat-attempts">
                                                    <i class="fas fa-user"></i> <?php echo $exam['student_attempts']; ?> student attempts
                                                </span>
                                            <?php elseif (!empty($exam['total_attempts']) && $exam['total_attempts'] > 0): ?>
                                                <span class="stat-badge stat-attempts">
                                                    <i class="fas fa-users"></i> <?php echo $exam['total_attempts']; ?> total attempts
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($exam['avg_score'])): ?>
                                                <span class="stat-badge stat-score">
                                                    <i class="fas fa-chart-line"></i> <?php echo number_format($exam['avg_score'], 1); ?>% avg
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="exam-details">
                                        <div class="detail-item">
                                            <span class="detail-label">Duration</span>
                                            <span class="detail-value"><?php echo !empty($exam['duration_minutes']) ? $exam['duration_minutes'] : 60; ?> mins</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Questions</span>
                                            <span class="detail-value">
                                                <?php
                                                $total_questions = (!empty($exam['objective_count']) ? $exam['objective_count'] : 0) +
                                                    (!empty($exam['subjective_count']) ? $exam['subjective_count'] : 0) +
                                                    (!empty($exam['theory_count']) ? $exam['theory_count'] : 0);
                                                echo $total_questions > 0 ? $total_questions : 'Variable';
                                                ?>
                                            </span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Created</span>
                                            <span class="detail-value">
                                                <?php echo $created_date; ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if ($exam['exam_source'] === 'assigned'): ?>
                                        <div style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e2e8f0;">
                                            <small style="color: #718096;">
                                                <i class="fas fa-info-circle"></i> Already assigned to this student
                                                <?php if (!empty($exam['assignment_type']) && $exam['assignment_type'] === 'scheduled' && $start_date_formatted && $end_date_formatted): ?>
                                                    - Scheduled from <?php echo $start_date_formatted; ?> to <?php echo $end_date_formatted; ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Assignment Options -->
                        <div id="assignmentOptions" style="display: none;">
                            <div class="card-header" style="margin-top: 30px;">
                                <h3><i class="fas fa-cog"></i> Assignment Options</h3>
                            </div>

                            <div class="options-grid">
                                <div class="option-group">
                                    <label for="assignment_type">Assignment Type</label>
                                    <select name="assignment_type" id="assignment_type" class="option-select" required>
                                        <option value="immediate">Immediate (Available Now)</option>
                                        <option value="scheduled">Scheduled (Available Later)</option>
                                    </select>
                                </div>

                                <div class="option-group">
                                    <label for="allow_retake">Retake Policy</label>
                                    <div class="checkbox-group">
                                        <input type="checkbox" name="allow_retake" id="allow_retake" value="1">
                                        <label for="allow_retake">Allow students to retake this exam</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Date inputs (hidden by default) -->
                            <div id="dateOptions" style="display: none; margin-top: 20px;">
                                <div class="date-inputs">
                                    <div class="date-group">
                                        <label for="start_date">Start Date</label>
                                        <input type="text" name="start_date" id="start_date" class="option-input date-picker"
                                            placeholder="Select start date">
                                    </div>
                                    <div class="date-group">
                                        <label for="end_date">End Date</label>
                                        <input type="text" name="end_date" id="end_date" class="option-input date-picker"
                                            placeholder="Select end date">
                                    </div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="action-buttons">
                                <button type="button" class="btn btn-secondary" onclick="clearSelection()">
                                    <i class="fas fa-times"></i> Cancel Selection
                                </button>
                                <button type="submit" class="btn btn-primary" id="assignButton">
                                    <i class="fas fa-paper-plane"></i> Assign Exam
                                </button>
                            </div>
                        </div>

                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h3>No Exams Available</h3>
                            <p>You don't have any exams available for the selected students' classes.</p>
                            <button type="button" class="btn btn-primary" onclick="showCreateExamModal()" style="margin-top: 20px;">
                                <i class="fas fa-plus-circle"></i> Create New Exam
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            mobileMenuBtn.innerHTML = sidebar.classList.contains('active') ?
                '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
        });

        // Initialize date picker
        document.addEventListener('DOMContentLoaded', function() {
            flatpickr(".date-picker", {
                dateFormat: "Y-m-d",
                minDate: "today",
                disableMobile: true
            });

            // Handle assignment type change
            const assignmentType = document.getElementById('assignment_type');
            const dateOptions = document.getElementById('dateOptions');

            if (assignmentType) {
                assignmentType.addEventListener('change', function() {
                    if (this.value === 'scheduled') {
                        dateOptions.style.display = 'block';
                    } else {
                        dateOptions.style.display = 'none';
                    }
                });
            }

            // Auto-select first exam if only one exists
            const examItems = document.querySelectorAll('.exam-item');
            if (examItems.length === 1) {
                setTimeout(() => {
                    examItems[0].click();
                }, 500);
            }
        });

        // Exam selection
        let selectedExamId = null;
        const examItems = document.querySelectorAll('.exam-item');
        const assignmentOptions = document.getElementById('assignmentOptions');
        const selectedExamIdInput = document.getElementById('selected_exam_id');

        examItems.forEach(item => {
            item.addEventListener('click', function() {
                const examId = this.getAttribute('data-exam-id');

                // Remove selection from all items
                examItems.forEach(exam => exam.classList.remove('selected'));

                // Add selection to clicked item
                this.classList.add('selected');

                // Store selected exam ID
                selectedExamId = examId;
                selectedExamIdInput.value = examId;

                // Show assignment options
                if (assignmentOptions) {
                    assignmentOptions.style.display = 'block';

                    // Scroll to assignment options
                    assignmentOptions.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Clear selection
        function clearSelection() {
            examItems.forEach(exam => exam.classList.remove('selected'));
            selectedExamId = null;
            selectedExamIdInput.value = '';
            if (assignmentOptions) {
                assignmentOptions.style.display = 'none';
            }
        }

        // Form submission
        const assignExamForm = document.getElementById('assignExamForm');
        const loadingOverlay = document.getElementById('loadingOverlay');

        if (assignExamForm) {
            assignExamForm.addEventListener('submit', function(e) {
                if (!selectedExamId) {
                    e.preventDefault();
                    alert('Please select an exam first');
                    return;
                }

                // Validate scheduled dates if selected
                const assignmentType = document.getElementById('assignment_type');
                if (assignmentType && assignmentType.value === 'scheduled') {
                    const startDate = document.getElementById('start_date')?.value;
                    const endDate = document.getElementById('end_date')?.value;

                    if (!startDate || !endDate) {
                        e.preventDefault();
                        alert('Please select both start and end dates for scheduled assignment');
                        return;
                    }

                    const start = new Date(startDate);
                    const end = new Date(endDate);

                    if (end < start) {
                        e.preventDefault();
                        alert('End date must be after start date');
                        return;
                    }
                }

                // Show loading overlay
                if (loadingOverlay) {
                    loadingOverlay.style.display = 'flex';
                }

                // Confirm assignment
                const studentCount = <?php echo count($students); ?>;
                if (!confirm(`Assign this exam to ${studentCount} student(s)?\n\nThis action cannot be undone.`)) {
                    e.preventDefault();
                    if (loadingOverlay) {
                        loadingOverlay.style.display = 'none';
                    }
                    return;
                }
            });
        }

        // Create exam modal functions
        function showCreateExamModal() {
            document.getElementById('createExamModal').classList.add('active');
        }

        function hideCreateExamModal() {
            document.getElementById('createExamModal').classList.remove('active');
        }

        // Close modal when clicking outside
        document.getElementById('createExamModal').addEventListener('click', function(event) {
            if (event.target === this) {
                hideCreateExamModal();
            }
        });

        // Create exam form validation
        const createExamForm = document.getElementById('createExamForm');
        const createExamBtn = document.getElementById('createExamBtn');

        if (createExamForm) {
            createExamForm.addEventListener('submit', function(e) {
                const examName = this.querySelector('[name="exam_name"]').value;
                const examClass = this.querySelector('#autoClass').value;

                if (!examName.trim()) {
                    e.preventDefault();
                    alert('Please enter an exam name');
                    return;
                }

                if (!examClass) {
                    e.preventDefault();
                    alert('Unable to determine student class. Please select students first.');
                    return;
                }

                // Show loading
                if (createExamBtn) {
                    const originalText = createExamBtn.innerHTML;
                    createExamBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
                    createExamBtn.disabled = true;

                    // Re-enable button if form doesn't submit
                    setTimeout(() => {
                        createExamBtn.innerHTML = originalText;
                        createExamBtn.disabled = false;
                    }, 3000);
                }
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
                }
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to clear selection or close sidebar
            if (e.key === 'Escape') {
                if (selectedExamId) {
                    clearSelection();
                } else if (window.innerWidth <= 768) {
                    sidebar.classList.remove('active');
                    mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
                }
                hideCreateExamModal();
            }

            // Ctrl+Enter to submit form
            if (e.ctrlKey && e.key === 'Enter' && selectedExamId) {
                e.preventDefault();
                document.getElementById('assignButton')?.click();
            }

            // Ctrl+N to create new exam
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                showCreateExamModal();
            }
        });

        // Handle exam type changes in create form
        const examTypeSelect = document.querySelector('select[name="exam_type"]');
        if (examTypeSelect) {
            function updateQuestionCounts() {
                const type = examTypeSelect.value;
                const objectiveCount = document.querySelector('input[name="objective_count"]');
                const theoryCount = document.querySelector('input[name="theory_count"]');
                const subjectiveCount = document.querySelector('input[name="subjective_count"]');

                if (type === 'objective') {
                    if (objectiveCount) objectiveCount.disabled = false;
                    if (theoryCount) theoryCount.disabled = true;
                    if (subjectiveCount) subjectiveCount.disabled = true;
                } else if (type === 'theory') {
                    if (objectiveCount) objectiveCount.disabled = true;
                    if (theoryCount) theoryCount.disabled = false;
                    if (subjectiveCount) subjectiveCount.disabled = true;
                } else if (type === 'subjective') {
                    if (objectiveCount) objectiveCount.disabled = true;
                    if (theoryCount) theoryCount.disabled = true;
                    if (subjectiveCount) subjectiveCount.disabled = false;
                }
            }

            examTypeSelect.addEventListener('change', updateQuestionCounts);
            // Initialize on page load
            updateQuestionCounts();
        }
    </script>
</body>

</html>