<?php
// preview-resource.php - Preview resources
session_start();
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isStudentLoggedIn()) {
    die("Access denied");
}

$resource_id = $_GET['id'] ?? 0;

if (!$resource_id) {
    die("Invalid resource ID");
}

$stmt = $pdo->prepare("SELECT * FROM library_resources WHERE id = ?");
$stmt->execute([$resource_id]);
$resource = $stmt->fetch();

if (!$resource) {
    die("Resource not found");
}

// Check access
$student_class = $_SESSION['class'];
if ($resource['class'] !== $student_class && $resource['class'] !== 'All' && !empty($resource['class'])) {
    die("You don't have access to this resource");
}

$file_path = '../assets/uploads/' . $resource['file_path'];
$file_ext = strtolower(pathinfo($resource['file_path'], PATHINFO_EXTENSION));

echo displayHeader('Preview: ' . $resource['title']);
?>

<div class="main-container">
    <div style="background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin: 0;">
                <?php echo htmlspecialchars($resource['title']); ?>
            </h2>
            <a href="library.php" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>;">← Back to Library</a>
        </div>

        <?php if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
            <!-- Image Preview -->
            <div style="text-align: center;">
                <img src="../assets/uploads/<?php echo htmlspecialchars($resource['file_path']); ?>"
                    alt="<?php echo htmlspecialchars($resource['title']); ?>"
                    style="max-width: 100%; max-height: 70vh; border-radius: 5px;">
            </div>

        <?php elseif ($file_ext === 'pdf'): ?>
            <!-- PDF Preview (using iframe) -->
            <div style="height: 70vh;">
                <iframe src="../assets/uploads/<?php echo htmlspecialchars($resource['file_path']); ?>"
                    style="width: 100%; height: 100%; border: none; border-radius: 5px;"></iframe>
            </div>

        <?php elseif ($file_ext === 'txt'): ?>
            <!-- Text File Preview -->
            <div style="background: #f8f9fa; padding: 20px; border-radius: 5px; max-height: 60vh; overflow: auto; font-family: monospace;">
                <pre><?php echo htmlspecialchars(file_get_contents($file_path)); ?></pre>
            </div>

        <?php else: ?>
            <!-- Unpreviewable file -->
            <div style="text-align: center; padding: 40px;">
                <div style="font-size: 4em; margin-bottom: 20px;">📄</div>
                <h3>File Preview Not Available</h3>
                <p>This file type cannot be previewed in the browser.</p>
                <a href="download-resource.php?id=<?php echo $resource['id']; ?>" class="btn" style="background: <?php echo COLOR_SUCCESS; ?>; margin-top: 20px;">
                    ⬇️ Download File
                </a>
            </div>
        <?php endif; ?>

        <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <p style="margin: 0; color: #666;">
                        <strong>Subject:</strong> <?php echo htmlspecialchars($resource['subject']); ?> |
                        <strong>Type:</strong> <?php echo htmlspecialchars($resource['file_type']); ?> |
                        <strong>Size:</strong> <?php echo formatFileSize($resource['file_size']); ?>
                    </p>
                </div>
                <div>
                    <a href="download-resource.php?id=<?php echo $resource['id']; ?>" class="btn" style="background: <?php echo COLOR_SUCCESS; ?>;">
                        ⬇️ Download
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
function formatFileSize($bytes)
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

echo displayFooter();
?>