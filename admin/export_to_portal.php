<?php
// admin/export_to_portal.php - Export data from local CBT system to MyResultChecker portal
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Portal configuration - MUST be configured in config.php
if (!defined('PORTAL_API_URL')) {
    define('PORTAL_API_URL', 'https://impactdigitalacademy.com.ng/result-checker/api/sync.php');
}
if (!defined('PORTAL_API_KEY')) {
    define('PORTAL_API_KEY', '8d1910fa39812e0077acfc629741b96b1580836edaf9dacc19fa95b64155c5bf');
}
if (!defined('SCHOOL_CODE')) {
    define('SCHOOL_CODE', 'TCBA001');
}

// Get current session and term
$current_session = date('Y') . '/' . (date('Y') + 1);
$current_term = 'First';

// Get classes for dropdown
$stmt = $pdo->query("SELECT DISTINCT class FROM students WHERE status = 'active' ORDER BY class");
$classes = $stmt->fetchAll();

// Get available sessions from student_scores
$stmt = $pdo->query("SELECT DISTINCT session FROM student_scores ORDER BY session DESC");
$sessions = $stmt->fetchAll();

// Handle export
$export_result = null;
$export_error = null;
$export_success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $session = trim($_POST['session'] ?? '');
    $term = trim($_POST['term'] ?? '');
    $class = trim($_POST['class'] ?? '');

    if (empty($session) || empty($term) || empty($class)) {
        $export_error = "Please select session, term, and class";
    } elseif (empty(PORTAL_API_KEY) || PORTAL_API_KEY === 'YOUR_SCHOOL_API_KEY') {
        $export_error = "Portal integration not configured. Please contact your system administrator to get API credentials.";
    } else {
        $export_result = exportClassData($session, $term, $class);
        if ($export_result['success']) {
            $export_success = $export_result;
        } else {
            $export_error = $export_result['error'];
        }
    }
}

/**
 * NEW FUNCTION: Export raw score data without transformation
 * This preserves the exact structure from the database
 */
