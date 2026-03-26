<?php
// functions.php - The Climax Brains Academy Utility Functions

// Include config if not already included
if (!defined('DB_HOST')) {
    require_once 'config.php';
}

/**
 * Display system header
 */
function displayHeader($pageTitle = '')
{
    $schoolName = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy';
    $systemName = defined('SYSTEM_NAME') ? SYSTEM_NAME : 'CBT System';

    // Define colors locally for safety
    $color_primary = '#2c3e50';
    $color_secondary = '#3498db';
    $color_light = '#ecf0f1';
    $color_dark = '#2c3e50';
    $color_success = '#27ae60';
    $color_warning = '#f39c12';
    $color_danger = '#e74c3c';

    return '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($pageTitle ? $pageTitle . ' - ' : '') . $schoolName . ' CBT System</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, ' . $color_light . ' 0%, #bdc3c7 100%);
                min-height: 100vh;
            }
            
            .school-header {
                background: linear-gradient(135deg, ' . $color_primary . ' 0%, ' . $color_secondary . ' 100%);
                color: white;
                padding: 25px 0;
                text-align: center;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                position: relative;
                overflow: hidden;
            }
            
            .school-header::before {
                content: "";
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: url("data:image/svg+xml,%3Csvg width=\'100\' height=\'100\' viewBox=\'0 0 100 100\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cpath d=\'M11 18c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm48 25c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm-43-7c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm63 31c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM34 90c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm56-76c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM12 86c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm28-65c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm23-11c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-6 60c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm29 22c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zM32 63c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm57-13c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-9-21c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM60 91c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM35 41c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM12 60c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2z\' fill=\'%23ffffff\' fill-opacity=\'0.1\' fill-rule=\'evenodd\'/%3E%3C/svg%3E");
            }
            
            .school-name {
                font-size: 2.5em;
                font-weight: 700;
                margin-bottom: 8px;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            }
            
            .system-name {
                font-size: 1.3em;
                font-weight: 300;
                opacity: 0.9;
                margin-bottom: 5px;
            }
            
            .tagline {
                font-size: 1em;
                font-weight: 300;
                opacity: 0.8;
            }
            
            .main-container {
                max-width: 1200px;
                margin: 30px auto;
                padding: 0 20px;
            }
            
            .content-card {
                background: white;
                border-radius: 15px;
                padding: 30px;
                box-shadow: 0 8px 25px rgba(0,0,0,0.1);
                margin-bottom: 25px;
                border-left: 5px solid ' . $color_secondary . ';
            }
            
            .btn {
                display: inline-block;
                padding: 12px 25px;
                background: linear-gradient(135deg, ' . $color_secondary . ', ' . $color_primary . ');
                color: white;
                text-decoration: none;
                border-radius: 8px;
                border: none;
                cursor: pointer;
                font-size: 16px;
                font-weight: 600;
                transition: all 0.3s ease;
                box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
            }
            
            .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(52, 152, 219, 0.4);
            }
            
            .btn-success {
                background: linear-gradient(135deg, ' . $color_success . ', #229954);
            }
            
            .btn-warning {
                background: linear-gradient(135deg, ' . $color_warning . ', #e67e22);
            }
            
            .btn-danger {
                background: linear-gradient(135deg, ' . $color_danger . ', #c0392b);
            }
            
            .alert {
                padding: 15px 20px;
                border-radius: 8px;
                margin: 15px 0;
                border-left: 4px solid;
            }
            
            .alert-success {
                background: #d5f4e6;
                border-color: ' . $color_success . ';
                color: #155724;
            }
            
            .alert-warning {
                background: #fff3cd;
                border-color: ' . $color_warning . ';
                color: #856404;
            }
            
            .alert-danger {
                background: #f8d7da;
                border-color: ' . $color_danger . ';
                color: #721c24;
            }
            
            .alert-info {
                background: #d1ecf1;
                border-color: ' . $color_secondary . ';
                color: #0c5460;
            }
            
            .footer {
                text-align: center;
                padding: 25px;
                background: ' . $color_dark . ';
                color: white;
                margin-top: 40px;
            }
            
            .login-container {
                max-width: 400px;
                margin: 50px auto;
            }
            
            .form-group {
                margin-bottom: 20px;
            }
            
            .form-label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: ' . $color_dark . ';
            }
            
            .form-control {
                width: 100%;
                padding: 12px 15px;
                border: 2px solid #ddd;
                border-radius: 8px;
                font-size: 16px;
                transition: border-color 0.3s ease;
            }
            
            .form-control:focus {
                border-color: ' . $color_secondary . ';
                outline: none;
                box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
            }
        </style>
    </head>
    <body>
        <div class="school-header">
            <div class="school-name">' . htmlspecialchars($schoolName) . '</div>
            <div class="system-name">' . htmlspecialchars($systemName) . '</div>
            <div class="tagline">Raising Champions</div>
        </div>
    ';
}

/**
 * Display system footer
 */
function displayFooter()
{
    $currentYear = date('Y');
    $schoolName = defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy';
    $color_dark = '#2c3e50';

    return '
        <div class="footer" style="background: ' . $color_dark . ';">
            <p>&copy; ' . $currentYear . ' ' . htmlspecialchars($schoolName) . ' - CBT System. All rights reserved.</p>
            <p style="opacity: 0.8; font-size: 0.9em; margin-top: 8px;">
                Powered by <strong>Impact Digital Solutions</strong>
            </p>
        </div>
    </body>
    </html>';
}

/**
 * Hash password
 */
function hashPassword($password)
{
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash)
{
    return password_verify($password, $hash);
}

/**
 * Generate random string
 */
function generateRandomString($length = 10)
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

/*
 Log activity

function logActivity($pdo, $userId, $activity, $userType = 'student')
{
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, activity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $userType, $activity, $_SERVER['REMOTE_ADDR'] ?? 'Unknown', $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown']);
        return true;
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
        return false;
    }
}
*/

/**
 * Sanitize input
 */
function sanitizeInput($data)
{
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(strip_tags(trim($data ?? '')));
}

/**
 * Format date
 */
function formatDate($date, $format = 'F j, Y g:i A')
{
    if (!$date) return 'N/A';
    return date($format, strtotime($date));
}

/**
 * Check if student has ongoing exam
 */
function hasOngoingExam($pdo, $studentId)
{
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM exam_sessions 
            WHERE student_id = ? AND status = 'in_progress' 
            ORDER BY start_time DESC 
            LIMIT 1
        ");
        $stmt->execute([$studentId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("hasOngoingExam error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get exam time remaining
 */
function getTimeRemaining($endTime)
{
    if (!$endTime) return 0;

    $end = strtotime($endTime);
    $now = time();
    $remaining = $end - $now;
    return $remaining > 0 ? $remaining : 0;
}

/**
 * Display countdown timer
 */
function displayCountdownTimer($seconds)
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;

    $color_warning = '#f39c12';
    $color_danger = '#e74c3c';

    return sprintf(
        "<div class='timer-display' style='font-size: 1.5em; font-weight: bold; text-align: center; padding: 15px; background: linear-gradient(135deg, %s, %s); color: white; border-radius: 10px; margin: 15px 0;'>
                    Time Remaining: %02d:%02d:%02d
                  </div>",
        $color_warning,
        $color_danger,
        $hours,
        $minutes,
        $seconds
    );
}

/**
 * Redirect with message
 */
function redirect($url, $message = '', $type = 'success')
{
    if ($message) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }
    header("Location: $url");
    exit();
}

/**
 * Display flash message
 */
function displayFlashMessage()
{
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';

        // Define alert colors
        $alert_classes = [
            'success' => 'alert-success',
            'warning' => 'alert-warning',
            'danger' => 'alert-danger',
            'info' => 'alert-info'
        ];

        $alert_class = $alert_classes[$type] ?? 'alert-info';

        echo "<div class='alert $alert_class'>" . htmlspecialchars($message) . "</div>";

        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
    }
}

/*
  Simple debug function
 
function debug($data, $exit = true)
{
    echo '<pre>';
    print_r($data);
    echo '</pre>';
    if ($exit) exit;
}

function getSubjectName($pdo, $subject_id)
{
    $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
    $stmt->execute([$subject_id]);
    return $stmt->fetchColumn();
}

function getStudentName($pdo, $student_id)
{
    $stmt = $pdo->prepare("SELECT full_name FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    return $stmt->fetchColumn();
}
*/


/**
 * Extract text from DOCX using simple XML parsing
 */
function extractTextFromDocx($filePath)
{
    $content = '';

    if (!extension_loaded('zip')) {
        throw new Exception("ZIP extension is not enabled. Please enable it in php.ini.");
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
        throw new Exception("Cannot open DOCX file as ZIP archive.");
    }

    // Read document.xml
    if (($index = $zip->locateName('word/document.xml')) !== FALSE) {
        $xmlContent = $zip->getFromIndex($index);

        // Remove XML namespace declarations but preserve the structure
        $xmlContent = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $xmlContent);
        $xmlContent = preg_replace('/<(\/)?([a-zA-Z]+):/', '<$1', $xmlContent);

        // Try to parse with error suppression
        $old_error_reporting = error_reporting(0);
        $xml = @simplexml_load_string($xmlContent);
        error_reporting($old_error_reporting);

        if ($xml !== false) {
            // Method 1: Try to get all text content
            $content = strip_tags($xmlContent);

            // Clean up the content
            $content = preg_replace('/\s+/', ' ', $content);
            $content = trim($content);

            // If that doesn't work well, try more specific extraction
            if (strlen($content) < 50) {
                $content = '';
                // Try to find text in <t> elements
                $pattern = '/<t[^>]*>(.*?)<\/t>/si';
                if (preg_match_all($pattern, $xmlContent, $matches)) {
                    $content = implode("\n", $matches[1]);
                }
            }
        } else {
            // Fallback: Just strip all tags
            $content = strip_tags($xmlContent);
            $content = preg_replace('/\s+/', ' ', $content);
            $content = trim($content);
        }
    }

    $zip->close();

    return $content;
}

/**
 * Extract text using PhpWord library
 */
function extractTextWithPhpWord($filePath)
{
    $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
    $content = '';

    // Get all sections
    $sections = $phpWord->getSections();
    foreach ($sections as $section) {
        // Get all elements in section
        $elements = $section->getElements();
        foreach ($elements as $element) {
            // Handle different element types
            if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                $textElements = $element->getElements();
                foreach ($textElements as $textElement) {
                    if ($textElement instanceof \PhpOffice\PhpWord\Element\Text) {
                        $content .= $textElement->getText();
                    }
                }
                $content .= "\n";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\Text) {
                $content .= $element->getText() . "\n";
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\ListItem) {
                $content .= "* " . $element->getText() . "\n";
            }
        }
    }

    return $content;
}

