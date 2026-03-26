<?php
// staff/import-questions.php - Import Questions
session_start();

// Check if staff is logged in
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Get staff information
$staff_id = $_SESSION['staff_id'];
$staff_name = $_SESSION['staff_name'] ?? 'Staff Member';

$message = '';
$message_type = '';
$preview_data = [];
$question_type = $_GET['type'] ?? $_POST['question_type'] ?? 'objective';
$valid_types = ['objective', 'subjective', 'theory'];
if (!in_array($question_type, $valid_types)) {
    $question_type = 'objective';
}

// Get staff assigned subjects
try {
    $stmt = $pdo->prepare("
        SELECT s.id, s.subject_name, sc.class
        FROM subjects s
        INNER JOIN staff_subjects ss ON s.id = ss.subject_id
        LEFT JOIN subject_classes sc ON s.id = sc.subject_id
        WHERE ss.staff_id = ?
        ORDER BY s.subject_name
    ");
    $stmt->execute([$staff_id]);
    $assigned_subjects = $stmt->fetchAll();
} catch (Exception $e) {
    $message = "Error loading subjects: " . $e->getMessage();
    $message_type = "error";
}

// Handle file upload and import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    $question_type = $_POST['question_type'];
    $subject_id = $_POST['subject_id'] ?? 0;
    $topic_id = $_POST['topic_id'] ?? 0;
    $class = $_POST['class'] ?? '';

    if (empty($subject_id)) {
        $message = "Please select a subject.";
        $message_type = "error";
    } elseif (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $file_path = $_FILES['import_file']['tmp_name'];
        $file_name = $_FILES['import_file']['name'];
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if ($file_extension !== 'csv') {
            $message = "Only CSV files are supported for import.";
            $message_type = "error";
        } else {
            $file_content = file_get_contents($file_path);

            // Convert to UTF-8 if needed
            if (!mb_check_encoding($file_content, 'UTF-8')) {
                $file_content = mb_convert_encoding($file_content, 'UTF-8');
            }

            // Parse CSV
            $lines = array_map('str_getcsv', explode("\n", trim($file_content)));
            $headers = array_shift($lines); // Remove header row

            $success_count = 0;
            $error_count = 0;
            $errors = [];

            foreach ($lines as $line_num => $line) {
                if (empty(array_filter($line))) continue;

                try {
                    if ($question_type === 'objective') {
                        // Expected: Question, Option A, Option B, Option C, Option D, Correct Answer, Difficulty, Marks
                        $question_text = trim($line[0] ?? '');
                        $option_a = trim($line[1] ?? '');
                        $option_b = trim($line[2] ?? '');
                        $option_c = trim($line[3] ?? '');
                        $option_d = trim($line[4] ?? '');
                        $correct_answer = strtoupper(trim($line[5] ?? ''));
                        $difficulty = strtolower(trim($line[6] ?? 'medium'));
                        $marks = intval($line[7] ?? 1);

                        if (empty($question_text) || empty($option_a) || empty($option_b)) {
                            throw new Exception("Missing required fields on line " . ($line_num + 2));
                        }

                        if (!in_array($correct_answer, ['A', 'B', 'C', 'D'])) {
                            $correct_answer = 'A';
                        }

                        if (!in_array($difficulty, ['easy', 'medium', 'hard'])) {
                            $difficulty = 'medium';
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO objective_questions 
                            (question_text, option_a, option_b, option_c, option_d, correct_answer, 
                             difficulty_level, marks, subject_id, topic_id, class) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $question_text,
                            $option_a,
                            $option_b,
                            $option_c,
                            $option_d,
                            $correct_answer,
                            $difficulty,
                            $marks,
                            $subject_id,
                            $topic_id ?: null,
                            $class
                        ]);
                        $success_count++;
                    } elseif ($question_type === 'subjective') {
                        // Expected: Question, Correct Answer, Difficulty, Marks
                        $question_text = trim($line[0] ?? '');
                        $correct_answer = trim($line[1] ?? '');
                        $difficulty = strtolower(trim($line[2] ?? 'medium'));
                        $marks = intval($line[3] ?? 1);

                        if (empty($question_text) || empty($correct_answer)) {
                            throw new Exception("Missing required fields on line " . ($line_num + 2));
                        }

                        if (!in_array($difficulty, ['easy', 'medium', 'hard'])) {
                            $difficulty = 'medium';
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO subjective_questions 
                            (question_text, correct_answer, difficulty_level, marks, subject_id, topic_id, class) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $question_text,
                            $correct_answer,
                            $difficulty,
                            $marks,
                            $subject_id,
                            $topic_id ?: null,
                            $class
                        ]);
                        $success_count++;
                    } else { // theory
                        // Expected: Question, Marks
                        $question_text = trim($line[0] ?? '');
                        $marks = intval($line[1] ?? 5);

                        if (empty($question_text)) {
                            throw new Exception("Missing question text on line " . ($line_num + 2));
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO theory_questions 
                            (question_text, marks, subject_id, topic_id, class) 
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $question_text,
                            $marks,
                            $subject_id,
                            $topic_id ?: null,
                            $class
                        ]);
                        $success_count++;
                    }
                } catch (Exception $e) {
                    $error_count++;
                    $errors[] = "Line " . ($line_num + 2) . ": " . $e->getMessage();
                }
            }

            if ($success_count > 0) {
                $message = "Import completed! $success_count questions imported successfully.";
                $message_type = "success";

                if ($error_count > 0) {
                    $message .= " $error_count questions failed.";
                    $_SESSION['import_errors'] = $errors;
                }

                // Log activity
                logActivity($staff_id, 'staff', "Imported $success_count $question_type questions");
            } else {
                $message = "No questions were imported. Please check your CSV format.";
                $message_type = "error";
            }
        }
    } else {
        $message = "Please select a file to upload.";
        $message_type = "error";
    }
}

