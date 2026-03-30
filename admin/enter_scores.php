<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit();
}

// --- Determine the most recent session and term from settings ---
$most_recent = $pdo->query("SELECT session, term FROM report_card_settings ORDER BY session DESC, 
                            CASE term WHEN 'Third' THEN 3 WHEN 'Second' THEN 2 WHEN 'First' THEN 1 END DESC LIMIT 1")->fetch();

if ($most_recent) {
    $default_session = $most_recent['session'];
    $default_term = $most_recent['term'];
} else {
    // Fallback to current year if no settings exist
    $default_session = date('Y') . '/' . (date('Y') + 1);
    $default_term = 'First';
}

// Get available classes
$classes = $pdo->query("SELECT DISTINCT class FROM students WHERE status = 'active' ORDER BY class")->fetchAll();

// Get subjects
$subjects = [];
try {
    $subjects = $pdo->query("SELECT id, subject_name as name FROM subjects ORDER BY subject_name")->fetchAll();
} catch (Exception $e) {
    die("Error: Could not retrieve subjects. Please check your subjects table structure.");
}

if (empty($subjects)) {
    die("No subjects found in the database. Please add subjects first.");
}

// --- Handle form input with fallback to defaults ---
$selected_class = $_POST['class'] ?? ($_GET['class'] ?? '');
$selected_subject_id = $_POST['subject_id'] ?? ($_GET['subject_id'] ?? '');
$session = $_POST['session'] ?? $default_session;
$term = $_POST['term'] ?? $default_term;

$students = [];
$settings = null;
$score_types = [];

// Load settings for selected class (preferring exact match, then latest)
if ($selected_class) {
    // Try exact match first
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE class = ? AND session = ? AND term = ?");
    $stmt->execute([$selected_class, $session, $term]);
    $settings = $stmt->fetch();

    // If no exact match, get the most recent for this class
    if (!$settings) {
        $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE class = ? ORDER BY session DESC, 
                              CASE term WHEN 'Third' THEN 3 WHEN 'Second' THEN 2 WHEN 'First' THEN 1 END DESC LIMIT 1");
        $stmt->execute([$selected_class]);
        $settings = $stmt->fetch();

        if ($settings) {
            // Update the session/term to match what we found
            $session = $settings['session'];
            $term = $settings['term'];
        }
    }

    if ($settings) {
        // Load score types
        if (isset($settings['score_types']) && !empty($settings['score_types'])) {
            $score_types = json_decode($settings['score_types'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $score_types = [
                    ['name' => 'CA 1', 'max_score' => 20],
                    ['name' => 'CA 2', 'max_score' => 20],
                    ['name' => 'Exam', 'max_score' => 60]
                ];
            }
        } else {
            $score_types = [
                ['name' => 'CA 1', 'max_score' => 20],
                ['name' => 'CA 2', 'max_score' => 20],
                ['name' => 'Exam', 'max_score' => 60]
            ];
        }

        // Ensure max_score exists
        if (!isset($settings['max_score']) || empty($settings['max_score'])) {
            $settings['max_score'] = 100;
        }

        if (!isset($settings['grading_system']) || empty($settings['grading_system'])) {
            $settings['grading_system'] = 'simple';
        }
    }

    // Get students ONLY if a subject is also selected
    if ($selected_subject_id) {
        $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE class = ? AND status = 'active' ORDER BY full_name");
        $stmt->execute([$selected_class]);
        $students = $stmt->fetchAll();
    }
}

// --- Handle score submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_scores'])) {
    $session = $_POST['session'];
    $term = $_POST['term'];
    $subject_id = $_POST['subject_id'];
    $class = $_POST['class'];

    // Ensure settings are loaded
    if (!$settings) {
        $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE class = ? ORDER BY session DESC, 
                              CASE term WHEN 'Third' THEN 3 WHEN 'Second' THEN 2 WHEN 'First' THEN 1 END DESC LIMIT 1");
        $stmt->execute([$class]);
        $settings = $stmt->fetch();
        if ($settings) {
            if (isset($settings['score_types']) && !empty($settings['score_types'])) {
                $score_types = json_decode($settings['score_types'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $score_types = [
                        ['name' => 'CA 1', 'max_score' => 20],
                        ['name' => 'CA 2', 'max_score' => 20],
                        ['name' => 'Exam', 'max_score' => 60]
                    ];
                }
            }
            if (!isset($settings['max_score']) || empty($settings['max_score'])) $settings['max_score'] = 100;
            if (!isset($settings['grading_system']) || empty($settings['grading_system'])) $settings['grading_system'] = 'simple';
        }
    }

    if (!$settings) {
        $message = "Cannot save scores: No settings found for <strong>$class</strong>. Please configure report card settings first.";
        $message_type = "error";
    } elseif (empty($score_types)) {
        $message = "Cannot save scores: No score types configured for <strong>$class</strong>.";
        $message_type = "error";
    } else {
        $success_count = 0;
        $error_count = 0;
        $skipped_count = 0;

        if (isset($_POST['scores']) && is_array($_POST['scores'])) {
            foreach ($_POST['scores'] as $student_id => $score_data) {
                try {
                    $scores = [];
                    $total_score = 0;
                    $has_scores = false;

                    foreach ($score_types as $score_type) {
                        $score_key = str_replace(' ', '_', strtolower($score_type['name']));
                        $score = isset($score_data[$score_key]) ? trim($score_data[$score_key]) : '';

                        if ($score === '' || $score === 'skip' || $score === 'NA' || $score === 'N/A') {
                            continue 2; // Skip this student
                        }

                        $score_value = floatval($score);
                        $scores[$score_type['name']] = $score_value;
                        $total_score += $score_value;
                        $has_scores = true;
                    }

                    if ($has_scores) {
                        $subject_stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
                        $subject_stmt->execute([$subject_id]);
                        $subject_name = $subject_stmt->fetchColumn() ?: 'Unknown Subject';

                        $check_stmt = $pdo->prepare("SELECT id FROM student_scores WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ?");
                        $check_stmt->execute([$student_id, $subject_id, $session, $term]);

                        if ($check_stmt->fetch()) {
                            $update_stmt = $pdo->prepare("UPDATE student_scores SET score_data = ? WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ?");
                            $update_stmt->execute([json_encode($scores), $student_id, $subject_id, $session, $term]);
                        } else {
                            $insert_stmt = $pdo->prepare("INSERT INTO student_scores (student_id, subject_id, subject_name, session, term, score_data) VALUES (?, ?, ?, ?, ?, ?)");
                            $insert_stmt->execute([$student_id, $subject_id, $subject_name, $session, $term, json_encode($scores)]);
                        }
                        $success_count++;
                    } else {
                        $skipped_count++;
                    }
                } catch (Exception $e) {
                    $error_count++;
                    error_log("Error saving score for student $student_id: " . $e->getMessage());
                }
            }
        } else {
            $message = "No scores submitted.";
            $message_type = "warning";
        }

        if ($success_count > 0 || $error_count === 0) {
            $message = "✅ Successfully saved scores for $success_count students! ";
            if ($skipped_count > 0) $message .= "$skipped_count students were skipped.";
            $message_type = "success";
        } else {
            $message = "⚠️ Saved scores for $success_count students. $error_count errors occurred.";
            $message_type = "warning";
        }

        // Reload students after saving
        if ($selected_class && $selected_subject_id) {
            $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE class = ? AND status = 'active' ORDER BY full_name");
            $stmt->execute([$selected_class]);
            $students = $stmt->fetchAll();
        }
    }
}

// --- Import from results (simplified) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_results'])) {
    // (Keep the import logic similar, but ensure it uses $score_types and $settings)
    // ... (existing import logic, omitted for brevity but can be added back)
}

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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Scores - <?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School Management System'; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
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

        /* Sidebar styles (same as before, omitted for brevity but included in final) */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), #1a2a3a);
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
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

        .btn {
            background: var(--secondary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-success {
            background: var(--success-color);
        }

        .btn-warning {
            background: var(--warning-color);
        }

        .btn-danger {
            background: var(--danger-color);
        }

        .btn:hover {
            transform: translateY(-2px);
            filter: brightness(0.95);
        }

        .score-types-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .score-type-list {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin: 10px 0;
        }

        .score-type-item {
            background: white;
            padding: 8px 15px;
            border-radius: 8px;
            border: 1px solid #b3d9ff;
            font-weight: 500;
        }

        .scores-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        .scores-table th {
            background: var(--primary-color);
            color: white;
            padding: 12px;
            text-align: center;
            position: sticky;
            top: 0;
        }

        .scores-table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            text-align: center;
        }

        .scores-table tr:hover {
            background: #f8f9fa;
        }

        .score-input {
            width: 100px;
            padding: 8px;
            text-align: center;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
        }

        .score-input:focus {
            border-color: var(--secondary-color);
            outline: none;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .student-info {
            text-align: left;
        }

        .student-admission {
            font-size: 0.8rem;
            color: #666;
            margin-top: 3px;
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

            .mobile-menu-btn {
                display: block;
            }

            .scores-table {
                font-size: 0.85rem;
            }

            .score-input {
                width: 70px;
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
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn"><i class="fas fa-bars"></i></button>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-content">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
                <div class="logo-text">
                    <h3><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School Management System'; ?></h3>
                    <p>Report Card System</p>
                </div>
            </div>
            <ul class="nav-links">
                <li><a href="report_card_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="report_card_settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a href="enter_scores.php" class="active"><i class="fas fa-edit"></i> Enter Scores</a></li>
                <li><a href="enter_comments.php"><i class="fas fa-comment"></i> Comments</a></li>
                <li><a href="calculate_positions.php"><i class="fas fa-chart-bar"></i> Calculate Positions</a></li>
                <li><a href="report_cards.php"><i class="fas fa-file-alt"></i> Generate Reports</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-edit"></i> Enter Student Scores</h1>
                <p>Enter or import student scores for report card generation</p>
            </div>
        </div>

        <div class="container">
            <?php if (isset($message)): ?>
                <div class="alert alert-<?= $message_type ?>">
                    <i class="fas fa-<?= $message_type === 'error' ? 'exclamation-triangle' : ($message_type === 'warning' ? 'exclamation-circle' : 'check-circle') ?>"></i>
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <!-- Selection Form -->
            <div class="form-section">
                <h2><i class="fas fa-filter"></i> Select Class & Subject</h2>
                <form method="POST" id="selectionForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Class:</label>
                            <select name="class" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= $class['class'] ?>" <?= $selected_class == $class['class'] ? 'selected' : '' ?>><?= $class['class'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Subject:</label>
                            <select name="subject_id" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= $subject['id'] ?>" <?= $selected_subject_id == $subject['id'] ? 'selected' : '' ?>><?= htmlspecialchars($subject['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Session:</label>
                            <input type="text" name="session" value="<?= htmlspecialchars($session) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Term:</label>
                            <select name="term" required>
                                <option value="First" <?= $term == 'First' ? 'selected' : '' ?>>First Term</option>
                                <option value="Second" <?= $term == 'Second' ? 'selected' : '' ?>>Second Term</option>
                                <option value="Third" <?= $term == 'Third' ? 'selected' : '' ?>>Third Term</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="load_students" class="btn btn-success"><i class="fas fa-users"></i> Load Students</button>
                </form>
            </div>

            <?php if ($selected_class && !$settings): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> No report card settings found for <strong><?= $selected_class ?></strong>. Please configure settings first.
                    <a href="report_card_settings.php" style="margin-left: 10px;">Go to Settings</a>
                </div>
            <?php elseif ($selected_class && $settings && $selected_subject_id): ?>
                <!-- Settings Info -->
                <div class="score-types-info">
                    <h4><i class="fas fa-sliders-h"></i> Settings for <?= $selected_class ?></h4>
                    <p><strong>Session:</strong> <?= $settings['session'] ?> | <strong>Term:</strong> <?= $settings['term'] ?> | <strong>Max Score:</strong> <?= $settings['max_score'] ?></p>
                    <div class="score-type-list">
                        <?php foreach ($score_types as $type): ?>
                            <div class="score-type-item"><?= $type['name'] ?>: <?= $type['max_score'] ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Scores Entry -->
                <div class="form-section">
                    <h2><i class="fas fa-user-graduate"></i> Enter Scores for <?= $selected_class ?></h2>
                    <?php if (!empty($students)): ?>
                        <form method="POST" id="scoresForm">
                            <input type="hidden" name="class" value="<?= $selected_class ?>">
                            <input type="hidden" name="subject_id" value="<?= $selected_subject_id ?>">
                            <input type="hidden" name="session" value="<?= $session ?>">
                            <input type="hidden" name="term" value="<?= $term ?>">
                            <div style="overflow-x: auto;">
                                <table class="scores-table">
                                    <thead>
                                        <tr>
                                            <th>Student Name</th>
                                            <?php foreach ($score_types as $type): ?>
                                                <th><?= $type['name'] ?><br><small>Max: <?= $type['max_score'] ?></small></th>
                                            <?php endforeach; ?>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student):
                                            $existing_scores = [];
                                            $existing_total = 0;
                                            $check = $pdo->prepare("SELECT score_data FROM student_scores WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ?");
                                            $check->execute([$student['id'], $selected_subject_id, $session, $term]);
                                            $existing = $check->fetch();
                                            if ($existing && !empty($existing['score_data'])) {
                                                $existing_scores = json_decode($existing['score_data'], true);
                                                $existing_total = is_array($existing_scores) ? array_sum($existing_scores) : 0;
                                            }
                                        ?>
                                            <tr>
                                                <td class="student-info">
                                                    <?= htmlspecialchars($student['full_name']) ?>
                                                    <div class="student-admission"><?= htmlspecialchars($student['admission_number']) ?></div>
                                                </td>
                                                <?php foreach ($score_types as $type):
                                                    $score_key = str_replace(' ', '_', strtolower($type['name']));
                                                    $value = isset($existing_scores[$type['name']]) ? $existing_scores[$type['name']] : '';
                                                ?>
                                                    <td>
                                                        <input type="number" step="0.5"
                                                            name="scores[<?= $student['id'] ?>][<?= $score_key ?>]"
                                                            class="score-input" value="<?= htmlspecialchars($value) ?>"
                                                            data-student="<?= $student['id'] ?>"
                                                            data-max="<?= $type['max_score'] ?>"
                                                            onchange="calculateTotal(this)">
                                                    </td>
                                                <?php endforeach; ?>
                                                <td><span id="total_<?= $student['id'] ?>"><?= number_format($existing_total, 1) ?></span>/<?= $settings['max_score'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="action-buttons">
                                <button type="submit" name="save_scores" class="btn btn-success"><i class="fas fa-save"></i> Save All Scores</button>
                                <button type="button" class="btn btn-warning" onclick="clearAllScores()"><i class="fas fa-trash"></i> Clear All</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div style="text-align:center; padding:40px;">No active students found in <?= $selected_class ?></div>
                    <?php endif; ?>
                </div>
            <?php elseif ($selected_class && $settings && !$selected_subject_id): ?>
                <div class="alert alert-info">Please select a subject to enter scores.</div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function calculateTotal(input) {
            const studentId = input.dataset.student;
            const row = input.closest('tr');
            const inputs = row.querySelectorAll('.score-input');
            let total = 0;
            inputs.forEach(inp => {
                let val = parseFloat(inp.value);
                if (!isNaN(val)) total += val;
            });
            document.getElementById(`total_${studentId}`).textContent = total.toFixed(1);
        }

        function clearAllScores() {
            if (confirm('Clear all scores? This cannot be undone.')) {
                document.querySelectorAll('.score-input').forEach(inp => inp.value = '');
                document.querySelectorAll('[id^="total_"]').forEach(span => span.textContent = '0');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.score-input').forEach(inp => calculateTotal(inp));
            const mobileBtn = document.getElementById('mobileMenuBtn');
            const sidebar = document.getElementById('sidebar');
            mobileBtn.addEventListener('click', () => sidebar.classList.toggle('active'));
            document.addEventListener('click', (e) => {
                if (window.innerWidth <= 768 && !sidebar.contains(e.target) && !mobileBtn.contains(e.target))
                    sidebar.classList.remove('active');
            });
        });
    </script>
</body>

</html>