/**
 * Simple ZIP-based extraction
 */
function extractTextFromDocxZip($filePath)
{
    $content = '';

    $zip = new ZipArchive();
    if ($zip->open($filePath) === TRUE) {
        // Read document.xml
        $xmlContent = $zip->getFromName('word/document.xml');

        if ($xmlContent) {
            // Remove all XML tags to get plain text
            $content = strip_tags($xmlContent);
            // Clean up multiple spaces and newlines
            $content = preg_replace('/\s+/', ' ', $content);
            $content = str_replace('  ', ' ', $content);
        }

        $zip->close();
    }

    return $content;
}

/**
 * Clean and normalize Word content
 */
function cleanWordContent($content)
{
    // Remove non-printable characters
    $content = preg_replace('/[^\x20-\x7E\x0A\x0D]/', '', $content);

    // Replace multiple newlines with single newline
    $content = preg_replace('/\n\s*\n/', "\n", $content);

    // Trim each line
    $lines = explode("\n", $content);
    $lines = array_map('trim', $lines);

    // Remove empty lines
    $lines = array_filter($lines, function ($line) {
        return !empty(trim($line));
    });

    // Rebuild content
    $content = implode("\n", $lines);

    return $content;
}

/**
 * Parse Word content into structured data for objective questions
 */
