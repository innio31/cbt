<?php
// admin/settings.php - System Settings Page
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: ../login.php");
    exit();
}

// Check if admin has permission to access settings
if ($_SESSION['admin_role'] !== 'super_admin' && $_SESSION['admin_role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Include database connection
require_once '../includes/config.php';
require_once '../includes/auth.php';

$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'admin';

// Initialize messages
$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'update_general':
                $school_name = $_POST['school_name'] ?? '';
                $school_address = $_POST['school_address'] ?? '';
                $school_email = $_POST['school_email'] ?? '';
                $school_phone = $_POST['school_phone'] ?? '';
                $school_website = $_POST['school_website'] ?? '';

                // Update configuration file or database
                $config_file = '../includes/config.php';
                $config_content = file_get_contents($config_file);

                // Update constants
                $replacements = [
                    "define('SCHOOL_NAME'," => "define('SCHOOL_NAME', '$school_name')",
                    "define('SCHOOL_ADDRESS'," => "define('SCHOOL_ADDRESS', '$school_address')",
                    "define('SCHOOL_EMAIL'," => "define('SCHOOL_EMAIL', '$school_email')",
                    "define('SCHOOL_PHONE'," => "define('SCHOOL_PHONE', '$school_phone')",
                    "define('SCHOOL_WEBSITE'," => "define('SCHOOL_WEBSITE', '$school_website')",
                ];

                foreach ($replacements as $search => $replace) {
                    $pattern = '/' . preg_quote($search) . ".*?\);/";
                    $config_content = preg_replace($pattern, $replace . ';', $config_content);
                }

                file_put_contents($config_file, $config_content);

                $message = 'General settings updated successfully';
                $message_type = 'success';

                // Log activity
                logActivity("Updated general settings", 'admin', $_SESSION['admin_id']);
                break;

            case 'update_system':
                $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
                $enable_registration = isset($_POST['enable_registration']) ? 1 : 0;
                $enable_results = isset($_POST['enable_results']) ? 1 : 0;
                $session_name = $_POST['session_name'] ?? date('Y');
                $current_term = $_POST['current_term'] ?? 'First';
                $result_pass_mark = $_POST['result_pass_mark'] ?? 50;

                // In a real system, you would save these to a database settings table
                // For now, we'll simulate with session
                $_SESSION['system_settings'] = [
                    'maintenance_mode' => $maintenance_mode,
                    'enable_registration' => $enable_registration,
                    'enable_results' => $enable_results,
                    'session_name' => $session_name,
                    'current_term' => $current_term,
                    'result_pass_mark' => $result_pass_mark
                ];

                $message = 'System settings updated successfully';
                $message_type = 'success';

                // Log activity
                logActivity("Updated system settings", 'admin', $_SESSION['admin_id']);
                break;

            case 'update_email':
                $smtp_host = $_POST['smtp_host'] ?? '';
                $smtp_port = $_POST['smtp_port'] ?? 587;
                $smtp_username = $_POST['smtp_username'] ?? '';
                $smtp_password = $_POST['smtp_password'] ?? '';
                $smtp_encryption = $_POST['smtp_encryption'] ?? 'tls';
                $from_email = $_POST['from_email'] ?? '';
                $from_name = $_POST['from_name'] ?? '';

                // Update email configuration
                $email_config_file = '../includes/email_config.php';
                $email_config = "<?php\n";
                $email_config .= "// Email Configuration\n";
                $email_config .= "define('SMTP_HOST', '$smtp_host');\n";
                $email_config .= "define('SMTP_PORT', $smtp_port);\n";
                $email_config .= "define('SMTP_USERNAME', '$smtp_username');\n";
                $email_config .= "define('SMTP_PASSWORD', '$smtp_password');\n";
                $email_config .= "define('SMTP_ENCRYPTION', '$smtp_encryption');\n";
                $email_config .= "define('FROM_EMAIL', '$from_email');\n";
                $email_config .= "define('FROM_NAME', '$from_name');\n";

                file_put_contents($email_config_file, $email_config);

                $message = 'Email settings updated successfully';
                $message_type = 'success';

                // Log activity
                logActivity("Updated email settings", 'admin', $_SESSION['admin_id']);
                break;

            case 'update_database':
                // Handle database backup
                if (isset($_POST['backup_database'])) {
                    backupDatabase();
                    $message = 'Database backup created successfully';
                    $message_type = 'success';

                    // Log activity
                    logActivity("Created database backup", 'admin', $_SESSION['admin_id']);
                }

                // Handle database restore
                if (isset($_FILES['restore_file']) && $_FILES['restore_file']['error'] === UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['restore_file']['tmp_name'];
                    $content = file_get_contents($tmp_name);

                    // In a real system, you would restore the database here
                    // This is a simplified example
                    $message = 'Database restore initiated (simulated)';
                    $message_type = 'success';

                    // Log activity
                    logActivity("Initiated database restore", 'admin', $_SESSION['admin_id']);
                }
                break;

            case 'update_security':
                $password_policy = $_POST['password_policy'] ?? 'medium';
                $login_attempts = $_POST['login_attempts'] ?? 5;
                $session_timeout = $_POST['session_timeout'] ?? 30;
                $enable_2fa = isset($_POST['enable_2fa']) ? 1 : 0;
                $force_ssl = isset($_POST['force_ssl']) ? 1 : 0;

                // Update security configuration
                $security_config_file = '../includes/security_config.php';
                $security_config = "<?php\n";
                $security_config .= "// Security Configuration\n";
                $security_config .= "define('PASSWORD_POLICY', '$password_policy'); // low, medium, high\n";
                $security_config .= "define('MAX_LOGIN_ATTEMPTS', $login_attempts);\n";
                $security_config .= "define('SESSION_TIMEOUT_MINUTES', $session_timeout);\n";
                $security_config .= "define('ENABLE_2FA', " . ($enable_2fa ? 'true' : 'false') . ");\n";
                $security_config .= "define('FORCE_SSL', " . ($force_ssl ? 'true' : 'false') . ");\n";

                file_put_contents($security_config_file, $security_config);

                $message = 'Security settings updated successfully';
                $message_type = 'success';

                // Log activity
                logActivity("Updated security settings", 'admin', $_SESSION['admin_id']);
                break;

            case 'update_exam':
                $default_exam_duration = $_POST['default_exam_duration'] ?? 60;
                $default_question_count = $_POST['default_question_count'] ?? 50;
                $enable_negative_marking = isset($_POST['enable_negative_marking']) ? 1 : 0;
                $negative_mark_percentage = $_POST['negative_mark_percentage'] ?? 25;
                $shuffle_questions = isset($_POST['shuffle_questions']) ? 1 : 0;
                $shuffle_options = isset($_POST['shuffle_options']) ? 1 : 0;

                // Update exam configuration
                $exam_config_file = '../includes/exam_config.php';
                $exam_config = "<?php\n";
                $exam_config .= "// Exam Configuration\n";
                $exam_config .= "define('DEFAULT_EXAM_DURATION', $default_exam_duration);\n";
                $exam_config .= "define('DEFAULT_QUESTION_COUNT', $default_question_count);\n";
                $exam_config .= "define('ENABLE_NEGATIVE_MARKING', " . ($enable_negative_marking ? 'true' : 'false') . ");\n";
                $exam_config .= "define('NEGATIVE_MARK_PERCENTAGE', $negative_mark_percentage);\n";
                $exam_config .= "define('SHUFFLE_QUESTIONS', " . ($shuffle_questions ? 'true' : 'false') . ");\n";
                $exam_config .= "define('SHUFFLE_OPTIONS', " . ($shuffle_options ? 'true' : 'false') . ");\n";

                file_put_contents($exam_config_file, $exam_config);

                $message = 'Exam settings updated successfully';
                $message_type = 'success';

                // Log activity
                logActivity("Updated exam settings", 'admin', $_SESSION['admin_id']);
                break;
        }
    } catch (Exception $e) {
        $message = 'Error updating settings: ' . $e->getMessage();
        $message_type = 'error';
        error_log("Settings update error: " . $e->getMessage());
    }
}

