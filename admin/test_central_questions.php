<?php
session_start();
require_once '../includes/central_sync.php';
require_once '../includes/config.php';

echo "<h1>Central Questions Test</h1>";

// Test 1: Check connection
echo "<h2>1. Testing Connection</h2>";
$test = $centralSync->testConnection();
echo "<pre>";
print_r($test);
echo "</pre>";

if (!$test['success']) {
    echo "<p style='color:red'>Connection failed! Cannot continue.</p>";
    exit;
}

// Test 2: Get subjects
echo "<h2>2. Getting Subjects</h2>";
$subjects = $centralSync->getSubjects();
echo "<pre>";
print_r($subjects);
echo "</pre>";

if (!$subjects['success'] || empty($subjects['subjects'])) {
    echo "<p style='color:red'>No subjects found!</p>";
    exit;
}

// Test 3: Try to get questions for first subject
$first_subject = $subjects['subjects'][0];
echo "<h2>3. Testing Questions for Subject: " . $first_subject['subject_name'] . " (ID: " . $first_subject['id'] . ")</h2>";

$params = [
    'subject_id' => $first_subject['id'],
    'limit' => 5,
    'type' => 'objective'
];

echo "<p>Request params:</p>";
echo "<pre>";
print_r($params);
echo "</pre>";

$questions = $centralSync->previewQuestions($params);

echo "<p>Response:</p>";
echo "<pre>";
print_r($questions);
echo "</pre>";

if (isset($questions['objective']['count']) && $questions['objective']['count'] > 0) {
    echo "<p style='color:green'>✓ Success! Found " . $questions['objective']['count'] . " questions</p>";
} else {
    echo "<p style='color:red'>✗ No questions found or error in response</p>";
}

// Test 4: Check a specific subject ID that you know has questions
echo "<h2>4. Testing Known Subject ID (e.g., Mathematics - ID 2)</h2>";
$params = [
    'subject_id' => 2,  // Try Mathematics
    'limit' => 5,
    'type' => 'objective'
];

$questions = $centralSync->previewQuestions($params);
echo "<pre>";
print_r($questions);
echo "</pre>";
