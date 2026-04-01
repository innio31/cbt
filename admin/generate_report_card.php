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
            * {
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Arial, sans-serif;
                margin: 0;
                padding: 0;
                font-size: 9pt;
                /* Increased base size */
                line-height: 1.3;
                background: white;
            }

            .container {
                width: 210mm;
                height: 297mm;
                margin: 0 auto;
                padding: 8mm;
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }

            .header {
                text-align: center;
                margin-bottom: 5px;
            }

            .school-name {
                font-size: 18pt;
                /* Larger and bolder */
                font-weight: bold;
                margin: 0;
                color: #1a237e;
            }

            .motto {
                font-size: 10pt;
                margin: 2px 0;
                font-style: italic;
            }

            .divider {
                border-top: 2.5px solid #000;
                margin: 6px 0;
            }

            .section-title {
                text-align: center;
                font-weight: bold;
                font-size: 11pt;
                margin: 8px 0 4px 0;
                text-transform: uppercase;
                background-color: #f2f2f2;
                padding: 3px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 8px;
            }

            table,
            th,
            td {
                border: 1px solid #000;
            }

            td {
                padding: 4px 6px;
                /* Roomier padding */
            }

            .label {
                font-weight: bold;
                background: #f8f8f8;
                width: 18%;
            }

            /* Academic Scores Table - Enhanced Size */
            .scores-table {
                font-size: 9pt;
            }

            .scores-table th {
                background: #1a237e;
                color: white;
                padding: 6px;
                text-transform: uppercase;
                font-size: 8.5pt;
            }

            .subject-col {
                text-align: left;
                width: 28%;
                font-weight: bold;
            }

            /* Side-by-Side Traits Layout */
            .traits-row {
                display: flex;
                justify-content: space-between;
                gap: 15px;
                margin-top: 5px;
            }

            .traits-column {
                flex: 1;
            }

            .section-header {
                background: #3949ab;
                color: white;
                font-weight: bold;
                text-align: center;
                padding: 4px;
                margin-bottom: 0;
            }

            .compact-table {
                font-size: 8.5pt;
                margin-top: 0;
            }

            /* Rating Key */
            .rating-key {
                font-size: 8pt;
                text-align: center;
                margin: 5px 0;
            }

            /* Comments Section */
            .comments-section div {
                margin: 4px 0;
                padding: 8px;
                border: 1px solid #000;
                min-height: 40px;
            }

            .footer {
                text-align: center;
                font-size: 8.5pt;
                margin-top: auto;
                padding-top: 10px;
                border-top: 1px solid #ccc;
            }

            @media print {
                .no-print {
                    display: none !important;
                }

                .container {
                    padding: 5mm;
                    height: 100vh;
                }

                body {
                    -webkit-print-color-adjust: exact;
                }
            }
        </style>
    </head>

    <body>
        <div class="no-print" style="text-align:center;">
            <button class="btn" onclick="window.print()">🖨️ Print Report Card</button>
            <a href="?student_id=<?= $student['id'] ?>&session=<?= $settings['session'] ?>&term=<?= $settings['term'] ?>&download=pdf" class="btn">📄 Download PDF</a>
        </div>

        <div class="container">
            <div class="header">
                <div class="school-name"><?= defined('SCHOOL_NAME') ? htmlspecialchars(SCHOOL_NAME) : 'THE CLIMAX BRAINS ACADEMY, OTA' ?></div>
                <div class="motto"><i><?= defined('SCHOOL_MOTTO') ? htmlspecialchars(SCHOOL_MOTTO) : 'Raising Champions' ?></i></div>
                <div class="contact-info">
                    <?= htmlspecialchars(SCHOOL_ADDRESS ?? '') ?> | <?= htmlspecialchars(SCHOOL_PHONE ?? '') ?> | <?= htmlspecialchars(SCHOOL_EMAIL ?? '') ?>
                </div>
            </div>

            <div class="divider"></div>
            <div class="section-title"><?= strtoupper($settings['term']) ?> TERM <?= $settings['session'] ?></div>

            <table>
                <tr>
                    <td class="label">Session</td>
                    <td><?= $settings['session'] ?></td>
                    <td class="label">Term</td>
                    <td><?= $settings['term'] ?></td>
                    <td class="label">Age</td>
                    <td><?= $age_display ?: 'N/A' ?></td>
                </tr>
                <tr>
                    <td class="label">Student Name</td>
                    <td colspan="3"><strong><?= strtoupper(htmlspecialchars($student['full_name'])) ?></strong></td>
                    <td class="label">Reg. No</td>
                    <td><?= htmlspecialchars($student['admission_number']) ?></td>
                </tr>
                <tr>
                    <td class="label">Class</td>
                    <td><?= htmlspecialchars($student['class']) ?></td>
                    <td class="label">Resumption</td>
                    <td><?= !empty($settings['next_resumption_date']) ? date('d-M-Y', strtotime($settings['next_resumption_date'])) : 'TBA' ?></td>
                    <td class="label">Gender</td>
                    <td><?= $gender_display ?: 'N/A' ?></td>
                </tr>
            </table>

            <table>
                <tr>
                    <td class="label">Class Position</td>
                    <td><?= $position ? ordinal($position['class_position']) : 'N/A' ?></td>
                    <td class="label">Students in Class</td>
                    <td><?= $class_total ?></td>
                    <td class="label">Days Opened</td>
                    <td><?= $days_school_opened ?></td>
                </tr>
                <tr>
                    <td class="label">Total Score</td>
                    <td><?= number_format($total_marks, 1) ?></td>
                    <td class="label">Class Avg</td>
                    <td><?= number_format($highest_average, 1) ?></td>
                    <td class="label">Days Present</td>
                    <td><?= $days_present ?></td>
                </tr>
                <tr>
                    <td class="label">Student Avg</td>
                    <td><?= number_format($overall_average, 1) ?></td>
                    <td class="label">Overall Performance</td>
                    <td colspan="3" style="font-weight:bold; color:green;"><?= getPerformanceRemark($overall_average) ?></td>
                </tr>
            </table>

            <div class="section-title">Academic Performance</div>
            <table class="scores-table">
                <thead>
                    <tr>
                        <th class="subject-col">SUBJECT</th>
                        <?php
                        $score_types = json_decode($settings['score_types'], true) ?: [['name' => 'CA', 'max_score' => 30], ['name' => 'Exam', 'max_score' => 70]];
                        foreach ($score_types as $type): ?>
                            <th><?= substr($type['name'], 0, 4) ?></th>
                        <?php endforeach; ?>
                        <th>Total</th>
                        <th>%</th>
                        <th>Grade</th>
                        <th>Pos</th>
                        <th>Avg</th>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $counter = 1;
                    foreach ($scores as $score):
                        $score_data = json_decode($score['score_data'], true);
                        $total = $score['total_score_original'] ?? $score['total_score'];
                        $perc = $score['percentage'];
                        $grade = $score['grade'] ?: getGrade($perc);
                    ?>
                        <tr>
                            <td class="subject-col"><?= $counter++ . '. ' . htmlspecialchars($score['subject_name']) ?></td>
                            <?php foreach ($score_types as $type): ?>
                                <td><?= $score_data[$type['name']] ?? 0 ?></td>
                            <?php endforeach; ?>
                            <td><strong><?= number_format($total, 1) ?></strong></td>
                            <td><?= number_format($perc, 1) ?></td>
                            <td><?= $grade ?></td>
                            <td><?= $score['subject_position'] ? ordinal($score['subject_position']) : '-' ?></td>
                            <td><?= number_format($perc, 1) ?></td>
                            <td><?= getPerformanceRemark($perc) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php for ($i = count($scores) + 1; $i <= 15; $i++): ?>
                        <tr>
                            <td class="subject-col"><?= $i ?>. </td>
                            <?php foreach ($score_types as $type): ?><td></td><?php endforeach; ?>
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

            <div class="traits-row">
                <div class="traits-column">
                    <div class="section-header">AFFECTIVE TRAITS</div>
                    <table class="compact-table">
                        <?php
                        $affective_traits = [
                            ['Punctuality', 'punctuality'],
                            ['Neatness', 'neatness'],
                            ['Honesty', 'honesty'],
                            ['Reliability', 'reliability'],
                            ['Relationship', 'relationship'],
                            ['Politeness', 'politeness']
                        ];
                        foreach ($affective_traits as $item):
                            $val = $affective[$item[1]] ?? '';
                            $num = convertGradeToRating($val);
                        ?>
                            <tr>
                                <td><?= $item[0] ?></td>
                                <td><strong><?= $val ?: '-' ?></strong> <?php if ($num): ?><span class="rating-circle rating-<?= $num ?>"><?= $num ?></span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <div class="traits-column">
                    <div class="section-header">PSYCHOMOTOR SKILLS</div>
                    <table class="compact-table">
                        <?php
                        $psychomotor_list = [
                            ['Handwriting', 'handwriting'],
                            ['Reading', ''],
                            ['Fluency', 'verbal_fluency'],
                            ['Musical', 'musical_skills'],
                            ['Creative Arts', 'drawing_painting'],
                            ['Sports', 'sports']
                        ];
                        foreach ($psychomotor_list as $item):
                            $val = $psychomotor[$item[1]] ?? '';
                            $num = convertGradeToRating($val);
                        ?>
                            <tr>
                                <td><?= $item[0] ?></td>
                                <td><strong><?= $val ?: '-' ?></strong> <?php if ($num): ?><span class="rating-circle rating-<?= $num ?>"><?= $num ?></span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <table class="rating-key">
                <tr style="background:#f0f0f0; font-weight:bold;">
                    <td>5: Excellent</td>
                    <td>4: Very Good</td>
                    <td>3: Good</td>
                    <td>2: Pass</td>
                    <td>1: Poor</td>
                </tr>
            </table>

            <div class="comments-section">
                <div><strong>Teacher:</strong> <?= htmlspecialchars($comments['teachers_comment'] ?? 'No comment.') ?></div>
                <div><strong>Principal:</strong> <?= htmlspecialchars($comments['principals_comment'] ?? 'No comment.') ?></div>
                <div style="font-style: italic;"><strong>Director:</strong> This is an average academic output. Work harder next term.</div>
            </div>

            <div class="footer">
                <strong>Next Term Resumption Date:</strong> <?= !empty($settings['next_resumption_date']) ? date('F j, Y', strtotime($settings['next_resumption_date'])) : 'TBA' ?>
                <br><i>Generated on: <?= date('F j, Y \a\t g:i A') ?></i>
            </div>
        </div>
    </body>

    </html>
<?php
    return ob_get_clean();
}
?>