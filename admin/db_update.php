<?php
// admin/db_rebuild.php - Simple and reliable table rebuild tool
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

// Allow super_admin only for this operation
if ($_SESSION['admin_role'] !== 'super_admin') {
    header("Location: index.php?message=Access denied&type=error");
    exit();
}

require_once '../includes/config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$message = '';
$message_type = '';
$results = [];

// Process rebuild request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rebuild_tables'])) {
    $tables_to_rebuild = isset($_POST['tables']) ? $_POST['tables'] : [];

    if (empty($tables_to_rebuild)) {
        $message = "No tables selected for rebuild";
        $message_type = "error";
    } else {
        // Path to cbt.sql
        $cbtSqlPath = __DIR__ . '/../cbt.sql';

        if (!file_exists($cbtSqlPath)) {
            $message = "cbt.sql file not found at: $cbtSqlPath";
            $message_type = "error";
        } else {
            // Read cbt.sql content
            $cbtSqlContent = file_get_contents($cbtSqlPath);

            // Extract CREATE TABLE statements
            preg_match_all('/CREATE TABLE `([^`]+)`\s*\(.+?\)\s*ENGINE[^;]*;/s', $cbtSqlContent, $matches, PREG_SET_ORDER);

            $createStatements = [];
            foreach ($matches as $match) {
                $createStatements[$match[1]] = $match[0];
            }

            $pdo->beginTransaction();
            $all_success = true;

            foreach ($tables_to_rebuild as $tableName) {
                $result = [
                    'table' => $tableName,
                    'success' => false,
                    'steps' => []
                ];

                try {
                    // Check if table exists in cbt.sql
                    if (!isset($createStatements[$tableName])) {
                        throw new Exception("Table $tableName not found in cbt.sql");
                    }
                    $result['steps'][] = "✓ Found table structure in cbt.sql";

                    // Check if table exists in database
                    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
                    $stmt->execute([$tableName]);
                    $tableExists = $stmt->rowCount() > 0;

                    $backupTable = $tableName . '_backup_' . time();

                    if ($tableExists) {
                        // Backup existing data
                        $result['steps'][] = "📦 Backing up existing data to $backupTable...";
                        $pdo->exec("CREATE TABLE `$backupTable` LIKE `$tableName`");
                        $pdo->exec("INSERT INTO `$backupTable` SELECT * FROM `$tableName`");

                        $stmt = $pdo->query("SELECT COUNT(*) FROM `$backupTable`");
                        $recordCount = $stmt->fetchColumn();
                        $result['steps'][] = "✓ Backed up $recordCount records";

                        // Drop original table
                        $result['steps'][] = "🗑️ Dropping existing table...";
                        $pdo->exec("DROP TABLE IF EXISTS `$tableName`");
                        $result['steps'][] = "✓ Table dropped";
                    } else {
                        $result['steps'][] = "ℹ️ Table does not exist, creating new";
                    }

                    // Create new table from cbt.sql
                    $result['steps'][] = "🏗️ Creating new table from cbt.sql...";
                    $pdo->exec($createStatements[$tableName]);
                    $result['steps'][] = "✓ Table created successfully";

                    // Restore data if we had a backup
                    if ($tableExists) {
                        $result['steps'][] = "🔄 Restoring data from backup...";

                        // Get common columns between old and new table
                        $stmt = $pdo->query("SHOW COLUMNS FROM `$tableName`");
                        $newColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);

                        $stmt = $pdo->query("SHOW COLUMNS FROM `$backupTable`");
                        $oldColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);

                        $commonColumns = array_intersect($newColumns, $oldColumns);

                        if (!empty($commonColumns)) {
                            $columnList = implode(', ', array_map(function ($col) {
                                return "`$col`";
                            }, $commonColumns));

                            $pdo->exec("INSERT INTO `$tableName` ($columnList) SELECT $columnList FROM `$backupTable`");

                            $stmt = $pdo->query("SELECT COUNT(*) FROM `$tableName`");
                            $restoredCount = $stmt->fetchColumn();
                            $result['steps'][] = "✓ Restored $restoredCount records";
                        } else {
                            $result['steps'][] = "⚠️ No common columns found - data could not be restored";
                        }

                        // Drop backup table
                        $result['steps'][] = "🧹 Cleaning up backup table...";
                        $pdo->exec("DROP TABLE IF EXISTS `$backupTable`");
                        $result['steps'][] = "✓ Backup table removed";
                    }

                    $result['success'] = true;
                    $result['steps'][] = "✅ Table $tableName rebuilt successfully!";
                } catch (Exception $e) {
                    $all_success = false;
                    $result['steps'][] = "❌ Error: " . $e->getMessage();
                    $results[] = $result;
                    break;
                }

                $results[] = $result;
            }

            if ($all_success) {
                $pdo->commit();
                $message = "All selected tables rebuilt successfully!";
                $message_type = "success";
            } else {
                $pdo->rollBack();
                $message = "Error occurred. All changes rolled back.";
                $message_type = "error";
            }
        }
    }
}

// Get all tables from cbt.sql
function getTablesFromCbt($cbtSqlPath)
{
    $sqlContent = file_get_contents($cbtSqlPath);
    preg_match_all('/CREATE TABLE `([^`]+)`/', $sqlContent, $matches);
    return array_unique($matches[1]);
}

