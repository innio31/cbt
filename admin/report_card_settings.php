<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit();
}

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $session = $_POST['session'];
    $term = $_POST['term'];
    $template = $_POST['template'] ?? 'default';
    $classes = isset($_POST['classes']) ? $_POST['classes'] : [];
    $max_score = $_POST['max_score'];
    $grading_system = $_POST['grading_system'];
    $next_resumption_date = $_POST['next_resumption_date'];
    $current_resumption_date = $_POST['current_resumption_date'];
    $current_closing_date = $_POST['current_closing_date'];
    $days_school_opened = $_POST['days_school_opened'] ?? 0;

    // Validate classes selection
    if (empty($classes)) {
        $message = "Error: Please select at least one class";
        $message_type = "error";
    } else {
        // Get score types from form
        $score_types = [];
        $total_score_types = 0;

        if (isset($_POST['score_type_name']) && isset($_POST['score_type_max'])) {
            $names = $_POST['score_type_name'];
            $max_scores = $_POST['score_type_max'];

            for ($i = 0; $i < count($names); $i++) {
                if (!empty(trim($names[$i])) && !empty($max_scores[$i])) {
                    $score_types[] = [
                        'name' => trim($names[$i]),
                        'max_score' => $max_scores[$i]
                    ];
                    $total_score_types += $max_scores[$i];
                }
            }
        }

        // Validate total score types matches max score
        if ($total_score_types != $max_score) {
            $message = "Error: Total of score types ($total_score_types) must equal maximum obtainable score ($max_score)";
            $message_type = "error";
        } else {
            try {
                // Serialize classes and score types for storage
                $classes_json = json_encode($classes);
                $score_types_json = json_encode($score_types);

                // Toggle options
                $show_class_position = isset($_POST['show_class_position']) ? 1 : 0;
                $show_subject_position = isset($_POST['show_subject_position']) ? 1 : 0;
                $show_promoted_to = isset($_POST['show_promoted_to']) ? 1 : 0;
                $show_lowest_highest_avg = isset($_POST['show_lowest_highest_avg']) ? 1 : 0;
                $show_lowest_highest_class = isset($_POST['show_lowest_highest_class']) ? 1 : 0;

                // Check if we're updating or inserting multiple records (one per class)
                $success_count = 0;
                $error_count = 0;

                foreach ($classes as $class) {
                    // Check if settings already exist for this session/term/class
                    $stmt = $pdo->prepare("SELECT id FROM report_card_settings WHERE session = ? AND term = ? AND class = ?");
                    $stmt->execute([$session, $term, $class]);

                    if ($stmt->fetch()) {
                        // Update existing
                        $stmt = $pdo->prepare("UPDATE report_card_settings SET 
    max_score = ?, score_types = ?, grading_system = ?,
    next_resumption_date = ?, current_resumption_date = ?, current_closing_date = ?,
    days_school_opened = ?, template = ?,
    show_class_position = ?, show_subject_position = ?, show_promoted_to = ?,
    show_lowest_highest_avg = ?, show_lowest_highest_class = ?,
    updated_at = NOW()
    WHERE session = ? AND term = ? AND class = ?");
                        $stmt->execute([
                            $max_score,
                            $score_types_json,
                            $grading_system,
                            $next_resumption_date,
                            $current_resumption_date,
                            $current_closing_date,
                            $days_school_opened,
                            $template,  // New template value
                            $show_class_position,
                            $show_subject_position,
                            $show_promoted_to,
                            $show_lowest_highest_avg,
                            $show_lowest_highest_class,
                            $session,
                            $term,
                            $class
                        ]);
                        $success_count++;
                    } else {
                        // Insert new
                        $stmt = $pdo->prepare("INSERT INTO report_card_settings 
    (session, term, class, max_score, score_types, grading_system,
     next_resumption_date, current_resumption_date, current_closing_date,
     days_school_opened, template,
     show_class_position, show_subject_position, show_promoted_to,
     show_lowest_highest_avg, show_lowest_highest_class,
     created_at, updated_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([
                            $session,
                            $term,
                            $class,
                            $max_score,
                            $score_types_json,
                            $grading_system,
                            $next_resumption_date,
                            $current_resumption_date,
                            $current_closing_date,
                            $days_school_opened,
                            $template,  // New template value
                            $show_class_position,
                            $show_subject_position,
                            $show_promoted_to,
                            $show_lowest_highest_avg,
                            $show_lowest_highest_class
                        ]);
                        $success_count++;
                    }
                }

                if ($success_count > 0) {
                    $message = "Report card settings saved successfully for $success_count class(es)!";
                    $message_type = "success";
                } else {
                    $message = "Error: No settings were saved";
                    $message_type = "error";
                }
            } catch (Exception $e) {
                $message = "Error saving settings: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// Get available classes
$classes = [];
$stmt = $pdo->query("SELECT DISTINCT class FROM students WHERE status = 'active' ORDER BY class");
$classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get current settings if available (default to first class)
$current_settings = null;
$current_session = date('Y') . '/' . (date('Y') + 1);
$current_term = 'First';
$selected_class = isset($_GET['class']) ? $_GET['class'] : ($classes[0] ?? '');

if ($selected_class) {
    $stmt = $pdo->prepare("SELECT * FROM report_card_settings WHERE session = ? AND term = ? AND class = ?");
    $stmt->execute([$current_session, $current_term, $selected_class]);
    $current_settings = $stmt->fetch();
}

// Decode score types if they exist
$score_types = [];
if ($current_settings && !empty($current_settings['score_types'])) {
    $score_types = json_decode($current_settings['score_types'], true);
} else {
    // Default score types
    $score_types = [
        ['name' => 'CA 1', 'max_score' => 20],
        ['name' => 'CA 2', 'max_score' => 20],
        ['name' => 'Exam', 'max_score' => 60]
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Card Settings - <?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School Management System'; ?></title>

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

        .score-breakdown {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid var(--secondary-color);
        }

        .score-item {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border: 1px solid #eee;
        }

        .score-item input[type="text"] {
            flex: 2;
        }

        .score-item input[type="number"] {
            flex: 1;
            text-align: center;
        }

        .score-total {
            text-align: center;
            margin-top: 20px;
            font-weight: 600;
            padding: 12px;
            border-radius: 8px;
            font-size: 1.1rem;
        }

        .score-total.valid {
            color: var(--success-color);
            background: #d5f4e6;
            border: 1px solid #b8e6cc;
        }

        .score-total.invalid {
            color: var(--danger-color);
            background: #f8d7da;
            border: 1px solid #f5c6cb;
        }

        .btn-score {
            background: var(--secondary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-score:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .btn-score.remove {
            background: var(--danger-color);
        }

        .btn-score.remove:hover {
            background: #c0392b;
        }

        .toggle-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }

        .toggle-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .toggle-option:hover {
            background: #e9ecef;
        }

        .toggle-option input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .toggle-option label {
            margin-bottom: 0;
            cursor: pointer;
            flex: 1;
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

        .alert i {
            font-size: 1.2rem;
        }

        .class-selection {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
        }

        .class-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .class-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .class-option input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .grading-system-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .days-opened-input {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .days-opened-input input {
            width: 100px;
            text-align: center;
        }

        .days-note {
            font-size: 0.85rem;
            color: #666;
            font-style: italic;
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .toggle-options {
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

        .template-preview-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .template-preview {
            border: 2px solid #ddd;
            border-radius: 10px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }

        .template-preview:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            border-color: var(--secondary-color);
        }

        .template-preview.selected {
            border-color: var(--secondary-color);
            background: #e7f3ff;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .template-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .template-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
        }

        .template-default .template-icon {
            background: #3498db;
        }

        .template-modern .template-icon {
            background: #9b59b6;
        }

        .template-classic .template-icon {
            background: #e67e22;
        }

        .template-colorful .template-icon {
            background: #e74c3c;
        }

        .template-minimal .template-icon {
            background: #2ecc71;
        }

        .template-elegant .template-icon {
            background: #34495e;
        }

        .template-name {
            font-weight: 600;
            color: var(--primary-color);
        }

        .template-description {
            font-size: 0.85rem;
            color: #666;
            line-height: 1.5;
        }

        .template-features {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #777;
        }

        .template-features ul {
            margin: 5px 0 0 15px;
        }

        .radio-template {
            display: none;
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
                <li><a href="enter_scores.php"><i class="fas fa-users"></i> Enter Scores</a></li>
                <li><a href="enter_comments.php"><i class="fas fa-chalkboard-teacher"></i> Enter Comments</a></li>
                <li><a href="report_card_settings.php" class="active"><i class="fas fa-cog"></i> Report Card Settings</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-cogs"></i> Report Card Settings</h1>
                <p>Configure report card settings for different classes and terms</p>
            </div>
        </div>

        <div class="container">
            <div class="settings-card">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?>">
                        <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <h2><i class="fas fa-sliders-h"></i> Report Card Configuration</h2>

                <form method="POST" id="settingsForm">
                    <!-- Basic Information -->
                    <div class="form-section">
                        <h3><i class="fas fa-info-circle"></i> Academic Information</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="session">Academic Session:</label>
                                <input type="text" id="session" name="session"
                                    value="<?php echo $current_settings ? $current_settings['session'] : $current_session; ?>"
                                    placeholder="e.g., 2024/2025" required>
                            </div>
                            <div class="form-group">
                                <label for="term">Term:</label>
                                <select id="term" name="term" required>
                                    <option value="First" <?php echo ($current_settings && $current_settings['term'] == 'First') ? 'selected' : ''; ?>>First Term</option>
                                    <option value="Second" <?php echo ($current_settings && $current_settings['term'] == 'Second') ? 'selected' : ''; ?>>Second Term</option>
                                    <option value="Third" <?php echo ($current_settings && $current_settings['term'] == 'Third') ? 'selected' : ''; ?>>Third Term</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Class Selection -->
                    <div class="form-section">
                        <h3><i class="fas fa-chalkboard"></i> Class Selection</h3>
                        <div class="class-selection">
                            <p style="margin-bottom: 10px; color: #666;">Select classes for which these settings should apply:</p>
                            <div class="class-grid">
                                <?php foreach ($classes as $class): ?>
                                    <div class="class-option">
                                        <input type="checkbox" id="class_<?php echo htmlspecialchars($class); ?>"
                                            name="classes[]" value="<?php echo htmlspecialchars($class); ?>"
                                            <?php echo ($current_settings && $current_settings['class'] == $class) ? 'checked' : ''; ?>>
                                        <label for="class_<?php echo htmlspecialchars($class); ?>">
                                            <?php echo htmlspecialchars($class); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (empty($classes)): ?>
                                <p style="color: #666; font-style: italic;">No classes found. Add students first.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Dates -->
                    <div class="form-section">
                        <h3><i class="fas fa-calendar-alt"></i> Important Dates</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="current_resumption_date">Current Term Resumption:</label>
                                <input type="date" id="current_resumption_date" name="current_resumption_date"
                                    value="<?php echo $current_settings ? $current_settings['current_resumption_date'] : ''; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="current_closing_date">Current Term Closing:</label>
                                <input type="date" id="current_closing_date" name="current_closing_date"
                                    value="<?php echo $current_settings ? $current_settings['current_closing_date'] : ''; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="next_resumption_date">Next Term Resumption:</label>
                                <input type="date" id="next_resumption_date" name="next_resumption_date"
                                    value="<?php echo $current_settings ? $current_settings['next_resumption_date'] : ''; ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="days_school_opened">Days School Opened:</label>
                                <div class="days-opened-input">
                                    <input type="number" id="days_school_opened" name="days_school_opened"
                                        value="<?php echo $current_settings ? $current_settings['days_school_opened'] : '90'; ?>"
                                        min="1" max="365" required>
                                    <span class="days-note">Total days school was open this term</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Score Settings -->
                    <div class="form-section">
                        <h3><i class="fas fa-chart-line"></i> Score Settings</h3>
                        <div class="score-breakdown">
                            <div class="form-group">
                                <label for="max_score">Maximum Obtainable Score:</label>
                                <input type="number" id="max_score" name="max_score"
                                    value="<?php echo $current_settings ? $current_settings['max_score'] : '100'; ?>"
                                    min="1" max="200" required>
                            </div>

                            <h4 style="margin: 20px 0 15px 0; color: #555;">Score Types Breakdown:</h4>
                            <div id="score-types-container">
                                <?php foreach ($score_types as $index => $score_type): ?>
                                    <div class="score-item" data-index="<?php echo $index; ?>">
                                        <input type="text" name="score_type_name[]"
                                            value="<?php echo htmlspecialchars($score_type['name']); ?>"
                                            placeholder="e.g., CA 1, Assignment, Project" required>
                                        <input type="number" name="score_type_max[]"
                                            value="<?php echo $score_type['max_score']; ?>"
                                            min="0" max="100" class="score-type-max" required>
                                        <button type="button" class="btn-score remove" onclick="removeScoreType(this)">
                                            <i class="fas fa-trash"></i> Remove
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <button type="button" class="btn-score" onclick="addScoreType()">
                                <i class="fas fa-plus"></i> Add Score Type
                            </button>

                            <div class="score-total" id="score-total-display">
                                Total: <span id="total-score-types">0</span>/<span id="max-score-display">100</span>
                            </div>
                        </div>
                    </div>

                    <!-- Grading System -->
                    <div class="form-section">
                        <h3><i class="fas fa-star"></i> Grading System</h3>
                        <div class="form-group">
                            <label for="grading_system">Select Grading System:</label>
                            <select id="grading_system" name="grading_system" required>
                                <option value="simple" <?php echo ($current_settings && $current_settings['grading_system'] == 'simple') ? 'selected' : ''; ?>>Simple Letter Grading (A-F)</option>
                                <option value="american" <?php echo ($current_settings && $current_settings['grading_system'] == 'american') ? 'selected' : ''; ?>>American Grading System (A+-F)</option>
                                <option value="waec" <?php echo ($current_settings && $current_settings['grading_system'] == 'waec') ? 'selected' : ''; ?>>WAEC Grading System (A1-F9)</option>
                            </select>
                        </div>

                        <div class="grading-system-info" id="grading_info">
                            <!-- Grading system info will be displayed here -->
                        </div>
                    </div>

                    <!-- Display Options -->
                    <div class="form-section">
                        <h3><i class="fas fa-eye"></i> Display Options</h3>
                        <div class="toggle-options">
                            <div class="toggle-option">
                                <input type="checkbox" id="show_class_position" name="show_class_position"
                                    <?php echo ($current_settings && $current_settings['show_class_position']) ? 'checked' : 'checked'; ?>>
                                <label for="show_class_position">Show Class Position</label>
                            </div>
                            <div class="toggle-option">
                                <input type="checkbox" id="show_subject_position" name="show_subject_position"
                                    <?php echo ($current_settings && $current_settings['show_subject_position']) ? 'checked' : 'checked'; ?>>
                                <label for="show_subject_position">Show Subject Position</label>
                            </div>
                            <div class="toggle-option">
                                <input type="checkbox" id="show_promoted_to" name="show_promoted_to"
                                    <?php echo ($current_settings && $current_settings['show_promoted_to']) ? 'checked' : 'checked'; ?>>
                                <label for="show_promoted_to">Show Promoted To</label>
                            </div>
                            <div class="toggle-option">
                                <input type="checkbox" id="show_lowest_highest_avg" name="show_lowest_highest_avg"
                                    <?php echo ($current_settings && $current_settings['show_lowest_highest_avg']) ? 'checked' : 'checked'; ?>>
                                <label for="show_lowest_highest_avg">Show Lowest/Highest Average</label>
                            </div>
                            <div class="toggle-option">
                                <input type="checkbox" id="show_lowest_highest_class" name="show_lowest_highest_class"
                                    <?php echo ($current_settings && $current_settings['show_lowest_highest_class']) ? 'checked' : 'checked'; ?>>
                                <label for="show_lowest_highest_class">Show Lowest/Highest in Class</label>
                            </div>
                        </div>
                    </div>

                    <!-- Template Selection
                    <div class="form-section">
                        <h3><i class="fas fa-palette"></i> Report Card Template</h3>
                        <div class="form-group">
                            <label for="template">Select Report Card Design:</label>
                            <select id="template" name="template" required>
                                <option value="default" <?php echo ($current_settings && $current_settings['template'] == 'default') ? 'selected' : ''; ?>>Default Template (Standard)</option>
                                <option value="modern" <?php echo ($current_settings && $current_settings['template'] == 'modern') ? 'selected' : ''; ?>>Modern Design</option>
                                <option value="classic" <?php echo ($current_settings && $current_settings['template'] == 'classic') ? 'selected' : ''; ?>>Classic Style</option>
                                <option value="colorful" <?php echo ($current_settings && $current_settings['template'] == 'colorful') ? 'selected' : ''; ?>>Colorful Design</option>
                                <option value="minimal" <?php echo ($current_settings && $current_settings['template'] == 'minimal') ? 'selected' : ''; ?>>Minimal Template</option>
                                <option value="elegant" <?php echo ($current_settings && $current_settings['template'] == 'elegant') ? 'selected' : ''; ?>>Elegant Design</option>
                            </select>
                        </div>

                        <div class="template-preview-container" id="templatePreview">
                            Template previews will be displayed here
            </div>

            <div class="grading-system-info" style="margin-top: 15px;">
                <strong>Template Features:</strong>
                <ul style="margin: 10px 0 0 20px; font-size: 0.9rem;">
                    <li><strong>Default:</strong> Standard school report card layout</li>
                    <li><strong>Modern:</strong> Clean, contemporary design with charts</li>
                    <li><strong>Classic:</strong> Traditional formal report card style</li>
                    <li><strong>Colorful:</strong> Bright design suitable for younger students</li>
                    <li><strong>Minimal:</strong> Simplified layout focusing on essential data</li>
                    <li><strong>Elegant:</strong> Professional design with subtle styling</li>
                </ul>
            </div>
        </div> -->

                    <button type="submit" class="btn">
                        <i class="fas fa-save"></i> Save Report Card Settings
                    </button>
                </form>

                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <script>
        let scoreTypeIndex = <?php echo count($score_types); ?>;

        function addScoreType() {
            const container = document.getElementById('score-types-container');
            const newItem = document.createElement('div');
            newItem.className = 'score-item';
            newItem.setAttribute('data-index', scoreTypeIndex);
            newItem.innerHTML = `
                <input type="text" name="score_type_name[]" placeholder="e.g., CA 1, Assignment, Project" required>
                <input type="number" name="score_type_max[]" min="0" max="100" class="score-type-max" required>
                <button type="button" class="btn-score remove" onclick="removeScoreType(this)">
                    <i class="fas fa-trash"></i> Remove
                </button>
            `;
            container.appendChild(newItem);
            scoreTypeIndex++;

            // Add event listeners to new inputs
            const inputs = newItem.querySelectorAll('input');
            inputs.forEach(input => {
                input.addEventListener('input', updateTotalScoreTypes);
            });

            updateTotalScoreTypes();
        }

        function removeScoreType(button) {
            const container = document.getElementById('score-types-container');
            const items = container.querySelectorAll('.score-item');

            if (items.length > 1) {
                button.closest('.score-item').remove();
                updateTotalScoreTypes();
            } else {
                alert('You must have at least one score type.');
            }
        }

        function updateTotalScoreTypes() {
            const maxScoreInput = document.getElementById('max_score');
            const maxScore = parseInt(maxScoreInput.value) || 0;
            const scoreInputs = document.querySelectorAll('.score-type-max');

            let total = 0;
            scoreInputs.forEach(input => {
                total += parseInt(input.value) || 0;
            });

            document.getElementById('total-score-types').textContent = total;
            document.getElementById('max-score-display').textContent = maxScore;

            const display = document.getElementById('score-total-display');
            if (total === maxScore) {
                display.className = 'score-total valid';
            } else {
                display.className = 'score-total invalid';
            }
        }

        function updateGradingInfo() {
            const system = document.getElementById('grading_system').value;
            const infoDiv = document.getElementById('grading_info');

            const gradingSystems = {
                simple: `
                    <strong>Simple Letter Grading:</strong><br>
                    A: 80-100% | B: 70-79% | C: 60-69%<br>
                    D: 50-59% | E: 40-49% | F: 0-39%
                `,
                american: `
                    <strong>American Grading System:</strong><br>
                    A+: 97-100% | A: 93-96% | A-: 90-92%<br>
                    B+: 87-89% | B: 83-86% | B-: 80-82%<br>
                    C+: 77-79% | C: 73-76% | C-: 70-72%<br>
                    D+: 67-69% | D: 63-66% | D-: 60-62%<br>
                    F: 0-59%
                `,
                waec: `
                    <strong>WAEC Grading System:</strong><br>
                    A1: 75-100% | B2: 70-74% | B3: 65-69%<br>
                    C4: 60-64% | C5: 55-59% | C6: 50-54%<br>
                    D7: 45-49% | E8: 40-44% | F9: 0-39%
                `
            };

            infoDiv.innerHTML = gradingSystems[system];
        }

        // Form validation
        document.getElementById('settingsForm').addEventListener('submit', function(e) {
            const classes = document.querySelectorAll('input[name="classes[]"]:checked');
            if (classes.length === 0) {
                e.preventDefault();
                alert('Please select at least one class.');
                return false;
            }

            const maxScore = parseInt(document.getElementById('max_score').value) || 0;
            const scoreInputs = document.querySelectorAll('.score-type-max');
            let total = 0;
            scoreInputs.forEach(input => {
                total += parseInt(input.value) || 0;
            });

            if (total !== maxScore) {
                e.preventDefault();
                alert(`Total score types (${total}) must equal maximum obtainable score (${maxScore}).`);
                return false;
            }
        });

        // Event listeners
        document.getElementById('max_score').addEventListener('input', updateTotalScoreTypes);
        document.getElementById('grading_system').addEventListener('change', updateGradingInfo);

        // Add event listeners to existing score type inputs
        document.querySelectorAll('.score-type-max').forEach(input => {
            input.addEventListener('input', updateTotalScoreTypes);
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

        // Initialize
        updateTotalScoreTypes();
        updateGradingInfo();

        // Select all classes button functionality
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllBtn = document.createElement('button');
            selectAllBtn.type = 'button';
            selectAllBtn.className = 'btn-score';
            selectAllBtn.innerHTML = '<i class="fas fa-check-square"></i> Select All';
            selectAllBtn.style.marginTop = '10px';

            selectAllBtn.addEventListener('click', function() {
                const checkboxes = document.querySelectorAll('input[name="classes[]"]');
                const allChecked = Array.from(checkboxes).every(cb => cb.checked);

                checkboxes.forEach(cb => {
                    cb.checked = !allChecked;
                });

                selectAllBtn.innerHTML = allChecked ?
                    '<i class="fas fa-check-square"></i> Select All' :
                    '<i class="fas fa-square"></i> Deselect All';
            });

            const classSelection = document.querySelector('.class-selection');
            if (classSelection) {
                classSelection.appendChild(selectAllBtn);
            }
        });
        // Template preview data
        const templateData = {
            'default': {
                name: 'Default Template',
                description: 'Standard school report card layout with all essential information',
                icon: 'fas fa-file-alt',
                features: ['Standard layout', 'All sections', 'Easy to read']
            },
            'modern': {
                name: 'Modern Design',
                description: 'Clean, contemporary design with visual charts and modern typography',
                icon: 'fas fa-chart-line',
                features: ['Visual charts', 'Modern typography', 'Color coding']
            },
            'classic': {
                name: 'Classic Style',
                description: 'Traditional formal report card style used by many schools',
                icon: 'fas fa-landmark',
                features: ['Formal layout', 'Traditional design', 'Official appearance']
            },
            'colorful': {
                name: 'Colorful Design',
                description: 'Bright and colorful design suitable for younger students',
                icon: 'fas fa-palette',
                features: ['Bright colors', 'Child-friendly', 'Visual elements']
            },
            'minimal': {
                name: 'Minimal Template',
                description: 'Simplified layout focusing only on essential performance data',
                icon: 'fas fa-stream',
                features: ['Clean design', 'Essential info only', 'Uncluttered']
            },
            'elegant': {
                name: 'Elegant Design',
                description: 'Professional design with subtle styling and elegant typography',
                icon: 'fas fa-gem',
                features: ['Professional look', 'Subtle styling', 'Elegant fonts']
            }
        };

        // Function to update template preview
        function updateTemplatePreview() {
            const templateSelect = document.getElementById('template');
            const previewContainer = document.getElementById('templatePreview');
            const selectedValue = templateSelect.value;

            // Clear existing previews
            previewContainer.innerHTML = '';

            // Create preview for each template
            Object.entries(templateData).forEach(([value, data]) => {
                const isSelected = value === selectedValue;

                const preview = document.createElement('div');
                preview.className = `template-preview template-${value} ${isSelected ? 'selected' : ''}`;
                preview.innerHTML = `
            <div class="template-header">
                <div class="template-icon">
                    <i class="${data.icon}"></i>
                </div>
                <div>
                    <div class="template-name">${data.name}</div>
                    <div class="template-description">${data.description}</div>
                </div>
            </div>
            <div class="template-features">
                <ul>
                    ${data.features.map(feature => `<li>${feature}</li>`).join('')}
                </ul>
            </div>
            <input type="radio" class="radio-template" name="template" value="${value}" ${isSelected ? 'checked' : ''}>
        `;

                // Add click event to select template
                preview.addEventListener('click', function() {
                    templateSelect.value = value;
                    updateTemplatePreview();
                });

                previewContainer.appendChild(preview);
            });
        }

        // Function to handle template selection from dropdown
        function handleTemplateChange() {
            updateTemplatePreview();
        }

        // Initialize template preview when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Add event listener to template dropdown
            const templateSelect = document.getElementById('template');
            if (templateSelect) {
                templateSelect.addEventListener('change', handleTemplateChange);
            }

            // Initialize preview
            updateTemplatePreview();
        });
    </script>
</body>

</html>