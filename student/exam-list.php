<?php
// student/exam-list.php
session_start();
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Check if student is logged in
if (!isStudentLoggedIn()) {
    redirect('../index.php', 'Please login to view exams', 'warning');
}

$student_id = $_SESSION['student_id'];
$student_class = $_SESSION['class'];

// Check session timeout
checkSessionTimeout();

// Initialize variables
$search = $_GET['search'] ?? '';
$subject_filter = $_GET['subject'] ?? '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? 'available';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build base query for available exams
$available_conditions = ["e.class = :class", "e.is_active = TRUE"];
$available_params = [':class' => $student_class];

if ($search) {
    $available_conditions[] = "(e.exam_name LIKE :search OR s.subject_name LIKE :search)";
    $available_params[':search'] = "%$search%";
}

if ($subject_filter) {
    $available_conditions[] = "e.subject_id = :subject";
    $available_params[':subject'] = $subject_filter;
}

if ($type_filter) {
    $available_conditions[] = "e.exam_type = :type";
    $available_params[':type'] = $type_filter;
}

// Get available exams (not started or in-progress)
$available_query = "
    SELECT e.*, s.subject_name, 
           CASE 
               WHEN es.id IS NOT NULL AND r.id IS NULL THEN 'in_progress'
               WHEN r.id IS NOT NULL THEN 'completed'
               ELSE 'available'
           END as exam_status
    FROM exams e 
    LEFT JOIN subjects s ON e.subject_id = s.id 
    LEFT JOIN exam_sessions es ON e.id = es.exam_id AND es.student_id = :student_id
    LEFT JOIN results r ON e.id = r.exam_id AND r.student_id = :student_id
    WHERE " . implode(" AND ", $available_conditions) . "
    ORDER BY e.created_at DESC
    LIMIT :limit OFFSET :offset
";

// Count total available exams for pagination
$count_query = "
    SELECT COUNT(*) as total 
    FROM exams e 
    LEFT JOIN subjects s ON e.subject_id = s.id 
    WHERE " . implode(" AND ", $available_conditions);

try {
    // Get total count
    $stmt = $pdo->prepare($count_query);
    foreach ($available_params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $total_count = $stmt->fetch()['total'];
    $total_pages = ceil($total_count / $per_page);

    // Get exams with pagination
    $stmt = $pdo->prepare($available_query);
    $available_params[':student_id'] = $student_id;
    $available_params[':limit'] = $per_page;
    $available_params[':offset'] = $offset;

    foreach ($available_params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    $stmt->execute();
    $exams = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Exam list error: " . $e->getMessage());
    $exams = [];
    $total_count = 0;
    $total_pages = 1;
}

// Get completed exams for filter
$completed_exams = [];
try {
    $stmt = $pdo->prepare("
        SELECT e.*, s.subject_name, r.percentage, r.grade, r.submitted_at
        FROM exams e
        JOIN subjects s ON e.subject_id = s.id
        JOIN results r ON e.id = r.exam_id
        WHERE r.student_id = ? AND e.class = ?
        ORDER BY r.submitted_at DESC
    ");
    $stmt->execute([$student_id, $student_class]);
    $completed_exams = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Completed exams error: " . $e->getMessage());
}

// Get all subjects for filter
$subjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.id, s.subject_name 
        FROM subjects s 
        JOIN exams e ON s.id = e.subject_id 
        WHERE e.class = ? AND e.is_active = TRUE
        ORDER BY s.subject_name
    ");
    $stmt->execute([$student_class]);
    $subjects = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Subjects error: " . $e->getMessage());
}

// Get upcoming exams (not yet active)
$upcoming_exams = [];
try {
    $stmt = $pdo->prepare("
        SELECT e.*, s.subject_name 
        FROM exams e 
        JOIN subjects s ON e.subject_id = s.id 
        WHERE e.class = ? AND e.is_active = FALSE
        ORDER BY e.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$student_class]);
    $upcoming_exams = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Upcoming exams error: " . $e->getMessage());
}

// Get exam statistics
$stats = [
    'total_available' => 0,
    'total_completed' => count($completed_exams),
    'total_in_progress' => 0,
    'average_score' => 0,
    'best_score' => 0
];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT e.id) as total_available,
            AVG(r.percentage) as average_score,
            MAX(r.percentage) as best_score,
            COUNT(DISTINCT CASE WHEN es.id IS NOT NULL AND r.id IS NULL THEN e.id END) as total_in_progress
        FROM exams e 
        LEFT JOIN exam_sessions es ON e.id = es.exam_id AND es.student_id = ?
        LEFT JOIN results r ON e.id = r.exam_id AND r.student_id = ?
        WHERE e.class = ? AND e.is_active = TRUE
    ");
    $stmt->execute([$student_id, $student_id, $student_class]);
    $stats_result = $stmt->fetch();

    if ($stats_result) {
        $stats['total_available'] = $stats_result['total_available'] ?? 0;
        $stats['total_in_progress'] = $stats_result['total_in_progress'] ?? 0;
        $stats['average_score'] = round($stats_result['average_score'] ?? 0, 1);
        $stats['best_score'] = round($stats_result['best_score'] ?? 0, 1);
    }
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
}

