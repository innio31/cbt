<?php

/**
 * Central Question Bank Sync Engine
 * This file handles all communication with the central server
 */

require_once 'config.php';

class CentralSync
{
    private $central_url;
    private $api_key;
    private $school_code;
    private $pdo;
    private $log_file;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->log_file = dirname(__DIR__) . '/logs/sync_log.txt';

        // Load central settings from database or config
        $this->loadSettings();
    }

    /**
     * Load central sync settings
     */
    private function loadSettings()
    {
        // Try to load from database first
        try {
            $stmt = $this->pdo->query("SELECT * FROM central_settings LIMIT 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($settings) {
                $this->central_url = rtrim($settings['central_url'], '/');
                $this->api_key = $settings['api_key'];
                $this->school_code = $settings['school_code'];
                return;
            }
        } catch (PDOException $e) {
            // Table might not exist, use config constants
        }

        // Fallback to constants from config.php
        $this->central_url = defined('CENTRAL_URL') ? CENTRAL_URL : 'https://your-central-domain.com/api';
        $this->api_key = defined('CENTRAL_API_KEY') ? CENTRAL_API_KEY : '';
        $this->school_code = defined('SCHOOL_CODE') ? SCHOOL_CODE : '';
    }

    /**
     * Log sync activity
     */
    private function log($message, $type = 'INFO')
    {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] [$type] $message" . PHP_EOL;

        // Ensure log directory exists
        $log_dir = dirname($this->log_file);
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0777, true);
        }

        file_put_contents($this->log_file, $log_entry, FILE_APPEND);

        // Also log to PHP error log for debugging
        error_log("CentralSync: $message");
    }

    /**
     * Make API call to central server
     */
    private function callAPI($endpoint, $params = [])
    {
        if (empty($this->api_key)) {
            $this->log("API key not configured", 'ERROR');
            return ['error' => 'API key not configured'];
        }

        // Build the base URL
        $url = $this->central_url . '/' . ltrim($endpoint, '/');

        // Add api_key as URL parameter (since header doesn't work)
        if (strpos($url, '?') !== false) {
            $url .= '&api_key=' . urlencode($this->api_key);
        } else {
            $url .= '?api_key=' . urlencode($this->api_key);
        }

        // Add any additional parameters
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $url .= '&' . urlencode($key) . '=' . urlencode($value);
            }
        }

        // Log the URL (hiding the API key for security)
        $log_url = str_replace($this->api_key, 'HIDDEN', $url);
        $this->log("Calling API: $log_url");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false, // Set to true in production
            CURLOPT_USERAGENT => 'School-CBT/1.0',
            CURLOPT_FOLLOWLOCATION => true // Follow redirects if any
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log("CURL Error: $error", 'ERROR');
            return ['error' => "Connection failed: $error"];
        }

        if ($http_code != 200) {
            $this->log("HTTP Error: $http_code - $response", 'ERROR');

            // Try to parse error response
            $error_data = json_decode($response, true);
            $error_message = isset($error_data['error']) ? $error_data['error'] : "Server returned HTTP $http_code";

            // Add specific messages for common HTTP codes
            if ($http_code == 401) {
                $error_message = "Invalid API key - Check your API key in central settings";
            } elseif ($http_code == 403) {
                $error_message = "Access forbidden - Your subscription may have expired";
            } elseif ($http_code == 404) {
                $error_message = "API endpoint not found - Check central server URL";
            } elseif ($http_code == 500) {
                $error_message = "Central server error - Contact administrator";
            }

            return ['error' => $error_message, 'http_code' => $http_code];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON Parse Error: " . json_last_error_msg(), 'ERROR');
            return ['error' => 'Invalid JSON response'];
        }

        return $data;
    }

    /**
     * Test connection to central server
     */
    public function testConnection()
    {
        $this->log("Testing connection to central server");

        // Use the correct endpoint - note we don't need to add api_key here 
        // because callAPI already adds it
        $result = $this->callAPI('?action=auth');

        if (isset($result['status']) && $result['status'] === 'success') {
            $this->log("Connection successful: " . ($result['school']['name'] ?? 'Unknown'));
            return ['success' => true, 'data' => $result];
        }

        return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
    }

    /**
     * Get list of subjects from central
     */
    public function getSubjects()
    {
        $this->log("Fetching subjects from central");

        // Remove the ?action=get_subjects part - let callAPI handle it
        $result = $this->callAPI('?action=get_subjects');

        if (isset($result['status']) && $result['status'] === 'success') {
            return ['success' => true, 'subjects' => $result['subjects']];
        }

        return ['success' => false, 'error' => $result['error'] ?? 'Failed to fetch subjects'];
    }

    /**
     * Get topics for a subject
     */
    public function getTopics($subject_id = null)
    {
        $endpoint = '?action=get_topics';
        if ($subject_id) {
            $endpoint .= '&subject_id=' . urlencode($subject_id);
        }

        $result = $this->callAPI($endpoint);

        if (isset($result['status']) && $result['status'] === 'success') {
            return ['success' => true, 'topics' => $result['topics']];
        }

        return ['success' => false, 'error' => $result['error'] ?? 'Failed to fetch topics'];
    }

    /**
     * Pull questions from central server with mapping to local subject/topic
     */
    public function pullQuestions($params = [], $target_subject_id = null, $target_topic_id = null)
    {
        $this->log("Pulling questions with params: " . json_encode($params));

        // Build query parameters
        $query_params = ['action' => 'get_questions'];
        $allowed_params = ['subject_id', 'topic_id', 'class', 'difficulty', 'limit', 'type', 'question_ids'];

        foreach ($allowed_params as $param) {
            if (isset($params[$param])) {
                $query_params[$param] = $params[$param];
            }
        }

        $result = $this->callAPI('?' . http_build_query($query_params));

        if (isset($result['status']) && $result['status'] === 'success') {
            $saved = $this->saveQuestionsLocally($result, $params, $target_subject_id, $target_topic_id);
            return array_merge($result, ['local_save' => $saved]);
        }

        return ['success' => false, 'error' => $result['error'] ?? 'Failed to pull questions'];
    }

    /**
     * Preview questions without saving
     */
    public function previewQuestions($params = [])
    {
        $this->log("Previewing questions with params: " . json_encode($params));

        // Build query parameters
        $query_params = ['action' => 'get_questions'];

        // Map our params to what the API expects
        if (isset($params['subject_id']) && $params['subject_id'] > 0) {
            $query_params['subject_id'] = $params['subject_id'];
        }
        if (isset($params['topic_id']) && $params['topic_id'] > 0) {
            $query_params['topic_id'] = $params['topic_id'];
        }
        if (isset($params['class']) && !empty($params['class'])) {
            $query_params['class'] = $params['class'];
        }
        if (isset($params['difficulty']) && !empty($params['difficulty'])) {
            $query_params['difficulty'] = $params['difficulty'];
        }
        if (isset($params['limit']) && $params['limit'] > 0) {
            $query_params['limit'] = min($params['limit'], 100);
        }
        if (isset($params['type']) && !empty($params['type'])) {
            $query_params['type'] = $params['type'];
        }

        // Build the endpoint
        $endpoint = '?' . http_build_query($query_params);
        $this->log("Calling endpoint: " . $endpoint);

        $result = $this->callAPI($endpoint);

        // Log the raw result
        $this->log("Raw API response: " . print_r($result, true));

        if (isset($result['status']) && $result['status'] === 'success') {
            // Add info about which questions already exist locally
            if (isset($result['objective']['questions'])) {
                foreach ($result['objective']['questions'] as &$q) {
                    $q['exists_locally'] = $this->questionExists($q['id'], 'objective');
                }
            }
            if (isset($result['theory']['questions'])) {
                foreach ($result['theory']['questions'] as &$q) {
                    $q['exists_locally'] = $this->questionExists($q['id'], 'theory');
                }
            }
            return $result;
        }

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return ['error' => 'Failed to preview questions - unexpected response format'];
    }

    /**
     * Save pulled questions to local database with mapping
     */
    private function saveQuestionsLocally($data, $pull_params, $target_subject_id = null, $target_topic_id = null)
    {
        $this->log("Saving questions locally");

        $saved_count = 0;
        $batch_id = $data['batch_id'] ?? date('YmdHis');

        try {
            // Only start transaction if we have questions to save
            $has_questions = false;

            if (isset($data['objective']['questions']) && count($data['objective']['questions']) > 0) {
                $has_questions = true;
            }
            if (isset($data['theory']['questions']) && count($data['theory']['questions']) > 0) {
                $has_questions = true;
            }

            if ($has_questions) {
                $this->pdo->beginTransaction();
                $this->log("Started transaction for saving questions");
            } else {
                $this->log("No questions to save");
                return 0;
            }

            // Save objective questions
            if (isset($data['objective']['questions'])) {
                foreach ($data['objective']['questions'] as $q) {
                    if ($this->saveObjectiveQuestion($q, $batch_id, $pull_params, $target_subject_id, $target_topic_id)) {
                        $saved_count++;
                    }
                }
            }

            // Save theory questions
            if (isset($data['theory']['questions'])) {
                foreach ($data['theory']['questions'] as $q) {
                    if ($this->saveTheoryQuestion($q, $batch_id, $pull_params, $target_subject_id, $target_topic_id)) {
                        $saved_count++;
                    }
                }
            }

            // Log the sync
            $this->logSync($batch_id, $saved_count, $pull_params);

            // Only commit if we had a transaction
            if ($has_questions) {
                $this->pdo->commit();
                $this->log("Transaction committed successfully");
            }

            $this->log("Successfully saved $saved_count questions");
        } catch (Exception $e) {
            // Only rollback if we had a transaction
            if (isset($has_questions) && $has_questions && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                $this->log("Transaction rolled back due to error: " . $e->getMessage(), 'ERROR');
            }
            $this->log("Error saving questions: " . $e->getMessage(), 'ERROR');
            return 0;
        }

        return $saved_count;
    }

    /**
     * Save objective question to local database with mapping
     */
    private function saveObjectiveQuestion($q, $batch_id, $pull_params, $target_subject_id = null, $target_topic_id = null)
    {
        // First ensure the objective_questions table has the required columns
        $this->ensureObjectiveQuestionsTable();

        // Use mapped subject/topic if provided, otherwise use original
        $subject_id = $target_subject_id ?? $q['subject_id'];
        $topic_id = $target_topic_id ?? $q['topic_id'];

        // Check if question already exists (by central ID)
        $stmt = $this->pdo->prepare("SELECT id FROM objective_questions WHERE central_question_id = ?");
        $stmt->execute([$q['id']]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing
            $sql = "UPDATE objective_questions SET 
                    question_text = ?, 
                    option_a = ?, 
                    option_b = ?, 
                    option_c = ?, 
                    option_d = ?,
                    correct_answer = ?, 
                    subject_id = ?, 
                    topic_id = ?, 
                    difficulty_level = ?,
                    marks = ?, 
                    class = ?, 
                    last_sync = NOW()
                    WHERE central_question_id = ?";

            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                $q['question_text'],
                $q['option_a'],
                $q['option_b'],
                $q['option_c'],
                $q['option_d'],
                $q['correct_answer'],
                $subject_id,
                $topic_id,
                $q['difficulty_level'],
                $q['marks'],
                $q['class_level'] ?? $pull_params['class'] ?? null,
                $q['id']
            ]);

            if ($result) {
                $this->log("Updated question ID: " . $q['id'] . " (mapped to local subject: $subject_id)");
            }

            return $result;
        } else {
            // Insert new
            $sql = "INSERT INTO objective_questions 
                    (question_text, option_a, option_b, option_c, option_d, correct_answer,
                     subject_id, topic_id, difficulty_level, marks, class, 
                     central_question_id, source_batch, created_at, last_sync)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                $q['question_text'],
                $q['option_a'],
                $q['option_b'],
                $q['option_c'],
                $q['option_d'],
                $q['correct_answer'],
                $subject_id,
                $topic_id,
                $q['difficulty_level'],
                $q['marks'],
                $q['class_level'] ?? $pull_params['class'] ?? null,
                $q['id'],
                $batch_id
            ]);

            if ($result) {
                $this->log("Inserted new question ID: " . $q['id'] . " (mapped to local subject: $subject_id)");
            }

            return $result;
        }
    }

    /**
     * Save theory question to local database
     */
    private function saveTheoryQuestion($q, $batch_id, $pull_params, $target_subject_id = null, $target_topic_id = null)
    {
        // Ensure theory_questions table exists with required columns
        $this->ensureTheoryQuestionsTable();

        // Use mapped subject/topic if provided, otherwise use original
        $subject_id = $target_subject_id ?? $q['subject_id'];
        $topic_id = $target_topic_id ?? $q['topic_id'];

        // Check if question already exists
        $stmt = $this->pdo->prepare("SELECT id FROM theory_questions WHERE central_question_id = ?");
        $stmt->execute([$q['id']]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing
            $sql = "UPDATE theory_questions SET 
                    question_text = ?,
                    subject_id = ?,
                    topic_id = ?,
                    class = ?,
                    marks = ?,
                    difficulty_level = ?,
                    model_answer = ?,
                    last_sync = NOW()
                    WHERE central_question_id = ?";

            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                $q['question_text'],
                $subject_id,
                $topic_id,
                $q['class_level'] ?? $pull_params['class'] ?? null,
                $q['marks'],
                $q['difficulty_level'],
                $q['model_answer'] ?? null,
                $q['id']
            ]);

            if ($result) {
                $this->log("Updated theory question ID: " . $q['id'] . " (mapped to local subject: $subject_id)");
            }

            return $result;
        } else {
            // Insert new
            $sql = "INSERT INTO theory_questions 
                    (question_text, subject_id, topic_id, class, marks, difficulty_level, 
                     model_answer, central_question_id, source_batch, created_at, last_sync)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                $q['question_text'],
                $subject_id,
                $topic_id,
                $q['class_level'] ?? $pull_params['class'] ?? null,
                $q['marks'],
                $q['difficulty_level'],
                $q['model_answer'] ?? null,
                $q['id'],
                $batch_id
            ]);

            if ($result) {
                $this->log("Inserted new theory question ID: " . $q['id'] . " (mapped to local subject: $subject_id)");
            }

            return $result;
        }
    }

    /**
     * Ensure objective_questions table has required columns
     */
    private function ensureObjectiveQuestionsTable()
    {
        try {
            // Check if central_question_id column exists
            $stmt = $this->pdo->query("SHOW COLUMNS FROM objective_questions LIKE 'central_question_id'");
            if (!$stmt->fetch()) {
                $this->pdo->exec("ALTER TABLE objective_questions ADD COLUMN central_question_id INT NULL");
                $this->log("Added central_question_id column to objective_questions");
            }

            $stmt = $this->pdo->query("SHOW COLUMNS FROM objective_questions LIKE 'source_batch'");
            if (!$stmt->fetch()) {
                $this->pdo->exec("ALTER TABLE objective_questions ADD COLUMN source_batch VARCHAR(50) NULL");
                $this->log("Added source_batch column to objective_questions");
            }

            $stmt = $this->pdo->query("SHOW COLUMNS FROM objective_questions LIKE 'last_sync'");
            if (!$stmt->fetch()) {
                $this->pdo->exec("ALTER TABLE objective_questions ADD COLUMN last_sync TIMESTAMP NULL");
                $this->log("Added last_sync column to objective_questions");
            }
        } catch (PDOException $e) {
            $this->log("Error checking/adding columns: " . $e->getMessage(), 'ERROR');
        }
    }

    /**
     * Ensure theory_questions table has required columns
     */
    private function ensureTheoryQuestionsTable()
    {
        try {
            // Check if table exists
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS theory_questions (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    question_text TEXT,
                    subject_id INT,
                    topic_id INT,
                    class VARCHAR(50),
                    marks INT DEFAULT 5,
                    difficulty_level ENUM('easy','medium','hard') DEFAULT 'medium',
                    model_answer TEXT,
                    central_question_id INT NULL,
                    source_batch VARCHAR(50) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    last_sync TIMESTAMP NULL
                )
            ");
        } catch (PDOException $e) {
            $this->log("Error creating theory_questions table: " . $e->getMessage(), 'ERROR');
        }
    }

    /**
     * Check if question exists locally
     */
    public function questionExists($central_id, $type = 'objective')
    {
        $table = ($type === 'theory') ? 'theory_questions' : 'objective_questions';
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM $table WHERE central_question_id = ?");
            $stmt->execute([$central_id]);
            return $stmt->fetch() ? true : false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Log sync to database
     */
    private function logSync($batch_id, $count, $params)
    {
        // Create sync_logs table if not exists
        $this->ensureSyncTable();

        $stmt = $this->pdo->prepare("
            INSERT INTO sync_logs (batch_id, question_count, pull_params, synced_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$batch_id, $count, json_encode($params)]);
    }

    /**
     * Ensure sync_logs table exists
     */
    private function ensureSyncTable()
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS sync_logs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                batch_id VARCHAR(50),
                question_count INT,
                pull_params TEXT,
                synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    /**
     * Get sync status
     */
    public function getSyncStatus()
    {
        $result = $this->callAPI('?action=sync_status');

        $status = [
            'last_sync' => null,
            'total_questions' => 0,
            'central_connected' => false,
            'central_info' => null
        ];

        // Get last sync from local
        try {
            $this->ensureSyncTable();
            $stmt = $this->pdo->query("SELECT * FROM sync_logs ORDER BY synced_at DESC LIMIT 1");
            $last = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($last) {
                $status['last_sync'] = $last['synced_at'];
                $status['last_batch'] = $last['batch_id'];
                $status['last_count'] = $last['question_count'];
            }
        } catch (Exception $e) {
            // Table might not exist yet
        }

        // Get total questions from central
        if (isset($result['status']) && $result['status'] === 'success') {
            $status['central_connected'] = true;
            $status['central_info'] = $result;

            // Get question counts from central
            $counts = $this->callAPI('?action=get_question_count');
            if (isset($counts['counts'])) {
                $status['central_questions'] = $counts['counts']['total'] ?? 0;
            }
        }

        // Get local question count
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM objective_questions");
            $status['local_questions'] = $stmt->fetchColumn();
        } catch (Exception $e) {
            $status['local_questions'] = 0;
        }

        return $status;
    }

    /**
     * Get available subjects from central with local counts
     */
    public function getAvailableSubjects()
    {
        $subjects = $this->getSubjects();
        if (!$subjects['success']) {
            return $subjects;
        }

        // Add local question counts
        foreach ($subjects['subjects'] as &$subject) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM objective_questions 
                    WHERE subject_id = ? AND central_question_id IS NOT NULL
                ");
                $stmt->execute([$subject['id']]);
                $subject['local_count'] = $stmt->fetchColumn();
            } catch (Exception $e) {
                $subject['local_count'] = 0;
            }
        }

        return $subjects;
    }

    /**
     * Get question count for a specific subject
     */
    public function getQuestionCount($subject_id = null)
    {
        $params = ['action' => 'get_question_count'];
        if ($subject_id) {
            $params['subject_id'] = $subject_id;
        }

        $result = $this->callAPI('?' . http_build_query($params));

        if (isset($result['status']) && $result['status'] === 'success') {
            return ['success' => true, 'counts' => $result['counts']];
        }

        return ['success' => false, 'error' => $result['error'] ?? 'Failed to get counts'];
    }
}


// Initialize central sync on include
global $pdo;
$centralSync = new CentralSync($pdo);