function exportClassData($session, $term, $class)
{
    global $pdo;

    // Get students in class
    $stmt = $pdo->prepare("
        SELECT id, admission_number, full_name, class, gender, dob, parent_phone, parent_email 
        FROM students 
        WHERE class = ? AND status = 'active'
        ORDER BY full_name ASC
    ");
    $stmt->execute([$class]);
    $students = $stmt->fetchAll();

    if (empty($students)) {
        return ['success' => false, 'error' => 'No students found in this class'];
    }

    $students_data = [];
    $results_data = [];
    $processed = 0;
    $failed = 0;
    $errors = [];

    foreach ($students as $student) {
        // Get scores for this student - PRESERVE ORIGINAL STRUCTURE
        $stmt = $pdo->prepare("
            SELECT ss.*, sub.subject_name, sub.id as subject_id 
            FROM student_scores ss
            JOIN subjects sub ON ss.subject_id = sub.id
            WHERE ss.student_id = ? AND ss.session = ? AND ss.term = ?
            ORDER BY sub.subject_name ASC
        ");
        $stmt->execute([$student['id'], $session, $term]);
        $scores = $stmt->fetchAll();

        // Skip if no scores found
        if (empty($scores)) {
            $failed++;
            $errors[] = "No scores found for {$student['full_name']} ({$student['admission_number']})";
            continue;
        }

        // ============ KEY CHANGE: Preserve original score_data exactly ============
        $formatted_scores = [];
        foreach ($scores as $score) {
            // Decode the original score_data
            $original_score_data = json_decode($score['score_data'], true);

            // If decoding failed or it's not an array, use empty array
            if (!is_array($original_score_data)) {
                $original_score_data = [];
            }

            // Add metadata to help portal understand the structure
            $formatted_scores[] = [
                'subject_name' => $score['subject_name'],
                'subject_id' => $score['subject_id'],
                // IMPORTANT: Send the EXACT original data structure
                'score_data' => $original_score_data,
                // Also include a flat representation of all numeric fields for flexibility
                'raw_values' => $original_score_data
            ];
        }

        // Get comments
        $stmt = $pdo->prepare("
            SELECT * FROM student_comments 
            WHERE student_id = ? AND session = ? AND term = ?
        ");
        $stmt->execute([$student['id'], $session, $term]);
        $comments = $stmt->fetch();

        // Get position
        $stmt = $pdo->prepare("
            SELECT * FROM student_positions 
            WHERE student_id = ? AND session = ? AND term = ?
        ");
        $stmt->execute([$student['id'], $session, $term]);
        $position = $stmt->fetch();

        // Get affective traits
        $stmt = $pdo->prepare("
            SELECT * FROM affective_traits 
            WHERE student_id = ? AND session = ? AND term = ?
        ");
        $stmt->execute([$student['id'], $session, $term]);
        $affective = $stmt->fetch();

        // Get psychomotor skills
        $stmt = $pdo->prepare("
            SELECT * FROM psychomotor_skills 
            WHERE student_id = ? AND session = ? AND term = ?
        ");
        $stmt->execute([$student['id'], $session, $term]);
        $psychomotor = $stmt->fetch();

        // Prepare student data
        $students_data[] = [
            'admission_number' => $student['admission_number'],
            'full_name' => $student['full_name'],
            'class' => $student['class'],
            'gender' => $student['gender'] ?? null,
            'date_of_birth' => $student['dob'] ?? null,
            'parent_phone' => $student['parent_phone'] ?? null,
            'parent_email' => $student['parent_email'] ?? null
        ];

        // Prepare result data with ORIGINAL scores preserved
        $results_data[] = [
            'admission_number' => $student['admission_number'],
            'session_year' => $session,
            'term' => $term,
            'scores' => $formatted_scores,  // Contains original score_data
            'class_position' => $position['class_position'] ?? null,
            'class_total_students' => getClassTotal($pdo, $class, $session, $term),
            'promoted_to' => $position['promoted_to'] ?? null,
            'teachers_comment' => $comments['teachers_comment'] ?? null,
            'principals_comment' => $comments['principals_comment'] ?? null,
            'days_present' => $comments['days_present'] ?? 0,
            'days_absent' => $comments['days_absent'] ?? 0,
            'affective_traits' => $affective ? [
                'punctuality' => $affective['punctuality'],
                'attendance' => $affective['attendance'],
                'politeness' => $affective['politeness'],
                'honesty' => $affective['honesty'],
                'neatness' => $affective['neatness'],
                'reliability' => $affective['reliability'],
                'relationship' => $affective['relationship'],
                'self_control' => $affective['self_control']
            ] : null,
            'psychomotor_skills' => $psychomotor ? [
                'handwriting' => $psychomotor['handwriting'],
                'verbal_fluency' => $psychomotor['verbal_fluency'],
                'sports' => $psychomotor['sports'],
                'handling_tools' => $psychomotor['handling_tools'],
                'drawing_painting' => $psychomotor['drawing_painting'],
                'musical_skills' => $psychomotor['musical_skills']
            ] : null,
            'is_published' => true
        ];

        $processed++;
    }

    if (empty($students_data)) {
        return ['success' => false, 'error' => 'No valid student data to export'];
    }

    // Prepare payload
    $payload = [
        'api_key' => PORTAL_API_KEY,
        'school_code' => SCHOOL_CODE,
        'action' => 'sync_full',
        'students' => $students_data,
        'results' => $results_data
    ];

    // Debug logging
    $log_dir = '../logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0777, true);
    }

    // Save sample of what we're sending (for debugging)
    $debug_file = $log_dir . '/export_payload_sample.log';
    $sample = [
        'timestamp' => date('Y-m-d H:i:s'),
        'school_code' => SCHOOL_CODE,
        'students_count' => count($students_data),
        'results_count' => count($results_data),
        'first_student_sample' => !empty($students_data) ? $students_data[0] : null,
        'first_result_sample' => !empty($results_data) ? [
            'admission_number' => $results_data[0]['admission_number'],
            'session_year' => $results_data[0]['session_year'],
            'term' => $results_data[0]['term'],
            'scores_sample' => !empty($results_data[0]['scores']) ? array_map(function ($s) {
                return [
                    'subject_name' => $s['subject_name'],
                    'score_data' => $s['score_data']  // Shows the original structure
                ];
            }, array_slice($results_data[0]['scores'], 0, 2)) : null
        ] : null
    ];
    file_put_contents($debug_file, json_encode($sample, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

    // Send to portal
    $ch = curl_init(PORTAL_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Save response debug
    $response_file = $log_dir . '/export_response.log';
    $response_log = [
        'timestamp' => date('Y-m-d H:i:s'),
        'http_code' => $httpCode,
        'curl_error' => $curl_error,
        'response' => $response
    ];
    file_put_contents($response_file, json_encode($response_log, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

    if ($curl_error) {
        return ['success' => false, 'error' => 'CURL Error: ' . $curl_error];
    }

    if ($httpCode == 200) {
        $result = json_decode($response, true);
        return [
            'success' => true,
            'students_count' => count($students_data),
            'results_count' => count($results_data),
            'processed' => $processed,
            'failed' => $failed,
            'errors' => $errors,
            'response' => $result
        ];
    } else {
        return [
            'success' => false,
            'error' => "HTTP $httpCode: " . substr($response, 0, 500)
        ];
    }
}

function getClassTotal($pdo, $class, $session, $term)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT student_id) as total 
        FROM student_scores ss
        JOIN students s ON ss.student_id = s.id
        WHERE s.class = ? AND ss.session = ? AND ss.term = ?
    ");
    $stmt->execute([$class, $session, $term]);
    $result = $stmt->fetch();
    return $result['total'] ?? 0;
}

function testPortalConnection()
{
    if (empty(PORTAL_API_KEY) || PORTAL_API_KEY === 'YOUR_SCHOOL_API_KEY') {
        return ['success' => false, 'error' => 'API key not configured'];
    }

    $payload = [
        'api_key' => PORTAL_API_KEY,
        'school_code' => SCHOOL_CODE,
        'action' => 'test_connection'
    ];

    $ch = curl_init(PORTAL_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        $result = json_decode($response, true);
        return ['success' => true, 'message' => $result['message'] ?? 'Connection successful'];
    } else {
        return ['success' => false, 'error' => "HTTP $httpCode: " . substr($response, 0, 200)];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Export to Portal - <?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School Management System'; ?></title>

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
            max-width: 1000px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .card h2 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
        }

        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--light-color);
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group select:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .btn {
            background: linear-gradient(135deg, var(--secondary-color), #2980b9);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }

        .btn-secondary:hover {
            box-shadow: 0 5px 15px rgba(127, 140, 141, 0.3);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #d5f4e6;
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #fef2f2;
            color: var(--danger-color);
            border-left: 4px solid var(--danger-color);
        }

        .alert-info {
            background: #e8f4fd;
            color: var(--secondary-color);
            border-left: 4px solid var(--secondary-color);
        }

        .alert-warning {
            background: #fff8e1;
            color: var(--warning-color);
            border-left: 4px solid var(--warning-color);
        }

        .info-box {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }

        .info-box h4 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 1.1rem;
        }

        .info-box ul {
            padding-left: 20px;
            color: #666;
        }

        .info-box li {
            margin-bottom: 8px;
        }

        .export-stats {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }

        .export-stats h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }

        .stat-label {
            font-weight: 500;
            color: #555;
        }

        .stat-value {
            font-weight: 600;
            color: var(--primary-color);
        }

        .stat-value.success {
            color: var(--success-color);
        }

        .stat-value.error {
            color: var(--danger-color);
        }

        .error-list {
            max-height: 200px;
            overflow-y: auto;
            margin-top: 15px;
            padding: 10px;
            background: #fff;
            border-radius: 8px;
            font-size: 0.85rem;
        }

        .error-item {
            padding: 5px 0;
            color: var(--danger-color);
            border-bottom: 1px solid #f0f0f0;
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
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .back-link:hover {
            background: rgba(52, 152, 219, 0.1);
            transform: translateX(-5px);
        }

        .config-warning {
            background: #fff8e1;
            border: 1px solid #ffd54f;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }

        .config-warning i {
            font-size: 48px;
            color: var(--warning-color);
            margin-bottom: 15px;
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

            .row {
                grid-template-columns: 1fr;
            }

            .top-header {
                padding: 20px;
            }

            .header-title h1 {
                font-size: 1.5rem;
            }

            .card {
                padding: 20px;
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

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #fff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
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
                    <p>Admin Panel</p>
                </div>
            </div>

            <ul class="nav-links">
                <li><a href="report_card_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="export_to_portal.php" class="active"><i class="fas fa-cloud-upload-alt"></i> Export to Portal</a></li>
                <li><a href="report_card_dashboard.php"><i class="fas fa-file-contract"></i> Report Cards</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-cloud-upload-alt"></i> Export to MyResultChecker Portal</h1>
                <p>Upload student data and results to the central result checking portal</p>
            </div>
        </div>

        <div class="container">
            <?php if (empty(PORTAL_API_KEY) || PORTAL_API_KEY === 'YOUR_SCHOOL_API_KEY'): ?>
                <div class="config-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Portal Integration Not Configured</h3>
                    <p>Please contact your system administrator to get API credentials for MyResultChecker portal.</p>
                    <p style="margin-top: 10px; font-size: 0.85rem;">You need to add the following to your config.php file:</p>
                    <code style="display: inline-block; margin-top: 10px; padding: 10px; background: #f0f0f0; border-radius: 5px;">
                        define('PORTAL_API_URL', 'https://impactdigitalacademy.com.ng/result-checker/api/sync.php');<br>
                        define('PORTAL_API_KEY', 'YOUR_SCHOOL_API_KEY');<br>
                        define('SCHOOL_CODE', 'YOUR_SCHOOL_CODE');
                    </code>
                </div>
            <?php endif; ?>

            <?php if ($export_error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($export_error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($export_success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span>Export completed successfully!</span>
                </div>

                <div class="export-stats">
                    <h3><i class="fas fa-chart-bar"></i> Export Summary</h3>
                    <div class="stat-row">
                        <span class="stat-label">Students Exported:</span>
                        <span class="stat-value success"><?php echo $export_success['students_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Results Exported:</span>
                        <span class="stat-value success"><?php echo $export_success['results_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Successfully Processed:</span>
                        <span class="stat-value success"><?php echo $export_success['processed']; ?></span>
                    </div>
                    <?php if ($export_success['failed'] > 0): ?>
                        <div class="stat-row">
                            <span class="stat-label">Failed:</span>
                            <span class="stat-value error"><?php echo $export_success['failed']; ?></span>
                        </div>
                        <?php if (!empty($export_success['errors'])): ?>
                            <div class="error-list">
                                <?php foreach ($export_success['errors'] as $error): ?>
                                    <div class="error-item">
                                        <i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($error); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <h2><i class="fas fa-upload"></i> Export Student Results</h2>

                <form method="POST" action="" id="exportForm">
                    <div class="row">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Academic Session</label>
                            <select name="session" required>
                                <option value="">Select Session</option>
                                <?php foreach ($sessions as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s['session']); ?>"
                                        <?php echo (isset($_POST['session']) && $_POST['session'] === $s['session']) ? 'selected' : ''; ?>
                                        <?php echo (!isset($_POST['session']) && $s['session'] === $current_session) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['session']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-chalkboard"></i> Term</label>
                            <select name="term" required>
                                <option value="">Select Term</option>
                                <option value="First" <?php echo (isset($_POST['term']) && $_POST['term'] === 'First') ? 'selected' : ''; ?>>First Term</option>
                                <option value="Second" <?php echo (isset($_POST['term']) && $_POST['term'] === 'Second') ? 'selected' : ''; ?>>Second Term</option>
                                <option value="Third" <?php echo (isset($_POST['term']) && $_POST['term'] === 'Third') ? 'selected' : ''; ?>>Third Term</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-users"></i> Class</label>
                            <select name="class" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c['class']); ?>"
                                        <?php echo (isset($_POST['class']) && $_POST['class'] === $c['class']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['class']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <button type="submit" class="btn" id="exportBtn">
                            <i class="fas fa-cloud-upload-alt"></i> Export to Portal
                        </button>
                        <button type="button" class="btn btn-secondary" id="testConnectionBtn" onclick="testConnection()">
                            <i class="fas fa-plug"></i> Test Connection
                        </button>
                    </div>
                </form>

                <div class="info-box">
                    <h4><i class="fas fa-info-circle"></i> What gets exported?</h4>
                    <ul>
                        <li><strong>Student Information:</strong> Name, admission number, class, gender, date of birth, parent contacts</li>
                        <li><strong>Academic Scores:</strong> CA1, CA2, Exam scores for all subjects</li>
                        <li><strong>Teacher Comments:</strong> Teacher's and Principal's remarks</li>
                        <li><strong>Affective Traits:</strong> Punctuality, attendance, politeness, honesty, neatness, etc.</li>
                        <li><strong>Psychomotor Skills:</strong> Handwriting, verbal fluency, sports, drawing, etc.</li>
                        <li><strong>Class Position:</strong> Student ranking within the class</li>
                        <li><strong>Promotion Status:</strong> Promoted to next class</li>
                    </ul>
                </div>

                <div class="info-box" style="margin-top: 15px; background: #e8f4fd;">
                    <h4><i class="fas fa-shield-alt"></i> Important Notes</h4>
                    <ul>
                        <li>Only students with scores will be exported</li>
                        <li>Classes are automatically created in the portal if they don't exist</li>
                        <li>Students are updated if they already exist (based on admission number)</li>
                        <li>Results are stored and made available for parents to check using PINs</li>
                        <li>This process may take a few moments depending on the number of students</li>
                    </ul>
                </div>
            </div>

            <a href="index.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <script>
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

        // Test connection function
        async function testConnection() {
            const testBtn = document.getElementById('testConnectionBtn');
            const originalHtml = testBtn.innerHTML;

            testBtn.innerHTML = '<span class="loading"></span> Testing...';
            testBtn.disabled = true;

            try {
                const response = await fetch('ajax_test_connection.php');
                const data = await response.json();

                if (data.success) {
                    showAlert('success', 'Connection successful! ' + (data.message || 'Portal is reachable.'));
                } else {
                    showAlert('error', 'Connection failed: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                showAlert('error', 'Network error: ' + error.message);
            } finally {
                testBtn.innerHTML = originalHtml;
                testBtn.disabled = false;
            }
        }

        function showAlert(type, message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i><span>${message}</span>`;

            const container = document.querySelector('.container');
            const firstCard = document.querySelector('.card');
            container.insertBefore(alertDiv, firstCard);

            setTimeout(() => {
                alertDiv.style.opacity = '0';
                setTimeout(() => alertDiv.remove(), 300);
            }, 5000);
        }

        // Form submission handling
        const exportForm = document.getElementById('exportForm');
        const exportBtn = document.getElementById('exportBtn');

        exportForm.addEventListener('submit', function() {
            exportBtn.innerHTML = '<span class="loading"></span> Exporting...';
            exportBtn.disabled = true;
        });

        // Auto-hide existing alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>
</body>

</html>