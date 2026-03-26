<?php
// admin/manage-students.php - Manage Students with Transfer & Archive Features
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: ../login.php");
    exit();
}

// Check if admin has permission (super_admin or admin)
if ($_SESSION['admin_role'] !== 'super_admin' && $_SESSION['admin_role'] !== 'admin') {
    header("Location: index.php?message=Access denied&type=error");
    exit();
}

// Include database connection
require_once '../includes/config.php';
require_once '../includes/auth.php';

$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'admin';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize variables
$search_query = '';
$class_filter = '';
$status_filter = '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get all classes for filter (including archived)
$classes = [];
$active_classes = [];
try {
    // Get distinct classes from active students
    $stmt = $pdo->query("SELECT DISTINCT class FROM students WHERE status = 'active' ORDER BY class");
    $active_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all distinct classes including archived
    $stmt = $pdo->query("SELECT DISTINCT class FROM students ORDER BY class");
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Classes fetched: " . count($classes));
} catch (Exception $e) {
    error_log("Error fetching classes: " . $e->getMessage());
}

// Initialize variables for pagination and students
$total_students = 0;
$total_pages = 1;
$students = [];

// Handle search and filters
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$class_filter = isset($_GET['class']) ? trim($_GET['class']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Build query with filters
$conditions = [];
$params = [];

if (!empty($search_query)) {
    $conditions[] = "(full_name LIKE ? OR admission_number LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

if (!empty($class_filter) && $class_filter !== 'all') {
    $conditions[] = "class = ?";
    $params[] = $class_filter;
}

if (!empty($status_filter) && $status_filter !== 'all') {
    $conditions[] = "status = ?";
    $params[] = $status_filter;
}

$where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get total count for pagination
try {
    $count_sql = "SELECT COUNT(*) as total FROM students $where_clause";
    $stmt = $pdo->prepare($count_sql);
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_students = $result['total'] ?? 0;
    $total_pages = ceil($total_students / $limit);
} catch (Exception $e) {
    error_log("Error counting students: " . $e->getMessage());
    $total_students = 0;
}

// Get students with pagination
try {
    $sql = "SELECT * FROM students $where_clause ORDER BY class, full_name LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching students: " . $e->getMessage());
    $students = [];
}

// Handle student actions (POST requests)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add new student
    if ($action === 'add_student') {
        $admission_number = trim($_POST['admission_number']);
        $full_name = trim($_POST['full_name']);
        $class = trim($_POST['class']);
        $status = $_POST['status'] ?? 'active';

        // Generate initial password (surname in lowercase)
        $surname = explode(' ', $full_name)[0];
        $password = password_hash(strtolower($surname), PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO students (admission_number, password, full_name, class, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$admission_number, $password, $full_name, $class, $status]);

            logActivity($_SESSION['admin_id'], 'admin', "Added new student: $full_name ($admission_number)");

            header("Location: manage-students.php?message=Student added successfully&type=success");
            exit();
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                header("Location: manage-students.php?message=Admission number already exists&type=error");
            } else {
                header("Location: manage-students.php?message=Error adding student&type=error");
            }
            exit();
        }
    }
    // Update student
    elseif ($action === 'update_student') {
        $student_id = $_POST['student_id'];
        $admission_number = trim($_POST['admission_number']);
        $full_name = trim($_POST['full_name']);
        $class = trim($_POST['class']);
        $status = $_POST['status'] ?? 'active';

        try {
            $stmt = $pdo->prepare("UPDATE students SET admission_number = ?, full_name = ?, class = ?, status = ? WHERE id = ?");
            $stmt->execute([$admission_number, $full_name, $class, $status, $student_id]);

            logActivity($_SESSION['admin_id'], 'admin', "Updated student: $full_name ($admission_number)");

            header("Location: manage-students.php?message=Student updated successfully&type=success");
            exit();
        } catch (PDOException $e) {
            header("Location: manage-students.php?message=Error updating student&type=error");
            exit();
        }
    }
    // Delete student
    elseif ($action === 'delete_student') {
        $student_id = $_POST['student_id'];

        try {
            // Get student info for logging
            $stmt = $pdo->prepare("SELECT full_name, admission_number FROM students WHERE id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            // Delete student
            $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
            $stmt->execute([$student_id]);

            logActivity($_SESSION['admin_id'], 'admin', "Deleted student: {$student['full_name']} ({$student['admission_number']})");

            header("Location: manage-students.php?message=Student deleted successfully&type=success");
            exit();
        } catch (Exception $e) {
            header("Location: manage-students.php?message=Error deleting student&type=error");
            exit();
        }
    }
    // Transfer/Promote students
    elseif ($action === 'transfer_students') {
        $selected_students = $_POST['selected_students'] ?? [];
        $target_class = trim($_POST['target_class'] ?? '');
        $transfer_type = $_POST['transfer_type'] ?? 'promote';

        if (empty($selected_students) || empty($target_class)) {
            header("Location: manage-students.php?message=Please select students and target class&type=error");
            exit();
        }

        try {
            $placeholders = str_repeat('?,', count($selected_students) - 1) . '?';
            $stmt = $pdo->prepare("UPDATE students SET class = ? WHERE id IN ($placeholders)");
            $params = array_merge([$target_class], $selected_students);
            $stmt->execute($params);

            $count = count($selected_students);
            $action_text = $transfer_type === 'promote' ? 'promoted' : 'transferred';

            logActivity($_SESSION['admin_id'], 'admin', "$action_text $count students to class: $target_class");

            header("Location: manage-students.php?message=$count student(s) successfully $action_text to $target_class&type=success");
            exit();
        } catch (Exception $e) {
            error_log("Transfer error: " . $e->getMessage());
            header("Location: manage-students.php?message=Error transferring students: " . $e->getMessage() . "&type=error");
            exit();
        }
    }
    // Archive students
    elseif ($action === 'archive_students') {
        $selected_students = $_POST['selected_students'] ?? [];
        $archive_reason = trim($_POST['archive_reason'] ?? 'Graduated');

        if (empty($selected_students)) {
            header("Location: manage-students.php?message=Please select students to archive&type=error");
            exit();
        }

        try {
            $placeholders = str_repeat('?,', count($selected_students) - 1) . '?';

            // Update status to archived (inactive) and record archive info
            $stmt = $pdo->prepare("UPDATE students SET status = 'archived', archive_reason = ?, archived_at = NOW() WHERE id IN ($placeholders)");
            $params = array_merge([$archive_reason], $selected_students);
            $stmt->execute($params);

            $count = count($selected_students);
            logActivity($_SESSION['admin_id'], 'admin', "Archived $count students. Reason: $archive_reason");

            header("Location: manage-students.php?message=$count student(s) archived successfully&type=success");
            exit();
        } catch (Exception $e) {
            error_log("Archive error: " . $e->getMessage());
            header("Location: manage-students.php?message=Error archiving students: " . $e->getMessage() . "&type=error");
            exit();
        }
    }
    // Restore students from archive
    elseif ($action === 'restore_students') {
        $selected_students = $_POST['selected_students'] ?? [];

        if (empty($selected_students)) {
            header("Location: manage-students.php?message=Please select students to restore&type=error");
            exit();
        }

        try {
            $placeholders = str_repeat('?,', count($selected_students) - 1) . '?';
            $stmt = $pdo->prepare("UPDATE students SET status = 'active', archive_reason = NULL, archived_at = NULL WHERE id IN ($placeholders)");
            $stmt->execute($selected_students);

            $count = count($selected_students);
            logActivity($_SESSION['admin_id'], 'admin', "Restored $count students from archive");

            header("Location: manage-students.php?message=$count student(s) restored successfully&type=success");
            exit();
        } catch (Exception $e) {
            error_log("Restore error: " . $e->getMessage());
            header("Location: manage-students.php?message=Error restoring students: " . $e->getMessage() . "&type=error");
            exit();
        }
    }
    // Bulk actions (original)
    elseif ($action === 'bulk_actions') {
        $bulk_action = $_POST['bulk_action'] ?? '';
        $selected_students = $_POST['selected_students'] ?? [];

        if (!empty($selected_students) && !empty($bulk_action)) {
            $placeholders = str_repeat('?,', count($selected_students) - 1) . '?';

            if ($bulk_action === 'activate') {
                $stmt = $pdo->prepare("UPDATE students SET status = 'active' WHERE id IN ($placeholders)");
                $stmt->execute($selected_students);
                $message = count($selected_students) . " student(s) activated successfully";
            } elseif ($bulk_action === 'deactivate') {
                $stmt = $pdo->prepare("UPDATE students SET status = 'inactive' WHERE id IN ($placeholders)");
                $stmt->execute($selected_students);
                $message = count($selected_students) . " student(s) deactivated successfully";
            } elseif ($bulk_action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM students WHERE id IN ($placeholders)");
                $stmt->execute($selected_students);
                $message = count($selected_students) . " student(s) deleted successfully";
            } elseif ($bulk_action === 'reset_password') {
                // Reset passwords to surname (lowercase)
                $stmt = $pdo->prepare("SELECT id, full_name FROM students WHERE id IN ($placeholders)");
                $stmt->execute($selected_students);
                $students_to_reset = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($students_to_reset as $student) {
                    $surname = explode(' ', $student['full_name'])[0];
                    $new_password = password_hash(strtolower($surname), PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE students SET password = ? WHERE id = ?");
                    $stmt->execute([$new_password, $student['id']]);
                }
                $message = count($selected_students) . " student password(s) reset to surname (lowercase) successfully";
            }

            logActivity($_SESSION['admin_id'], 'admin', $message);
            header("Location: manage-students.php?message=" . urlencode($message) . "&type=success");
            exit();
        }
    }
}

// Handle export requests
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];

    if ($export_type === 'csv') {
        exportStudentsCSV();
        exit();
    } elseif ($export_type === 'pdf') {
        exportStudentsPDF();
        exit();
    }
}

