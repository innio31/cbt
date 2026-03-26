<?php
// admin/version.php - Display system version
require_once '../includes/config.php';
?>
<!DOCTYPE html>
<html>

<head>
    <title>System Version</title>
    <style>
        body {
            font-family: Arial;
            padding: 20px;
            background: #f5f6fa;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            max-width: 500px;
            margin: 0 auto;
        }

        .version {
            font-size: 24px;
            color: #3498db;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="card">
        <h2>CBT System Version</h2>
        <p>Current Version: <span class="version"><?php echo defined('SYSTEM_VERSION') ? SYSTEM_VERSION : '1.0.0'; ?></span></p>
        <p>Last Updated: <?php echo date('Y-m-d H:i:s'); ?></p>
    </div>
</body>

</html>