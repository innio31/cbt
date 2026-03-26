<?php
// config.php - Mighty School for Valours CBT System Configuration
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'cbt');
define('SCHOOL_NAME', 'Mighty School for Valours, Ota');
define('SYSTEM_NAME', 'Offline CBT System');

// Add these school information constants
define('SCHOOL_MOTTO', 'Raising Champions');
define('SCHOOL_ADDRESS', 'Ijaba Road, Adebisi, Iyesi Ota, Ogun state');
define('SCHOOL_PHONE', '+234 803 123 4567'); // Update with actual phone number
define('SCHOOL_EMAIL', 'mforvalours@gmail.com');

// System paths
$base_url = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/msv/';
define('BASE_URL', $base_url);
define('UPLOAD_PATH', $_SERVER['DOCUMENT_ROOT'] . '/msv/uploads/');

// Design colors - Climax Brains for Valours Theme
define('COLOR_PRIMARY', '#2c3e50');
define('COLOR_SECONDARY', '#3498db');
define('COLOR_ACCENT', '#e74c3c');
define('COLOR_SUCCESS', '#27ae60');
define('COLOR_WARNING', '#f39c12');
define('COLOR_DANGER', '#e74c3c');
define('COLOR_LIGHT', '#ecf0f1');
define('COLOR_DARK', '#2c3e50');

// Database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Database Connection Error: " . $e->getMessage();
    error_log($error_message);

    // Simple error display without using constants that might not be defined
    die("<div style='background: #2c3e50; color: white; padding: 20px; border-radius: 10px; text-align: center;'>
            <h1>🚨 Database Connection Error</h1>
            <p><strong>Mighty School for Valours CBT System</strong></p>
            <p>Unable to connect to database. Please contact administrator.</p>
            <p><small>Error: " . htmlspecialchars($e->getMessage()) . "</small></p>
        </div>");
}

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Africa/Lagos');

// Add these functions to includes/config.php

function getSubjectName($pdo, $subject_id)
{
    $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
    $stmt->execute([$subject_id]);
    return $stmt->fetchColumn();
}

function getAssignedSubjects($pdo, $staff_id)
{
    // Check if staff_subjects table exists, otherwise use session
    try {
        // Try to get from staff_subjects table first
        $stmt = $pdo->prepare("
            SELECT s.id, s.subject_name 
            FROM staff_subjects ss 
            JOIN subjects s ON ss.subject_id = s.id 
            WHERE ss.staff_id = ?
        ");
        $stmt->execute([$staff_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($subjects)) {
            return $subjects;
        }
    } catch (PDOException $e) {
        // Table doesn't exist or error, fall back to session
    }

    // Fallback: use session assigned_subject_ids
    if (isset($_SESSION['assigned_subject_ids']) && !empty($_SESSION['assigned_subject_ids'])) {
        $subject_ids = $_SESSION['assigned_subject_ids'];
        $placeholders = str_repeat('?,', count($subject_ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE id IN ($placeholders)");
        $stmt->execute($subject_ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return [];
}

// Add these to your existing config.php

// Central Question Bank Configuration
define('CENTRAL_URL', 'https://your-central-domain.com/api'); // Change to your actual central URL
define('CENTRAL_API_KEY', ''); // Will be set in database/settings
define('SCHOOL_CODE', ''); // Your school's unique code

// Sync Settings
define('AUTO_SYNC_ENABLED', true); // Enable automatic sync via cron
define('SYNC_INTERVAL', 86400); // 24 hours in seconds
define('MAX_QUESTIONS_PER_SYNC', 200); // Max questions to pull per sync

// Create central_settings table if not exists
function ensureCentralSettings()
{
    global $pdo;

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

    // Insert default if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM central_settings");
    if ($stmt->fetchColumn() == 0) {
        $pdo->prepare("
            INSERT INTO central_settings (central_url, api_key, school_code)
            VALUES (?, ?, ?)
        ")->execute([CENTRAL_URL, CENTRAL_API_KEY, SCHOOL_CODE]);
    }
}

// Call this when including config
ensureCentralSettings();

// Define system version
define('SYSTEM_VERSION', '1.0.0');

// GitHub repository for updates
define('GITHUB_REPO', 'innio/cbt'); // Change this