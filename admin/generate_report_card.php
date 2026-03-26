<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Check if TCPDF exists
$use_pdf = false;
if (file_exists('../includes/tcpdf/tcpdf.php')) {
    require_once '../includes/tcpdf/tcpdf.php';
    $use_pdf = true;
} else {
    error_log("TCPDF not found at ../includes/tcpdf/tcpdf.php");
}

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit();
}

$student_id = $_GET['student_id'] ?? null;
$session = $_GET['session'] ?? date('Y') . '/' . (date('Y') + 1);
$term = $_GET['term'] ?? 'First';

if (!$student_id) {
    die("Student ID is required!");
}

// Get all data with proper PDO syntax
try {
    // Get student info with all new fields
    $stmt = $pdo->prepare("SELECT *, 
                           TIMESTAMPDIFF(YEAR, dob, CURDATE()) as age_years,
                           TIMESTAMPDIFF(MONTH, dob, CURDATE()) % 12 as age_months
                           FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        die("Student not found!");
    }

    // Get student scores with subject names
    $stmt = $pdo->prepare("
        SELECT ss.*, sub.subject_name 
        FROM student_scores ss 
        JOIN subjects sub ON ss.subject_id = sub.id 
        WHERE ss.student_id = ? AND ss.session = ? AND ss.term = ?
        ORDER BY sub.subject_name
    ");
    $stmt->execute([$student_id, $session, $term]);
    $scores = $stmt->fetchAll();

    // Calculate total marks and average
    $total_marks = 0;
    $total_percentage = 0;
    $subject_count = count($scores);

    foreach ($scores as $score) {
        $total_marks += $score['total_score'];
        $total_percentage += $score['percentage'];
    }

    $overall_average = $subject_count > 0 ? $total_percentage / $subject_count : 0;

    // Get class position
    $stmt = $pdo->prepare("SELECT * FROM student_positions WHERE student_id = ? AND session = ? AND term = ?");
    $stmt->execute([$student_id, $session, $term]);
    $position = $stmt->fetch();

    // Get total students in class
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM students WHERE class = ? AND status = 'active'");
    $stmt->execute([$student['class']]);
    $class_total = $stmt->fetch()['total'];

    // Get highest and lowest averages in class
    $stmt = $pdo->prepare("
        SELECT MAX(average) as highest, MIN(average) as lowest 
        FROM student_positions sp 
        JOIN students s ON sp.student_id = s.id 
        WHERE s.class = ? AND sp.session = ? AND sp.term = ? AND average > 0
    ");
    $stmt->execute([$student['class'], $session, $term]);
    $class_stats = $stmt->fetch();
    $highest_average = $class_stats['highest'] ?? 0;
    $lowest_average = $class_stats['lowest'] ?? 0;

    // Get comments with attendance data
    $stmt = $pdo->prepare("SELECT * FROM student_comments WHERE student_id = ? AND session = ? AND term = ?");
    $stmt->execute([$student_id, $session, $term]);
    $comments = $stmt->fetch();

    // Get affective traits
    $stmt = $pdo->prepare("SELECT * FROM affective_traits WHERE student_id = ? AND session = ? AND term = ?");
    $stmt->execute([$student_id, $session, $term]);
    $affective = $stmt->fetch();

    // Get psychomotor skills
    $stmt = $pdo->prepare("SELECT * FROM psychomotor_skills WHERE student_id = ? AND session = ? AND term = ?");
    $stmt->execute([$student_id, $session, $term]);
    $psychomotor = $stmt->fetch();

    // Get report card settings
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE session = ? AND term = ?");
    $stmt->execute([$session, $term]);
    $settings = $stmt->fetch();

    if (!$settings) {
        // Use default settings
        $settings = [
            'session' => $session,
            'term' => $term,
            'max_score' => 100,
            'score_types' => json_encode([
                ['name' => 'CA 1', 'max_score' => 10],
                ['name' => 'CA 2', 'max_score' => 10],
                ['name' => 'CA 3', 'max_score' => 10],
                ['name' => 'Exam', 'max_score' => 70]
            ]),
            'grading_system' => 'simple',
            'next_resumption_date' => null,
            'current_resumption_date' => null,
            'current_closing_date' => null,
            'days_school_opened' => 90
        ];
    }

    // Calculate attendance percentage
    $days_present = $comments['days_present'] ?? 0;
    $days_absent = $comments['days_absent'] ?? 0;
    $days_school_opened = $settings['days_school_opened'] ?? 90;
    $attendance_percentage = $days_school_opened > 0 ? round(($days_present / $days_school_opened) * 100, 1) : 0;

    // Calculate age for display
    $age_display = '';
    if ($student['dob']) {
        $age_years = floor((time() - strtotime($student['dob'])) / 31556926);
        $age_display = $age_years . 'yrs';
    }

    // Format gender for display
    $gender_display = '';
    if ($student['gender']) {
        $gender_labels = ['M' => 'M', 'F' => 'F', 'Other' => 'Other'];
        $gender_display = $gender_labels[$student['gender']] ?? $student['gender'];
    }
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Helper functions
function ordinal($number)
{
    if (!is_numeric($number)) return $number;

    $ends = array('th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th');
    if ((($number % 100) >= 11) && (($number % 100) <= 13))
        return $number . 'th';
    else
        return $number . $ends[$number % 10];
}

function getPerformanceRemark($percentage)
{
    if ($percentage >= 70) return 'Excellent';
    if ($percentage >= 60) return 'Very good';
    if ($percentage >= 50) return 'Good';
    if ($percentage >= 40) return 'Pass';
    if ($percentage >= 30) return 'Poor';
    return 'Fail';
}

function getGrade($percentage)
{
    if ($percentage >= 70) return 'A';
    if ($percentage >= 60) return 'B';
    if ($percentage >= 50) return 'C';
    if ($percentage >= 40) return 'D';
    if ($percentage >= 30) return 'E';
    return 'F';
}

function getRatingMeaning($rating)
{
    $meanings = [
        '5' => 'Maintains an excellent degree of observation',
        '4' => 'Maintains high level of observation trait',
        '3' => 'Acceptable level of observation trait',
        '2' => 'Shows minimal level of observation trait',
        '1' => 'Has no regard for observation trait'
    ];
    return $meanings[$rating] ?? '';
}

function convertGradeToRating($grade)
{
    $ratings = [
        'A' => '5',
        'B' => '4',
        'C' => '3',
        'D' => '2',
        'E' => '1',
        'F' => ''
    ];
    return $ratings[$grade] ?? '';
}

// Generate HTML content
$html = generateReportCardHTML($student, $scores, $position, $comments, $affective, $psychomotor, $settings, $total_marks, $overall_average, $class_total, $highest_average, $lowest_average, $days_present, $days_absent, $days_school_opened, $attendance_percentage, $age_display, $gender_display);

if ($use_pdf && class_exists('TCPDF') && isset($_GET['download']) && $_GET['download'] === 'pdf') {
    try {
        // Create PDF instance with A4 size
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('School Management System');
        $pdf->SetAuthor('School Management System');
        $pdf->SetTitle('Report Card - ' . $student['full_name']);
        $pdf->SetSubject('Student Report Card');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Add a page
        $pdf->AddPage();

        // Set font
        $pdf->SetFont('helvetica', '', 8);

        // Write HTML content
        $pdf->writeHTML($html, true, false, true, false, '');

        // Output PDF as download
        $filename = 'report_card_' . str_replace(' ', '_', $student['full_name']) . '_' . $session . '_' . $term . '.pdf';
        $pdf->Output($filename, 'D');
        exit;
    } catch (Exception $e) {
        // Fallback to HTML if PDF generation fails
        error_log("PDF Generation Error: " . $e->getMessage());
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }
} else {
    // Output HTML directly
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}

function generateReportCardHTML($student, $scores, $position, $comments, $affective, $psychomotor, $settings, $total_marks, $overall_average, $class_total, $highest_average, $lowest_average, $days_present, $days_absent, $days_school_opened, $attendance_percentage, $age_display, $gender_display)
{
    ob_start();
?>
    <!DOCTYPE html>
    <html>

    <head>
        <title>Report Card - <?= htmlspecialchars($student['full_name']) ?></title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 0;
                padding: 0;
                font-size: 8pt;
                background: white;
            }

            .container {
                width: 210mm;
                min-height: 297mm;
                margin: 0 auto;
                padding: 15px;
                box-sizing: border-box;
            }

            .header {
                text-align: center;
                margin-bottom: 15px;
                position: relative;
            }

            .school-name {
                font-size: 16pt;
                font-weight: bold;
                margin: 5px 0;
                color: #000;
            }

            .motto {
                font-size: 10pt;
                margin: 3px 0;
                color: #000;
            }

            .contact-info {
                font-size: 8pt;
                margin: 3px 0;
                color: #000;
            }

            .divider {
                border-top: 2px solid #000;
                margin: 10px 0;
            }

            .section-title {
                text-align: center;
                font-weight: bold;
                font-size: 11pt;
                margin: 10px 0;
                color: #000;
            }

            .info-table {
                width: 100%;
                border-collapse: collapse;
                margin: 10px 0;
                font-size: 8pt;
                border: 1px solid #000;
            }

            .info-table td {
                padding: 4px;
                border: 1px solid #000;
            }

            .info-table .label {
                font-weight: bold;
                background: #f0f0f0;
                width: 25%;
            }

            .scores-table {
                width: 100%;
                border-collapse: collapse;
                margin: 10px 0;
                font-size: 7pt;
                border: 1px solid #000;
            }

            .scores-table th,
            .scores-table td {
                border: 1px solid #000;
                padding: 3px;
                text-align: center;
            }

            .scores-table th {
                background: #e0e0e0;
                font-weight: bold;
            }

            .scores-table .subject-col {
                text-align: left;
                width: 15%;
                font-weight: bold;
            }

            .scores-table .grade-a {
                color: #006600;
                font-weight: bold;
            }

            .scores-table .grade-b {
                color: #339933;
                font-weight: bold;
            }

            .scores-table .grade-c {
                color: #666600;
                font-weight: bold;
            }

            .scores-table .grade-d {
                color: #996600;
                font-weight: bold;
            }

            .scores-table .grade-e {
                color: #cc6600;
                font-weight: bold;
            }

            .scores-table .grade-f {
                color: #cc0000;
                font-weight: bold;
            }

            .traits-table {
                width: 100%;
                border-collapse: collapse;
                margin: 10px 0;
                font-size: 7pt;
                border: 1px solid #000;
            }

            .traits-table td {
                border: 1px solid #000;
                padding: 3px;
                vertical-align: top;
            }

            .traits-table .section-header {
                background: #f0f0f0;
                font-weight: bold;
                text-align: center;
            }

            .rating-key {
                margin: 10px 0;
                font-size: 7pt;
                border: 1px solid #000;
                border-collapse: collapse;
            }

            .rating-key table {
                width: 100%;
                border-collapse: collapse;
            }

            .rating-key td,
            .rating-key th {
                border: 1px solid #000;
                padding: 3px;
                text-align: center;
            }

            .rating-key th {
                background: #e0e0e0;
                font-weight: bold;
            }

            .comments-section {
                margin: 15px 0;
                font-size: 8pt;
            }

            .comments-section div {
                margin: 8px 0;
                padding: 5px;
                border: 1px solid #000;
                min-height: 40px;
            }

            .footer {
                text-align: center;
                margin-top: 20px;
                font-size: 7pt;
                color: #000;
            }

            .no-print {
                text-align: center;
                margin: 20px 0;
            }

            .btn {
                background: #4a90e2;
                color: white;
                border: none;
                padding: 8px 15px;
                margin: 0 5px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 9pt;
                text-decoration: none;
                display: inline-block;
            }

            .performance-remark {
                font-weight: bold;
                color: #006600;
            }

            .compact-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 7pt;
                margin: 5px 0;
            }

            .compact-table td {
                padding: 2px;
                border: 1px solid #000;
            }

            .rating-circle {
                display: inline-block;
                width: 20px;
                height: 20px;
                line-height: 20px;
                border-radius: 50%;
                text-align: center;
                font-weight: bold;
                color: white;
                background: #666;
                font-size: 6pt;
            }

            .rating-5 {
                background: #006600;
            }

            .rating-4 {
                background: #339933;
            }

            .rating-3 {
                background: #999900;
            }

            .rating-2 {
                background: #cc6600;
            }

            .rating-1 {
                background: #cc0000;
            }

            @media print {
                .no-print {
                    display: none !important;
                }

                .container {
                    padding: 10px;
                }
            }
        </style>
    </head>

    <body>
        <div class="no-print">
            <button class="btn" onclick="window.print()">🖨️ Print Report Card</button>
            <a href="?student_id=<?= $student['id'] ?>&session=<?= $settings['session'] ?>&term=<?= $settings['term'] ?>&download=pdf" class="btn">📄 Download PDF</a>
            <button class="btn" onclick="window.close()">❌ Close</button>
        </div>

        <div class="container">
            <!-- School Header -->
            <div class="header">
                <div class="school-name">THE CLIMAX BRAINS ACADEMY, OTA</div>
                <div class="motto"><i>Raising Champions</i></div>
                <div class="contact-info">
                    Address: Ijaba Road, Adebisi, Iyesi Ota, Ogun state | Phone No: [Phone Number] | Email: theclimaxbrainsacademyota@gmail.com
                </div>
            </div>

            <div class="divider"></div>

            <!-- Term Information -->
            <div class="section-title"><?= strtoupper($settings['term']) ?> TERM <?= $settings['session'] ?></div>

            <!-- Student Information -->
            <table class="info-table">
                <tr>
                    <td class="label">Session</td>
                    <td><?= $settings['session'] ?></td>
                    <td class="label">Term</td>
                    <td><?= $settings['term'] ?></td>
                    <td class="label">Age</td>
                    <td><?= $age_display ?: 'N/A' ?></td>
                </tr>
                <tr>
                    <td class="label">Name of Student</td>
                    <td colspan="3"><strong><?= strtoupper(htmlspecialchars($student['full_name'])) ?></strong></td>
                    <td class="label">Reg. No</td>
                    <td><?= htmlspecialchars($student['admission_number']) ?></td>
                </tr>
                <tr>
                    <td class="label">Class</td>
                    <td><?= htmlspecialchars($student['class']) ?></td>
                    <td class="label">Next Term Begins</td>
                    <td><?= !empty($settings['next_resumption_date']) ? date('d-M-Y', strtotime($settings['next_resumption_date'])) : 'To be announced' ?></td>
                    <td class="label">Gender</td>
                    <td><?= $gender_display ?: 'N/A' ?></td>
                </tr>
            </table>

            <!-- Performance Summary -->
            <table class="info-table">
                <tr>
                    <td class="label">Position in Class</td>
                    <td><?= $position ? ordinal($position['class_position']) : 'N/A' ?></td>
                    <td class="label">No. of Students in Class</td>
                    <td><?= $class_total ?></td>
                    <td class="label">No. of Days School Opened</td>
                    <td><?= $days_school_opened ?></td>
                </tr>
                <tr>
                    <td class="label">Overall Total Score</td>
                    <td><?= number_format($total_marks, 1) ?></td>
                    <td class="label">Class Average Score</td>
                    <td><?= number_format($highest_average, 1) ?></td>
                    <td class="label">No. of Days Present</td>
                    <td><?= $days_present ?></td>
                </tr>
                <tr>
                    <td class="label">Student's Average Score</td>
                    <td><?= number_format($overall_average, 1) ?></td>
                    <td class="label">Lowest Average in Class</td>
                    <td><?= number_format($lowest_average, 1) ?></td>
                    <td class="label">No. of Days Absent</td>
                    <td><?= $days_absent ?></td>
                </tr>
                <tr>
                    <td class="label">Highest Average in Class</td>
                    <td><?= number_format($highest_average, 1) ?></td>
                    <td class="label">Overall Performance</td>
                    <td colspan="3" class="performance-remark"><?= getPerformanceRemark($overall_average) ?></td>
                </tr>
            </table>

            <div class="divider"></div>

            <!-- Academic Performance Table -->
            <div class="section-title">SUBJECT</div>

            <?php
            $score_types = json_decode($settings['score_types'], true);
            if (!$score_types || empty($score_types)) {
                $score_types = [
                    ['name' => 'CA 1', 'max_score' => 10],
                    ['name' => 'CA 2', 'max_score' => 10],
                    ['name' => 'CA 3', 'max_score' => 10],
                    ['name' => 'Exam', 'max_score' => 70]
                ];
            }
            ?>

            <table class="scores-table">
                <thead>
                    <tr>
                        <th rowspan="2" class="subject-col">SUBJECT</th>
                        <?php foreach ($score_types as $type): ?>
                            <th rowspan="2"><?= substr($type['name'], 0, 4) ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2">Total</th>
                        <th rowspan="2">%</th>
                        <th rowspan="2">Grade</th>
                        <th rowspan="2">Pos.</th>
                        <th rowspan="2">Class<br>Avg</th>
                        <th rowspan="2">Highest<br>Score</th>
                        <th rowspan="2">Lowest<br>Score</th>
                        <th rowspan="2">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $counter = 1;
                    foreach ($scores as $score):
                        $score_data = json_decode($score['score_data'], true);
                        $percentage = $score['percentage'];
                        $grade = $score['grade'] ?: getGrade($percentage);
                        $grade_class = 'grade-' . strtolower($grade);
                        $remark = getPerformanceRemark($percentage);
                    ?>
                        <tr>
                            <td class="subject-col"><?= $counter . '. ' . htmlspecialchars($score['subject_name']) ?></td>
                            <?php foreach ($score_types as $type): ?>
                                <td><?= $score_data[$type['name']] ?? 0 ?></td>
                            <?php endforeach; ?>
                            <td><strong><?= number_format($score['total_score'], 1) ?></strong></td>
                            <td><?= number_format($percentage, 1) ?></td>
                            <td class="<?= $grade_class ?>"><?= $grade ?></td>
                            <td><?= $score['subject_position'] ? ordinal($score['subject_position']) : '-' ?></td>
                            <td><?= number_format($percentage, 1) ?></td>
                            <td><?= number_format($percentage, 1) ?></td>
                            <td><?= number_format($percentage, 1) ?></td>
                            <td><?= $remark ?></td>
                        </tr>
                    <?php $counter++;
                    endforeach; ?>

                    <!-- Add empty rows for consistent formatting -->
                    <?php for ($i = count($scores) + 1; $i <= 15; $i++): ?>
                        <tr>
                            <td class="subject-col"><?= $i . '. ' ?></td>
                            <?php for ($j = 0; $j < count($score_types); $j++): ?>
                                <td></td>
                            <?php endfor; ?>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <div class="divider"></div>

            <!-- Affective Traits and Psychomotor Skills -->
            <table class="traits-table">
                <tr>
                    <td width="50%" class="section-header">AFFECTIVE TRAITS</td>
                    <td width="50%" class="section-header">PSYCHOMOTOR SKILLS</td>
                </tr>
                <tr>
                    <td>
                        <table class="compact-table">
                            <?php
                            $affective_traits_list = [
                                ['label' => 'Punctuality', 'key' => 'punctuality'],
                                ['label' => 'Mental Alertness', 'key' => ''],
                                ['label' => 'Behavior', 'key' => ''],
                                ['label' => 'Reliability', 'key' => 'reliability'],
                                ['label' => 'Attentiveness', 'key' => ''],
                                ['label' => 'Respect', 'key' => ''],
                                ['label' => 'Neatness', 'key' => 'neatness'],
                                ['label' => 'Politeness', 'key' => 'politeness'],
                                ['label' => 'Honesty', 'key' => 'honesty'],
                                ['label' => 'Relationship with staff', 'key' => ''],
                                ['label' => 'Relationship with students', 'key' => 'relationship'],
                                ['label' => 'Attitude to school', 'key' => ''],
                                ['label' => 'Spirit of teamwork', 'key' => ''],
                                ['label' => 'Initiatives', 'key' => ''],
                                ['label' => 'Organizational ability', 'key' => '']
                            ];

                            $chunks = array_chunk($affective_traits_list, ceil(count($affective_traits_list) / 3), true);
                            ?>

                            <?php foreach ($chunks as $chunk): ?>
                                <tr>
                                    <?php foreach ($chunk as $item):
                                        $rating = '';
                                        if ($item['key'] && $affective && isset($affective[$item['key']])) {
                                            $rating = $affective[$item['key']];
                                        }
                                        $rating_number = convertGradeToRating($rating);
                                    ?>
                                        <td style="border:none; padding:1px 3px; width:33%;">
                                            <?= $item['label'] ?>:
                                            <?php if ($rating): ?>
                                                <strong><?= $rating ?></strong>
                                                <?php if ($rating_number): ?>
                                                    <span class="rating-circle rating-<?= $rating_number ?>"><?= $rating_number ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <strong>-</strong>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </td>
                    <td>
                        <table class="compact-table">
                            <?php
                            $psychomotor_skills_list = [
                                ['label' => 'Handwriting', 'key' => 'handwriting'],
                                ['label' => 'Reading', 'key' => ''],
                                ['label' => 'Verbal fluency/Diction', 'key' => 'verbal_fluency'],
                                ['label' => 'Musical Skills', 'key' => 'musical_skills'],
                                ['label' => 'Creative arts', 'key' => 'drawing_painting'],
                                ['label' => 'Physical education', 'key' => 'sports'],
                                ['label' => 'General reasoning', 'key' => ''],
                                ['label' => 'Handling of Tools', 'key' => 'handling_tools']
                            ];

                            $skill_chunks = array_chunk($psychomotor_skills_list, ceil(count($psychomotor_skills_list) / 2), true);
                            ?>

                            <?php foreach ($skill_chunks as $chunk): ?>
                                <tr>
                                    <?php foreach ($chunk as $item):
                                        $rating = '';
                                        if ($item['key'] && $psychomotor && isset($psychomotor[$item['key']])) {
                                            $rating = $psychomotor[$item['key']];
                                        }
                                        $rating_number = convertGradeToRating($rating);
                                    ?>
                                        <td style="border:none; padding:1px 3px; width:50%;">
                                            <?= $item['label'] ?>:
                                            <?php if ($rating): ?>
                                                <strong><?= $rating ?></strong>
                                                <?php if ($rating_number): ?>
                                                    <span class="rating-circle rating-<?= $rating_number ?>"><?= $rating_number ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <strong>-</strong>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </td>
                </tr>
            </table>

            <!-- Rating Key -->
            <div class="rating-key">
                <table>
                    <tr>
                        <th>RATING</th>
                        <th>SCORE RANGE</th>
                        <th>GRADE</th>
                        <th>GRADE POINT</th>
                        <th>MEANING</th>
                    </tr>
                    <tr>
                        <td>5</td>
                        <td><?= getRatingMeaning('5') ?></td>
                        <td>A</td>
                        <td>≥70% ~ 100%</td>
                        <td>Excellent</td>
                    </tr>
                    <tr>
                        <td>4</td>
                        <td><?= getRatingMeaning('4') ?></td>
                        <td>B</td>
                        <td>≥60% ~ &lt;70%</td>
                        <td>Very Good</td>
                    </tr>
                    <tr>
                        <td>3</td>
                        <td><?= getRatingMeaning('3') ?></td>
                        <td>C</td>
                        <td>≥50% ~ &lt;60%</td>
                        <td>Good</td>
                    </tr>
                    <tr>
                        <td>2</td>
                        <td><?= getRatingMeaning('2') ?></td>
                        <td>D</td>
                        <td>≥40% ~ &lt;50%</td>
                        <td>Pass</td>
                    </tr>
                    <tr>
                        <td>1</td>
                        <td><?= getRatingMeaning('1') ?></td>
                        <td>E</td>
                        <td>≥30% ~ &lt;40%</td>
                        <td>Poor</td>
                    </tr>
                    <tr>
                        <td></td>
                        <td></td>
                        <td>F</td>
                        <td>0% ~ &lt;30%</td>
                        <td>Fail</td>
                    </tr>
                </table>
            </div>

            <!-- Comments Section -->
            <div class="comments-section">
                <div>
                    <strong>Class Teacher's Comment:</strong><br>
                    <?= nl2br(htmlspecialchars($comments['teachers_comment'] ?? 'No comment provided.')) ?>
                    <?php if ($comments && !empty($comments['class_teachers_name'])): ?>
                        <br><br><strong>Class Teacher:</strong> <?= htmlspecialchars($comments['class_teachers_name']) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <strong>Head Teacher's Report:</strong><br>
                    <?= nl2br(htmlspecialchars($comments['principals_comment'] ?? 'No comment provided.')) ?>
                    <?php if ($comments && !empty($comments['principals_name'])): ?>
                        <br><br><strong>Principal:</strong> <?= htmlspecialchars($comments['principals_name']) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <strong>Director's Report:</strong><br>
                    This is an average academic output. Work harder next term.
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <p><strong>Next Term Resumption Date:</strong> <?= !empty($settings['next_resumption_date']) ? date('F j, Y', strtotime($settings['next_resumption_date'])) : 'To be announced' ?></p>
                <p><strong>Current Term Dates:</strong>
                    <?= !empty($settings['current_resumption_date']) ? date('M j', strtotime($settings['current_resumption_date'])) : 'N/A' ?> -
                    <?= !empty($settings['current_closing_date']) ? date('M j, Y', strtotime($settings['current_closing_date'])) : 'N/A' ?>
                </p>
                <p><em>Generated on: <?= date('F j, Y \a\t g:i A') ?></em></p>
            </div>
        </div>

        <script>
            // Auto-print if print parameter is set
            if (window.location.search.includes('print=true')) {
                window.print();
            }

            // Add page break for printing
            window.onbeforeprint = function() {
                document.querySelector('.container').style.pageBreakInside = 'avoid';
            };
        </script>
    </body>

    </html>
<?php
    return ob_get_clean();
}
?>