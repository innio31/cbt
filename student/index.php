<?php
// student/index.php
session_start();
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';


// Check if student is logged in
if (!isStudentLoggedIn()) {
    redirect('../index.php', 'Please login to access the student dashboard', 'warning');
}

$student_id = $_SESSION['student_id'];
$student_class = $_SESSION['class'];

// Check session timeout
checkSessionTimeout();

// Get student details
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

// Get available single subject exams - CORRECTED: Remove status check
$stmt = $pdo->prepare("
    SELECT e.*, s.subject_name 
    FROM exams e 
    JOIN subjects s ON e.subject_id = s.id 
    WHERE e.class = ? AND e.is_active = TRUE AND e.exam_type = 'objective'
    AND e.id NOT IN (
        SELECT exam_id FROM exam_sessions 
        WHERE student_id = ?
    )
    ORDER BY e.created_at DESC
");
$stmt->execute([$student_class, $student_id]);
$available_exams = $stmt->fetchAll();

// Get in-progress exams - CORRECTED: Check based on end_time > NOW()
$stmt = $pdo->prepare("
    SELECT es.*, e.exam_name, s.subject_name, e.duration_minutes,
           TIMESTAMPDIFF(SECOND, NOW(), es.end_time) as time_remaining
    FROM exam_sessions es 
    JOIN exams e ON es.exam_id = e.id 
    LEFT JOIN subjects s ON e.subject_id = s.id 
    WHERE es.student_id = ? AND es.end_time > NOW()
    ORDER BY es.start_time DESC
");
$stmt->execute([$student_id]);
$in_progress_exams = $stmt->fetchAll();

// Get completed exams with scores - CORRECTED: Check based on end_time < NOW()
$stmt = $pdo->prepare("
    SELECT es.*, e.exam_name, s.subject_name, e.exam_type,
           r.objective_score, r.total_score, r.percentage, r.grade
    FROM exam_sessions es 
    JOIN exams e ON es.exam_id = e.id 
    LEFT JOIN subjects s ON e.subject_id = s.id 
    LEFT JOIN results r ON es.exam_id = r.exam_id AND es.student_id = r.student_id
    WHERE es.student_id = ? AND es.end_time <= NOW()
    ORDER BY es.end_time DESC
    LIMIT 10
");
$stmt->execute([$student_id]);
$completed_exams = $stmt->fetchAll();

// Get pending assignments
$stmt = $pdo->prepare("
    SELECT a.*, s.subject_name 
    FROM assignments a 
    JOIN subjects s ON a.subject_id = s.id 
    WHERE a.class = ? AND a.deadline >= CURDATE()
    AND a.id NOT IN (
        SELECT assignment_id FROM assignment_submissions 
        WHERE student_id = ?
    )
    ORDER BY a.deadline ASC
");
$stmt->execute([$student_class, $student_id]);
$pending_assignments = $stmt->fetchAll();

// Get submitted assignments
$stmt = $pdo->prepare("
    SELECT asub.*, a.title, a.subject_id, s.subject_name 
    FROM assignment_submissions asub 
    JOIN assignments a ON asub.assignment_id = a.id 
    JOIN subjects s ON a.subject_id = s.id 
    WHERE asub.student_id = ?
    ORDER BY asub.submitted_at DESC
    LIMIT 10
");
$stmt->execute([$student_id]);
$submitted_assignments = $stmt->fetchAll();

// Get recent library resources
$stmt = $pdo->prepare("
    SELECT * FROM library_resources 
    WHERE class = ? OR class = 'All'
    ORDER BY uploaded_at DESC 
    LIMIT 6
");
$stmt->execute([$student_class]);
$library_resources = $stmt->fetchAll();

// Get student statistics - CORRECTED: Use end_time for completed exams
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT es.exam_id) as total_exams_taken,
        AVG(r.percentage) as average_score,
        MAX(r.percentage) as best_score,
        COUNT(DISTINCT asub.assignment_id) as assignments_submitted
    FROM exam_sessions es 
    LEFT JOIN results r ON es.exam_id = r.exam_id AND es.student_id = r.student_id
    LEFT JOIN assignment_submissions asub ON es.student_id = asub.student_id
    WHERE es.student_id = ? AND es.end_time <= NOW()
");
$stmt->execute([$student_id]);
$stats = $stmt->fetch();

// Get recent activities
$stmt = $pdo->prepare("
    SELECT al.activity, al.created_at 
    FROM activity_logs al 
    WHERE al.user_id = ? AND al.user_type = 'student'
    ORDER BY al.created_at DESC 
    LIMIT 5
");
$stmt->execute([$student_id]);
$recent_activities = $stmt->fetchAll();

// Display header
echo displayHeader('Student Dashboard');
?>

<div class="main-container">
    <!-- Welcome Banner -->
    <div style="background: linear-gradient(135deg, <?php echo COLOR_SECONDARY; ?>, <?php echo COLOR_PRIMARY; ?>); 
                color: white; padding: 25px; border-radius: 15px; margin-bottom: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div>
                <h1 style="margin: 0 0 10px 0;">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h1>
                <p style="margin: 0; opacity: 0.9;">
                    <?php echo htmlspecialchars($_SESSION['admission_number']); ?> |
                    <?php echo htmlspecialchars($_SESSION['class']); ?>
                </p>
            </div>
            <div>
                <a href="../logout.php" class="btn btn-danger">Logout</a>
            </div>
        </div>
    </div>

    <!-- Display flash message -->
    <?php displayFlashMessage(); ?>

    <!-- Statistics Cards -->
    <div class="content-card">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px; border-bottom: 2px solid <?php echo COLOR_SECONDARY; ?>; padding-bottom: 10px;">
            Your Academic Statistics
        </h2>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
            <div style="background: linear-gradient(135deg, #3498db, #2c3e50); color: white; padding: 20px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2.5em; font-weight: bold;"><?php echo $stats['total_exams_taken'] ?? 0; ?></div>
                <div style="font-size: 0.9em; opacity: 0.9;">Exams Taken</div>
            </div>

            <div style="background: linear-gradient(135deg, #27ae60, #229954); color: white; padding: 20px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2.5em; font-weight: bold;">
                    <?php echo $stats['average_score'] ? round($stats['average_score'], 1) . '%' : 'N/A'; ?>
                </div>
                <div style="font-size: 0.9em; opacity: 0.9;">Average Score</div>
            </div>

            <div style="background: linear-gradient(135deg, #f39c12, #e67e22); color: white; padding: 20px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2.5em; font-weight: bold;">
                    <?php echo $stats['best_score'] ? round($stats['best_score'], 1) . '%' : 'N/A'; ?>
                </div>
                <div style="font-size: 0.9em; opacity: 0.9;">Best Score</div>
            </div>

            <div style="background: linear-gradient(135deg, #8e44ad, #9b59b6); color: white; padding: 20px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2.5em; font-weight: bold;"><?php echo $stats['assignments_submitted'] ?? 0; ?></div>
                <div style="font-size: 0.9em; opacity: 0.9;">Assignments</div>
            </div>
        </div>
    </div>

    <!-- In-Progress Exams -->
    <?php if (!empty($in_progress_exams)): ?>
        <div class="content-card">
            <h2 style="color: <?php echo COLOR_WARNING; ?>; margin-bottom: 20px;">
                ⚡ Continue Your Exams
            </h2>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                <?php foreach ($in_progress_exams as $exam):
                    $time_remaining = max(0, $exam['time_remaining']);
                ?>
                    <div style="background: linear-gradient(135deg, #f39c12, #e67e22); color: white; padding: 20px; border-radius: 10px;">
                        <h3 style="margin: 0 0 10px 0;"><?php echo htmlspecialchars($exam['exam_name']); ?></h3>
                        <p style="margin: 0 0 10px 0; opacity: 0.9;">
                            Subject: <?php echo htmlspecialchars($exam['subject_name'] ?? 'Multiple Subjects'); ?><br>
                            Time Left: <?php echo gmdate("H:i:s", $time_remaining); ?><br>
                            Started: <?php echo date('g:i A', strtotime($exam['start_time'])); ?>
                        </p>
                        <a href="take-exam.php?session_id=<?php echo $exam['id']; ?>" class="btn"
                            style="background: white; color: #e67e22; margin-right: 10px;">Continue Exam</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Available Exams -->
    <div class="content-card">
        <h2 style="color: <?php echo COLOR_SUCCESS; ?>; margin-bottom: 20px;">
            📝 Available Exams
        </h2>

        <?php if (!empty($available_exams)): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                <?php foreach ($available_exams as $exam): ?>
                    <div style="background: linear-gradient(135deg, #27ae60, #229954); color: white; padding: 20px; border-radius: 10px;">
                        <h3 style="margin: 0 0 10px 0;"><?php echo htmlspecialchars($exam['exam_name']); ?></h3>
                        <p style="margin: 0 0 10px 0; opacity: 0.9;">
                            Subject: <?php echo htmlspecialchars($exam['subject_name']); ?><br>
                            Duration: <?php echo htmlspecialchars($exam['duration_minutes']); ?> minutes<br>
                            Questions: <?php echo ($exam['objective_count'] ?? 0) + ($exam['theory_count'] ?? 0); ?>
                        </p>
                        <a href="take-exam.php?exam_id=<?php echo $exam['id']; ?>" class="btn"
                            style="background: white; color: #229954;">Start Exam</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="text-align: center; color: #666; font-style: italic;">No exams available at the moment.</p>
        <?php endif; ?>

        <div style="text-align: center; margin-top: 20px;">
            <a href="exam-list.php" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>;">View All Exams</a>
        </div>
    </div>

    <!-- Pending Assignments -->
    <div class="content-card">
        <h2 style="color: <?php echo COLOR_DANGER; ?>; margin-bottom: 20px;">
            📚 Pending Assignments
        </h2>

        <?php if (!empty($pending_assignments)): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                <?php foreach ($pending_assignments as $assignment):
                    $is_urgent = strtotime($assignment['deadline']) - time() < 2 * 24 * 60 * 60;
                ?>
                    <div style="background: linear-gradient(135deg, <?php echo $is_urgent ? '#e74c3c' : '#3498db'; ?>, <?php echo $is_urgent ? '#c0392b' : '#2c3e50'; ?>); 
                        color: white; padding: 20px; border-radius: 10px;">
                        <h3 style="margin: 0 0 10px 0;"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                        <p style="margin: 0 0 10px 0; opacity: 0.9;">
                            Subject: <?php echo htmlspecialchars($assignment['subject_name']); ?><br>
                            Deadline: <?php echo date('M j, Y g:i A', strtotime($assignment['deadline'])); ?><br>
                            <?php if ($assignment['max_marks']): ?>
                                Marks: <?php echo htmlspecialchars($assignment['max_marks']); ?><br>
                            <?php endif; ?>
                        </p>
                        <a href="assignment.php?id=<?php echo $assignment['id']; ?>" class="btn"
                            style="background: white; color: <?php echo $is_urgent ? '#c0392b' : '#2c3e50'; ?>;">
                            <?php echo $is_urgent ? 'Submit Urgently' : 'View Assignment'; ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="text-align: center; color: #666; font-style: italic;">No pending assignments. Great job!</p>
        <?php endif; ?>

        <div style="text-align: center; margin-top: 20px;">
            <a href="assignments.php" class="btn" style="background: <?php echo COLOR_DANGER; ?>;">View All Assignments</a>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="content-card">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Quick Actions</h2>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
            <a href="exam-list.php" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>;">
                <div style="font-size: 1.5em;">📝</div>
                <div>All Exams</div>
            </a>
            <a href="view-result.php" class="btn" style="background: <?php echo COLOR_SUCCESS; ?>;">
                <div style="font-size: 1.5em;">📊</div>
                <div>My Results</div>
            </a>
            <a href="library.php" class="btn" style="background: <?php echo COLOR_WARNING; ?>;">
                <div style="font-size: 1.5em;">📚</div>
                <div>E-Library</div>
            </a>
            <a href="assignments.php" class="btn" style="background: <?php echo COLOR_DANGER; ?>;">
                <div style="font-size: 1.5em;">📋</div>
                <div>Assignments</div>
            </a>
            <a href="profile.php" class="btn" style="background: #8e44ad;">
                <div style="font-size: 1.5em;">👤</div>
                <div>My Profile</div>
            </a>
            <a href="../logout.php" class="btn" style="background: #7f8c8d;">
                <div style="font-size: 1.5em;">🚪</div>
                <div>Logout</div>
            </a>
        </div>
    </div>

    <!-- Recent Activities -->
    <?php if (!empty($recent_activities)): ?>
        <div class="content-card">
            <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Recent Activities</h2>

            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                <?php foreach ($recent_activities as $activity): ?>
                    <div style="padding: 10px 0; border-bottom: 1px solid #eee; <?php echo $loop ? '' : 'border-top: 1px solid #eee;'; ?>">
                        <div style="font-weight: 500;"><?php echo htmlspecialchars($activity['activity']); ?></div>
                        <div style="font-size: 0.85em; color: #666;">
                            <?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Recent Library Resources -->
    <?php if (!empty($library_resources)): ?>
        <div class="content-card">
            <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Recent Learning Resources</h2>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                <?php foreach ($library_resources as $resource):
                    $icon = getFileIcon($resource['file_type']);
                ?>
                    <div style="border: 1px solid #ddd; border-radius: 8px; padding: 15px;">
                        <div style="font-size: 2em; margin-bottom: 10px;"><?php echo $icon; ?></div>
                        <h4 style="margin: 0 0 10px 0;"><?php echo htmlspecialchars($resource['title']); ?></h4>
                        <p style="margin: 0 0 10px 0; font-size: 0.9em; color: #666;">
                            <?php echo htmlspecialchars($resource['subject']); ?><br>
                            Type: <?php echo htmlspecialchars($resource['file_type']); ?>
                        </p>
                        <a href="library.php#resource-<?php echo $resource['id']; ?>" class="btn"
                            style="background: <?php echo COLOR_SECONDARY; ?>; padding: 8px 15px; font-size: 0.9em;">
                            View Details
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="text-align: center; margin-top: 20px;">
                <a href="library.php" class="btn" style="background: <?php echo COLOR_WARNING; ?>;">Browse Full Library</a>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php
// Helper function to get file icons
function getFileIcon($file_type)
{
    $icons = [
        'pdf' => '📕',
        'doc' => '📘',
        'docx' => '📘',
        'ppt' => '📙',
        'pptx' => '📙',
        'xls' => '📗',
        'xlsx' => '📗',
        'jpg' => '🖼️',
        'jpeg' => '🖼️',
        'png' => '🖼️',
        'mp4' => '🎬',
        'avi' => '🎬',
        'mp3' => '🎵',
        'txt' => '📄',
        'zip' => '📦',
        'rar' => '📦'
    ];

    $ext = strtolower(pathinfo($file_type, PATHINFO_EXTENSION));
    return $icons[$ext] ?? '📁';
}

// Display footer
echo displayFooter();
?>