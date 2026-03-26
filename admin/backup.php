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

    // Get all tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $output = "-- CBT System Database Backup\n";
    $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

    foreach ($tables as $table) {
        // Get create table statement
        $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $stmt->fetch();
        $output .= "DROP TABLE IF EXISTS `$table`;\n";
        $output .= $row[1] . ";\n\n";

        // Get table data
        $stmt = $pdo->query("SELECT * FROM `$table`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            foreach ($rows as $row) {
                $values = array_map(function ($value) use ($pdo) {
                    if ($value === null) return 'NULL';
                    return "'" . addslashes($value) . "'";
                }, array_values($row));

                $output .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
            }
            $output .= "\n";
        }
    }

    file_put_contents($filename, $output);

    // Provide download
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($filename));
    readfile($filename);
    exit();
}
