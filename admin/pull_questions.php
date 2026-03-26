<?php
// Start session at the VERY beginning
session_start();

// Debug - remove after fixing
error_log("Pull Questions - Session: " . print_r($_SESSION, true));

// Check authentication based on your session variables (same as dashboard)
$authenticated = false;

// Your system uses these session variables:
// - admin_id = 1
// - admin_username = admin
// - admin_name = Administrator
// - admin_role = admin
// - user_type = admin
// - logged_in = 1

if (isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0) {
    $authenticated = true;
} else if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
    $authenticated = true;
} else if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin') {
    $authenticated = true;
} else if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] == 1) {
    // Also check if it's admin by looking at other indicators
    if (isset($_SESSION['admin_username'])) {
        $authenticated = true;
    }
}

if (!$authenticated) {
    // Try to include auth file as fallback
    if (file_exists('../includes/auth.php')) {
        include_once '../includes/auth.php';
        // Check session again after include
        if (isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0) {
            $authenticated = true;
        }
    }

    // If still not authenticated, redirect
    if (!$authenticated) {
        error_log("Pull Questions - Authentication failed, redirecting to index");
        header('Location: ../index.php');
        exit;
    }
}

// Now include necessary files
require_once '../includes/central_sync.php';
require_once '../includes/config.php';

$message = '';
$message_type = '';

// Get available subjects for dropdown
$subjects = $centralSync->getAvailableSubjects();

// Get topics if subject selected
$topics = [];
$selected_subject = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : (isset($_POST['subject_id']) ? intval($_POST['subject_id']) : 0);

if ($selected_subject > 0) {
    $topics_result = $centralSync->getTopics($selected_subject);
    if ($topics_result['success']) {
        $topics = $topics_result['topics'];
    }
}

// Handle question pull
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $params = [
        'subject_id' => intval($_POST['subject_id'] ?? 0),
        'topic_id' => intval($_POST['topic_id'] ?? 0),
        'class' => $_POST['class'] ?? '',
        'difficulty' => $_POST['difficulty'] ?? '',
        'limit' => intval($_POST['limit'] ?? 50),
        'type' => $_POST['type'] ?? 'objective'
    ];

    if ($params['subject_id'] === 0) {
        $message = "Please select a subject";
        $message_type = 'error';
    } else {
        $result = $centralSync->pullQuestions($params);

        if (isset($result['local_save']) && $result['local_save'] > 0) {
            $message = "Successfully pulled " . $result['local_save'] . " questions!";
            $message_type = 'success';
        } elseif (isset($result['error'])) {
            $message = "Error: " . $result['error'];
            $message_type = 'error';
        } else {
            $message = "No questions pulled. They might already exist in your database.";
            $message_type = 'warning';
        }
    }
}

