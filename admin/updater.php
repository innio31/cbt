<?php
// admin/updater.php - AJAX Version (Shows real progress)
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../includes/config.php';

// ============= CONFIGURATION =============
$github_repo = 'innio31/cbt';
$current_version = defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '1.0.0';
$branch = 'main';
// =========================================

// Function to get latest release
function getLatestRelease($repo)
{
    $url = "https://api.github.com/repos/{$repo}/releases/latest";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'CBT-Updater/1.0');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data ?: null;
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    $action = $_GET['action'] ?? '';

    if ($action === 'update_file') {
        $file = $_GET['file'] ?? '';
        $branch = $_GET['branch'] ?? 'main';

        $url = "https://raw.githubusercontent.com/{$github_repo}/{$branch}/{$file}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CBT-Updater/1.0');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200 && $content) {
            $local_path = '../' . $file;
            $dir = dirname($local_path);

            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }

            if (file_put_contents($local_path, $content)) {
                echo json_encode(['success' => true, 'message' => "✓ Updated: {$file}"]);
            } else {
                echo json_encode(['success' => false, 'message' => "✗ Failed to save: {$file}"]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => "✗ Failed to download: {$file} (HTTP {$http_code})"]);
        }
        exit();
    }

    if ($action === 'update_version') {
        $url = "https://raw.githubusercontent.com/{$github_repo}/{$branch}/version.txt";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CBT-Updater/1.0');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $content = curl_exec($ch);
        curl_close($ch);

        if ($content) {
            $new_version = trim($content);
            file_put_contents('../version.txt', $new_version);
            file_put_contents('../includes/version.php', "<?php\ndefine('SYSTEM_VERSION', '$new_version');\n?>");
            echo json_encode(['success' => true, 'message' => "✓ Version updated to: {$new_version}"]);
        } else {
            echo json_encode(['success' => false, 'message' => "✗ Failed to update version"]);
        }
        exit();
    }

    exit();
}

// Handle form actions
$message = '';
$message_type = '';

if (isset($_POST['check_updates'])) {
    $release = getLatestRelease($github_repo);
    if ($release && isset($release['tag_name'])) {
        $latest_version = ltrim($release['tag_name'], 'v');
        $update_available = version_compare($latest_version, $current_version, '>');

        if ($update_available) {
            $message = "Update available! Version {$latest_version}";
            $message_type = "success";
            $_SESSION['update_available'] = true;
            $_SESSION['latest_version'] = $latest_version;
        } else {
            $message = "You're already on the latest version ({$current_version})";
            $message_type = "info";
        }
    } else {
        $message = "Could not check for updates.";
        $message_type = "error";
    }
}

$current_release = getLatestRelease($github_repo);
$latest_version = $current_release && isset($current_release['tag_name']) ? ltrim($current_release['tag_name'], 'v') : 'Unknown';
$update_available = $current_release && isset($current_release['tag_name']) ? version_compare($latest_version, $current_version, '>') : false;

