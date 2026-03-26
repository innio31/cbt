<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit();
}

$current_session = date('Y') . '/' . (date('Y') + 1);
$current_term = 'First';

// Get classes
$classes = $pdo->query("SELECT DISTINCT class FROM students ORDER BY class")->fetchAll();

$selected_class = $_POST['class'] ?? '';
$selected_student_id = $_POST['student_id'] ?? '';
$students = [];
$selected_student = null;

if ($selected_class) {
    $stmt = $pdo->prepare("SELECT id, full_name, admission_number FROM students WHERE class = ? AND status = 'active' ORDER BY full_name");
    $stmt->execute([$selected_class]);
    $students = $stmt->fetchAll();

    // Get selected student details
    if ($selected_student_id) {
        foreach ($students as $student) {
            if ($student['id'] == $selected_student_id) {
                $selected_student = $student;
                break;
            }
        }
    }
}

// Get class teacher and principal names for the class (if already saved)
$class_teacher_name = '';
$principal_name = '';
if ($selected_class) {
    $stmt = $pdo->prepare("SELECT DISTINCT class_teachers_name, principals_name FROM student_comments WHERE student_id IN (SELECT id FROM students WHERE class = ?) AND session = ? AND term = ? LIMIT 1");
    $stmt->execute([$selected_class, $current_session, $current_term]);
    $existing_names = $stmt->fetch();
    if ($existing_names) {
        $class_teacher_name = $existing_names['class_teachers_name'] ?? '';
        $principal_name = $existing_names['principals_name'] ?? '';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_comments'])) {
    $session = $_POST['session'];
    $term = $_POST['term'];
    $class_teacher_name = $_POST['class_teacher_name'] ?? '';
    $principal_name = $_POST['principal_name'] ?? '';

    $success_count = 0;
    $error_count = 0;

    if ($selected_student_id) {
        try {
            // Save comments for selected student
            $teachers_comment = $_POST['teachers_comment'] ?? '';
            $principals_comment = $_POST['principals_comment'] ?? '';

            // Save attendance data
            $days_present = $_POST['days_present'] ?? 0;
            $days_absent = $_POST['days_absent'] ?? 0;

            // Check if comments already exist
            $stmt = $pdo->prepare("SELECT id FROM student_comments WHERE student_id = ? AND session = ? AND term = ?");
            $stmt->execute([$selected_student_id, $session, $term]);

            if ($stmt->fetch()) {
                // Update existing
                $stmt = $pdo->prepare("UPDATE student_comments SET 
                    teachers_comment = ?, principals_comment = ?, class_teachers_name = ?, principals_name = ?,
                    days_present = ?, days_absent = ?, updated_at = NOW()
                    WHERE student_id = ? AND session = ? AND term = ?");
                $stmt->execute([
                    $teachers_comment,
                    $principals_comment,
                    $class_teacher_name,
                    $principal_name,
                    $days_present,
                    $days_absent,
                    $selected_student_id,
                    $session,
                    $term
                ]);
            } else {
                // Insert new
                $stmt = $pdo->prepare("INSERT INTO student_comments 
                    (student_id, session, term, teachers_comment, principals_comment, class_teachers_name, principals_name, days_present, days_absent, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([
                    $selected_student_id,
                    $session,
                    $term,
                    $teachers_comment,
                    $principals_comment,
                    $class_teacher_name,
                    $principal_name,
                    $days_present,
                    $days_absent
                ]);
            }

            // Save affective traits if provided
            if (isset($_POST['affective'])) {
                $affective_data = $_POST['affective'];

                $stmt = $pdo->prepare("SELECT id FROM affective_traits WHERE student_id = ? AND session = ? AND term = ?");
                $stmt->execute([$selected_student_id, $session, $term]);

                if ($stmt->fetch()) {
                    // Update existing
                    $stmt = $pdo->prepare("UPDATE affective_traits SET 
                        punctuality = ?, attendance = ?, politeness = ?, honesty = ?, 
                        neatness = ?, reliability = ?, relationship = ?, self_control = ?
                        WHERE student_id = ? AND session = ? AND term = ?");
                    $stmt->execute([
                        $affective_data['punctuality'] ?? '',
                        $affective_data['attendance'] ?? '',
                        $affective_data['politeness'] ?? '',
                        $affective_data['honesty'] ?? '',
                        $affective_data['neatness'] ?? '',
                        $affective_data['reliability'] ?? '',
                        $affective_data['relationship'] ?? '',
                        $affective_data['self_control'] ?? '',
                        $selected_student_id,
                        $session,
                        $term
                    ]);
                } else {
                    // Insert new
                    $stmt = $pdo->prepare("INSERT INTO affective_traits 
                        (student_id, session, term, punctuality, attendance, politeness, honesty, 
                         neatness, reliability, relationship, self_control, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $selected_student_id,
                        $session,
                        $term,
                        $affective_data['punctuality'] ?? '',
                        $affective_data['attendance'] ?? '',
                        $affective_data['politeness'] ?? '',
                        $affective_data['honesty'] ?? '',
                        $affective_data['neatness'] ?? '',
                        $affective_data['reliability'] ?? '',
                        $affective_data['relationship'] ?? '',
                        $affective_data['self_control'] ?? ''
                    ]);
                }
            }

            // Save psychomotor skills if provided
            if (isset($_POST['psychomotor'])) {
                $psychomotor_data = $_POST['psychomotor'];

                $stmt = $pdo->prepare("SELECT id FROM psychomotor_skills WHERE student_id = ? AND session = ? AND term = ?");
                $stmt->execute([$selected_student_id, $session, $term]);

                if ($stmt->fetch()) {
                    // Update existing
                    $stmt = $pdo->prepare("UPDATE psychomotor_skills SET 
                        handwriting = ?, verbal_fluency = ?, sports = ?, handling_tools = ?, 
                        drawing_painting = ?, musical_skills = ?, updated_at = NOW()
                        WHERE student_id = ? AND session = ? AND term = ?");
                    $stmt->execute([
                        $psychomotor_data['handwriting'] ?? '',
                        $psychomotor_data['verbal_fluency'] ?? '',
                        $psychomotor_data['sports'] ?? '',
                        $psychomotor_data['handling_tools'] ?? '',
                        $psychomotor_data['drawing_painting'] ?? '',
                        $psychomotor_data['musical_skills'] ?? '',
                        $selected_student_id,
                        $session,
                        $term
                    ]);
                } else {
                    // Insert new
                    $stmt = $pdo->prepare("INSERT INTO psychomotor_skills 
                        (student_id, session, term, handwriting, verbal_fluency, sports, 
                         handling_tools, drawing_painting, musical_skills, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([
                        $selected_student_id,
                        $session,
                        $term,
                        $psychomotor_data['handwriting'] ?? '',
                        $psychomotor_data['verbal_fluency'] ?? '',
                        $psychomotor_data['sports'] ?? '',
                        $psychomotor_data['handling_tools'] ?? '',
                        $psychomotor_data['drawing_painting'] ?? '',
                        $psychomotor_data['musical_skills'] ?? ''
                    ]);
                }
            }

            $success_count = 1;
            $message = "Successfully saved comments and traits for " . ($selected_student ? $selected_student['full_name'] : 'student') . "!";
            $message_type = "success";
        } catch (Exception $e) {
            $error_count = 1;
            $message = "Error saving comments: " . $e->getMessage();
            $message_type = "error";
            error_log("Error saving comments for student $selected_student_id: " . $e->getMessage());
        }
    } else {
        $message = "Please select a student first!";
        $message_type = "warning";
    }
}

// Function to get existing data
function getStudentComments($pdo, $student_id, $session, $term)
{
    $stmt = $pdo->prepare("SELECT * FROM student_comments WHERE student_id = ? AND session = ? AND term = ?");
    $stmt->execute([$student_id, $session, $term]);
    return $stmt->fetch();
}

function getAffectiveTraits($pdo, $student_id, $session, $term)
{
    $stmt = $pdo->prepare("SELECT * FROM affective_traits WHERE student_id = ? AND session = ? AND term = ?");
    $stmt->execute([$student_id, $session, $term]);
    return $stmt->fetch();
}

function getPsychomotorSkills($pdo, $student_id, $session, $term)
{
    $stmt = $pdo->prepare("SELECT * FROM psychomotor_skills WHERE student_id = ? AND session = ? AND term = ?");
    $stmt->execute([$student_id, $session, $term]);
    return $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enter Comments & Traits - <?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School Management System'; ?></title>

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
            max-width: 1200px;
            margin: 0 auto;
        }

        .settings-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .settings-card h2 {
            color: var(--primary-color);
            margin-bottom: 25px;
            text-align: center;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .form-section {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .form-section h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section:last-child {
            border-bottom: none;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
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
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .btn {
            background: linear-gradient(135deg, var(--secondary-color), #2980b9);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(52, 152, 219, 0.2);
        }

        .btn:active {
            transform: translateY(-1px);
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

        .alert i {
            font-size: 1.2rem;
        }

        .student-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .student-info h3 {
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .student-info p {
            color: #666;
            margin-bottom: 5px;
        }

        .traits-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .trait-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .trait-item label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 0.95rem;
        }

        .rating-select {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            background: white;
        }

        .attendance-section {
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }

        .attendance-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .attendance-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .attendance-item label {
            font-size: 0.95rem;
            color: #555;
            margin-bottom: 10px;
            display: block;
        }

        .attendance-item input {
            font-size: 1.2rem;
            font-weight: 600;
            text-align: center;
        }

        .attendance-summary {
            text-align: center;
            padding: 15px;
            background: #f1f8e9;
            border-radius: 8px;
            margin-top: 15px;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .staff-names-section {
            background: #fff3e0;
            border: 1px solid #ffcc80;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }

        .staff-names-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .note {
            font-size: 0.85rem;
            color: #666;
            font-style: italic;
            margin-top: 5px;
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
            margin-top: 20px;
        }

        .back-link:hover {
            background: rgba(52, 152, 219, 0.1);
            transform: translateX(-5px);
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

            .form-grid,
            .traits-grid,
            .attendance-grid,
            .staff-names-grid {
                grid-template-columns: 1fr;
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
                    <p>Admin Panel</p>
                </div>
            </div>

            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
                <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
                <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
                <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
                <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="report_card_dashboard.php"><i class="fas fa-cog"></i> Report Card Dashboard</a></li>
                <li><a href="enter_comments.php" class="active"><i class="fas fa-comment"></i> Enter Comments</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-comment-dots"></i> Enter Comments & Traits</h1>
                <p>Add comments, attendance, and behavioral ratings for students</p>
            </div>
        </div>

        <div class="container">
            <div class="settings-card">
                <?php if (isset($message)): ?>
                    <div class="alert alert-<?php echo $message_type; ?>">
                        <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : ($message_type === 'warning' ? 'exclamation-circle' : 'check-circle'); ?>"></i>
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <h2><i class="fas fa-user-edit"></i> Student Comments & Traits</h2>

                <form method="POST" id="commentsForm">
                    <!-- Selection Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-search"></i> Select Student</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="class">Select Class:</label>
                                <select name="class" id="class" required onchange="this.form.submit()">
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?= $class['class'] ?>" <?= $selected_class == $class['class'] ? 'selected' : '' ?>>
                                            <?= $class['class'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if ($selected_class): ?>
                                <div class="form-group">
                                    <label for="student_id">Select Student:</label>
                                    <select name="student_id" id="student_id" required onchange="this.form.submit()">
                                        <option value="">Select Student</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?= $student['id'] ?>" <?= $selected_student_id == $student['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($student['full_name']) ?> (<?= htmlspecialchars($student['admission_number']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="session">Academic Session:</label>
                                    <input type="text" name="session" value="<?= $current_session ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="term">Term:</label>
                                    <select name="term" required>
                                        <option value="First" selected>First Term</option>
                                        <option value="Second">Second Term</option>
                                        <option value="Third">Third Term</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($selected_student):
                        $existing_comments = getStudentComments($pdo, $selected_student_id, $current_session, $current_term);
                        $existing_affective = getAffectiveTraits($pdo, $selected_student_id, $current_session, $current_term);
                        $existing_psychomotor = getPsychomotorSkills($pdo, $selected_student_id, $current_session, $current_term);
                    ?>
                        <!-- Student Information -->
                        <div class="student-info">
                            <h3><?= htmlspecialchars($selected_student['full_name']) ?></h3>
                            <p><strong>Admission Number:</strong> <?= htmlspecialchars($selected_student['admission_number']) ?></p>
                            <p><strong>Class:</strong> <?= htmlspecialchars($selected_class) ?></p>
                        </div>

                        <!-- Staff Names Section -->
                        <div class="form-section">
                            <h3><i class="fas fa-chalkboard-teacher"></i> Staff Information</h3>
                            <div class="staff-names-section">
                                <div class="staff-names-grid">
                                    <div class="form-group">
                                        <label>Class Teacher's Name:</label>
                                        <input type="text" name="class_teacher_name"
                                            value="<?= htmlspecialchars($class_teacher_name) ?>"
                                            placeholder="Enter Class Teacher's Name" required>
                                        <p class="note">Will be applied to all students in <?= htmlspecialchars($selected_class) ?> class</p>
                                    </div>
                                    <div class="form-group">
                                        <label>Principal's Name:</label>
                                        <input type="text" name="principal_name"
                                            value="<?= htmlspecialchars($principal_name) ?>"
                                            placeholder="Enter Principal's Name" required>
                                        <p class="note">Will be applied to all students in <?= htmlspecialchars($selected_class) ?> class</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Attendance Section -->
                        <div class="form-section">
                            <h3><i class="fas fa-calendar-check"></i> Attendance</h3>
                            <div class="attendance-section">
                                <div class="attendance-grid">
                                    <div class="attendance-item">
                                        <label>Days Present:</label>
                                        <input type="number" name="days_present"
                                            value="<?= $existing_comments['days_present'] ?? 0 ?>"
                                            min="0" max="365" required>
                                    </div>
                                    <div class="attendance-item">
                                        <label>Days Absent:</label>
                                        <input type="number" name="days_absent"
                                            value="<?= $existing_comments['days_absent'] ?? 0 ?>"
                                            min="0" max="365" required>
                                    </div>
                                </div>
                                <?php
                                $days_present = $existing_comments['days_present'] ?? 0;
                                $days_absent = $existing_comments['days_absent'] ?? 0;
                                $total_days = $days_present + $days_absent;
                                $attendance_rate = $total_days > 0 ? round(($days_present / $total_days) * 100) : 0;
                                ?>
                                <div class="attendance-summary" id="attendance-summary">
                                    Total Days: <?= $total_days ?> | Attendance Rate: <?= $attendance_rate ?>%
                                </div>
                            </div>
                        </div>

                        <!-- Comments Section -->
                        <div class="form-section">
                            <h3><i class="fas fa-comments"></i> Comments</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Teacher's Comment:</label>
                                    <textarea name="teachers_comment" placeholder="Enter teacher's comment..." rows="4"><?= $existing_comments['teachers_comment'] ?? '' ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Principal's Comment:</label>
                                    <textarea name="principals_comment" placeholder="Enter principal's comment..." rows="4"><?= $existing_comments['principals_comment'] ?? '' ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Affective Traits -->
                        <div class="form-section">
                            <h3><i class="fas fa-heart"></i> Affective Traits (Rate A-E)</h3>
                            <div class="traits-grid">
                                <?php
                                $affective_traits = [
                                    'punctuality' => 'Punctuality',
                                    'attendance' => 'Attendance',
                                    'politeness' => 'Politeness',
                                    'honesty' => 'Honesty',
                                    'neatness' => 'Neatness',
                                    'reliability' => 'Reliability',
                                    'relationship' => 'Relationship with Others',
                                    'self_control' => 'Self Control'
                                ];

                                foreach ($affective_traits as $key => $label):
                                ?>
                                    <div class="trait-item">
                                        <label><?= $label ?>:</label>
                                        <select name="affective[<?= $key ?>]" class="rating-select">
                                            <option value="">Select Grade</option>
                                            <option value="A" <?= ($existing_affective[$key] ?? '') == 'A' ? 'selected' : '' ?>>A - Excellent</option>
                                            <option value="B" <?= ($existing_affective[$key] ?? '') == 'B' ? 'selected' : '' ?>>B - Very Good</option>
                                            <option value="C" <?= ($existing_affective[$key] ?? '') == 'C' ? 'selected' : '' ?>>C - Good</option>
                                            <option value="D" <?= ($existing_affective[$key] ?? '') == 'D' ? 'selected' : '' ?>>D - Fair</option>
                                            <option value="E" <?= ($existing_affective[$key] ?? '') == 'E' ? 'selected' : '' ?>>E - Poor</option>
                                        </select>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Psychomotor Skills -->
                        <div class="form-section">
                            <h3><i class="fas fa-running"></i> Psychomotor Skills (Rate A-E)</h3>
                            <div class="traits-grid">
                                <?php
                                $psychomotor_skills = [
                                    'handwriting' => 'Handwriting',
                                    'verbal_fluency' => 'Verbal Fluency',
                                    'sports' => 'Sports',
                                    'handling_tools' => 'Handling of Tools',
                                    'drawing_painting' => 'Drawing & Painting',
                                    'musical_skills' => 'Musical Skills'
                                ];

                                foreach ($psychomotor_skills as $key => $label):
                                ?>
                                    <div class="trait-item">
                                        <label><?= $label ?>:</label>
                                        <select name="psychomotor[<?= $key ?>]" class="rating-select">
                                            <option value="">Select Grade</option>
                                            <option value="A" <?= ($existing_psychomotor[$key] ?? '') == 'A' ? 'selected' : '' ?>>A - Excellent</option>
                                            <option value="B" <?= ($existing_psychomotor[$key] ?? '') == 'B' ? 'selected' : '' ?>>B - Very Good</option>
                                            <option value="C" <?= ($existing_psychomotor[$key] ?? '') == 'C' ? 'selected' : '' ?>>C - Good</option>
                                            <option value="D" <?= ($existing_psychomotor[$key] ?? '') == 'D' ? 'selected' : '' ?>>D - Fair</option>
                                            <option value="E" <?= ($existing_psychomotor[$key] ?? '') == 'E' ? 'selected' : '' ?>>E - Poor</option>
                                        </select>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" name="save_comments" class="btn">
                            <i class="fas fa-save"></i> Save Comments & Traits
                        </button>
                    <?php endif; ?>
                </form>

                <a href="report_card_dashboard.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Report Card Dashboard
                </a>
            </div>
        </div>
    </div>

    <script>
        // Automatically calculate attendance summary
        document.addEventListener('input', function(e) {
            if (e.target.name === 'days_present' || e.target.name === 'days_absent') {
                const daysPresent = document.querySelector('input[name="days_present"]');
                const daysAbsent = document.querySelector('input[name="days_absent"]');

                if (daysPresent && daysAbsent) {
                    const present = parseInt(daysPresent.value) || 0;
                    const absent = parseInt(daysAbsent.value) || 0;
                    const total = present + absent;
                    const rate = total > 0 ? Math.round((present / total) * 100) : 0;

                    // Find and update the attendance summary
                    const summaryDiv = document.getElementById('attendance-summary');
                    if (summaryDiv) {
                        summaryDiv.textContent = `Total Days: ${total} | Attendance Rate: ${rate}%`;
                    }
                }
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
    </script>
</body>

</html>