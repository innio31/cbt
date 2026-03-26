<?php
// admin/view-results.php - View Exam Results
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Get admin information
$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
$admin_role = $_SESSION['admin_role'] ?? 'super_admin';

// Initialize variables
$results = [];
$total_results = 0;
$classes = [];
$subjects = [];
$exams = [];
$filter_class = $_GET['class'] ?? '';
$filter_subject = $_GET['subject'] ?? '';
$filter_exam = $_GET['exam'] ?? '';
$filter_student = $_GET['student'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Get all classes for filter dropdown
try {
    $stmt = $pdo->query("SELECT DISTINCT class FROM students WHERE status = 'active' ORDER BY class");
    $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $error_message = "Error loading classes: " . $e->getMessage();
}

// Get all subjects for filter dropdown
try {
    $stmt = $pdo->query("
        SELECT s.id, s.subject_name, GROUP_CONCAT(sc.class ORDER BY sc.class SEPARATOR ', ') as classes
        FROM subjects s
        LEFT JOIN subject_classes sc ON s.id = sc.subject_id
        GROUP BY s.id, s.subject_name
        ORDER BY s.subject_name
    ");
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error loading subjects: " . $e->getMessage();
}

// Get all exams for filter dropdown
try {
    $stmt = $pdo->query("
        SELECT e.id, e.exam_name, e.class as exam_class, s.subject_name
        FROM exams e
        LEFT JOIN subjects s ON e.subject_id = s.id
        ORDER BY e.created_at DESC
    ");
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error loading exams: " . $e->getMessage();
}

// Build query for results with filters
try {
    $query = "
        SELECT 
            r.*,
            s.full_name as student_name,
            s.class as student_class,
            s.admission_number,
            e.exam_name,
            e.exam_type,
            sub.subject_name,
            e.class as exam_class,
            e.objective_count,
            e.theory_count,
            es.objective_answers,
            TIMESTAMPDIFF(MINUTE, e.created_at, r.submitted_at) as time_after_exam_created
        FROM results r
        JOIN students s ON r.student_id = s.id
        JOIN exams e ON r.exam_id = e.id
        LEFT JOIN subjects sub ON e.subject_id = sub.id
        LEFT JOIN exam_sessions es ON es.exam_id = r.exam_id AND es.student_id = r.student_id
        WHERE 1=1
    ";

    $params = [];

    // Apply filters
    if (!empty($filter_class)) {
        $query .= " AND s.class = ?";
        $params[] = $filter_class;
    }

    if (!empty($filter_subject) && is_numeric($filter_subject)) {
        $query .= " AND e.subject_id = ?";
        $params[] = $filter_subject;
    }

    if (!empty($filter_exam) && is_numeric($filter_exam)) {
        $query .= " AND r.exam_id = ?";
        $params[] = $filter_exam;
    }

    if (!empty($filter_student)) {
        $query .= " AND (s.full_name LIKE ? OR s.admission_number LIKE ?)";
        $params[] = "%$filter_student%";
        $params[] = "%$filter_student%";
    }

    if (!empty($filter_date_from)) {
        $query .= " AND DATE(r.submitted_at) >= ?";
        $params[] = $filter_date_from;
    }

    if (!empty($filter_date_to)) {
        $query .= " AND DATE(r.submitted_at) <= ?";
        $params[] = $filter_date_to;
    }

    // Count total results
    $count_query = "SELECT COUNT(*) FROM (" . $query . ") as filtered_results";
    $stmt = $pdo->prepare(preg_replace('/SELECT .*? FROM/', 'SELECT COUNT(*) as count FROM', $query, 1));
    $stmt->execute($params);
    $total_results = $stmt->fetchColumn();

    // Add ordering and pagination
    $query .= " ORDER BY r.submitted_at DESC LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // Calculate total pages
    $total_pages = $total_results > 0 ? ceil($total_results / $limit) : 1;
    
    // Store filter info for export
    $export_class = $filter_class;
    $export_subject_id = $filter_subject;
    $export_subject_name = '';
    if (!empty($filter_subject)) {
        foreach ($subjects as $subj) {
            if ($subj['id'] == $filter_subject) {
                $export_subject_name = $subj['subject_name'];
                break;
            }
        }
    }
    $export_exam_id = $filter_exam;
    $export_exam_name = '';
    if (!empty($filter_exam)) {
        foreach ($exams as $ex) {
            if ($ex['id'] == $filter_exam) {
                $export_exam_name = $ex['exam_name'];
                break;
            }
        }
    }
    
} catch (Exception $e) {
    $error_message = "Error loading results: " . $e->getMessage();
    error_log("SQL Error: " . $e->getMessage());
}

// Handle result deletion
if (isset($_POST['delete_result']) && isset($_POST['result_id']) && $admin_role === 'super_admin') {
    $result_id = $_POST['result_id'];

    try {
        $pdo->beginTransaction();

        // Delete the result
        $stmt = $pdo->prepare("DELETE FROM results WHERE id = ?");
        $stmt->execute([$result_id]);

        // Also delete related exam session data
        $pdo->commit();

        $_SESSION['success_message'] = "Result deleted successfully!";
        header("Location: view-results.php");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error deleting result: " . $e->getMessage();
    }
}

// Export to CSV functionality
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=exam_results_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');

    // Add CSV headers
    fputcsv($output, [
        'Admission Number',
        'Student Name',
        'Class',
        'Exam Name',
        'Subject',
        'Exam Type',
        'Objective Score',
        'Theory Score',
        'Total Score',
        'Percentage',
        'Grade',
        'Time Taken (mins)',
        'Submitted Date'
    ]);

    // Export all filtered results without pagination
    try {
        $export_query = preg_replace('/LIMIT \d+ OFFSET \d+/', '', $query);
        $stmt = $pdo->prepare($export_query);
        $stmt->execute($params);
        $export_results = $stmt->fetchAll();

        foreach ($export_results as $result) {
            fputcsv($output, [
                $result['admission_number'],
                $result['student_name'],
                $result['student_class'],
                $result['exam_name'],
                $result['subject_name'] ?? 'N/A',
                $result['exam_type'],
                $result['objective_score'] ?? 0,
                $result['theory_score'] ?? 0,
                $result['total_score'] ?? 0,
                $result['percentage'] ?? 0,
                $result['grade'] ?? 'N/A',
                $result['time_taken'] ?? 0,
                $result['submitted_at']
            ]);
        }

        fclose($output);
        exit();
    } catch (Exception $e) {
        // If export fails, redirect back with error
        $_SESSION['error_message'] = "Error exporting results: " . $e->getMessage();
        header("Location: view-results.php");
        exit();
    }
}

// Export to PDF functionality
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    // Get all filtered results without pagination for PDF
    try {
        $pdf_query = preg_replace('/LIMIT \d+ OFFSET \d+/', '', $query);
        $stmt = $pdo->prepare($pdf_query);
        $stmt->execute($params);
        $pdf_results = $stmt->fetchAll();
        
        // Get class name for display
        $class_display = !empty($filter_class) ? $filter_class : 'All Classes';
        
        // Get subject name for display
        $subject_display = !empty($export_subject_name) ? $export_subject_name : 'All Subjects';
        
        // Get exam name for display
        $exam_display = !empty($export_exam_name) ? $export_exam_name : 'All Exams';
        
        // Generate HTML for PDF
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <title>Exam Results Report</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 20px;
                    font-size: 12px;
                }
                .header {
                    text-align: center;
                    margin-bottom: 30px;
                    border-bottom: 2px solid #333;
                    padding-bottom: 20px;
                }
                .header h1 {
                    margin: 0;
                    color: #2c3e50;
                    font-size: 24px;
                }
                .header p {
                    margin: 5px 0;
                    color: #666;
                }
                .report-info {
                    margin-bottom: 20px;
                    padding: 15px;
                    background: #f5f5f5;
                    border-radius: 5px;
                }
                .report-info table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .report-info td {
                    padding: 5px;
                    vertical-align: top;
                }
                .report-info td.label {
                    font-weight: bold;
                    width: 100px;
                }
                table.data-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                }
                table.data-table th {
                    background: #2c3e50;
                    color: white;
                    padding: 10px;
                    text-align: left;
                    font-weight: bold;
                }
                table.data-table td {
                    border: 1px solid #ddd;
                    padding: 8px;
                }
                table.data-table tr:nth-child(even) {
                    background: #f9f9f9;
                }
                .footer {
                    margin-top: 30px;
                    text-align: center;
                    font-size: 10px;
                    color: #999;
                    border-top: 1px solid #ddd;
                    padding-top: 10px;
                }
                .grade-badge {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 3px;
                    font-weight: bold;
                }
                .grade-A { background: #d4edda; color: #155724; }
                .grade-B { background: #fff3cd; color: #856404; }
                .grade-C { background: #f8d7da; color: #721c24; }
                .grade-F { background: #dc3545; color: white; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>' . htmlspecialchars(defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy') . '</h1>
                <p>Exam Results Report</p>
                <p>Generated on: ' . date('F d, Y h:i A') . '</p>
            </div>
            
            <div class="report-info">
                <table>
                    <tr>
                        <td class="label">Class:</td>
                        <td><strong>' . htmlspecialchars($class_display) . '</strong></td>
                        <td class="label">Subject:</td>
                        <td><strong>' . htmlspecialchars($subject_display) . '</strong></td>
                    </tr>
                    <tr>
                        <td class="label">Exam:</td>
                        <td><strong>' . htmlspecialchars($exam_display) . '</strong></td>
                        <td class="label">Total Students:</td>
                        <td><strong>' . count($pdf_results) . '</strong></td>
                    </tr>
                </table>
            </div>
            
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 5%">S/N</th>
                        <th style="width: 45%">Student Name</th>
                        <th style="width: 25%">Class</th>
                        <th style="width: 25%">Score</th>
                    </tr>
                </thead>
                <tbody>';
        
        $sn = 1;
        foreach ($pdf_results as $result) {
            // Calculate score display (e.g., 4/10)
            $total_score = isset($result['total_score']) ? (int)$result['total_score'] : 0;
            $max_score = 0;
            
            // Calculate max possible score
            if (isset($result['objective_score']) && isset($result['theory_score'])) {
                $max_score = (($result['objective_count'] ?? 0) + ($result['theory_count'] ?? 0));
            } else {
                // Fallback: estimate from exam config
                $max_score = ($result['objective_count'] ?? 0) + ($result['theory_count'] ?? 0);
            }
            
            if ($max_score == 0) {
                $max_score = 100; // Default fallback
            }
            
            $score_display = $total_score . '/' . $max_score;
            
            $html .= '
                    <tr>
                        <td>' . $sn++ . '</td>
                        <td>' . htmlspecialchars($result['student_name'] ?? 'N/A') . '</td>
                        <td>' . htmlspecialchars($result['student_class'] ?? 'N/A') . '</td>
                        <td><strong>' . htmlspecialchars($score_display) . '</strong></td>
                    </tr>';
        }
        
        if (empty($pdf_results)) {
            $html .= '
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 40px;">
                            No results found for the selected filters.
                        </td>
                    </tr>';
        }
        
        $html .= '
                </tbody>
            </table>
            
            <div class="footer">
                <p>This is a computer-generated report. For any inquiries, please contact the school administration.</p>
                <p>Page 1 of 1</p>
            </div>
        </body>
        </html>';
        
        // Set headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="exam_results_report_' . date('Y-m-d') . '.pdf"');
        
        // Use wkhtmltopdf or similar - for now, we'll use a simple approach with HTML to PDF
        // Since we can't guarantee wkhtmltopdf is installed, we'll use a browser print-to-PDF friendly format
        // But for actual PDF generation, we'll use TCPDF if available
        
        // Check if TCPDF is available
        if (file_exists('../includes/tcpdf/tcpdf.php')) {
            require_once('../includes/tcpdf/tcpdf.php');
            
            // Create new PDF document
            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            
            // Set document information
            $pdf->SetCreator('Digital CBT System');
            $pdf->SetAuthor('School Administration');
            $pdf->SetTitle('Exam Results Report');
            $pdf->SetSubject('Exam Results');
            
            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Add a page
            $pdf->AddPage();
            
            // Set font
            $pdf->SetFont('helvetica', '', 10);
            
            // Output HTML content
            $pdf->writeHTML($html, true, false, true, false, '');
            
            // Close and output PDF document
            $pdf->Output('exam_results_report_' . date('Y-m-d') . '.pdf', 'D');
            exit();
        } else {
            // Fallback: Output as HTML that can be printed to PDF
            header('Content-Type: text/html');
            header('Content-Disposition: inline; filename="exam_results_report_' . date('Y-m-d') . '.html"');
            echo $html;
            exit();
        }
        
    } catch (Exception $e) {
        error_log("PDF Export error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error exporting PDF: " . $e->getMessage();
        header("Location: view-results.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Results - Admin Dashboard</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* Include all CSS from index.php */
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --sidebar-width: 260px;
            --header-height: 70px;
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

        /* Sidebar Styles (same as index.php) */
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
        }

        .sidebar-header {
            padding: 0 20px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
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
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), #2980b9);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #219653);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #d68910);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(243, 156, 18, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
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

        /* Filter Section */
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-header h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        /* Results Table */
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
        }

        .card-header h3 {
            color: var(--primary-color);
            font-size: 1.3rem;
        }

        .btn-group {
            display: flex;
            gap: 10px;
        }

        .results-summary {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .summary-item {
            text-align: center;
            padding: 10px;
            flex: 1;
        }

        .summary-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .summary-label {
            font-size: 0.9rem;
            color: #666;
        }

        .table-container {
            overflow-x: auto;
            margin-bottom: 20px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .data-table th {
            background: linear-gradient(135deg, var(--primary-color), #1a252f);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: #f9f9f9;
        }

        .data-table tr:nth-child(even) {
            background: #f8f9fa;
        }

        .data-table tr:nth-child(even):hover {
            background: #f1f3f4;
        }

        .grade-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-align: center;
            min-width: 50px;
        }

        .grade-A {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .grade-B {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .grade-C {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .grade-F {
            background: #dc3545;
            color: white;
            border: 1px solid #c82333;
        }

        .percentage-cell {
            font-weight: 600;
        }

        .percentage-excellent {
            color: #27ae60;
        }

        .percentage-good {
            color: #f39c12;
        }

        .percentage-poor {
            color: #e74c3c;
        }

        .score-display {
            font-weight: 600;
            font-size: 1rem;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .action-btn.view {
            background: #e3f2fd;
            color: var(--secondary-color);
            border: 1px solid #bbdefb;
        }

        .action-btn.view:hover {
            background: #bbdefb;
        }

        .action-btn.delete {
            background: #ffebee;
            color: var(--danger-color);
            border: 1px solid #ffcdd2;
        }

        .action-btn.delete:hover {
            background: #ffcdd2;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }

        .page-link {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            text-decoration: none;
            color: var(--primary-color);
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }

        .page-link.active {
            background: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .page-info {
            color: #666;
            font-size: 0.9rem;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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

        /* Modal */
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
            padding: 30px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            margin-bottom: 20px;
        }

        .modal-title {
            color: var(--primary-color);
            font-size: 1.5rem;
        }

        .modal-body {
            margin-bottom: 25px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Loading Spinner */
        .spinner-border {
            display: inline-block;
            width: 2rem;
            height: 2rem;
            border: 0.25em solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            animation: spinner-border .75s linear infinite;
        }

        @keyframes spinner-border {
            to {
                transform: rotate(360deg);
            }
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
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

            .filter-form {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
            }

            .results-summary {
                flex-direction: column;
                gap: 10px;
            }

            .summary-item {
                padding: 10px 0;
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
        }

        @media (min-width: 769px) {
            .mobile-menu-btn {
                display: none;
            }
        }

        /* Print styles for PDF export */
        @media print {
            .sidebar,
            .top-header .header-actions,
            .filter-card,
            .card-header .btn-group,
            .action-buttons,
            .pagination,
            .mobile-menu-btn {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            
            .content-card {
                box-shadow: none !important;
                padding: 0 !important;
            }
            
            .data-table th {
                background: #2c3e50 !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .grade-badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
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
            <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
            <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
            <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
            <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
            <li><a href="view-results.php" class="active"><i class="fas fa-chart-bar"></i> View Results</a></li>
            <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
            <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>Exam Results</h1>
                <p>View and manage student exam results</p>
            </div>
            <div class="header-actions">
                <a href="?export=csv<?php echo !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['export' => ''])) : ''; ?>" class="btn btn-success">
                    <i class="fas fa-file-export"></i> Export CSV
                </a>
                <a href="?export=pdf<?php echo !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['export' => ''])) : ''; ?>" class="btn btn-info" target="_blank">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
                <button class="logout-btn" onclick="window.location.href='../logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success_message'];
                unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo $_SESSION['error_message'];
                unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="filter-card">
            <div class="filter-header">
                <h3>Filter Results</h3>
                <?php if (!empty($_GET)): ?>
                    <a href="view-results.php" class="btn btn-warning">
                        <i class="fas fa-times"></i> Clear Filters
                    </a>
                <?php endif; ?>
            </div>

            <form method="GET" action="">
                <div class="filter-form">
                    <div class="form-group">
                        <label for="class">Class</label>
                        <select name="class" id="class" class="form-control">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $filter_class == $class ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="subject">Subject</label>
                        <select name="subject" id="subject" class="form-control">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>" <?php echo $filter_subject == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    <?php if (!empty($subject['classes'])): ?>
                                        (<?php echo htmlspecialchars($subject['classes']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="exam">Exam</label>
                        <select name="exam" id="exam" class="form-control">
                            <option value="">All Exams</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?php echo $exam['id']; ?>" <?php echo $filter_exam == $exam['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($exam['exam_name']) . ' (' . htmlspecialchars($exam['exam_class']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="student">Student Name/ID</label>
                        <input type="text" name="student" id="student" class="form-control"
                            placeholder="Search by name or admission number" value="<?php echo htmlspecialchars($filter_student); ?>">
                    </div>

                    <div class="form-group">
                        <label for="date_from">Date From</label>
                        <input type="date" name="date_from" id="date_from" class="form-control" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>

                    <div class="form-group">
                        <label for="date_to">Date To</label>
                        <input type="date" name="date_to" id="date_to" class="form-control" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <button type="reset" class="btn btn-warning" onclick="window.location.href='view-results.php'">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <!-- Results Table -->
        <div class="content-card">
            <div class="card-header">
                <h3>Exam Results (<?php echo number_format($total_results); ?> records found)</h3>
                <div class="btn-group">
                    <button class="btn btn-primary" onclick="refreshResults()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>

            <?php if ($total_results > 0): ?>
                <!-- Results Summary -->
                <div class="results-summary">
                    <div class="summary-item">
                        <div class="summary-value"><?php echo $total_results; ?></div>
                        <div class="summary-label">Total Results</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value">
                            <?php
                            // Calculate average percentage
                            $avg_percentage = 0;
                            if ($total_results > 0 && !empty($results)) {
                                $total_percentage = 0;
                                foreach ($results as $result) {
                                    $total_percentage += $result['percentage'] ?? 0;
                                }
                                $avg_percentage = $total_percentage / count($results);
                            }
                            echo number_format($avg_percentage, 1) . '%';
                            ?>
                        </div>
                        <div class="summary-label">Average Score</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value">
                            <?php
                            // Count passing students (assuming 40% is pass)
                            $passing_count = 0;
                            foreach ($results as $result) {
                                if (($result['percentage'] ?? 0) >= 40) {
                                    $passing_count++;
                                }
                            }
                            echo $passing_count;
                            ?>
                        </div>
                        <div class="summary-label">Passing Students</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value">
                            <?php
                            $unique_exams = [];
                            foreach ($results as $result) {
                                if (isset($result['exam_id'])) {
                                    $unique_exams[$result['exam_id']] = true;
                                }
                            }
                            echo count($unique_exams);
                            ?>
                        </div>
                        <div class="summary-label">Unique Exams</div>
                    </div>
                </div>

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Admission No.</th>
                                <th>Class</th>
                                <th>Exam</th>
                                <th>Subject</th>
                                <th>Score</th>
                                <th>Percentage</th>
                                <th>Grade</th>
                                <th>Time Taken</th>
                                <th>Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result): ?>
                                <?php
                                // Determine percentage color
                                $percentage_class = '';
                                $percentage = $result['percentage'] ?? 0;
                                if ($percentage >= 70) {
                                    $percentage_class = 'percentage-excellent';
                                } elseif ($percentage >= 40) {
                                    $percentage_class = 'percentage-good';
                                } else {
                                    $percentage_class = 'percentage-poor';
                                }
                                
                                // Calculate score display (e.g., 4/10)
                                $total_score = isset($result['total_score']) ? (int)$result['total_score'] : 0;
                                $max_score = 0;
                                
                                // Calculate max possible score
                                if (isset($result['objective_count']) && isset($result['theory_count'])) {
                                    $max_score = ((int)$result['objective_count'] + (int)$result['theory_count']);
                                } else {
                                    $max_score = 100; // Default fallback
                                }
                                
                                $score_display = $total_score . '/' . $max_score;

                                // Get grade letter
                                $grade = $result['grade'] ?? '';
                                $grade_display = '';
                                if ($grade) {
                                    if (strtoupper($grade[0]) == 'A') $grade_display = 'A';
                                    elseif (strtoupper($grade[0]) == 'B') $grade_display = 'B';
                                    elseif (strtoupper($grade[0]) == 'C') $grade_display = 'C';
                                    elseif (strtoupper($grade[0]) == 'F') $grade_display = 'F';
                                    else $grade_display = strtoupper(substr($grade, 0, 1));
                                }
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($result['student_name'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($result['admission_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($result['student_class'] ?? 'N/A'); ?></td>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($result['exam_name'] ?? 'N/A'); ?></div>
                                        <small style="color: #666;"><?php echo ucfirst($result['exam_type'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($result['subject_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <div class="score-display"><?php echo $score_display; ?></div>
                                        <small style="color: #666;">
                                            Obj: <?php echo $result['objective_score'] ?? 0; ?> |
                                            Thy: <?php echo $result['theory_score'] ?? 0; ?>
                                        </small>
                                    </td>
                                    <td class="percentage-cell <?php echo $percentage_class; ?>">
                                        <?php echo number_format($percentage, 2); ?>%
                                    </td>
                                    <td>
                                        <?php if ($grade_display): ?>
                                            <span class="grade-badge grade-<?php echo $grade_display; ?>">
                                                <?php echo $grade_display; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #999;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($result['time_taken']): ?>
                                            <?php echo floor($result['time_taken'] / 60); ?>m <?php echo $result['time_taken'] % 60; ?>s
                                        <?php else: ?>
                                            <span style="color: #999;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($result['submitted_at'])); ?><br>
                                        <small style="color: #666;"><?php echo date('h:i A', strtotime($result['submitted_at'])); ?></small>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn view" onclick="viewResultDetails(<?php echo $result['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <?php if ($admin_role === 'super_admin'): ?>
                                                <button class="action-btn delete" onclick="confirmDelete(<?php echo $result['id']; ?>, '<?php echo htmlspecialchars(addslashes($result['student_name'] ?? 'Unknown')); ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                                <i class="fas fa-angle-left"></i>
                            </a>
                        <?php else: ?>
                            <span class="page-link disabled"><i class="fas fa-angle-double-left"></i></span>
                            <span class="page-link disabled"><i class="fas fa-angle-left"></i></span>
                        <?php endif; ?>

                        <span class="page-info">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        </span>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="page-link">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="page-link disabled"><i class="fas fa-angle-right"></i></span>
                            <span class="page-link disabled"><i class="fas fa-angle-double-right"></i></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div style="text-align: center; padding: 40px 20px;">
                    <i class="fas fa-inbox" style="font-size: 60px; color: #ddd; margin-bottom: 20px;"></i>
                    <h3 style="color: #666; margin-bottom: 10px;">No Results Found</h3>
                    <p style="color: #888;">No exam results match your search criteria.</p>
                    <?php if (!empty($_GET)): ?>
                        <a href="view-results.php" class="btn btn-primary" style="margin-top: 20px;">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-exclamation-triangle" style="color: #e74c3c;"></i> Confirm Deletion</h3>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this result? This action cannot be undone.</p>
                <p><strong>Student:</strong> <span id="deleteStudentName"></span></p>
                <p><strong>Warning:</strong> This will permanently remove the result record.</p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="result_id" id="deleteResultId">
                    <button type="button" class="btn btn-warning" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="delete_result" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal" id="detailsModal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-info-circle"></i> Result Details</h3>
                <button type="button" onclick="closeDetailsModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #666;">&times;</button>
            </div>
            <div class="modal-body" id="detailsContent">
                <!-- Details will be loaded here via AJAX -->
                <div style="text-align: center; padding: 40px;">
                    <div class="spinner-border" role="status" style="color: var(--secondary-color);">
                        <span class="sr-only">Loading...</span>
                    </div>
                    <p style="margin-top: 20px;">Loading result details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-warning" onclick="closeDetailsModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');

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

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        // Delete confirmation
        function confirmDelete(resultId, studentName) {
            document.getElementById('deleteResultId').value = resultId;
            document.getElementById('deleteStudentName').textContent = studentName;
            openModal('deleteModal');
        }

        // View result details
        function viewResultDetails(resultId) {
            openModal('detailsModal');

            // Load result details via AJAX
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `get_result_details.php?id=${resultId}`, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    document.getElementById('detailsContent').innerHTML = xhr.responseText;
                } else {
                    document.getElementById('detailsContent').innerHTML = `
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            Error loading result details. Please try again.
                        </div>
                    `;
                }
            };
            xhr.onerror = function() {
                document.getElementById('detailsContent').innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        Network error. Please check your connection.
                    </div>
                `;
            };
            xhr.send();
        }

        // Refresh results
        function refreshResults() {
            window.location.reload();
        }

        // Set date inputs max to today
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const dateFrom = document.getElementById('date_from');
            const dateTo = document.getElementById('date_to');

            if (dateFrom) dateFrom.max = today;
            if (dateTo) dateTo.max = today;

            // Ensure date_to is not before date_from
            if (dateFrom) {
                dateFrom.addEventListener('change', function() {
                    if (dateTo) dateTo.min = this.value;
                });
            }

            if (dateTo) {
                dateTo.addEventListener('change', function() {
                    if (dateFrom) dateFrom.max = this.value;
                });
            }

            // Auto-refresh page every 2 minutes if on first page
            const urlParams = new URLSearchParams(window.location.search);
            const page = parseInt(urlParams.get('page')) || 1;

            if (page === 1 && !urlParams.get('student') && !urlParams.get('date_from')) {
                setTimeout(function() {
                    window.location.reload();
                }, 120000); // 2 minutes
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeDetailsModal();

                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Auto-submit form on some filter changes
        const classFilter = document.getElementById('class');
        if (classFilter) {
            classFilter.addEventListener('change', function() {
                if (this.value) {
                    this.form.submit();
                }
            });
        }

        // Print functionality
        function printResults() {
            window.print();
        }
    </script>
</body>

</html>