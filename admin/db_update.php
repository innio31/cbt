<?php
// admin/db_migrate.php - Database Migration System
session_start();

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

require_once '../includes/config.php';

// Create migration tracking table if it doesn't exist
$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(50) NOT NULL,
    description TEXT,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_version (version)
)");

// Function to check if a column exists
function columnExists($pdo, $table, $column)
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return $stmt->rowCount() > 0;
}

// Function to check if a table exists
function tableExists($pdo, $table)
{
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return $stmt->rowCount() > 0;
}

// Function to check if an index exists
function indexExists($pdo, $table, $index)
{
    $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
    $stmt->execute([$index]);
    return $stmt->rowCount() > 0;
}

// Get applied migrations
$stmt = $pdo->query("SELECT version FROM migrations");
$applied = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Define migrations (each version should contain SQL that adds new structure)
$migrations = [
    '1.0.0' => [
        'description' => 'Initial database structure',
        'sql' => "
            -- This is the base structure from your SQL file
            -- Tables will be created if they don't exist
        "
    ],
    '1.1.0' => [
        'description' => 'Add parent contact fields and attendance table',
        'sql' => "
            -- Add parent contact columns to students table
            ALTER TABLE students 
            ADD COLUMN IF NOT EXISTS parent_phone VARCHAR(20) AFTER full_name,
            ADD COLUMN IF NOT EXISTS parent_email VARCHAR(100) AFTER parent_phone;
            
            -- Create attendance table if not exists
            CREATE TABLE IF NOT EXISTS attendance (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                date DATE NOT NULL,
                status ENUM('present', 'absent', 'late') DEFAULT 'present',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY (student_id, date),
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            
            -- Add email column to staff table
            ALTER TABLE staff 
            ADD COLUMN IF NOT EXISTS email VARCHAR(255) AFTER profile_picture;
            
            -- Add class_id to students table
            ALTER TABLE students 
            ADD COLUMN IF NOT EXISTS class_id INT AFTER class,
            ADD KEY fk_students_class_id (class_id);
            
            -- Add archive fields to students table
            ALTER TABLE students 
            ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(255) AFTER status,
            ADD COLUMN IF NOT EXISTS archived_at DATETIME AFTER archive_reason;
        "
    ],
    '1.2.0' => [
        'description' => 'Add exam duration and instructions',
        'sql' => "
            -- Add exam duration columns
            ALTER TABLE exams 
            ADD COLUMN IF NOT EXISTS duration_minutes INT DEFAULT 60 AFTER exam_name,
            ADD COLUMN IF NOT EXISTS instructions TEXT AFTER duration_minutes;
            
            -- Add results metadata
            ALTER TABLE results 
            ADD COLUMN IF NOT EXISTS started_at DATETIME AFTER total_score,
            ADD COLUMN IF NOT EXISTS completed_at DATETIME AFTER started_at,
            ADD COLUMN IF NOT EXISTS correct_count INT DEFAULT 0 AFTER time_taken,
            ADD COLUMN IF NOT EXISTS total_questions INT DEFAULT 0 AFTER correct_count;
            
            -- Add exam type column
            ALTER TABLE exams 
            ADD COLUMN IF NOT EXISTS exam_type ENUM('objective','subjective','theory') DEFAULT 'objective' AFTER instructions;
        "
    ],
    '1.3.0' => [
        'description' => 'Add report card and student position tables',
        'sql' => "
            -- Create report_card_settings table if not exists
            CREATE TABLE IF NOT EXISTS report_card_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session VARCHAR(20) NOT NULL,
                term ENUM('First','Second','Third') NOT NULL,
                template VARCHAR(50) DEFAULT 'default',
                class VARCHAR(50) NOT NULL,
                max_score INT NOT NULL,
                score_types JSON NOT NULL,
                grading_system VARCHAR(20) DEFAULT 'simple',
                next_resumption_date DATE DEFAULT NULL,
                current_resumption_date DATE DEFAULT NULL,
                current_closing_date DATE DEFAULT NULL,
                days_school_opened INT DEFAULT 90,
                show_class_position TINYINT(1) DEFAULT 1,
                show_subject_position TINYINT(1) DEFAULT 1,
                show_promoted_to TINYINT(1) DEFAULT 1,
                show_lowest_highest_avg TINYINT(1) DEFAULT 1,
                show_lowest_highest_class TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_session_term_class (session, term, class)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            
            -- Create student_positions table
            CREATE TABLE IF NOT EXISTS student_positions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                session VARCHAR(20) NOT NULL,
                term ENUM('First','Second','Third') NOT NULL,
                class_position INT DEFAULT NULL,
                total_marks DECIMAL(8,2) DEFAULT 0.00,
                average DECIMAL(5,2) DEFAULT 0.00,
                promoted_to VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_student_session_term (student_id, session, term),
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            
            -- Create student_scores table
            CREATE TABLE IF NOT EXISTS student_scores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                subject_id INT NOT NULL,
                subject_name VARCHAR(255) DEFAULT NULL,
                session VARCHAR(20) NOT NULL,
                term ENUM('First','Second','Third') NOT NULL,
                score_data JSON NOT NULL,
                total_score DECIMAL(8,2) DEFAULT 0.00,
                percentage DECIMAL(5,2) DEFAULT 0.00,
                grade VARCHAR(5) DEFAULT NULL,
                subject_position INT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            
            -- Create student_comments table
            CREATE TABLE IF NOT EXISTS student_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                session VARCHAR(20) NOT NULL,
                term ENUM('First','Second','Third') NOT NULL,
                teachers_comment TEXT DEFAULT NULL,
                principals_comment TEXT DEFAULT NULL,
                class_teachers_name VARCHAR(255) DEFAULT NULL,
                principals_name VARCHAR(255) DEFAULT NULL,
                days_present INT DEFAULT 0,
                days_absent INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_student_session_term (student_id, session, term),
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        "
    ],
    '1.4.0' => [
        'description' => 'Add psychomotor and affective traits tables',
        'sql' => "
            -- Create psychomotor_skills table
            CREATE TABLE IF NOT EXISTS psychomotor_skills (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                session VARCHAR(20) NOT NULL,
                term ENUM('First','Second','Third') NOT NULL,
                handwriting ENUM('A','B','C','D','E') DEFAULT NULL,
                verbal_fluency ENUM('A','B','C','D','E') DEFAULT NULL,
                sports ENUM('A','B','C','D','E') DEFAULT NULL,
                handling_tools ENUM('A','B','C','D','E') DEFAULT NULL,
                drawing_painting ENUM('A','B','C','D','E') DEFAULT NULL,
                musical_skills ENUM('A','B','C','D','E') DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_student_session_term (student_id, session, term),
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            
            -- Create affective_traits table
            CREATE TABLE IF NOT EXISTS affective_traits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                session VARCHAR(20) NOT NULL,
                term VARCHAR(20) NOT NULL,
                punctuality ENUM('A','B','C','D','E') DEFAULT NULL,
                attendance ENUM('A','B','C','D','E') DEFAULT NULL,
                politeness ENUM('A','B','C','D','E') DEFAULT NULL,
                honesty ENUM('A','B','C','D','E') DEFAULT NULL,
                neatness ENUM('A','B','C','D','E') DEFAULT NULL,
                reliability ENUM('A','B','C','D','E') DEFAULT NULL,
                relationship ENUM('A','B','C','D','E') DEFAULT NULL,
                self_control ENUM('A','B','C','D','E') DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_student_session_term (student_id, session, term),
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        "
    ],
    '1.5.0' => [
        'description' => 'Add indexes for performance optimization',
        'sql' => "
            -- Add indexes to students table
            CREATE INDEX IF NOT EXISTS idx_student_class ON students(class);
            CREATE INDEX IF NOT EXISTS idx_student_status ON students(status);
            
            -- Add indexes to exams table
            CREATE INDEX IF NOT EXISTS idx_exam_class ON exams(class);
            CREATE INDEX IF NOT EXISTS idx_exam_active ON exams(is_active);
            
            -- Add indexes to results table
            CREATE INDEX IF NOT EXISTS idx_result_student ON results(student_id);
            CREATE INDEX IF NOT EXISTS idx_result_exam ON results(exam_id);
            
            -- Add indexes to exam_sessions table
            CREATE INDEX IF NOT EXISTS idx_session_student ON exam_sessions(student_id);
            CREATE INDEX IF NOT EXISTS idx_session_exam ON exam_sessions(exam_id);
        "
    ]
];

// Get current system version
$current_version = defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '1.0.0';

// Find pending migrations
$pending = [];
foreach ($migrations as $version => $migration) {
    if (!in_array($version, $applied) && version_compare($version, $current_version, '<=')) {
        $pending[$version] = $migration;
    }
}

// Handle migration application
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    $version = $_POST['version'];

    if (isset($migrations[$version])) {
        try {
            $pdo->beginTransaction();

            // Execute the SQL statements (split by semicolon)
            $sql_statements = array_filter(array_map('trim', explode(';', $migrations[$version]['sql'])));

            $errors = [];
            $executed = 0;

            foreach ($sql_statements as $statement) {
                if (!empty($statement)) {
                    try {
                        $pdo->exec($statement);
                        $executed++;
                    } catch (PDOException $e) {
                        // Ignore "already exists" errors for columns/tables
                        if (
                            strpos($e->getMessage(), 'Duplicate column') === false &&
                            strpos($e->getMessage(), 'already exists') === false
                        ) {
                            $errors[] = $e->getMessage();
                        } else {
                            $executed++;
                        }
                    }
                }
            }

            // Record the migration
            $stmt = $pdo->prepare("INSERT INTO migrations (version, description) VALUES (?, ?)");
            $stmt->execute([$version, $migrations[$version]['description']]);

            $pdo->commit();

            $message = "Migration {$version} applied successfully! ({$executed} statements executed)";
            $message_type = "success";

            // Refresh applied list
            $stmt = $pdo->query("SELECT version FROM migrations");
            $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $pending = [];
            foreach ($migrations as $v => $m) {
                if (!in_array($v, $applied) && version_compare($v, $current_version, '<=')) {
                    $pending[$v] = $m;
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Error applying migration: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Run all pending migrations at once
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_all_migrations'])) {
    $all_success = true;
    $results = [];

    foreach ($pending as $version => $migration) {
        try {
            $pdo->beginTransaction();

            $sql_statements = array_filter(array_map('trim', explode(';', $migration['sql'])));
            $executed = 0;

            foreach ($sql_statements as $statement) {
                if (!empty($statement)) {
                    try {
                        $pdo->exec($statement);
                        $executed++;
                    } catch (PDOException $e) {
                        if (
                            strpos($e->getMessage(), 'Duplicate column') === false &&
                            strpos($e->getMessage(), 'already exists') === false
                        ) {
                            throw $e;
                        }
                        $executed++;
                    }
                }
            }

            $stmt = $pdo->prepare("INSERT INTO migrations (version, description) VALUES (?, ?)");
            $stmt->execute([$version, $migration['description']]);

            $pdo->commit();
            $results[] = "✓ {$version}: {$migration['description']}";
        } catch (Exception $e) {
            $pdo->rollBack();
            $all_success = false;
            $results[] = "✗ {$version}: Failed - " . $e->getMessage();
            break;
        }
    }

    if ($all_success) {
        $message = "All migrations applied successfully!<br>" . implode('<br>', $results);
        $message_type = "success";

        // Refresh
        $stmt = $pdo->query("SELECT version FROM migrations");
        $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $pending = [];
        foreach ($migrations as $v => $m) {
            if (!in_array($v, $applied) && version_compare($v, $current_version, '<=')) {
                $pending[$v] = $m;
            }
        }
    } else {
        $message = "Migrations failed:<br>" . implode('<br>', $results);
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration - CBT System</title>
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
            max-width: 900px;
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
            cursor: pointer;
        }

        .migration-header:hover {
            background: #e9ecef;
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

        .migration-details {
            padding: 20px;
            border-top: 1px solid #e0e0e0;
            background: #fafafa;
            display: none;
        }

        .migration-details.show {
            display: block;
        }

        .sql-preview {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            overflow-x: auto;
            margin: 10px 0;
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

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-warning:hover {
            background: #e67e22;
        }

        .btn-sm {
            padding: 5px 15px;
            font-size: 12px;
        }

        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #f39c12;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }

        .success-box {
            background: #d4edda;
            border-left: 4px solid #27ae60;
            padding: 15px;
            margin: 20px 0;
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
        }

        @media (max-width: 768px) {
            .card {
                padding: 20px;
            }

            .migration-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1><i class="fas fa-database"></i> Database Migration Manager</h1>
                <p>Update your database structure to match the latest system version</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="version-info">
                <h3>Current System Version</h3>
                <div class="current-version"><?php echo $current_version; ?></div>
                <p style="margin-top: 10px;">Target Structure: Version <?php echo $current_version; ?></p>
            </div>

            <div class="warning-box">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Important:</strong> Please backup your database before running migrations!
                <button class="btn btn-warning btn-sm" onclick="backupDatabase()" style="margin-left: 10px;">
                    <i class="fas fa-database"></i> Backup Database
                </button>
            </div>

            <!-- Pending Migrations -->
            <h3><i class="fas fa-clock"></i> Pending Migrations</h3>

            <?php if (empty($pending)): ?>
                <div class="success-box">
                    <i class="fas fa-check-circle"></i>
                    <strong>Your database is up to date!</strong> All migrations have been applied.
                </div>
            <?php else: ?>
                <p style="margin: 15px 0; color: #666;">
                    The following migrations need to be applied to update your database structure:
                </p>

                <form method="POST" style="margin-bottom: 20px;">
                    <input type="hidden" name="run_all_migrations" value="1">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Apply ALL pending migrations? This will update your database structure.');">
                        <i class="fas fa-play"></i> Apply All Migrations
                    </button>
                </form>

                <?php foreach ($pending as $version => $migration): ?>
                    <div class="migration-item">
                        <div class="migration-header" onclick="toggleDetails('<?php echo $version; ?>')">
                            <div>
                                <span class="migration-version">Version <?php echo $version; ?></span>
                                <span class="migration-desc"> - <?php echo htmlspecialchars($migration['description']); ?></span>
                            </div>
                            <div>
                                <span class="migration-status status-pending">Pending</span>
                                <form method="POST" style="display: inline; margin-left: 10px;"
                                    onsubmit="return confirm('Apply migration <?php echo $version; ?>?');">
                                    <input type="hidden" name="run_migration" value="1">
                                    <input type="hidden" name="version" value="<?php echo $version; ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="fas fa-play"></i> Apply
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div id="details-<?php echo $version; ?>" class="migration-details">
                            <strong>SQL to execute:</strong>
                            <div class="sql-preview">
                                <pre><?php echo htmlspecialchars($migration['sql']); ?></pre>
                            </div>
                            <div class="alert-info" style="margin-top: 10px; padding: 10px;">
                                <i class="fas fa-info-circle"></i>
                                <strong>Note:</strong> This migration uses "IF NOT EXISTS" and will only add missing structure. Your existing data is safe.
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Applied Migrations History -->
            <h3 style="margin-top: 30px;"><i class="fas fa-history"></i> Migration History</h3>
            <div style="max-height: 300px; overflow-y: auto; margin-top: 15px;">
                <?php
                $stmt = $pdo->query("SELECT * FROM migrations ORDER BY id DESC");
                $history = $stmt->fetchAll();
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

            <!-- Database Information -->
            <h3 style="margin-top: 30px;"><i class="fas fa-info-circle"></i> Database Information</h3>
            <div style="margin-top: 15px;">
                <?php
                $stmt = $pdo->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                ?>
                <p><strong>Total Tables:</strong> <?php echo count($tables); ?></p>
                <details>
                    <summary style="cursor: pointer; color: #3498db;">View all tables</summary>
                    <div style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 5px;">
                        <?php foreach ($tables as $table): ?>
                            <span style="background: #e0e0e0; padding: 3px 10px; border-radius: 15px; font-size: 12px;"><?php echo $table; ?></span>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
        </div>
    </div>

    <script>
        function toggleDetails(version) {
            const details = document.getElementById('details-' + version);
            details.classList.toggle('show');
        }

        function backupDatabase() {
            if (confirm('Create a database backup? This may take a few seconds.')) {
                window.location.href = 'backup.php?action=backup';
            }
        }

        // Auto-expand details if there's an error message
        <?php if ($message_type === 'error'): ?>
            document.querySelectorAll('.migration-details').forEach(d => d.classList.add('show'));
        <?php endif; ?>
    </script>
</body>

</html>