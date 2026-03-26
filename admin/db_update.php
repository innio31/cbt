<?php
// admin/db_update.php - Simple Database Structure Updater
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

require_once '../includes/config.php';

// Function to get applied updates
function getAppliedUpdates()
{
    global $pdo;

    try {
        $stmt = $pdo->query("SELECT version FROM db_updates");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

// Create updates tracking table if it doesn't exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS db_updates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(50) NOT NULL,
        name VARCHAR(100) NOT NULL,
        applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_version (version)
    )");
} catch (Exception $e) {
    // Table might already exist
}

// Get applied updates
$applied = getAppliedUpdates();

// Define updates for version 1.1.0 (the first update)
$updates = [
    '1.1.0' => [
        'name' => 'Add Parent Contact Information',
        'sql' => "
            -- Add parent contact columns to students table
            ALTER TABLE students 
            ADD COLUMN IF NOT EXISTS parent_phone VARCHAR(20) AFTER full_name,
            ADD COLUMN IF NOT EXISTS parent_email VARCHAR(100) AFTER parent_phone;
            
            -- Add attendance tracking table
            CREATE TABLE IF NOT EXISTS attendance (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                date DATE NOT NULL,
                status ENUM('present', 'absent', 'late') DEFAULT 'present',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_student_date (student_id, date),
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        "
    ]
];

// Check for pending updates
$pending = [];
foreach ($updates as $version => $update) {
    if (!in_array($version, $applied)) {
        $pending[$version] = $update;
    }
}

// Handle update submission
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_update'])) {
    $version = $_POST['version'];

    if (isset($updates[$version])) {
        try {
            // Start transaction
            $pdo->beginTransaction();

            // Execute the SQL
            $sql = $updates[$version]['sql'];
            $pdo->exec($sql);

            // Record the update
            $stmt = $pdo->prepare("INSERT INTO db_updates (version, name) VALUES (?, ?)");
            $stmt->execute([$version, $updates[$version]['name']]);

            // Commit transaction
            $pdo->commit();

            $message = "✅ Update {$version} applied successfully!";
            $message_type = "success";

            // Refresh applied updates
            $applied = getAppliedUpdates();
            $pending = [];
            foreach ($updates as $v => $u) {
                if (!in_array($v, $applied)) {
                    $pending[$v] = $u;
                }
            }
        } catch (Exception $e) {
            // Rollback on error
            $pdo->rollBack();
            $message = "❌ Error applying update: " . $e->getMessage();
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Structure Update - CBT System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .header h1 {
            margin-bottom: 5px;
        }

        .header p {
            opacity: 0.9;
        }

        .version-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            margin-top: 10px;
            font-size: 14px;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .card h3 {
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ecf0f1;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .update-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 15px;
            overflow: hidden;
        }

        .update-header {
            background: #f8f9fa;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .update-version {
            font-weight: bold;
            color: #3498db;
            font-size: 16px;
        }

        .update-name {
            color: #666;
            font-size: 14px;
        }

        .update-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            background: #fff3cd;
            color: #856404;
        }

        .update-details {
            padding: 20px;
            border-top: 1px solid #e0e0e0;
            background: #fafafa;
            display: none;
        }

        .update-details.show {
            display: block;
        }

        .sql-preview {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            overflow-x: auto;
            margin: 10px 0;
        }

        .sql-preview pre {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
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

        .btn-sm {
            padding: 5px 15px;
            font-size: 12px;
        }

        .backup-note {
            background: #fff3cd;
            border-left: 4px solid #f39c12;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .info-box {
            background: #e8f4fc;
            border-left: 4px solid #3498db;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        tr:hover {
            background: #f8f9fa;
        }

        @media (max-width: 768px) {
            .update-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .update-header>div:last-child {
                width: 100%;
            }

            .btn-sm {
                width: 100%;
                margin-top: 5px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-database"></i> Database Structure Update</h1>
            <p>Update your database structure to match the latest system version</p>
            <div class="version-badge">
                Current System Version: <?php echo defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '1.0.0'; ?>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <strong><?php echo $message_type === 'success' ? '✓' : '⚠'; ?></strong>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="backup-note">
            <strong>⚠️ Important:</strong> Please backup your database before running updates!
            <button class="btn btn-warning btn-sm" onclick="backupDatabase()" style="margin-left: 10px;">
                💾 Backup Database
            </button>
        </div>

        <div class="info-box">
            <strong>ℹ️ What this does:</strong> This tool only ADDS new tables, columns, and indexes.
            It will NOT delete or modify your existing student data. All your current records will remain intact.
        </div>

        <!-- Pending Updates -->
        <div class="card">
            <h3>📋 Pending Structure Updates</h3>

            <?php if (empty($pending)): ?>
                <div class="alert alert-success" style="margin-top: 10px;">
                    ✓ Your database structure is up to date! No updates needed.
                </div>
            <?php else: ?>
                <p style="color: #666; margin-bottom: 15px;">The following updates need to be applied:</p>

                <?php foreach ($pending as $version => $update): ?>
                    <div class="update-item">
                        <div class="update-header">
                            <div>
                                <span class="update-version">Version <?php echo htmlspecialchars($version); ?></span>
                                <span class="update-name"> - <?php echo htmlspecialchars($update['name']); ?></span>
                            </div>
                            <div>
                                <span class="update-status">Pending</span>
                                <button class="btn btn-primary btn-sm" onclick="toggleDetails('<?php echo $version; ?>')" style="margin-left: 10px;">
                                    👁️ View SQL
                                </button>
                                <form method="POST" style="display: inline; margin-left: 10px;"
                                    onsubmit="return confirm('Apply update <?php echo $version; ?>? This will modify your database structure. Your data will be preserved.');">
                                    <input type="hidden" name="apply_update" value="1">
                                    <input type="hidden" name="version" value="<?php echo $version; ?>">
                                    <button type="submit" class="btn btn-success btn-sm">
                                        ✓ Apply Update
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div id="details-<?php echo $version; ?>" class="update-details">
                            <strong>SQL to execute:</strong>
                            <div class="sql-preview">
                                <pre><?php echo htmlspecialchars(trim($update['sql'])); ?></pre>
                            </div>
                            <div class="alert alert-info" style="margin-top: 10px; font-size: 12px;">
                                <strong>Safe to run:</strong> This uses "IF NOT EXISTS" and "ADD COLUMN IF NOT EXISTS"
                                which means it will only add what's missing and won't affect existing data.
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Applied Updates History -->
        <div class="card">
            <h3>📜 Update History</h3>
            <div style="max-height: 300px; overflow-y: auto;">
                <?php
                try {
                    $stmt = $pdo->query("SELECT * FROM db_updates ORDER BY id DESC");
                    $history = $stmt->fetchAll();
                } catch (Exception $e) {
                    $history = [];
                }
                ?>

                <?php if (empty($history)): ?>
                    <p style="color: #999; text-align: center; padding: 20px;">No updates applied yet.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Update Name</th>
                                <th>Applied At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $update): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($update['version']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($update['name']); ?></td>
                                    <td><?php echo date('M d, Y H:i:s', strtotime($update['applied_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- FAQ -->
        <div class="card">
            <h3>❓ Frequently Asked Questions</h3>

            <div style="margin-top: 15px;">
                <p><strong>Will this delete my student data?</strong><br>
                    <strong>No.</strong> This only ADDS new columns and tables. All existing data is preserved.
                </p>

                <p style="margin-top: 10px;"><strong>What if an update fails?</strong><br>
                    The update will roll back automatically. Your database will remain unchanged.</p>

                <p style="margin-top: 10px;"><strong>Do I need to run updates in order?</strong><br>
                    <strong>Yes.</strong> Always run updates in version order. The system will show pending updates in the correct sequence.
                </p>

                <p style="margin-top: 10px;"><strong>Can I skip an update?</strong><br>
                    <strong>No.</strong> To use the latest system features, you need to apply all pending updates.
                </p>
            </div>
        </div>
    </div>

    <script>
        function toggleDetails(version) {
            const detailsDiv = document.getElementById('details-' + version);
            if (detailsDiv.classList.contains('show')) {
                detailsDiv.classList.remove('show');
            } else {
                detailsDiv.classList.add('show');
            }
        }

        function backupDatabase() {
            if (confirm('Create a database backup before updating?')) {
                window.open('backup.php?action=backup', '_blank');
            }
        }

        // Add icons to buttons (simple fallback if FontAwesome not available)
        document.addEventListener('DOMContentLoaded', function() {
            // Check if FontAwesome is loaded
            if (typeof FontAwesome === 'undefined') {
                // Add simple text icons
                const buttons = document.querySelectorAll('.btn');
                buttons.forEach(btn => {
                    if (btn.innerHTML.includes('View SQL')) {
                        btn.innerHTML = '📋 View SQL';
                    }
                    if (btn.innerHTML.includes('Apply Update')) {
                        btn.innerHTML = '✅ Apply Update';
                    }
                    if (btn.innerHTML.includes('Backup Database')) {
                        btn.innerHTML = '💾 Backup Database';
                    }
                });
            }
        });
    </script>
</body>

</html>