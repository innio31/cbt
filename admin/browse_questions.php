<?php
// Start session at the VERY beginning
session_start();

// Debug - remove after fixing
error_log("Browse Questions - Session: " . print_r($_SESSION, true));

// Check authentication based on your session variables
$authenticated = false;

if (isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0) {
    $authenticated = true;
} else if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
    $authenticated = true;
} else if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin') {
    $authenticated = true;
} else if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] == 1) {
    if (isset($_SESSION['admin_username'])) {
        $authenticated = true;
    }
}

if (!$authenticated) {
    if (file_exists('../includes/auth.php')) {
        include_once '../includes/auth.php';
        if (isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0) {
            $authenticated = true;
        }
    }

    if (!$authenticated) {
        error_log("Browse Questions - Authentication failed, redirecting to index");
        header('Location: ../index.php');
        exit;
    }
}

// Now include necessary files
require_once '../includes/central_sync.php';
require_once '../includes/config.php';

$message = '';
$message_type = '';

// Get filter parameters for CENTRAL server (for preview)
$central_subject = isset($_GET['central_subject_id']) ? intval($_GET['central_subject_id']) : 0;
$central_topic = isset($_GET['central_topic_id']) ? intval($_GET['central_topic_id']) : 0;
$filter_difficulty = isset($_GET['difficulty']) ? $_GET['difficulty'] : '';
$filter_class = isset($_GET['class']) ? $_GET['class'] : '';
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'objective';
$preview_mode = isset($_GET['preview']) ? true : false;

// Get LOCAL subjects and topics for mapping (where to import to)
$local_subject = isset($_GET['local_subject_id']) ? intval($_GET['local_subject_id']) : 0;
$local_topic = isset($_GET['local_topic_id']) ? intval($_GET['local_topic_id']) : 0;

// Get available subjects from central
$central_subjects = $centralSync->getAvailableSubjects();

// Get local subjects from your database - FIXED: removed subject_code
$local_subjects = [];
try {
    // Check if subjects table exists
    $tables = $pdo->query("SHOW TABLES LIKE 'subjects'")->rowCount();

    if ($tables > 0) {
        // FIXED: Only select columns that exist - removed subject_code
        $stmt = $pdo->query("SELECT id, subject_name, description FROM subjects ORDER BY subject_name");
        $local_subjects = $stmt->fetchAll();

        error_log("Local subjects found: " . count($local_subjects));
    } else {
        error_log("subjects table does not exist");

        // Create subjects table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS subjects (
                id INT PRIMARY KEY AUTO_INCREMENT,
                subject_name VARCHAR(100) NOT NULL,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                last_sync TIMESTAMP NULL
            )
        ");

        // Fetch again
        $stmt = $pdo->query("SELECT id, subject_name, description FROM subjects ORDER BY subject_name");
        $local_subjects = $stmt->fetchAll();
    }
} catch (Exception $e) {
    error_log("Error fetching local subjects: " . $e->getMessage());
    $message = "Database error: " . $e->getMessage();
    $message_type = 'error';
}

// Get central topics if central subject selected
$central_topics = [];
if ($central_subject > 0) {
    $topics_result = $centralSync->getTopics($central_subject);
    if ($topics_result['success']) {
        $central_topics = $topics_result['topics'];
    }
}

