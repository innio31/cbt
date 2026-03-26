<?php
// admin/backup.php - Simple Database Backup
session_start();

if (!isset($_SESSION['admin_id'])) {
    die("Access denied");
}

require_once '../includes/config.php';

$action = $_GET['action'] ?? '';

if ($action === 'backup') {
    $backup_dir = '../backups/';
    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }

    $filename = $backup_dir . 'backup_' . date('Y-m-d_H-i-s') . '.sql';

    // Simple backup using mysqldump if available, fallback to PHP method
    $db_host = DB_HOST;
    $db_name = DB_NAME;
    $db_user = DB_USER;
    $db_pass = DB_PASS;

    // Try mysqldump first (better for large databases)
    $command = "mysqldump --host={$db_host} --user={$db_user} --password={$db_pass} {$db_name} > {$filename} 2>&1";
    exec($command, $output, $return_var);

    if ($return_var !== 0) {
        // Fallback to PHP method
        $output = "-- CBT System Database Backup\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            // Get create table
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch();
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            $output .= $row[1] . ";\n\n";

            // Get data
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $values = array_map(function ($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    return "'" . addslashes($v) . "'";
                }, array_values($row));

                $output .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
            }
            $output .= "\n";
        }

        file_put_contents($filename, $output);
    }

    // Provide download
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($filename));
    readfile($filename);
    exit();
}
