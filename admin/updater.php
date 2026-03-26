<?php
// admin/updater.php - Automatic Update System
session_start();

// Check admin access
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: ../login.php");
    exit();
}

// Only super_admin can perform updates
if ($_SESSION['admin_role'] !== 'admin') {
    header("Location: index.php?message=Access denied&type=error");
    exit();
}

require_once '../includes/config.php';

// Get current version
$current_version = defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '1.0.0';

// GitHub repository info (change to your repo)
$github_repo = 'innio31/cbt'; // Change this
$github_api_url = "https://api.github.com/repos/{$github_repo}/releases/latest";

// Function to check for updates
function checkForUpdates($current_version)
{
    global $github_api_url;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $github_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'CBT-System-Updater/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        return ['error' => 'Unable to check for updates. GitHub API returned: ' . $http_code];
    }

    $release = json_decode($response, true);

    if (!$release || !isset($release['tag_name'])) {
        return ['error' => 'Invalid response from GitHub'];
    }

    $latest_version = ltrim($release['tag_name'], 'v');
    $update_available = version_compare($latest_version, $current_version, '>');

    return [
        'update_available' => $update_available,
        'current_version' => $current_version,
        'latest_version' => $latest_version,
        'release_notes' => $release['body'] ?? '',
        'download_url' => $release['zipball_url'] ?? '',
        'published_at' => $release['published_at'] ?? ''
    ];
}

// Handle update actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$message_type = '';

if ($action === 'check') {
    $update_info = checkForUpdates($current_version);
} elseif ($action === 'update') {
    // Perform the update
    $update_file = $_FILES['update_file'] ?? null;

    if ($update_file && $update_file['error'] === UPLOAD_ERR_OK) {
        // Process uploaded zip file
        $temp_file = $update_file['tmp_name'];
        $extract_path = '../temp_update/';

        // Create temp directory if not exists
        if (!file_exists($extract_path)) {
            mkdir($extract_path, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($temp_file) === true) {
            $zip->extractTo($extract_path);
            $zip->close();

            // Run update script if exists
            if (file_exists($extract_path . 'update.php')) {
                include $extract_path . 'update.php';
            }

            // Copy files to main directory (excluding config.php and other important files)
            copyUpdatedFiles($extract_path);

            // Clean up
            deleteDirectory($extract_path);

            $message = "Update completed successfully!";
            $message_type = "success";
        } else {
            $message = "Failed to extract update file.";
            $message_type = "error";
        }
    } else {
        $message = "Please upload the update file.";
        $message_type = "error";
    }
} elseif ($action === 'update_from_github') {
    // Download and apply update directly from GitHub
    $update_info = checkForUpdates($current_version);

    if ($update_info['update_available'] && isset($update_info['download_url'])) {
        $zip_url = $update_info['download_url'];
        $temp_zip = '../temp_update.zip';

        // Download the zip file
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $zip_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CBT-System-Updater/1.0');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $zip_data = curl_exec($ch);
        curl_close($ch);

        if ($zip_data) {
            file_put_contents($temp_zip, $zip_data);

            // Extract and update
            $extract_path = '../temp_update/';
            if (!file_exists($extract_path)) {
                mkdir($extract_path, 0755, true);
            }

            $zip = new ZipArchive();
            if ($zip->open($temp_zip) === true) {
                $zip->extractTo($extract_path);
                $zip->close();

                // Get the extracted directory name (GitHub adds a folder with repo name and commit hash)
                $extracted_dirs = glob($extract_path . '*', GLOB_ONLYDIR);
                if (!empty($extracted_dirs)) {
                    $source_dir = $extracted_dirs[0];
                    copyUpdatedFiles($source_dir);
                }

                deleteDirectory($extract_path);
                unlink($temp_zip);

                $message = "Update downloaded and applied successfully!";
                $message_type = "success";
            } else {
                $message = "Failed to extract GitHub update.";
                $message_type = "error";
            }
        } else {
            $message = "Failed to download update from GitHub.";
            $message_type = "error";
        }
    } else {
        $message = "No update available or invalid download URL.";
        $message_type = "warning";
    }
}

function copyUpdatedFiles($source_dir)
{
    // Files and directories to exclude from overwriting
    $exclude = [
        'includes/config.php',
        'temp_update/',
        '.git/',
        'uploads/',
        'backups/',
        'admin/updater.php' // Don't overwrite the updater itself during update
    ];

    $source_dir = rtrim($source_dir, '/');
    $destination = '../';

    // Create a RecursiveDirectoryIterator
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($files as $file) {
        $relative_path = str_replace($source_dir . '/', '', $file->getPathname());

        // Skip excluded files
        $should_skip = false;
        foreach ($exclude as $exclude_pattern) {
            if (strpos($relative_path, $exclude_pattern) === 0) {
                $should_skip = true;
                break;
            }
        }

        if ($should_skip) {
            continue;
        }

        $target = $destination . $relative_path;

        if ($file->isDir()) {
            if (!file_exists($target)) {
                mkdir($target, 0755, true);
            }
        } else {
            // Create directory if not exists
            $target_dir = dirname($target);
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0755, true);
            }

            // Copy the file
            copy($file->getPathname(), $target);
        }
    }
}

function deleteDirectory($dir)
{
    if (!file_exists($dir)) {
        return true;
    }

    if (!is_dir($dir)) {
        return unlink($dir);
    }

    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }

        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }

    return rmdir($dir);
}

