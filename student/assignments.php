<?php
// student/assignments.php - Assignment Management for Students
session_start();
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Check if student is logged in
if (!isStudentLoggedIn()) {
    redirect('../login.php', 'Please login to access assignments', 'warning');
}

$student_id = $_SESSION['student_id'];
$student_class = $_SESSION['class'];

// Check session timeout
checkSessionTimeout();

// Get filter parameters
$status = $_GET['status'] ?? 'all'; // all, pending, submitted, graded, late
$subject_id = $_GET['subject'] ?? '';
$sort = $_GET['sort'] ?? 'deadline_asc';

// Initialize search variables
$search_query = $_GET['search'] ?? '';
$search_subject = $_GET['search_subject'] ?? '';

// Process assignment submission if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_assignment'])) {
        $assignment_id = $_POST['assignment_id'] ?? '';
        $submitted_text = $_POST['submitted_text'] ?? '';

        // Validate assignment exists and belongs to student's class
        $stmt = $pdo->prepare("SELECT * FROM assignments WHERE id = ? AND class = ?");
        $stmt->execute([$assignment_id, $student_class]);
        $assignment = $stmt->fetch();

        if (!$assignment) {
            $_SESSION['flash_message'] = "Assignment not found or not accessible";
            $_SESSION['flash_type'] = "danger";
            header("Location: assignments.php");
            exit();
        }

        // Check if deadline has passed
        if (strtotime($assignment['deadline']) < time()) {
            $_SESSION['flash_message'] = "Assignment deadline has passed";
            $_SESSION['flash_type'] = "warning";
            header("Location: assignments.php");
            exit();
        }

        // Check if already submitted
        $stmt = $pdo->prepare("SELECT id FROM assignment_submissions WHERE student_id = ? AND assignment_id = ?");
        $stmt->execute([$student_id, $assignment_id]);
        if ($stmt->fetch()) {
            $_SESSION['flash_message'] = "You have already submitted this assignment";
            $_SESSION['flash_type'] = "warning";
            header("Location: assignments.php");
            exit();
        }

        // Handle file upload
        $file_path = null;
        if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_OK) {
            $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
            $file_name = $_FILES['assignment_file']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $file_size = $_FILES['assignment_file']['size'];

            // Validate file
            if (!in_array($file_ext, $allowed_types)) {
                $_SESSION['flash_message'] = "File type not allowed. Allowed types: " . implode(', ', $allowed_types);
                $_SESSION['flash_type'] = "danger";
                header("Location: assignments.php");
                exit();
            }

            if ($file_size > 10 * 1024 * 1024) { // 10MB limit
                $_SESSION['flash_message'] = "File size too large. Maximum size is 10MB";
                $_SESSION['flash_type'] = "danger";
                header("Location: assignments.php");
                exit();
            }

            // Generate unique filename
            $new_filename = 'assignment_' . $assignment_id . '_' . $student_id . '_' . time() . '.' . $file_ext;
            $upload_dir = '../assets/uploads/assignments/';

            // Create directory if not exists
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $upload_path = $upload_dir . $new_filename;

            if (move_uploaded_file($_FILES['assignment_file']['tmp_name'], $upload_path)) {
                $file_path = 'assignments/' . $new_filename;
            } else {
                $_SESSION['flash_message'] = "File upload failed";
                $_SESSION['flash_type'] = "danger";
                header("Location: assignments.php");
                exit();
            }
        }

        // Insert submission
        try {
            $stmt = $pdo->prepare("
                INSERT INTO assignment_submissions 
                (student_id, assignment_id, submitted_text, file_path, submitted_at, status) 
                VALUES (?, ?, ?, ?, NOW(), 'submitted')
            ");
            $stmt->execute([$student_id, $assignment_id, $submitted_text, $file_path]);

            $_SESSION['flash_message'] = "Assignment submitted successfully!";
            $_SESSION['flash_type'] = "success";
            header("Location: assignments.php");
            exit();
        } catch (Exception $e) {
            error_log("Assignment submission error: " . $e->getMessage());
            $_SESSION['flash_message'] = "Error submitting assignment. Please try again.";
            $_SESSION['flash_type'] = "danger";
            header("Location: assignments.php");
            exit();
        }
    }
}

// Get all assignments for student's class with filters
$where_conditions = ["a.class = ?"];
$params = [$student_class];

