<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit();
}

$current_session = date('Y') . '/' . (date('Y') + 1);
$current_term = 'First';

// Get available classes
$classes = $pdo->query("SELECT DISTINCT class FROM students WHERE status = 'active' ORDER BY class")->fetchAll();

$selected_class = $_POST['class'] ?? ($_GET['class'] ?? '');
$selected_session = $_POST['session'] ?? $current_session;
$selected_term = $_POST['term'] ?? $current_term;

$message = '';
$message_type = '';
$results = [];

// Handle position calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate_positions'])) {
    $class = $_POST['class'];
    $session = $_POST['session'];
    $term = $_POST['term'];

    if (empty($class)) {
        $message = "Please select a class.";
        $message_type = "warning";
    } else {
        try {
            // Start transaction
            $pdo->beginTransaction();

            // Get all students in the class
            $stmt = $pdo->prepare("SELECT id, full_name FROM students WHERE class = ? AND status = 'active' ORDER BY full_name");
            $stmt->execute([$class]);
            $students = $stmt->fetchAll();

            if (empty($students)) {
                $message = "No active students found in class $class.";
                $message_type = "warning";
                $pdo->rollBack();
            } else {
                // Arrays to store student data
                $student_scores = [];
                $student_averages = [];

                // Get score types from settings
                $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE class = ? AND session = ? AND term = ?");
                $stmt->execute([$class, $session, $term]);
                $settings = $stmt->fetch();

                if (!$settings) {
                    $message = "No report card settings found for $class in $session - $term. Please configure settings first.";
                    $message_type = "error";
                    $pdo->rollBack();
                } else {
                    $max_score = $settings['max_score'];
                    $score_types = json_decode($settings['score_types'], true);

                    // For each student, get all subjects and calculate averages
                    foreach ($students as $student) {
                        $student_id = $student['id'];
                        $student_name = $student['full_name'];

                        // Get all scores for this student
                        $stmt = $pdo->prepare("
                            SELECT ss.*, sub.subject_name 
                            FROM student_scores ss 
                            JOIN subjects sub ON ss.subject_id = sub.id 
                            WHERE ss.student_id = ? AND ss.session = ? AND ss.term = ?
                        ");
                        $stmt->execute([$student_id, $session, $term]);
                        $scores = $stmt->fetchAll();

                        $total_percentage = 0;
                        $subject_count = 0;
                        $subject_data = [];

                        foreach ($scores as $score) {
                            // Decode score data
                            $score_data = json_decode($score['score_data'], true);
                            if (is_array($score_data)) {
                                $total_score = array_sum($score_data);
                                $percentage = ($max_score > 0) ? ($total_score / $max_score) * 100 : 0;

                                $subject_data[] = [
                                    'subject_id' => $score['subject_id'],
                                    'subject_name' => $score['subject_name'],
                                    'total_score' => $total_score,
                                    'percentage' => $percentage
                                ];

                                $total_percentage += $percentage;
                                $subject_count++;
                            }
                        }

                        // Calculate overall average
                        $overall_average = $subject_count > 0 ? $total_percentage / $subject_count : 0;

                        $student_averages[$student_id] = [
                            'student_id' => $student_id,
                            'student_name' => $student_name,
                            'average' => $overall_average,
                            'total_score' => $overall_average * $subject_count / 100,
                            'subject_count' => $subject_count,
                            'subjects' => $subject_data
                        ];

                        $student_scores[$student_id] = $subject_data;
                    }

                    // Sort students by average (highest first) for class position
                    uasort($student_averages, function ($a, $b) {
                        if ($a['average'] == $b['average']) return 0;
                        return ($a['average'] > $b['average']) ? -1 : 1;
                    });

                    // Calculate class positions
                    $position = 1;
                    $prev_average = null;
                    $position_map = [];

                    foreach ($student_averages as $student_id => $data) {
                        if ($prev_average !== null && $data['average'] < $prev_average) {
                            $position++;
                        }
                        $position_map[$student_id] = $position;
                        $prev_average = $data['average'];
                    }

                    // Calculate subject positions for each subject
                    $subject_positions = [];

                    // Get all subjects that have scores for this class
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT ss.subject_id, sub.subject_name 
                        FROM student_scores ss 
                        JOIN subjects sub ON ss.subject_id = sub.id 
                        WHERE ss.session = ? AND ss.term = ? 
                        AND ss.student_id IN (SELECT id FROM students WHERE class = ? AND status = 'active')
                    ");
                    $stmt->execute([$session, $term, $class]);
                    $subjects = $stmt->fetchAll();

                    foreach ($subjects as $subject) {
                        $subject_id = $subject['subject_id'];
                        $subject_name = $subject['subject_name'];

                        // Get all students with scores for this subject
                        $subject_scores = [];

                        foreach ($students as $student) {
                            $student_id = $student['id'];

                            // Find the score for this subject
                            if (isset($student_scores[$student_id])) {
                                foreach ($student_scores[$student_id] as $score) {
                                    if ($score['subject_id'] == $subject_id) {
                                        $subject_scores[] = [
                                            'student_id' => $student_id,
                                            'student_name' => $student['full_name'],
                                            'percentage' => $score['percentage']
                                        ];
                                        break;
                                    }
                                }
                            }
                        }

                        // Sort by percentage (highest first)
                        usort($subject_scores, function ($a, $b) {
                            if ($a['percentage'] == $b['percentage']) return 0;
                            return ($a['percentage'] > $b['percentage']) ? -1 : 1;
                        });

                        // Calculate positions
                        $position = 1;
                        $prev_percentage = null;

                        foreach ($subject_scores as $index => $score) {
                            if ($prev_percentage !== null && $score['percentage'] < $prev_percentage) {
                                $position++;
                            }
                            $subject_positions[$subject_id][$score['student_id']] = $position;
                            $prev_percentage = $score['percentage'];
                        }
                    }

                    // Save positions to database
                    $saved_count = 0;
                    $error_count = 0;

                    // Clear existing positions for this class, session, term
                    $stmt = $pdo->prepare("
                        DELETE sp FROM student_positions sp 
                        JOIN students s ON sp.student_id = s.id 
                        WHERE s.class = ? AND sp.session = ? AND sp.term = ?
                    ");
                    $stmt->execute([$class, $session, $term]);

                    // Clear existing subject positions
                    $stmt = $pdo->prepare("
                        DELETE ssp FROM student_subject_positions ssp 
                        JOIN students s ON ssp.student_id = s.id 
                        WHERE s.class = ? AND ssp.session = ? AND ssp.term = ?
                    ");
                    $stmt->execute([$class, $session, $term]);

                    // Save class positions
                    foreach ($student_averages as $student_id => $data) {
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO student_positions 
                                (student_id, session, term, average, class_position, total_students, created_at, updated_at) 
                                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                            ");
                            $stmt->execute([
                                $student_id,
                                $session,
                                $term,
                                $data['average'],
                                $position_map[$student_id],
                                count($students)
                            ]);
                            $saved_count++;
                        } catch (Exception $e) {
                            $error_count++;
                            error_log("Error saving position for student $student_id: " . $e->getMessage());
                        }
                    }

                    // Save subject positions
                    foreach ($subject_positions as $subject_id => $student_positions) {
                        foreach ($student_positions as $student_id => $position) {
                            try {
                                $stmt = $pdo->prepare("
                                    INSERT INTO student_subject_positions 
                                    (student_id, subject_id, session, term, subject_position, created_at, updated_at) 
                                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                                ");
                                $stmt->execute([
                                    $student_id,
                                    $subject_id,
                                    $session,
                                    $term,
                                    $position
                                ]);
                            } catch (Exception $e) {
                                $error_count++;
                                error_log("Error saving subject position for student $student_id, subject $subject_id: " . $e->getMessage());
                            }
                        }
                    }

                    // Update student_scores table with positions and grades
                    foreach ($student_averages as $student_id => $data) {
                        foreach ($data['subjects'] as $subject) {
                            $subject_id = $subject['subject_id'];
                            $percentage = $subject['percentage'];
                            $grade = calculateGrade($percentage, $settings['grading_system']);
                            $subject_position = isset($subject_positions[$subject_id][$student_id]) ? $subject_positions[$subject_id][$student_id] : null;

                            try {
                                $stmt = $pdo->prepare("
                                    UPDATE student_scores 
                                    SET percentage = ?, grade = ?, subject_position = ?, updated_at = NOW()
                                    WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ?
                                ");
                                $stmt->execute([
                                    $percentage,
                                    $grade,
                                    $subject_position,
                                    $student_id,
                                    $subject_id,
                                    $session,
                                    $term
                                ]);
                            } catch (Exception $e) {
                                error_log("Error updating student_scores for student $student_id: " . $e->getMessage());
                            }
                        }
                    }

                    // Commit transaction
                    $pdo->commit();

                    $message = "✅ Positions calculated successfully! ";
                    $message .= "Class positions saved for $saved_count students. ";
                    if ($error_count > 0) {
                        $message .= "($error_count errors occurred)";
                    }
                    $message_type = "success";

                    // Store results for display
                    $results = [
                        'students' => $student_averages,
                        'class' => $class,
                        'session' => $session,
                        'term' => $term,
                        'positions' => $position_map,
                        'total_students' => count($students),
                        'subjects' => $subjects,
                        'subject_positions' => $subject_positions
                    ];
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Error calculating positions: " . $e->getMessage();
            $message_type = "error";
            error_log("Position calculation error: " . $e->getMessage());
        }
    }
}

// Helper function to calculate grade
function calculateGrade($percentage, $grading_system)
{
    switch ($grading_system) {
        case 'simple':
            if ($percentage >= 80) return 'A';
            if ($percentage >= 70) return 'B';
            if ($percentage >= 60) return 'C';
            if ($percentage >= 50) return 'D';
            if ($percentage >= 40) return 'E';
            return 'F';

        case 'american':
            if ($percentage >= 97) return 'A+';
            if ($percentage >= 93) return 'A';
            if ($percentage >= 90) return 'A-';
            if ($percentage >= 87) return 'B+';
            if ($percentage >= 83) return 'B';
            if ($percentage >= 80) return 'B-';
            if ($percentage >= 77) return 'C+';
            if ($percentage >= 73) return 'C';
            if ($percentage >= 70) return 'C-';
            if ($percentage >= 67) return 'D+';
            if ($percentage >= 63) return 'D';
            if ($percentage >= 60) return 'D-';
            return 'F';

        case 'waec':
            if ($percentage >= 75) return 'A1';
            if ($percentage >= 70) return 'B2';
            if ($percentage >= 65) return 'B3';
            if ($percentage >= 60) return 'C4';
            if ($percentage >= 55) return 'C5';
            if ($percentage >= 50) return 'C6';
            if ($percentage >= 45) return 'D7';
            if ($percentage >= 40) return 'E8';
            return 'F9';

        default:
            return 'F';
    }
}

// Get existing positions for display if class is selected
$existing_positions = null;
if ($selected_class) {
    $stmt = $pdo->prepare("
        SELECT sp.*, s.full_name, s.admission_number 
        FROM student_positions sp
        JOIN students s ON sp.student_id = s.id
        WHERE s.class = ? AND sp.session = ? AND sp.term = ?
        ORDER BY sp.class_position ASC
    ");
    $stmt->execute([$selected_class, $selected_session, $selected_term]);
    $existing_positions = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calculate Positions - <?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School Management System'; ?></title>

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
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
            z-index: 100;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }

        .sidebar-content {
            padding: 0 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 30px;
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

        .nav-links {
            list-style: none;
            margin-bottom: 30px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            border-radius: 8px;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--secondary-color);
        }

        .nav-links i {
            width: 20px;
            text-align: center;
        }

        .main-content {
            margin-left: 260px;
            padding: 20px;
            min-height: 100vh;
        }

        .top-header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        .header-title p {
            color: #666;
            font-size: 0.95rem;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .form-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .form-section h2 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 0.95rem;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }

        .alert i {
            font-size: 1.2rem;
        }

        .btn {
            background: linear-gradient(135deg, var(--secondary-color), #2980b9);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.2);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-success {
            background: var(--success-color);
        }

        .btn-success:hover {
            background: #218838;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .results-table th {
            background: var(--primary-color);
            color: white;
            padding: 12px;
            text-align: center;
            font-weight: 500;
            position: sticky;
            top: 0;
        }

        .results-table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            text-align: center;
        }

        .results-table tr:hover {
            background: #f8f9fa;
        }

        .results-table .rank-1 {
            background: #fff9e6;
            font-weight: bold;
        }

        .position-badge {
            display: inline-block;
            width: 32px;
            height: 32px;
            line-height: 32px;
            border-radius: 50%;
            text-align: center;
            font-weight: bold;
            font-size: 0.9rem;
        }

        .position-1 {
            background: #ffd700;
            color: #000;
        }

        .position-2 {
            background: #c0c0c0;
            color: #000;
        }

        .position-3 {
            background: #cd7f32;
            color: #fff;
        }

        .info-box {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }

        .info-box h4 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .info-box ul {
            margin-left: 20px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
            margin-top: 20px;
        }

        .back-link:hover {
            background: rgba(52, 152, 219, 0.1);
            transform: translateX(-5px);
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
                overflow: hidden;
            }

            .sidebar:hover {
                width: 260px;
            }

            .logo-text,
            .nav-links span {
                display: none;
            }

            .sidebar:hover .logo-text,
            .sidebar:hover .nav-links span {
                display: block;
            }

            .main-content {
                margin-left: 70px;
            }

            .sidebar:hover~.main-content {
                margin-left: 260px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .results-table {
                font-size: 0.85rem;
            }
        }

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

        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
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
        <div class="sidebar-content">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="logo-text">
                    <h3><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School Management System'; ?></h3>
                    <p>Report Card System</p>
                </div>
            </div>

            <ul class="nav-links">
                <li><a href="report_card_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="report_card_settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a href="enter_scores.php"><i class="fas fa-edit"></i> Enter Scores</a></li>
                <li><a href="enter_comments.php"><i class="fas fa-comment"></i> Comments</a></li>
                <li><a href="calculate_positions.php" class="active"><i class="fas fa-chart-bar"></i> Calculate Positions</a></li>
                <li><a href="report_cards.php"><i class="fas fa-file-alt"></i> Generate Reports</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-chart-bar"></i> Calculate Positions</h1>
                <p>Calculate class positions and subject rankings based on student scores</p>
            </div>
        </div>

        <div class="container">
            <?php if (isset($message)): ?>
                <div class="alert alert-<?= $message_type ?>">
                    <i class="fas fa-<?= $message_type === 'error' ? 'exclamation-triangle' : ($message_type === 'warning' ? 'exclamation-circle' : 'check-circle') ?>"></i>
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <!-- Position Calculation Form -->
            <div class="form-section">
                <h2><i class="fas fa-calculator"></i> Calculate Class Positions</h2>
                <form method="POST" id="positionForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="class"><i class="fas fa-chalkboard"></i> Select Class:</label>
                            <select name="class" id="class" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= $class['class'] ?>" <?= $selected_class == $class['class'] ? 'selected' : '' ?>>
                                        <?= $class['class'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="session"><i class="fas fa-calendar"></i> Session:</label>
                            <input type="text" name="session" id="session" value="<?= htmlspecialchars($selected_session) ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="term"><i class="fas fa-clock"></i> Term:</label>
                            <select name="term" id="term" required>
                                <option value="First" <?= $selected_term == 'First' ? 'selected' : '' ?>>First Term</option>
                                <option value="Second" <?= $selected_term == 'Second' ? 'selected' : '' ?>>Second Term</option>
                                <option value="Third" <?= $selected_term == 'Third' ? 'selected' : '' ?>>Third Term</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <button type="submit" name="calculate_positions" class="btn btn-success">
                            <i class="fas fa-chart-line"></i> Calculate Positions
                        </button>
                        <button type="button" class="btn" onclick="window.location.href='calculate_positions.php'">
                            <i class="fas fa-sync-alt"></i> Reset
                        </button>
                    </div>
                </form>
            </div>

            <!-- Information Box -->
            <div class="info-box">
                <h4><i class="fas fa-info-circle"></i> About Position Calculation</h4>
                <ul>
                    <li>Class positions are calculated based on the overall average score across all subjects</li>
                    <li>Subject positions are calculated for each subject individually</li>
                    <li>Students with identical scores receive the same position (tie handling)</li>
                    <li>Only students with at least one subject score are included in rankings</li>
                    <li>Positions are saved to the database for use in report cards</li>
                </ul>
            </div>

            <?php if (!empty($existing_positions)): ?>
                <!-- Display Existing Positions -->
                <div class="form-section">
                    <h2><i class="fas fa-trophy"></i> Current Class Positions - <?= htmlspecialchars($selected_class) ?></h2>
                    <p style="margin-bottom: 20px; color: #666;">
                        Session: <?= htmlspecialchars($selected_session) ?> | Term: <?= htmlspecialchars($selected_term) ?>
                    </p>

                    <div style="overflow-x: auto;">
                        <table class="results-table">
                            <thead>
                                <tr>
                                    <th>Position</th>
                                    <th>Admission No.</th>
                                    <th>Student Name</th>
                                    <th>Average (%)</th>
                                    <th>Out of Students</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($existing_positions as $pos): ?>
                                    <?php
                                    $position_class = '';
                                    $badge_class = '';
                                    if ($pos['class_position'] == 1) {
                                        $position_class = 'rank-1';
                                        $badge_class = 'position-1';
                                    } elseif ($pos['class_position'] == 2) {
                                        $badge_class = 'position-2';
                                    } elseif ($pos['class_position'] == 3) {
                                        $badge_class = 'position-3';
                                    }
                                    ?>
                                    <tr class="<?= $position_class ?>">
                                        <td>
                                            <span class="position-badge <?= $badge_class ?>">
                                                <?= $pos['class_position'] ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($pos['admission_number']) ?></td>
                                        <td style="text-align: left;"><?= htmlspecialchars($pos['full_name']) ?></td>
                                        <td><strong><?= number_format($pos['average'], 1) ?>%</strong></td>
                                        <td><?= $pos['total_students'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($results) && !empty($results['students'])): ?>
                <!-- Display Calculation Results -->
                <div class="form-section">
                    <h2><i class="fas fa-chart-line"></i> Position Calculation Results</h2>
                    <p style="margin-bottom: 20px; color: var(--success-color);">
                        <i class="fas fa-check-circle"></i> Successfully calculated positions for <?= count($results['students']) ?> students
                    </p>

                    <div style="overflow-x: auto;">
                        <table class="results-table">
                            <thead>
                                <tr>
                                    <th>Position</th>
                                    <th>Student Name</th>
                                    <th>Average (%)</th>
                                    <th>Subjects</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $prev_average = null;
                                $display_pos = 1;
                                $actual_pos = 1;
                                foreach ($results['students'] as $student_id => $data):
                                    $position = $results['positions'][$student_id];
                                    if ($prev_average !== null && $data['average'] < $prev_average) {
                                        $actual_pos++;
                                        $display_pos = $actual_pos;
                                    } else {
                                        $display_pos = $actual_pos;
                                    }
                                    $prev_average = $data['average'];

                                    $position_class = '';
                                    if ($display_pos == 1) $position_class = 'rank-1';
                                ?>
                                    <tr class="<?= $position_class ?>">
                                        <td>
                                            <strong><?= ordinal($display_pos) ?></strong>
                                        </td>
                                        <td style="text-align: left;"><?= htmlspecialchars($data['student_name']) ?></td>
                                        <td><strong><?= number_format($data['average'], 1) ?>%</strong></td>
                                        <td><?= $data['subject_count'] ?></td>
                                        <td>
                                            <span style="color: var(--success-color);">
                                                <i class="fas fa-check-circle"></i> Saved
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px;">
                        <h4><i class="fas fa-chart-simple"></i> Summary</h4>
                        <ul style="margin: 10px 0 0 20px;">
                            <li>Total Students: <?= $results['total_students'] ?></li>
                            <li>Total Subjects: <?= count($results['subjects']) ?></li>
                            <li>Highest Average: <?= number_format(($results['students'][array_key_first($results['students'])]['average'] ?? 0), 1) ?>%</li>
                            <li>Lowest Average: <?= number_format(($results['students'][array_key_last($results['students'])]['average'] ?? 0), 1) ?>%</li>
                        </ul>
                    </div>
                </div>

                <!-- Subject Positions Display -->
                <?php if (!empty($results['subjects'])): ?>
                    <div class="form-section">
                        <h2><i class="fas fa-book-open"></i> Subject Positions</h2>
                        <div style="overflow-x: auto;">
                            <table class="results-table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>1st Position</th>
                                        <th>2nd Position</th>
                                        <th>3rd Position</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results['subjects'] as $subject): ?>
                                        <?php
                                        $subject_id = $subject['subject_id'];
                                        $subject_name = $subject['subject_name'];

                                        // Get top 3 students for this subject
                                        $top_students = [];
                                        if (isset($results['subject_positions'][$subject_id])) {
                                            $positions = $results['subject_positions'][$subject_id];
                                            $students_by_position = [];
                                            foreach ($positions as $sid => $pos) {
                                                if (!isset($students_by_position[$pos])) {
                                                    $students_by_position[$pos] = [];
                                                }
                                                $students_by_position[$pos][] = $results['students'][$sid]['student_name'];
                                            }

                                            for ($i = 1; $i <= 3; $i++) {
                                                if (isset($students_by_position[$i])) {
                                                    $top_students[$i] = implode(', ', $students_by_position[$i]);
                                                } else {
                                                    $top_students[$i] = '-';
                                                }
                                            }
                                        }
                                        ?>
                                        <tr>
                                            <td style="text-align: left; font-weight: bold;"><?= htmlspecialchars($subject_name) ?></td>
                                            <td><?= $top_students[1] ?? '-' ?></td>
                                            <td><?= $top_students[2] ?? '-' ?></td>
                                            <td><?= $top_students[3] ?? '-' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <a href="report_card_dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Report Card Dashboard
            </a>
        </div>
    </div>

    <script>
        // Helper function for ordinal numbers
        function ordinal(number) {
            const ends = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];
            if (((number % 100) >= 11) && ((number % 100) <= 13))
                return number + 'th';
            else
                return number + ends[number % 10];
        }

        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

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

        // Form validation
        document.getElementById('positionForm').addEventListener('submit', function(e) {
            const classSelect = document.getElementById('class');
            if (!classSelect.value) {
                e.preventDefault();
                alert('Please select a class');
                return false;
            }
        });
    </script>
</body>

</html>

<?php
// Helper function for ordinal numbers in PHP
function ordinal($number)
{
    if (!is_numeric($number)) return $number;

    $ends = array('th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th');
    if ((($number % 100) >= 11) && (($number % 100) <= 13))
        return $number . 'th';
    else
        return $number . $ends[$number % 10];
}
?>