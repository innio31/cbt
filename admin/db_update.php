<?php
// admin/db_update.php - Complete Database Migration with Column Checks
session_start();

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'admin') {
    header("Location: ../login.php");
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
            $sql_statements[] = "CREATE INDEX `$index` ON `$table` ($columns)";
            return true;
        }
    } catch (PDOException $e) {
        // Table might not exist yet
    }
    return false;
}

// Get applied migrations
$stmt = $pdo->query("SELECT version FROM migrations");
$applied = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ============================================
// COMPLETE DATABASE MIGRATION - Version 1.2.0
// Includes ALL tables and columns from cbt.sql
// ============================================

$migrations = [
    // Version 1.2.0 - Complete database structure with all tables and columns
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

            // Add missing columns to activity_logs
            addColumnIfNotExists($pdo, 'activity_logs', 'user_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'activity_logs', 'user_type', "ENUM('student','admin','staff') NOT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'activity_logs', 'activity', 'TEXT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'activity_logs', 'ip_address', 'VARCHAR(45) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'activity_logs', 'user_agent', 'TEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'activity_logs', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'admin_users', 'username', 'VARCHAR(50) NOT NULL UNIQUE', $sql_statements);
            addColumnIfNotExists($pdo, 'admin_users', 'password', 'VARCHAR(255) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'admin_users', 'full_name', 'VARCHAR(100) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'admin_users', 'role', "ENUM('super_admin','admin','teacher') DEFAULT 'admin'", $sql_statements);
            addColumnIfNotExists($pdo, 'admin_users', 'status', "ENUM('active','inactive') DEFAULT 'active'", $sql_statements);
            addColumnIfNotExists($pdo, 'admin_users', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            addColumnIfNotExists($pdo, 'affective_traits', 'student_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'affective_traits', 'session', 'VARCHAR(20) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'affective_traits', 'term', 'VARCHAR(20) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'affective_traits', 'punctuality', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'affective_traits', 'attendance', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'affective_traits', 'politeness', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'affective_traits', 'honesty', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'affective_traits', 'neatness', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'affective_traits', 'reliability', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'affective_traits', 'relationship', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'affective_traits', 'self_control', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'affective_traits', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'affective_traits', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $sql_statements);

            // Add unique index
            $sql_statements[] = "ALTER TABLE affective_traits ADD UNIQUE INDEX unique_student_session_term (student_id, session, term)";

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

            addColumnIfNotExists($pdo, 'assignments', 'title', 'VARCHAR(255) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignments', 'subject_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignments', 'class', 'VARCHAR(50) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignments', 'instructions', 'TEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignments', 'file_path', 'VARCHAR(255) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignments', 'deadline', 'DATETIME DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignments', 'max_marks', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignments', 'staff_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignments', 'created_by', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignments', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'assignment_submissions', 'student_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignment_submissions', 'assignment_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignment_submissions', 'submitted_text', 'TEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignment_submissions', 'file_path', 'VARCHAR(500) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignment_submissions', 'submitted_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'assignment_submissions', 'status', "ENUM('submitted','graded') DEFAULT 'submitted'", $sql_statements);
            addColumnIfNotExists($pdo, 'assignment_submissions', 'grade', 'VARCHAR(10) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignment_submissions', 'teacher_feedback', 'TEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'assignment_submissions', 'graded_at', 'TIMESTAMP NULL DEFAULT NULL', $sql_statements);

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

            addColumnIfNotExists($pdo, 'attendance', 'student_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'attendance', 'date', 'DATE NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'attendance', 'status', "ENUM('present', 'absent', 'late') DEFAULT 'present'", $sql_statements);
            addColumnIfNotExists($pdo, 'attendance', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'central_settings', 'central_url', 'VARCHAR(255) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'central_settings', 'api_key', 'VARCHAR(100) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'central_settings', 'school_code', 'VARCHAR(50) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'central_settings', 'auto_sync', 'TINYINT(1) DEFAULT 1', $sql_statements);
            addColumnIfNotExists($pdo, 'central_settings', 'sync_interval', 'INT DEFAULT 86400', $sql_statements);
            addColumnIfNotExists($pdo, 'central_settings', 'last_sync', 'TIMESTAMP NULL DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'central_settings', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'central_settings', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'exams', 'exam_name', 'VARCHAR(100) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'class', 'VARCHAR(50) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'subject_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'topics', 'LONGTEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'objective_count', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'subjective_count', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'theory_count', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'duration_minutes', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'objective_duration', 'INT DEFAULT 60', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'theory_duration', 'INT DEFAULT 60', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'subjective_duration', 'INT DEFAULT 60', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'is_active', 'TINYINT(1) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'instructions', 'TEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'created_at', 'DATETIME DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'exam_type', "ENUM('objective','subjective','theory') DEFAULT 'objective'", $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'group_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'theory_display', "ENUM('combined','separate') DEFAULT 'separate'", $sql_statements);

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

            addColumnIfNotExists($pdo, 'exam_assignments', 'student_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_assignments', 'exam_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_assignments', 'assigned_by', 'INT NOT NULL COMMENT \'Staff ID\'', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_assignments', 'assignment_type', "ENUM('immediate','scheduled') DEFAULT 'immediate'", $sql_statements);
            addColumnIfNotExists($pdo, 'exam_assignments', 'start_date', 'DATETIME DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_assignments', 'end_date', 'DATETIME DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_assignments', 'status', "ENUM('assigned','in_progress','completed','expired') DEFAULT 'assigned'", $sql_statements);
            addColumnIfNotExists($pdo, 'exam_assignments', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'exam_questions', 'question_text', 'TEXT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_questions', 'option_a', 'VARCHAR(255) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_questions', 'option_b', 'VARCHAR(255) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_questions', 'option_c', 'VARCHAR(255) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_questions', 'option_d', 'VARCHAR(255) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_questions', 'correct_answer', 'CHAR(1) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_questions', 'subject_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_questions', 'topic_id', 'INT NOT NULL', $sql_statements);

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

            addColumnIfNotExists($pdo, 'exam_sessions', 'student_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'exam_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'exam_type', "ENUM('objective','subjective','theory') DEFAULT 'objective'", $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'start_time', 'DATETIME DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'end_time', 'DATETIME DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'status', "ENUM('in_progress','completed') DEFAULT 'in_progress'", $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'objective_answers', 'LONGTEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'score', 'DECIMAL(5,2) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'correct_answers', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'total_questions', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'submitted_at', 'DATETIME DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'percentage', 'DECIMAL(5,2) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'grade', 'VARCHAR(10) DEFAULT NULL', $sql_statements);

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

            addColumnIfNotExists($pdo, 'exam_session_questions', 'session_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_session_questions', 'question_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_session_questions', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_session_questions', 'question_type', "ENUM('objective','theory') DEFAULT 'objective'", $sql_statements);

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

            addColumnIfNotExists($pdo, 'library_resources', 'title', 'VARCHAR(255) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'library_resources', 'subject', 'VARCHAR(100) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'library_resources', 'class', 'VARCHAR(50) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'library_resources', 'file_type', 'VARCHAR(50) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'library_resources', 'file_path', 'VARCHAR(500) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'library_resources', 'file_size', 'VARCHAR(50) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'library_resources', 'uploaded_by', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'library_resources', 'uploaded_by_type', "VARCHAR(20) DEFAULT 'staff'", $sql_statements);
            addColumnIfNotExists($pdo, 'library_resources', 'uploaded_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'login_attempts', 'username', 'VARCHAR(100) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'login_attempts', 'success', 'TINYINT(1) NOT NULL DEFAULT 0', $sql_statements);
            addColumnIfNotExists($pdo, 'login_attempts', 'ip_address', 'VARCHAR(45) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'login_attempts', 'user_agent', 'TEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'login_attempts', 'attempt_time', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'objective_questions', 'question_text', 'MEDIUMTEXT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'option_a', 'MEDIUMTEXT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'option_b', 'MEDIUMTEXT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'option_c', 'MEDIUMTEXT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'option_d', 'MEDIUMTEXT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'correct_answer', 'CHAR(1) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'subject_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'topic_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'difficulty_level', "ENUM('easy','medium','hard') DEFAULT 'medium'", $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'marks', 'INT DEFAULT 1', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'class', 'VARCHAR(50) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'question_image', 'VARCHAR(255) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'passage_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'objective_questions', 'gap_number', 'INT DEFAULT NULL', $sql_statements);

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

            addColumnIfNotExists($pdo, 'passages', 'passage_text', 'TEXT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'passages', 'title', 'VARCHAR(255) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'passages', 'subject_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'passages', 'topic_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'passages', 'class', 'VARCHAR(50) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'passages', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'password_resets', 'user_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'password_resets', 'user_type', "ENUM('student','staff','admin') NOT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'password_resets', 'token', 'VARCHAR(64) NOT NULL UNIQUE', $sql_statements);
            addColumnIfNotExists($pdo, 'password_resets', 'expires_at', 'DATETIME NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'password_resets', 'used', 'TINYINT(1) DEFAULT 0', $sql_statements);
            addColumnIfNotExists($pdo, 'password_resets', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            addColumnIfNotExists($pdo, 'psychomotor_skills', 'student_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'psychomotor_skills', 'session', 'VARCHAR(20) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'psychomotor_skills', 'term', "ENUM('First','Second','Third') NOT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'psychomotor_skills', 'handwriting', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'psychomotor_skills', 'verbal_fluency', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'psychomotor_skills', 'sports', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'psychomotor_skills', 'handling_tools', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'psychomotor_skills', 'drawing_painting', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'psychomotor_skills', 'musical_skills', "ENUM('A','B','C','D','E') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'psychomotor_skills', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'psychomotor_skills', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $sql_statements);

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
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            addColumnIfNotExists($pdo, 'report_card_settings', 'session', 'VARCHAR(20) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'term', "ENUM('First','Second','Third') NOT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'template', "VARCHAR(50) DEFAULT 'default'", $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'class', 'VARCHAR(50) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'max_score', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'score_types', 'JSON NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'grading_system', "VARCHAR(20) DEFAULT 'simple'", $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'next_resumption_date', 'DATE DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'current_resumption_date', 'DATE DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'current_closing_date', 'DATE DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'days_school_opened', 'INT DEFAULT 90', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'show_class_position', 'TINYINT(1) DEFAULT 1', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'show_subject_position', 'TINYINT(1) DEFAULT 1', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'show_promoted_to', 'TINYINT(1) DEFAULT 1', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'show_lowest_highest_avg', 'TINYINT(1) DEFAULT 1', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'show_lowest_highest_class', 'TINYINT(1) DEFAULT 1', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'report_card_settings', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $sql_statements);

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
                total_questions INT DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            addColumnIfNotExists($pdo, 'results', 'student_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'exam_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'objective_score', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'theory_score', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'total_score', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'percentage', 'DECIMAL(5,2) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'grade', 'VARCHAR(5) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'time_taken', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'submitted_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'correct_count', 'INT DEFAULT 0', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'total_questions', 'INT DEFAULT 0', $sql_statements);

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

            addColumnIfNotExists($pdo, 'staff', 'staff_id', 'VARCHAR(50) NOT NULL UNIQUE', $sql_statements);
            addColumnIfNotExists($pdo, 'staff', 'password', 'VARCHAR(255) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'staff', 'full_name', 'VARCHAR(100) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'staff', 'role', "ENUM('staff','admin') DEFAULT 'staff'", $sql_statements);
            addColumnIfNotExists($pdo, 'staff', 'is_active', 'TINYINT(1) DEFAULT 1', $sql_statements);
            addColumnIfNotExists($pdo, 'staff', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'staff', 'profile_picture', 'VARCHAR(255) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'staff', 'email', 'VARCHAR(255) DEFAULT NULL', $sql_statements);

            // ============================================
            // TABLE: staff_classes
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS staff_classes (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                staff_id VARCHAR(50) NOT NULL,
                class VARCHAR(50) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            addColumnIfNotExists($pdo, 'staff_classes', 'staff_id', 'VARCHAR(50) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'staff_classes', 'class', 'VARCHAR(50) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'staff_classes', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'staff_subjects', 'staff_id', 'VARCHAR(50) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'staff_subjects', 'subject_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'staff_subjects', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'staff_subjects', 'updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'staff_subjects', 'last_sync', 'TIMESTAMP NULL DEFAULT NULL', $sql_statements);

            // ============================================
            // TABLE: students
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS students (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                admission_number VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                class VARCHAR(50) NOT NULL,
                class_id INT DEFAULT NULL,
                status ENUM('active','inactive') DEFAULT 'active',
                full_name VARCHAR(100) NOT NULL,
                dob DATE DEFAULT NULL,
                gender ENUM('M','F','Other') DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            addColumnIfNotExists($pdo, 'students', 'admission_number', 'VARCHAR(50) NOT NULL UNIQUE', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'password', 'VARCHAR(255) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'class', 'VARCHAR(50) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'class_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'status', "ENUM('active','inactive') DEFAULT 'active'", $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'full_name', 'VARCHAR(100) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'dob', 'DATE DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'gender', "ENUM('M','F','Other') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            addColumnIfNotExists($pdo, 'student_comments', 'student_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_comments', 'session', 'VARCHAR(20) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_comments', 'term', "ENUM('First','Second','Third') NOT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'student_comments', 'teachers_comment', 'TEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_comments', 'principals_comment', 'TEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_comments', 'class_teachers_name', 'VARCHAR(255) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_comments', 'principals_name', 'VARCHAR(255) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_comments', 'days_present', 'INT DEFAULT 0', $sql_statements);
            addColumnIfNotExists($pdo, 'student_comments', 'days_absent', 'INT DEFAULT 0', $sql_statements);
            addColumnIfNotExists($pdo, 'student_comments', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'student_comments', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $sql_statements);

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
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            addColumnIfNotExists($pdo, 'student_positions', 'student_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_positions', 'session', 'VARCHAR(20) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_positions', 'term', "ENUM('First','Second','Third') NOT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'student_positions', 'class_position', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_positions', 'total_marks', 'DECIMAL(8,2) DEFAULT 0.00', $sql_statements);
            addColumnIfNotExists($pdo, 'student_positions', 'average', 'DECIMAL(5,2) DEFAULT 0.00', $sql_statements);
            addColumnIfNotExists($pdo, 'student_positions', 'promoted_to', 'VARCHAR(50) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_positions', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'student_positions', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'student_scores', 'student_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_scores', 'subject_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_scores', 'subject_name', 'VARCHAR(255) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_scores', 'session', 'VARCHAR(20) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_scores', 'term', "ENUM('First','Second','Third') NOT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'student_scores', 'score_data', 'JSON NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_scores', 'total_score', 'DECIMAL(8,2) DEFAULT 0.00', $sql_statements);
            addColumnIfNotExists($pdo, 'student_scores', 'percentage', 'DECIMAL(5,2) DEFAULT 0.00', $sql_statements);
            addColumnIfNotExists($pdo, 'student_scores', 'grade', 'VARCHAR(5) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'student_scores', 'subject_position', 'INT DEFAULT NULL', $sql_statements);

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

            addColumnIfNotExists($pdo, 'subjective_questions', 'question_text', 'TEXT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'subjective_questions', 'correct_answer', 'VARCHAR(500) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'subjective_questions', 'difficulty_level', "ENUM('easy','medium','hard') DEFAULT 'medium'", $sql_statements);
            addColumnIfNotExists($pdo, 'subjective_questions', 'marks', 'INT DEFAULT 1', $sql_statements);
            addColumnIfNotExists($pdo, 'subjective_questions', 'subject_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'subjective_questions', 'topic_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'subjective_questions', 'class', 'VARCHAR(50) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'subjective_questions', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'subjects', 'subject_name', 'VARCHAR(100) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'subjects', 'description', 'MEDIUMTEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'subjects', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'subjects', 'updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $sql_statements);
            addColumnIfNotExists($pdo, 'subjects', 'last_sync', 'TIMESTAMP NULL DEFAULT NULL', $sql_statements);

            // ============================================
            // TABLE: subject_classes
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS subject_classes (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                subject_id INT NOT NULL,
                class VARCHAR(50) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            addColumnIfNotExists($pdo, 'subject_classes', 'subject_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'subject_classes', 'class', 'VARCHAR(50) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'subject_classes', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'subject_groups', 'group_name', 'VARCHAR(255) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'subject_groups', 'description', 'TEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'subject_groups', 'total_duration_minutes', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'subject_groups', 'is_active', 'TINYINT(1) DEFAULT 1', $sql_statements);
            addColumnIfNotExists($pdo, 'subject_groups', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'theory_questions', 'question_file', 'VARCHAR(255) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'theory_questions', 'question_text', 'TEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'theory_questions', 'subject_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'theory_questions', 'topic_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'theory_questions', 'class', 'VARCHAR(50) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'theory_questions', 'marks', 'INT DEFAULT 5', $sql_statements);
            addColumnIfNotExists($pdo, 'theory_questions', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

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

            addColumnIfNotExists($pdo, 'theory_sessions', 'student_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'theory_sessions', 'exam_id', 'INT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'theory_sessions', 'start_time', 'DATETIME DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'theory_sessions', 'end_time', 'DATETIME DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'theory_sessions', 'status', "ENUM('in_progress','completed') DEFAULT NULL", $sql_statements);
            addColumnIfNotExists($pdo, 'theory_sessions', 'submitted_answers', 'LONGTEXT DEFAULT NULL', $sql_statements);

            // ============================================
            // TABLE: topics
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS topics (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                topic_name VARCHAR(255) NOT NULL,
                subject_id INT NOT NULL,
                description TEXT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            addColumnIfNotExists($pdo, 'topics', 'topic_name', 'VARCHAR(255) NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'topics', 'subject_id', 'INT NOT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'topics', 'description', 'TEXT DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'topics', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP', $sql_statements);

            // ============================================
            // ADD INDEXES
            // ============================================
            addIndexIfNotExists($pdo, 'students', 'idx_student_class', 'class', $sql_statements);
            addIndexIfNotExists($pdo, 'students', 'idx_student_status', 'status', $sql_statements);
            addIndexIfNotExists($pdo, 'exams', 'idx_exam_class', 'class', $sql_statements);
            addIndexIfNotExists($pdo, 'exams', 'idx_exam_active', 'is_active', $sql_statements);
            addIndexIfNotExists($pdo, 'results', 'idx_result_student', 'student_id', $sql_statements);
            addIndexIfNotExists($pdo, 'results', 'idx_result_exam', 'exam_id', $sql_statements);
            addIndexIfNotExists($pdo, 'exam_sessions', 'idx_session_student', 'student_id', $sql_statements);
            addIndexIfNotExists($pdo, 'exam_sessions', 'idx_session_exam', 'exam_id', $sql_statements);
            addIndexIfNotExists($pdo, 'attendance', 'idx_attendance_student_date', 'student_id, date', $sql_statements);
            addIndexIfNotExists($pdo, 'login_attempts', 'idx_username_time', 'username, attempt_time', $sql_statements);
            addIndexIfNotExists($pdo, 'password_resets', 'idx_token', 'token', $sql_statements);
            addIndexIfNotExists($pdo, 'password_resets', 'idx_expires', 'expires_at', $sql_statements);

            return $sql_statements;
        }
    ],
];

// Get current system version
$current_version = defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '1.2.0';

// Find pending migrations
$pending = [];
foreach ($migrations as $version => $migration) {
    if (!in_array($version, $applied) && version_compare($version, $current_version, '<=')) {
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
                $errors = [];

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
                            $errors[] = $errorMsg;
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
                    if (!in_array($v, $applied) && version_compare($v, $current_version, '<=')) {
                        $pending[$v] = $m;
                    }
                }
            }
        } catch (Exception $e) {
            if ($transactionStarted) {
                try {
                    $pdo->rollBack();
                } catch (Exception $rollbackError) {
                    // Rollback failed, but we already have an error
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
            $errors = [];

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
                        $errors[] = $errorMsg;
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
            if (!in_array($v, $applied) && version_compare($v, $current_version, '<=')) {
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
                    <div class="stat-number">1.2.0</div>
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