// Display header
echo displayHeader('Exam List');
?>

<div class="main-container">
    <!-- Header and Navigation -->
    <div style="margin-bottom: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; margin-bottom: 20px;">
            <div>
                <h1 style="color: <?php echo COLOR_PRIMARY; ?>; margin: 0;">Exam Center</h1>
                <p style="color: #666; margin: 5px 0 0 0;">
                    Take exams, track progress, and view results
                </p>
            </div>
            <div>
                <a href="index.php" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>;">Back to Dashboard</a>
                <a href="view-result.php" class="btn" style="background: <?php echo COLOR_SUCCESS; ?>;">View Results</a>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php displayFlashMessage(); ?>

    <!-- Statistics Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div style="background: linear-gradient(135deg, #3498db, #2980b9); color: white; padding: 20px; border-radius: 10px; text-align: center;">
            <div style="font-size: 2em; font-weight: bold;"><?php echo $stats['total_available']; ?></div>
            <div style="font-size: 0.9em;">Available Exams</div>
        </div>

        <div style="background: linear-gradient(135deg, #27ae60, #229954); color: white; padding: 20px; border-radius: 10px; text-align: center;">
            <div style="font-size: 2em; font-weight: bold;"><?php echo $stats['total_completed']; ?></div>
            <div style="font-size: 0.9em;">Completed</div>
        </div>

        <div style="background: linear-gradient(135deg, #f39c12, #e67e22); color: white; padding: 20px; border-radius: 10px; text-align: center;">
            <div style="font-size: 2em; font-weight: bold;"><?php echo $stats['total_in_progress']; ?></div>
            <div style="font-size: 0.9em;">In Progress</div>
        </div>

        <div style="background: linear-gradient(135deg, #8e44ad, #9b59b6); color: white; padding: 20px; border-radius: 10px; text-align: center;">
            <div style="font-size: 2em; font-weight: bold;"><?php echo $stats['best_score']; ?>%</div>
            <div style="font-size: 0.9em;">Best Score</div>
        </div>
    </div>

    <!-- Search and Filter Section -->
    <div class="content-card" style="margin-bottom: 30px;">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Filter Exams</h2>

        <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="Exam name or subject..."
                    class="form-control">
            </div>

            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Subject</label>
                <select name="subject" class="form-control">
                    <option value="">All Subjects</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?php echo $subject['id']; ?>" <?php echo $subject_filter == $subject['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Exam Type</label>
                <select name="type" class="form-control">
                    <option value="">All Types</option>
                    <option value="objective" <?php echo $type_filter == 'objective' ? 'selected' : ''; ?>>Objective Only</option>
                    <option value="theory" <?php echo $type_filter == 'theory' ? 'selected' : ''; ?>>Theory Only</option>
                    <option value="subjective" <?php echo $type_filter == 'subjective' ? 'selected' : ''; ?>>Subjective Only</option>
                </select>
            </div>

            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Status</label>
                <select name="status" class="form-control">
                    <option value="available" <?php echo $status_filter == 'available' ? 'selected' : ''; ?>>Available</option>
                    <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="in_progress" <?php echo $status_filter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Exams</option>
                </select>
            </div>

            <div style="align-self: end;">
                <button type="submit" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>; width: 100%;">Filter</button>
            </div>

            <div style="align-self: end;">
                <a href="exam-list.php" class="btn" style="background: <?php echo COLOR_WARNING; ?>; width: 100%; text-align: center;">Reset</a>
            </div>
        </form>
    </div>

    <!-- Main Exams Table -->
    <div class="content-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin: 0;">Available Exams</h2>
            <div style="color: #666; font-size: 0.9em;">
                Showing <?php echo min($per_page, count($exams)); ?> of <?php echo $total_count; ?> exams
            </div>
        </div>

        <?php if (empty($exams)): ?>
            <div style="text-align: center; padding: 40px; color: #666;">
                <p style="font-size: 1.2em; margin-bottom: 10px;">No exams found matching your criteria.</p>
                <p>Try adjusting your filters or check back later for new exams.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: <?php echo COLOR_SECONDARY; ?>; color: white;">
                            <th style="padding: 15px; text-align: left;">Exam Name</th>
                            <th style="padding: 15px; text-align: left;">Subject</th>
                            <th style="padding: 15px; text-align: left;">Type</th>
                            <th style="padding: 15px; text-align: left;">Duration</th>
                            <th style="padding: 15px; text-align: left;">Questions</th>
                            <th style="padding: 15px; text-align: left;">Status</th>
                            <th style="padding: 15px; text-align: left;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exams as $exam):
                            $status_color = '';
                            $status_text = '';
                            $action_btn = '';
                            $action_url = '';

                            switch ($exam['exam_status']) {
                                case 'available':
                                    $status_color = '#27ae60';
                                    $status_text = 'Available';
                                    $action_btn = 'Start Exam';
                                    $action_url = "take-exam.php?exam_id={$exam['id']}";
                                    break;

                                case 'in_progress':
                                    $status_color = '#f39c12';
                                    $status_text = 'In Progress';
                                    $action_btn = 'Continue';
                                    $action_url = "take-exam.php?session_id={$exam['id']}";
                                    break;

                                case 'completed':
                                    $status_color = '#3498db';
                                    $status_text = 'Completed';
                                    $action_btn = 'View Result';
                                    $action_url = "view-result.php?exam_id={$exam['id']}";
                                    break;

                                default:
                                    $status_color = '#95a5a6';
                                    $status_text = 'Unknown';
                                    $action_btn = 'View';
                                    $action_url = "#";
                            }

                            // Get question count
                            $question_count = 0;
                            if ($exam['exam_type'] === 'objective') {
                                $question_count = $exam['objective_count'] ?? 0;
                            } elseif ($exam['exam_type'] === 'theory') {
                                $question_count = $exam['theory_count'] ?? 0;
                            } elseif ($exam['exam_type'] === 'subjective') {
                                $question_count = $exam['subjective_count'] ?? 0;
                            }
                        ?>
                            <tr style="border-bottom: 1px solid #eee; transition: background 0.3s;">
                                <td style="padding: 15px; font-weight: 600;"><?php echo htmlspecialchars($exam['exam_name']); ?></td>
                                <td style="padding: 15px;"><?php echo htmlspecialchars($exam['subject_name'] ?? 'N/A'); ?></td>
                                <td style="padding: 15px;">
                                    <span style="background: #e8f4fc; color: <?php echo COLOR_SECONDARY; ?>; 
                                  padding: 4px 10px; border-radius: 15px; font-size: 0.85em;">
                                        <?php echo ucfirst($exam['exam_type'] ?? 'objective'); ?>
                                    </span>
                                </td>
                                <td style="padding: 15px;"><?php echo htmlspecialchars($exam['duration_minutes'] ?? 0); ?> mins</td>
                                <td style="padding: 15px;"><?php echo $question_count; ?></td>
                                <td style="padding: 15px;">
                                    <span style="background: <?php echo $status_color; ?>; color: white; 
                                  padding: 5px 12px; border-radius: 15px; font-size: 0.85em; font-weight: 600;">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td style="padding: 15px;">
                                    <a href="<?php echo $action_url; ?>" class="btn"
                                        style="background: <?php echo $status_color; ?>; padding: 8px 15px; font-size: 0.9em;">
                                        <?php echo $action_btn; ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div style="margin-top: 30px; text-align: center;">
                    <div style="display: inline-flex; gap: 5px;">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&subject=<?php echo $subject_filter; ?>&type=<?php echo $type_filter; ?>&status=<?php echo $status_filter; ?>"
                                class="btn" style="background: <?php echo COLOR_SECONDARY; ?>; padding: 8px 15px;">← Previous</a>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&subject=<?php echo $subject_filter; ?>&type=<?php echo $type_filter; ?>&status=<?php echo $status_filter; ?>"
                                    class="btn <?php echo $i == $page ? 'active' : ''; ?>"
                                    style="background: <?php echo $i == $page ? COLOR_PRIMARY : COLOR_SECONDARY; ?>; 
                              padding: 8px 15px; min-width: 40px;">
                                    <?php echo $i; ?>
                                </a>
                            <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                <span style="padding: 8px 15px; color: #666;">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&subject=<?php echo $subject_filter; ?>&type=<?php echo $type_filter; ?>&status=<?php echo $status_filter; ?>"
                                class="btn" style="background: <?php echo COLOR_SECONDARY; ?>; padding: 8px 15px;">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Upcoming Exams -->
    <?php if (!empty($upcoming_exams)): ?>
        <div class="content-card" style="margin-top: 30px;">
            <h2 style="color: <?php echo COLOR_WARNING; ?>; margin-bottom: 20px;">📅 Upcoming Exams</h2>

            <div style="background: #fff8e1; padding: 20px; border-radius: 10px; border-left: 4px solid <?php echo COLOR_WARNING; ?>;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                    <?php foreach ($upcoming_exams as $exam): ?>
                        <div style="background: white; padding: 15px; border-radius: 8px; border: 1px solid #f1c40f;">
                            <h3 style="margin: 0 0 10px 0; color: <?php echo COLOR_PRIMARY; ?>; font-size: 1.1em;">
                                <?php echo htmlspecialchars($exam['exam_name']); ?>
                            </h3>
                            <p style="margin: 0 0 8px 0; color: #666; font-size: 0.9em;">
                                Subject: <?php echo htmlspecialchars($exam['subject_name']); ?>
                            </p>
                            <p style="margin: 0; color: #f39c12; font-size: 0.85em;">
                                <strong>Status:</strong> Coming Soon
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Completed Exams Section -->
    <?php if (!empty($completed_exams) && $status_filter !== 'completed'): ?>
        <div class="content-card" style="margin-top: 30px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="color: <?php echo COLOR_SUCCESS; ?>; margin: 0;">📊 Recently Completed Exams</h2>
                <a href="?status=completed" class="btn" style="background: <?php echo COLOR_SUCCESS; ?>;">View All Completed</a>
            </div>

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: <?php echo COLOR_SUCCESS; ?>; color: white;">
                            <th style="padding: 12px; text-align: left;">Exam</th>
                            <th style="padding: 12px; text-align: left;">Subject</th>
                            <th style="padding: 12px; text-align: left;">Score</th>
                            <th style="padding: 12px; text-align: left;">Grade</th>
                            <th style="padding: 12px; text-align: left;">Date Completed</th>
                            <th style="padding: 12px; text-align: left;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($completed_exams, 0, 5) as $exam): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px; font-weight: 600;"><?php echo htmlspecialchars($exam['exam_name']); ?></td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($exam['subject_name']); ?></td>
                                <td style="padding: 12px;">
                                    <span style="font-weight: bold; color: <?php echo getScoreColor($exam['percentage']); ?>;">
                                        <?php echo round($exam['percentage'], 1); ?>%
                                    </span>
                                </td>
                                <td style="padding: 12px;">
                                    <span style="background: <?php echo getGradeColor($exam['grade']); ?>; 
                                  color: white; padding: 4px 10px; border-radius: 15px; font-size: 0.85em; font-weight: 600;">
                                        <?php echo htmlspecialchars($exam['grade']); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px; font-size: 0.9em;">
                                    <?php echo date('M j, Y', strtotime($exam['submitted_at'])); ?>
                                </td>
                                <td style="padding: 12px;">
                                    <a href="view-result.php?exam_id=<?php echo $exam['id']; ?>" class="btn"
                                        style="background: <?php echo COLOR_SECONDARY; ?>; padding: 6px 12px; font-size: 0.85em;">
                                        View Details
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Quick Tips -->
    <div class="content-card" style="margin-top: 30px; background: #e8f4fc; border-left: 4px solid <?php echo COLOR_SECONDARY; ?>;">
        <h3 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 15px;">💡 Exam Tips</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
            <div style="background: white; padding: 15px; border-radius: 8px;">
                <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_SUCCESS; ?>; font-size: 1em;">Before Starting</h4>
                <ul style="margin: 0; padding-left: 20px; font-size: 0.9em; color: #666;">
                    <li>Ensure stable internet connection</li>
                    <li>Close unnecessary browser tabs</li>
                    <li>Have all required materials ready</li>
                </ul>
            </div>

            <div style="background: white; padding: 15px; border-radius: 8px;">
                <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_WARNING; ?>; font-size: 1em;">During Exam</h4>
                <ul style="margin: 0; padding-left: 20px; font-size: 0.9em; color: #666;">
                    <li>Monitor time remaining</li>
                    <li>Save answers regularly</li>
                    <li>Review before submitting</li>
                </ul>
            </div>

            <div style="background: white; padding: 15px; border-radius: 8px;">
                <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_DANGER; ?>; font-size: 1em;">Technical Issues</h4>
                <ul style="margin: 0; padding-left: 20px; font-size: 0.9em; color: #666;">
                    <li>Don't refresh the page</li>
                    <li>Contact admin if disconnected</li>
                    <li>Save your progress frequently</li>
                </ul>
            </div>
        </div>
    </div>

</div>

<?php
// Helper function for score color
function getScoreColor($score)
{
    if ($score >= 70) return COLOR_SUCCESS;
    if ($score >= 50) return COLOR_WARNING;
    return COLOR_DANGER;
}

// Helper function for grade color
function getGradeColor($grade)
{
    if (empty($grade)) return '#95a5a6';

    switch (strtoupper($grade)) {
        case 'A':
            return '#27ae60';
        case 'B':
            return '#2ecc71';
        case 'C':
            return '#f39c12';
        case 'D':
            return '#e67e22';
        case 'E':
            return '#e74c3c';
        case 'F':
            return '#c0392b';
        default:
            return '#95a5a6';
    }
}

// Display footer
echo displayFooter();
?>