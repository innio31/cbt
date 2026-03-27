<?php
// admin/db_update.php - Complete Database Migration with Column Checks
session_start();

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

require_once '../includes/config.php';

// Create migration tracking table if it doesn't exist
$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(50) NOT NULL,
    description TEXT,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_version (version)
)");

// Helper function to check if a column exists
function columnExists($pdo, $table, $column)
{
    try {
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
    if (!columnExists($pdo, $table, $column)) {
        $sql_statements[] = "ALTER TABLE `$table` ADD COLUMN $column $definition";
        return true;
    }
    return false;
}

// Helper function to add index if it doesn't exist
function addIndexIfNotExists($pdo, $table, $index, $columns, &$sql_statements)
{
    try {
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
// COMPLETE DATABASE MIGRATION
// Handles both new tables AND missing columns
// ============================================

$migrations = [
    // Version 1.0.0 - Complete database structure with column checks
    '1.0.0' => [
        'description' => 'Complete database structure - all tables and columns',
        'sql' => function ($pdo) {
            $sql_statements = [];

            // ============================================
            // ACTIVITY LOGS
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
            // ADMIN USERS
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
            // AFFECTIVE TRAITS
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
            // ASSIGNMENTS
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
            // ASSIGNMENT SUBMISSIONS
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
            // ATTENDANCE
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS attendance (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                date DATE NOT NULL,
                status ENUM('present', 'absent', 'late') DEFAULT 'present',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY (student_id, date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

            // ============================================
            // CENTRAL SETTINGS
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
            // EXAMS
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
            // EXAM ASSIGNMENTS
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
            // EXAM QUESTIONS
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
            // EXAM SESSIONS
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
            // EXAM SESSION QUESTIONS
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS exam_session_questions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                session_id INT DEFAULT NULL,
                question_id INT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                question_type ENUM('objective','theory') DEFAULT 'objective'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // LIBRARY RESOURCES
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
            // LOGIN ATTEMPTS
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS login_attempts (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                attempt_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_username_time (username, attempt_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // OBJECTIVE QUESTIONS
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
            // PASSAGES
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
            // PASSWORD RESETS
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS password_resets (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                user_type ENUM('student','staff','admin') NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                used TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_token (token),
                KEY idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // PSYCHOMOTOR SKILLS
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
            // REPORT CARD SETTINGS
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
            // RESULTS
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
                started_at DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // STAFF
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
            // STAFF CLASSES
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS staff_classes (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                staff_id VARCHAR(50) NOT NULL,
                class VARCHAR(50) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // STAFF SUBJECTS
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
            // STUDENTS - Main table with ALL columns
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
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                parent_phone VARCHAR(20) DEFAULT NULL,
                parent_email VARCHAR(100) DEFAULT NULL,
                archive_reason VARCHAR(255) DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci";

            // ============================================
            // STUDENT COMMENTS
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
            // STUDENT POSITIONS
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
            // STUDENT SCORES
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
            // SUBJECTIVE QUESTIONS
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
            // SUBJECTS
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
            // SUBJECT CLASSES
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS subject_classes (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                subject_id INT NOT NULL,
                class VARCHAR(50) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_subject_class (subject_id, class)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

            // ============================================
            // SUBJECT GROUPS
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
            // THEORY QUESTIONS
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
            // THEORY SESSIONS
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
            // TOPICS
            // ============================================
            $sql_statements[] = "CREATE TABLE IF NOT EXISTS topics (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                topic_name VARCHAR(255) NOT NULL,
                subject_id INT NOT NULL,
                description TEXT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            // ============================================
            // ADD FOREIGN KEYS (only if tables exist)
            // ============================================
            try {
                $pdo->exec("ALTER TABLE affective_traits ADD CONSTRAINT fk_affective_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE");
            } catch (Exception $e) {
            }

            try {
                $pdo->exec("ALTER TABLE attendance ADD CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE");
            } catch (Exception $e) {
            }

            try {
                $pdo->exec("ALTER TABLE exam_assignments ADD CONSTRAINT fk_exam_assignments_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE");
                $pdo->exec("ALTER TABLE exam_assignments ADD CONSTRAINT fk_exam_assignments_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE");
            } catch (Exception $e) {
            }

            try {
                $pdo->exec("ALTER TABLE psychomotor_skills ADD CONSTRAINT fk_psychomotor_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE");
            } catch (Exception $e) {
            }

            try {
                $pdo->exec("ALTER TABLE student_comments ADD CONSTRAINT fk_comments_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE");
            } catch (Exception $e) {
            }

            try {
                $pdo->exec("ALTER TABLE student_positions ADD CONSTRAINT fk_positions_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE");
            } catch (Exception $e) {
            }

            try {
                $pdo->exec("ALTER TABLE subject_classes ADD CONSTRAINT fk_subject_classes_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE");
            } catch (Exception $e) {
            }

            return $sql_statements;
        }
    ],

    // Version 1.1.0 - Add any missing columns to existing tables
    '1.1.0' => [
        'description' => 'Add missing columns to existing tables',
        'sql' => function ($pdo) {
            $sql_statements = [];

            // Add missing columns to students table
            addColumnIfNotExists($pdo, 'students', 'parent_phone', 'VARCHAR(20) AFTER full_name', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'parent_email', 'VARCHAR(100) AFTER parent_phone', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'archive_reason', 'VARCHAR(255) AFTER status', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'archived_at', 'DATETIME AFTER archive_reason', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'dob', 'DATE AFTER full_name', $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'gender', "ENUM('M','F','Other') AFTER dob", $sql_statements);
            addColumnIfNotExists($pdo, 'students', 'class_id', 'INT AFTER class', $sql_statements);

            // Add missing columns to staff table
            addColumnIfNotExists($pdo, 'staff', 'email', 'VARCHAR(255) AFTER profile_picture', $sql_statements);

            // Add missing columns to exams table
            addColumnIfNotExists($pdo, 'exams', 'duration_minutes', 'INT DEFAULT 60 AFTER exam_name', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'instructions', 'TEXT AFTER duration_minutes', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'exam_type', "ENUM('objective','subjective','theory') DEFAULT 'objective' AFTER instructions", $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'group_id', 'INT AFTER exam_type', $sql_statements);
            addColumnIfNotExists($pdo, 'exams', 'theory_display', "ENUM('combined','separate') DEFAULT 'separate' AFTER group_id", $sql_statements);

            // Add missing columns to results table
            addColumnIfNotExists($pdo, 'results', 'started_at', 'DATETIME AFTER total_score', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'completed_at', 'DATETIME AFTER started_at', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'correct_count', 'INT DEFAULT 0 AFTER time_taken', $sql_statements);
            addColumnIfNotExists($pdo, 'results', 'total_questions', 'INT DEFAULT 0 AFTER correct_count', $sql_statements);

            // Add missing columns to exam_sessions
            addColumnIfNotExists($pdo, 'exam_sessions', 'exam_type', "ENUM('objective','subjective','theory') DEFAULT 'objective'", $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'score', 'DECIMAL(5,2) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'percentage', 'DECIMAL(5,2) DEFAULT NULL', $sql_statements);
            addColumnIfNotExists($pdo, 'exam_sessions', 'grade', 'VARCHAR(10) DEFAULT NULL', $sql_statements);

            return $sql_statements;
        }
    ],

    // Version 1.2.0 - Add indexes for performance
    '1.2.0' => [
        'description' => 'Add performance indexes',
        'sql' => function ($pdo) {
            $sql_statements = [];

            addIndexIfNotExists($pdo, 'students', 'idx_student_class', 'class', $sql_statements);
            addIndexIfNotExists($pdo, 'students', 'idx_student_status', 'status', $sql_statements);
            addIndexIfNotExists($pdo, 'exams', 'idx_exam_class', 'class', $sql_statements);
            addIndexIfNotExists($pdo, 'exams', 'idx_exam_active', 'is_active', $sql_statements);
            addIndexIfNotExists($pdo, 'results', 'idx_result_student', 'student_id', $sql_statements);
            addIndexIfNotExists($pdo, 'results', 'idx_result_exam', 'exam_id', $sql_statements);
            addIndexIfNotExists($pdo, 'exam_sessions', 'idx_session_student', 'student_id', $sql_statements);
            addIndexIfNotExists($pdo, 'exam_sessions', 'idx_session_exam', 'exam_id', $sql_statements);
            addIndexIfNotExists($pdo, 'attendance', 'idx_attendance_student_date', 'student_id, date', $sql_statements);

            return $sql_statements;
        }
    ],
];

// Get current system version
$current_version = defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '1.0.0';

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
        try {
            $pdo->beginTransaction();

            $sql_statements = $migrations[$version]['sql']($pdo);
            $executed = 0;

            foreach ($sql_statements as $statement) {
                if (!empty($statement)) {
                    try {
                        $pdo->exec($statement);
                        $executed++;
                    } catch (PDOException $e) {
                        // Ignore "already exists" errors
                        if (
                            strpos($e->getMessage(), 'Duplicate') === false &&
                            strpos($e->getMessage(), 'already exists') === false &&
                            strpos($e->getMessage(), 'Duplicate key') === false
                        ) {
                            throw $e;
                        } else {
                            $executed++;
                        }
                    }
                }
            }

            $stmt = $pdo->prepare("INSERT INTO migrations (version, description) VALUES (?, ?)");
            $stmt->execute([$version, $migrations[$version]['description']]);

            $pdo->commit();

            $message = "Migration {$version} applied successfully! ({$executed} changes)";
            $message_type = "success";

            // Refresh
            $stmt = $pdo->query("SELECT version FROM migrations");
            $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $pending = [];
            foreach ($migrations as $v => $m) {
                if (!in_array($v, $applied) && version_compare($v, $current_version, '<=')) {
                    $pending[$v] = $m;
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
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
        try {
            $pdo->beginTransaction();

            $sql_statements = $migration['sql']($pdo);
            $executed = 0;

            foreach ($sql_statements as $statement) {
                if (!empty($statement)) {
                    try {
                        $pdo->exec($statement);
                        $executed++;
                    } catch (PDOException $e) {
                        if (
                            strpos($e->getMessage(), 'Duplicate') === false &&
                            strpos($e->getMessage(), 'already exists') === false &&
                            strpos($e->getMessage(), 'Duplicate key') === false
                        ) {
                            throw $e;
                        }
                        $executed++;
                    }
                }
            }

            $stmt = $pdo->prepare("INSERT INTO migrations (version, description) VALUES (?, ?)");
            $stmt->execute([$version, $migration['description']]);

            $pdo->commit();
            $results[] = "✓ {$version}: {$migration['description']}";
        } catch (Exception $e) {
            $pdo->rollBack();
            $all_success = false;
            $results[] = "✗ {$version}: Failed - " . $e->getMessage();
            break;
        }
    }

    if ($all_success) {
        $message = "All migrations applied successfully!<br>" . implode('<br>', $results);
        $message_type = "success";

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
            max-width: 1000px;
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
            max-height: 300px;
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

        .missing-columns {
            background: #fff3cd;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            font-size: 12px;
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
        }

        .btn-back:hover {
            background: #3498db;
            color: white;
            transform: translateY(-2px);
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card">
            <div class="header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h1><i class="fas fa-database"></i> Database Migration Manager</h1>
                    <p>Ensures ALL tables exist AND all required columns are present</p>
                </div>
                <a href="index.php" class="btn-back" style="background: rgba(52, 152, 219, 0.2); color: #3498db; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
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
                <p style="margin-top: 10px;">This migration will create missing tables AND add missing columns</p>
            </div>

            <div class="warning-box">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Important:</strong> Please backup your database before running migrations!
                <button class="btn btn-warning btn-sm" onclick="backupDatabase()" style="margin-left: 10px;">
                    <i class="fas fa-database"></i> Backup Database
                </button>
            </div>

            <h3><i class="fas fa-clock"></i> Pending Migrations</h3>

            <?php if (empty($pending)): ?>
                <div class="success-box">
                    <i class="fas fa-check-circle"></i>
                    <strong>Your database is fully up to date!</strong> All tables and columns are present.
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
                                        foreach ($sql_list as $sql) {
                                            echo htmlspecialchars($sql) . "\n\n";
                                        }
                                        ?></pre>
                            </div>
                            <div class="alert-info" style="margin-top: 10px; padding: 10px;">
                                <i class="fas fa-info-circle"></i>
                                <strong>Note:</strong> This uses "IF NOT EXISTS" and will only add missing structure. Your existing data is safe.
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
            <?php
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $total_tables = count($tables);
            ?>
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
            details.classList.toggle('show');
        }

        function backupDatabase() {
            if (confirm('Create a database backup?')) {
                window.location.href = 'backup.php?action=backup';
            }
        }
    </script>
</body>

</html>