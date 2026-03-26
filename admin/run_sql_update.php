<?php
// admin/run_sql_update.php - Run SQL Updates
session_start();

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'super_admin') {
    die("Access denied");
}

require_once '../includes/config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'run_update') {
    $sql_file = $_FILES['sql_file'] ?? null;

    if ($sql_file && $sql_file['error'] === UPLOAD_ERR_OK) {
        $sql_content = file_get_contents($sql_file['tmp_name']);

        // Split SQL into individual statements
        $statements = explode(';', $sql_content);
        $errors = [];
        $success = 0;

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $pdo->exec($statement);
                    $success++;
                } catch (PDOException $e) {
                    $errors[] = "Error: " . $e->getMessage() . " in statement: " . substr($statement, 0, 100);
                }
            }
        }

        if (empty($errors)) {
            $message = "SQL update completed successfully! $success statements executed.";
            $type = "success";
        } else {
            $message = "SQL update completed with errors. $success statements executed.<br>" . implode('<br>', $errors);
            $type = "error";
        }

        // Log the update
        $log_entry = date('Y-m-d H:i:s') . " - SQL Update: $success statements executed\n";
        file_put_contents('../sql_update_log.txt', $log_entry, FILE_APPEND);
    } else {
        $message = "Please upload an SQL file.";
        $type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Run SQL Update</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
        }

        .btn {
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
    </style>
</head>

<body>
    <div class="card">
        <h2><i class="fas fa-database"></i> Run SQL Update</h2>

        <?php if (isset($message)): ?>
            <div class="alert alert-<?php echo $type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="run_update">
            <div style="margin-bottom: 20px;">
                <label>Select SQL File:</label>
                <input type="file" name="sql_file" accept=".sql" required style="display: block; margin-top: 10px;">
            </div>
            <button type="submit" class="btn" onclick="return confirm('Run SQL update? This will modify your database.')">
                <i class="fas fa-play"></i> Run SQL Update
            </button>
        </form>

        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
            <h3>Important Notes:</h3>
            <ul>
                <li>Always backup your database before running SQL updates</li>
                <li>Only run SQL files from trusted sources</li>
                <li>Make sure the SQL is compatible with your MySQL version</li>
                <li>Test on a staging environment first if possible</li>
            </ul>
        </div>
    </div>
</body>

</html>