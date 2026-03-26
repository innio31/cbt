<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit();
}

// List students with links to generate their report cards
$students = $pdo->query("SELECT * FROM students WHERE status = 'active' ORDER BY class, full_name")->fetchAll();

// Get unique classes for filter
$classes = $pdo->query("SELECT DISTINCT class FROM students WHERE status = 'active' ORDER BY class")->fetchAll();

// Get current session and term
$current_session = date('Y') . '/' . (date('Y') + 1);
$current_term = 'First';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Report Cards - <?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School Management System'; ?></title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
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
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: linear-gradient(180deg, var(--primary-color), var(--dark-color));
            color: white;
            padding: 20px 0;
            z-index: 100;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
            overflow-y: auto;
        }

        .sidebar-content {
            padding: 0 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 30px;
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

        .nav-links {
            list-style: none;
            margin-bottom: 30px;
        }

        .nav-links li {
            margin-bottom: 5px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            border-radius: 8px;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--secondary-color);
        }

        .nav-links i {
            width: 20px;
            text-align: center;
        }

        .main-content {
            margin-left: 260px;
            padding: 20px;
            min-height: 100vh;
        }

        .top-header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .header-title h1 {
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-title p {
            color: #666;
            font-size: 0.95rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .dashboard-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .dashboard-card h2 {
            color: var(--primary-color);
            margin-bottom: 25px;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 250px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .select-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23333' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            padding-right: 40px;
        }

        .class-filter {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 4px solid var(--secondary-color);
        }

        .class-filter label {
            font-weight: 500;
            margin-right: 10px;
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .students-table th,
        .students-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .students-table th {
            background: var(--primary-color);
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .students-table tbody tr {
            transition: all 0.3s ease;
        }

        .students-table tbody tr:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }

        .btn {
            background: linear-gradient(135deg, var(--secondary-color), #2980b9);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-sm {
            padding: 8px 15px;
            font-size: 0.85rem;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
            margin-top: 30px;
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .back-link:hover {
            background: rgba(52, 152, 219, 0.1);
            transform: translateX(-5px);
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            display: none;
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

        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
                overflow: hidden;
            }

            .sidebar:hover {
                width: 260px;
            }

            .logo-text,
            .nav-links span {
                display: none;
            }

            .sidebar:hover .logo-text,
            .sidebar:hover .nav-links span {
                display: block;
            }

            .main-content {
                margin-left: 70px;
            }

            .sidebar:hover~.main-content {
                margin-left: 260px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .form-row {
                flex-direction: column;
            }

            .form-group {
                min-width: 100%;
            }

            .students-table {
                font-size: 0.9rem;
            }

            .students-table th,
            .students-table td {
                padding: 10px;
            }

            .mobile-menu-btn {
                display: block;
            }
        }

        @media (max-width: 576px) {
            .students-table {
                display: block;
                overflow-x: auto;
            }
        }

        .no-students {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-students i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #ddd;
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
        <div class="sidebar-content">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="logo-text">
                    <h3><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School Management System'; ?></h3>
                    <p>Admin Panel</p>
                </div>
            </div>

            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="manage-students.php"><i class="fas fa-users"></i> Manage Students</a></li>
                <li><a href="manage-staff.php"><i class="fas fa-chalkboard-teacher"></i> Manage Staff</a></li>
                <li><a href="manage-subjects.php"><i class="fas fa-book"></i> Manage Subjects</a></li>
                <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
                <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>
                <li><a href="report_card_dashboard.php"><i class="fas fa-file-contract"></i> Report Cards</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-file-pdf"></i> Generate Report Cards</h1>
                <p>Generate individual report cards for students</p>
            </div>
        </div>

        <div class="container">
            <!-- Report Card Settings -->
            <div class="dashboard-card">
                <h2><i class="fas fa-cog"></i> Report Card Settings</h2>

                <div class="form-row">
                    <div class="form-group">
                        <label for="session">Academic Session</label>
                        <input type="text" id="session" class="form-control"
                            value="<?php echo $current_session; ?>">
                    </div>

                    <div class="form-group">
                        <label for="term">Term</label>
                        <select id="term" class="form-control select-control">
                            <option value="First" <?php echo $current_term == 'First' ? 'selected' : ''; ?>>First Term</option>
                            <option value="Second" <?php echo $current_term == 'Second' ? 'selected' : ''; ?>>Second Term</option>
                            <option value="Third" <?php echo $current_term == 'Third' ? 'selected' : ''; ?>>Third Term</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Class Filter -->
            <div class="class-filter">
                <label for="classFilter">Filter by Class:</label>
                <select id="classFilter" class="form-control select-control" style="max-width: 300px; display: inline-block;" onchange="filterStudents()">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= htmlspecialchars($class['class']) ?>">
                            <?= htmlspecialchars($class['class']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span id="studentCount" style="margin-left: 15px; color: #666; font-weight: 500;">
                    <?= count($students) ?> student(s) found
                </span>
            </div>

            <!-- Students List -->
            <div class="dashboard-card">
                <h2><i class="fas fa-users"></i> Student List</h2>

                <?php if (empty($students)): ?>
                    <div class="no-students">
                        <i class="fas fa-user-graduate"></i>
                        <h3>No Active Students Found</h3>
                        <p>There are currently no active students in the system.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th>S/N</th>
                                    <th>Student Name</th>
                                    <th>Class</th>
                                    <th>Admission Number</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="studentsTableBody">
                                <?php foreach ($students as $index => $student): ?>
                                    <tr data-class="<?= htmlspecialchars($student['class']) ?>">
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($student['full_name']) ?></td>
                                        <td>
                                            <span class="class-badge" style="background: #e3f2fd; color: #1976d2; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">
                                                <?= htmlspecialchars($student['class']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <code style="background: #f5f5f5; padding: 3px 6px; border-radius: 3px;">
                                                <?= htmlspecialchars($student['admission_number'] ?? 'N/A') ?>
                                            </code>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm" onclick="generateReportCard(<?= $student['id'] ?>)">
                                                <i class="fas fa-file-pdf"></i> Generate
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <a href="report_card_dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Report Card Dashboard
            </a>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
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

        function generateReportCard(studentId) {
            const session = document.getElementById('session').value;
            const term = document.getElementById('term').value;

            if (!studentId) {
                alert('Student ID is missing!');
                return;
            }

            // Open in new tab - this should call generate_report_card.php
            window.open(`generate_report_card.php?student_id=${studentId}&session=${session}&term=${term}`, '_blank');
        }

        function filterStudents() {
            const classFilter = document.getElementById('classFilter').value;
            const rows = document.querySelectorAll('#studentsTableBody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                if (!classFilter || row.getAttribute('data-class') === classFilter) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Update student count
            document.getElementById('studentCount').textContent = visibleCount + ' student(s) found';

            // Update serial numbers
            const visibleRows = document.querySelectorAll('#studentsTableBody tr:not([style*="display: none"])');
            visibleRows.forEach((row, index) => {
                row.querySelector('td:first-child').textContent = index + 1;
            });
        }

        // Initialize row highlighting and count
        document.addEventListener('DOMContentLoaded', function() {
            // Add row highlighting
            const rows = document.querySelectorAll('.students-table tbody tr');
            rows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = '#f0f7ff';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                });
            });

            // Focus session field when page loads
            const sessionField = document.getElementById('session');
            if (sessionField) {
                sessionField.addEventListener('focus', function() {
                    this.select();
                });
            }
        });
    </script>
</body>

</html>