// Status filter
if ($status === 'pending') {
    $where_conditions[] = "a.deadline >= CURDATE()";
    $where_conditions[] = "asub.id IS NULL";
} elseif ($status === 'submitted') {
    $where_conditions[] = "asub.id IS NOT NULL AND asub.status = 'submitted'";
} elseif ($status === 'graded') {
    $where_conditions[] = "asub.id IS NOT NULL AND asub.status = 'graded'";
} elseif ($status === 'late') {
    $where_conditions[] = "a.deadline < CURDATE()";
    $where_conditions[] = "asub.id IS NULL";
}

// Subject filter
if ($subject_id && is_numeric($subject_id)) {
    $where_conditions[] = "a.subject_id = ?";
    $params[] = $subject_id;
}

// Search filter
if ($search_query) {
    $where_conditions[] = "(a.title LIKE ? OR a.instructions LIKE ?)";
    $search_term = "%$search_query%";
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($search_subject) {
    $where_conditions[] = "s.subject_name LIKE ?";
    $subject_term = "%$search_subject%";
    $params[] = $subject_term;
}

// Build WHERE clause
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Sort order
$order_by = "ORDER BY ";
switch ($sort) {
    case 'deadline_desc':
        $order_by .= "a.deadline DESC";
        break;
    case 'title_asc':
        $order_by .= "a.title ASC";
        break;
    case 'title_desc':
        $order_by .= "a.title DESC";
        break;
    case 'submitted_desc':
        $order_by .= "asub.submitted_at DESC";
        break;
    default: // deadline_asc
        $order_by .= "a.deadline ASC";
}

// Get assignments with submission status
$query = "
    SELECT 
        a.*,
        s.subject_name,
        s.id as subject_id,
        asub.id as submission_id,
        asub.submitted_at,
        asub.status as submission_status,
        asub.grade,
        asub.teacher_feedback,
        asub.graded_at,
        asub.file_path as submitted_file,
        DATEDIFF(a.deadline, CURDATE()) as days_until_deadline,
        CASE 
            WHEN asub.id IS NOT NULL THEN 'submitted'
            WHEN a.deadline < CURDATE() THEN 'late'
            ELSE 'pending'
        END as assignment_status
    FROM assignments a
    LEFT JOIN subjects s ON a.subject_id = s.id
    LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND asub.student_id = ?
    $where_clause
    $order_by
";

// Add student_id parameter for LEFT JOIN
array_unshift($params, $student_id);

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Get distinct subjects for filter dropdown
$stmt = $pdo->prepare("
    SELECT DISTINCT s.id, s.subject_name 
    FROM assignments a
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.class = ?
    ORDER BY s.subject_name
");
$stmt->execute([$student_class]);
$available_subjects = $stmt->fetchAll();

// Count assignments by status
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN a.deadline >= CURDATE() AND asub.id IS NULL THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN a.deadline < CURDATE() AND asub.id IS NULL THEN 1 ELSE 0 END) as late,
        SUM(CASE WHEN asub.id IS NOT NULL THEN 1 ELSE 0 END) as submitted,
        SUM(CASE WHEN asub.status = 'graded' THEN 1 ELSE 0 END) as graded
    FROM assignments a
    LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND asub.student_id = ?
    WHERE a.class = ?
";
$stmt = $pdo->prepare($stats_query);
$stmt->execute([$student_id, $student_class]);
$stats = $stmt->fetch();

// Display header
echo displayHeader('Assignments');
?>

<div class="main-container">
    <!-- Welcome Header -->
    <div style="background: linear-gradient(135deg, <?php echo COLOR_PRIMARY; ?>, <?php echo COLOR_SECONDARY; ?>); 
                color: white; padding: 25px; border-radius: 15px; margin-bottom: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div>
                <h1 style="margin: 0 0 10px 0;">📚 Assignments</h1>
                <p style="margin: 0; opacity: 0.9;">Manage your assignments and submissions</p>
            </div>
            <div>
                <a href="index.php" class="btn" style="background: rgba(255,255,255,0.2);">← Back to Dashboard</a>
            </div>
        </div>
    </div>

    <!-- Display flash message -->
    <?php displayFlashMessage(); ?>

    <!-- Statistics Cards -->
    <div class="content-card">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Assignment Overview</h2>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
            <div style="background: linear-gradient(135deg, #3498db, #2980b9); color: white; padding: 15px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2em; font-weight: bold;"><?php echo $stats['total'] ?? 0; ?></div>
                <div>Total Assignments</div>
            </div>

            <div style="background: linear-gradient(135deg, #2ecc71, #27ae60); color: white; padding: 15px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2em; font-weight: bold;"><?php echo $stats['submitted'] ?? 0; ?></div>
                <div>Submitted</div>
            </div>

            <div style="background: linear-gradient(135deg, #f39c12, #e67e22); color: white; padding: 15px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2em; font-weight: bold;"><?php echo $stats['pending'] ?? 0; ?></div>
                <div>Pending</div>
            </div>

            <div style="background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; padding: 15px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2em; font-weight: bold;"><?php echo $stats['late'] ?? 0; ?></div>
                <div>Overdue</div>
            </div>

            <div style="background: linear-gradient(135deg, #9b59b6, #8e44ad); color: white; padding: 15px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2em; font-weight: bold;"><?php echo $stats['graded'] ?? 0; ?></div>
                <div>Graded</div>
            </div>
        </div>
    </div>

    <!-- Filters and Search -->
    <div class="content-card">
        <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <!-- Status Filter -->
            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Status</label>
                <select name="status" class="form-control" onchange="this.form.submit()">
                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Assignments</option>
                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="submitted" <?php echo $status === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                    <option value="graded" <?php echo $status === 'graded' ? 'selected' : ''; ?>>Graded</option>
                    <option value="late" <?php echo $status === 'late' ? 'selected' : ''; ?>>Overdue</option>
                </select>
            </div>

            <!-- Subject Filter -->
            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Subject</label>
                <select name="subject" class="form-control" onchange="this.form.submit()">
                    <option value="">All Subjects</option>
                    <?php foreach ($available_subjects as $subject): ?>
                        <option value="<?php echo $subject['id']; ?>" <?php echo $subject_id == $subject['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Sort By -->
            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Sort By</label>
                <select name="sort" class="form-control" onchange="this.form.submit()">
                    <option value="deadline_asc" <?php echo $sort === 'deadline_asc' ? 'selected' : ''; ?>>Deadline (Earliest First)</option>
                    <option value="deadline_desc" <?php echo $sort === 'deadline_desc' ? 'selected' : ''; ?>>Deadline (Latest First)</option>
                    <option value="title_asc" <?php echo $sort === 'title_asc' ? 'selected' : ''; ?>>Title (A-Z)</option>
                    <option value="title_desc" <?php echo $sort === 'title_desc' ? 'selected' : ''; ?>>Title (Z-A)</option>
                    <option value="submitted_desc" <?php echo $sort === 'submitted_desc' ? 'selected' : ''; ?>>Recently Submitted</option>
                </select>
            </div>

            <!-- Search -->
            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Search assignments..."
                    value="<?php echo htmlspecialchars($search_query); ?>">
            </div>

            <!-- Subject Search -->
            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Search by Subject</label>
                <input type="text" name="search_subject" class="form-control" placeholder="Enter subject name..."
                    value="<?php echo htmlspecialchars($search_subject); ?>">
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; align-items: flex-end; gap: 10px;">
                <button type="submit" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>; padding: 12px 20px;">Apply Filters</button>
                <a href="assignments.php" class="btn" style="background: #95a5a6; padding: 12px 20px;">Clear All</a>
            </div>
        </form>
    </div>

    <!-- Assignments List -->
    <div class="content-card">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <span>Your Assignments</span>
            <span style="font-size: 0.8em; font-weight: normal; color: #666;">
                Showing <?php echo count($assignments); ?> assignments
            </span>
        </h2>

        <?php if (empty($assignments)): ?>
            <div style="text-align: center; padding: 40px; color: #666;">
                <div style="font-size: 3em; margin-bottom: 20px;">📭</div>
                <h3>No assignments found</h3>
                <p>There are no assignments matching your current filters.</p>
                <a href="assignments.php" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>; margin-top: 15px;">
                    View All Assignments
                </a>
            </div>
        <?php else: ?>
            <div style="display: grid; gap: 20px;">
                <?php foreach ($assignments as $assignment):
                    $is_pending = $assignment['assignment_status'] === 'pending';
                    $is_submitted = $assignment['assignment_status'] === 'submitted';
                    $is_late = $assignment['assignment_status'] === 'late';
                    $is_graded = $assignment['submission_status'] === 'graded';

                    // Determine card color based on status
                    if ($is_late) {
                        $card_color = 'linear-gradient(135deg, #e74c3c, #c0392b)';
                        $status_text = '⚠️ OVERDUE';
                    } elseif ($is_graded) {
                        $card_color = 'linear-gradient(135deg, #27ae60, #229954)';
                        $status_text = '✅ GRADED';
                    } elseif ($is_submitted) {
                        $card_color = 'linear-gradient(135deg, #3498db, #2980b9)';
                        $status_text = '📤 SUBMITTED';
                    } else {
                        $card_color = 'linear-gradient(135deg, #f39c12, #e67e22)';
                        $status_text = '📝 PENDING';
                    }

                    // Calculate time until deadline
                    $deadline = strtotime($assignment['deadline']);
                    $now = time();
                    $time_left = $deadline - $now;
                    $days_left = floor($time_left / (60 * 60 * 24));
                    $hours_left = floor(($time_left % (60 * 60 * 24)) / (60 * 60));

                    $time_text = '';
                    if ($time_left > 0) {
                        if ($days_left > 0) {
                            $time_text = "Due in $days_left day" . ($days_left != 1 ? 's' : '');
                        } else {
                            $time_text = "Due in $hours_left hour" . ($hours_left != 1 ? 's' : '');
                        }
                    } else {
                        $time_text = "Deadline passed " . abs($days_left) . " day" . (abs($days_left) != 1 ? 's' : '') . " ago";
                    }
                ?>

                    <div style="border-radius: 10px; overflow: hidden; border: 1px solid #e0e0e0;">
                        <!-- Assignment Header -->
                        <div style="background: <?php echo $card_color; ?>; color: white; padding: 15px 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                                <div>
                                    <h3 style="margin: 0 0 5px 0; font-size: 1.2em;"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                                    <p style="margin: 0; opacity: 0.9; font-size: 0.9em;">
                                        Subject: <?php echo htmlspecialchars($assignment['subject_name']); ?> •
                                        <?php echo $time_text; ?>
                                    </p>
                                </div>
                                <div style="background: rgba(255,255,255,0.2); padding: 5px 15px; border-radius: 20px; font-weight: bold;">
                                    <?php echo $status_text; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Assignment Body -->
                        <div style="padding: 20px; background: white;">
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                                <div>
                                    <p><strong>📅 Deadline:</strong> <?php echo date('F j, Y g:i A', strtotime($assignment['deadline'])); ?></p>
                                    <p><strong>📊 Max Marks:</strong> <?php echo $assignment['max_marks'] ? $assignment['max_marks'] . ' marks' : 'Not specified'; ?></p>
                                    <?php if ($assignment['file_path']): ?>
                                        <p><strong>📎 Attachment:</strong>
                                            <a href="../assets/uploads/<?php echo htmlspecialchars($assignment['file_path']); ?>"
                                                target="_blank" style="color: <?php echo COLOR_SECONDARY; ?>;">
                                                Download File
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <?php if ($is_submitted || $is_graded): ?>
                                        <p><strong>📤 Submitted:</strong> <?php echo date('F j, Y g:i A', strtotime($assignment['submitted_at'])); ?></p>
                                        <?php if ($assignment['submitted_file']): ?>
                                            <p><strong>📎 Your File:</strong>
                                                <a href="../assets/uploads/<?php echo htmlspecialchars($assignment['submitted_file']); ?>"
                                                    target="_blank" style="color: <?php echo COLOR_SECONDARY; ?>;">
                                                    View Submission
                                                </a>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($is_graded): ?>
                                            <p><strong>🎯 Grade:</strong> <span style="font-weight: bold; color: <?php echo COLOR_SUCCESS; ?>;">
                                                    <?php echo htmlspecialchars($assignment['grade']); ?>
                                                </span></p>
                                            <?php if ($assignment['teacher_feedback']): ?>
                                                <p><strong>💬 Feedback:</strong> <?php echo htmlspecialchars($assignment['teacher_feedback']); ?></p>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Instructions -->
                            <?php if ($assignment['instructions']): ?>
                                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                                    <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_PRIMARY; ?>;">Instructions</h4>
                                    <p style="margin: 0; white-space: pre-line;"><?php echo htmlspecialchars($assignment['instructions']); ?></p>
                                </div>
                            <?php endif; ?>

                            <!-- Action Buttons -->
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <?php if ($is_pending || $is_late): ?>
                                    <button onclick="openSubmissionModal(<?php echo $assignment['id']; ?>)"
                                        class="btn"
                                        style="background: <?php echo $is_late ? COLOR_DANGER : COLOR_SUCCESS; ?>;">
                                        <?php echo $is_late ? 'Submit Late' : 'Submit Assignment'; ?>
                                    </button>
                                <?php endif; ?>

                                <?php if ($is_submitted && !$is_graded): ?>
                                    <span style="padding: 10px 15px; background: #f8f9fa; border-radius: 5px; color: #666;">
                                        ⏳ Awaiting grading...
                                    </span>
                                <?php endif; ?>

                                <!-- View submission details -->
                                <?php if ($is_submitted || $is_graded): ?>
                                    <button onclick="viewSubmissionDetails(<?php echo $assignment['id']; ?>)"
                                        class="btn"
                                        style="background: <?php echo COLOR_SECONDARY; ?>;">
                                        View Submission Details
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Help Section -->
    <div class="content-card">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 15px;">Need Help?</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_PRIMARY; ?>;">📅 Deadline Information</h4>
                <p style="margin: 0; font-size: 0.9em; color: #666;">
                    • Assignments marked as "OVERDUE" can still be submitted<br>
                    • Submit before deadline for full consideration<br>
                    • Contact your teacher for deadline extensions
                </p>
            </div>

            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_PRIMARY; ?>;">📤 Submission Guidelines</h4>
                <p style="margin: 0; font-size: 0.9em; color: #666;">
                    • Accepted file types: PDF, DOC, DOCX, TXT, JPG, PNG<br>
                    • Maximum file size: 10MB<br>
                    • You can submit both text and file together
                </p>
            </div>

            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
                <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_PRIMARY; ?>;">📊 Grading Information</h4>
                <p style="margin: 0; font-size: 0.9em; color: #666;">
                    • Grades are typically available within 1-2 weeks<br>
                    • Check back regularly for updates<br>
                    • Contact teacher for grade inquiries
                </p>
            </div>
        </div>
    </div>

</div>

<!-- Submission Modal -->
<div id="submissionModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
     background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; width: 90%; max-width: 600px; border-radius: 15px; padding: 30px;">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Submit Assignment</h2>

        <form id="submissionForm" method="POST" enctype="multipart/form-data">
            <input type="hidden" id="modalAssignmentId" name="assignment_id" value="">

            <div class="form-group">
                <label class="form-label">Your Answer/Text Submission</label>
                <textarea name="submitted_text" class="form-control" rows="6"
                    placeholder="Type your answer here... (Optional if submitting file)"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Upload File (Optional)</label>
                <input type="file" name="assignment_file" class="form-control" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png">
                <small style="display: block; color: #666; margin-top: 5px;">
                    Accepted: PDF, DOC, DOCX, TXT, JPG, PNG (Max 10MB)
                </small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                <button type="button" onclick="closeModal()"
                    style="padding: 10px 20px; background: #95a5a6; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    Cancel
                </button>
                <button type="submit" name="submit_assignment"
                    style="padding: 10px 20px; background: <?php echo COLOR_SUCCESS; ?>; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    Submit Assignment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Submission Details Modal -->
<div id="detailsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
     background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; width: 90%; max-width: 700px; border-radius: 15px; padding: 30px; max-height: 80vh; overflow-y: auto;">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Submission Details</h2>
        <div id="detailsContent">
            <!-- Content will be loaded via AJAX -->
        </div>
        <div style="text-align: center; margin-top: 20px;">
            <button onclick="closeDetailsModal()"
                style="padding: 10px 20px; background: <?php echo COLOR_SECONDARY; ?>; color: white; border: none; border-radius: 5px; cursor: pointer;">
                Close
            </button>
        </div>
    </div>
</div>

<script>
    // Modal functions
    function openSubmissionModal(assignmentId) {
        document.getElementById('modalAssignmentId').value = assignmentId;
        document.getElementById('submissionModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('submissionModal').style.display = 'none';
    }

    function openDetailsModal() {
        document.getElementById('detailsModal').style.display = 'flex';
    }

    function closeDetailsModal() {
        document.getElementById('detailsModal').style.display = 'none';
    }

    // View submission details
    function viewSubmissionDetails(assignmentId) {
        // In a real implementation, you would fetch submission details via AJAX
        // For now, we'll redirect to a view page
        window.location.href = 'view-submission.php?id=' + assignmentId;
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
        const submissionModal = document.getElementById('submissionModal');
        const detailsModal = document.getElementById('detailsModal');

        if (event.target === submissionModal) {
            closeModal();
        }
        if (event.target === detailsModal) {
            closeDetailsModal();
        }
    }

    // Auto-refresh page every 5 minutes to check for new assignments
    setTimeout(() => {
        window.location.reload();
    }, 5 * 60 * 1000); // 5 minutes
</script>

<?php
echo displayFooter();
?>