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
 * EXPORT FUNCTION - Now matches the structure expected by generate_report_card.php
 */
function exportClassData($session, $term, $class)
{
    global $pdo;

    // Get students in class
    $stmt = $pdo->prepare("
        SELECT id, admission_number, full_name, class, gender, dob as date_of_birth, 
               parent_phone, parent_email 
        FROM students 
        WHERE class = ? AND status = 'active'
        ORDER BY full_name ASC
    ");
    $stmt->execute([$class]);
    $students = $stmt->fetchAll();

    if (empty($students)) {
        return ['success' => false, 'error' => 'No students found in this class'];
    }

    // Get report card settings for this session/term
    $stmt = $pdo->prepare("
        SELECT * FROM report_card_settings WHERE session = ? AND term = ?
    ");
    $stmt->execute([$session, $term]);
    $settings = $stmt->fetch();

    if (!$settings) {
        // Use default settings if none found
        $settings = [
            'session' => $session,
            'term' => $term,
            'max_score' => 100,
            'score_types' => json_encode([
                ['name' => 'CA1', 'max_score' => 10],
                ['name' => 'CA2', 'max_score' => 10],
                ['name' => 'Exam', 'max_score' => 80]
            ]),
            'grading_system' => 'simple',
            'next_resumption_date' => null,
            'current_resumption_date' => null,
            'current_closing_date' => null,
            'days_school_opened' => 90
        ];
    }

    $score_types = json_decode($settings['score_types'], true) ?: [
        ['name' => 'CA1', 'max_score' => 10],
        ['name' => 'CA2', 'max_score' => 10],
        ['name' => 'Exam', 'max_score' => 80]
    ];

    $students_data = [];
    $results_data = [];
    $processed = 0;
    $failed = 0;
    $errors = [];

    foreach ($students as $student) {
        // Get scores for this student - with proper calculations
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

        // Calculate totals and prepare scores in the SAME format as generate_report_card.php
        $formatted_scores = [];
        $total_marks = 0;
        $total_percentage = 0;
        $subject_count = count($scores);

        foreach ($scores as $index => $score) {
            // Decode the original score_data
            $score_data = json_decode($score['score_data'], true);
            if (!is_array($score_data)) {
                $score_data = [];
            }

            // Calculate total score from individual components
            $subject_total = 0;
            $subject_max = 0;

            foreach ($score_types as $type) {
                $score_value = floatval($score_data[$type['name']] ?? 0);
                $subject_total += $score_value;
                $subject_max += floatval($type['max_score']);
            }

            // Calculate percentage
            $percentage = $subject_max > 0 ? round(($subject_total / $subject_max) * 100, 2) : 0;
            $grade = calculateGrade($percentage);
            $remark = getPerformanceRemark($percentage);

            $total_marks += $subject_total;
            $total_percentage += $percentage;

            // Format scores EXACTLY like generate_report_card.php expects
            $formatted_scores[] = [
                'subject_name' => $score['subject_name'],
                'subject_id' => $score['subject_id'],
                'score_data' => $score_data,  // Original raw scores (CA1, CA2, Exam, etc.)
                'total_score' => $subject_total,
                'percentage' => $percentage,
                'grade' => $grade,
                'remark' => $remark,
                'max_score' => $subject_max,
                // Also include individual component values for easy access
                'components' => $score_data
            ];
        }

        // Calculate overall average
        $overall_average = $subject_count > 0 ? $total_percentage / $subject_count : 0;
        $overall_grade = calculateGrade($overall_average);
        $overall_remark = getPerformanceRemark($overall_average);

        // Get class position
        $stmt = $pdo->prepare("
            SELECT * FROM student_positions 
            WHERE student_id = ? AND session = ? AND term = ?
        ");
        $stmt->execute([$student['id'], $session, $term]);
        $position = $stmt->fetch();

        // Get class total students
        $class_total = getClassTotal($pdo, $class, $session, $term);

        // Get highest and lowest averages in class
        $stmt = $pdo->prepare("
            SELECT MAX(average) as highest, MIN(average) as lowest 
            FROM student_positions sp 
            JOIN students s ON sp.student_id = s.id 
            WHERE s.class = ? AND sp.session = ? AND sp.term = ? AND average > 0
        ");
        $stmt->execute([$class, $session, $term]);
        $class_stats = $stmt->fetch();
        $highest_average = $class_stats['highest'] ?? 0;
        $lowest_average = $class_stats['lowest'] ?? 0;

        // Get comments
        $stmt = $pdo->prepare("
            SELECT * FROM student_comments 
            WHERE student_id = ? AND session = ? AND term = ?
        ");
        $stmt->execute([$student['id'], $session, $term]);
        $comments = $stmt->fetch();

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

        // Calculate attendance
        $days_present = $comments['days_present'] ?? 0;
        $days_absent = $comments['days_absent'] ?? 0;
        $days_school_opened = $settings['days_school_opened'] ?? 90;
        $attendance_percentage = $days_school_opened > 0 ? round(($days_present / $days_school_opened) * 100, 1) : 0;

        // Calculate age
        $age = null;
        if (!empty($student['date_of_birth'])) {
            $age_years = floor((time() - strtotime($student['date_of_birth'])) / 31556926);
            $age = $age_years;
        }

        // Prepare student data (same structure as generate_report_card)
        $students_data[] = [
            'admission_number' => $student['admission_number'],
            'full_name' => $student['full_name'],
            'class' => $student['class'],
            'gender' => $student['gender'] ?? null,
            'date_of_birth' => $student['date_of_birth'] ?? null,
            'age' => $age,
            'parent_phone' => $student['parent_phone'] ?? null,
            'parent_email' => $student['parent_email'] ?? null
        ];

        // Prepare result data - MATCHING generate_report_card.php structure EXACTLY
        $results_data[] = [
            'admission_number' => $student['admission_number'],
            'full_name' => $student['full_name'],
            'session_year' => $session,
            'term' => $term,
            'class' => $student['class'],

            // Academic scores with full details
            'scores' => $formatted_scores,

            // Summary statistics
            'summary' => [
                'total_marks' => round($total_marks, 1),
                'average' => round($overall_average, 1),
                'grade' => $overall_grade,
                'remark' => $overall_remark,
                'subject_count' => $subject_count,
                'highest_average' => round($highest_average, 1),
                'lowest_average' => round($lowest_average, 1)
            ],

            // Position information
            'position' => [
                'class_position' => $position['class_position'] ?? null,
                'class_total' => $class_total,
                'subject_positions' => getSubjectPositions($pdo, $student['id'], $session, $term, $class)
            ],

            // Comments
            'comments' => [
                'teachers_comment' => $comments['teachers_comment'] ?? null,
                'principals_comment' => $comments['principals_comment'] ?? null
            ],

            // Attendance
            'attendance' => [
                'days_present' => $days_present,
                'days_absent' => $days_absent,
                'days_school_opened' => $days_school_opened,
                'attendance_percentage' => $attendance_percentage
            ],

            // Affective traits
            'affective_traits' => $affective ? [
                'punctuality' => $affective['punctuality'] ?? null,
                'attendance' => $affective['attendance'] ?? null,
                'politeness' => $affective['politeness'] ?? null,
                'honesty' => $affective['honesty'] ?? null,
                'neatness' => $affective['neatness'] ?? null,
                'reliability' => $affective['reliability'] ?? null,
                'relationship' => $affective['relationship'] ?? null,
                'self_control' => $affective['self_control'] ?? null
            ] : null,

            // Psychomotor skills
            'psychomotor_skills' => $psychomotor ? [
                'handwriting' => $psychomotor['handwriting'] ?? null,
                'verbal_fluency' => $psychomotor['verbal_fluency'] ?? null,
                'sports' => $psychomotor['sports'] ?? null,
                'handling_tools' => $psychomotor['handling_tools'] ?? null,
                'drawing_painting' => $psychomotor['drawing_painting'] ?? null,
                'musical_skills' => $psychomotor['musical_skills'] ?? null
            ] : null,

            // Settings for report card generation
            'settings' => [
                'score_types' => $score_types,
                'max_score' => $settings['max_score'] ?? 100,
                'grading_system' => $settings['grading_system'] ?? 'simple',
                'next_resumption_date' => $settings['next_resumption_date'],
                'current_resumption_date' => $settings['current_resumption_date'],
                'current_closing_date' => $settings['current_closing_date']
            ],

            'is_published' => true,
            'export_timestamp' => date('Y-m-d H:i:s')
        ];

        $processed++;
    }

    if (empty($students_data)) {
        return ['success' => false, 'error' => 'No valid student data to export'];
    }

    // Prepare payload with FULL data structure
    $payload = [
        'api_key' => PORTAL_API_KEY,
        'school_code' => SCHOOL_CODE,
        'action' => 'sync_full',
        'students' => $students_data,
        'results' => $results_data,
        'metadata' => [
            'export_session' => $session,
            'export_term' => $term,
            'export_class' => $class,
            'export_date' => date('Y-m-d H:i:s'),
            'total_students' => count($students_data),
            'total_results' => count($results_data)
        ]
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
            'summary' => $results_data[0]['summary'],
            'scores_sample' => !empty($results_data[0]['scores']) ? array_map(function ($s) {
                return [
                    'subject_name' => $s['subject_name'],
                    'total_score' => $s['total_score'],
                    'percentage' => $s['percentage'],
                    'grade' => $s['grade'],
                    'remark' => $s['remark']
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

/**
 * Get subject positions for a student
 */
function getSubjectPositions($pdo, $student_id, $session, $term, $class)
{
    $positions = [];

    try {
        // Get all subjects and their scores for this class
        $stmt = $pdo->prepare("
            SELECT 
                sub.subject_name,
                ss.subject_id,
                ss.student_id,
                ss.total_score
            FROM student_scores ss
            JOIN subjects sub ON ss.subject_id = sub.id
            JOIN students s ON ss.student_id = s.id
            WHERE s.class = ? AND ss.session = ? AND ss.term = ?
            ORDER BY sub.subject_name, ss.total_score DESC
        ");
        $stmt->execute([$class, $session, $term]);
        $all_scores = $stmt->fetchAll();

        // Group by subject and calculate positions
        $subject_scores = [];
        foreach ($all_scores as $score) {
            if (!isset($subject_scores[$score['subject_id']])) {
                $subject_scores[$score['subject_id']] = [
                    'name' => $score['subject_name'],
                    'scores' => []
                ];
            }
            $subject_scores[$score['subject_id']]['scores'][] = [
                'student_id' => $score['student_id'],
                'total_score' => $score['total_score']
            ];
        }

        // Calculate position for each subject for this student
        foreach ($subject_scores as $subject_id => $data) {
            $position = 1;
            $prev_score = null;
            $rank = 1;

            foreach ($data['scores'] as $index => $score_data) {
                if ($score_data['total_score'] != $prev_score && $index > 0) {
                    $rank = $position;
                }
                if ($score_data['student_id'] == $student_id) {
                    $positions[$data['name']] = $rank;
                    break;
                }
                $position++;
                $prev_score = $score_data['total_score'];
            }
        }
    } catch (Exception $e) {
        // Log error but continue
        error_log("Error getting subject positions: " . $e->getMessage());
    }

    return $positions;
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

/**
 * Calculate grade based on percentage (matches generate_report_card.php)
 */
function calculateGrade($percentage)
{
    if ($percentage >= 70) return 'A';
    if ($percentage >= 60) return 'B';
    if ($percentage >= 50) return 'C';
    if ($percentage >= 40) return 'D';
    if ($percentage >= 30) return 'E';
    return 'F';
}

/**
 * Get performance remark (matches generate_report_card.php)
 */
function getPerformanceRemark($percentage)
{
    if ($percentage >= 70) return 'Excellent';
    if ($percentage >= 60) return 'Very good';
    if ($percentage >= 50) return 'Good';
    if ($percentage >= 40) return 'Pass';
    if ($percentage >= 30) return 'Poor';
    return 'Fail';
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
