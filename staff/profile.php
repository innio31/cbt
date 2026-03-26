<?php
// staff/profile.php - Staff Profile Management
session_start();

// Check if staff is logged in
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'staff') {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Get staff information
$staff_id = $_SESSION['staff_id'];
$staff_name = $_SESSION['staff_name'] ?? 'Staff Member';
$staff_role = $_SESSION['staff_role'] ?? 'staff';

// Initialize auth system
initAuthSystem($pdo);

// Fetch staff profile data
$profile_data = null;
$error_message = '';
$success_message = '';

try {
    // Get staff profile data
    $stmt = $pdo->prepare("
        SELECT id, staff_id, full_name, role, email, profile_picture, 
               created_at, is_active
        FROM staff 
        WHERE staff_id = ?
    ");
    $stmt->execute([$staff_id]);
    $profile_data = $stmt->fetch();

    if (!$profile_data) {
        throw new Exception("Staff profile not found");
    }

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['update_profile'])) {
            // Update basic profile information
            $full_name = trim($_POST['full_name']);
            $email = trim($_POST['email']);

            // Validate inputs
            if (empty($full_name)) {
                $error_message = "Full name is required";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($email)) {
                $error_message = "Invalid email address";
            } else {
                // Update profile
                $stmt = $pdo->prepare("
                    UPDATE staff 
                    SET full_name = ?, email = ?
                    WHERE staff_id = ?
                ");
                $stmt->execute([$full_name, $email, $staff_id]);

                // Update session data
                $_SESSION['staff_name'] = $full_name;

                $success_message = "Profile updated successfully!";

                // Refresh profile data
                $stmt = $pdo->prepare("
                    SELECT id, staff_id, full_name, role, email, profile_picture, 
                           created_at, is_active
                    FROM staff 
                    WHERE staff_id = ?
                ");
                $stmt->execute([$staff_id]);
                $profile_data = $stmt->fetch();
            }
        } elseif (isset($_POST['change_password'])) {
            // Change password
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            // Validate current password
            $stmt = $pdo->prepare("SELECT password FROM staff WHERE staff_id = ?");
            $stmt->execute([$staff_id]);
            $staff = $stmt->fetch();

            if (!password_verify($current_password, $staff['password'])) {
                $error_message = "Current password is incorrect";
            } elseif (strlen($new_password) < 8) {
                $error_message = "New password must be at least 8 characters long";
            } elseif ($new_password !== $confirm_password) {
                $error_message = "New passwords do not match";
            } else {
                // Hash and update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE staff SET password = ? WHERE staff_id = ?");
                $stmt->execute([$hashed_password, $staff_id]);

                // Log password change activity
                logActivity($profile_data['id'], 'staff', "Changed password", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

                $success_message = "Password changed successfully!";
            }
        } elseif (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
            // Upload profile picture
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_type = $_FILES['profile_picture']['type'];
            $file_size = $_FILES['profile_picture']['size'];
            $file_tmp = $_FILES['profile_picture']['tmp_name'];

            // Validate file
            if (!in_array($file_type, $allowed_types)) {
                $error_message = "Only JPG, PNG, and GIF files are allowed";
            } elseif ($file_size > 5 * 1024 * 1024) { // 5MB limit
                $error_message = "File size must be less than 5MB";
            } else {
                // Create uploads directory if it doesn't exist
                $upload_dir = '../uploads/staff_profiles/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                // Generate unique filename
                $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                $new_filename = 'staff_' . $staff_id . '_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;

                // Resize image if needed
                if (move_uploaded_file($file_tmp, $upload_path)) {
                    // Resize to 200x200 pixels
                    list($width, $height) = getimagesize($upload_path);
                    $new_width = 200;
                    $new_height = 200;

                    $image = null;
                    if ($file_type === 'image/jpeg') {
                        $image = imagecreatefromjpeg($upload_path);
                    } elseif ($file_type === 'image/png') {
                        $image = imagecreatefrompng($upload_path);
                    } elseif ($file_type === 'image/gif') {
                        $image = imagecreatefromgif($upload_path);
                    }

                    if ($image) {
                        $resized = imagecreatetruecolor($new_width, $new_height);

                        // Preserve transparency for PNG and GIF
                        if ($file_type === 'image/png' || $file_type === 'image/gif') {
                            imagecolortransparent($resized, imagecolorallocatealpha($resized, 0, 0, 0, 127));
                            imagealphablending($resized, false);
                            imagesavealpha($resized, true);
                        }

                        imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

                        // Save resized image
                        if ($file_type === 'image/jpeg') {
                            imagejpeg($resized, $upload_path, 90);
                        } elseif ($file_type === 'image/png') {
                            imagepng($resized, $upload_path, 9);
                        } elseif ($file_type === 'image/gif') {
                            imagegif($resized, $upload_path);
                        }

                        imagedestroy($image);
                        imagedestroy($resized);
                    }

                    // Delete old profile picture if exists
                    if ($profile_data['profile_picture']) {
                        $old_file = '../uploads/staff_profiles/' . basename($profile_data['profile_picture']);
                        if (file_exists($old_file)) {
                            unlink($old_file);
                        }
                    }

                    // Update database with relative path
                    $relative_path = 'uploads/staff_profiles/' . $new_filename;
                    $stmt = $pdo->prepare("UPDATE staff SET profile_picture = ? WHERE staff_id = ?");
                    $stmt->execute([$relative_path, $staff_id]);

                    // Update profile data
                    $profile_data['profile_picture'] = $relative_path;

                    $success_message = "Profile picture updated successfully!";
                } else {
                    $error_message = "Failed to upload profile picture";
                }
            }
        }
    }

    // Get staff activity statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT DATE(created_at)) as active_days,
            COUNT(*) as total_activities,
            MIN(created_at) as first_activity,
            MAX(created_at) as last_activity
        FROM activity_logs 
        WHERE user_id = ? AND user_type = 'staff'
    ");
    $stmt->execute([$profile_data['id']]);
    $activity_stats = $stmt->fetch();

    // Get recent activities
    $stmt = $pdo->prepare("
        SELECT activity, created_at, ip_address 
        FROM activity_logs 
        WHERE user_id = ? AND user_type = 'staff'
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$profile_data['id']]);
    $recent_activities = $stmt->fetchAll();

    // Get assigned subjects and classes count
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT subject_id) as subjects_count
        FROM staff_subjects 
        WHERE staff_id = ?
    ");
    $stmt->execute([$staff_id]);
    $subjects_count = $stmt->fetch()['subjects_count'];

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT class) as classes_count
        FROM staff_classes 
        WHERE staff_id = ?
    ");
    $stmt->execute([$staff_id]);
    $classes_count = $stmt->fetch()['classes_count'];
} catch (Exception $e) {
    error_log("Staff profile error: " . $e->getMessage());
    $error_message = "Error loading profile data";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Staff Dashboard</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* Reuse styles from index.php with profile-specific additions */
        :root {
            --primary-color: #2c5282;
            --secondary-color: #4299e1;
            --accent-color: #ed8936;
            --success-color: #38a169;
            --warning-color: #d69e2e;
            --danger-color: #e53e3e;
            --light-color: #edf2f7;
            --dark-color: #2d3748;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: #333;
            min-height: 100vh;
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
            box-shadow: 3px 0 20px rgba(0, 0, 0, 0.15);
            display: flex;
            flex-direction: column;
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0 0 20px 0;
        }

        .sidebar-content::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar-content::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }

        .sidebar-content::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
        }

        .sidebar-content::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.4);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding: 0 20px;
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--secondary-color), #3182ce);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }

        .logo-text h3 {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .logo-text p {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .staff-info {
            text-align: center;
            padding: 20px 15px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 15px;
            margin: 0 15px 25px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .staff-info h4 {
            margin-bottom: 8px;
            font-size: 1.1rem;
            font-weight: 500;
        }

        .staff-info p {
            font-size: 0.85rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .staff-role {
            display: inline-block;
            background: rgba(66, 153, 225, 0.3);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-top: 5px;
        }

        .nav-links {
            list-style: none;
            padding: 0 15px;
        }

        .nav-links li {
            margin-bottom: 8px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-radius: 12px;
            border-left: 4px solid transparent;
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: var(--accent-color);
            transform: translateX(5px);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border-left-color: var(--accent-color);
            font-weight: 500;
        }

        .nav-links i {
            width: 22px;
            text-align: center;
            font-size: 1.1rem;
        }

        /* Main Content Styles */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 25px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        .top-header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 35px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 5px solid var(--accent-color);
        }

        .header-title h1 {
            color: var(--dark-color);
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 8px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .header-title p {
            color: #4a5568;
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logout-btn {
            background: linear-gradient(135deg, var(--danger-color), #c53030);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 1rem;
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.2);
        }

        .logout-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(229, 62, 62, 0.3);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: white;
            padding: 30px;
            border-radius: 18px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            border-top: 5px solid;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: inherit;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }

        .stat-card.students {
            border-top-color: var(--secondary-color);
        }

        .stat-card.exams {
            border-top-color: var(--warning-color);
        }

        .stat-card.assignments {
            border-top-color: var(--accent-color);
        }

        .stat-card.grading {
            border-top-color: var(--danger-color);
        }

        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.students .stat-icon {
            background: linear-gradient(135deg, var(--secondary-color), #3182ce);
        }

        .stat-card.exams .stat-icon {
            background: linear-gradient(135deg, var(--warning-color), #d69e2e);
        }

        .stat-card.assignments .stat-icon {
            background: linear-gradient(135deg, var(--accent-color), #dd6b20);
        }

        .stat-card.grading .stat-icon {
            background: linear-gradient(135deg, var(--danger-color), #c53030);
        }

        .stat-value {
            font-size: 2.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--dark-color);
        }

        .stat-label {
            font-size: 1rem;
            color: #718096;
            font-weight: 500;
        }

        .stat-description {
            font-size: 0.9rem;
            color: #a0aec0;
            margin-top: 10px;
            line-height: 1.5;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 25px;
            min-height: 100vh;
        }

        .top-header {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 35px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            border-left: 5px solid var(--accent-color);
        }

        .header-title h1 {
            color: var(--dark-color);
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 8px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .header-title p {
            color: #4a5568;
            font-size: 1rem;
        }

        /* Profile Container */
        .profile-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 30px;
            margin-bottom: 35px;
        }

        @media (max-width: 1024px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
        }

        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
            position: relative;
        }

        .profile-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            border-radius: 18px 18px 0 0;
        }

        .profile-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .profile-picture {
            position: relative;
            width: 180px;
            height: 180px;
            margin: 0 auto 20px;
            border-radius: 50%;
            overflow: hidden;
            border: 5px solid var(--light-color);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        .profile-picture img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-picture-upload {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            opacity: 0;
        }

        .profile-picture:hover .profile-picture-upload {
            opacity: 1;
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 8px;
        }

        .profile-role {
            display: inline-block;
            background: linear-gradient(135deg, var(--secondary-color), #3182ce);
            color: white;
            padding: 6px 18px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Profile Info */
        .profile-info {
            margin-top: 25px;
        }

        .info-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid var(--light-color);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-icon {
            width: 40px;
            height: 40px;
            background: var(--light-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: var(--secondary-color);
            font-size: 1.1rem;
        }

        .info-content h4 {
            font-size: 0.9rem;
            color: #718096;
            margin-bottom: 4px;
        }

        .info-content p {
            font-size: 1rem;
            font-weight: 500;
            color: var(--dark-color);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 25px;
        }

        .stat-item {
            background: var(--light-color);
            padding: 15px;
            border-radius: 12px;
            text-align: center;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #718096;
        }

        /* Forms */
        .form-card {
            background: white;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);
            margin-bottom: 25px;
        }

        .form-card h3 {
            color: var(--dark-color);
            font-size: 1.4rem;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--light-color);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark-color);
        }

        .form-control {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.2);
        }

        .btn {
            display: inline-block;
            padding: 14px 28px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            box-shadow: 0 4px 15px rgba(66, 153, 225, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(66, 153, 225, 0.3);
        }

        .btn-secondary {
            background: var(--light-color);
            color: var(--dark-color);
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        /* Activity List */
        .activity-list {
            list-style: none;
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            padding: 15px 0;
            border-bottom: 1px solid var(--light-color);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-content {
            font-size: 0.95rem;
            color: #4a5568;
            margin-bottom: 8px;
        }

        .activity-meta {
            font-size: 0.85rem;
            color: #a0aec0;
            display: flex;
            justify-content: space-between;
        }

        /* Alert Messages */
        .alert {
            padding: 18px 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 1rem;
            border-left: 5px solid;
        }

        .alert-success {
            background: #f0fff4;
            color: #22543d;
            border-left-color: var(--success-color);
        }

        .alert-error {
            background: #fff5f5;
            color: #742a2a;
            border-left-color: var(--danger-color);
        }

        /* File Upload */
        .file-upload {
            position: relative;
            display: inline-block;
            width: 100%;
        }

        .file-upload input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-upload-label {
            display: block;
            padding: 14px 18px;
            background: var(--light-color);
            border: 2px dashed #cbd5e0;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload-label:hover {
            border-color: var(--secondary-color);
            background: #ebf8ff;
        }

        /* Password Strength */
        .password-strength {
            margin-top: 10px;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0;
            border-radius: 3px;
            transition: all 0.3s ease;
        }

        .strength-weak {
            background: var(--danger-color);
        }

        .strength-medium {
            background: var(--warning-color);
        }

        .strength-strong {
            background: var(--success-color);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 300px;
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .profile-container {
                gap: 20px;
            }

            .profile-picture {
                width: 150px;
                height: 150px;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar (Same as index.php - truncated for brevity) -->
    <div class="sidebar" id="sidebar">
        <!-- Same sidebar content as index.php -->
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="logo-text">
                    <h3><?php echo defined('SCHOOL_NAME') ? SCHOOL_NAME : 'The Climax Brains Academy'; ?></h3>
                    <p>Staff Portal</p>
                </div>
            </div>
        </div>

        <div class="sidebar-content">
            <div class="staff-info">
                <h4><?php echo htmlspecialchars($staff_name); ?></h4>
                <p>Staff ID: <?php echo htmlspecialchars($staff_id); ?></p>
                <div class="staff-role"><?php echo ucfirst(str_replace('_', ' ', $staff_role)); ?></div>
            </div>

            <ul class="nav-links">
                <li><a href="index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="profile.php" class="active"><i class="fas fa-user-cog"></i> My Profile</a></li>
                <li><a href="manage-students.php"><i class="fas fa-users"></i> My Students</a></li>
                <li><a href="manage-exams.php"><i class="fas fa-file-alt"></i> Manage Exams</a></li>
                <li><a href="view-results.php"><i class="fas fa-chart-bar"></i> View Results</a></li>
                <li><a href="assignments.php"><i class="fas fa-tasks"></i> Assignments</a></li>
                <li><a href="questions.php"><i class="fas fa-question-circle"></i> Question Bank</a></li>
                <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Header -->
        <div class="top-header">
            <div class="header-title">
                <h1>My Profile</h1>
                <p>Manage your personal information and account settings</p>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <div class="profile-container">
            <!-- Profile Information Card -->
            <div class="profile-card">
                <div class="profile-header">
                    <div class="profile-picture">
                        <?php if ($profile_data['profile_picture']): ?>
                            <img src="../<?php echo htmlspecialchars($profile_data['profile_picture']); ?>" alt="Profile Picture">
                        <?php else: ?>
                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($profile_data['full_name']); ?>&background=4299e1&color=fff&size=200" alt="Profile Picture">
                        <?php endif; ?>

                        <!-- Upload Form -->
                        <form id="pictureForm" method="POST" enctype="multipart/form-data" style="display: none;">
                            <input type="file" name="profile_picture" id="profilePictureInput" accept="image/*" onchange="this.form.submit()">
                        </form>
                        <div class="profile-picture-upload" onclick="document.getElementById('profilePictureInput').click()">
                            <i class="fas fa-camera"></i> Change Photo
                        </div>
                    </div>

                    <h2 class="profile-name"><?php echo htmlspecialchars($profile_data['full_name']); ?></h2>
                    <div class="profile-role">
                        <?php echo ucfirst($profile_data['role']); ?>
                    </div>
                </div>

                <div class="profile-info">
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-id-card"></i>
                        </div>
                        <div class="info-content">
                            <h4>Staff ID</h4>
                            <p><?php echo htmlspecialchars($profile_data['staff_id']); ?></p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="info-content">
                            <h4>Email</h4>
                            <p><?php echo htmlspecialchars($profile_data['email'] ?? 'Not set'); ?></p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="info-content">
                            <h4>Member Since</h4>
                            <p><?php echo date('F j, Y', strtotime($profile_data['created_at'])); ?></p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="info-content">
                            <h4>Account Status</h4>
                            <p>
                                <span style="color: <?php echo $profile_data['is_active'] ? 'var(--success-color)' : 'var(--danger-color)'; ?>; font-weight: 600;">
                                    <?php echo $profile_data['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $activity_stats['total_activities'] ?? 0; ?></div>
                        <div class="stat-label">Activities</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $subjects_count ?? 0; ?></div>
                        <div class="stat-label">Subjects</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $classes_count ?? 0; ?></div>
                        <div class="stat-label">Classes</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $activity_stats['active_days'] ?? 0; ?></div>
                        <div class="stat-label">Active Days</div>
                    </div>
                </div>
            </div>

            <!-- Forms Section -->
            <div>
                <!-- Update Profile Form -->
                <div class="form-card">
                    <h3>Update Profile Information</h3>
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="full_name">Full Name *</label>
                            <input type="text" id="full_name" name="full_name" class="form-control"
                                value="<?php echo htmlspecialchars($profile_data['full_name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" class="form-control"
                                value="<?php echo htmlspecialchars($profile_data['email'] ?? ''); ?>">
                        </div>

                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </form>
                </div>

                <!-- Change Password Form -->
                <div class="form-card">
                    <h3>Change Password</h3>
                    <form method="POST" action="">
                        <div class="form-group">
                            <label for="current_password">Current Password *</label>
                            <input type="password" id="current_password" name="current_password" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="new_password">New Password *</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" required
                                onkeyup="checkPasswordStrength(this.value)">
                            <div class="password-strength">
                                <div class="strength-bar" id="passwordStrength"></div>
                            </div>
                            <small style="color: #718096; font-size: 0.85rem;">
                                Password must be at least 8 characters long
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                        </div>

                        <button type="submit" name="change_password" class="btn btn-primary">
                            <i class="fas fa-key"></i> Change Password
                        </button>
                    </form>
                </div>

                <!-- Recent Activities -->
                <div class="form-card">
                    <h3>Recent Activities</h3>
                    <?php if (!empty($recent_activities)): ?>
                        <ul class="activity-list">
                            <?php foreach ($recent_activities as $activity): ?>
                                <li class="activity-item">
                                    <div class="activity-content">
                                        <?php echo htmlspecialchars($activity['activity']); ?>
                                    </div>
                                    <div class="activity-meta">
                                        <span><?php echo date('M d, h:i A', strtotime($activity['created_at'])); ?></span>
                                        <span>IP: <?php echo htmlspecialchars($activity['ip_address']); ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p style="color: #a0aec0; text-align: center; padding: 20px;">No recent activities found</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Password strength checker
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('passwordStrength');
            let strength = 0;

            // Length check
            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;

            // Complexity checks
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;

            // Update strength bar
            let width = 0;
            let color = '';

            if (strength <= 2) {
                width = 33;
                color = 'strength-weak';
            } else if (strength <= 4) {
                width = 66;
                color = 'strength-medium';
            } else {
                width = 100;
                color = 'strength-strong';
            }

            strengthBar.style.width = width + '%';
            strengthBar.className = 'strength-bar ' + color;
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');

            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const passwordInputs = form.querySelectorAll('input[type="password"]');
                    const newPassword = form.querySelector('input[name="new_password"]');
                    const confirmPassword = form.querySelector('input[name="confirm_password"]');

                    if (newPassword && confirmPassword) {
                        if (newPassword.value !== confirmPassword.value) {
                            e.preventDefault();
                            alert('New passwords do not match!');
                            return false;
                        }

                        if (newPassword.value.length < 8) {
                            e.preventDefault();
                            alert('Password must be at least 8 characters long!');
                            return false;
                        }
                    }
                });
            });
        });

        // Profile picture preview
        document.getElementById('profilePictureInput').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.querySelector('.profile-picture img').src = e.target.result;
                }
                reader.readAsDataURL(this.files[0]);
            }
        });

        // Toggle sidebar on mobile (same as index.php)
        const mobileMenuBtn = document.createElement('button');
        mobileMenuBtn.className = 'mobile-menu-btn';
        mobileMenuBtn.id = 'mobileMenuBtn';
        mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
        document.body.appendChild(mobileMenuBtn);

        mobileMenuBtn.addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
            this.innerHTML = sidebar.classList.contains('active') ?
                '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');

            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove('active');
                    mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
                }
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P to focus on profile form
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                document.getElementById('full_name').focus();
            }

            // Escape to close sidebar on mobile
            if (e.key === 'Escape' && window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const mobileMenuBtn = document.getElementById('mobileMenuBtn');
                sidebar.classList.remove('active');
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });
    </script>
</body>

</html>