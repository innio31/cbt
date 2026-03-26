<?php
// download-resource.php - Handle resource downloads
session_start();
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isStudentLoggedIn()) {
    die("Access denied");
}

$resource_id = $_GET['id'] ?? 0;

if (!$resource_id) {
    die("Invalid resource ID");
}

try {
    $stmt = $pdo->prepare("SELECT * FROM library_resources WHERE id = ?");
    $stmt->execute([$resource_id]);
    $resource = $stmt->fetch();

    if (!$resource) {
        die("Resource not found");
    }

    // Check if student has access to this resource
    $student_class = $_SESSION['class'];
    if ($resource['class'] !== $student_class && $resource['class'] !== 'All' && !empty($resource['class'])) {
        die("You don't have access to this resource");
    }

    $file_path = '../assets/uploads/' . $resource['file_path'];

    if (!file_exists($file_path)) {
        die("File not found on server");
    }

    // Track download
    try {
        $stmt = $pdo->prepare("
            CREATE TABLE IF NOT EXISTS resource_downloads (
                id INT PRIMARY KEY AUTO_INCREMENT,
                resource_id INT NOT NULL,
                student_id INT NOT NULL,
                downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute();

        $stmt = $pdo->prepare("
            INSERT INTO resource_downloads (resource_id, student_id) 
            VALUES (?, ?)
        ");
        $stmt->execute([$resource_id, $_SESSION['student_id']]);
    } catch (Exception $e) {
        error_log("Download tracking error: " . $e->getMessage());
    }

    // Force download
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($resource['title'] . '.' . pathinfo($resource['file_path'], PATHINFO_EXTENSION)) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_path));
    ob_clean();
    flush();
    readfile($file_path);
    exit;
} catch (Exception $e) {
    error_log("Download error: " . $e->getMessage());
    die("Error downloading file");
}