// Handle template download
if (isset($_GET['download_template'])) {
    $type = $_GET['type'] ?? 'objective';
    $filename = $type . '_questions_template.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($type === 'objective') {
        fputcsv($output, ['Question Text', 'Option A', 'Option B', 'Option C', 'Option D', 'Correct Answer (A/B/C/D)', 'Difficulty (easy/medium/hard)', 'Marks']);
        fputcsv($output, ['What is 2+2?', '3', '4', '5', '6', 'B', 'easy', '1']);
        fputcsv($output, ['What is the capital of France?', 'London', 'Berlin', 'Paris', 'Madrid', 'C', 'medium', '1']);
    } elseif ($type === 'subjective') {
        fputcsv($output, ['Question Text', 'Correct Answer', 'Difficulty (easy/medium/hard)', 'Marks']);
        fputcsv($output, ['Define photosynthesis.', 'Photosynthesis is the process by which plants make food using sunlight.', 'medium', '5']);
        fputcsv($output, ['What is the capital of Nigeria?', 'Abuja', 'easy', '2']);
    } else {
        fputcsv($output, ['Question Text', 'Marks']);
        fputcsv($output, ['Discuss the causes of World War I.', '10']);
        fputcsv($output, ['Explain the theory of evolution.', '15']);
    }

    fclose($output);
    exit();
}

// Get topics for selected subject (AJAX endpoint)
if (isset($_GET['get_topics']) && isset($_GET['subject_id'])) {
    $subject_id = intval($_GET['subject_id']);
    $stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE subject_id = ? ORDER BY topic_name");
    $stmt->execute([$subject_id]);
    $topics = $stmt->fetchAll();

    header('Content-Type: application/json');
    echo json_encode($topics);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Questions - Staff Portal</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #2c5282;
            --secondary-color: #4299e1;
            --success-color: #38a169;
            --danger-color: #e53e3e;
            --light-color: #edf2f7;
            --dark-color: #2d3748;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: #333;
            min-height: 100vh;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
            transition: all 0.3s ease;
            z-index: 100;
            box-shadow: 3px 0 20px rgba(0, 0, 0, 0.15);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            margin-bottom: 25px;
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--secondary-color), #3182ce);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .logo-text h3 {
            font-size: 1.2rem;
            margin-bottom: 3px;
        }

        .logo-text p {
            font-size: 0.8rem;
            opacity: 0.9;
        }

        .staff-info {
            text-align: center;
            padding: 20px 15px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 15px;
            margin: 0 15px 25px;
        }

        .staff-info h4 {
            margin-bottom: 5px;
        }

        .staff-info p {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
        }

        .nav-links li {
            margin-bottom: 8px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 18px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 25px;
            min-height: 100vh;
        }

        .top-header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title h1 {
            color: var(--dark-color);
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .header-title p {
            color: #4a5568;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), #3182ce);
            color: white;
        }

        .btn-secondary {
            background: #718096;
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #2f855a);
            color: white;
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
            background: #f0fff4;
            color: #22543d;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #fff5f5;
            color: #742a2a;
            border-left: 4px solid var(--danger-color);
        }

        .import-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark-color);
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--secondary-color);
        }

        .file-upload {
            border: 2px dashed #cbd5e0;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .file-upload:hover {
            border-color: var(--secondary-color);
            background: #f7fafc;
        }

        .file-upload i {
            font-size: 3rem;
            color: #a0aec0;
            margin-bottom: 15px;
        }

        .file-upload input {
            display: none;
        }

        .file-info {
            margin-top: 15px;
            font-size: 0.9rem;
            color: #718096;
        }

        .template-links {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .template-links h3 {
            margin-bottom: 15px;
            color: var(--dark-color);
        }

        .template-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: block;
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
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }
    </style>