// Function to create database backup
function backupDatabase()
{
    global $pdo;

    $backup_dir = '../backups/';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }

    $backup_file = $backup_dir . 'backup_' . date('Y-m-d_H-i-s') . '.sql';

    // Get all tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    $backup_content = "";

    foreach ($tables as $table) {
        // Get table structure
        $create_table = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        $backup_content .= $create_table['Create Table'] . ";\n\n";

        // Get table data
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) > 0) {
            $backup_content .= "INSERT INTO `$table` VALUES\n";
            $insert_values = [];

            foreach ($rows as $row) {
                $values = array_map(function ($value) {
                    if ($value === null) return 'NULL';
                    return "'" . str_replace("'", "''", $value) . "'";
                }, $row);

                $insert_values[] = "(" . implode(", ", $values) . ")";
            }

            $backup_content .= implode(",\n", $insert_values) . ";\n\n";
        }
    }

    file_put_contents($backup_file, $backup_content);

    // Log the backup
    logActivity("Created database backup: " . basename($backup_file), 'admin', $_SESSION['admin_id']);

    return $backup_file;
}

// Get current settings (simulated - in real system, fetch from database)
$system_settings = $_SESSION['system_settings'] ?? [
    'maintenance_mode' => 0,
    'enable_registration' => 1,
    'enable_results' => 1,
    'session_name' => date('Y'),
    'current_term' => 'First',
    'result_pass_mark' => 50
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - Digital CBT System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* Reuse styles from index.php with some additions */
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
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

        /* Sidebar Styles (same as index.php) */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
            z-index: 100;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
        }

        .top-header {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .header-title p {
            color: #666;
            font-size: 0.9rem;
        }

        /* Settings Container */
        .settings-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .settings-tabs {
            display: flex;
            background: white;
            border-radius: 10px 10px 0 0;
            overflow: hidden;
            margin-bottom: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .tab-btn {
            padding: 15px 25px;
            background: none;
            border: none;
            font-size: 1rem;
            font-weight: 500;
            color: #666;
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            flex: 1;
            text-align: center;
        }

        .tab-btn:hover {
            background: #f8f9fa;
            color: var(--primary-color);
        }

        .tab-btn.active {
            color: var(--secondary-color);
            border-bottom-color: var(--secondary-color);
            background: #f8f9fa;
        }

        .settings-content {
            background: white;
            border-radius: 0 0 10px 10px;
            padding: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .settings-section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 1.3rem;
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--secondary-color);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-text {
            font-size: 0.85rem;
            color: #777;
            margin-top: 5px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-group label {
            cursor: pointer;
            user-select: none;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), #2980b9);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #d35400);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #219653);
            color: white;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        .danger-zone {
            border: 2px solid var(--danger-color);
            background: #fff5f5;
        }

        .danger-zone .section-title {
            color: var(--danger-color);
        }

        .backup-list {
            list-style: none;
            margin-top: 15px;
        }

        .backup-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid var(--secondary-color);
        }

        .backup-actions {
            display: flex;
            gap: 10px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        .file-input {
            padding: 10px;
            border: 2px dashed #e0e0e0;
            border-radius: 8px;
            background: #f8f9fa;
            cursor: pointer;
            text-align: center;
        }

        .file-input:hover {
            border-color: var(--secondary-color);
        }

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid var(--secondary-color);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .info-box i {
            color: var(--secondary-color);
            margin-right: 10px;
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .settings-tabs {
                flex-direction: column;
            }

            .tab-btn {
                border-bottom: 1px solid #eee;
                border-right: none;
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
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
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
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar (same as index.php) -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="logo-text">
                    <h3><?php echo htmlspecialchars($school_name); ?></h3>
                    <p>Admin Panel</p>
                </div>
            </div>
        </div>

        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
        </div>

        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="settings.php" class="active"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>System Settings</h1>
                <p>Configure system preferences and manage application settings</p>
            </div>
            <div class="header-actions">
                <button class="btn btn-danger" onclick="window.location.href='../logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <!-- Display Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : ($message_type === 'warning' ? 'exclamation-circle' : 'check-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Settings Container -->
        <div class="settings-container">
            <!-- Settings Tabs -->
            <div class="settings-tabs">
                <button class="tab-btn active" data-tab="general">
                    <i class="fas fa-school"></i> General
                </button>
                <button class="tab-btn" data-tab="system">
                    <i class="fas fa-cog"></i> System
                </button>
                <button class="tab-btn" data-tab="exam">
                    <i class="fas fa-file-alt"></i> Exam
                </button>
                <button class="tab-btn" data-tab="email">
                    <i class="fas fa-envelope"></i> Email
                </button>
                <button class="tab-btn" data-tab="security">
                    <i class="fas fa-shield-alt"></i> Security
                </button>
                <button class="tab-btn" data-tab="database">
                    <i class="fas fa-database"></i> Database
                </button>
            </div>

            <!-- Settings Content -->
            <div class="settings-content">

                <!-- General Settings Tab -->
                <div class="tab-content active" id="general-tab">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_general">

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-info-circle"></i> School Information
                            </h3>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">School Name *</label>
                                    <input type="text" name="school_name" class="form-control"
                                        value="<?php echo htmlspecialchars($school_name); ?>" required>
                                    <small class="form-text">Display name for the school</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">School Email</label>
                                    <input type="email" name="school_email" class="form-control"
                                        value="<?php echo htmlspecialchars($school_email); ?>">
                                    <small class="form-text">Official email address</small>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">School Address</label>
                                <textarea name="school_address" class="form-control" rows="3"><?php echo htmlspecialchars($school_address); ?></textarea>
                                <small class="form-text">Complete school address</small>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">School Phone</label>
                                    <input type="text" name="school_phone" class="form-control"
                                        value="<?php echo htmlspecialchars($school_phone); ?>">
                                    <small class="form-text">Contact phone number</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">School Website</label>
                                    <input type="url" name="school_website" class="form-control"
                                        value="<?php echo htmlspecialchars($school_website); ?>">
                                    <small class="form-text">Website URL (include https://)</small>
                                </div>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <button type="reset" class="btn" style="background: #e0e0e0;">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                        </div>
                    </form>
                </div>

                <!-- System Settings Tab -->
                <div class="tab-content" id="system-tab">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_system">

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-tasks"></i> System Preferences
                            </h3>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Current Session</label>
                                    <input type="text" name="session_name" class="form-control"
                                        value="<?php echo htmlspecialchars($system_settings['session_name']); ?>" required>
                                    <small class="form-text">e.g., 2023/2024</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Current Term</label>
                                    <select name="current_term" class="form-control" required>
                                        <option value="First" <?php echo $system_settings['current_term'] === 'First' ? 'selected' : ''; ?>>First Term</option>
                                        <option value="Second" <?php echo $system_settings['current_term'] === 'Second' ? 'selected' : ''; ?>>Second Term</option>
                                        <option value="Third" <?php echo $system_settings['current_term'] === 'Third' ? 'selected' : ''; ?>>Third Term</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Pass Mark Percentage</label>
                                    <input type="number" name="result_pass_mark" class="form-control"
                                        min="0" max="100" step="0.5"
                                        value="<?php echo htmlspecialchars($system_settings['result_pass_mark']); ?>" required>
                                    <small class="form-text">Minimum percentage to pass an exam</small>
                                </div>
                            </div>

                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" name="maintenance_mode" id="maintenance_mode"
                                        <?php echo $system_settings['maintenance_mode'] ? 'checked' : ''; ?>>
                                    <label for="maintenance_mode">Enable Maintenance Mode</label>
                                </div>
                                <small class="form-text">When enabled, only administrators can access the system</small>
                            </div>

                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" name="enable_registration" id="enable_registration"
                                        <?php echo $system_settings['enable_registration'] ? 'checked' : ''; ?>>
                                    <label for="enable_registration">Enable Student Registration</label>
                                </div>
                                <small class="form-text">Allow new student registrations</small>
                            </div>

                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" name="enable_results" id="enable_results"
                                        <?php echo $system_settings['enable_results'] ? 'checked' : ''; ?>>
                                    <label for="enable_results">Enable Results Display</label>
                                </div>
                                <small class="form-text">Allow students to view their results</small>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save System Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Exam Settings Tab -->
                <div class="tab-content" id="exam-tab">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_exam">

                        <div class="info-box">
                            <i class="fas fa-info-circle"></i>
                            Configure default exam settings that apply to all new exams.
                        </div>

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-clock"></i> Timing Settings
                            </h3>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Default Exam Duration (minutes)</label>
                                    <input type="number" name="default_exam_duration" class="form-control"
                                        min="1" max="300" value="60" required>
                                    <small class="form-text">Default time limit for exams</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Default Question Count</label>
                                    <input type="number" name="default_question_count" class="form-control"
                                        min="1" max="200" value="50" required>
                                    <small class="form-text">Default number of questions per exam</small>
                                </div>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-random"></i> Question Settings
                            </h3>

                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" name="shuffle_questions" id="shuffle_questions" checked>
                                    <label for="shuffle_questions">Shuffle Questions</label>
                                </div>
                                <small class="form-text">Display questions in random order</small>
                            </div>

                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" name="shuffle_options" id="shuffle_options" checked>
                                    <label for="shuffle_options">Shuffle Options</label>
                                </div>
                                <small class="form-text">Display multiple choice options in random order</small>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-percentage"></i> Scoring Settings
                            </h3>

                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" name="enable_negative_marking" id="enable_negative_marking">
                                    <label for="enable_negative_marking">Enable Negative Marking</label>
                                </div>
                                <small class="form-text">Deduct marks for wrong answers</small>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Negative Mark Percentage</label>
                                <input type="number" name="negative_mark_percentage" class="form-control"
                                    min="0" max="100" value="25"
                                    <?php echo $system_settings['enable_negative_marking'] ? '' : 'disabled'; ?>>
                                <small class="form-text">Percentage of marks to deduct for wrong answers</small>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Exam Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Email Settings Tab -->
                <div class="tab-content" id="email-tab">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_email">

                        <div class="info-box">
                            <i class="fas fa-info-circle"></i>
                            Configure email server settings for sending notifications and reset passwords.
                        </div>

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-server"></i> SMTP Server Settings
                            </h3>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">SMTP Host *</label>
                                    <input type="text" name="smtp_host" class="form-control"
                                        value="" placeholder="smtp.gmail.com" required>
                                    <small class="form-text">SMTP server address</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">SMTP Port *</label>
                                    <input type="number" name="smtp_port" class="form-control"
                                        value="587" min="1" max="65535" required>
                                    <small class="form-text">Usually 587 for TLS, 465 for SSL</small>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">SMTP Username *</label>
                                    <input type="text" name="smtp_username" class="form-control"
                                        value="" required>
                                    <small class="form-text">Email address for authentication</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">SMTP Password *</label>
                                    <input type="password" name="smtp_password" class="form-control"
                                        value="" required>
                                    <small class="form-text">Password for the email account</small>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Encryption *</label>
                                    <select name="smtp_encryption" class="form-control" required>
                                        <option value="tls">TLS</option>
                                        <option value="ssl">SSL</option>
                                        <option value="">None</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-envelope"></i> Sender Information
                            </h3>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">From Email *</label>
                                    <input type="email" name="from_email" class="form-control"
                                        value="" required>
                                    <small class="form-text">Sender email address</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">From Name *</label>
                                    <input type="text" name="from_name" class="form-control"
                                        value="<?php echo htmlspecialchars($school_name); ?>" required>
                                    <small class="form-text">Sender display name</small>
                                </div>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Email Settings
                            </button>
                            <button type="button" class="btn btn-warning" onclick="testEmailSettings()">
                                <i class="fas fa-paper-plane"></i> Test Email
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Security Settings Tab -->
                <div class="tab-content" id="security-tab">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_security">

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-lock"></i> Login Security
                            </h3>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Max Login Attempts</label>
                                    <input type="number" name="login_attempts" class="form-control"
                                        min="1" max="10" value="5" required>
                                    <small class="form-text">Number of failed attempts before lockout</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Session Timeout (minutes)</label>
                                    <input type="number" name="session_timeout" class="form-control"
                                        min="5" max="480" value="30" required>
                                    <small class="form-text">Inactivity timeout for auto-logout</small>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Password Policy</label>
                                <select name="password_policy" class="form-control" required>
                                    <option value="low">Low (6+ characters)</option>
                                    <option value="medium" selected>Medium (8+ chars, mixed case)</option>
                                    <option value="high">High (12+ chars, mixed case + numbers + symbols)</option>
                                </select>
                                <small class="form-text">Password complexity requirements</small>
                            </div>
                        </div>

                        <div class="settings-section">
                            <h3 class="section-title">
                                <i class="fas fa-shield-alt"></i> Advanced Security
                            </h3>

                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" name="enable_2fa" id="enable_2fa">
                                    <label for="enable_2fa">Enable Two-Factor Authentication (2FA)</label>
                                </div>
                                <small class="form-text">Require additional verification for admin logins</small>
                            </div>

                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" name="force_ssl" id="force_ssl">
                                    <label for="force_ssl">Force SSL/HTTPS</label>
                                </div>
                                <small class="form-text">Redirect all traffic to HTTPS</small>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Security Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Database Settings Tab -->
                <div class="tab-content" id="database-tab">
                    <div class="settings-section">
                        <h3 class="section-title">
                            <i class="fas fa-database"></i> Database Backup
                        </h3>

                        <form method="POST" action="">
                            <input type="hidden" name="action" value="update_database">

                            <div class="info-box">
                                <i class="fas fa-info-circle"></i>
                                Create a backup of your database. Recommended before making major changes.
                            </div>

                            <div class="form-group">
                                <button type="submit" name="backup_database" class="btn btn-success">
                                    <i class="fas fa-download"></i> Create Backup Now
                                </button>
                                <small class="form-text">Creates a complete SQL dump of the database</small>
                            </div>
                        </form>

                        <!-- Backup List -->
                        <div class="form-group">
                            <label class="form-label">Recent Backups</label>
                            <div class="backup-list">
                                <?php
                                $backup_dir = '../backups/';
                                if (is_dir($backup_dir)) {
                                    $backups = glob($backup_dir . '*.sql');
                                    rsort($backups); // Show newest first

                                    if (count($backups) > 0) {
                                        foreach (array_slice($backups, 0, 5) as $backup) {
                                            $filename = basename($backup);
                                            $filesize = filesize($backup);
                                            $date = date('Y-m-d H:i:s', filemtime($backup));
                                ?>
                                            <div class="backup-item">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($filename); ?></strong><br>
                                                    <small><?php echo $date; ?> • <?php echo formatFileSize($filesize); ?></small>
                                                </div>
                                                <div class="backup-actions">
                                                    <button class="btn btn-sm" onclick="downloadBackup('<?php echo $filename; ?>')">
                                                        <i class="fas fa-download"></i> Download
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" onclick="deleteBackup('<?php echo $filename; ?>')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </div>
                                            </div>
                                <?php
                                        }
                                    } else {
                                        echo '<p>No backups found.</p>';
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="settings-section danger-zone">
                        <h3 class="section-title">
                            <i class="fas fa-exclamation-triangle"></i> Database Restore
                        </h3>

                        <form method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update_database">

                            <div class="info-box" style="background: #fff3cd;">
                                <i class="fas fa-exclamation-circle"></i>
                                <strong>Warning:</strong> Restoring a backup will overwrite all current data.
                                This action cannot be undone. Make sure you have a current backup.
                            </div>

                            <div class="form-group">
                                <label class="form-label">Select Backup File</label>
                                <div class="file-input" onclick="document.getElementById('restoreFile').click()">
                                    <i class="fas fa-upload fa-2x" style="color: #666; margin-bottom: 10px;"></i><br>
                                    <span>Click to upload SQL backup file</span>
                                    <input type="file" name="restore_file" id="restoreFile"
                                        accept=".sql,.txt" style="display: none;"
                                        onchange="document.getElementById('fileName').textContent = this.files[0].name">
                                    <div id="fileName" style="margin-top: 10px; color: #666;"></div>
                                </div>
                            </div>

                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" name="confirm_restore" id="confirm_restore" required>
                                    <label for="confirm_restore">I understand this will overwrite all current data</label>
                                </div>
                            </div>

                            <div class="btn-group">
                                <button type="submit" class="btn btn-danger" onclick="return confirm('Are you absolutely sure? This will overwrite ALL current data.')">
                                    <i class="fas fa-history"></i> Restore Database
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="settings-section">
                        <h3 class="section-title">
                            <i class="fas fa-tools"></i> Database Maintenance
                        </h3>

                        <div class="form-group">
                            <button class="btn btn-warning" onclick="optimizeDatabase()">
                                <i class="fas fa-broom"></i> Optimize Database
                            </button>
                            <small class="form-text">Optimize database tables for better performance</small>
                        </div>

                        <div class="form-group">
                            <button class="btn" onclick="clearOldSessions()" style="background: #e0e0e0;">
                                <i class="fas fa-trash-alt"></i> Clear Old Sessions
                            </button>
                            <small class="form-text">Remove expired exam and login sessions</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });

        // Tab switching
        document.querySelectorAll('.tab-btn').forEach(button => {
            button.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');

                // Update active tab button
                document.querySelectorAll('.tab-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');

                // Show corresponding content
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.getElementById(tabId + '-tab').classList.add('active');
            });
        });

        // Toggle negative marking input
        const negativeMarkingCheckbox = document.getElementById('enable_negative_marking');
        const negativeMarkingInput = document.querySelector('input[name="negative_mark_percentage"]');

        negativeMarkingCheckbox.addEventListener('change', function() {
            negativeMarkingInput.disabled = !this.checked;
        });

        // Test email settings
        function testEmailSettings() {
            const email = prompt('Enter email address to send test email:');
            if (email) {
                alert('Test email would be sent to: ' + email + '\n\nThis feature requires proper email configuration.');
            }
        }

        // Backup functions
        function downloadBackup(filename) {
            window.location.href = '../backups/' + filename;
        }

        function deleteBackup(filename) {
            if (confirm('Are you sure you want to delete this backup?\n' + filename)) {
                fetch('delete_backup.php?file=' + encodeURIComponent(filename))
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Backup deleted successfully');
                            location.reload();
                        } else {
                            alert('Error deleting backup: ' + data.error);
                        }
                    })
                    .catch(error => {
                        alert('Error: ' + error);
                    });
            }
        }

        // Database functions
        function optimizeDatabase() {
            if (confirm('Optimize database tables for better performance?')) {
                fetch('optimize_database.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Database optimized successfully');
                        } else {
                            alert('Error: ' + data.error);
                        }
                    })
                    .catch(error => {
                        alert('Error: ' + error);
                    });
            }
        }

        function clearOldSessions() {
            if (confirm('Clear expired exam and login sessions?')) {
                fetch('clear_sessions.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                        } else {
                            alert('Error: ' + data.error);
                        }
                    })
                    .catch(error => {
                        alert('Error: ' + error);
                    });
            }
        }

        // File input display
        document.getElementById('restoreFile').addEventListener('change', function(e) {
            const fileName = document.getElementById('fileName');
            if (this.files.length > 0) {
                fileName.textContent = this.files[0].name;
                fileName.style.color = '#333';
            } else {
                fileName.textContent = 'No file selected';
                fileName.style.color = '#666';
            }
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

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Set default tab from URL hash
            const hash = window.location.hash.substring(1);
            if (hash) {
                const tabBtn = document.querySelector(`.tab-btn[data-tab="${hash}"]`);
                if (tabBtn) {
                    tabBtn.click();
                }
            }
        });
    </script>
</body>

</html>

<?php
// Helper function to format file size
function formatFileSize($bytes)
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>