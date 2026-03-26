<?php
// student/profile.php
session_start();
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Check if student is logged in
if (!isStudentLoggedIn()) {
    redirect('../index.php', 'Please login to access your profile', 'warning');
}

$student_id = $_SESSION['student_id'];
$student_class = $_SESSION['class'];

// Check session timeout
checkSessionTimeout();

// Initialize variables
$success_msg = '';
$error_msg = '';
$current_password_error = '';

// Get current student data
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

// Get academic statistics - FIXED QUERY (no status column)
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT es.exam_id) as total_exams,
        AVG(r.percentage) as avg_score,
        MAX(r.percentage) as best_score,
        MIN(r.percentage) as worst_score,
        COUNT(DISTINCT CASE WHEN r.grade = 'A' OR r.grade = 'B' OR r.grade = 'C' THEN es.exam_id END) as passed_exams,
        COUNT(DISTINCT asub.assignment_id) as submitted_assignments
    FROM exam_sessions es 
    LEFT JOIN results r ON es.exam_id = r.exam_id AND es.student_id = r.student_id
    LEFT JOIN assignment_submissions asub ON es.student_id = asub.student_id
    WHERE es.student_id = ?
");
$stmt->execute([$student_id]);
$stats = $stmt->fetch();

// Get exam history - FIXED QUERY (using exam_sessions end_time to check completion)
$stmt = $pdo->prepare("
    SELECT e.exam_name, s.subject_name, e.exam_type, 
           r.total_score, r.percentage, r.grade, r.submitted_at,
           es.end_time
    FROM results r
    JOIN exams e ON r.exam_id = e.id
    JOIN subjects s ON e.subject_id = s.id
    JOIN exam_sessions es ON r.exam_id = es.exam_id AND r.student_id = es.student_id
    WHERE r.student_id = ?
    ORDER BY r.submitted_at DESC
    LIMIT 10
");
$stmt->execute([$student_id]);
$exam_history = $stmt->fetchAll();

// Get attendance/punctuality record (if available)
$stmt = $pdo->prepare("
    SELECT session, term, 
           AVG(CASE WHEN attendance = 'A' THEN 5 
                    WHEN attendance = 'B' THEN 4
                    WHEN attendance = 'C' THEN 3
                    WHEN attendance = 'D' THEN 2
                    WHEN attendance = 'E' THEN 1
                    ELSE 0 END) as attendance_score,
           AVG(CASE WHEN punctuality = 'A' THEN 5 
                    WHEN punctuality = 'B' THEN 4
                    WHEN punctuality = 'C' THEN 3
                    WHEN punctuality = 'D' THEN 2
                    WHEN punctuality = 'E' THEN 1
                    ELSE 0 END) as punctuality_score
    FROM affective_traits
    WHERE student_id = ?
    GROUP BY session, term
    ORDER BY session DESC, FIELD(term, 'Third', 'Second', 'First')
    LIMIT 3
");
$stmt->execute([$student_id]);
$behavior_records = $stmt->fetchAll();

// Get in-progress exams (exams with sessions but no results)
$stmt = $pdo->prepare("
    SELECT es.*, e.exam_name, s.subject_name
    FROM exam_sessions es
    JOIN exams e ON es.exam_id = e.id
    LEFT JOIN subjects s ON e.subject_id = s.id
    LEFT JOIN results r ON es.exam_id = r.exam_id AND es.student_id = r.student_id
    WHERE es.student_id = ? AND r.id IS NULL
    ORDER BY es.start_time DESC
    LIMIT 5
");
$stmt->execute([$student_id]);
$in_progress_exams = $stmt->fetchAll();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $full_name = sanitizeInput($_POST['full_name']);
        $email = sanitizeInput($_POST['email']);
        $phone = sanitizeInput($_POST['phone']);

        // Validate email if provided
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Please enter a valid email address";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE students SET full_name = ?, email = ?, phone = ? WHERE id = ?");
                $stmt->execute([$full_name, $email, $phone, $student_id]);

                // Update session
                $_SESSION['full_name'] = $full_name;

                // Log activity
                logActivity($pdo, $student_id, "Updated profile information", "student");

                $success_msg = "Profile updated successfully!";
            } catch (Exception $e) {
                $error_msg = "Error updating profile: " . $e->getMessage();
            }
        }
    } elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Validate passwords
        if (empty($current_password)) {
            $current_password_error = "Current password is required";
        } elseif (!password_verify($current_password, $student['password']) && $current_password !== $student['password']) {
            $current_password_error = "Current password is incorrect";
        } elseif (empty($new_password)) {
            $error_msg = "New password is required";
        } elseif (strlen($new_password) < 6) {
            $error_msg = "New password must be at least 6 characters";
        } elseif ($new_password !== $confirm_password) {
            $error_msg = "New passwords do not match";
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE students SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $student_id]);

                // Log activity
                logActivity($pdo, $student_id, "Changed password", "student");

                $success_msg = "Password changed successfully!";
            } catch (Exception $e) {
                $error_msg = "Error changing password: " . $e->getMessage();
            }
        }
    } elseif ($action === 'upload_photo') {
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $max_size = 2 * 1024 * 1024; // 2MB

            $file_type = $_FILES['profile_picture']['type'];
            $file_size = $_FILES['profile_picture']['size'];

            if (!in_array($file_type, $allowed_types)) {
                $error_msg = "Only JPG, PNG, and GIF files are allowed";
            } elseif ($file_size > $max_size) {
                $error_msg = "File size must be less than 2MB";
            } else {
                // Create upload directory if it doesn't exist
                $upload_dir = '../assets/uploads/profile-pics/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                // Generate unique filename
                $file_ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                $filename = 'student_' . $student_id . '_' . time() . '.' . $file_ext;
                $target_path = $upload_dir . $filename;

                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_path)) {
                    // Update database
                    $profile_pic_url = 'assets/uploads/profile-pics/' . $filename;
                    $stmt = $pdo->prepare("UPDATE students SET profile_picture = ? WHERE id = ?");
                    $stmt->execute([$profile_pic_url, $student_id]);

                    // Update session
                    $_SESSION['profile_picture'] = $profile_pic_url;

                    // Log activity
                    logActivity($pdo, $student_id, "Updated profile picture", "student");

                    $success_msg = "Profile picture updated successfully!";
                } else {
                    $error_msg = "Error uploading file";
                }
            }
        } else {
            $error_msg = "Please select a valid image file";
        }
    }
}

