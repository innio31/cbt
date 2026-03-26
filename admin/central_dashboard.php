<?php
// Start session at the VERY beginning
session_start();

// Debug - remove after fixing
error_log("Central Dashboard - Session: " . print_r($_SESSION, true));

// Check authentication based on your session variables
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
        // Check if there's an isAdmin() function
        if (function_exists('isAdmin')) {
            if (isAdmin()) {
                $authenticated = true;
            }
        } else {
            // No isAdmin function, check session again after include
            if (isset($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0) {
                $authenticated = true;
            }
        }
    }

    // If still not authenticated, redirect
    if (!$authenticated) {
        error_log("Central Dashboard - Authentication failed, redirecting to index");
        header('Location: ../index.php');
        exit;
    }
}

// Now include necessary files
require_once '../includes/central_sync.php';

// Rest of your dashboard code...
$message = '';
$message_type = '';

// Handle manual sync
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'test_connection') {
        $result = $centralSync->testConnection();
        if ($result['success']) {
            $message = "Connection successful! Connected to: " . ($result['data']['school']['name'] ?? 'Central Server');
            $message_type = 'success';
        } else {
            $message = "Connection failed: " . ($result['error'] ?? 'Unknown error');
            $message_type = 'error';
        }
    }

    if ($_POST['action'] === 'pull_subjects') {
        $result = $centralSync->getSubjects();
        if ($result['success']) {
            $message = "Successfully fetched " . count($result['subjects']) . " subjects";
            $message_type = 'success';
        } else {
            $message = "Failed to fetch subjects: " . ($result['error'] ?? 'Unknown error');
            $message_type = 'error';
        }
    }
}

// Get sync status
$status = $centralSync->getSyncStatus();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Central Question Bank - Sync Dashboard</title>
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

        .sync-dashboard {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .sync-dashboard h1 {
            margin-bottom: 30px;
            color: #2c3e50;
            font-size: 28px;
        }

        .status-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .status-card {
            background: #fff;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
        }

        .status-card:hover {
            transform: translateY(-5px);
        }

        .status-card.connected {
            border-left: 4px solid #2ecc71;
        }

        .status-card.disconnected {
            border-left: 4px solid #e74c3c;
        }

        .status-card h3 {
            margin-bottom: 15px;
            font-size: 1.2rem;
            color: #555;
        }

        .status-card .value {
            font-size: 2.5rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .status-card .label {
            color: #666;
            font-size: 1rem;
        }

        .status-card small {
            display: block;
            margin-top: 10px;
            color: #999;
            font-size: 0.85rem;
        }

        .sync-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .sync-btn {
            padding: 15px 20px;
            border: none;
            border-radius: 8px;
            background: #3498db;
            color: white;
            cursor: pointer;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
            text-decoration: none;
        }

        .sync-btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .sync-btn.warning {
            background: #f39c12;
        }

        .sync-btn.warning:hover {
            background: #e67e22;
        }

        .sync-btn i {
            font-size: 1.2rem;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: slideDown 0.3s ease;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #3498db;
        }

        @media (max-width: 768px) {
            .status-cards {
                grid-template-columns: 1fr;
            }

            .sync-actions {
                grid-template-columns: 1fr;
            }

            .sync-dashboard h1 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>
    <!-- Include your existing header if you have one -->
    <?php if (file_exists('header.php')): ?>
        <?php include 'header.php'; ?>
    <?php else: ?>
        <div style="background: #2c3e50; color: white; padding: 15px 20px; margin-bottom: 20px;">
            <h2 style="margin: 0;">School CBT Admin</h2>
        </div>
    <?php endif; ?>

    <div class="sync-dashboard">
        <h1><i class="fas fa-cloud-download-alt"></i> Central Question Bank Sync</h1>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>" id="alertMessage">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
                <button class="close-alert" onclick="this.parentElement.remove()" style="float: right; background: none; border: none; font-size: 1.2rem; cursor: pointer;">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Status Cards -->
        <div class="status-cards">
            <div class="status-card <?php echo ($status['central_connected'] ?? false) ? 'connected' : 'disconnected'; ?>">
                <h3><i class="fas fa-plug"></i> Connection Status</h3>
                <div class="value"><?php echo ($status['central_connected'] ?? false) ? 'Connected' : 'Disconnected'; ?></div>
                <?php if (!empty($status['central_info']['school']['name'])): ?>
                    <div class="label">
                        <i class="fas fa-school"></i> <?php echo htmlspecialchars($status['central_info']['school']['name']); ?><br>
                        <i class="fas fa-calendar-alt"></i> Expires: <?php echo $status['central_info']['school']['expiry'] ?? 'N/A'; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="status-card">
                <h3><i class="fas fa-database"></i> Questions</h3>
                <div class="value"><?php echo number_format($status['local_questions'] ?? 0); ?></div>
                <div class="label">Local Questions</div>
                <?php if (isset($status['central_questions'])): ?>
                    <small><?php echo number_format($status['central_questions']); ?> available centrally</small>
                <?php endif; ?>
            </div>

            <div class="status-card">
                <h3><i class="fas fa-history"></i> Last Sync</h3>
                <div class="value">
                    <?php
                    if (!empty($status['last_sync'])) {
                        echo date('M d, H:i', strtotime($status['last_sync']));
                    } else {
                        echo 'Never';
                    }
                    ?>
                </div>
                <?php if (!empty($status['last_count'])): ?>
                    <div class="label"><?php echo $status['last_count']; ?> questions synced</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sync Actions -->
        <div class="sync-actions">
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="test_connection">
                <button type="submit" class="sync-btn">
                    <i class="fas fa-plug"></i> Test Connection
                </button>
            </form>

            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="pull_subjects">
                <button type="submit" class="sync-btn">
                    <i class="fas fa-sync"></i> Refresh Subjects
                </button>
            </form>

            <a href="browse_questions.php" class="sync-btn warning">
                <i class="fas fa-download"></i> Pull Questions
            </a>

            <a href="sync_settings.php" class="sync-btn">
                <i class="fas fa-cog"></i> Settings
            </a>
        </div>

        <!-- Quick Setup Guide -->
        <div class="status-card" style="margin-top: 20px; background: #f8f9fa;">
            <h3><i class="fas fa-info-circle"></i> Quick Setup Guide</h3>
            <ol style="margin-left: 20px; margin-top: 10px; line-height: 1.8;">
                <li><strong>Step 1:</strong> Go to <a href="sync_settings.php">Settings</a> and enter your central server URL and API key</li>
                <li><strong>Step 2:</strong> Click "Test Connection" to verify your settings</li>
                <li><strong>Step 3:</strong> Use "Pull Questions" to download questions from the central bank</li>
                <li><strong>Step 4:</strong> Questions will be added to your local database for exam creation</li>
            </ol>
        </div>
    </div>

    <!-- Include your existing footer if you have one -->
    <?php if (file_exists('footer.php')): ?>
        <?php include 'footer.php'; ?>
    <?php endif; ?>

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
    </script>
</body>

</html>