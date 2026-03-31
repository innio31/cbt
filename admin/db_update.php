<?php
// Add these lines at the very top for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// admin/db_update.php - Complete Database Migration with Column Checks
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit();
}

// Allow super_admin and admin to access
if ($_SESSION['admin_role'] !== 'admin' && $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: index.php?message=Access denied&type=error");
    exit();
}

require_once '../includes/config.php';

// Create migration tracking table if it doesn't exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(50) NOT NULL,
        description TEXT,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_version (version)
    )");
} catch (PDOException $e) {
    // Table might already exist, continue
}

// Helper function to check if a table exists
function tableExists($pdo, $table)
{
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Helper function to check if a column exists
function columnExists($pdo, $table, $column)
{
    try {
        if (!tableExists($pdo, $table)) {
            return false;
        }
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Helper function to add column if it doesn't exist
function addColumnIfNotExists($pdo, $table, $column, $definition, &$sql_statements)
{
    if (tableExists($pdo, $table) && !columnExists($pdo, $table, $column)) {
        $sql_statements[] = "ALTER TABLE `$table` ADD COLUMN $column $definition";
        return true;
    }
    return false;
}

// Helper function to modify column if needed (for data type changes)
function modifyColumnIfExists($pdo, $table, $column, $definition, &$sql_statements)
{
    if (tableExists($pdo, $table) && columnExists($pdo, $table, $column)) {
        $sql_statements[] = "ALTER TABLE `$table` MODIFY COLUMN $column $definition";
        return true;
    }
    return false;
}

// Helper function to add index if it doesn't exist
function addIndexIfNotExists($pdo, $table, $index, $columns, &$sql_statements)
{
    try {
        if (!tableExists($pdo, $table)) {
            return false;
        }
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
        $stmt->execute([$index]);
        if ($stmt->rowCount() == 0) {
            $sql_statements[] = "ALTER TABLE `$table` ADD INDEX `$index` ($columns)";
            return true;
        }
    } catch (PDOException $e) {
        // Table might not exist yet
    }
    return false;
}

// Helper function to add foreign key if it doesn't exist
function addForeignKeyIfNotExists($pdo, $table, $constraint, $column, $ref_table, $ref_column, &$sql_statements)
{
    try {
        if (!tableExists($pdo, $table) || !tableExists($pdo, $ref_table)) {
            return false;
        }
        // Check if foreign key already exists
        $stmt = $pdo->prepare("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND CONSTRAINT_NAME = ?
        ");
        $stmt->execute([$table, $constraint]);
        if ($stmt->rowCount() == 0) {
            $sql_statements[] = "ALTER TABLE `$table` ADD CONSTRAINT `$constraint` FOREIGN KEY (`$column`) REFERENCES `$ref_table`(`$ref_column`) ON DELETE CASCADE";
            return true;
        }
    } catch (PDOException $e) {
        // Foreign key might already exist or table not ready
    }
    return false;
}

// Get applied migrations
$stmt = $pdo->query("SELECT version FROM migrations");
$applied = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ============================================
// MIGRATION DEFINITIONS
// Keep all existing migrations and add new ones
// ============================================

$migrations = [
    // Version 1.0.0 - Initial structure
    '1.0.0' => [
        'description' => 'Initial database structure',
        'sql' => function ($pdo) {
            $sql_statements = [];
            // Basic tables only
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS schools (
                id INT PRIMARY KEY AUTO_INCREMENT,
                school_code VARCHAR(20) UNIQUE NOT NULL,
                school_name VARCHAR(255) NOT NULL,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            return $sql_statements;
        }
    ],
    // Version 1.1.0 - Added portal tables
    '1.1.0' => [
        'description' => 'Added portal admin and PIN management tables',
        'sql' => function ($pdo) {
            $sql_statements = [];

            // Portal admins table
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS portal_admins (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(100) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(255),
                email VARCHAR(255),
                role ENUM('super_admin', 'school_manager', 'support') DEFAULT 'support',
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";

            // Result pins table
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS result_pins (
                id INT PRIMARY KEY AUTO_INCREMENT,
                school_id INT NOT NULL,
                pin_code VARCHAR(20) UNIQUE NOT NULL,
                batch_number VARCHAR(50),
                student_id INT,
                max_uses INT DEFAULT 3,
                used_count INT DEFAULT 0,
                status ENUM('unused', 'active', 'expired', 'used_up') DEFAULT 'unused',
                generated_by INT,
                generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expiry_date DATE,
                price DECIMAL(10,2),
                FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
            )";

            return $sql_statements;
        }
    ],
    // Version 1.2.0 - Complete database structure
    '1.2.0' => [
        'description' => 'Complete database structure - all tables and columns from cbt.sql',
        'sql' => function ($pdo) {
            $sql_statements = [];

            // ============================================
            // TABLE: activity_logs
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS activity_logs (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                user_type ENUM('student','admin','staff') NOT NULL,
                activity TEXT NOT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            // ============================================
            // TABLE: admin_users
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS admin_users (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                role ENUM('super_admin','admin','teacher') DEFAULT 'admin',
                status ENUM('active','inactive') DEFAULT 'active',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // TABLE: affective_traits
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS affective_traits (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                session VARCHAR(20) NOT NULL,
                term VARCHAR(20) NOT NULL,
                punctuality ENUM('A','B','C','D','E') DEFAULT NULL,
                attendance ENUM('A','B','C','D','E') DEFAULT NULL,
                politeness ENUM('A','B','C','D','E') DEFAULT NULL,
                honesty ENUM('A','B','C','D','E') DEFAULT NULL,
                neatness ENUM('A','B','C','D','E') DEFAULT NULL,
                reliability ENUM('A','B','C','D','E') DEFAULT NULL,
                relationship ENUM('A','B','C','D','E') DEFAULT NULL,
                self_control ENUM('A','B','C','D','E') DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_student_session_term (student_id, session, term)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // TABLE: assignments
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS assignments (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                subject_id INT DEFAULT NULL,
                class VARCHAR(50) DEFAULT NULL,
                instructions TEXT DEFAULT NULL,
                file_path VARCHAR(255) DEFAULT NULL,
                deadline DATETIME DEFAULT NULL,
                max_marks INT DEFAULT NULL,
                staff_id INT DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: assignment_submissions
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS assignment_submissions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT DEFAULT NULL,
                assignment_id INT DEFAULT NULL,
                submitted_text TEXT DEFAULT NULL,
                file_path VARCHAR(500) DEFAULT NULL,
                submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                status ENUM('submitted','graded') DEFAULT 'submitted',
                grade VARCHAR(10) DEFAULT NULL,
                teacher_feedback TEXT DEFAULT NULL,
                graded_at TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: attendance
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS attendance (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                date DATE NOT NULL,
                status ENUM('present', 'absent', 'late') DEFAULT 'present',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

            // ============================================
            // TABLE: central_settings
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS central_settings (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                central_url VARCHAR(255) NOT NULL,
                api_key VARCHAR(100) NOT NULL,
                school_code VARCHAR(50) DEFAULT NULL,
                auto_sync TINYINT(1) DEFAULT 1,
                sync_interval INT DEFAULT 86400,
                last_sync TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // TABLE: exams
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS exams (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                exam_name VARCHAR(100) NOT NULL,
                class VARCHAR(50) NOT NULL,
                subject_id INT DEFAULT NULL,
                topics LONGTEXT DEFAULT NULL,
                objective_count INT DEFAULT NULL,
                subjective_count INT DEFAULT NULL,
                theory_count INT DEFAULT NULL,
                duration_minutes INT DEFAULT NULL,
                objective_duration INT DEFAULT 60,
                theory_duration INT DEFAULT 60,
                subjective_duration INT DEFAULT 60,
                is_active TINYINT(1) DEFAULT NULL,
                instructions TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT NULL,
                exam_type ENUM('objective','subjective','theory') DEFAULT 'objective',
                group_id INT DEFAULT NULL,
                theory_display ENUM('combined','separate') DEFAULT 'separate'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            // ============================================
            // TABLE: exam_assignments
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS exam_assignments (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                exam_id INT NOT NULL,
                assigned_by INT NOT NULL COMMENT 'Staff ID',
                assignment_type ENUM('immediate','scheduled') DEFAULT 'immediate',
                start_date DATETIME DEFAULT NULL,
                end_date DATETIME DEFAULT NULL,
                status ENUM('assigned','in_progress','completed','expired') DEFAULT 'assigned',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            // ============================================
            // TABLE: exam_questions
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS exam_questions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                question_text TEXT NOT NULL,
                option_a VARCHAR(255) NOT NULL,
                option_b VARCHAR(255) NOT NULL,
                option_c VARCHAR(255) NOT NULL,
                option_d VARCHAR(255) NOT NULL,
                correct_answer CHAR(1) NOT NULL,
                subject_id INT NOT NULL,
                topic_id INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // TABLE: exam_sessions
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS exam_sessions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT DEFAULT NULL,
                exam_id INT DEFAULT NULL,
                exam_type ENUM('objective','subjective','theory') DEFAULT 'objective',
                start_time DATETIME DEFAULT NULL,
                end_time DATETIME DEFAULT NULL,
                status ENUM('in_progress','completed') DEFAULT 'in_progress',
                objective_answers LONGTEXT DEFAULT NULL,
                score DECIMAL(5,2) DEFAULT NULL,
                correct_answers INT DEFAULT NULL,
                total_questions INT DEFAULT NULL,
                submitted_at DATETIME DEFAULT NULL,
                percentage DECIMAL(5,2) DEFAULT NULL,
                grade VARCHAR(10) DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // TABLE: exam_session_questions
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS exam_session_questions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                session_id INT DEFAULT NULL,
                question_id INT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                question_type ENUM('objective','theory') DEFAULT 'objective'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // TABLE: library_resources
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS library_resources (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                subject VARCHAR(100) DEFAULT NULL,
                class VARCHAR(50) DEFAULT NULL,
                file_type VARCHAR(50) DEFAULT NULL,
                file_path VARCHAR(500) DEFAULT NULL,
                file_size VARCHAR(50) DEFAULT NULL,
                uploaded_by INT DEFAULT NULL,
                uploaded_by_type VARCHAR(20) DEFAULT 'staff',
                uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: login_attempts
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS login_attempts (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                attempt_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // TABLE: objective_questions
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS objective_questions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                question_text MEDIUMTEXT NOT NULL,
                option_a MEDIUMTEXT NOT NULL,
                option_b MEDIUMTEXT NOT NULL,
                option_c MEDIUMTEXT NOT NULL,
                option_d MEDIUMTEXT NOT NULL,
                correct_answer CHAR(1) NOT NULL,
                subject_id INT DEFAULT NULL,
                topic_id INT DEFAULT NULL,
                difficulty_level ENUM('easy','medium','hard') DEFAULT 'medium',
                marks INT DEFAULT 1,
                class VARCHAR(50) DEFAULT NULL,
                question_image VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                passage_id INT DEFAULT NULL,
                gap_number INT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            // ============================================
            // TABLE: passages
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS passages (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                passage_text TEXT NOT NULL,
                title VARCHAR(255) DEFAULT NULL,
                subject_id INT DEFAULT NULL,
                topic_id INT DEFAULT NULL,
                class VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            // ============================================
            // TABLE: password_resets
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS password_resets (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                user_type ENUM('student','staff','admin') NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                used TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // TABLE: portal_activity_logs
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS portal_activity_logs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                user_type ENUM('admin', 'staff') DEFAULT 'admin',
                activity VARCHAR(255) NOT NULL,
                details TEXT,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";

            // ============================================
            // TABLE: psychomotor_skills
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS psychomotor_skills (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                session VARCHAR(20) NOT NULL,
                term ENUM('First','Second','Third') NOT NULL,
                handwriting ENUM('A','B','C','D','E') DEFAULT NULL,
                verbal_fluency ENUM('A','B','C','D','E') DEFAULT NULL,
                sports ENUM('A','B','C','D','E') DEFAULT NULL,
                handling_tools ENUM('A','B','C','D','E') DEFAULT NULL,
                drawing_painting ENUM('A','B','C','D','E') DEFAULT NULL,
                musical_skills ENUM('A','B','C','D','E') DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_student_session_term (student_id, session, term)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: report_card_settings
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS report_card_settings (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                session VARCHAR(20) NOT NULL,
                term ENUM('First','Second','Third') NOT NULL,
                template VARCHAR(50) DEFAULT 'default',
                class VARCHAR(50) NOT NULL,
                max_score INT NOT NULL,
                score_types JSON NOT NULL,
                grading_system VARCHAR(20) DEFAULT 'simple',
                next_resumption_date DATE DEFAULT NULL,
                current_resumption_date DATE DEFAULT NULL,
                current_closing_date DATE DEFAULT NULL,
                days_school_opened INT DEFAULT 90,
                show_class_position TINYINT(1) DEFAULT 1,
                show_subject_position TINYINT(1) DEFAULT 1,
                show_promoted_to TINYINT(1) DEFAULT 1,
                show_lowest_highest_avg TINYINT(1) DEFAULT 1,
                show_lowest_highest_class TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_session_term_class (session, term, class)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // TABLE: results
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS results (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT DEFAULT NULL,
                exam_id INT DEFAULT NULL,
                objective_score INT DEFAULT NULL,
                theory_score INT DEFAULT NULL,
                total_score INT DEFAULT NULL,
                percentage DECIMAL(5,2) DEFAULT NULL,
                grade VARCHAR(5) DEFAULT NULL,
                time_taken INT DEFAULT NULL,
                submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                correct_count INT DEFAULT 0,
                total_questions INT DEFAULT 0,
                KEY idx_result_student (student_id),
                KEY idx_result_exam (exam_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: school_classes
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS school_classes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                school_id INT NOT NULL,
                class_name VARCHAR(100) NOT NULL,
                class_code VARCHAR(20),
                class_category ENUM('Primary', 'Junior Secondary', 'Senior Secondary', 'Other') DEFAULT 'Other',
                sort_order INT DEFAULT 0,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
                UNIQUE KEY unique_school_class (school_id, class_name)
            )";

            // ============================================
            // TABLE: staff
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS staff (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                staff_id VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                role ENUM('staff','admin') DEFAULT 'staff',
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                profile_picture VARCHAR(255) DEFAULT NULL,
                email VARCHAR(255) DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: staff_classes
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS staff_classes (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                staff_id VARCHAR(50) NOT NULL,
                class VARCHAR(50) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: staff_subjects
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS staff_subjects (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                staff_id VARCHAR(50) NOT NULL,
                subject_id INT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_sync TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: students
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS students (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                admission_number VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                class VARCHAR(50) NOT NULL,
                class_id INT DEFAULT NULL,
                status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
                archive_reason VARCHAR(100) DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                full_name VARCHAR(100) NOT NULL,
                dob DATE DEFAULT NULL,
                gender ENUM('M','F','Other') DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                school_id INT,
                last_sync_at TIMESTAMP,
                parent_phone VARCHAR(50),
                parent_email VARCHAR(255),
                KEY idx_student_class (class),
                KEY idx_student_status (status),
                FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: student_comments
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS student_comments (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                session VARCHAR(20) NOT NULL,
                term ENUM('First','Second','Third') NOT NULL,
                teachers_comment TEXT DEFAULT NULL,
                principals_comment TEXT DEFAULT NULL,
                class_teachers_name VARCHAR(255) DEFAULT NULL,
                principals_name VARCHAR(255) DEFAULT NULL,
                days_present INT DEFAULT 0,
                days_absent INT DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_student_session_term (student_id, session, term)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: student_positions
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS student_positions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                session VARCHAR(20) NOT NULL,
                term ENUM('First','Second','Third') NOT NULL,
                class_position INT DEFAULT NULL,
                total_marks DECIMAL(8,2) DEFAULT 0.00,
                average DECIMAL(5,2) DEFAULT 0.00,
                promoted_to VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_student_session_term (student_id, session, term)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: student_scores
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS student_scores (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                subject_id INT NOT NULL,
                subject_name VARCHAR(255) DEFAULT NULL,
                session VARCHAR(20) NOT NULL,
                term ENUM('First','Second','Third') NOT NULL,
                score_data JSON NOT NULL,
                total_score DECIMAL(8,2) DEFAULT 0.00,
                percentage DECIMAL(5,2) DEFAULT 0.00,
                grade VARCHAR(5) DEFAULT NULL,
                subject_position INT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // TABLE: subjective_questions
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS subjective_questions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                question_text TEXT NOT NULL,
                correct_answer VARCHAR(500) NOT NULL,
                difficulty_level ENUM('easy','medium','hard') DEFAULT 'medium',
                marks INT DEFAULT 1,
                subject_id INT DEFAULT NULL,
                topic_id INT DEFAULT NULL,
                class VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: subjects
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS subjects (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                subject_name VARCHAR(100) NOT NULL,
                description MEDIUMTEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_sync TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            // ============================================
            // TABLE: subject_classes
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS subject_classes (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                subject_id INT NOT NULL,
                class VARCHAR(50) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
                UNIQUE KEY unique_subject_class (subject_id, class)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // TABLE: subject_groups
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS subject_groups (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                group_name VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                total_duration_minutes INT NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // TABLE: system_settings
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS system_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value TEXT,
                updated_by INT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";

            // ============================================
            // TABLE: theory_questions
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS theory_questions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                question_file VARCHAR(255) DEFAULT NULL,
                question_text TEXT DEFAULT NULL,
                subject_id INT DEFAULT NULL,
                topic_id INT DEFAULT NULL,
                class VARCHAR(50) DEFAULT NULL,
                marks INT DEFAULT 5,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: theory_sessions
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS theory_sessions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT DEFAULT NULL,
                exam_id INT DEFAULT NULL,
                start_time DATETIME DEFAULT NULL,
                end_time DATETIME DEFAULT NULL,
                status ENUM('in_progress','completed') DEFAULT NULL,
                submitted_answers LONGTEXT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // TABLE: topics
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS topics (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                topic_name VARCHAR(255) NOT NULL,
                subject_id INT NOT NULL,
                description TEXT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (subject_id) REFERENCES subjects(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            return $sql_statements;
        }
    ],
    // Version 1.2.1 - Missing tables, columns, and indexes from cbt.sql
    '1.2.1' => [
        'description' => 'Added missing tables (db_updates, student_subject_positions), missing columns in students table, and missing indexes from cbt.sql',
        'sql' => function ($pdo) {
            $sql_statements = [];

            // ============================================
            // MISSING TABLE: db_updates
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS db_updates (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                update_version VARCHAR(50) NOT NULL,
                update_name VARCHAR(100) NOT NULL,
                applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_update (update_version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // MISSING TABLE: student_subject_positions
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS student_subject_positions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                subject_id INT NOT NULL,
                session VARCHAR(20) NOT NULL,
                term VARCHAR(10) NOT NULL,
                subject_position INT DEFAULT NULL,
                created_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                KEY student_id (student_id),
                KEY subject_id (subject_id),
                KEY session (session),
                KEY term (term)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // MISSING COLUMNS IN students TABLE
            // ============================================

            // Add missing columns to students table
            addColumnIfNotExists($pdo, 'students', 'address', 'TEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'guardian_name', 'VARCHAR(255) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'guardian_phone', 'VARCHAR(20) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'date_of_admission', 'DATE DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'profile_picture', 'VARCHAR(255) DEFAULT NULL', $sql_statements);

            // Modify existing columns to match cbt.sql data types
            modifyColumnIfExists($pdo, 'students', 'parent_phone', 'VARCHAR(20) DEFAULT NULL', $sql_statements);
            modifyColumnIfExists($pdo, 'students', 'parent_email', 'VARCHAR(100) DEFAULT NULL', $sql_statements);

            // Update gender ENUM to match cbt.sql (Male,Female,Other vs M,F,Other)
            // First check if gender column exists and its current definition
            if (columnExists($pdo, 'students', 'gender')) {
                // Get current column definition
                $stmt = $pdo->prepare("SHOW COLUMNS FROM students WHERE Field = 'gender'");
                $stmt->execute();
                $colInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($colInfo && strpos($colInfo['Type'], 'Male') === false && strpos($colInfo['Type'], 'M') !== false) {
                    $sql_statements[] = "ALTER TABLE `students` MODIFY COLUMN `gender` ENUM('Male','Female','Other') DEFAULT NULL";
                }
            }

            // ============================================
            // MISSING INDEXES
            // ============================================

            // Add fk_students_class_id index to students table
            addIndexIfNotExists($pdo, 'students', 'fk_students_class_id', 'class_id', $sql_statements);

            // Add index for login_attempts
            addIndexIfNotExists($pdo, 'login_attempts', 'idx_username_time', 'username, attempt_time', $sql_statements);

            // Add index for password_resets
            addIndexIfNotExists($pdo, 'password_resets', 'idx_token', 'token', $sql_statements);
            addIndexIfNotExists($pdo, 'password_resets', 'idx_expires', 'expires_at', $sql_statements);

            // Add index for exam_assignments
            addIndexIfNotExists($pdo, 'exam_assignments', 'status', 'status', $sql_statements);
            addIndexIfNotExists($pdo, 'exam_assignments', 'assignment_type', 'assignment_type', $sql_statements);

            // Add index for exams table
            addIndexIfNotExists($pdo, 'exams', 'fk_exams_group_id', 'group_id', $sql_statements);

            // Add index for library_resources
            addIndexIfNotExists($pdo, 'library_resources', 'uploaded_by', 'uploaded_by', $sql_statements);

            // Add index for theory_questions
            addIndexIfNotExists($pdo, 'theory_questions', 'subject_id', 'subject_id', $sql_statements);
            addIndexIfNotExists($pdo, 'theory_questions', 'topic_id', 'topic_id', $sql_statements);

            // Add index for topics
            addIndexIfNotExists($pdo, 'topics', 'subject_id', 'subject_id', $sql_statements);

            // ============================================
            // FOREIGN KEY CONSTRAINTS
            // ============================================

            // Add foreign key for exam_assignments (if tables exist and constraint doesn't exist)
            if (tableExists($pdo, 'exam_assignments') && tableExists($pdo, 'students')) {
                addForeignKeyIfNotExists($pdo, 'exam_assignments', 'exam_assignments_ibfk_1', 'student_id', 'students', 'id', $sql_statements);
            }
            if (tableExists($pdo, 'exam_assignments') && tableExists($pdo, 'exams')) {
                addForeignKeyIfNotExists($pdo, 'exam_assignments', 'exam_assignments_ibfk_2', 'exam_id', 'exams', 'id', $sql_statements);
            }

            // Add foreign key for subject_classes
            if (tableExists($pdo, 'subject_classes') && tableExists($pdo, 'subjects')) {
                addForeignKeyIfNotExists($pdo, 'subject_classes', 'subject_classes_ibfk_1', 'subject_id', 'subjects', 'id', $sql_statements);
            }

            return $sql_statements;
        }
    ],
];

// Get current system version
$current_version = '1.2.1';

// Find pending migrations - ONLY those not already applied
$pending = [];
foreach ($migrations as $version => $migration) {
    if (!in_array($version, $applied)) {
        $pending[$version] = $migration;
    }
}

// Handle migration application
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    $version = $_POST['version'];

    if (isset($migrations[$version])) {
        $transactionStarted = false;
        try {
            // First, check if migration already applied
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM migrations WHERE version = ?");
            $checkStmt->execute([$version]);
            if ($checkStmt->fetchColumn() > 0) {
                $message = "Migration {$version} has already been applied.";
                $message_type = "info";
            } else {
                // Get SQL statements first (this might throw exceptions)
                $sql_statements = $migrations[$version]['sql']($pdo);

                // Start transaction only after we have valid SQL
                $pdo->beginTransaction();
                $transactionStarted = true;

                $executed = 0;

                foreach ($sql_statements as $statement) {
                    if (!empty($statement)) {
                        try {
                            $pdo->exec($statement);
                            $executed++;
                        } catch (PDOException $e) {
                            $errorMsg = $e->getMessage();
                            // Ignore "already exists" errors
                            if (
                                stripos($errorMsg, 'duplicate') !== false ||
                                stripos($errorMsg, 'already exists') !== false ||
                                stripos($errorMsg, 'duplicate key') !== false ||
                                stripos($errorMsg, 'already has') !== false ||
                                stripos($errorMsg, 'multiple primary key') !== false
                            ) {
                                $executed++;
                                continue;
                            }
                            throw $e;
                        }
                    }
                }

                // Insert migration record
                $stmt = $pdo->prepare("INSERT INTO migrations (version, description) VALUES (?, ?)");
                $stmt->execute([$version, $migrations[$version]['description']]);

                $pdo->commit();
                $transactionStarted = false;

                $message = "Migration {$version} applied successfully! ({$executed} changes)";
                $message_type = "success";

                // Refresh applied migrations
                $stmt = $pdo->query("SELECT version FROM migrations");
                $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $pending = [];
                foreach ($migrations as $v => $m) {
                    if (!in_array($v, $applied)) {
                        $pending[$v] = $m;
                    }
                }
            }
        } catch (Exception $e) {
            if ($transactionStarted) {
                try {
                    $pdo->rollBack();
                } catch (Exception $rollbackError) {
                    // Rollback failed
                }
            }
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Run all pending migrations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_all_migrations'])) {
    $all_success = true;
    $results = [];

    foreach ($pending as $version => $migration) {
        $transactionStarted = false;
        try {
            // Check if migration already applied
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM migrations WHERE version = ?");
            $checkStmt->execute([$version]);
            if ($checkStmt->fetchColumn() > 0) {
                $results[] = "⏭ {$version}: Already applied, skipping";
                continue;
            }

            // Get SQL statements first
            $sql_statements = $migration['sql']($pdo);

            // Start transaction
            $pdo->beginTransaction();
            $transactionStarted = true;

            $executed = 0;

            foreach ($sql_statements as $statement) {
                if (!empty($statement)) {
                    try {
                        $pdo->exec($statement);
                        $executed++;
                    } catch (PDOException $e) {
                        $errorMsg = $e->getMessage();
                        if (
                            stripos($errorMsg, 'duplicate') !== false ||
                            stripos($errorMsg, 'already exists') !== false ||
                            stripos($errorMsg, 'duplicate key') !== false ||
                            stripos($errorMsg, 'already has') !== false ||
                            stripos($errorMsg, 'multiple primary key') !== false
                        ) {
                            $executed++;
                            continue;
                        }
                        throw $e;
                    }
                }
            }

            $stmt = $pdo->prepare("INSERT INTO migrations (version, description) VALUES (?, ?)");
            $stmt->execute([$version, $migration['description']]);

            $pdo->commit();
            $transactionStarted = false;
            $results[] = "✓ {$version}: {$migration['description']} ({$executed} changes)";
        } catch (Exception $e) {
            if ($transactionStarted) {
                try {
                    $pdo->rollBack();
                } catch (Exception $rollbackError) {
                    // Rollback failed
                }
            }
            $all_success = false;
            $results[] = "✗ {$version}: Failed - " . $e->getMessage();
            break;
        }
    }

    if ($all_success) {
        $message = "All migrations applied successfully!<br>" . implode('<br>', $results);
        $message_type = "success";

        // Refresh applied migrations
        $stmt = $pdo->query("SELECT version FROM migrations");
        $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $pending = [];
        foreach ($migrations as $v => $m) {
            if (!in_array($v, $applied)) {
                $pending[$v] = $m;
            }
        }
    } else {
        $message = "Migrations failed:<br>" . implode('<br>', $results);
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration - CBT System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 20px;
        }

        .version-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: center;
        }

        .current-version {
            font-size: 24px;
            font-weight: bold;
            color: #3498db;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .migration-item {
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            margin-bottom: 15px;
            overflow: hidden;
        }

        .migration-header {
            background: #f8f9fa;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            cursor: pointer;
        }

        .migration-header:hover {
            background: #e9ecef;
        }

        .migration-version {
            font-weight: bold;
            color: #3498db;
            font-size: 16px;
        }

        .migration-desc {
            color: #666;
            font-size: 14px;
        }

        .migration-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-applied {
            background: #d4edda;
            color: #155724;
        }

        .migration-details {
            padding: 20px;
            border-top: 1px solid #e0e0e0;
            background: #fafafa;
            display: none;
        }

        .migration-details.show {
            display: block;
        }

        .sql-preview {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 11px;
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
            margin: 10px 0;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-success:hover {
            background: #219653;
            transform: translateY(-2px);
        }

        .btn-warning {
            background: #f39c12;
            color: white;
        }

        .btn-sm {
            padding: 5px 15px;
            font-size: 12px;
        }

        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #f39c12;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }

        .success-box {
            background: #d4edda;
            border-left: 4px solid #27ae60;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .card {
                padding: 20px;
            }

            .migration-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        .btn-back {
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
        }

        .btn-back:hover {
            background: #3498db;
            color: white;
            transform: translateY(-2px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .stat-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #3498db;
        }

        .stat-label {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card">
            <div class="header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1><i class="fas fa-database"></i> Database Migration Manager</h1>
                    <p>Complete database structure from cbt.sql - Safe and idempotent</p>
                </div>
                <a href="index.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="version-info">
                <h3>Current System Version</h3>
                <div class="current-version"><?php echo $current_version; ?></div>
                <p style="margin-top: 10px;">This migration includes ALL tables and columns from cbt.sql</p>
            </div>

            <div class="warning-box">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Important:</strong> Please backup your database before running migrations!
                <button class="btn btn-warning btn-sm" onclick="backupDatabase()" style="margin-left: 10px;">
                    <i class="fas fa-database"></i> Backup Database
                </button>
            </div>

            <?php
            // Get database statistics
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $total_tables = count($tables);

            $total_applied = count($applied);
            $total_pending = count($pending);
            ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_tables; ?></div>
                    <div class="stat-label">Total Tables</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_applied; ?></div>
                    <div class="stat-label">Applied Migrations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_pending; ?></div>
                    <div class="stat-label">Pending Migrations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">1.2.1</div>
                    <div class="stat-label">Latest Version</div>
                </div>
            </div>

            <h3><i class="fas fa-clock"></i> Pending Migrations</h3>

            <?php if (empty($pending)): ?>
                <div class="success-box">
                    <i class="fas fa-check-circle"></i>
                    <strong>Your database is fully up to date!</strong> All tables and columns from cbt.sql are present.
                </div>
            <?php else: ?>
                <p style="margin: 15px 0; color: #666;">
                    The following migrations need to be applied:
                </p>

                <form method="POST" style="margin-bottom: 20px;">
                    <input type="hidden" name="run_all_migrations" value="1">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Apply ALL pending migrations? This will create missing tables and add missing columns. Your data will be preserved.');">
                        <i class="fas fa-play"></i> Apply All Migrations
                    </button>
                </form>

                <?php foreach ($pending as $version => $migration): ?>
                    <div class="migration-item">
                        <div class="migration-header" onclick="toggleDetails('<?php echo $version; ?>')">
                            <div>
                                <span class="migration-version">Version <?php echo $version; ?></span>
                                <span class="migration-desc"> - <?php echo htmlspecialchars($migration['description']); ?></span>
                            </div>
                            <div>
                                <span class="migration-status status-pending">Pending</span>
                                <form method="POST" style="display: inline; margin-left: 10px;" onsubmit="return confirm('Apply migration <?php echo $version; ?>?');">
                                    <input type="hidden" name="run_migration" value="1">
                                    <input type="hidden" name="version" value="<?php echo $version; ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="fas fa-play"></i> Apply
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div id="details-<?php echo $version; ?>" class="migration-details">
                            <strong>Changes to apply:</strong>
                            <div class="sql-preview">
                                <pre><?php
                                        $sql_list = $migration['sql']($pdo);
                                        $preview_sql = array_slice($sql_list, 0, 20);
                                        foreach ($preview_sql as $sql) {
                                            echo htmlspecialchars($sql) . "\n\n";
                                        }
                                        if (count($sql_list) > 20) {
                                            echo "... and " . (count($sql_list) - 20) . " more statements\n";
                                        }
                                        ?></pre>
                            </div>
                            <div class="alert-info" style="margin-top: 10px; padding: 10px;">
                                <i class="fas fa-info-circle"></i>
                                <strong>Note:</strong> This migration includes <?php echo count($sql_list); ?> SQL statements that will create missing tables and add missing columns. Your existing data is safe.
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Migration History -->
            <h3 style="margin-top: 30px;"><i class="fas fa-history"></i> Migration History</h3>
            <div style="max-height: 300px; overflow-y: auto; margin-top: 15px;">
                <?php
                $stmt = $pdo->query("SELECT * FROM migrations ORDER BY id DESC");
                $history = $stmt->fetchAll();
                ?>
                <?php if (empty($history)): ?>
                    <p style="color: #999; text-align: center; padding: 20px;">No migrations applied yet.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Description</th>
                                <th>Applied At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $migration): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($migration['version']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($migration['description']); ?></td>
                                    <td><?php echo date('M d, Y H:i:s', strtotime($migration['applied_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Database Summary -->
            <h3 style="margin-top: 30px;"><i class="fas fa-chart-bar"></i> Database Summary</h3>
            <div style="margin-top: 15px;">
                <p><strong>Total Tables:</strong> <?php echo $total_tables; ?></p>
                <details>
                    <summary style="cursor: pointer; color: #3498db;">View all tables</summary>
                    <div style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 5px;">
                        <?php foreach ($tables as $table): ?>
                            <span style="background: #e9ecef; padding: 3px 10px; border-radius: 15px; font-size: 11px;">
                                <?php echo $table; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
        </div>
    </div>

    <script>
        function toggleDetails(version) {
            const details = document.getElementById('details-' + version);
            if (details) {
                details.classList.toggle('show');
            }
        }

        function backupDatabase() {
            if (confirm('Create a database backup?')) {
                window.location.href = 'backup.php?action=backup';
            }
        }
    </script>
</body>

</html>