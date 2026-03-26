#!/usr/bin/php
<?php
/**
 * Automatic sync script - Run via cron job
 * Add to crontab: 0 2 * * * /usr/bin/php /path/to/school-cbt/cron/auto_sync.php
 */

require_once dirname(__DIR__) . '/includes/central_sync.php';

$sync = new CentralSync($pdo);
$log = dirname(__DIR__) . '/logs/auto_sync.log';

// Get settings
$stmt = $pdo->query("SELECT * FROM central_settings LIMIT 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings || !$settings['auto_sync']) {
    file_put_contents($log, date('Y-m-d H:i:s') . " - Auto sync disabled\n", FILE_APPEND);
    exit;
}

file_put_contents($log, date('Y-m-d H:i:s') . " - Starting auto sync\n", FILE_APPEND);

// Test connection first
$test = $sync->testConnection();
if (!$test['success']) {
    file_put_contents($log, date('Y-m-d H:i:s') . " - Connection failed: {$test['error']}\n", FILE_APPEND);
    exit;
}

// Get subjects to sync
$subjects = $sync->getSubjects();
if (!$subjects['success']) {
    file_put_contents($log, date('Y-m-d H:i:s') . " - Failed to get subjects\n", FILE_APPEND);
    exit;
}

$total_pulled = 0;

// Pull questions for each subject
foreach ($subjects['subjects'] as $subject) {
    // Check if we need more questions for this subject locally
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM objective_questions WHERE subject_id = ?");
    $stmt->execute([$subject['id']]);
    $local_count = $stmt->fetchColumn();

    // If we have less than 100 questions, pull more
    if ($local_count < 100) {
        $pull_count = min(100 - $local_count, MAX_QUESTIONS_PER_SYNC);

        file_put_contents($log, date('Y-m-d H:i:s') . " - Pulling $pull_count questions for {$subject['subject_name']}\n", FILE_APPEND);

        $result = $sync->pullQuestions([
            'subject_id' => $subject['id'],
            'limit' => $pull_count,
            'type' => 'objective'
        ]);

        if (isset($result['local_save'])) {
            $total_pulled += $result['local_save'];
        }
    }
}

// Update last sync time
$pdo->exec("UPDATE central_settings SET last_sync = NOW() WHERE id = 1");

file_put_contents($log, date('Y-m-d H:i:s') . " - Auto sync complete. Pulled $total_pulled questions.\n\n", FILE_APPEND);
?>