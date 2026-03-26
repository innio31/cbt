<?php
// admin/get-staff-data.php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Include database connection
require_once '../includes/config.php';

// Get staff ID from request
$staff_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($staff_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid staff ID']);
    exit();
}

try {
    // Get staff data
    $stmt = $pdo->prepare("
        SELECT s.*, 
               GROUP_CONCAT(DISTINCT ss.subject_id) as assigned_subjects,
               GROUP_CONCAT(DISTINCT sc.class) as assigned_classes
        FROM staff s
        LEFT JOIN staff_subjects ss ON s.staff_id = ss.staff_id
        LEFT JOIN staff_classes sc ON s.staff_id = sc.staff_id
        WHERE s.id = ?
        GROUP BY s.id
    ");
    $stmt->execute([$staff_id]);
    $staff = $stmt->fetch();

    if (!$staff) {
        echo json_encode(['success' => false, 'message' => 'Staff not found']);
        exit();
    }

    // Get all subjects
    $stmt = $pdo->query("SELECT id, subject_name, class FROM subjects ORDER BY class, subject_name");
    $subjects = $stmt->fetchAll();

    // Get distinct classes
    $stmt = $pdo->query("SELECT DISTINCT class FROM subjects ORDER BY class");
    $classes = $stmt->fetchAll();

    // Convert comma-separated strings to arrays
    $staff['assigned_subjects'] = !empty($staff['assigned_subjects'])
        ? explode(',', $staff['assigned_subjects'])
        : [];
    $staff['assigned_classes'] = !empty($staff['assigned_classes'])
        ? explode(',', $staff['assigned_classes'])
        : [];

    // Return JSON response
    echo json_encode([
        'success' => true,
        'staff' => $staff,
        'subjects' => $subjects,
        'classes' => $classes
    ]);
} catch (Exception $e) {
    error_log("Get staff data error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