function parseObjectiveQuestionsFromWord($content, $import_type)
{
    $questions = [];
    $lines = explode("\n", $content);

    $currentQuestion = null;

    foreach ($lines as $line) {
        $line = trim($line);

        if (empty($line)) {
            continue;
        }

        // Check if this is a new question (starts with number)
        if (preg_match('/^(\d+)[\.\)]\s*(.+)$/i', $line, $matches)) {
            // Save previous question if exists
            if ($currentQuestion) {
                $questions[] = $currentQuestion;
            }

            // Start new question
            $currentQuestion = [
                'number' => $matches[1],
                'text' => $matches[2],
                'options' => [],
                'correct' => '',
                'difficulty' => 'medium',
                'marks' => 1
            ];
        }
        // Check for options (A., B., C., D.)
        elseif (preg_match('/^([A-D])[\.\)]\s*(.+)$/i', $line, $matches)) {
            if ($currentQuestion) {
                $currentQuestion['options'][$matches[1]] = $matches[2];

                // Check if this is marked as correct (might have * or ✓)
                if (preg_match('/(\*|✓|\(correct\)|\[x\])$/i', $matches[2])) {
                    $currentQuestion['correct'] = $matches[1];
                    // Remove the marker from the option text
                    $currentQuestion['options'][$matches[1]] = preg_replace('/(\*|✓|\(correct\)|\[x\])$/i', '', $matches[2]);
                }
            }
        }
        // Check for "Answer:" or "Correct Answer:"
        elseif (preg_match('/^(Answer|Correct Answer|Ans\.?)\s*[:]?\s*([A-D])$/i', $line, $matches)) {
            if ($currentQuestion) {
                $currentQuestion['correct'] = strtoupper($matches[2]);
            }
        }
        // If we're in a question and line doesn't match patterns, append to question text
        elseif ($currentQuestion && empty($currentQuestion['options'])) {
            $currentQuestion['text'] .= " " . $line;
        }
    }

    // Add the last question
    if ($currentQuestion) {
        $questions[] = $currentQuestion;
    }

    return $questions;
}
