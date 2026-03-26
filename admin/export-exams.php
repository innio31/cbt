<?php
// admin/export-exams.php - Export Exams to CSV/PDF
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../includes/config.php';

// Get export parameters
$format = isset($_GET['format']) ? strtolower($_GET['format']) : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$class_filter = isset($_GET['class']) ? $_GET['class'] : '';
$subject_filter = isset($_GET['subject']) ? $_GET['subject'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

// Function to fetch exams data with filters
function getExamsData($pdo, $search, $class_filter, $subject_filter, $status_filter, $type_filter)
{
    $query = "
        SELECT e.*, 
               s.subject_name, 
               sg.group_name,
               COUNT(DISTINCT oq.id) as objective_questions_count,
               COUNT(DISTINCT sq.id) as subjective_questions_count,
               COUNT(DISTINCT tq.id) as theory_questions_count,
               COUNT(DISTINCT es.id) as exam_sessions_count
        FROM exams e
        LEFT JOIN subjects s ON e.subject_id = s.id
        LEFT JOIN subject_groups sg ON e.group_id = sg.id
        LEFT JOIN objective_questions oq ON e.subject_id = oq.subject_id 
            AND (e.class = oq.class OR oq.class IS NULL OR oq.class = '')
        LEFT JOIN subjective_questions sq ON e.subject_id = sq.subject_id 
            AND (e.class = sq.class OR sq.class IS NULL OR sq.class = '')
        LEFT JOIN theory_questions tq ON e.subject_id = tq.subject_id 
            AND (e.class = tq.class OR tq.class IS NULL OR tq.class = '')
        LEFT JOIN exam_sessions es ON e.id = es.exam_id
        WHERE 1=1
    ";

    $params = [];

    if (!empty($search)) {
        $query .= " AND (e.exam_name LIKE ? OR e.class LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($class_filter)) {
        $query .= " AND e.class = ?";
        $params[] = $class_filter;
    }

    if (!empty($subject_filter)) {
        $query .= " AND e.subject_id = ?";
        $params[] = $subject_filter;
    }

    if ($status_filter === 'active') {
        $query .= " AND e.is_active = 1";
    } elseif ($status_filter === 'inactive') {
        $query .= " AND e.is_active = 0";
    }

    if (!empty($type_filter)) {
        $query .= " AND e.exam_type = ?";
        $params[] = $type_filter;
    }

    $query .= " GROUP BY e.id ORDER BY e.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Function to get exam type display name
function getExamTypeDisplay($type)
{
    $types = [
        'objective' => 'Objective Only',
        'subjective' => 'Subjective Only',
        'theory' => 'Theory Only'
    ];
    return $types[$type] ?? ucfirst($type);
}

// If no format specified, show the export selection page
if (empty($format) || ($format !== 'csv' && $format !== 'pdf')) {
    // Fetch filter options for the selection page
    try {
        $subjects = $pdo->query("SELECT id, subject_name FROM subjects ORDER BY subject_name")->fetchAll();
        $classes = $pdo->query("SELECT DISTINCT class FROM exams WHERE class IS NOT NULL AND class != '' ORDER BY class")->fetchAll();

        $exam_types = [
            'objective' => 'Objective Only',
            'subjective' => 'Subjective Only',
            'theory' => 'Theory Only'
        ];
    } catch (Exception $e) {
        error_log("Fetch filter options error: " . $e->getMessage());
    }
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Export Exams - Digital CBT System</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            :root {
                --primary-color: #2c3e50;
                --secondary-color: #3498db;
                --success-color: #27ae60;
                --danger-color: #e74c3c;
                --warning-color: #f39c12;
            }

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Poppins', sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 40px 20px;
            }

            .container {
                max-width: 800px;
                margin: 0 auto;
            }

            .card {
                background: white;
                border-radius: 20px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
                overflow: hidden;
            }

            .card-header {
                background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
                color: white;
                padding: 30px;
                text-align: center;
            }

            .card-header h1 {
                font-size: 1.8rem;
                margin-bottom: 10px;
            }

            .card-header p {
                opacity: 0.9;
                font-size: 0.95rem;
            }

            .card-body {
                padding: 30px;
            }

            .export-options {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-bottom: 30px;
            }

            .export-option {
                text-align: center;
                padding: 30px 20px;
                border: 2px solid #e0e0e0;
                border-radius: 15px;
                cursor: pointer;
                transition: all 0.3s ease;
                text-decoration: none;
                display: block;
            }

            .export-option:hover {
                transform: translateY(-5px);
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            }

            .export-option.csv:hover {
                border-color: var(--success-color);
                background: #f0f9f0;
            }

            .export-option.pdf:hover {
                border-color: var(--danger-color);
                background: #fff5f5;
            }

            .export-option i {
                font-size: 48px;
                margin-bottom: 15px;
            }

            .export-option.csv i {
                color: var(--success-color);
            }

            .export-option.pdf i {
                color: var(--danger-color);
            }

            .export-option h3 {
                margin-bottom: 10px;
                color: #333;
            }

            .export-option p {
                color: #666;
                font-size: 0.85rem;
            }

            .filter-section {
                border-top: 1px solid #e0e0e0;
                padding-top: 25px;
                margin-top: 10px;
            }

            .filter-section h3 {
                margin-bottom: 20px;
                color: var(--primary-color);
                font-size: 1.2rem;
            }

            .filter-form {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }

            .form-group {
                display: flex;
                flex-direction: column;
            }

            .form-group label {
                margin-bottom: 5px;
                font-weight: 500;
                color: #555;
                font-size: 0.85rem;
            }

            .form-control {
                padding: 10px 12px;
                border: 2px solid #e0e0e0;
                border-radius: 8px;
                font-family: 'Poppins', sans-serif;
                font-size: 0.9rem;
                transition: border-color 0.3s ease;
            }

            .form-control:focus {
                outline: none;
                border-color: var(--secondary-color);
            }

            .btn-back {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 12px 24px;
                background: #f5f5f5;
                color: #666;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 500;
                transition: all 0.3s ease;
                margin-top: 20px;
            }

            .btn-back:hover {
                background: #e0e0e0;
            }

            @media (max-width: 600px) {
                .export-options {
                    grid-template-columns: 1fr;
                }

                .card-body {
                    padding: 20px;
                }

                .filter-form {
                    grid-template-columns: 1fr;
                }
            }
        </style>
    </head>

    <body>
        <div class="container">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-download" style="font-size: 48px; margin-bottom: 15px;"></i>
                    <h1>Export Exams Data</h1>
                    <p>Choose your preferred export format and apply filters</p>
                </div>
                <div class="card-body">
                    <div class="export-options">
                        <button onclick="exportWithFilters('csv')" class="export-option csv" style="background: none; width: 100%; cursor: pointer;">
                            <i class="fas fa-file-excel"></i>
                            <h3>Export to CSV/Excel</h3>
                            <p>Download as CSV file (compatible with Excel)</p>
                        </button>
                        <button onclick="exportWithFilters('pdf')" class="export-option pdf" style="background: none; width: 100%; cursor: pointer;">
                            <i class="fas fa-file-pdf"></i>
                            <h3>Export to PDF</h3>
                            <p>Download as PDF document (.pdf)</p>
                        </button>
                    </div>

                    <div class="filter-section">
                        <h3><i class="fas fa-filter"></i> Apply Filters (Optional)</h3>
                        <form method="GET" class="filter-form" id="filterForm">
                            <div class="form-group">
                                <label for="search"><i class="fas fa-search"></i> Search</label>
                                <input type="text" id="search" name="search" class="form-control"
                                    placeholder="Exam name or class..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>

                            <div class="form-group">
                                <label for="class"><i class="fas fa-school"></i> Class</label>
                                <select id="class" name="class" class="form-control">
                                    <option value="">All Classes</option>
                                    <?php if (!empty($classes)): ?>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo htmlspecialchars($class['class']); ?>"
                                                <?php echo $class_filter === $class['class'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['class']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="subject"><i class="fas fa-book"></i> Subject</label>
                                <select id="subject" name="subject" class="form-control">
                                    <option value="">All Subjects</option>
                                    <?php if (!empty($subjects)): ?>
                                        <?php foreach ($subjects as $subject): ?>
                                            <option value="<?php echo $subject['id']; ?>"
                                                <?php echo $subject_filter == $subject['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="status"><i class="fas fa-toggle-on"></i> Status</label>
                                <select id="status" name="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="type"><i class="fas fa-file-alt"></i> Exam Type</label>
                                <select id="type" name="type" class="form-control">
                                    <option value="">All Types</option>
                                    <?php foreach ($exam_types as $key => $value): ?>
                                        <option value="<?php echo $key; ?>"
                                            <?php echo $type_filter === $key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($value); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    </div>

                    <a href="manage-exams.php" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Manage Exams
                    </a>
                </div>
            </div>
        </div>

        <script>
            function exportWithFilters(format) {
                const search = document.getElementById('search').value;
                const classVal = document.getElementById('class').value;
                const subject = document.getElementById('subject').value;
                const status = document.getElementById('status').value;
                const type = document.getElementById('type').value;

                let url = `?format=${format}`;
                if (search) url += `&search=${encodeURIComponent(search)}`;
                if (classVal) url += `&class=${encodeURIComponent(classVal)}`;
                if (subject) url += `&subject=${encodeURIComponent(subject)}`;
                if (status) url += `&status=${encodeURIComponent(status)}`;
                if (type) url += `&type=${encodeURIComponent(type)}`;

                window.location.href = url;
            }
        </script>
    </body>

    </html>
    <?php
    exit();
}

// ============================================
// EXPORT TO CSV (No external libraries required)
// ============================================
if ($format === 'csv') {
    try {
        // Fetch exams data
        $exams = getExamsData($pdo, $search, $class_filter, $subject_filter, $status_filter, $type_filter);

        if (empty($exams)) {
            $_SESSION['export_error'] = "No exams found matching your filters.";
            header("Location: manage-exams.php");
            exit();
        }

        // Set headers for CSV download
        $filename = 'exams_export_' . date('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: public');
        header('Cache-Control: max-age=0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add UTF-8 BOM for Excel compatibility (handles special characters)
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Headers
        $headers = [
            'ID',
            'Exam Name',
            'Class',
            'Subject',
            'Exam Type',
            'Duration (mins)',
            'Objective Qty',
            'Subjective Qty',
            'Theory Qty',
            'Total Questions',
            'Sessions',
            'Status',
            'Group',
            'Instructions',
            'Created Date'
        ];
        fputcsv($output, $headers);

        // Data rows
        foreach ($exams as $exam) {
            $total_questions = ($exam['objective_count'] ?? 0) + ($exam['subjective_count'] ?? 0) + ($exam['theory_count'] ?? 0);

            $row = [
                $exam['id'],
                $exam['exam_name'],
                $exam['class'],
                $exam['subject_name'] ?? 'N/A',
                getExamTypeDisplay($exam['exam_type']),
                $exam['duration_minutes'],
                $exam['objective_count'] ?? 0,
                $exam['subjective_count'] ?? 0,
                $exam['theory_count'] ?? 0,
                $total_questions,
                $exam['exam_sessions_count'] ?? 0,
                $exam['is_active'] ? 'Active' : 'Inactive',
                $exam['group_name'] ?? 'N/A',
                strip_tags($exam['instructions'] ?? ''),
                date('Y-m-d H:i:s', strtotime($exam['created_at']))
            ];

            fputcsv($output, $row);
        }

        fclose($output);
        exit();
    } catch (Exception $e) {
        error_log("CSV export error: " . $e->getMessage());
        $_SESSION['export_error'] = "Error exporting to CSV: " . $e->getMessage();
        header("Location: manage-exams.php");
        exit();
    }
}

// ============================================
// EXPORT TO PDF (No external libraries - uses browser print)
// ============================================
if ($format === 'pdf') {
    try {
        // Fetch exams data
        $exams = getExamsData($pdo, $search, $class_filter, $subject_filter, $status_filter, $type_filter);

        if (empty($exams)) {
            $_SESSION['export_error'] = "No exams found matching your filters.";
            header("Location: manage-exams.php");
            exit();
        }

        // Calculate summary statistics
        $active_count = count(array_filter($exams, function ($e) {
            return $e['is_active'] == 1;
        }));
        $total_sessions = array_sum(array_column($exams, 'exam_sessions_count'));
        $total_questions_sum = 0;
        foreach ($exams as $exam) {
            $total_questions_sum += ($exam['objective_count'] ?? 0) + ($exam['subjective_count'] ?? 0) + ($exam['theory_count'] ?? 0);
        }

    ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <title>Exams Export Report</title>
            <style>
                @media print {
                    body {
                        margin: 0;
                        padding: 20px;
                    }

                    .page-break {
                        page-break-after: always;
                    }

                    button {
                        display: none;
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

                .type-badge {
                    padding: 3px 8px;
                    border-radius: 12px;
                    font-size: 10px;
                    font-weight: bold;
                    display: inline-block;
                }

                .type-objective {
                    background: #e3f2fd;
                    color: #1976d2;
                }

                .type-subjective {
                    background: #f3e5f5;
                    color: #7b1fa2;
                }

                .type-theory {
                    background: #e8f5e8;
                    color: #388e3c;
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
                <button onclick="window.print()"><i class="fas fa-print"></i> Save as PDF / Print</button>
                <button onclick="window.close()">Close</button>
            </div>

            <div class="report-header">
                <h1>📋 Exams Management Report</h1>
                <p>Generated on: <?php echo date('F j, Y, g:i a'); ?></p>
                <p>Digital CBT System - The Climax Brains Academy</p>
            </div>

            <?php if (!empty($search) || !empty($class_filter) || !empty($subject_filter) || !empty($status_filter) || !empty($type_filter)): ?>
                <div class="filter-info">
                    <strong>📌 Applied Filters:</strong><br>
                    <?php if (!empty($search)): ?>• Search: "<?php echo htmlspecialchars($search); ?>"<br><?php endif; ?>
                <?php if (!empty($class_filter)): ?>• Class: <?php echo htmlspecialchars($class_filter); ?><br><?php endif; ?>
            <?php if (!empty($subject_filter)): ?>• Subject: <?php
                                                                $subject_name = '';
                                                                $stmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
                                                                $stmt->execute([$subject_filter]);
                                                                $subj = $stmt->fetch();
                                                                echo htmlspecialchars($subj['subject_name'] ?? $subject_filter);
                                                                ?><br><?php endif; ?>
        <?php if (!empty($status_filter)): ?>• Status: <?php echo $status_filter === 'active' ? 'Active' : 'Inactive'; ?><br><?php endif; ?>
    <?php if (!empty($type_filter)): ?>• Exam Type: <?php echo getExamTypeDisplay($type_filter); ?><br><?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="summary">
                <h3>📊 Summary Statistics</h3>
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="label">Total Exams</div>
                        <div class="value"><?php echo count($exams); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Active Exams</div>
                        <div class="value"><?php echo $active_count; ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Inactive Exams</div>
                        <div class="value"><?php echo count($exams) - $active_count; ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Total Sessions</div>
                        <div class="value"><?php echo $total_sessions; ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Total Questions</div>
                        <div class="value"><?php echo $total_questions_sum; ?></div>
                    </div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Exam Name</th>
                        <th>Class</th>
                        <th>Subject</th>
                        <th>Type</th>
                        <th>Duration</th>
                        <th>Questions</th>
                        <th>Sessions</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($exams as $exam):
                        $type_class = 'type-' . $exam['exam_type'];
                        $total_q = ($exam['objective_count'] ?? 0) + ($exam['subjective_count'] ?? 0) + ($exam['theory_count'] ?? 0);
                    ?>
                        <tr>
                            <td><?php echo $exam['id']; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($exam['exam_name']); ?></strong>
                                <?php if (!empty($exam['group_name'])): ?>
                                    <br><small style="color: #666;">📁 <?php echo htmlspecialchars($exam['group_name']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($exam['class']); ?></td>
                            <td><?php echo htmlspecialchars($exam['subject_name'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="type-badge <?php echo $type_class; ?>">
                                    <?php echo getExamTypeDisplay($exam['exam_type']); ?>
                                </span>
                            </td>
                            <td><?php echo $exam['duration_minutes']; ?> min</td>
                            <td>
                                <small>
                                    <?php if ($exam['objective_count'] > 0): ?>📝 O:<?php echo $exam['objective_count']; ?><?php endif; ?>
                                    <?php if ($exam['subjective_count'] > 0): ?> ✍️ S:<?php echo $exam['subjective_count']; ?><?php endif; ?>
                                        <?php if ($exam['theory_count'] > 0): ?> 📖 T:<?php echo $exam['theory_count']; ?><?php endif; ?>
                                            <?php if ($total_q == 0): ?>-<?php endif; ?>
                                </small>
                            </td>
                            <td><?php echo $exam['exam_sessions_count'] ?? 0; ?></td>
                            <td>
                                <span class="status-<?php echo $exam['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $exam['is_active'] ? '✓ Active' : '✗ Inactive'; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="footer">
                <p>This report was generated automatically by the Digital CBT System. &copy; <?php echo date('Y'); ?> All rights reserved.</p>
                <p>Report generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>

            <script>
                // Auto-trigger print dialog after a short delay
                setTimeout(function() {
                    // Check if this is being viewed in a browser (not already printing)
                    if (!window.matchMedia('print').matches) {
                        // Optional: auto-trigger print - uncomment if desired
                        // window.print();
                    }
                }, 500);
            </script>
        </body>

        </html>
<?php
        exit();
    } catch (Exception $e) {
        error_log("PDF export error: " . $e->getMessage());
        $_SESSION['export_error'] = "Error exporting to PDF: " . $e->getMessage();
        header("Location: manage-exams.php");
        exit();
    }
}
?>