// Function to export students to CSV
function exportStudentsCSV()
{
    global $pdo, $search_query, $class_filter, $status_filter;

    // Build query with filters
    $conditions = [];
    $params = [];

    if (!empty($search_query)) {
        $conditions[] = "(full_name LIKE ? OR admission_number LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }

    if (!empty($class_filter) && $class_filter !== 'all') {
        $conditions[] = "class = ?";
        $params[] = $class_filter;
    }

    if (!empty($status_filter) && $status_filter !== 'all') {
        $conditions[] = "status = ?";
        $params[] = $status_filter;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=students_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');

    // Add BOM for UTF-8
    fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

    // Column headers
    fputcsv($output, ['Admission Number', 'Full Name', 'Class', 'Status', 'Archive Reason', 'Archived Date', 'Created Date']);

    // Get filtered students
    $sql = "SELECT * FROM students $where_clause ORDER BY class, full_name";
    $stmt = $pdo->prepare($sql);
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($students as $student) {
        fputcsv($output, [
            $student['admission_number'],
            $student['full_name'],
            $student['class'],
            $student['status'],
            $student['archive_reason'] ?? '',
            $student['archived_at'] ?? '',
            $student['created_at']
        ]);
    }

    fclose($output);
    exit();
}

// Function to export students to PDF
function exportStudentsPDF()
{
    global $pdo, $search_query, $class_filter, $status_filter;

    // Build query with filters - same as main page
    $conditions = [];
    $params = [];

    if (!empty($search_query)) {
        $conditions[] = "(full_name LIKE ? OR admission_number LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }

    if (!empty($class_filter) && $class_filter !== 'all') {
        $conditions[] = "class = ?";
        $params[] = $class_filter;
    }

    if (!empty($status_filter) && $status_filter !== 'all') {
        $conditions[] = "status = ?";
        $params[] = $status_filter;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // Get students with filters applied
    $sql = "SELECT * FROM students $where_clause ORDER BY class, full_name";
    $stmt = $pdo->prepare($sql);
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate summary statistics
    $total_students = count($students);
    $active_count = count(array_filter($students, function ($s) {
        return $s['status'] == 'active';
    }));
    $inactive_count = count(array_filter($students, function ($s) {
        return $s['status'] == 'inactive';
    }));
    $archived_count = count(array_filter($students, function ($s) {
        return $s['status'] == 'archived';
    }));

    // Group by class for statistics
    $class_stats = [];
    foreach ($students as $student) {
        $class = $student['class'];
        if (!isset($class_stats[$class])) {
            $class_stats[$class] = 0;
        }
        $class_stats[$class]++;
    }

    // Build filter description for display
    $filter_description = [];
    if (!empty($search_query)) {
        $filter_description[] = "Search: \"" . htmlspecialchars($search_query) . "\"";
    }
    if (!empty($class_filter) && $class_filter !== 'all') {
        $filter_description[] = "Class: " . htmlspecialchars($class_filter);
    }
    if (!empty($status_filter) && $status_filter !== 'all') {
        $filter_description[] = "Status: " . ucfirst(htmlspecialchars($status_filter));
    }
    $has_filters = !empty($filter_description);

    // Set headers for PDF (HTML content with print styles)
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename=students_export_' . date('Y-m-d') . '.pdf');

?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Students Export Report</title>
        <style>
            @media print {
                body {
                    margin: 0;
                    padding: 20px;
                }

                .page-break {
                    page-break-after: always;
                }

                .no-print {
                    display: none;
                }
            }

            * {
                font-family: 'DejaVu Sans', 'Segoe UI', Arial, sans-serif;
                box-sizing: border-box;
            }

            body {
                padding: 20px;
                background: white;
            }

            .no-print {
                text-align: center;
                margin-bottom: 20px;
                padding: 10px;
                background: #f5f5f5;
                border-radius: 8px;
            }

            .no-print button {
                padding: 10px 20px;
                background: #3498db;
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-size: 14px;
                margin: 0 5px;
            }

            .no-print button:hover {
                background: #2980b9;
            }

            .btn-save {
                background: #27ae60 !important;
            }

            .btn-save:hover {
                background: #219653 !important;
            }

            .btn-print {
                background: #3498db !important;
            }

            .btn-print:hover {
                background: #2980b9 !important;
            }

            .btn-close {
                background: #e74c3c !important;
            }

            .btn-close:hover {
                background: #c0392b !important;
            }

            .report-header {
                text-align: center;
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 2px solid #2c3e50;
            }

            .report-header h1 {
                color: #2c3e50;
                margin-bottom: 5px;
                font-size: 24px;
            }

            .report-header p {
                color: #666;
                font-size: 12px;
                margin: 5px 0;
            }

            .filter-info {
                background: #f5f5f5;
                padding: 10px 15px;
                border-radius: 5px;
                margin-bottom: 20px;
                font-size: 12px;
                border-left: 4px solid #3498db;
            }

            .filter-info strong {
                color: #2c3e50;
            }

            .summary {
                margin-bottom: 20px;
                padding: 15px;
                background: linear-gradient(135deg, #e8f4fc 0%, #d4eaf7 100%);
                border-radius: 8px;
                border-left: 4px solid #3498db;
            }

            .summary h3 {
                margin-bottom: 10px;
                color: #2c3e50;
                font-size: 14px;
            }

            .summary-grid {
                display: flex;
                gap: 20px;
                flex-wrap: wrap;
            }

            .summary-item {
                background: white;
                padding: 10px 20px;
                border-radius: 5px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                min-width: 120px;
            }

            .summary-item .label {
                font-size: 11px;
                color: #666;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .summary-item .value {
                font-size: 20px;
                font-weight: bold;
                color: #2c3e50;
            }

            .class-stats {
                margin-bottom: 20px;
                padding: 15px;
                background: white;
                border-radius: 8px;
                border: 1px solid #e0e0e0;
            }

            .class-stats h3 {
                margin-bottom: 10px;
                color: #2c3e50;
                font-size: 14px;
            }

            .class-stats-grid {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }

            .class-stat-item {
                background: #f8f9fa;
                padding: 8px 15px;
                border-radius: 20px;
                font-size: 12px;
            }

            .class-stat-item strong {
                color: #3498db;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
                font-size: 11px;
            }

            th {
                background: #2c3e50;
                color: white;
                padding: 10px 8px;
                text-align: left;
                font-weight: bold;
            }

            td {
                padding: 8px;
                border-bottom: 1px solid #ddd;
                vertical-align: top;
            }

            tr:hover {
                background: #f9f9f9;
            }

            .status-active {
                color: #27ae60;
                font-weight: bold;
            }

            .status-inactive {
                color: #e74c3c;
                font-weight: bold;
            }

            .status-archived {
                color: #f39c12;
                font-weight: bold;
            }

            .class-badge {
                background: #e8f4fc;
                color: #3498db;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 10px;
                font-weight: bold;
                display: inline-block;
            }

            .archive-badge {
                background: #fff3cd;
                color: #f39c12;
                padding: 2px 6px;
                border-radius: 10px;
                font-size: 9px;
                margin-left: 5px;
            }

            .footer {
                text-align: center;
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #ddd;
                font-size: 10px;
                color: #999;
            }

            @media (max-width: 768px) {
                .summary-grid {
                    gap: 10px;
                }

                .summary-item {
                    padding: 8px 15px;
                    min-width: 100px;
                }

                .summary-item .value {
                    font-size: 16px;
                }

                table {
                    font-size: 10px;
                }

                th,
                td {
                    padding: 6px 4px;
                }
            }
        </style>
    </head>

    <body>
        <div class="no-print">
            <button class="btn-save" onclick="downloadAsPDF()"><i class="fas fa-download"></i> Save as PDF (Ctrl+S)</button>
            <button class="btn-print" onclick="printReport()"><i class="fas fa-print"></i> Print (Ctrl+P)</button>
            <button class="btn-close" onclick="closeWindow()"><i class="fas fa-times"></i> Close (Esc)</button>
        </div>

        <div class="report-header">
            <h1>📋 Students Management Report</h1>
            <p>Generated on: <?php echo date('F j, Y, g:i a'); ?></p>
            <p>Digital CBT System - The Climax Brains Academy</p>
        </div>

        <?php if ($has_filters): ?>
            <div class="filter-info">
                <strong>📌 Applied Filters:</strong><br>
                <?php echo implode('<br>', $filter_description); ?>
            </div>
        <?php endif; ?>

        <div class="summary">
            <h3>📊 Summary Statistics</h3>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="label">Total Students</div>
                    <div class="value"><?php echo $total_students; ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Active</div>
                    <div class="value"><?php echo $active_count; ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Inactive</div>
                    <div class="value"><?php echo $inactive_count; ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Archived</div>
                    <div class="value"><?php echo $archived_count; ?></div>
                </div>
            </div>
        </div>

        <?php if (!empty($class_stats)): ?>
            <div class="class-stats">
                <h3>🏫 Class Distribution</h3>
                <div class="class-stats-grid">
                    <?php foreach ($class_stats as $class => $count): ?>
                        <div class="class-stat-item">
                            <strong><?php echo htmlspecialchars($class); ?></strong>: <?php echo $count; ?> students
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Admission No.</th>
                    <th>Full Name</th>
                    <th>Class</th>
                    <th>Status</th>
                    <th>Created Date</th>
                </tr>
            </thead>
            <tbody>
                <?php $counter = 1; ?>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo htmlspecialchars($student['admission_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                        <td>
                            <span class="class-badge"><?php echo htmlspecialchars($student['class']); ?></span>
                            <?php if ($student['status'] === 'archived' && !empty($student['archive_reason'])): ?>
                                <span class="archive-badge"><?php echo htmlspecialchars($student['archive_reason']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-<?php echo $student['status']; ?>">
                                <?php echo ucfirst($student['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($student['created_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer">
            <p>This report was generated automatically by the Digital CBT System. &copy; <?php echo date('Y'); ?> All rights reserved.</p>
            <p>Report generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>

        <script>
            // Function to download HTML as PDF using html2pdf library
            function downloadAsPDF() {
                // Show loading indicator
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF...';
                button.disabled = true;

                // Get the content to convert (everything except the button bar)
                const element = document.body.cloneNode(true);
                const noPrintDiv = element.querySelector('.no-print');
                if (noPrintDiv) {
                    noPrintDiv.style.display = 'none';
                }

                // Create a temporary container for the PDF content
                const tempDiv = document.createElement('div');
                tempDiv.appendChild(element);
                tempDiv.style.padding = '20px';
                tempDiv.style.background = 'white';

                // Options for PDF generation
                const opt = {
                    margin: [0.5, 0.5, 0.5, 0.5],
                    filename: 'students_report_<?php echo date('Y-m-d'); ?>.pdf',
                    image: {
                        type: 'jpeg',
                        quality: 0.98
                    },
                    html2canvas: {
                        scale: 2,
                        letterRendering: true
                    },
                    jsPDF: {
                        unit: 'in',
                        format: 'a4',
                        orientation: 'portrait'
                    }
                };

                // Check if html2pdf is available, if not load it
                if (typeof html2pdf === 'undefined') {
                    // Load html2pdf library dynamically
                    const script = document.createElement('script');
                    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
                    script.onload = function() {
                        html2pdf().set(opt).from(element).save()
                            .then(() => {
                                button.innerHTML = originalText;
                                button.disabled = false;
                            })
                            .catch(err => {
                                console.error('PDF generation error:', err);
                                alert('Error generating PDF. Trying fallback method...');
                                fallbackPrint();
                                button.innerHTML = originalText;
                                button.disabled = false;
                            });
                    };
                    script.onerror = function() {
                        alert('Failed to load PDF library. Using print method instead.');
                        fallbackPrint();
                        button.innerHTML = originalText;
                        button.disabled = false;
                    };
                    document.head.appendChild(script);
                } else {
                    html2pdf().set(opt).from(element).save()
                        .then(() => {
                            button.innerHTML = originalText;
                            button.disabled = false;
                        })
                        .catch(err => {
                            console.error('PDF generation error:', err);
                            alert('Error generating PDF. Using print method instead.');
                            fallbackPrint();
                            button.innerHTML = originalText;
                            button.disabled = false;
                        });
                }
            }

            // Fallback print method if PDF generation fails
            function fallbackPrint() {
                if (confirm('PDF generation failed. Would you like to print instead? You can save as PDF from the print dialog.')) {
                    window.print();
                }
            }

            // Print function
            function printReport() {
                window.print();
            }

            // Close window function with confirmation
            function closeWindow() {
                if (confirm('Are you sure you want to close this report?')) {
                    window.close();
                    // Fallback if window.close doesn't work
                    setTimeout(function() {
                        window.location.href = 'manage-students.php';
                    }, 500);
                }
            }

            // Optional: Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    downloadAsPDF();
                } else if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    printReport();
                } else if (e.key === 'Escape') {
                    closeWindow();
                }
            });
        </script>
    </body>

    </html>
<?php
    exit();
}

// Function to get promotion classes based on current class
function getPromotionClass($current_class)
{
    $promotion_map = [
        'JSS 1' => 'JSS 2',
        'JSS 2' => 'JSS 3',
        'JSS 3' => 'SS 1',
        'SS 1' => 'SS 2',
        'SS 2' => 'SS 3',
        'SS 3' => 'Graduated'
    ];

    return $promotion_map[$current_class] ?? $current_class;
}

// Get distinct classes for promotion/transfer dropdown
$all_classes = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT class FROM students WHERE class IS NOT NULL AND class != '' ORDER BY class");
    $all_classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching classes: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - Admin Dashboard</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* CSS styles (same as previous version) */
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --purple-color: #9b59b6;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
            transition: all 0.3s ease;
            z-index: 100;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0 0 20px 0;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding: 0 20px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--secondary-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .logo-text h3 {
            font-size: 1.2rem;
            margin-bottom: 2px;
        }

        .logo-text p {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .admin-info {
            text-align: center;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 0 15px 20px;
        }

        .admin-info h4 {
            margin-bottom: 5px;
            font-size: 1rem;
        }

        .admin-info p {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .nav-links {
            list-style: none;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--secondary-color);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: var(--secondary-color);
        }

        .nav-links i {
            width: 20px;
            text-align: center;
        }

        /* Main Content Styles */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .top-header {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .header-title p {
            color: #666;
            font-size: 0.9rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), #2980b9);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #219653);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #d68910);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info-color), #2980b9);
            color: white;
        }

        .btn-purple {
            background: linear-gradient(135deg, var(--purple-color), #8e44ad);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        /* Search and Filters */
        .search-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .search-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-color);
        }

        .form-control {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        select.form-control {
            cursor: pointer;
        }

        .search-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        /* Action Bar */
        .action-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .bulk-select {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .bulk-actions-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Students Table */
        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .data-table th {
            background: var(--light-color);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--primary-color);
            border-bottom: 2px solid #ddd;
        }

        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        .data-table tr.selected {
            background: #e3f2fd;
        }

        .data-table tr.archived {
            background: #fef5e7;
            opacity: 0.8;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--secondary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        .status-active {
            background: #d5f4e6;
            color: var(--success-color);
        }

        .status-inactive {
            background: #f8d7da;
            color: var(--danger-color);
        }

        .status-archived {
            background: #fff3cd;
            color: var(--warning-color);
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .page-item {
            list-style: none;
        }

        .page-link {
            padding: 8px 15px;
            background: white;
            border: 1px solid #ddd;
            color: var(--primary-color);
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: var(--light-color);
        }

        .page-item.active .page-link {
            background: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 550px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
            transition: color 0.3s ease;
        }

        .close-modal:hover {
            color: var(--danger-color);
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: #d5f4e6;
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid var(--warning-color);
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid var(--info-color);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
        }

        .empty-state-icon {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            color: #666;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #999;
        }

        .class-badge {
            padding: 4px 10px;
            background: #e8f4fc;
            color: var(--secondary-color);
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .logout-btn {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        .checkbox-container input[type="checkbox"] {
            margin-right: 8px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .archive-badge {
            font-size: 0.7rem;
            background: #f39c12;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: block;
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 101;
                background: var(--primary-color);
                color: white;
                border: none;
                width: 45px;
                height: 45px;
                border-radius: 10px;
                font-size: 20px;
                cursor: pointer;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            }

            .search-form {
                grid-template-columns: 1fr;
            }

            .action-bar {
                flex-direction: column;
            }

            .bulk-actions-form {
                flex-wrap: wrap;
            }

            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="logo-text">
                    <h3><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?></h3>
                    <p>Admin Panel</p>
                </div>
            </div>
        </div>

        <div class="admin-info">
            <h4><?php echo htmlspecialchars($admin_name); ?></h4>
            <p><?php echo ucfirst(str_replace('_', ' ', $admin_role)); ?></p>
        </div>

        <ul class="nav-links">
            <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="manage-students.php" class="active"><i class="fas fa-users"></i> Manage Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>Manage Students</h1>
                <p>View, add, edit, transfer, and archive student accounts</p>
            </div>
            <div class="header-actions">
                <a href="?export=csv&search=<?php echo urlencode($search_query); ?>&class=<?php echo urlencode($class_filter); ?>&status=<?php echo urlencode($status_filter); ?>" class="btn btn-warning">
                    <i class="fas fa-file-excel"></i> Export CSV
                </a>
                <a href="?export=pdf&search=<?php echo urlencode($search_query); ?>&class=<?php echo urlencode($class_filter); ?>&status=<?php echo urlencode($status_filter); ?>" class="btn btn-danger">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-user-plus"></i> Add Student
                </button>
            </div>
        </div>

        <!-- Display Alert Messages -->
        <?php if (isset($_GET['message'])): ?>
            <div class="alert alert-<?php echo $_GET['type'] ?? 'success'; ?>">
                <i class="fas fa-<?php echo $_GET['type'] === 'error' ? 'exclamation-triangle' : ($_GET['type'] === 'warning' ? 'exclamation-circle' : 'check-circle'); ?>"></i>
                <?php echo htmlspecialchars($_GET['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Search and Filters -->
        <div class="search-container">
            <form method="GET" class="search-form">
                <div class="form-group">
                    <label for="search"><i class="fas fa-search"></i> Search</label>
                    <input type="text" id="search" name="search" class="form-control"
                        placeholder="Search by name or admission number"
                        value="<?php echo htmlspecialchars($search_query); ?>">
                </div>

                <div class="form-group">
                    <label for="class"><i class="fas fa-graduation-cap"></i> Class</label>
                    <select id="class" name="class" class="form-control">
                        <option value="all">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo htmlspecialchars($class['class']); ?>"
                                <?php echo ($class_filter === $class['class']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status"><i class="fas fa-circle"></i> Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="all">All Status</option>
                        <option value="active" <?php echo ($status_filter === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($status_filter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        <option value="archived" <?php echo ($status_filter === 'archived') ? 'selected' : ''; ?>>Archived</option>
                    </select>
                </div>

                <div class="search-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="manage-students.php" class="btn btn-warning">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <div class="bulk-select">
                <label class="checkbox-container">
                    <input type="checkbox" id="selectAll">
                    <span>Select All</span>
                </label>
                <span id="selectedCount">0 students selected</span>
            </div>

            <div class="bulk-actions-form">
                <form method="POST" id="transferForm" style="display: inline-flex; gap: 10px;">
                    <input type="hidden" name="action" value="transfer_students">
                    <select name="target_class" class="form-control" required>
                        <option value="">Select Target Class</option>
                        <option value="---">--- Promote to Next Class ---</option>
                        <?php
                        $classes_list = ['JSS 1', 'JSS 2', 'JSS 3', 'SS 1', 'SS 2', 'SS 3', 'Graduated'];
                        foreach ($classes_list as $class_option):
                        ?>
                            <option value="<?php echo $class_option; ?>"><?php echo $class_option; ?></option>
                        <?php endforeach; ?>
                        <option value="---">--- Or Enter Custom Class ---</option>
                    </select>
                    <input type="text" name="target_class_custom" class="form-control" placeholder="Custom class name" style="width: 150px;">
                    <button type="button" class="btn btn-success" onclick="submitTransfer()">
                        <i class="fas fa-arrow-right"></i> Transfer/Promote
                    </button>
                </form>

                <form method="POST" id="archiveForm" style="display: inline-flex; gap: 10px;">
                    <input type="hidden" name="action" value="archive_students">
                    <select name="archive_reason" class="form-control" required>
                        <option value="">Select Archive Reason</option>
                        <option value="Graduated">Graduated</option>
                        <option value="Transferred Out">Transferred Out</option>
                        <option value="Dropped Out">Dropped Out</option>
                        <option value="Left School">Left School</option>
                        <option value="Deceased">Deceased</option>
                        <option value="Other">Other</option>
                    </select>
                    <button type="button" class="btn btn-warning" onclick="submitArchive()">
                        <i class="fas fa-archive"></i> Archive Selected
                    </button>
                </form>

                <form method="POST" id="restoreForm" style="display: inline-flex;">
                    <input type="hidden" name="action" value="restore_students">
                    <button type="button" class="btn btn-info" onclick="submitRestore()">
                        <i class="fas fa-undo"></i> Restore Selected
                    </button>
                </form>

                <form method="POST" class="bulk-actions-form" onsubmit="return confirmBulkAction()">
                    <input type="hidden" name="action" value="bulk_actions">
                    <select name="bulk_action" class="form-control" required>
                        <option value="">Bulk Actions</option>
                        <option value="activate">Activate Selected</option>
                        <option value="deactivate">Deactivate Selected</option>
                        <option value="reset_password">Reset Passwords (to Surname)</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-play"></i> Apply
                    </button>
                </form>
            </div>
        </div>

        <!-- Students Table -->
        <div class="table-container">
            <?php if (empty($students) && $total_students > 0): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3>No Students Found on This Page</h3>
                    <p>Try going to page 1 or adjusting your search filters.</p>
                    <a href="?page=1" class="btn btn-primary" style="margin-top: 20px;">
                        <i class="fas fa-arrow-left"></i> Go to First Page
                    </a>
                </div>
            <?php elseif (empty($students)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3>No Students Found</h3>
                    <p>Try adjusting your search or add a new student.</p>
                    <button class="btn btn-primary" onclick="openAddModal()" style="margin-top: 20px;">
                        <i class="fas fa-user-plus"></i> Add First Student
                    </button>
                </div>
            <?php else: ?>
                <form id="bulkForm" method="POST">
                    <input type="hidden" name="action" value="bulk_actions">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">
                                    <input type="checkbox" id="selectAllHeader">
                                </th>
                                <th style="width: 60px;"></th>
                                <th>Admission Number</th>
                                <th>Full Name</th>
                                <th>Class</th>
                                <th>Status</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr class="<?php echo $student['status'] === 'archived' ? 'archived' : ''; ?>">
                                    <td>
                                        <input type="checkbox" class="student-checkbox"
                                            name="selected_students[]" value="<?php echo $student['id']; ?>"
                                            onchange="updateSelectedCount()">
                                    </td>
                                    <td>
                                        <div class="student-avatar">
                                            <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($student['admission_number']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                    <td>
                                        <span class="class-badge"><?php echo htmlspecialchars($student['class']); ?></span>
                                        <?php if ($student['status'] === 'archived' && !empty($student['archive_reason'])): ?>
                                            <span class="archive-badge"><?php echo htmlspecialchars($student['archive_reason']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $student['status']; ?>">
                                            <?php echo ucfirst($student['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($student['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-small btn-primary"
                                                onclick="openEditModal(<?php echo htmlspecialchars(json_encode($student)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-small btn-success"
                                                onclick="promoteStudent(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['class']); ?>')">
                                                <i class="fas fa-arrow-up"></i>
                                            </button>
                                            <button type="button" class="btn btn-small btn-warning"
                                                onclick="resetPassword(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['full_name']); ?>')">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <button type="button" class="btn btn-small btn-danger"
                                                onclick="deleteStudent(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['full_name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <ul class="pagination">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search_query); ?>&class=<?php echo urlencode($class_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>&class=<?php echo urlencode($class_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php elseif ($i == 2 && $page > 4): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php elseif ($i == $total_pages - 1 && $page < $total_pages - 3): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search_query); ?>&class=<?php echo urlencode($class_filter); ?>&status=<?php echo urlencode($status_filter); ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>

        <!-- Footer -->
        <div style="text-align: center; padding: 20px; color: #666; font-size: 0.9rem; margin-top: 30px;">
            <p>&copy; <?php echo date('Y'); ?> Digital CBT System - Showing <?php echo $total_students ?? 0; ?> students (Page <?php echo $page; ?> of <?php echo $total_pages; ?>)</p>
            <p><small>Default password for new students is their surname (lowercase). Reset password also resets to surname.</small></p>
        </div>
    </div>

    <!-- Add Student Modal -->
    <div class="modal" id="addStudentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New Student</h3>
                <button type="button" class="close-modal" onclick="closeAddModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_student">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="add_admission_number">Admission Number *</label>
                        <input type="text" id="add_admission_number" name="admission_number"
                            class="form-control" required placeholder="e.g., STU2024001">
                    </div>
                    <div class="form-group">
                        <label for="add_full_name">Full Name *</label>
                        <input type="text" id="add_full_name" name="full_name"
                            class="form-control" required placeholder="John Doe">
                    </div>
                    <div class="form-group">
                        <label for="add_class">Class *</label>
                        <input type="text" id="add_class" name="class"
                            class="form-control" required placeholder="e.g., JSS 1">
                    </div>
                    <div class="form-group">
                        <label for="add_status">Status</label>
                        <select id="add_status" name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="alert alert-warning" style="margin-top: 20px;">
                        <i class="fas fa-info-circle"></i>
                        <strong>Note:</strong> Initial password will be set to the student's surname in lowercase.
                        For example, if full name is "John Doe", the password is "doe".
                        The student should change it after first login.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Student</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div class="modal" id="editStudentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Student</h3>
                <button type="button" class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_student">
                <input type="hidden" id="edit_student_id" name="student_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_admission_number">Admission Number *</label>
                        <input type="text" id="edit_admission_number" name="admission_number"
                            class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_full_name">Full Name *</label>
                        <input type="text" id="edit_full_name" name="full_name"
                            class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_class">Class *</label>
                        <input type="text" id="edit_class" name="class"
                            class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_status">Status</label>
                        <select id="edit_status" name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Student</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h3>
                <button type="button" class="close-modal" onclick="closeDeleteModal()">&times;</button>
            </div>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete_student">
                <input type="hidden" id="delete_student_id" name="student_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="deleteStudentName"></strong>?</p>
                    <div class="alert alert-error" style="margin-top: 15px;">
                        <i class="fas fa-exclamation-circle"></i>
                        <strong>Warning:</strong> This action cannot be undone. All student data including exam results and submissions will be permanently deleted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Student</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Get selected students IDs
        function getSelectedStudentIds() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            return Array.from(checkboxes).map(cb => cb.value);
        }

        // Submit transfer action
        function submitTransfer() {
            const selectedIds = getSelectedStudentIds();
            if (selectedIds.length === 0) {
                alert('Please select at least one student.');
                return;
            }

            const targetClassSelect = document.querySelector('select[name="target_class"]');
            const targetClassCustom = document.querySelector('input[name="target_class_custom"]');
            let targetClass = targetClassSelect.value;

            if (targetClassCustom.value.trim()) {
                targetClass = targetClassCustom.value.trim();
            }

            if (!targetClass || targetClass === '---') {
                alert('Please select or enter a target class.');
                return;
            }

            if (confirm(`Transfer ${selectedIds.length} student(s) to class: ${targetClass}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'transfer_students';

                const targetClassInput = document.createElement('input');
                targetClassInput.type = 'hidden';
                targetClassInput.name = 'target_class';
                targetClassInput.value = targetClass;

                form.appendChild(actionInput);
                form.appendChild(targetClassInput);

                selectedIds.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_students[]';
                    input.value = id;
                    form.appendChild(input);
                });

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Submit archive action
        function submitArchive() {
            const selectedIds = getSelectedStudentIds();
            if (selectedIds.length === 0) {
                alert('Please select at least one student.');
                return;
            }

            const archiveReason = document.querySelector('select[name="archive_reason"]').value;
            if (!archiveReason) {
                alert('Please select an archive reason.');
                return;
            }

            if (confirm(`Archive ${selectedIds.length} student(s)?\nReason: ${archiveReason}\n\nArchived students will be moved to archive and won't appear in active lists.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'archive_students';

                const reasonInput = document.createElement('input');
                reasonInput.type = 'hidden';
                reasonInput.name = 'archive_reason';
                reasonInput.value = archiveReason;

                form.appendChild(actionInput);
                form.appendChild(reasonInput);

                selectedIds.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_students[]';
                    input.value = id;
                    form.appendChild(input);
                });

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Submit restore action
        function submitRestore() {
            const selectedIds = getSelectedStudentIds();
            if (selectedIds.length === 0) {
                alert('Please select at least one student.');
                return;
            }

            if (confirm(`Restore ${selectedIds.length} student(s) from archive?\n\nThis will set their status back to Active.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'restore_students';

                form.appendChild(actionInput);

                selectedIds.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'selected_students[]';
                    input.value = id;
                    form.appendChild(input);
                });

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Promote single student
        function promoteStudent(studentId, currentClass) {
            const promotionMap = {
                'JSS 1': 'JSS 2',
                'JSS 2': 'JSS 3',
                'JSS 3': 'SS 1',
                'SS 1': 'SS 2',
                'SS 2': 'SS 3',
                'SS 3': 'Graduated'
            };

            let targetClass = promotionMap[currentClass];
            if (!targetClass) {
                targetClass = prompt('Enter the next class for this student:', currentClass);
                if (!targetClass) return;
            }

            if (confirm(`Promote student from ${currentClass} to ${targetClass}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'transfer_students';

                const targetClassInput = document.createElement('input');
                targetClassInput.type = 'hidden';
                targetClassInput.name = 'target_class';
                targetClassInput.value = targetClass;

                const studentInput = document.createElement('input');
                studentInput.type = 'hidden';
                studentInput.name = 'selected_students[]';
                studentInput.value = studentId;

                form.appendChild(actionInput);
                form.appendChild(targetClassInput);
                form.appendChild(studentInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Reset single student password (to surname)
        function resetPassword(studentId, studentName) {
            const surname = studentName.split(' ')[0].toLowerCase();
            if (confirm(`Reset password for ${studentName} to "${surname}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'bulk_actions';

                const bulkActionInput = document.createElement('input');
                bulkActionInput.type = 'hidden';
                bulkActionInput.name = 'bulk_action';
                bulkActionInput.value = 'reset_password';

                const studentInput = document.createElement('input');
                studentInput.type = 'hidden';
                studentInput.name = 'selected_students[]';
                studentInput.value = studentId;

                form.appendChild(actionInput);
                form.appendChild(bulkActionInput);
                form.appendChild(studentInput);
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            }
        }

        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function() {
                sidebar.classList.toggle('active');
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (sidebar && !sidebar.contains(event.target) && mobileMenuBtn && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
            }
        });

        // Bulk selection
        const selectAllCheckbox = document.getElementById('selectAll');
        const selectAllHeader = document.getElementById('selectAllHeader');
        const studentCheckboxes = document.querySelectorAll('.student-checkbox');

        function updateSelectedCount() {
            const selected = document.querySelectorAll('.student-checkbox:checked');
            document.getElementById('selectedCount').textContent = selected.length + ' students selected';

            if (selectAllCheckbox) selectAllCheckbox.checked = selected.length === studentCheckboxes.length;
            if (selectAllHeader) selectAllHeader.checked = selected.length === studentCheckboxes.length;

            studentCheckboxes.forEach(cb => {
                const row = cb.closest('tr');
                if (cb.checked) {
                    row.classList.add('selected');
                } else {
                    row.classList.remove('selected');
                }
            });
        }

        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                studentCheckboxes.forEach(cb => {
                    cb.checked = this.checked;
                });
                updateSelectedCount();
            });
        }

        if (selectAllHeader) {
            selectAllHeader.addEventListener('change', function() {
                studentCheckboxes.forEach(cb => {
                    cb.checked = this.checked;
                });
                updateSelectedCount();
            });
        }

        // Modal functions
        function openAddModal() {
            document.getElementById('addStudentModal').style.display = 'flex';
        }

        function closeAddModal() {
            document.getElementById('addStudentModal').style.display = 'none';
        }

        function openEditModal(student) {
            document.getElementById('edit_student_id').value = student.id;
            document.getElementById('edit_admission_number').value = student.admission_number;
            document.getElementById('edit_full_name').value = student.full_name;
            document.getElementById('edit_class').value = student.class;
            document.getElementById('edit_status').value = student.status;
            document.getElementById('editStudentModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editStudentModal').style.display = 'none';
        }

        function deleteStudent(studentId, studentName) {
            document.getElementById('delete_student_id').value = studentId;
            document.getElementById('deleteStudentName').textContent = studentName;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Confirm bulk action
        function confirmBulkAction() {
            const selected = document.querySelectorAll('.student-checkbox:checked');
            if (selected.length === 0) {
                alert('Please select at least one student.');
                return false;
            }

            const action = document.querySelector('select[name="bulk_action"]').value;
            if (!action) {
                alert('Please select a bulk action.');
                return false;
            }

            let message = `Are you sure you want to ${action} ${selected.length} student(s)?`;
            if (action === 'delete') {
                message += '\n\nWarning: This action cannot be undone!';
            } else if (action === 'reset_password') {
                message += '\n\nPasswords will be reset to each student\'s surname (lowercase).';
            }

            return confirm(message);
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddModal();
                closeEditModal();
                closeDeleteModal();
            }

            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                if (selectAllCheckbox) selectAllCheckbox.click();
            }

            if (e.ctrlKey && e.shiftKey && e.key === 'A') {
                e.preventDefault();
                openAddModal();
            }
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();

            const searchInput = document.getElementById('search');
            if (searchInput && window.innerWidth > 768) {
                searchInput.focus();
            }

            // Handle custom class input
            const targetClassSelect = document.querySelector('select[name="target_class"]');
            const targetClassCustom = document.querySelector('input[name="target_class_custom"]');

            if (targetClassSelect && targetClassCustom) {
                targetClassSelect.addEventListener('change', function() {
                    if (this.value !== '---') {
                        targetClassCustom.value = '';
                        targetClassCustom.disabled = this.value !== '---';
                    } else {
                        targetClassCustom.disabled = false;
                    }
                });
                targetClassCustom.disabled = true;
            }
        });

        // Auto-refresh page if there's a message
        if (window.location.search.includes('message=')) {
            setTimeout(() => {
                const url = new URL(window.location);
                url.searchParams.delete('message');
                url.searchParams.delete('type');
                window.history.replaceState({}, '', url);
            }, 3000);
        }
    </script>
</body>

</html>