// Get local question counts by subject
$local_counts = [];
try {
    $stmt = $pdo->query("
        SELECT subject_id, COUNT(*) as count 
        FROM objective_questions 
        GROUP BY subject_id
    ");
    while ($row = $stmt->fetch()) {
        $local_counts[$row['subject_id']] = $row['count'];
    }
} catch (Exception $e) {
    // Table might not exist yet
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pull Questions from Central Bank</title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
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
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
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

        .pull-container {
            max-width: 900px;
            margin: 20px auto;
            padding: 30px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .pull-container h1 {
            margin-bottom: 30px;
            color: #2c3e50;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pull-container h1 i {
            color: #3498db;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }

        .form-group label i {
            color: #3498db;
            margin-right: 8px;
            width: 20px;
        }

        .form-group select,
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-group select:focus,
        .form-group input:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .btn-pull {
            background: #2ecc71;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 6px;
            font-size: 1.1rem;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-pull:hover {
            background: #27ae60;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .btn-pull:disabled {
            background: #95a5a6;
            cursor: not-allowed;
            transform: none;
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

        .close-alert {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: inherit;
            opacity: 0.5;
        }

        .close-alert:hover {
            opacity: 1;
        }

        .preview-section {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .preview-section h3 {
            margin-bottom: 15px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .preview-section h3 i {
            color: #3498db;
        }

        .stats-preview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 15px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #3498db;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
            margin-top: 5px;
        }

        .info-box {
            background: #e8f4fd;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .info-box h4 {
            margin-bottom: 10px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-box ul {
            margin-left: 25px;
            line-height: 1.8;
        }

        .subject-badge {
            display: inline-block;
            padding: 5px 10px;
            background: #e3f2fd;
            color: #1976d2;
            border-radius: 20px;
            font-size: 0.85rem;
            margin: 5px 5px 0 0;
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
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

        @media (max-width: 768px) {
            .pull-container {
                margin: 10px;
                padding: 20px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .stats-preview {
                grid-template-columns: 1fr;
            }

            .header-content {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .header-links a {
                margin: 0 10px;
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
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>

    <div class="pull-container">
        <h1>
            <i class="fas fa-download"></i> Pull Questions from Central Bank
        </h1>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>" id="alertMessage">
                <i class="fas fa-<?php
                                    echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle');
                                    ?>"></i>
                <?php echo htmlspecialchars($message); ?>
                <button class="close-alert" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <form method="POST" id="pullForm" onsubmit="return validateForm()">
            <div class="form-group">
                <label for="type">
                    <i class="fas fa-question-circle"></i> Question Type
                </label>
                <select name="type" id="type" required>
                    <option value="objective">Objective Questions (Multiple Choice)</option>
                    <option value="theory">Theory Questions</option>
                    <option value="all">Both Types</option>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="subject_id">
                        <i class="fas fa-book"></i> Subject *
                    </label>
                    <select name="subject_id" id="subject_id" required onchange="loadTopics(this.value)">
                        <option value="">-- Select Subject --</option>
                        <?php if ($subjects['success'] && !empty($subjects['subjects'])): ?>
                            <?php foreach ($subjects['subjects'] as $subject): ?>
                                <?php
                                $local_count = $local_counts[$subject['id']] ?? 0;
                                $subject['local_count'] = $local_count;
                                ?>
                                <option value="<?php echo $subject['id']; ?>"
                                    data-local="<?php echo $local_count; ?>"
                                    <?php echo $selected_subject == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    (<?php echo $local_count; ?> local)
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled>No subjects available. Check connection to central server.</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="topic_id">
                        <i class="fas fa-tags"></i> Topic (Optional)
                    </label>
                    <select name="topic_id" id="topic_id">
                        <option value="0">All Topics</option>
                        <?php if (!empty($topics)): ?>
                            <?php foreach ($topics as $topic): ?>
                                <option value="<?php echo $topic['id']; ?>">
                                    <?php echo htmlspecialchars($topic['topic_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="class">
                        <i class="fas fa-graduation-cap"></i> Class Level
                    </label>
                    <select name="class" id="class">
                        <option value="">All Classes</option>
                        <option value="JSS1">JSS 1</option>
                        <option value="JSS2">JSS 2</option>
                        <option value="JSS3">JSS 3</option>
                        <option value="SS1">SS 1</option>
                        <option value="SS2">SS 2</option>
                        <option value="SS3">SS 3</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="difficulty">
                        <i class="fas fa-chart-line"></i> Difficulty Level
                    </label>
                    <select name="difficulty" id="difficulty">
                        <option value="">All Difficulties</option>
                        <option value="easy">Easy</option>
                        <option value="medium">Medium</option>
                        <option value="hard">Hard</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="limit">
                    <i class="fas fa-sort-amount-up"></i> Number of Questions (Max 500)
                </label>
                <input type="number" name="limit" id="limit" min="1" max="500" value="50">
            </div>

            <button type="submit" class="btn-pull" id="pullBtn">
                <i class="fas fa-download"></i> Pull Questions
            </button>
        </form>

        <div class="preview-section">
            <h3>
                <i class="fas fa-info-circle"></i> Pull Information
            </h3>
            <p>Questions pulled from the central bank will be added to your local database. They will include:</p>
            <ul style="margin-left: 25px; margin-top: 10px; line-height: 1.8;">
                <li>Full question text with formatting</li>
                <li>All options for objective questions</li>
                <li>Correct answers and explanations</li>
                <li>Subject and topic associations</li>
                <li>Difficulty levels and marks</li>
            </ul>

            <div class="stats-preview">
                <div class="stat-item">
                    <div class="stat-value" id="localCount">0</div>
                    <div class="stat-label">Local Questions</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="pullEstimate">50</div>
                    <div class="stat-label">Will Pull</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="totalAfter">50</div>
                    <div class="stat-label">Total After Pull</div>
                </div>
            </div>
        </div>

        <div class="info-box">
            <h4><i class="fas fa-lightbulb"></i> Tips</h4>
            <ul>
                <li><strong>First time?</strong> Start with a small number (50) to test</li>
                <li><strong>Duplicates:</strong> Questions won't be duplicated - they're tracked by central ID</li>
                <li><strong>Updates:</strong> If a question changes centrally, pulling again will update your local copy</li>
                <li><strong>Topics:</strong> Select a subject first to see available topics</li>
            </ul>
        </div>
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

        // Load topics when subject changes
        function loadTopics(subjectId) {
            if (subjectId) {
                window.location.href = 'pull_questions.php?subject_id=' + subjectId;
            }
        }

        // Update stats when selections change
        document.getElementById('subject_id').addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const localCount = selected.dataset.local || 0;
            document.getElementById('localCount').textContent = localCount;
            updateTotalAfter();
        });

        document.getElementById('limit').addEventListener('input', function() {
            document.getElementById('pullEstimate').textContent = this.value;
            updateTotalAfter();
        });

        function updateTotalAfter() {
            const local = parseInt(document.getElementById('localCount').textContent) || 0;
            const pull = parseInt(document.getElementById('limit').value) || 0;
            document.getElementById('totalAfter').textContent = local + pull;
        }

        // Form validation
        function validateForm() {
            const subject = document.getElementById('subject_id').value;
            const limit = document.getElementById('limit').value;

            if (!subject) {
                alert('Please select a subject');
                return false;
            }

            if (limit < 1 || limit > 500) {
                alert('Please enter a valid number of questions (1-500)');
                return false;
            }

            // Show loading state
            const btn = document.getElementById('pullBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Pulling Questions...';
            btn.disabled = true;

            return true;
        }

        // Confirm before pull
        document.getElementById('pullForm').addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to pull these questions?\n\nThis will download new questions to your local database.')) {
                e.preventDefault();
                return false;
            }
        });

        // Initialize on page load
        window.onload = function() {
            const subjectSelect = document.getElementById('subject_id');
            if (subjectSelect.value) {
                const selected = subjectSelect.options[subjectSelect.selectedIndex];
                const localCount = selected.dataset.local || 0;
                document.getElementById('localCount').textContent = localCount;
                updateTotalAfter();
            }
        };
    </script>
</body>

</html>