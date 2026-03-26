<?php
// login.php - Main Login Page
session_start();

// Set base directory
define('BASE_DIR', dirname(__FILE__));

// Include config and auth
require_once BASE_DIR . '/includes/config.php';
require_once BASE_DIR . '/includes/auth.php';

// Check if user is already logged in by checking specific session variables
$is_logged_in = false;
$user_type = '';

// Check based on session variables (not just logged_in flag)
if (isset($_SESSION['student_id'])) {
    $is_logged_in = true;
    $user_type = 'student';
} elseif (isset($_SESSION['staff_id'])) {
    $is_logged_in = true;
    $user_type = 'staff';
} elseif (isset($_SESSION['admin_id'])) {
    $is_logged_in = true;
    $user_type = 'admin';
}

// If user is already logged in, redirect to appropriate dashboard
if ($is_logged_in) {
    header('Location: ' . determineDashboard($user_type));
    exit();
}

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $userType = $_POST['user_type'] ?? 'student';

    // Basic validation
    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields';
    } else {
        $authenticated = false;

        switch ($userType) {
            case 'student':
                if (studentLogin($pdo, $username, $password)) {
                    $_SESSION['user_type'] = 'student';
                    $_SESSION['logged_in'] = true;
                    $authenticated = true;
                    $user_type = 'student';
                } else {
                    $error = 'Invalid admission number or password';
                }
                break;

            case 'staff':
                if (staffLogin($pdo, $username, $password)) {
                    $_SESSION['user_type'] = 'staff';
                    $_SESSION['logged_in'] = true;
                    $authenticated = true;
                    $user_type = 'staff';
                } else {
                    $error = 'Invalid staff ID or password';
                }
                break;

            case 'admin':
                if (adminLogin($pdo, $username, $password)) {
                    $_SESSION['user_type'] = 'admin';
                    $_SESSION['logged_in'] = true;
                    $authenticated = true;
                    $user_type = 'admin';
                } else {
                    $error = 'Invalid admin credentials';
                }
                break;
        }

        if ($authenticated) {
            // Redirect to appropriate dashboard
            header('Location: ' . determineDashboard($user_type));
            exit();
        }
    }
}