$cbtSqlPath = __DIR__ . '/../cbt.sql';
$cbtTables = file_exists($cbtSqlPath) ? getTablesFromCbt($cbtSqlPath) : [];

// Get existing tables in database
$stmt = $pdo->query("SHOW TABLES");
$dbTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Compare to find missing/outdated tables
$missingTables = array_diff($cbtTables, $dbTables);
$existingTables = array_intersect($cbtTables, $dbTables);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Table Rebuild - CBT System</title>
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

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
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

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
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
            transform: translateY(-2px);
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-success:hover {
            background: #219653;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
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

        .table-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 10px;
            margin: 20px 0;
            max-height: 500px;
            overflow-y: auto;
        }

        .table-item {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .table-name {
            font-family: monospace;
            font-size: 13px;
            flex: 1;
        }

        .table-status {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 12px;
        }

        .status-missing {
            background: #f8d7da;
            color: #721c24;
        }

        .status-existing {
            background: #d4edda;
            color: #155724;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .stat-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #3498db;
        }

        .stat-label {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
        }

        .result-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin: 10px 0;
        }

        .result-header {
            padding: 12px 15px;
            background: #f8f9fa;
            font-weight: bold;
            cursor: pointer;
        }

        .result-success {
            border-left: 4px solid #27ae60;
        }

        .result-error {
            border-left: 4px solid #e74c3c;
        }

        .result-details {
            padding: 15px;
            display: none;
            background: #fafafa;
            border-top: 1px solid #e0e0e0;
        }

        .result-details.show {
            display: block;
        }

        .step-list {
            list-style: none;
            padding-left: 0;
        }

        .step-list li {
            padding: 4px 0;
            font-family: monospace;
            font-size: 12px;
        }

        .select-all {
            margin: 15px 0;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card">
            <div class="header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1><i class="fas fa-database"></i> Database Table Rebuild</h1>
                    <p>Rebuild tables exactly as defined in cbt.sql while preserving data</p>
                </div>
                <a href="index.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="warning-box">
                <h3><i class="fas fa-exclamation-triangle"></i> Important Information</h3>
                <ul style="margin-left: 20px;">
                    <li>This tool will rebuild selected tables to match cbt.sql EXACTLY</li>
                    <li>Existing data will be backed up and restored after rebuilding</li>
                    <li>All indexes, foreign keys, and constraints will be recreated</li>
                    <li>If any error occurs, all changes are automatically rolled back</li>
                    <li><strong>Only super_admin can perform this operation</strong></li>
                </ul>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($cbtTables); ?></div>
                    <div class="stat-label">Tables in cbt.sql</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($dbTables); ?></div>
                    <div class="stat-label">Existing Tables</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($missingTables); ?></div>
                    <div class="stat-label">Missing Tables</div>
                </div>
            </div>

            <form method="POST">
                <div class="select-all">
                    <label style="cursor: pointer;">
                        <input type="checkbox" id="select-all" onchange="toggleAll(this.checked)">
                        <strong>Select All Tables</strong>
                    </label>
                </div>

                <div class="table-list">
                    <?php foreach ($cbtTables as $table):
                        $exists = in_array($table, $dbTables);
                    ?>
                        <div class="table-item">
                            <input type="checkbox" name="tables[]" value="<?php echo htmlspecialchars($table); ?>"
                                class="table-checkbox" <?php echo $exists ? 'checked' : 'checked'; ?>>
                            <span class="table-name">
                                <?php echo htmlspecialchars($table); ?>
                            </span>
                            <span class="table-status <?php echo $exists ? 'status-existing' : 'status-missing'; ?>">
                                <?php echo $exists ? 'Exists' : 'Missing'; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 20px;">
                    <button type="submit" name="rebuild_tables" value="1" class="btn btn-danger"
                        onclick="return confirm('⚠️ WARNING: This will rebuild ALL selected tables. Data will be preserved, but a backup is recommended. Continue?');">
                        <i class="fas fa-sync-alt"></i> Rebuild Selected Tables
                    </button>
                </div>
            </form>

            <?php if (!empty($results)): ?>
                <h3 style="margin-top: 30px;"><i class="fas fa-chart-line"></i> Rebuild Results</h3>
                <?php foreach ($results as $result): ?>
                    <div class="result-card <?php echo $result['success'] ? 'result-success' : 'result-error'; ?>">
                        <div class="result-header" onclick="toggleDetails('<?php echo $result['table']; ?>')">
                            <i class="fas <?php echo $result['success'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                            <strong><?php echo htmlspecialchars($result['table']); ?></strong>
                            <?php echo $result['success'] ? '✓ Rebuilt successfully' : '✗ Failed'; ?>
                        </div>
                        <div id="details-<?php echo $result['table']; ?>" class="result-details">
                            <ul class="step-list">
                                <?php foreach ($result['steps'] as $step): ?>
                                    <li><?php echo htmlspecialchars($step); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleDetails(table) {
            const details = document.getElementById('details-' + table);
            if (details) {
                details.classList.toggle('show');
            }
        }

        function toggleAll(checked) {
            const checkboxes = document.querySelectorAll('.table-checkbox');
            checkboxes.forEach(cb => cb.checked = checked);
        }
    </script>
</body>

</html>