// List of files to update
$files_to_update = [
    'version.txt',
    'index.php',
    'login.php',
    'logout.php',
    'includes/functions.php',
    'includes/auth.php',
    'admin/index.php',
    'admin/manage-students.php',
    'admin/manage-staff.php',
    'admin/manage-subjects.php',
    'admin/manage-exams.php',
    'admin/view-results.php',
    'admin/reports.php',
    'admin/db_update.php',
    'student/index.php',
    'student/dashboard.php',
    'student/take-exam.php',
    'staff/index.php',
    'staff/dashboard.php',
    'staff/manage-questions.php',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Updater - CBT System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .header {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .btn-back {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .version-section {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .version-number {
            font-size: 2rem;
            font-weight: 700;
            margin: 10px 0;
        }

        .version-number.current {
            color: #2c3e50;
        }

        .version-number.latest {
            color: #27ae60;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #27ae60;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #e74c3c;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #3498db;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-success:hover,
        .btn-primary:hover {
            transform: translateY(-2px);
        }

        .btn-success:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .progress-log {
            background: #1e1e2e;
            color: #cdd6f4;
            padding: 15px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
            display: none;
        }

        .progress-log.active {
            display: block;
        }

        .log-entry {
            padding: 4px 0;
            border-bottom: 1px solid #313244;
        }

        .log-success {
            color: #a6e3a1;
        }

        .log-error {
            color: #f38ba8;
        }

        .log-info {
            color: #89b4fa;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            margin: 15px 0;
            overflow: hidden;
            display: none;
        }

        .progress-bar.active {
            display: block;
        }

        .progress-fill {
            height: 100%;
            background: #27ae60;
            width: 0%;
            transition: width 0.3s;
        }

        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #e9ecef;
        }

        .file-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
            max-height: 150px;
            overflow-y: auto;
        }

        .file-tag {
            background: #e9ecef;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-family: monospace;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
            }

            .version-number {
                font-size: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-sync-alt"></i> System Updater</h1>
                <p>AJAX-powered real-time update</p>
            </div>
            <a href="index.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>

        <div class="card">
            <div class="version-section">
                <div style="display: flex; justify-content: center; gap: 40px; flex-wrap: wrap;">
                    <div>
                        <div class="version-label">CURRENT VERSION</div>
                        <div class="version-number current"><?php echo htmlspecialchars($current_version); ?></div>
                    </div>
                    <div>
                        <div class="version-label">LATEST VERSION</div>
                        <div class="version-number latest"><?php echo htmlspecialchars($latest_version); ?></div>
                    </div>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <div><?php echo htmlspecialchars($message); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($update_available): ?>
                <div style="text-align: center;">
                    <button id="updateBtn" class="btn btn-success" onclick="startUpdate()">
                        <i class="fas fa-download"></i> Start Update (<?php echo count($files_to_update); ?> files)
                    </button>
                </div>
                <div class="progress-bar" id="progressBar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <div class="progress-log" id="progressLog">
                    <div class="log-entry log-info">🔄 Ready to start update...</div>
                </div>
            <?php endif; ?>

            <form method="POST" style="text-align: center; margin-top: 15px;">
                <input type="hidden" name="check_updates" value="1">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Check for Updates</button>
            </form>

            <hr>

            <h3><i class="fas fa-file-alt"></i> Files to Update (<?php echo count($files_to_update); ?> files)</h3>
            <div class="file-list">
                <?php foreach ($files_to_update as $file): ?>
                    <span class="file-tag"><?php echo htmlspecialchars($file); ?></span>
                <?php endforeach; ?>
            </div>

            <div class="alert alert-info" style="margin-top: 15px;">
                <i class="fas fa-shield-alt"></i>
                <strong>Preserved:</strong> includes/config.php, uploads/, backups/, vendor/
            </div>
        </div>
    </div>

    <script>
        const files = <?php echo json_encode($files_to_update); ?>;
        let currentIndex = 0;
        let updateInProgress = false;

        function addLog(message, type = 'info') {
            const logDiv = document.getElementById('progressLog');
            const entry = document.createElement('div');
            entry.className = `log-entry log-${type}`;
            const time = new Date().toLocaleTimeString();
            entry.textContent = `[${time}] ${message}`;
            logDiv.appendChild(entry);
            logDiv.scrollTop = logDiv.scrollHeight;
        }

        function updateProgress() {
            const percent = (currentIndex / files.length) * 100;
            const fill = document.getElementById('progressFill');
            fill.style.width = percent + '%';
        }

        function updateFile(index) {
            if (index >= files.length) {
                // All files updated, now update version
                addLog('All files updated! Updating version...', 'info');

                fetch('?ajax=1&action=update_version')
                    .then(response => response.json())
                    .then(data => {
                        addLog(data.message, data.success ? 'success' : 'error');
                        addLog('✅ UPDATE COMPLETE!', 'success');
                        document.getElementById('updateBtn').disabled = false;
                        document.getElementById('updateBtn').innerHTML = '<i class="fas fa-check"></i> Update Complete!';
                        updateInProgress = false;
                    });
                return;
            }

            const file = files[index];
            addLog(`Downloading: ${file}`, 'info');

            fetch(`?ajax=1&action=update_file&file=${encodeURIComponent(file)}`)
                .then(response => response.json())
                .then(data => {
                    addLog(data.message, data.success ? 'success' : 'error');
                    currentIndex++;
                    updateProgress();
                    updateFile(currentIndex);
                })
                .catch(error => {
                    addLog(`Error updating ${file}: ${error.message}`, 'error');
                    currentIndex++;
                    updateProgress();
                    updateFile(currentIndex);
                });
        }

        function startUpdate() {
            if (updateInProgress) {
                addLog('Update already in progress!', 'warning');
                return;
            }

            if (confirm('Start update? This will update ' + files.length + ' files.\n\nYour config.php and uploaded files will be preserved.\n\nDo not close this page until complete.')) {
                updateInProgress = true;
                currentIndex = 0;

                // Clear log
                const logDiv = document.getElementById('progressLog');
                logDiv.innerHTML = '';
                logDiv.classList.add('active');

                // Show progress bar
                document.getElementById('progressBar').classList.add('active');

                // Disable button
                const btn = document.getElementById('updateBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

                addLog('🚀 Starting update process...', 'info');
                addLog(`Total files: ${files.length}`, 'info');
                addLog('----------------------------------------', 'info');

                // Start updating
                updateFile(0);
            }
        }
    </script>
</body>

</html>