// Function to determine dashboard based on user type
function determineDashboard($user_type)
{
    switch ($user_type) {
        case 'student':
            return 'student/index.php';
        case 'staff':
            return 'staff/index.php';
        case 'admin':
            return 'admin/index.php';
        default:
            return 'index.php';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login - Digital CBT System</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* CSS Variables for easy customization */
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #4CAF50;
            --error-color: #f44336;
            --text-color: #333;
            --text-light: #666;
            --bg-light: #f8f9fa;
            --border-color: #e1e5e9;
            --white: #ffffff;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --radius: 12px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            min-height: 100vh;
            min-height: -webkit-fill-available;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 16px;
            position: relative;
            font-size: 16px;
            line-height: 1.5;
        }

        /* Back Home Button */
        .back-home {
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 100;
        }

        .back-home a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--white);
            text-decoration: none;
            padding: 10px 16px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 50px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: var(--transition);
            font-size: 0.9rem;
            font-weight: 500;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .back-home a:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
        }

        /* Main Container */
        .login-container {
            width: 100%;
            max-width: 480px;
            background: rgba(255, 255, 255, 0.98);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            margin: 60px auto 20px;
            animation: fadeIn 0.5s ease;
        }

        /* Logo Section */
        .logo-section {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.9) 0%, rgba(118, 75, 162, 0.9) 100%);
            color: var(--white);
            padding: 30px 24px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .logo-icon {
            width: 70px;
            height: 70px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 30px;
            backdrop-filter: blur(5px);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .logo-section h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            line-height: 1.2;
        }

        .logo-section p {
            font-size: 0.95rem;
            opacity: 0.9;
        }

        /* Features Grid */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin: 24px 0;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 16px;
            text-align: center;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: var(--transition);
        }

        .feature-card:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, 0.15);
        }

        .feature-icon {
            width: 40px;
            height: 40px;
            background: var(--white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            color: var(--primary-color);
            font-size: 18px;
        }

        .feature-card h4 {
            font-size: 0.9rem;
            margin-bottom: 4px;
            font-weight: 600;
        }

        .feature-card p {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        /* Form Section */
        .form-section {
            padding: 30px 24px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h2 {
            color: var(--text-color);
            font-size: 1.8rem;
            margin-bottom: 8px;
            font-weight: 700;
        }

        .login-header p {
            color: var(--text-light);
            font-size: 0.95rem;
        }

        /* Alert Messages */
        .alert {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            animation: slideDown 0.3s ease;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border: 1px solid transparent;
        }

        .alert i {
            font-size: 18px;
            margin-top: 1px;
        }

        .alert-error {
            background: rgba(244, 67, 54, 0.1);
            color: var(--error-color);
            border-color: rgba(244, 67, 54, 0.2);
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success-color);
            border-color: rgba(76, 175, 80, 0.2);
        }

        /* User Type Selector */
        .user-type-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 25px;
        }

        .user-type-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 16px 8px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            cursor: pointer;
            transition: var(--transition);
            background: var(--bg-light);
            text-align: center;
        }

        .user-type-option:hover {
            border-color: var(--primary-color);
            background: var(--white);
        }

        .user-type-option.selected {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: var(--white);
        }

        .user-type-option i {
            font-size: 22px;
            margin-bottom: 10px;
        }

        .user-type-option span {
            font-weight: 500;
            font-size: 0.85rem;
        }

        input[type="radio"] {
            display: none;
        }

        /* Form Groups */
        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: block;
            color: var(--text-color);
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .input-with-icon {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-color);
            font-size: 18px;
            z-index: 1;
            pointer-events: none;
        }

        .form-control {
            width: 100%;
            padding: 16px 16px 16px 48px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            transition: var(--transition);
            background: var(--bg-light);
            -webkit-appearance: none;
            appearance: none;
            min-height: 52px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Password Toggle */
        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--primary-color);
            cursor: pointer;
            font-size: 18px;
            z-index: 2;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            transition: var(--transition);
        }

        .password-toggle:hover {
            background: rgba(102, 126, 234, 0.1);
        }

        .forgot-password {
            text-align: right;
            margin-top: 8px;
        }

        .forgot-password a {
            color: var(--primary-color);
            font-size: 0.85rem;
            text-decoration: none;
            transition: var(--transition);
            font-weight: 500;
        }

        .forgot-password a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        /* Submit Button */
        .login-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: var(--white);
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
            min-height: 56px;
            position: relative;
            overflow: hidden;
        }

        .login-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }

        .login-btn:active:not(:disabled) {
            transform: translateY(0);
        }

        .login-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .login-btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }

        .login-btn:focus:not(:active)::after {
            animation: ripple 1s ease-out;
        }

        /* Loading Spinner */
        .loading {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--white);
            animation: spin 1s linear infinite;
        }

        /* Form Footer */
        .form-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .form-footer p {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .form-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .form-footer a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 12px;
            }

            .login-container {
                max-width: 100%;
                margin: 40px auto 10px;
                border-radius: 20px;
            }

            .logo-section {
                padding: 25px 20px;
            }

            .logo-icon {
                width: 60px;
                height: 60px;
                font-size: 26px;
            }

            .logo-section h1 {
                font-size: 1.6rem;
            }

            .features-grid {
                grid-template-columns: 1fr;
                gap: 12px;
                margin: 20px 0;
            }

            .form-section {
                padding: 25px 20px;
            }

            .login-header h2 {
                font-size: 1.6rem;
            }

            .user-type-selector {
                grid-template-columns: repeat(3, 1fr);
                gap: 8px;
            }

            .user-type-option {
                padding: 14px 6px;
            }

            .user-type-option i {
                font-size: 20px;
                margin-bottom: 8px;
            }

            .user-type-option span {
                font-size: 0.8rem;
            }

            .back-home {
                top: 12px;
                left: 12px;
            }

            .back-home a {
                padding: 8px 14px;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: 15px;
            }

            .login-container {
                border-radius: 16px;
            }

            .logo-section {
                padding: 20px 16px;
            }

            .logo-section h1 {
                font-size: 1.4rem;
            }

            .form-section {
                padding: 20px 16px;
            }

            .login-header h2 {
                font-size: 1.4rem;
            }

            .user-type-selector {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .user-type-option {
                flex-direction: row;
                padding: 12px 16px;
                text-align: left;
                gap: 12px;
            }

            .user-type-option i {
                margin-bottom: 0;
                font-size: 20px;
                flex-shrink: 0;
            }

            .form-control {
                padding: 14px 14px 14px 44px;
                min-height: 48px;
                font-size: 16px;
                /* Prevents zoom on iOS */
            }

            .login-btn {
                padding: 16px;
                min-height: 52px;
                font-size: 1rem;
            }

            .feature-card {
                padding: 14px;
            }

            .feature-icon {
                width: 36px;
                height: 36px;
                font-size: 16px;
            }
        }

        @media (max-width: 360px) {
            .logo-section h1 {
                font-size: 1.3rem;
            }

            .login-header h2 {
                font-size: 1.3rem;
            }

            .form-control {
                font-size: 15px;
            }

            .user-type-option span {
                font-size: 0.8rem;
            }
        }

        /* Landscape Orientation */
        @media (max-height: 600px) and (orientation: landscape) {
            .login-container {
                margin: 20px auto;
                max-height: 90vh;
                overflow-y: auto;
            }

            .logo-section {
                padding: 20px;
            }

            .form-section {
                padding: 20px;
            }

            .features-grid {
                display: none;
            }
        }

        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 0.5;
            }

            20% {
                transform: scale(25, 25);
                opacity: 0.3;
            }

            100% {
                opacity: 0;
                transform: scale(40, 40);
            }
        }

        /* Prevent text size adjustment on orientation change */
        html {
            -webkit-text-size-adjust: 100%;
        }

        /* Safe area insets for notch phones */
        @supports (padding: max(0px)) {
            body {
                padding-left: max(16px, env(safe-area-inset-left));
                padding-right: max(16px, env(safe-area-inset-right));
                padding-bottom: max(16px, env(safe-area-inset-bottom));
            }

            .back-home {
                left: max(16px, env(safe-area-inset-left));
                top: max(16px, env(safe-area-inset-top));
            }
        }

        /* iOS input styling fixes */
        input[type="text"],
        input[type="password"] {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }

        /* Hide spin buttons on number inputs */
        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
    </style>
