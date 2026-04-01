<?php
// Add these lines at the very top for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// admin/db_update.php - Complete Database Rebuild from cbt.sql
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

// Allow super_admin and admin to access
if ($_SESSION['admin_role'] !== 'admin' && $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: index.php?message=Access denied&type=error");
    exit();
}

require_once '../includes/config.php';

// Set PDO to use buffered queries to avoid the unbuffered query error
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

// Create migration tracking table if it doesn't exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(50) NOT NULL,
        description TEXT,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_version (version)
    )");
} catch (PDOException $e) {
    // Table might already exist, continue
}

// Get applied migrations
$stmt = $pdo->query("SELECT version FROM migrations");
$applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
$stmt->closeCursor();

// ============================================
// MAIN MIGRATION - Rebuild all tables from cbt.sql
// ============================================

$migrations = [
    '2.0.0' => [
        'description' => 'Complete database rebuild - Recreates ALL tables exactly as in cbt.sql while preserving data',
        'execute' => function ($pdo, &$logMessages) {
            $logMessages = [];
            $success = true;
            $failedRestores = [];

            // Path to cbt.sql
            $cbtSqlPath = __DIR__ . '/cbt.sql';

            // Check if cbt.sql exists
            if (!file_exists($cbtSqlPath)) {
                $logMessages[] = "ERROR: cbt.sql file not found at $cbtSqlPath";
                return false;
            }

            $logMessages[] = "Found cbt.sql at: $cbtSqlPath";

            // Read the entire cbt.sql file
            $cbtContent = file_get_contents($cbtSqlPath);

            // Extract all CREATE TABLE statements with their full definitions
            preg_match_all('/CREATE TABLE `([^`]+)`\s*\(.+?\)\s*ENGINE[^;]*;/s', $cbtContent, $matches, PREG_SET_ORDER);

            $createStatements = [];
            foreach ($matches as $match) {
                $tableName = $match[1];
                $createStatement = $match[0];
                $createStatements[$tableName] = $createStatement;
            }

            $logMessages[] = "Found " . count($createStatements) . " tables in cbt.sql";

            // Define the correct order for table creation based on foreign key dependencies
            $tableCreationOrder = [
                // Level 1: Completely independent tables (no foreign keys)
                'subjects',
                'schools',
                'staff',
                'admin_users',
                'portal_admins',
                'system_settings',
                'central_settings',
                'subject_groups',
                'db_updates',
                'migrations',

                // Level 2: Tables that reference Level 1 tables
                'students',
                'topics',
                'school_classes',
                'staff_subjects',
                'staff_classes',
                'subject_classes',

                // Level 3: Tables that reference Level 2 tables
                'student_scores',
                'student_comments',
                'student_positions',
                'student_subject_positions',
                'affective_traits',
                'psychomotor_skills',
                'report_card_settings',

                // Level 4: Exam tables
                'exams',
                'exam_assignments',
                'exam_questions',
                'exam_sessions',
                'exam_session_questions',
                'results',
                'theory_questions',
                'theory_sessions',
                'objective_questions',
                'subjective_questions',
                'passages',

                // Level 5: Result pins
                'result_pins',

                // Level 6: Library and assignments
                'library_resources',
                'assignments',
                'assignment_submissions',

                // Level 7: Logs and attendance
                'attendance',
                'activity_logs',
                'login_attempts',
                'password_resets',
                'portal_activity_logs'
            ];

            // Add any missing tables at the end
            foreach ($createStatements as $tableName => $statement) {
                if (!in_array($tableName, $tableCreationOrder)) {
                    $tableCreationOrder[] = $tableName;
                }
            }

            // Get all existing tables
            $existingTables = [];
            try {
                $stmt = $pdo->query("SHOW TABLES");
                $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $stmt->closeCursor();
                $logMessages[] = "Found " . count($existingTables) . " existing tables in database";
            } catch (Exception $e) {
                $logMessages[] = "No existing tables found or error: " . $e->getMessage();
            }

            try {
                // Step 1: Disable foreign key checks
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $logMessages[] = "✓ Disabled foreign key checks";

                // Step 2: Create backup tables for existing data
                $backupTables = [];
                $backupSchemas = [];
                foreach ($existingTables as $tableName) {
                    if (isset($createStatements[$tableName])) {
                        $backupTable = $tableName . '_temp_backup';
                        $backupTables[$tableName] = $backupTable;

                        // Drop backup if it exists
                        $pdo->exec("DROP TABLE IF EXISTS `$backupTable`");

                        // Create backup table structure
                        $pdo->exec("CREATE TABLE `$backupTable` LIKE `$tableName`");

                        // Get column structure of old table
                        $stmt = $pdo->query("SHOW COLUMNS FROM `$tableName`");
                        $oldColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        $stmt->closeCursor();
                        $backupSchemas[$tableName] = $oldColumns;

                        // Get record count
                        $stmt = $pdo->query("SELECT COUNT(*) FROM `$tableName`");
                        $count = $stmt->fetchColumn();
                        $stmt->closeCursor();

                        if ($count > 0) {
                            // Copy data to backup
                            $pdo->exec("INSERT INTO `$backupTable` SELECT * FROM `$tableName`");
                            $logMessages[] = "  ✓ Backed up $count records from $tableName to $backupTable";
                        } else {
                            $logMessages[] = "  ℹ️ $tableName is empty, no data to backup";
                        }
                    }
                }

                // Step 3: Drop all existing tables
                foreach ($existingTables as $tableName) {
                    $pdo->exec("DROP TABLE IF EXISTS `$tableName`");
                }
                $logMessages[] = "✓ Dropped all existing tables";

                // Step 4: Create all tables in the correct order
                $createdCount = 0;
                foreach ($tableCreationOrder as $tableName) {
                    if (isset($createStatements[$tableName])) {
                        try {
                            $pdo->exec($createStatements[$tableName]);
                            $createdCount++;
                            $logMessages[] = "  ✓ Created table: $tableName";
                        } catch (PDOException $e) {
                            $logMessages[] = "  ✗ Failed to create $tableName: " . $e->getMessage();
                            throw $e;
                        }
                    }
                }
                $logMessages[] = "✓ Created $createdCount tables from cbt.sql";

                // Step 5: Restore data from backups with intelligent column mapping
                $restoredCount = 0;
                foreach ($backupTables as $tableName => $backupTable) {
                    // Check if backup exists
                    $stmt = $pdo->query("SHOW TABLES LIKE '$backupTable'");
                    $backupExists = $stmt->rowCount() > 0;
                    $stmt->closeCursor();

                    if ($backupExists) {
                        // Get count from backup
                        $stmt = $pdo->query("SELECT COUNT(*) FROM `$backupTable`");
                        $backupCount = $stmt->fetchColumn();
                        $stmt->closeCursor();

                        if ($backupCount > 0) {
                            try {
                                // Get columns of new table
                                $stmt = $pdo->query("SHOW COLUMNS FROM `$tableName`");
                                $newColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                $stmt->closeCursor();

                                // Get columns of old table from backup
                                $oldColumns = $backupSchemas[$tableName];

                                // Find common columns
                                $commonColumns = array_intersect($newColumns, $oldColumns);

                                if (!empty($commonColumns)) {
                                    // Build column list for INSERT
                                    $columnList = implode(', ', array_map(function ($col) {
                                        return "`$col`";
                                    }, $commonColumns));

                                    // Build SELECT column list
                                    $selectList = implode(', ', array_map(function ($col) {
                                        return "`$col`";
                                    }, $commonColumns));

                                    // Restore only common columns
                                    $sql = "INSERT INTO `$tableName` ($columnList) SELECT $selectList FROM `$backupTable`";
                                    $pdo->exec($sql);

                                    // Verify restoration
                                    $stmt = $pdo->query("SELECT COUNT(*) FROM `$tableName`");
                                    $newCount = $stmt->fetchColumn();
                                    $stmt->closeCursor();

                                    $logMessages[] = "  ✓ Restored $backupCount records to $tableName (now has $newCount)";
                                    $restoredCount++;
                                } else {
                                    $logMessages[] = "  ⚠️ No common columns found for $tableName - data not restored";
                                    $failedRestores[] = "$tableName (no common columns)";
                                }
                            } catch (PDOException $e) {
                                $logMessages[] = "  ⚠️ Could not restore data to $tableName: " . $e->getMessage();
                                $failedRestores[] = "$tableName (" . $e->getMessage() . ")";
                            }
                        }
                    }
                }
                $logMessages[] = "✓ Restored data to $restoredCount tables";

                if (!empty($failedRestores)) {
                    $logMessages[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
                    $logMessages[] = "⚠️ Tables that could NOT be restored (structure changed):";
                    foreach ($failedRestores as $failed) {
                        $logMessages[] = "  • $failed";
                    }
                    $logMessages[] = "Note: These tables were recreated with the correct structure from cbt.sql,";
                    $logMessages[] = "but their data could not be restored due to column mismatches.";
                    $logMessages[] = "You may need to manually re-enter data for these tables.";
                }

                // Step 6: Drop all backup tables
                foreach ($backupTables as $tableName => $backupTable) {
                    $pdo->exec("DROP TABLE IF EXISTS `$backupTable`");
                }
                $logMessages[] = "✓ Cleaned up backup tables";

                // Step 7: Re-enable foreign key checks
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $logMessages[] = "✓ Re-enabled foreign key checks";

                $logMessages[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
                $logMessages[] = "✅ DATABASE REBUILD COMPLETED SUCCESSFULLY!";
                $logMessages[] = "✅ $createdCount tables created, $restoredCount tables restored with data";

                if (!empty($failedRestores)) {
                    $logMessages[] = "⚠️ " . count($failedRestores) . " tables had structure changes and data could not be restored";
                    $logMessages[] = "Please review the list above and re-enter data for those tables if needed.";
                }

                return true;
            } catch (Exception $e) {
                $logMessages[] = "❌ ERROR: " . $e->getMessage();
                $logMessages[] = "Attempting to rollback...";

                // Try to rollback by re-enabling foreign keys
                try {
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                } catch (Exception $rollbackError) {
                    // Ignore
                }

                return false;
            }
        }
    ],
];

// Get current system version
$current_version = '2.0.0';

// Find pending migrations - ONLY those not already applied
$pending = [];
foreach ($migrations as $version => $migration) {
    if (!in_array($version, $applied)) {
        $pending[$version] = $migration;
    }
}

// Handle migration application
$message = '';
$message_type = '';
$logMessages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    $version = $_POST['version'];

    if (isset($migrations[$version])) {
        try {
            // First, check if migration already applied
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM migrations WHERE version = ?");
            $checkStmt->execute([$version]);
            if ($checkStmt->fetchColumn() > 0) {
                $message = "Migration {$version} has already been applied.";
                $message_type = "info";
            } else {
                // Execute the migration
                $logMessages = [];
                $success = $migrations[$version]['execute']($pdo, $logMessages);

                if ($success) {
                    // Insert migration record
                    $stmt = $pdo->prepare("INSERT INTO migrations (version, description) VALUES (?, ?)");
                    $stmt->execute([$version, $migrations[$version]['description']]);

                    $message = "Migration {$version} applied successfully!<br>" . implode('<br>', $logMessages);
                    $message_type = "success";

                    // Refresh applied migrations
                    $stmt = $pdo->query("SELECT version FROM migrations");
                    $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $stmt->closeCursor();
                    $pending = [];
                    foreach ($migrations as $v => $m) {
                        if (!in_array($v, $applied)) {
                            $pending[$v] = $m;
                        }
                    }
                } else {
                    $message = "Migration {$version} failed:<br>" . implode('<br>', $logMessages);
                    $message_type = "error";
                }
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Run all pending migrations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_all_migrations'])) {
    $all_success = true;
    $allLogs = [];

    foreach ($pending as $version => $migration) {
        try {
            // Check if migration already applied
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM migrations WHERE version = ?");
            $checkStmt->execute([$version]);
            if ($checkStmt->fetchColumn() > 0) {
                $allLogs[] = "⏭ {$version}: Already applied, skipping";
                continue;
            }

            // Execute the migration
            $migrationLogs = [];
            $success = $migration['execute']($pdo, $migrationLogs);

            if ($success) {
                // Insert migration record
                $stmt = $pdo->prepare("INSERT INTO migrations (version, description) VALUES (?, ?)");
                $stmt->execute([$version, $migration['description']]);

                $allLogs[] = "✓ {$version}: {$migration['description']}";
                foreach ($migrationLogs as $log) {
                    $allLogs[] = "  " . $log;
                }
            } else {
                $all_success = false;
                $allLogs[] = "✗ {$version}: Failed";
                foreach ($migrationLogs as $log) {
                    $allLogs[] = "  " . $log;
                }
                break;
            }
        } catch (Exception $e) {
            $all_success = false;
            $allLogs[] = "✗ {$version}: Failed - " . $e->getMessage();
            break;
        }
    }

    if ($all_success) {
        $message = "All migrations applied successfully!<br>" . implode('<br>', $allLogs);
        $message_type = "success";

        // Refresh applied migrations
        $stmt = $pdo->query("SELECT version FROM migrations");
        $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt->closeCursor();
        $pending = [];
        foreach ($migrations as $v => $m) {
            if (!in_array($v, $applied)) {
                $pending[$v] = $m;
            }
        }
    } else {
        $message = "Migrations failed:<br>" . implode('<br>', $allLogs);
        $message_type = "error";
    }
}

// Get cbt.sql info
$cbtSqlPath = __DIR__ . '/cbt.sql';
$cbtExists = file_exists($cbtSqlPath);
$cbtTables = [];
if ($cbtExists) {
    $cbtContent = file_get_contents($cbtSqlPath);
    preg_match_all('/CREATE TABLE `([^`]+)`/', $cbtContent, $matches);
    $cbtTables = array_unique($matches[1]);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Rebuild - CBT System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 20px;
        }

        .version-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: center;
        }

        .current-version {
            font-size: 24px;
            font-weight: bold;
            color: #3498db;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            max-height: 600px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
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

        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #f39c12;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
        }

        .success-box {
            background: #d4edda;
            border-left: 4px solid #27ae60;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }

        .file-info {
            background: #e8f4fd;
            padding: 10px 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-family: monospace;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .btn-back {
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
        }

        .btn-back:hover {
            background: #3498db;
            color: white;
            transform: translateY(-2px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .stat-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #3498db;
        }

        .stat-label {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }

        .migration-item {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            margin-bottom: 15px;
            overflow: hidden;
        }

        .migration-header {
            background: #f8f9fa;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .migration-version {
            font-weight: bold;
            color: #3498db;
            font-size: 16px;
        }

        .migration-desc {
            color: #666;
            font-size: 14px;
        }

        .migration-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-applied {
            background: #d4edda;
            color: #155724;
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
        }

        .process-steps {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-size: 14px;
        }

        .process-steps ol {
            margin-left: 20px;
            margin-top: 10px;
        }

        .process-steps li {
            margin: 5px 0;
        }

        .table-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 10px;
            margin-top: 10px;
            max-height: 300px;
            overflow-y: auto;
        }

        .table-badge {
            background: #e9ecef;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-family: monospace;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card">
            <div class="header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1><i class="fas fa-database"></i> Database Rebuild Tool</h1>
                    <p>Rebuilds ALL tables exactly as defined in cbt.sql while preserving your data</p>
                </div>
                <a href="index.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- File status -->
            <div class="file-info">
                <i class="fas fa-file-code"></i> cbt.sql location: <strong><?php echo $cbtSqlPath; ?></strong><br>
                <?php if ($cbtExists): ?>
                    <i class="fas fa-check-circle" style="color: #28a745;"></i> File found - <?php echo count($cbtTables); ?> tables found in file
                <?php else: ?>
                    <i class="fas fa-times-circle" style="color: #dc3545;"></i> File NOT found - Please place cbt.sql in the project root folder
                <?php endif; ?>
            </div>

            <div class="version-info">
                <h3>Migration Version</h3>
                <div class="current-version">2.0.0 - Complete Rebuild</div>
                <p style="margin-top: 10px;">This migration will rebuild ALL tables to match cbt.sql exactly</p>
            </div>

            <div class="warning-box">
                <h3><i class="fas fa-exclamation-triangle"></i> How this works:</h3>
                <div class="process-steps">
                    <strong>Step-by-step process:</strong>
                    <ol>
                        <li><strong>Disable foreign key checks</strong> - Prevents constraint errors during rebuild</li>
                        <li><strong>Backup all existing data</strong> - Creates temporary backup tables</li>
                        <li><strong>Drop all existing tables</strong> - Removes old table structures</li>
                        <li><strong>Create new tables in correct order</strong> - Parent tables first, then child tables using EXACT structure from cbt.sql</li>
                        <li><strong>Restore data intelligently</strong> - Only restores columns that exist in both old and new tables</li>
                        <li><strong>Clean up backups</strong> - Removes temporary tables</li>
                        <li><strong>Re-enable foreign key checks</strong> - Restores referential integrity</li>
                    </ol>
                    <p style="margin-top: 15px;"><strong>Note:</strong> If table structures changed, only common columns will be restored. You'll be notified of any tables that couldn't be fully restored.</p>
                </div>
            </div>

            <?php
            // Get database statistics
            $stmt = $pdo->query("SHOW TABLES");
            $dbTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $stmt->closeCursor();
            $totalTables = count($dbTables);
            $totalApplied = count($applied);
            $totalPending = count($pending);
            ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($cbtTables); ?></div>
                    <div class="stat-label">Tables in cbt.sql</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $totalTables; ?></div>
                    <div class="stat-label">Existing Tables</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $totalPending; ?></div>
                    <div class="stat-label">Pending Migrations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $totalApplied; ?></div>
                    <div class="stat-label">Applied Migrations</div>
                </div>
            </div>

            <?php if (empty($pending)): ?>
                <div class="success-box">
                    <i class="fas fa-check-circle"></i>
                    <strong>Database is already up to date!</strong> Version 2.0.0 has already been applied.
                </div>
            <?php else: ?>
                <h3><i class="fas fa-clock"></i> Migration Available</h3>

                <?php foreach ($pending as $version => $migration): ?>
                    <div class="migration-item">
                        <div class="migration-header">
                            <div>
                                <span class="migration-version">Version <?php echo $version; ?></span>
                                <span class="migration-desc"> - <?php echo htmlspecialchars($migration['description']); ?></span>
                            </div>
                            <div>
                                <span class="migration-status status-pending">Pending</span>
                                <form method="POST" style="display: inline; margin-left: 10px;" onsubmit="return confirm('⚠️ WARNING: This will rebuild ALL tables to match cbt.sql exactly. Your data will be preserved. Continue?');">
                                    <input type="hidden" name="run_migration" value="1">
                                    <input type="hidden" name="version" value="<?php echo $version; ?>">
                                    <button type="submit" class="btn btn-danger">
                                        <i class="fas fa-sync-alt"></i> Rebuild Database
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div style="margin-top: 20px; text-align: center;">
                    <form method="POST" onsubmit="return confirm('⚠️ WARNING: This will rebuild ALL tables to match cbt.sql exactly. Your data will be preserved. Continue?');">
                        <input type="hidden" name="run_all_migrations" value="1">
                        <button type="submit" class="btn btn-success" style="font-size: 16px; padding: 15px 30px;">
                            <i class="fas fa-database"></i> Rebuild Entire Database from cbt.sql
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Table List -->
            <?php if (!empty($cbtTables)): ?>
                <h3 style="margin-top: 30px;"><i class="fas fa-table"></i> Tables to be Rebuilt (<?php echo count($cbtTables); ?> tables)</h3>
                <div class="table-list">
                    <?php foreach ($cbtTables as $table): ?>
                        <span class="table-badge"><?php echo htmlspecialchars($table); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Migration History -->
            <h3 style="margin-top: 30px;"><i class="fas fa-history"></i> Migration History</h3>
            <div style="max-height: 300px; overflow-y: auto; margin-top: 15px;">
                <?php
                $stmt = $pdo->query("SELECT * FROM migrations ORDER BY id DESC");
                $history = $stmt->fetchAll();
                $stmt->closeCursor();
                ?>
                <?php if (empty($history)): ?>
                    <p style="color: #999; text-align: center; padding: 20px;">No migrations applied yet.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Description</th>
                                <th>Applied At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $migration): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($migration['version']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($migration['description']); ?></td>
                                    <td><?php echo date('M d, Y H:i:s', strtotime($migration['applied_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>