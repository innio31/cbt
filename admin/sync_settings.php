<?php
// Start session at the VERY beginning
session_start();

// Debug - remove after fixing
error_log("Sync Settings - Session: " . print_r($_SESSION, true));

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
        error_log("Sync Settings - Authentication failed, redirecting to index");
        header('Location: ../index.php');
        exit;
    }
}

// Now include necessary files
require_once '../includes/central_sync.php';
require_once '../includes/config.php'; // Your existing config

$message = '';
$message_type = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $central_url = rtrim($_POST['central_url'], '/');
    $api_key = $_POST['api_key'];
    $school_code = $_POST['school_code'];
    $auto_sync = isset($_POST['auto_sync']) ? 1 : 0;
    $sync_interval = intval($_POST['sync_interval']);

    try {
        // First, ensure the settings table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS central_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                central_url VARCHAR(255) NOT NULL,
                api_key VARCHAR(100) NOT NULL,
                school_code VARCHAR(50),
                auto_sync BOOLEAN DEFAULT TRUE,
                sync_interval INT DEFAULT 86400,
                last_sync TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        // Check if settings exist
        $check = $pdo->query("SELECT COUNT(*) FROM central_settings")->fetchColumn();

        if ($check == 0) {
            // Insert new settings
            $stmt = $pdo->prepare("
                INSERT INTO central_settings (central_url, api_key, school_code, auto_sync, sync_interval)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$central_url, $api_key, $school_code, $auto_sync, $sync_interval]);
        } else {
            // Update existing settings
            $stmt = $pdo->prepare("
                UPDATE central_settings SET 
                    central_url = ?, 
                    api_key = ?, 
                    school_code = ?,
                    auto_sync = ?,
                    sync_interval = ?
                WHERE id = 1
            ");
            $stmt->execute([$central_url, $api_key, $school_code, $auto_sync, $sync_interval]);
        }

        $message = "Settings saved successfully!";
        $message_type = 'success';
    } catch (PDOException $e) {
        $message = "Error saving settings: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Get current settings
$settings = [];
try {
    $stmt = $pdo->query("SELECT * FROM central_settings LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet
    $settings = [
        'central_url' => '',
        'api_key' => '',
        'school_code' => '',
        'auto_sync' => 1,
        'sync_interval' => 86400
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Central Sync Settings</title>
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

        .settings-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 30px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .settings-container h2 {
            margin-bottom: 30px;
            color: #2c3e50;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .settings-container h2 i {
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

        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group input[type="number"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-group input:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .form-group input.error {
            border-color: #e74c3c;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
        }

        .btn-save {
            background: #2ecc71;
            color: white;
            padding: 14px 28px;
            border: none;
            border-radius: 6px;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-save:hover {
            background: #27ae60;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .btn-test {
            background: #3498db;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }

        .btn-test:hover {
            background: #2980b9;
        }

        .btn-test:disabled {
            background: #95a5a6;
            cursor: not-allowed;
        }

        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #3498db;
            padding: 20px;
            margin: 30px 0;
            border-radius: 5px;
        }

        .info-box h4 {
            margin-bottom: 15px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-box h4 i {
            color: #3498db;
        }

        .info-box code {
            background: #fff;
            padding: 2px 5px;
            border-radius: 3px;
            border: 1px solid #ddd;
            font-family: monospace;
        }

        .info-box ol {
            margin-left: 25px;
            line-height: 1.8;
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

        .form-actions {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .nav-links {
            margin-bottom: 20px;
        }

        .nav-links a {
            color: #3498db;
            text-decoration: none;
            margin-right: 20px;
        }

        .nav-links a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .settings-container {
                margin: 20px;
                padding: 20px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn-save,
            .btn-test {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <!-- Simple header -->
    <div style="background: #2c3e50; color: white; padding: 15px 30px; margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto;">
            <h2 style="margin: 0;">School CBT Admin</h2>
            <div>
                <span style="margin-right: 20px;"><i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                <a href="central_dashboard.php" style="color: white; text-decoration: none; margin-right: 15px;"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="logout.php" style="color: white; text-decoration: none;"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>

    <div class="settings-container">
        <h2>
            <i class="fas fa-cog"></i> Central Sync Settings
        </h2>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>" id="alertMessage">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
                <button class="close-alert" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <form method="POST" id="settingsForm">
            <div class="form-group">
                <label for="central_url">
                    <i class="fas fa-globe"></i> Central Server URL *
                </label>
                <input type="text"
                    id="central_url"
                    name="central_url"
                    value="<?php echo htmlspecialchars($settings['central_url'] ?? ''); ?>"
                    placeholder="https://your-central-domain.com/api"
                    required>
                <small style="color: #666; display: block; margin-top: 5px;">
                    The URL of your central question bank server (e.g., https://cbt-central.com/api)
                </small>
            </div>

            <div class="form-group">
                <label for="api_key">
                    <i class="fas fa-key"></i> API Key *
                </label>
                <input type="text"
                    id="api_key"
                    name="api_key"
                    value="<?php echo htmlspecialchars($settings['api_key'] ?? ''); ?>"
                    placeholder="Enter your 32-character API key"
                    required
                    pattern="[a-f0-9]{32}"
                    title="API key should be 32 hexadecimal characters">
                <small style="color: #666; display: block; margin-top: 5px;">
                    Your unique API key from the central system (32 characters)
                </small>
            </div>

            <div class="form-group">
                <label for="school_code">
                    <i class="fas fa-school"></i> School Code *
                </label>
                <input type="text"
                    id="school_code"
                    name="school_code"
                    value="<?php echo htmlspecialchars($settings['school_code'] ?? ''); ?>"
                    placeholder="e.g., SCH001"
                    required>
                <small style="color: #666; display: block; margin-top: 5px;">
                    Your unique school code from the central system
                </small>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox"
                        id="auto_sync"
                        name="auto_sync"
                        <?php echo ($settings['auto_sync'] ?? 1) ? 'checked' : ''; ?>>
                    <label for="auto_sync">
                        <i class="fas fa-clock"></i> Enable automatic daily sync
                    </label>
                </div>
                <small style="color: #666; display: block; margin-top: 5px; margin-left: 28px;">
                    Automatically pull new questions from central server every day
                </small>
            </div>

            <div class="form-group">
                <label for="sync_interval">
                    <i class="fas fa-hourglass-half"></i> Sync Interval (seconds)
                </label>
                <input type="number"
                    id="sync_interval"
                    name="sync_interval"
                    value="<?php echo htmlspecialchars($settings['sync_interval'] ?? 86400); ?>"
                    min="3600"
                    max="604800"
                    step="3600">
                <small style="color: #666; display: block; margin-top: 5px;">
                    How often to check for new questions (86400 = 24 hours)
                </small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-save">
                    <i class="fas fa-save"></i> Save Settings
                </button>
                <button type="button" class="btn-test" onclick="testConnection()" id="testBtn">
                    <i class="fas fa-plug"></i> Test Connection
                </button>
                <a href="central_dashboard.php" style="color: #666; text-decoration: none;">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </form>

        <div class="info-box">
            <h4>
                <i class="fas fa-info-circle"></i> How to Get Your Credentials
            </h4>
            <ol>
                <li>Log in to your <strong>Central Question Bank Admin Panel</strong></li>
                <li>Go to <strong>Manage Schools</strong> and add your school or get your existing school code</li>
                <li>Copy the <strong>API Key</strong> assigned to your school</li>
                <li>Enter the API key and school code in the fields above</li>
                <li>Click <strong>Test Connection</strong> to verify everything works</li>
                <li>Save settings and start pulling questions!</li>
            </ol>
            <p style="margin-top: 15px; color: #666;">
                <i class="fas fa-question-circle"></i> Need help? Contact your central system administrator.
            </p>
        </div>

        <div style="background: #e8f4fd; padding: 15px; border-radius: 5px; margin-top: 20px;">
            <h4 style="margin-bottom: 10px; color: #2c3e50;">
                <i class="fas fa-rocket"></i> Quick Start
            </h4>
            <p>After saving your settings:</p>
            <ol style="margin-left: 25px;">
                <li>Go to <a href="pull_questions.php">Pull Questions</a> to download questions</li>
                <li>Select subject, topic, and difficulty</li>
                <li>Click "Pull Questions" to add them to your local database</li>
                <li>Use them in your local exams!</li>
            </ol>
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

        // Test connection function
        function testConnection() {
            const url = document.getElementById('central_url').value;
            const apiKey = document.getElementById('api_key').value;
            const schoolCode = document.getElementById('school_code').value;

            if (!url || !apiKey || !schoolCode) {
                alert('Please fill in URL, API Key, and School Code first');
                return;
            }

            // Show loading
            const btn = document.getElementById('testBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';
            btn.disabled = true;

            // Make test request
            fetch('../ajax/test_connection.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        url: url,
                        api_key: apiKey,
                        school_code: schoolCode
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ Connection successful!\n\n' +
                            'Connected to: ' + (data.data?.school?.name || 'Central Server') + '\n' +
                            'Expires: ' + (data.data?.school?.expiry || 'N/A'));
                    } else {
                        alert('❌ Connection failed:\n' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    alert('❌ Connection failed:\n' + error.message);
                })
                .finally(() => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
        }

        // Form validation
        document.getElementById('settingsForm').addEventListener('submit', function(e) {
            const apiKey = document.getElementById('api_key').value;
            const apiKeyPattern = /^[a-f0-9]{32}$/;

            if (!apiKeyPattern.test(apiKey)) {
                e.preventDefault();
                alert('API Key must be 32 hexadecimal characters (0-9, a-f)');
                document.getElementById('api_key').focus();
            }
        });
    </script>
</body>

</html>