</head>

<body>
    <div class="back-home">
        <a href="index.php">
            <i class="fas fa-arrow-left"></i>
            <span>Home</span>
        </a>
    </div>

    <div class="login-container">
        <!-- Logo & Features Section -->
        <div class="logo-section">
            <div class="logo-icon">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <h1><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'Chrisdeb Academy'; ?></h1>
            <p>Examination Management System</p>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h4>Instant Results</h4>
                    <p>Get immediate scoring</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h4>Secure Platform</h4>
                    <p>Protected exams</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h4>Analytics</h4>
                    <p>Track progress</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h4>24/7 Access</h4>
                    <p>Anytime, anywhere</p>
                </div>
            </div>
        </div>

        <!-- Form Section -->
        <div class="form-section">
            <div class="login-header">
                <h2>Welcome Back</h2>
                <p>Sign in to your account</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo htmlspecialchars($success); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <!-- User Type Selection -->
                <div class="user-type-selector">
                    <label class="user-type-option <?php echo ($_POST['user_type'] ?? 'student') === 'student' ? 'selected' : ''; ?>">
                        <input type="radio" name="user_type" value="student" <?php echo ($_POST['user_type'] ?? 'student') === 'student' ? 'checked' : ''; ?>>
                        <i class="fas fa-user-graduate"></i>
                        <span>Student</span>
                    </label>

                    <label class="user-type-option <?php echo ($_POST['user_type'] ?? '') === 'staff' ? 'selected' : ''; ?>">
                        <input type="radio" name="user_type" value="staff" <?php echo ($_POST['user_type'] ?? '') === 'staff' ? 'checked' : ''; ?>>
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Staff</span>
                    </label>

                    <label class="user-type-option <?php echo ($_POST['user_type'] ?? '') === 'admin' ? 'selected' : ''; ?>">
                        <input type="radio" name="user_type" value="admin" <?php echo ($_POST['user_type'] ?? '') === 'admin' ? 'checked' : ''; ?>>
                        <i class="fas fa-user-cog"></i>
                        <span>Admin</span>
                    </label>
                </div>

                <!-- Username Field -->
                <div class="form-group">
                    <label for="username" id="usernameLabel">Admission Number</label>
                    <div class="input-with-icon">
                        <i class="input-icon fas fa-user"></i>
                        <input type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            placeholder="Enter your admission number"
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                            required
                            autocomplete="username"
                            autocapitalize="none">
                    </div>
                </div>

                <!-- Password Field -->
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-with-icon">
                        <i class="input-icon fas fa-lock"></i>
                        <input type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Enter your password"
                            required
                            autocomplete="current-password">
                        <button type="button" class="password-toggle" id="togglePassword" aria-label="Toggle password visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="forgot-password">
                        <a href="forgot-password.php">Forgot Password?</a>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="login-btn" id="submitBtn">
                    <span id="btnText">Sign In</span>
                    <div class="loading" id="loadingSpinner"></div>
                </button>
            </form>

            <div class="form-footer">
                <p>Need help? <a href="contact.php">Contact Support</a></p>
            </div>
        </div>
    </div>

    <script>
        // Mobile-friendly JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            // Elements
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            const userTypeOptions = document.querySelectorAll('.user-type-option');
            const usernameLabel = document.getElementById('usernameLabel');
            const usernameInput = document.getElementById('username');
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const loginForm = document.getElementById('loginForm');

            // Password toggle with touch support
            togglePassword.addEventListener('click', togglePasswordVisibility);
            togglePassword.addEventListener('touchstart', function(e) {
                e.preventDefault();
                togglePasswordVisibility();
            }, {
                passive: false
            });

            function togglePasswordVisibility() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                const icon = togglePassword.querySelector('i');
                icon.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
                togglePassword.setAttribute('aria-label',
                    type === 'password' ? 'Show password' : 'Hide password'
                );
            }

            // User type selection with touch support
            userTypeOptions.forEach(option => {
                option.addEventListener('click', handleUserTypeSelection);
                option.addEventListener('touchstart', function(e) {
                    e.preventDefault();
                    handleUserTypeSelection.call(this);
                }, {
                    passive: false
                });
            });

            function handleUserTypeSelection() {
                // Remove selected class from all options
                userTypeOptions.forEach(opt => opt.classList.remove('selected'));

                // Add selected class to clicked option
                this.classList.add('selected');

                // Update radio button
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                    updateUsernameField(radio.value);
                }
            }

            // Update username field based on user type
            function updateUsernameField(userType) {
                switch (userType) {
                    case 'student':
                        usernameLabel.textContent = 'Admission Number';
                        usernameInput.placeholder = 'Enter admission number';
                        usernameInput.setAttribute('autocomplete', 'username');
                        break;
                    case 'staff':
                        usernameLabel.textContent = 'Staff ID';
                        usernameInput.placeholder = 'Enter staff ID';
                        usernameInput.setAttribute('autocomplete', 'username');
                        break;
                    case 'admin':
                        usernameLabel.textContent = 'Username';
                        usernameInput.placeholder = 'Enter username';
                        usernameInput.setAttribute('autocomplete', 'username');
                        break;
                }

                // Focus on input after selection on mobile
                if (window.innerWidth <= 768) {
                    setTimeout(() => usernameInput.focus(), 100);
                }
            }

            // Set initial username field
            const selectedUserType = document.querySelector('input[name="user_type"]:checked');
            if (selectedUserType) {
                updateUsernameField(selectedUserType.value);
            }

            // Form submission with loading state
            loginForm.addEventListener('submit', function(e) {
                // Client-side validation
                const username = usernameInput.value.trim();
                const password = passwordInput.value;

                if (!username || !password) {
                    e.preventDefault();
                    showMobileAlert('Please fill in all fields');
                    return;
                }

                // Show loading state
                btnText.style.display = 'none';
                loadingSpinner.style.display = 'block';
                submitBtn.disabled = true;
                submitBtn.setAttribute('aria-busy', 'true');

                // Reset loading state after 10 seconds if still on page
                setTimeout(() => {
                    if (loadingSpinner.style.display === 'block') {
                        resetLoadingState();
                    }
                }, 10000);
            });

            // Reset loading state function
            function resetLoadingState() {
                btnText.style.display = 'inline';
                loadingSpinner.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.setAttribute('aria-busy', 'false');
            }

            // Mobile alert function
            function showMobileAlert(message) {
                // Create alert element
                const alert = document.createElement('div');
                alert.className = 'alert alert-error';
                alert.innerHTML = `
                    <i class="fas fa-exclamation-circle"></i>
                    <div>${message}</div>
                `;
                alert.style.cssText = `
                    position: fixed;
                    top: 20px;
                    left: 20px;
                    right: 20px;
                    z-index: 1000;
                    animation: slideDown 0.3s ease;
                `;

                // Insert after form
                const formSection = document.querySelector('.form-section');
                formSection.insertBefore(alert, formSection.firstChild);

                // Remove after 5 seconds
                setTimeout(() => {
                    alert.style.animation = 'slideDown 0.3s ease reverse';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            }

            // Touch-friendly keyboard shortcuts
            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(e) {
                const now = Date.now();
                if (now - lastTouchEnd <= 300) {
                    e.preventDefault(); // Double-tap prevention
                }
                lastTouchEnd = now;
            }, false);

            // Prevent zoom on double-tap
            document.addEventListener('dblclick', function(e) {
                e.preventDefault();
            }, {
                passive: false
            });

            // Virtual keyboard detection
            const originalViewport = document.querySelector('meta[name=viewport]').content;

            function updateViewport() {
                const viewport = document.querySelector('meta[name=viewport]');
                if (window.innerWidth <= 768) {
                    viewport.content = originalViewport + ', height=' + window.innerHeight;
                } else {
                    viewport.content = originalViewport;
                }
            }

            window.addEventListener('resize', updateViewport);
            window.addEventListener('orientationchange', function() {
                setTimeout(updateViewport, 100);
            });

            // Auto-focus on username input with delay for mobile
            setTimeout(() => {
                if (!usernameInput.value) {
                    usernameInput.focus();
                }
            }, 300);

            // Handle iOS form field zoom prevention
            if (/iPad|iPhone|iPod/.test(navigator.userAgent)) {
                document.addEventListener('focus', function(e) {
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                        document.body.style.fontSize = '16px';
                    }
                }, true);

                document.addEventListener('blur', function(e) {
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                        document.body.style.fontSize = '';
                    }
                }, true);
            }

            // Improve touch feedback
            document.querySelectorAll('button, .user-type-option').forEach(element => {
                element.addEventListener('touchstart', function() {
                    this.style.opacity = '0.8';
                });

                element.addEventListener('touchend', function() {
                    this.style.opacity = '1';
                });
            });
        });

        // Handle page visibility for loading state
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                const loadingSpinner = document.getElementById('loadingSpinner');
                if (loadingSpinner && loadingSpinner.style.display === 'block') {
                    // User switched tabs and came back - reset loading
                    document.getElementById('btnText').style.display = 'inline';
                    loadingSpinner.style.display = 'none';
                    document.getElementById('submitBtn').disabled = false;
                }
            }
        });
    </script>
</body>

</html>