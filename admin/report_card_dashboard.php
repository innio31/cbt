<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isAdminLoggedIn()) {
    header("Location: login.php");
    exit();
}

// Get current session and term
$current_session = date('Y') . '/' . (date('Y') + 1);
$current_term = 'First';

// Check if settings exist for any class
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM report_card_settings WHERE session = ? AND term = ?");
$stmt->execute([$current_session, $current_term]);
$settings_count = $stmt->fetch()['count'];
$settings_exist = $settings_count > 0;

// Get progress stats
$students_with_scores = $pdo->query("SELECT COUNT(DISTINCT student_id) as count FROM student_scores WHERE session = '$current_session' AND term = '$current_term'")->fetch()['count'];
$total_students = $pdo->query("SELECT COUNT(*) as count FROM students WHERE status = 'active'")->fetch()['count'];
$completion_percentage = $total_students > 0 ? round(($students_with_scores / $total_students) * 100) : 0;

// Get students with comments
$students_with_comments = $pdo->query("SELECT COUNT(DISTINCT student_id) as count FROM student_comments WHERE session = '$current_session' AND term = '$current_term'")->fetch()['count'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Card Dashboard - <?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'School Management System'; ?></title>

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
            text-align: center;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .workflow-steps {
            display: flex;
            justify-content: space-between;
            margin: 30px 0;
            counter-reset: step;
            flex-wrap: wrap;
            gap: 20px;
        }

        .step {
            flex: 1;
            min-width: 200px;
            background: white;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            position: relative;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .step:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
            border-color: var(--secondary-color);
        }

        .step:before {
            counter-increment: step;
            content: counter(step);
            background: #e0e0e0;
            color: #666;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-weight: bold;
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }

        .step.completed {
            background: #f0f9ff;
            border-color: #b3e0ff;
        }

        .step.completed:before {
            background: var(--success-color);
            color: white;
        }

        .step.current {
            background: #fff8e1;
            border-color: #ffd54f;
        }

        .step.current:before {
            background: var(--warning-color);
            color: #000;
        }

        .step h3 {
            color: var(--primary-color);
            margin-bottom: 10px;
            font-size: 1.2rem;
        }

        .step p {
            color: #666;
            margin-bottom: 20px;
            font-size: 0.9rem;
            line-height: 1.5;
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

        .progress-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .progress-section h2 {
            color: var(--primary-color);
            margin-bottom: 25px;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .progress-overview {
            margin-bottom: 25px;
        }

        .progress-text {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            color: #555;
            font-weight: 500;
        }

        .progress-bar {
            background: #e0e0e0;
            border-radius: 10px;
            height: 15px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .progress-fill {
            background: linear-gradient(90deg, var(--success-color), #2ecc71);
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s ease;
            position: relative;
        }

        .progress-fill:after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background-image: linear-gradient(-45deg,
                    rgba(255, 255, 255, 0.2) 25%,
                    transparent 25%,
                    transparent 50%,
                    rgba(255, 255, 255, 0.2) 50%,
                    rgba(255, 255, 255, 0.2) 75%,
                    transparent 75%,
                    transparent);
            background-size: 30px 30px;
            animation: move 1.5s linear infinite;
        }

        @keyframes move {
            0% {
                background-position: 0 0;
            }

            100% {
                background-position: 30px 30px;
            }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .stat-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
            border-left: 4px solid var(--secondary-color);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .stat-label {
            color: #666;
            font-size: 1rem;
            font-weight: 500;
        }

        .stat-card.success .stat-number {
            color: var(--success-color);
        }

        .stat-card.warning .stat-number {
            color: var(--warning-color);
        }

        .stat-card.danger .stat-number {
            color: var(--danger-color);
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .action-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            text-decoration: none;
            color: var(--primary-color);
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
            border: 2px solid transparent;
        }

        .action-card:hover {
            background: linear-gradient(135deg, var(--secondary-color), #2980b9);
            color: white;
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(52, 152, 219, 0.3);
            border-color: var(--secondary-color);
        }

        .action-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            display: block;
        }

        .action-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .action-desc {
            font-size: 0.9rem;
            opacity: 0.8;
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

            .workflow-steps {
                flex-direction: column;
            }

            .step {
                min-width: 100%;
            }

            .stats-grid,
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }

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

        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
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
                <li><a href="report_card_dashboard.php" class="active"><i class="fas fa-file-contract"></i> Report Cards</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="top-header">
            <div class="header-title">
                <h1><i class="fas fa-file-contract"></i> Report Card Dashboard</h1>
                <p>Complete the workflow to generate student report cards for <?php echo $current_session; ?> - <?php echo $current_term; ?> Term</p>
            </div>
        </div>

        <div class="container">
            <!-- Workflow Progress -->
            <div class="dashboard-card">
                <h2><i class="fas fa-project-diagram"></i> Report Card Workflow</h2>
                <p style="text-align: center; color: #666; margin-bottom: 20px;">Follow these steps to complete the report card process</p>

                <div class="workflow-steps">
                    <div class="step <?= $settings_exist ? 'completed' : 'current' ?>">
                        <h3>1. Settings</h3>
                        <p>Configure grading system and score types</p>
                        <a href="report_card_settings.php" class="btn">
                            <i class="fas fa-cogs"></i> <?= $settings_exist ? 'Update Settings' : 'Configure' ?>
                        </a>
                    </div>
                    <div class="step <?= $students_with_scores > 0 ? 'completed' : '' ?>">
                        <h3>2. Enter Scores</h3>
                        <p>Input student scores and grades</p>
                        <a href="enter_scores.php" class="btn">
                            <i class="fas fa-edit"></i> Enter Scores
                        </a>
                    </div>
                    <div class="step <?= $students_with_comments > 0 ? 'completed' : '' ?>">
                        <h3>3. Comments & Traits</h3>
                        <p>Add comments and behavioral ratings</p>
                        <a href="enter_comments.php" class="btn">
                            <i class="fas fa-comment"></i> Add Comments
                        </a>
                    </div>
                    <div class="step">
                        <h3>4. Calculate Positions</h3>
                        <p>Compute class rankings</p>
                        <a href="calculate_positions.php" class="btn">
                            <i class="fas fa-calculator"></i> Calculate
                        </a>
                    </div>
                    <div class="step">
                        <h3>5. Generate Reports</h3>
                        <p>Produce final report cards</p>
                        <a href="report_cards.php" class="btn">
                            <i class="fas fa-file-pdf"></i> Generate
                        </a>
                    </div>
                </div>
            </div>

            <!-- Progress Overview -->
            <div class="progress-section">
                <h2><i class="fas fa-chart-line"></i> Overall Progress</h2>

                <div class="progress-overview">
                    <div class="progress-text">
                        <span>Report Card Completion</span>
                        <span><?= $completion_percentage ?>%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $completion_percentage ?>%"></div>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?= $total_students ?></div>
                        <div class="stat-label">Total Active Students</div>
                    </div>
                    <div class="stat-card <?= $students_with_scores > 0 ? 'success' : 'warning' ?>">
                        <div class="stat-number"><?= $students_with_scores ?></div>
                        <div class="stat-label">Students with Scores</div>
                    </div>
                    <div class="stat-card <?= $students_with_comments > 0 ? 'success' : 'warning' ?>">
                        <div class="stat-number"><?= $students_with_comments ?></div>
                        <div class="stat-label">Students with Comments</div>
                    </div>
                    <div class="stat-card <?= $settings_exist ? 'success' : 'danger' ?>">
                        <div class="stat-number"><?= $settings_exist ? '✓' : '✗' ?></div>
                        <div class="stat-label">Settings Configured</div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="dashboard-card">
                <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                <div class="quick-actions">
                    <a href="report_card_settings.php" class="action-card">
                        <span class="action-icon">⚙️</span>
                        <div class="action-title">Report Card Settings</div>
                        <div class="action-desc">Configure grading and display options</div>
                    </a>
                    <a href="enter_scores.php" class="action-card">
                        <span class="action-icon">📝</span>
                        <div class="action-title">Enter Scores</div>
                        <div class="action-desc">Input student academic scores</div>
                    </a>
                    <a href="enter_comments.php" class="action-card">
                        <span class="action-icon">💬</span>
                        <div class="action-title">Enter Comments & Traits</div>
                        <div class="action-desc">Add behavioral comments and ratings</div>
                    </a>
                    <a href="calculate_positions.php" class="action-card">
                        <span class="action-icon">📊</span>
                        <div class="action-title">Calculate Positions</div>
                        <div class="action-desc">Compute class rankings</div>
                    </a>
                    <a href="report_cards.php" class="action-card">
                        <span class="action-icon">👨‍🎓</span>
                        <div class="action-title">Generate Report Cards</div>
                        <div class="action-desc">Produce final report cards</div>
                    </a>
                </div>
            </div>

            <a href="index.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Main Dashboard
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

        // Add animation to progress bar
        document.addEventListener('DOMContentLoaded', function() {
            const progressFill = document.querySelector('.progress-fill');
            const computedStyle = getComputedStyle(progressFill);
            const width = parseFloat(computedStyle.width);

            // Reset and animate
            progressFill.style.width = '0';
            setTimeout(() => {
                progressFill.style.width = width + 'px';
            }, 300);
        });
    </script>
</body>

</html>