<?php
session_start();
require_once '../includes/central_sync.php';

echo "<h1>Test Preview for Mathematics (ID: 2)</h1>";

$params = [
    'subject_id' => 2,
    'limit' => 5,
    'type' => 'objective'
];

echo "<h2>Request Parameters:</h2>";
echo "<pre>";
print_r($params);
echo "</pre>";

$result = $centralSync->previewQuestions($params);

echo "<h2>Response:</h2>";
echo "<pre>";
print_r($result);
echo "</pre>";

if (isset($result['objective']['count']) && $result['objective']['count'] > 0) {
    echo "<p style='color:green'>✅ Success! Found " . $result['objective']['count'] . " questions</p>";

    echo "<h3>First Question:</h3>";
    if (isset($result['objective']['questions'][0])) {
        $q = $result['objective']['questions'][0];
        echo "<p><strong>Question:</strong> " . htmlspecialchars($q['question_text']) . "</p>";
        echo "<p><strong>Options:</strong> A: {$q['option_a']}, B: {$q['option_b']}, C: {$q['option_c']}, D: {$q['option_d']}</p>";
        echo "<p><strong>Correct:</strong> {$q['correct_answer']}</p>";
    }
} else {
    echo "<p style='color:red'>❌ No questions found</p>";
    if (isset($result['error'])) {
        echo "<p>Error: " . $result['error'] . "</p>";
    }
}
