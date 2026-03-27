<?php
// admin/updater.php - Updates entire folders (admin/, student/, staff/)
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

// Function to get all files in a GitHub folder recursively
function getGitHubFiles($folder)
{
    global $github_repo, $branch;
    $url = "https://api.github.com/repos/{$github_repo}/contents/{$folder}?ref={$branch}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'CBT-Updater/1.0');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    $files = [];

    if (is_array($data)) {
        foreach ($data as $item) {
            if ($item['type'] === 'file') {
                $files[] = $item['path'];
            } elseif ($item['type'] === 'dir') {
                $sub_files = getGitHubFiles($item['path']);
                $files = array_merge($files, $sub_files);
            }
        }
    }

    return $files;
}

// Function to download a single file
function downloadFile($file_path)
{
    global $github_repo, $branch;
    $url = "https://raw.githubusercontent.com/{$github_repo}/{$branch}/{$file_path}";

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
        return $content;
    }
    return false;
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    $action = $_GET['action'] ?? '';

    if ($action === 'get_files') {
        $folders = ['admin', 'student', 'staff'];
        $all_files = [];

        foreach ($folders as $folder) {
            $files = getGitHubFiles($folder);
            $all_files = array_merge($all_files, $files);
        }

        // Add individual files
        $individual_files = [
            'version.txt',
            'index.php',
            'login.php',
            'logout.php',
            'includes/functions.php',
            'includes/auth.php',
        ];

        $all_files = array_merge($all_files, $individual_files);

        echo json_encode(['success' => true, 'files' => $all_files]);
        exit();
    }

    if ($action === 'update_file') {
        $file = $_GET['file'] ?? '';

        // Skip config.php
        if (strpos($file, 'config.php') !== false) {
            echo json_encode(['success' => true, 'message' => "⏭ Skipped (preserved): {$file}"]);
            exit();
        }

        $content = downloadFile($file);

        if ($content !== false) {
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
            echo json_encode(['success' => false, 'message' => "✗ Failed to download: {$file}"]);
        }
        exit();
    }

    if ($action === 'update_version') {
        $content = downloadFile('version.txt');

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
            max-width: 1000px;
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

        .log-skip {
            color: #fab387;
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

        .folder-list {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 10px;
        }

        .folder-tag {
            background: #e8f4fc;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            color: #3498db;
        }

        .stats {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin: 15px 0;
        }

        .stat {
            text-align: center;
            padding: 10px;
        }

        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
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
                <p>Updates entire admin/, student/, staff/ folders + core files</p>
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
                <div class="stats">
                    <div class="stat">
                        <div class="stat-number" id="totalFiles">-</div>
                        <div class="stat-label">Total Files</div>
                    </div>
                    <div class="stat">
                        <div class="stat-number" id="updatedFiles">0</div>
                        <div class="stat-label">Updated</div>
                    </div>
                    <div class="stat">
                        <div class="stat-number" id="skippedFiles">0</div>
                        <div class="stat-label">Skipped</div>
                    </div>
                </div>

                <div style="text-align: center;">
                    <button id="updateBtn" class="btn btn-success" onclick="startUpdate()">
                        <i class="fas fa-download"></i> Start Update
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

            <h3><i class="fas fa-folder-open"></i> What Gets Updated</h3>
            <div class="folder-list">
                <span class="folder-tag"><i class="fas fa-folder"></i> admin/ (all files)</span>
                <span class="folder-tag"><i class="fas fa-folder"></i> student/ (all files)</span>
                <span class="folder-tag"><i class="fas fa-folder"></i> staff/ (all files)</span>
                <span class="folder-tag"><i class="fas fa-file-code"></i> includes/functions.php</span>
                <span class="folder-tag"><i class="fas fa-file-code"></i> includes/auth.php</span>
                <span class="folder-tag"><i class="fas fa-file-code"></i> index.php, login.php, logout.php</span>
                <span class="folder-tag"><i class="fas fa-file-code"></i> version.txt</span>
            </div>

            <div class="alert alert-info" style="margin-top: 15px;">
                <i class="fas fa-shield-alt"></i>
                <strong>Preserved:</strong> includes/config.php, uploads/, backups/, vendor/ (TCPDF, PhpSpreadsheet)
            </div>
        </div>
    </div>

    <script>
        let files = [];
        let currentIndex = 0;
        let updateInProgress = false;
        let updatedCount = 0;
        let skippedCount = 0;

        function addLog(message, type = 'info') {
            const logDiv = document.getElementById('progressLog');
            const entry = document.createElement('div');
            entry.className = `log-entry log-${type}`;
            const time = new Date().toLocaleTimeString();
            entry.textContent = `[${time}] ${message}`;
            logDiv.appendChild(entry);
            logDiv.scrollTop = logDiv.scrollHeight;
        }

        function updateStats() {
            document.getElementById('updatedFiles').innerText = updatedCount;
            document.getElementById('skippedFiles').innerText = skippedCount;
            const percent = (currentIndex / files.length) * 100;
            document.getElementById('progressFill').style.width = percent + '%';
        }

        function updateFile(index) {
            if (index >= files.length) {
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

            fetch(`?ajax=1&action=update_file&file=${encodeURIComponent(file)}`)
                .then(response => response.json())
                .then(data => {
                    addLog(data.message, data.success ? (data.message.includes('Skipped') ? 'skip' : 'success') : 'error');

                    if (data.message.includes('Skipped')) {
                        skippedCount++;
                    } else if (data.success) {
                        updatedCount++;
                    }

                    currentIndex++;
                    updateStats();
                    updateFile(currentIndex);
                })
                .catch(error => {
                    addLog(`Error updating ${file}: ${error.message}`, 'error');
                    currentIndex++;
                    updateStats();
                    updateFile(currentIndex);
                });
        }

        function startUpdate() {
            if (updateInProgress) {
                addLog('Update already in progress!', 'warning');
                return;
            }

            if (confirm('Start update?\n\nThis will update ALL files in:\n• admin/ folder\n• student/ folder\n• staff/ folder\n• Core files (index.php, login.php, etc.)\n\nYour config.php and uploaded files will be preserved.\n\nDo not close this page until complete.')) {
                updateInProgress = true;
                currentIndex = 0;
                updatedCount = 0;
                skippedCount = 0;

                // Clear log
                const logDiv = document.getElementById('progressLog');
                logDiv.innerHTML = '';
                logDiv.classList.add('active');

                // Show progress bar
                document.getElementById('progressBar').classList.add('active');

                // Disable button
                const btn = document.getElementById('updateBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fetching file list...';

                addLog('🚀 Starting update process...', 'info');

                // First get all files from GitHub
                fetch('?ajax=1&action=get_files')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.files) {
                            files = data.files;
                            document.getElementById('totalFiles').innerText = files.length;
                            addLog(`Total files to process: ${files.length}`, 'info');
                            addLog('----------------------------------------', 'info');
                            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
                            updateFile(0);
                        } else {
                            addLog('Failed to get file list from GitHub', 'error');
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-download"></i> Start Update';
                            updateInProgress = false;
                        }
                    })
                    .catch(error => {
                        addLog(`Error: ${error.message}`, 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-download"></i> Start Update';
                        updateInProgress = false;
                    });
            }
        }
    </script>
</body>

</html>