<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit();
}

// Get current session and term
$current_session = date('Y') . '/' . (date('Y') + 1);
$current_term = 'First';

// Get available classes
$classes = $pdo->query("SELECT DISTINCT class FROM students WHERE status = 'active' ORDER BY class")->fetchAll();

// Get subjects
$subjects = [];
try {
    $subjects = $pdo->query("SELECT id, subject_name as name FROM subjects ORDER BY subject_name")->fetchAll();
} catch (Exception $e) {
    die("Error: Could not retrieve subjects. Please check your subjects table structure.");
}

// If still no subjects, show error
if (empty($subjects)) {
    die("No subjects found in the database. Please add subjects first.");
}

$selected_class = $_POST['class'] ?? ($_GET['class'] ?? '');
$selected_subject_id = $_POST['subject_id'] ?? ($_GET['subject_id'] ?? '');
$session = $_POST['session'] ?? $current_session;
$term = $_POST['term'] ?? $current_term;

$students = [];
$settings = null;
$score_types = [];

// Load settings for selected class
if ($selected_class) {
    echo "<!-- Debug: Loading settings for class: $selected_class, session: $session, term: $term -->\n";

    // Try to get settings with the provided session and term
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE class = ? AND (session = ? OR session LIKE ?) AND term = ?");
    $stmt->execute([$selected_class, $session, "%{$session}%", $term]);
    $settings = $stmt->fetch();

    // If not found, try any settings for this class
    if (!$settings) {
        echo "<!-- Debug: No exact match, trying any settings for class -->\n";
        $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE class = ? ORDER BY session DESC, term DESC LIMIT 1");
        $stmt->execute([$selected_class]);
        $settings = $stmt->fetch();
    }

    if ($settings) {
        echo "<!-- Debug: Found settings! Session: {$settings['session']}, Term: {$settings['term']} -->\n";

        // Load score types
        if (isset($settings['score_types']) && !empty($settings['score_types'])) {
            $score_types = json_decode($settings['score_types'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "<!-- Debug: JSON decode error, using defaults -->\n";
                $score_types = [
                    ['name' => 'CA 1', 'max_score' => 20],
                    ['name' => 'CA 2', 'max_score' => 20],
                    ['name' => 'Exam', 'max_score' => 60]
                ];
            }
        } else {
            echo "<!-- Debug: No score types in settings, using defaults -->\n";
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

        // Ensure grading_system exists
        if (!isset($settings['grading_system']) || empty($settings['grading_system'])) {
            $settings['grading_system'] = 'simple';
        }
    } else {
        echo "<!-- Debug: No settings found for class $selected_class -->\n";
    }

    // Get students for the selected class ONLY if a subject is also selected
    if ($selected_subject_id) {
        $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE class = ? AND status = 'active' ORDER BY full_name");
        $stmt->execute([$selected_class]);
        $students = $stmt->fetchAll();
        echo "<!-- Debug: Loaded " . count($students) . " students -->\n";
    }
}

// Handle score submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_scores'])) {
    echo "<!-- Debug: Processing save_scores request -->\n";
    $session = $_POST['session'];
    $term = $_POST['term'];
    $subject_id = $_POST['subject_id'];
    $class = $_POST['class'];

    if (!$settings) {
        // Try to get settings one more time
        echo "<!-- Debug: Settings not loaded, trying to fetch again -->\n";
        $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE class = ? ORDER BY session DESC, term DESC LIMIT 1");
        $stmt->execute([$class]);
        $settings = $stmt->fetch();

        if ($settings) {
            // Reload score types from settings
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

            if (!isset($settings['max_score']) || empty($settings['max_score'])) {
                $settings['max_score'] = 100;
            }

            if (!isset($settings['grading_system']) || empty($settings['grading_system'])) {
                $settings['grading_system'] = 'simple';
            }
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

        // Check if scores were submitted
        if (isset($_POST['scores']) && is_array($_POST['scores'])) {
            echo "<!-- Debug: Processing " . count($_POST['scores']) . " student scores -->\n";
            foreach ($_POST['scores'] as $student_id => $score_data) {
                try {
                    $scores = [];
                    $total_score = 0;
                    $has_scores = false;

                    foreach ($score_types as $score_type) {
                        $score_key = str_replace(' ', '_', strtolower($score_type['name']));
                        $score = isset($score_data[$score_key]) ? trim($score_data[$score_key]) : '';

                        // Check if score is empty or marked as not taking subject
                        if ($score === '' || $score === 'skip' || $score === 'NA' || $score === 'N/A') {
                            // Skip this student - no scores entered
                            continue 2; // Skip to next student
                        }

                        $score_value = floatval($score);
                        $scores[$score_type['name']] = $score_value;
                        $total_score += $score_value;
                        $has_scores = true;
                    }

                    // Only save if student has scores
                    if ($has_scores) {
                        echo "<!-- Debug: Saving scores for student $student_id -->\n";

                        // Get subject name
                        $subject_stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
                        $subject_stmt->execute([$subject_id]);
                        $subject_result = $subject_stmt->fetch();
                        $subject_name = $subject_result ? $subject_result['subject_name'] : 'Unknown Subject';

                        // Check if score already exists
                        $check_stmt = $pdo->prepare("SELECT id FROM student_scores WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ?");
                        $check_stmt->execute([$student_id, $subject_id, $session, $term]);

                        if ($check_stmt->fetch()) {
                            // Update existing
                            $update_stmt = $pdo->prepare("UPDATE student_scores SET score_data = ? WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ?");
                            $update_stmt->execute([json_encode($scores), $student_id, $subject_id, $session, $term]);
                            echo "<!-- Debug: Updated existing scores for student $student_id -->\n";
                        } else {
                            // Insert new
                            $insert_stmt = $pdo->prepare("INSERT INTO student_scores (student_id, subject_id, subject_name, session, term, score_data) VALUES (?, ?, ?, ?, ?, ?)");
                            $insert_stmt->execute([$student_id, $subject_id, $subject_name, $session, $term, json_encode($scores)]);
                            echo "<!-- Debug: Inserted new scores for student $student_id -->\n";
                        }

                        $success_count++;
                    } else {
                        $skipped_count++;
                    }
                } catch (Exception $e) {
                    $error_count++;
                    echo "<!-- Debug: Error for student $student_id: " . $e->getMessage() . " -->\n";
                    error_log("Error saving score for student $student_id: " . $e->getMessage());
                }
            }
        } else {
            $message = "No scores submitted.";
            $message_type = "warning";
        }

        if ($success_count > 0 || $error_count === 0) {
            $message = "✅ Successfully saved scores for $success_count students! ";
            if ($skipped_count > 0) {
                $message .= "$skipped_count students were skipped (no scores entered).";
            }
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

// Handle import from results table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_results'])) {
    echo "<!-- Debug: Processing import_results request -->\n";
    $session = $_POST['session'];
    $term = $_POST['term'];
    $subject_id = $_POST['subject_id'];
    $class = $_POST['class'];
    $exam_type = $_POST['exam_type'] ?? 'both';

    if (!$settings) {
        // Try to get settings
        $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE class = ? ORDER BY session DESC, term DESC LIMIT 1");
        $stmt->execute([$class]);
        $settings = $stmt->fetch();
    }

    if (!$settings) {
        $message = "Cannot import results: No settings found for <strong>$class</strong>. Please configure report card settings first.";
        $message_type = "error";
    } elseif (empty($selected_class) || empty($subject_id)) {
        $message = "Please select both class and subject to import results.";
        $message_type = "warning";
    } else {
        $success_count = 0;
        $error_count = 0;

        // Get subject name for matching
        $subject_stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
        $subject_stmt->execute([$subject_id]);
        $subject = $subject_stmt->fetch();
        $subject_name = $subject['subject_name'];

        // Get students for the class
        $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE class = ? AND status = 'active' ORDER BY full_name");
        $stmt->execute([$class]);
        $import_students = $stmt->fetchAll();

        foreach ($import_students as $student) {
            try {
                // Build query based on exam type
                $query = "SELECT objective_score, theory_score, total_score 
                         FROM results 
                         WHERE student_id = ? 
                         AND (exam_id LIKE ? OR exam_id LIKE ?)";

                $stmt = $pdo->prepare($query);

                // Search for exams that match the subject and term/session pattern
                $term_pattern = "%{$term}%";
                $session_pattern = "%{$session}%";

                $stmt->execute([$student['id'], "%{$subject_name}%", "%{$term_pattern}%"]);
                $result = $stmt->fetch();

                if ($result) {
                    $scores = [];
                    $total_score = 0;

                    // Map results to score types
                    foreach ($score_types as $score_type) {
                        $score_type_name = strtolower($score_type['name']);

                        if (strpos($score_type_name, 'objective') !== false || strpos($score_type_name, 'obj') !== false) {
                            $score_value = $result['objective_score'] ?? 0;
                        } elseif (strpos($score_type_name, 'theory') !== false || strpos($score_type_name, 'essay') !== false) {
                            $score_value = $result['theory_score'] ?? 0;
                        } else {
                            // For other score types, distribute proportionally
                            $score_value = 0;
                            if ($result['total_score'] > 0) {
                                $score_value = ($score_type['max_score'] / $settings['max_score']) * $result['total_score'];
                            }
                        }

                        // Ensure score doesn't exceed max for that type
                        $score_value = min($score_value, $score_type['max_score']);
                        $scores[$score_type['name']] = $score_value;
                        $total_score += $score_value;
                    }

                    // Get subject name
                    $subject_stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
                    $subject_stmt->execute([$subject_id]);
                    $subject_result = $subject_stmt->fetch();
                    $subject_name = $subject_result ? $subject_result['subject_name'] : 'Unknown Subject';

                    // Check if score already exists
                    $check_stmt = $pdo->prepare("SELECT id FROM student_scores WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ?");
                    $check_stmt->execute([$student['id'], $subject_id, $session, $term]);

                    if ($check_stmt->fetch()) {
                        // Update existing
                        $update_stmt = $pdo->prepare("UPDATE student_scores SET score_data = ? WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ?");
                        $update_stmt->execute([json_encode($scores), $student['id'], $subject_id, $session, $term]);
                    } else {
                        // Insert new
                        $insert_stmt = $pdo->prepare("INSERT INTO student_scores (student_id, subject_id, subject_name, session, term, score_data) VALUES (?, ?, ?, ?, ?, ?)");
                        $insert_stmt->execute([$student['id'], $subject_id, $subject_name, $session, $term, json_encode($scores)]);
                    }

                    $success_count++;
                } else {
                    $error_count++; // No result found for this student
                }
            } catch (Exception $e) {
                $error_count++;
                error_log("Error importing result for student {$student['id']}: " . $e->getMessage());
            }
        }

        if ($success_count > 0) {
            $message = "✅ Successfully imported results for $success_count students!";
            $message_type = "success";

            // Reload students with their imported scores
            $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE class = ? AND status = 'active' ORDER BY full_name");
            $stmt->execute([$class]);
            $students = $stmt->fetchAll();

            foreach ($students as &$student) {
                $score_stmt = $pdo->prepare("SELECT score_data FROM student_scores WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ?");
                $score_stmt->execute([$student['id'], $subject_id, $session, $term]);
                $student_score = $score_stmt->fetch();

                if ($student_score) {
                    $student['imported_scores'] = json_decode($student_score['score_data'], true);
                    // Calculate total from imported scores
                    if (!empty($student['imported_scores'])) {
                        $student['imported_total'] = array_sum($student['imported_scores']);
                    }
                }
            }
            unset($student);
        } else {
            $message = "⚠️ No results found to import for the selected criteria.";
            $message_type = "warning";
        }
    }
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
            display: flex;
            align-items: center;
            gap: 10px;
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

        .form-section h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 1.1rem;
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

        .score-types-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .score-types-info h4 {
            color: var(--primary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .score-type-list {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 10px;
        }

        .score-type-item {
            background: white;
            padding: 8px 15px;
            border-radius: 8px;
            border: 1px solid #b3d9ff;
            font-weight: 500;
        }

        .import-section {
            background: #f8f9fa;
            border-left: 4px solid var(--success-color);
            padding: 20px;
            margin: 20px 0;
            border-radius: 10px;
        }

        .import-section h3 {
            color: var(--success-color);
            margin-bottom: 10px;
        }

        .import-options {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .import-options select {
            width: auto;
            min-width: 180px;
        }

        .scores-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .scores-table th {
            background: var(--primary-color);
            color: white;
            padding: 15px;
            text-align: center;
            font-weight: 500;
            position: sticky;
            top: 0;
        }

        .scores-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            text-align: center;
        }

        .scores-table tr:hover {
            background: #f8f9fa;
        }

        .scores-table tr.student-taking-subject {
            background: #e8f5e9;
        }

        .scores-table tr.student-not-taking {
            background: #fff3e0;
        }

        .score-input-container {
            position: relative;
        }

        .score-input {
            width: 80px;
            padding: 8px 30px 8px 10px;
            text-align: center;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
        }

        .score-input:focus {
            border-color: var(--secondary-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
        }

        .skip-btn {
            position: absolute;
            right: 5px;
            top: 50%;
            transform: translateY(-50%);
            background: var(--danger-color);
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
            padding: 3px 6px;
            display: none;
        }

        .score-input:focus+.skip-btn {
            display: block;
        }

        .skip-btn:hover {
            background: #c0392b;
        }

        .bulk-actions {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            background: var(--secondary-color);
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

        .btn-warning {
            background: var(--warning-color);
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-danger {
            background: var(--danger-color);
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .student-info {
            text-align: left;
        }

        .student-admission {
            font-size: 0.85rem;
            color: #666;
            margin-top: 3px;
        }

        .has-scores-badge {
            background: var(--success-color);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            margin-left: 5px;
        }

        .no-settings-warning {
            background: #fff3cd;
            border-left: 4px solid var(--warning-color);
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }

        .no-settings-warning h3 {
            color: var(--warning-color);
            margin-bottom: 10px;
        }

        .no-settings-warning a {
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 500;
        }

        .no-settings-warning a:hover {
            text-decoration: underline;
        }

        .btn-info {
            background: #17a2b8;
        }

        .btn-info:hover {
            background: #138496;
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

            .scores-table {
                font-size: 0.9rem;
            }

            .score-input {
                width: 60px;
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
                <li><a href="enter_scores.php" class="active"><i class="fas fa-edit"></i> Enter Scores</a></li>
                <li><a href="enter_comments.php"><i class="fas fa-comment"></i> Comments</a></li>
                <li><a href="calculate_positions.php"><i class="fas fa-chart-bar"></i> Calculate Positions</a></li>
                <li><a href="report_cards.php"><i class="fas fa-file-alt"></i> Generate Reports</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
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

            <!-- Class Selection Form -->
            <div class="form-section">
                <h2><i class="fas fa-filter"></i> Select Class & Subject</h2>
                <form method="POST" id="selectionForm">
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
                            <label for="subject_id"><i class="fas fa-book"></i> Select Subject:</label>
                            <select name="subject_id" id="subject_id" required>
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= $subject['id'] ?>" <?= $selected_subject_id == $subject['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($subject['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="session"><i class="fas fa-calendar"></i> Session:</label>
                            <input type="text" name="session" id="session" value="<?= htmlspecialchars($session) ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="term"><i class="fas fa-clock"></i> Term:</label>
                            <select name="term" id="term" required>
                                <option value="First" <?= $term == 'First' ? 'selected' : '' ?>>First Term</option>
                                <option value="Second" <?= $term == 'Second' ? 'selected' : '' ?>>Second Term</option>
                                <option value="Third" <?= $term == 'Third' ? 'selected' : '' ?>>Third Term</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" name="load_students" class="btn btn-success">
                        <i class="fas fa-users"></i> Load Students
                    </button>
                </form>
            </div>

            <?php if ($selected_class && !$settings): ?>
                <!-- Warning when no settings exist for selected class -->
                <div class="no-settings-warning">
                    <h3><i class="fas fa-exclamation-triangle"></i> Settings Required</h3>
                    <p>Report card settings have not been configured for <strong><?= $selected_class ?></strong>. You need to set up the grading system, score types, and other settings before entering scores.</p>
                    <div style="margin-top: 15px;">
                        <a href="report_card_settings.php" class="btn btn-info">
                            <i class="fas fa-cog"></i> Go to Settings Page
                        </a>
                    </div>
                </div>
            <?php elseif ($selected_class && $settings && $selected_subject_id): ?>
                <!-- Display settings information -->
                <div class="score-types-info">
                    <h4><i class="fas fa-sliders-h"></i> Current Settings for <?= $selected_class ?></h4>
                    <p><strong>Session:</strong> <?= $settings['session'] ?> |
                        <strong>Term:</strong> <?= $settings['term'] ?> |
                        <strong>Grading System:</strong> <?= ucfirst($settings['grading_system']) ?> |
                        <strong>Max Score:</strong> <?= $settings['max_score'] ?>
                    </p>
                    <div class="score-type-list">
                        <?php foreach ($score_types as $type): ?>
                            <div class="score-type-item">
                                <?= $type['name'] ?>: <?= $type['max_score'] ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p style="color: #666; font-size: 0.9rem; margin-top: 10px;">
                        <i class="fas fa-info-circle"></i> Using settings from session: <?= $settings['session'] ?>, term: <?= $settings['term'] ?>
                    </p>
                </div>

                <!-- Import Section -->
                <div class="import-section">
                    <h3><i class="fas fa-download"></i> Import Results from Exams</h3>
                    <p>Import existing exam results from the database for quick score entry.</p>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="class" value="<?= $selected_class ?>">
                        <input type="hidden" name="subject_id" value="<?= $selected_subject_id ?>">
                        <input type="hidden" name="session" value="<?= $session ?>">
                        <input type="hidden" name="term" value="<?= $term ?>">

                        <div class="import-options">
                            <select name="exam_type">
                                <option value="both">Both Objective & Theory</option>
                                <option value="objective">Objective Only</option>
                                <option value="theory">Theory Only</option>
                            </select>
                            <button type="submit" name="import_results" class="btn btn-success">
                                <i class="fas fa-download"></i> Import Results
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Scores Entry Section -->
                <div class="form-section">
                    <h2><i class="fas fa-user-graduate"></i> Enter Scores for <?= $selected_class ?></h2>

                    <?php if (!empty($students)): ?>
                        <!-- Bulk Actions -->
                        <div class="bulk-actions">
                            <strong>Bulk Actions:</strong>
                            <button type="button" class="btn btn-warning" onclick="markAllAsNotTaking()">
                                <i class="fas fa-ban"></i> Mark All as Not Taking
                            </button>
                            <button type="button" class="btn btn-warning" onclick="clearAllScores()">
                                <i class="fas fa-trash"></i> Clear All Scores
                            </button>
                            <button type="button" class="btn btn-warning" onclick="fillWithPlaceholders()">
                                <i class="fas fa-edit"></i> Fill Empty with 0
                            </button>
                        </div>

                        <form method="POST" id="scoresForm">
                            <input type="hidden" name="class" value="<?= $selected_class ?>">
                            <input type="hidden" name="subject_id" value="<?= $selected_subject_id ?>">
                            <input type="hidden" name="session" value="<?= $session ?>">
                            <input type="hidden" name="term" value="<?= $term ?>">

                            <div style="overflow-x: auto;">
                                <table class="scores-table">
                                    <thead>
                                        <tr>
                                            <th style="min-width: 200px;">Student Name</th>
                                            <?php foreach ($score_types as $type): ?>
                                                <th style="min-width: 120px;"><?= $type['name'] ?><br><small>Max: <?= $type['max_score'] ?></small></th>
                                            <?php endforeach; ?>
                                            <th style="min-width: 100px;">Total</th>
                                            <th style="min-width: 100px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student): ?>
                                            <?php
                                            // Check if student already has scores for this subject
                                            $has_existing_scores = false;
                                            $existing_total = 0;
                                            $existing_scores_data = [];

                                            if ($selected_subject_id) {
                                                $check_stmt = $pdo->prepare("SELECT score_data FROM student_scores WHERE student_id = ? AND subject_id = ? AND session = ? AND term = ?");
                                                $check_stmt->execute([$student['id'], $selected_subject_id, $session, $term]);
                                                $existing_score = $check_stmt->fetch();
                                                $has_existing_scores = !empty($existing_score);

                                                // Get existing scores for each type and calculate total
                                                if ($has_existing_scores && !empty($existing_score['score_data'])) {
                                                    $existing_scores_data = json_decode($existing_score['score_data'], true);
                                                    if (is_array($existing_scores_data)) {
                                                        $existing_total = array_sum($existing_scores_data);
                                                    }
                                                }
                                            }
                                            ?>
                                            <tr id="student_<?= $student['id'] ?>" class="<?= $has_existing_scores ? 'student-taking-subject' : 'student-not-taking' ?>">
                                                <td class="student-info">
                                                    <?= htmlspecialchars($student['full_name']) ?>
                                                    <div class="student-admission"><?= htmlspecialchars($student['admission_number']) ?></div>
                                                    <?php if ($has_existing_scores): ?>
                                                        <span class="has-scores-badge">Has scores</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php foreach ($score_types as $type): ?>
                                                    <?php
                                                    $score_key = str_replace(' ', '_', strtolower($type['name']));
                                                    $max_score = $type['max_score'];
                                                    $existing_value = isset($existing_scores_data[$type['name']]) ? $existing_scores_data[$type['name']] : '';
                                                    $imported_value = isset($student['imported_scores'][$type['name']]) ? $student['imported_scores'][$type['name']] : $existing_value;
                                                    ?>
                                                    <td>
                                                        <div class="score-input-container">
                                                            <input type="text"
                                                                name="scores[<?= $student['id'] ?>][<?= $score_key ?>]"
                                                                class="score-input"
                                                                placeholder="0"
                                                                data-student="<?= $student['id'] ?>"
                                                                data-max="<?= $max_score ?>"
                                                                value="<?= $imported_value ?>"
                                                                oninput="validateScore(this)"
                                                                onchange="calculateTotal(this)">
                                                            <button type="button" class="skip-btn" onclick="markAsSkip(this)">Skip</button>
                                                        </div>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td>
                                                    <span id="total_<?= $student['id'] ?>" style="font-weight: 600;">
                                                        <?= isset($student['imported_total']) ? $student['imported_total'] : $existing_total ?>
                                                    </span>/<?= $settings['max_score'] ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-danger" style="padding: 6px 12px; font-size: 0.85rem;" onclick="skipStudent(<?= $student['id'] ?>)">
                                                        <i class="fas fa-ban"></i> Skip
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="action-buttons">
                                <button type="submit" name="save_scores" class="btn btn-success">
                                    <i class="fas fa-save"></i> Save All Scores
                                </button>
                                <button type="button" class="btn" onclick="saveOnlyCompleted()">
                                    <i class="fas fa-check-circle"></i> Save Only Completed
                                </button>
                                <button type="button" class="btn btn-danger" onclick="clearForm()">
                                    <i class="fas fa-trash"></i> Clear Form
                                </button>
                            </div>
                        </form>

                        <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; font-size: 0.9rem;">
                            <h4><i class="fas fa-lightbulb"></i> Tips for Entering Scores:</h4>
                            <ul style="margin: 10px 0 0 20px;">
                                <li>Enter scores only for students taking this subject</li>
                                <li>Leave fields blank or type "skip" for students not taking the subject</li>
                                <li>Scores will be validated against the maximum for each score type</li>
                                <li>Use the "Skip" button to quickly mark a student as not taking the subject</li>
                                <li>Scores are saved per student - no need to fill all rows at once</li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #666;">
                            <i class="fas fa-user-slash" style="font-size: 3rem; margin-bottom: 20px; color: #ddd;"></i>
                            <h3>No Students Found</h3>
                            <p>No active students found in <?= $selected_class ?> class.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($selected_class && $settings && !$selected_subject_id): ?>
                <!-- Show message to select a subject -->
                <div class="form-section">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Please select a subject to enter scores for <?= $selected_class ?> class.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function validateScore(input) {
            const value = input.value.trim();
            const max = parseFloat(input.dataset.max);

            if (value === 'skip' || value === 'Skip' || value === 'SKIP' ||
                value === 'na' || value === 'NA' || value === 'n/a' || value === 'N/A' ||
                value === '') {
                // Valid skip values or empty
                return true;
            }

            const numValue = parseFloat(value);
            if (isNaN(numValue)) {
                alert('Please enter a valid number or "skip"');
                input.value = '';
                input.focus();
                return false;
            }

            if (numValue < 0 || numValue > max) {
                alert(`Score must be between 0 and ${max}`);
                input.value = '';
                input.focus();
                return false;
            }

            // Update with formatted value
            input.value = numValue.toFixed(1);
            calculateTotal(input);
            return true;
        }

        function calculateTotal(input) {
            const studentId = input.dataset.student;
            const row = document.getElementById(`student_${studentId}`);
            const inputs = row.querySelectorAll('.score-input');
            let total = 0;
            let hasAnyScore = false;

            inputs.forEach(input => {
                const value = input.value.trim();
                if (value && value !== 'skip' && value !== 'Skip' && value !== 'SKIP' &&
                    value !== 'na' && value !== 'NA' && value !== 'n/a' && value !== 'N/A') {
                    const numValue = parseFloat(value);
                    if (!isNaN(numValue)) {
                        total += numValue;
                        hasAnyScore = true;
                    }
                }
            });

            const totalSpan = document.getElementById(`total_${studentId}`);
            totalSpan.textContent = total.toFixed(1);

            // Update row styling based on whether student has scores
            if (hasAnyScore) {
                row.classList.remove('student-not-taking');
                row.classList.add('student-taking-subject');
            } else {
                row.classList.remove('student-taking-subject');
                row.classList.add('student-not-taking');
            }
        }

        function markAsSkip(button) {
            const input = button.previousElementSibling;
            input.value = 'skip';
            calculateTotal(input);
        }

        function skipStudent(studentId) {
            const row = document.getElementById(`student_${studentId}`);
            const inputs = row.querySelectorAll('.score-input');

            inputs.forEach(input => {
                input.value = 'skip';
            });

            calculateTotal(inputs[0]);
            alert('Student marked as not taking this subject');
        }

        function markAllAsNotTaking() {
            if (confirm('Mark ALL students as not taking this subject? This will clear any entered scores.')) {
                const inputs = document.querySelectorAll('.score-input');
                inputs.forEach(input => {
                    input.value = 'skip';
                    calculateTotal(input);
                });
            }
        }

        function clearAllScores() {
            if (confirm('Clear ALL scores? This will set all fields to empty.')) {
                const inputs = document.querySelectorAll('.score-input');
                inputs.forEach(input => {
                    input.value = '';
                    calculateTotal(input);
                });
            }
        }

        function fillWithPlaceholders() {
            if (confirm('Fill all empty fields with 0?')) {
                const inputs = document.querySelectorAll('.score-input');
                inputs.forEach(input => {
                    if (!input.value.trim()) {
                        input.value = '0';
                        calculateTotal(input);
                    }
                });
            }
        }

        function saveOnlyCompleted() {
            if (confirm('Save only scores for students with completed entries?')) {
                document.getElementById('scoresForm').submit();
            }
        }

        function clearForm() {
            if (confirm('Clear the entire form? All entered data will be lost.')) {
                document.getElementById('scoresForm').reset();
                const inputs = document.querySelectorAll('.score-input');
                inputs.forEach(input => {
                    input.value = '';
                    calculateTotal(input);
                });
            }
        }

        // Auto-calculate totals on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.score-input').forEach(input => {
                if (input.value) {
                    calculateTotal(input);
                }
            });

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
        });
    </script>
</body>

</html>