// Get update info for display
$update_info = checkForUpdates($current_version);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Updater - Admin Dashboard</title>
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
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .header p {
            color: #666;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .version-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .version-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
        }

        .update-available {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .no-update {
            background: #e2e3e5;
            color: #383d41;
            border: 1px solid #d6d8db;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-success:hover {
            background: #219653;
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }

        .release-notes {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }

        .backup-warning {
            background: #fff3cd;
            border-left: 4px solid #f39c12;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
        }

        .update-methods {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .update-methods {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-sync-alt"></i> System Updater</h1>
            <p>Update your CBT system to the latest version</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-triangle' : 'info-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="version-info">
            <h2><i class="fas fa-code-branch"></i> Version Information</h2>
            <div class="version-badge">Current Version: <?php echo htmlspecialchars($current_version); ?></div>
            <?php if (isset($update_info) && !isset($update_info['error'])): ?>
                <div class="version-badge" style="margin-left: 10px;">
                    Latest Version: <?php echo htmlspecialchars($update_info['latest_version']); ?>
                </div>
                <div style="margin-top: 10px;">
                    Released: <?php echo date('F j, Y', strtotime($update_info['published_at'])); ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (isset($update_info) && isset($update_info['update_available']) && $update_info['update_available']): ?>
            <div class="update-available">
                <i class="fas fa-download"></i>
                <strong>Update Available!</strong> Version <?php echo htmlspecialchars($update_info['latest_version']); ?> is now available.
            </div>

            <?php if (!empty($update_info['release_notes'])): ?>
                <div class="card">
                    <h3><i class="fas fa-list-ul"></i> Release Notes</h3>
                    <div class="release-notes">
                        <?php echo nl2br(htmlspecialchars($update_info['release_notes'])); ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php elseif (isset($update_info) && !isset($update_info['error'])): ?>
            <div class="no-update">
                <i class="fas fa-check-circle"></i>
                <strong>You're up to date!</strong> You have the latest version installed.
            </div>
        <?php endif; ?>

        <div class="backup-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Important:</strong> Before updating, please backup your database and files!
        </div>

        <div class="update-methods">
            <!-- Method 1: Manual Update -->
            <div class="card">
                <h3><i class="fas fa-upload"></i> Manual Update</h3>
                <p style="margin: 10px 0; color: #666;">Upload the update package (ZIP file) from your computer.</p>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update">
                    <div class="form-group">
                        <label for="update_file">Select Update Package (.zip):</label>
                        <input type="file" name="update_file" id="update_file" class="form-control" accept=".zip" required>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload and Update
                    </button>
                </form>
            </div>

            <!-- Method 2: GitHub Auto-Update -->
            <div class="card">
                <h3><i class="fab fa-github"></i> Auto Update from GitHub</h3>
                <p style="margin: 10px 0; color: #666;">Download and install the latest version directly from GitHub.</p>

                <?php if (isset($update_info) && isset($update_info['update_available']) && $update_info['update_available']): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_from_github">
                        <button type="submit" class="btn btn-success" onclick="return confirm('Download and install update from GitHub? This will overwrite existing files.')">
                            <i class="fab fa-github"></i> Download from GitHub
                        </button>
                    </form>
                <?php else: ?>
                    <button class="btn btn-success" disabled style="opacity: 0.6;">
                        <i class="fab fa-github"></i> No Update Available
                    </button>
                <?php endif; ?>

                <div style="margin-top: 15px;">
                    <a href="?action=check" class="btn btn-warning">
                        <i class="fas fa-sync"></i> Check for Updates
                    </a>
                </div>
            </div>
        </div>

        <!-- Backup Section -->
        <div class="card">
            <h3><i class="fas fa-database"></i> Database Backup</h3>
            <p style="margin: 10px 0; color: #666;">Create a backup of your database before updating.</p>
            <button class="btn btn-primary" onclick="backupDatabase()">
                <i class="fas fa-database"></i> Create Database Backup
            </button>
            <div id="backupResult" style="margin-top: 10px;"></div>
        </div>

        <!-- Update Log -->
        <div class="card">
            <h3><i class="fas fa-history"></i> Update History</h3>
            <div style="max-height: 300px; overflow-y: auto;">
                <?php
                $log_file = '../update_log.txt';
                if (file_exists($log_file)) {
                    $logs = file($log_file, FILE_IGNORE_NEW_LINES);
                    $logs = array_reverse($logs);
                    echo '<ul style="list-style: none;">';
                    foreach (array_slice($logs, 0, 20) as $log) {
                        echo '<li style="padding: 8px 0; border-bottom: 1px solid #eee;"><i class="fas fa-circle" style="font-size: 8px; color: #3498db;"></i> ' . htmlspecialchars($log) . '</li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<p>No update history available.</p>';
                }
                ?>
            </div>
        </div>
    </div>

    <script>
        function backupDatabase() {
            if (confirm('Create a database backup? This may take a few seconds.')) {
                document.getElementById('backupResult').innerHTML = '<div class="alert alert-info">Creating backup... <i class="fas fa-spinner fa-spin"></i></div>';

                fetch('backup.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=backup'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('backupResult').innerHTML = '<div class="alert alert-success">Backup created successfully! <a href="' + data.download_url + '">Download Backup</a></div>';
                        } else {
                            document.getElementById('backupResult').innerHTML = '<div class="alert alert-error">Backup failed: ' + data.message + '</div>';
                        }
                    })
                    .catch(error => {
                        document.getElementById('backupResult').innerHTML = '<div class="alert alert-error">Backup failed: ' + error + '</div>';
                    });
            }
        }

        // Auto-check for updates on page load
        window.onload = function() {
            <?php if (!isset($update_info)): ?>
                window.location.href = '?action=check';
            <?php endif; ?>
        };
    </script>
</body>

</html>