// Display header
echo displayHeader('My Profile');
?>

<div class="main-container">
    <!-- Welcome and Navigation -->
    <div style="margin-bottom: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; margin-bottom: 20px;">
            <div>
                <h1 style="color: <?php echo COLOR_PRIMARY; ?>; margin: 0;">My Profile</h1>
                <p style="color: #666; margin: 5px 0 0 0;">
                    Manage your account settings and view academic records
                </p>
            </div>
            <div>
                <a href="index.php" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>;">Back to Dashboard</a>
                <a href="../logout.php" class="btn" style="background: <?php echo COLOR_DANGER; ?>;">Logout</a>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php
    displayFlashMessage();
    if ($success_msg):
    ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- Profile Overview -->
    <div class="content-card">
        <div style="display: grid; grid-template-columns: auto 1fr; gap: 30px; align-items: start;">
            <!-- Profile Picture -->
            <div style="text-align: center;">
                <?php
                $profile_pic = $student['profile_picture'] ?? '../assets/images/default-avatar.jpg';
                if (!file_exists($profile_pic) || empty($student['profile_picture'])) {
                    $profile_pic = '../assets/images/default-avatar.jpg';
                }
                ?>
                <img src="<?php echo htmlspecialchars($profile_pic); ?>"
                    alt="Profile Picture"
                    style="width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 4px solid <?php echo COLOR_SECONDARY; ?>;">

                <!-- Photo Upload Form -->
                <form method="POST" enctype="multipart/form-data" style="margin-top: 15px;">
                    <input type="hidden" name="action" value="upload_photo">
                    <div style="margin-bottom: 10px;">
                        <input type="file" name="profile_picture" accept="image/*"
                            style="font-size: 14px; padding: 5px;">
                    </div>
                    <button type="submit" class="btn" style="background: <?php echo COLOR_WARNING; ?>; padding: 8px 15px; font-size: 14px;">
                        Update Photo
                    </button>
                </form>
                <p style="font-size: 12px; color: #666; margin-top: 5px;">
                    Max 2MB. JPG, PNG, GIF
                </p>
            </div>

            <!-- Student Information -->
            <div>
                <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin: 0 0 20px 0; border-bottom: 2px solid <?php echo COLOR_SECONDARY; ?>; padding-bottom: 10px;">
                    Personal Information
                </h2>

                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 25px;">
                    <div>
                        <p style="margin: 0 0 5px 0; color: #666; font-size: 0.9em;">Full Name</p>
                        <p style="margin: 0; font-weight: 600; font-size: 1.1em;"><?php echo htmlspecialchars($student['full_name']); ?></p>
                    </div>

                    <div>
                        <p style="margin: 0 0 5px 0; color: #666; font-size: 0.9em;">Admission Number</p>
                        <p style="margin: 0; font-weight: 600; font-size: 1.1em;"><?php echo htmlspecialchars($student['admission_number']); ?></p>
                    </div>

                    <div>
                        <p style="margin: 0 0 5px 0; color: #666; font-size: 0.9em;">Class</p>
                        <p style="margin: 0; font-weight: 600; font-size: 1.1em;"><?php echo htmlspecialchars($student['class']); ?></p>
                    </div>

                    <div>
                        <p style="margin: 0 0 5px 0; color: #666; font-size: 0.9em;">Status</p>
                        <p style="margin: 0;">
                            <span style="background: <?php echo $student['status'] === 'active' ? COLOR_SUCCESS : COLOR_WARNING; ?>; 
                                  color: white; padding: 3px 10px; border-radius: 15px; font-size: 0.9em;">
                                <?php echo ucfirst($student['status']); ?>
                            </span>
                        </p>
                    </div>

                    <div>
                        <p style="margin: 0 0 5px 0; color: #666; font-size: 0.9em;">Email</p>
                        <p style="margin: 0; font-weight: 600;"><?php echo htmlspecialchars($student['email'] ?? 'Not set'); ?></p>
                    </div>

                    <div>
                        <p style="margin: 0 0 5px 0; color: #666; font-size: 0.9em;">Phone</p>
                        <p style="margin: 0; font-weight: 600;"><?php echo htmlspecialchars($student['phone'] ?? 'Not set'); ?></p>
                    </div>
                </div>

                <p style="color: #666; font-size: 0.9em; margin: 0;">
                    <strong>Account Created:</strong> <?php echo formatDate($student['created_at']); ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
        <!-- Left Column: Academic Statistics -->
        <div class="content-card">
            <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Academic Statistics</h2>

            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div>
                        <p style="margin: 0 0 5px 0; color: #666;">Exams Taken</p>
                        <p style="margin: 0; font-size: 1.5em; font-weight: bold; color: <?php echo COLOR_PRIMARY; ?>;">
                            <?php echo $stats['total_exams'] ?? 0; ?>
                        </p>
                    </div>

                    <div>
                        <p style="margin: 0 0 5px 0; color: #666;">Average Score</p>
                        <p style="margin: 0; font-size: 1.5em; font-weight: bold; color: <?php echo COLOR_SUCCESS; ?>;">
                            <?php echo round($stats['avg_score'] ?? 0, 1); ?>%
                        </p>
                    </div>

                    <div>
                        <p style="margin: 0 0 5px 0; color: #666;">Best Score</p>
                        <p style="margin: 0; font-size: 1.5em; font-weight: bold; color: <?php echo COLOR_SUCCESS; ?>;">
                            <?php echo round($stats['best_score'] ?? 0, 1); ?>%
                        </p>
                    </div>

                    <div>
                        <p style="margin: 0 0 5px 0; color: #666;">Pass Rate</p>
                        <p style="margin: 0; font-size: 1.5em; font-weight: bold; color: <?php echo ($stats['total_exams'] ?? 0) > 0 ? COLOR_SUCCESS : COLOR_WARNING; ?>;">
                            <?php
                            $total_exams = $stats['total_exams'] ?? 0;
                            $passed_exams = $stats['passed_exams'] ?? 0;
                            $pass_rate = $total_exams > 0 ?
                                round(($passed_exams / $total_exams) * 100, 1) : 0;
                            echo $pass_rate; ?>%
                        </p>
                    </div>
                </div>
            </div>

            <?php if (!empty($behavior_records)): ?>
                <div style="margin-top: 25px;">
                    <h3 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 15px;">Behavior Records</h3>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: <?php echo COLOR_SECONDARY; ?>; color: white;">
                                    <th style="padding: 10px; text-align: left;">Session</th>
                                    <th style="padding: 10px; text-align: left;">Term</th>
                                    <th style="padding: 10px; text-align: left;">Attendance</th>
                                    <th style="padding: 10px; text-align: left;">Punctuality</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($behavior_records as $record): ?>
                                    <tr style="border-bottom: 1px solid #eee;">
                                        <td style="padding: 10px;"><?php echo htmlspecialchars($record['session']); ?></td>
                                        <td style="padding: 10px;"><?php echo htmlspecialchars($record['term']); ?></td>
                                        <td style="padding: 10px;">
                                            <?php
                                            $att_score = round($record['attendance_score'] ?? 0, 1);
                                            echo $att_score > 0 ? getGradeFromScore($att_score * 20) : 'N/A';
                                            ?>
                                        </td>
                                        <td style="padding: 10px;">
                                            <?php
                                            $punc_score = round($record['punctuality_score'] ?? 0, 1);
                                            echo $punc_score > 0 ? getGradeFromScore($punc_score * 20) : 'N/A';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- In-Progress Exams -->
            <?php if (!empty($in_progress_exams)): ?>
                <div style="margin-top: 25px;">
                    <h3 style="color: <?php echo COLOR_WARNING; ?>; margin-bottom: 15px;">In-Progress Exams</h3>
                    <div style="background: #fff8e1; padding: 15px; border-radius: 8px; border-left: 4px solid <?php echo COLOR_WARNING; ?>;">
                        <ul style="margin: 0; padding-left: 20px;">
                            <?php foreach ($in_progress_exams as $exam): ?>
                                <li style="margin-bottom: 8px;">
                                    <strong><?php echo htmlspecialchars($exam['exam_name']); ?></strong>
                                    (<?php echo htmlspecialchars($exam['subject_name'] ?? 'Multiple Subjects'); ?>)
                                    <br>
                                    <small>Started: <?php echo formatDate($exam['start_time'], 'M j, g:i A'); ?></small>
                                    <?php if ($exam['end_time'] && strtotime($exam['end_time']) > time()): ?>
                                        <br>
                                        <a href="take-exam.php?session_id=<?php echo $exam['id']; ?>" class="btn"
                                            style="background: <?php echo COLOR_WARNING; ?>; padding: 5px 10px; font-size: 12px; margin-top: 5px;">
                                            Continue Exam
                                        </a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right Column: Recent Exam History -->
        <div class="content-card">
            <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Recent Exam Results</h2>

            <?php if (!empty($exam_history)): ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: <?php echo COLOR_SECONDARY; ?>; color: white;">
                                <th style="padding: 10px; text-align: left;">Exam</th>
                                <th style="padding: 10px; text-align: left;">Subject</th>
                                <th style="padding: 10px; text-align: left;">Score</th>
                                <th style="padding: 10px; text-align: left;">Grade</th>
                                <th style="padding: 10px; text-align: left;">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($exam_history as $exam): ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 10px;"><?php echo htmlspecialchars($exam['exam_name']); ?></td>
                                    <td style="padding: 10px;"><?php echo htmlspecialchars($exam['subject_name']); ?></td>
                                    <td style="padding: 10px;">
                                        <span style="font-weight: bold; color: <?php echo getScoreColor($exam['percentage']); ?>;">
                                            <?php echo round($exam['percentage'] ?? 0, 1); ?>%
                                        </span>
                                    </td>
                                    <td style="padding: 10px;">
                                        <span style="background: <?php echo getGradeColor($exam['grade']); ?>; 
                                      color: white; padding: 3px 8px; border-radius: 15px; font-size: 0.9em;">
                                            <?php echo htmlspecialchars($exam['grade'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px; font-size: 0.9em;">
                                        <?php echo date('M j, Y', strtotime($exam['submitted_at'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: #666; font-style: italic; padding: 20px;">
                    No exam results yet. Take your first exam!
                </p>
            <?php endif; ?>

            <?php if (!empty($exam_history)): ?>
                <div style="margin-top: 20px; text-align: center;">
                    <a href="view-results.php" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>;">
                        View All Results
                    </a>
                </div>
            <?php endif; ?>

            <!-- Quick Stats -->
            <div style="margin-top: 25px; padding-top: 20px; border-top: 2px solid #eee;">
                <h3 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 15px; font-size: 1.1em;">Quick Stats</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div style="background: #f8f9fa; padding: 10px; border-radius: 8px;">
                        <p style="margin: 0 0 5px 0; color: #666; font-size: 0.9em;">Submissions</p>
                        <p style="margin: 0; font-weight: bold; color: <?php echo COLOR_PRIMARY; ?>;">
                            <?php echo $stats['submitted_assignments'] ?? 0; ?>
                        </p>
                    </div>
                    <div style="background: #f8f9fa; padding: 10px; border-radius: 8px;">
                        <p style="margin: 0 0 5px 0; color: #666; font-size: 0.9em;">Lowest Score</p>
                        <p style="margin: 0; font-weight: bold; color: <?php echo COLOR_DANGER; ?>;">
                            <?php echo round($stats['worst_score'] ?? 0, 1); ?>%
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile Update Forms -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
        <!-- Update Profile Information -->
        <div class="content-card">
            <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Update Profile</h2>

            <form method="POST" action="">
                <input type="hidden" name="action" value="update_profile">

                <div class="form-group">
                    <label class="form-label" for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control"
                        value="<?php echo htmlspecialchars($student['full_name']); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control"
                        value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>"
                        placeholder="Enter your email">
                </div>

                <div class="form-group">
                    <label class="form-label" for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control"
                        value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>"
                        placeholder="Enter your phone number">
                </div>

                <div style="margin-top: 25px;">
                    <button type="submit" class="btn" style="background: <?php echo COLOR_SUCCESS; ?>;">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>

        <!-- Change Password -->
        <div class="content-card">
            <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Change Password</h2>

            <form method="POST" action="">
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label class="form-label" for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                    <?php if ($current_password_error): ?>
                        <div style="color: <?php echo COLOR_DANGER; ?>; font-size: 0.9em; margin-top: 5px;">
                            <?php echo htmlspecialchars($current_password_error); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required>
                    <small style="color: #666;">At least 6 characters</small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                </div>

                <div style="margin-top: 25px;">
                    <button type="submit" class="btn" style="background: <?php echo COLOR_WARNING; ?>;">
                        Change Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Account Information -->
    <div class="content-card">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Account Information</h2>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid <?php echo COLOR_SUCCESS; ?>;">
                <h3 style="color: <?php echo COLOR_PRIMARY; ?>; margin: 0 0 10px 0; font-size: 1.1em;">Login History</h3>
                <p style="margin: 0; color: #666; font-size: 0.9em;">
                    Last login: <?php echo isset($_SESSION['login_time']) ? date('M j, Y g:i A', $_SESSION['login_time']) : 'Unknown'; ?>
                </p>
            </div>

            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid <?php echo COLOR_WARNING; ?>;">
                <h3 style="color: <?php echo COLOR_PRIMARY; ?>; margin: 0 0 10px 0; font-size: 1.1em;">Account Status</h3>
                <p style="margin: 0; color: #666; font-size: 0.9em;">
                    Status: <span style="font-weight: 600; color: <?php echo $student['status'] === 'active' ? COLOR_SUCCESS : COLOR_DANGER; ?>;">
                        <?php echo ucfirst($student['status']); ?>
                    </span>
                </p>
            </div>

            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid <?php echo COLOR_SECONDARY; ?>;">
                <h3 style="color: <?php echo COLOR_PRIMARY; ?>; margin: 0 0 10px 0; font-size: 1.1em;">Class Information</h3>
                <p style="margin: 0; color: #666; font-size: 0.9em;">
                    Current Class: <strong><?php echo htmlspecialchars($student['class']); ?></strong>
                </p>
            </div>
        </div>
    </div>

</div>

<?php
// Helper functions for display
function getScoreColor($score)
{
    if ($score >= 70) return COLOR_SUCCESS;
    if ($score >= 50) return COLOR_WARNING;
    return COLOR_DANGER;
}

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

function getGradeFromScore($score)
{
    if ($score >= 80) return 'A';
    if ($score >= 70) return 'B';
    if ($score >= 60) return 'C';
    if ($score >= 50) return 'D';
    if ($score >= 40) return 'E';
    return 'F';
}

// Display footer
echo displayFooter();
?>