// Get local topics if local subject selected - FIXED: check if topics table exists
$local_topics = [];
if ($local_subject > 0) {
    try {
        // Check if topics table exists
        $tables = $pdo->query("SHOW TABLES LIKE 'topics'")->rowCount();

        if ($tables > 0) {
            $stmt = $pdo->prepare("SELECT id, topic_name FROM topics WHERE subject_id = ? ORDER BY topic_name");
            $stmt->execute([$local_subject]);
            $local_topics = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        error_log("Error fetching local topics: " . $e->getMessage());
    }
}

// Preview questions from central
$preview_results = null;
if ($central_subject > 0 && isset($_GET['preview'])) {
    $params = [
        'subject_id' => $central_subject,
        'topic_id' => $central_topic,
        'class' => $filter_class,
        'difficulty' => $filter_difficulty,
        'limit' => intval($_GET['limit'] ?? 20),
        'type' => $filter_type
    ];

    // Debug: Print what we're sending
    echo "<!-- DEBUG: Sending params to central: " . htmlspecialchars(print_r($params, true)) . " -->";
    error_log("Sending to central: " . print_r($params, true));

    $preview_results = $centralSync->previewQuestions($params);

    // Debug: Print what we received
    echo "<!-- DEBUG: Received from central: " . htmlspecialchars(print_r($preview_results, true)) . " -->";
    error_log("Received from central: " . print_r($preview_results, true));
}

// Handle import selected questions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_selected'])) {
    $selected_questions = $_POST['selected'] ?? [];
    $target_subject = intval($_POST['target_subject_id']);
    $target_topic = intval($_POST['target_topic_id']);

    if (empty($selected_questions)) {
        $message = "Please select at least one question to import";
        $message_type = 'error';
    } else if ($target_subject == 0) {
        $message = "Please select where to import these questions (target subject)";
        $message_type = 'error';
    } else {
        $imported = 0;
        $skipped = 0;

        foreach ($selected_questions as $qid) {
            $parts = explode('_', $qid);
            $type = $parts[0];
            $central_id = $parts[1];

            // Check if already exists
            if ($centralSync->questionExists($central_id, $type)) {
                $skipped++;
                continue;
            }

            // Fetch this specific question
            $params = [
                'subject_id' => $central_subject,
                'topic_id' => $central_topic,
                'question_ids' => [$central_id],
                'type' => $type,
                'limit' => 1
            ];

            $result = $centralSync->pullQuestions($params, $target_subject, $target_topic);
            if (isset($result['local_save']) && $result['local_save'] > 0) {
                $imported++;
            }
        }

        if ($imported > 0) {
            $message = "Successfully imported $imported questions!";
            if ($skipped > 0) {
                $message .= " ($skipped questions already existed)";
            }
            $message_type = 'success';
        } else {
            $message = "No new questions were imported. " . ($skipped > 0 ? "$skipped already existed." : "");
            $message_type = 'warning';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Central Question Bank</title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Copy all the styles from your existing browse_questions.php here */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fa;
            color: #333;
        }

        .header {
            background: #2c3e50;
            color: white;
            padding: 15px 30px;
            margin-bottom: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }

        .header h2 {
            margin: 0;
            font-size: 1.5rem;
        }

        .header-links a {
            color: white;
            text-decoration: none;
            margin-left: 20px;
        }

        .header-links a:hover {
            text-decoration: underline;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px 20px;
        }

        .page-title {
            margin-bottom: 30px;
            color: #2c3e50;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .filter-card {
            background: #fff;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .filter-card.central {
            border-top: 4px solid #3498db;
        }

        .filter-card.local {
            border-top: 4px solid #2ecc71;
        }

        .filter-card h3 {
            margin-bottom: 20px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-card h3 i {
            font-size: 1.2rem;
        }

        .filter-card.central h3 i {
            color: #3498db;
        }

        .filter-card.local h3 i {
            color: #2ecc71;
        }

        .badge {
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 12px;
            margin-left: 8px;
        }

        .badge.central {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge.local {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
            font-size: 0.9rem;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.95rem;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            border-color: #3498db;
            outline: none;
        }

        .filter-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #2ecc71;
            color: white;
        }

        .btn-success:hover {
            background: #27ae60;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-warning:hover {
            background: #e67e22;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: slideDown 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @keyframes slideDown {
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
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #2ecc71;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #e74c3c;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #f39c12;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #3498db;
        }

        .mapping-info {
            background: #fff3e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            border-left: 4px solid #f39c12;
        }

        .mapping-info i {
            color: #f39c12;
            font-size: 1.5rem;
        }

        .results-card {
            background: #fff;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .results-header h3 {
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .question-list {
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
        }

        .question-item {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: flex-start;
            gap: 15px;
            transition: background 0.3s;
        }

        .question-item:hover {
            background: #f8f9fa;
        }

        .question-item:last-child {
            border-bottom: none;
        }

        .question-checkbox {
            margin-top: 5px;
        }

        .question-checkbox input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .question-content {
            flex: 1;
        }

        .question-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .badge-difficulty {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-difficulty-easy {
            background: #d4edda;
            color: #155724;
        }

        .badge-difficulty-medium {
            background: #fff3cd;
            color: #856404;
        }

        .badge-difficulty-hard {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-type {
            background: #e3f2fd;
            color: #1976d2;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-existing {
            background: #ffeb3b;
            color: #333;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-new {
            background: #2ecc71;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-central-id {
            background: #9b59b6;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .question-text {
            margin-bottom: 10px;
            line-height: 1.6;
        }

        .question-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .option {
            padding: 5px 10px;
        }

        .option.correct {
            background: #d4edda;
            border-radius: 4px;
            font-weight: 500;
        }

        .target-selector {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            border: 2px dashed #2ecc71;
        }

        .target-selector h4 {
            margin-bottom: 15px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .target-selector .filter-grid {
            grid-template-columns: 1fr 1fr;
        }

        .select-all-bar {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stats-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .stat {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-value {
            font-weight: bold;
            color: #3498db;
            font-size: 1.2rem;
        }

        .loading {
            text-align: center;
            padding: 40px;
        }

        .spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 968px) {
            .filter-section {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
            }

            .filter-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .target-selector .filter-grid {
                grid-template-columns: 1fr;
            }

            .question-item {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="header-content">
            <h2><i class="fas fa-school"></i> School CBT Admin</h2>
            <div class="header-links">
                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="central_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="sync_settings.php"><i class="fas fa-cog"></i> Settings</a>
                <a href="pull_questions.php"><i class="fas fa-download"></i> Quick Pull</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>

    <div class="container">
        <h1 class="page-title">
            <i class="fas fa-search"></i> Browse & Import Questions
        </h1>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>" id="alertMessage">
                <i class="fas fa-<?php
                                    echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle');
                                    ?>"></i>
                <?php echo htmlspecialchars($message); ?>
                <button class="close-alert" onclick="this.parentElement.remove()" style="margin-left: auto; background: none; border: none; font-size: 1.2rem; cursor: pointer;">&times;</button>
            </div>
        <?php endif; ?>

        <div class="mapping-info">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>How importing works:</strong> First, select questions from the central bank (left side). Then, choose where you want to save them in your local database (right side). The subject/topic IDs are different between systems.
            </div>
        </div>

        <!-- Two-column filter section -->
        <div class="filter-section">
            <!-- CENTRAL side - Source -->
            <div class="filter-card central">
                <h3>
                    <i class="fas fa-cloud"></i> Central Question Bank <span class="badge central">Source</span>
                </h3>
                <form method="GET" action="" id="previewForm">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="central_subject_id">Subject (Central)</label>
                            <select name="central_subject_id" id="central_subject_id" required>
                                <option value="">-- Select Central Subject --</option>
                                <?php if ($central_subjects['success'] && !empty($central_subjects['subjects'])): ?>
                                    <?php foreach ($central_subjects['subjects'] as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>"
                                            <?php echo $central_subject == $subject['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            (ID: <?php echo $subject['id']; ?>)
                                            <?php if ($subject['id'] == 2): ?>- 199 questions<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="central_topic_id">Topic (Central)</label>
                            <select name="central_topic_id" id="central_topic_id">
                                <option value="0">All Topics</option>
                                <?php if (!empty($central_topics)): ?>
                                    <?php foreach ($central_topics as $topic): ?>
                                        <option value="<?php echo $topic['id']; ?>"
                                            <?php echo $central_topic == $topic['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($topic['topic_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="type">Question Type</label>
                            <select name="type" id="type">
                                <option value="objective" <?php echo $filter_type == 'objective' ? 'selected' : ''; ?>>Objective</option>
                                <option value="theory" <?php echo $filter_type == 'theory' ? 'selected' : ''; ?>>Theory</option>
                                <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>Both</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="difficulty">Difficulty</label>
                            <select name="difficulty" id="difficulty">
                                <option value="">All</option>
                                <option value="easy" <?php echo $filter_difficulty == 'easy' ? 'selected' : ''; ?>>Easy</option>
                                <option value="medium" <?php echo $filter_difficulty == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="hard" <?php echo $filter_difficulty == 'hard' ? 'selected' : ''; ?>>Hard</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="class">Class Level</label>
                            <select name="class" id="class">
                                <option value="">All Classes</option>
                                <option value="JSS1" <?php echo $filter_class == 'JSS1' ? 'selected' : ''; ?>>JSS 1</option>
                                <option value="JSS2" <?php echo $filter_class == 'JSS2' ? 'selected' : ''; ?>>JSS 2</option>
                                <option value="JSS3" <?php echo $filter_class == 'JSS3' ? 'selected' : ''; ?>>JSS 3</option>
                                <option value="SS1" <?php echo $filter_class == 'SS1' ? 'selected' : ''; ?>>SS 1</option>
                                <option value="SS2" <?php echo $filter_class == 'SS2' ? 'selected' : ''; ?>>SS 2</option>
                                <option value="SS3" <?php echo $filter_class == 'SS3' ? 'selected' : ''; ?>>SS 3</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="limit">Number to Preview</label>
                            <input type="number" name="limit" id="limit" min="1" max="100" value="20">
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" name="preview" value="1" class="btn btn-primary">
                            <i class="fas fa-search"></i> Preview from Central
                        </button>
                        <a href="browse_questions.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- LOCAL side - Destination -->
            <div class="filter-card local">
                <h3>
                    <i class="fas fa-database"></i> Local Database <span class="badge local">Destination</span>
                </h3>

                <div class="target-selector">
                    <h4><i class="fas fa-arrow-right"></i> Where to import these questions?</h4>
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="local_subject_id">Target Subject (Local)</label>
                            <select name="local_subject_id" id="local_subject_id" form="importForm" required>
                                <option value="">-- Select Local Subject --</option>
                                <?php if (!empty($local_subjects)): ?>
                                    <?php foreach ($local_subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>"
                                            <?php echo $local_subject == $subject['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            (ID: <?php echo $subject['id']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No local subjects found</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="local_topic_id">Target Topic (Local) - Optional</label>
                            <select name="local_topic_id" id="local_topic_id" form="importForm">
                                <option value="0">-- No Specific Topic --</option>
                                <?php if (!empty($local_topics)): ?>
                                    <?php foreach ($local_topics as $topic): ?>
                                        <option value="<?php echo $topic['id']; ?>"
                                            <?php echo $local_topic == $topic['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($topic['topic_name']); ?>
                                            (ID: <?php echo $topic['id']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Results Section -->
        <?php if ($preview_results): ?>
            <?php if (isset($preview_results['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    Error: <?php echo htmlspecialchars($preview_results['error']); ?>
                </div>
            <?php else: ?>
                <?php
                $total_objective = isset($preview_results['objective']['count']) ? $preview_results['objective']['count'] : 0;
                $total_theory = isset($preview_results['theory']['count']) ? $preview_results['theory']['count'] : 0;
                $total_questions = $total_objective + $total_theory;
                ?>

                <?php if ($total_questions == 0): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        No questions found for the selected filters. This could mean:
                        <ul style="margin-top: 10px; margin-left: 20px;">
                            <li>The central server has no questions for this subject/topic</li>
                            <li>The subject/topic IDs don't match what the central server expects</li>
                            <li>There might be an issue with the API connection</li>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="results-card">
                    <div class="results-header">
                        <h3>
                            <i class="fas fa-list"></i>
                            Available Questions from Central
                            <?php if ($total_questions > 0): ?>
                                <span class="badge-type">Total: <?php echo $total_questions; ?></span>
                            <?php endif; ?>
                        </h3>

                        <div>
                            <button class="btn btn-success btn-sm" onclick="selectAll()">
                                <i class="fas fa-check-double"></i> Select All New
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="deselectAll()">
                                <i class="fas fa-times"></i> Deselect All
                            </button>
                        </div>
                    </div>

                    <form method="POST" action="" id="importForm">
                        <input type="hidden" name="target_subject_id" id="hidden_target_subject" value="<?php echo $local_subject; ?>">
                        <input type="hidden" name="target_topic_id" id="hidden_target_topic" value="<?php echo $local_topic; ?>">

                        <div class="select-all-bar">
                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)">
                            <label for="selectAllCheckbox">Select/Deselect All New Questions</label>
                            <span style="margin-left: auto;">
                                <span id="selectedCount">0</span> new questions selected
                            </span>
                        </div>

                        <div class="question-list">
                            <?php
                            $has_questions = false;

                            // Display objective questions
                            if (isset($preview_results['objective']['questions'])):
                                foreach ($preview_results['objective']['questions'] as $q):
                                    $has_questions = true;
                                    $exists = $q['exists_locally'] ?? false;
                            ?>
                                    <div class="question-item">
                                        <div class="question-checkbox">
                                            <input type="checkbox"
                                                name="selected[]"
                                                value="objective_<?php echo $q['id']; ?>"
                                                class="question-select"
                                                onchange="updateSelectedCount()"
                                                <?php echo $exists ? 'disabled' : ''; ?>>
                                        </div>
                                        <div class="question-content">
                                            <div class="question-badges">
                                                <span class="badge-type">
                                                    <i class="fas fa-list"></i> Objective
                                                </span>
                                                <span class="badge-difficulty badge-difficulty-<?php echo $q['difficulty_level']; ?>">
                                                    <i class="fas fa-<?php
                                                                        echo $q['difficulty_level'] == 'easy' ? 'smile' : ($q['difficulty_level'] == 'medium' ? 'meh' : 'frown');
                                                                        ?>"></i>
                                                    <?php echo ucfirst($q['difficulty_level']); ?>
                                                </span>
                                                <span class="badge-central-id">
                                                    <i class="fas fa-hashtag"></i> Central ID: <?php echo $q['id']; ?>
                                                </span>
                                                <?php if ($exists): ?>
                                                    <span class="badge-existing">
                                                        <i class="fas fa-check"></i> Already Imported
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge-new">
                                                        <i class="fas fa-plus"></i> New
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="question-text">
                                                <?php echo htmlspecialchars(substr($q['question_text'], 0, 200)); ?>
                                                <?php if (strlen($q['question_text']) > 200): ?>...<?php endif; ?>
                                            </div>

                                            <div class="question-options">
                                                <div class="option <?php echo $q['correct_answer'] == 'A' ? 'correct' : ''; ?>">
                                                    <strong>A:</strong> <?php echo htmlspecialchars(substr($q['option_a'], 0, 50)); ?>
                                                </div>
                                                <div class="option <?php echo $q['correct_answer'] == 'B' ? 'correct' : ''; ?>">
                                                    <strong>B:</strong> <?php echo htmlspecialchars(substr($q['option_b'], 0, 50)); ?>
                                                </div>
                                                <div class="option <?php echo $q['correct_answer'] == 'C' ? 'correct' : ''; ?>">
                                                    <strong>C:</strong> <?php echo htmlspecialchars(substr($q['option_c'], 0, 50)); ?>
                                                </div>
                                                <div class="option <?php echo $q['correct_answer'] == 'D' ? 'correct' : ''; ?>">
                                                    <strong>D:</strong> <?php echo htmlspecialchars(substr($q['option_d'], 0, 50)); ?>
                                                </div>
                                            </div>

                                            <?php if (!empty($q['explanation'])): ?>
                                                <div style="margin-top: 10px; font-size: 0.9rem; color: #666;">
                                                    <i class="fas fa-info-circle"></i>
                                                    <?php echo htmlspecialchars(substr($q['explanation'], 0, 100)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php
                                endforeach;
                            endif;

                            // Display theory questions
                            if (isset($preview_results['theory']['questions'])):
                                foreach ($preview_results['theory']['questions'] as $q):
                                    $has_questions = true;
                                    $exists = $q['exists_locally'] ?? false;
                                ?>
                                    <div class="question-item">
                                        <div class="question-checkbox">
                                            <input type="checkbox"
                                                name="selected[]"
                                                value="theory_<?php echo $q['id']; ?>"
                                                class="question-select"
                                                onchange="updateSelectedCount()"
                                                <?php echo $exists ? 'disabled' : ''; ?>>
                                        </div>
                                        <div class="question-content">
                                            <div class="question-badges">
                                                <span class="badge-type">
                                                    <i class="fas fa-pencil-alt"></i> Theory
                                                </span>
                                                <span class="badge-difficulty badge-difficulty-<?php echo $q['difficulty_level']; ?>">
                                                    <i class="fas fa-<?php
                                                                        echo $q['difficulty_level'] == 'easy' ? 'smile' : ($q['difficulty_level'] == 'medium' ? 'meh' : 'frown');
                                                                        ?>"></i>
                                                    <?php echo ucfirst($q['difficulty_level']); ?>
                                                </span>
                                                <span class="badge-central-id">
                                                    <i class="fas fa-hashtag"></i> Central ID: <?php echo $q['id']; ?>
                                                </span>
                                                <?php if ($exists): ?>
                                                    <span class="badge-existing">
                                                        <i class="fas fa-check"></i> Already Imported
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge-new">
                                                        <i class="fas fa-plus"></i> New
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="question-text">
                                                <?php echo htmlspecialchars(substr($q['question_text'] ?? '', 0, 300)); ?>
                                                <?php if (strlen($q['question_text'] ?? '') > 300): ?>...<?php endif; ?>
                                            </div>

                                            <?php if (!empty($q['model_answer'])): ?>
                                                <div style="margin-top: 10px; padding: 10px; background: #e8f4fd; border-radius: 4px;">
                                                    <strong><i class="fas fa-check-circle"></i> Model Answer:</strong><br>
                                                    <?php echo htmlspecialchars(substr($q['model_answer'], 0, 150)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php
                                endforeach;
                            endif;

                            if (!$has_questions):
                                ?>
                                <div style="text-align: center; padding: 40px; color: #666;">
                                    <i class="fas fa-search" style="font-size: 3rem; margin-bottom: 15px;"></i>
                                    <p>No questions found matching your criteria.</p>
                                    <p>Try adjusting your filters or select a different subject.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="stats-box">
                            <div class="stat">
                                <i class="fas fa-check-circle" style="color: #2ecc71;"></i>
                                <span class="stat-value" id="newCount">0</span>
                                <span>new questions selected</span>
                            </div>
                            <div class="stat">
                                <i class="fas fa-ban" style="color: #e74c3c;"></i>
                                <span class="stat-value" id="disabledCount">0</span>
                                <span>already imported</span>
                            </div>
                        </div>

                        <div style="margin-top: 20px; display: flex; gap: 15px; justify-content: flex-end;">
                            <button type="submit" name="import_selected" value="1" class="btn btn-success" onclick="return validateAndConfirm()">
                                <i class="fas fa-download"></i> Import Selected to Local Database
                            </button>
                            <a href="browse_questions.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // Auto-hide alert after 5 seconds
        const alertMessage = document.getElementById('alertMessage');
        if (alertMessage) {
            setTimeout(function() {
                alertMessage.style.transition = 'opacity 0.5s';
                alertMessage.style.opacity = '0';
                setTimeout(function() {
                    alertMessage.remove();
                }, 500);
            }, 5000);
        }

        // Load central topics when central subject changes
        document.getElementById('central_subject_id').addEventListener('change', function() {
            if (this.value) {
                const url = new URL(window.location.href);
                url.searchParams.set('central_subject_id', this.value);
                url.searchParams.delete('central_topic_id');
                window.location.href = url.toString();
            }
        });

        // Load local topics when local subject changes
        document.getElementById('local_subject_id').addEventListener('change', function() {
            if (this.value) {
                const url = new URL(window.location.href);
                url.searchParams.set('local_subject_id', this.value);
                url.searchParams.delete('local_topic_id');
                window.location.href = url.toString();
            }
        });

        // Update hidden fields when local selections change
        document.getElementById('local_subject_id').addEventListener('change', function() {
            document.getElementById('hidden_target_subject').value = this.value;
        });

        document.getElementById('local_topic_id').addEventListener('change', function() {
            document.getElementById('hidden_target_topic').value = this.value;
        });

        // Select/Deselect all functionality
        function toggleSelectAll(source) {
            const checkboxes = document.querySelectorAll('.question-select:not(:disabled)');
            checkboxes.forEach(checkbox => {
                checkbox.checked = source.checked;
            });
            updateSelectedCount();
        }

        function selectAll() {
            const checkboxes = document.querySelectorAll('.question-select:not(:disabled)');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            document.getElementById('selectAllCheckbox').checked = true;
            updateSelectedCount();
        }

        function deselectAll() {
            const checkboxes = document.querySelectorAll('.question-select');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            document.getElementById('selectAllCheckbox').checked = false;
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.question-select:checked');
            const allCheckboxes = document.querySelectorAll('.question-select');
            const disabledCheckboxes = document.querySelectorAll('.question-select:disabled');

            document.getElementById('selectedCount').textContent = checkboxes.length;
            document.getElementById('newCount').textContent = checkboxes.length;
            document.getElementById('disabledCount').textContent = disabledCheckboxes.length;

            const selectAll = document.getElementById('selectAllCheckbox');
            const totalEnabled = allCheckboxes.length - disabledCheckboxes.length;

            if (checkboxes.length === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else if (checkboxes.length === totalEnabled) {
                selectAll.checked = true;
                selectAll.indeterminate = false;
            } else {
                selectAll.indeterminate = true;
            }
        }

        function validateAndConfirm() {
            const selected = document.querySelectorAll('.question-select:checked').length;
            const targetSubject = document.getElementById('local_subject_id').value;

            if (selected === 0) {
                alert('Please select at least one question to import.');
                return false;
            }

            if (!targetSubject) {
                alert('Please select a target subject in your local database where these questions will be saved.');
                return false;
            }

            const targetSubjectName = document.getElementById('local_subject_id').options[
                document.getElementById('local_subject_id').selectedIndex
            ].text.split('(')[0].trim();

            return confirm(`Import ${selected} question(s) into "${targetSubjectName}"?\n\nCentral question IDs will be mapped to your local subject/topic IDs.`);
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();

            document.getElementById('hidden_target_subject').value = document.getElementById('local_subject_id').value;
            document.getElementById('hidden_target_topic').value = document.getElementById('local_topic_id').value;
        });
    </script>
</body>

</html>