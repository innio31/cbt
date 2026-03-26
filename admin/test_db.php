<?php
session_start();
require_once '../includes/config.php';

echo "<h1>Database Structure Test</h1>";

try {
    // Get list of all tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<h2>Tables in database:</h2>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";

    // Look for any table that might contain subjects
    $subject_tables = [];
    foreach ($tables as $table) {
        if (stripos($table, 'subject') !== false) {
            $subject_tables[] = $table;
        }
    }

    if (!empty($subject_tables)) {
        echo "<h2>Possible subject tables found:</h2>";
        foreach ($subject_tables as $table) {
            echo "<h3>Table: $table</h3>";

            // Show table structure
            $columns = $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
            echo "<pre>Columns:\n";
            print_r($columns);
            echo "</pre>";

            // Show first 5 rows
            $data = $pdo->query("SELECT * FROM $table LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            echo "<pre>Data:\n";
            print_r($data);
            echo "</pre>";
        }
    } else {
        echo "<p>No tables with 'subject' in name found.</p>";
    }
} catch (Exception $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