</head>

<body>
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-icon">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <div class="logo-text">
                <h3><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?></h3>
                <p>Staff Portal</p>
            </div>
        </div>

        <div class="staff-info">
            <h4><?php echo htmlspecialchars($staff_name); ?></h4>
            <p>ID: <?php echo htmlspecialchars($staff_id); ?></p>
        </div>

        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> My Students</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
            <li><a href="questions.php" class="active"><i class="fas fa-question-circle"></i> Question Bank</a></li>
            <li><a href="profile.php"><i class="fas fa-user-cog"></i> My Profile</a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1>Import Questions</h1>
                <p>Bulk import questions from CSV files</p>
            </div>
            <div>
                <a href="questions.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Questions
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['import_errors']) && !empty($_SESSION['import_errors'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Import Errors:</strong>
                    <ul style="margin-top: 10px; margin-left: 20px;">
                        <?php foreach ($_SESSION['import_errors'] as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php unset($_SESSION['import_errors']); ?>
        <?php endif; ?>

        <div class="import-container">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Question Type *</label>
                    <select name="question_type" class="form-select" id="questionType" required>
                        <option value="objective" <?php echo $question_type === 'objective' ? 'selected' : ''; ?>>Objective Questions</option>
                        <option value="subjective" <?php echo $question_type === 'subjective' ? 'selected' : ''; ?>>Subjective Questions</option>
                        <option value="theory" <?php echo $question_type === 'theory' ? 'selected' : ''; ?>>Theory Questions</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Subject *</label>
                    <select name="subject_id" id="subject_id" class="form-select" required>
                        <option value="">Select Subject</option>
                        <?php foreach ($assigned_subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>">
                                <?php echo htmlspecialchars($subject['subject_name'] . ($subject['class'] ? ' (' . $subject['class'] . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Topic (Optional)</label>
                    <select name="topic_id" id="topic_id" class="form-select">
                        <option value="">Select Topic (Optional)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Class (Optional)</label>
                    <input type="text" name="class" class="form-control" placeholder="e.g., SS1, SS2, JSS3">
                </div>

                <div class="form-group">
                    <label>CSV File *</label>
                    <div class="file-upload" onclick="document.getElementById('import_file').click()">
                        <i class="fas fa-file-csv"></i>
                        <p>Click to select CSV file</p>
                        <input type="file" id="import_file" name="import_file" accept=".csv" required>
                        <div class="file-info" id="fileInfo">No file selected</div>
                    </div>
                </div>

                <div style="text-align: right;">
                    <button type="submit" name="import" class="btn btn-success">
                        <i class="fas fa-upload"></i> Import Questions
                    </button>
                </div>
            </form>

            <div class="template-links">
                <h3><i class="fas fa-download"></i> Download Templates</h3>
                <div class="template-buttons">
                    <a href="?download_template=1&type=objective" class="btn btn-primary">
                        <i class="fas fa-file-csv"></i> Objective Template
                    </a>
                    <a href="?download_template=1&type=subjective" class="btn btn-primary">
                        <i class="fas fa-file-csv"></i> Subjective Template
                    </a>
                    <a href="?download_template=1&type=theory" class="btn btn-primary">
                        <i class="fas fa-file-csv"></i> Theory Template
                    </a>
                </div>
                <p style="margin-top: 15px; color: #718096; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i>
                    Download the template, fill in your questions, and upload the CSV file.
                    The first row should contain headers as shown in the template.
                </p>
            </div>
        </div>
    </div>

    <script>
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        mobileMenuBtn.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });

        // File upload preview
        document.getElementById('import_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const fileInfo = document.getElementById('fileInfo');
            if (file) {
                fileInfo.innerHTML = `<strong>Selected:</strong> ${file.name} (${(file.size / 1024).toFixed(2)} KB)`;
            } else {
                fileInfo.innerHTML = 'No file selected';
            }
        });

        // Load topics when subject changes
        const subjectSelect = document.getElementById('subject_id');
        const topicSelect = document.getElementById('topic_id');

        subjectSelect.addEventListener('change', function() {
            const subjectId = this.value;
            if (subjectId) {
                fetch(`import-questions.php?get_topics=1&subject_id=${subjectId}`)
                    .then(response => response.json())
                    .then(data => {
                        topicSelect.innerHTML = '<option value="">Select Topic (Optional)</option>';
                        data.forEach(topic => {
                            topicSelect.innerHTML += `<option value="${topic.id}">${escapeHtml(topic.topic_name)}</option>`;
                        });
                    })
                    .catch(error => console.error('Error loading topics:', error));
            } else {
                topicSelect.innerHTML = '<option value="">Select Topic (Optional)</option>';
            }
        });

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close sidebar on outside click (mobile)
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Auto-hide messages
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>

</html>