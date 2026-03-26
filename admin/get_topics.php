<?php
require_once 'includes/config.php';

if (isset($_GET['subject_id'])) {
    $subject_id = (int)$_GET['subject_id'];
    
    $stmt = $pdo->prepare("SELECT * FROM topics WHERE subject_id = ? ORDER BY topic_name");
    $stmt->execute([$subject_id]);
    $topics = $stmt->fetchAll();
    
    header('Content-Type: application/json');
    echo json_encode($topics);
    exit;
}
?>