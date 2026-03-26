<?php
// student/library.php - E-Library Management for Students
session_start();
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Check if student is logged in
if (!isStudentLoggedIn()) {
    redirect('../login.php', 'Please login to access the library', 'warning');
}

$student_id = $_SESSION['student_id'];
$student_class = $_SESSION['class'];

// Check session timeout
checkSessionTimeout();

// Get filter parameters
$subject_filter = $_GET['subject'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'newest';

// Build WHERE conditions
$where_conditions = ["(lr.class = ? OR lr.class = 'All' OR lr.class = '')"];
$params = [$student_class];

// Subject filter
if ($subject_filter !== 'all') {
    $where_conditions[] = "lr.subject = ?";
    $params[] = $subject_filter;
}

// Type filter
if ($type_filter !== 'all') {
    if ($type_filter === 'IMAGE') {
        $where_conditions[] = "(lr.file_type LIKE '%.jpg%' OR lr.file_type LIKE '%.jpeg%' OR lr.file_type LIKE '%.png%' OR lr.file_type LIKE '%.gif%')";
    } elseif ($type_filter === 'VIDEO') {
        $where_conditions[] = "(lr.file_type LIKE '%.mp4%' OR lr.file_type LIKE '%.avi%' OR lr.file_type LIKE '%.mov%' OR lr.file_type LIKE '%.wmv%')";
    } elseif ($type_filter === 'AUDIO') {
        $where_conditions[] = "(lr.file_type LIKE '%.mp3%' OR lr.file_type LIKE '%.wav%' OR lr.file_type LIKE '%.ogg%')";
    } elseif ($type_filter === 'DOC') {
        $where_conditions[] = "(lr.file_type LIKE '%.doc%' OR lr.file_type LIKE '%.docx%')";
    } elseif ($type_filter === 'PPT') {
        $where_conditions[] = "(lr.file_type LIKE '%.ppt%' OR lr.file_type LIKE '%.pptx%')";
    } elseif ($type_filter === 'EXCEL') {
        $where_conditions[] = "(lr.file_type LIKE '%.xls%' OR lr.file_type LIKE '%.xlsx%')";
    } elseif ($type_filter === 'PDF') {
        $where_conditions[] = "lr.file_type LIKE '%.pdf%'";
    } elseif ($type_filter === 'ARCHIVE') {
        $where_conditions[] = "(lr.file_type LIKE '%.zip%' OR lr.file_type LIKE '%.rar%')";
    } else {
        $where_conditions[] = "lr.file_type LIKE ?";
        $params[] = "%$type_filter%";
    }
}

// Search filter
if ($search_query) {
    $where_conditions[] = "(lr.title LIKE ? OR lr.subject LIKE ?)";
    $search_term = "%$search_query%";
    $params[] = $search_term;
    $params[] = $search_term;
}

// Build WHERE clause
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Sort order
$order_by = "ORDER BY ";
switch ($sort_by) {
    case 'oldest':
        $order_by .= "lr.uploaded_at ASC";
        break;
    case 'title_asc':
        $order_by .= "lr.title ASC";
        break;
    case 'title_desc':
        $order_by .= "lr.title DESC";
        break;
    case 'size_asc':
        $order_by .= "lr.file_size ASC";
        break;
    case 'size_desc':
        $order_by .= "lr.file_size DESC";
        break;
    default: // newest
        $order_by .= "lr.uploaded_at DESC";
}

// Get library resources - CORRECTED QUERY
$query = "
    SELECT lr.*, 
           CASE 
               WHEN lr.file_size < 1024 THEN CONCAT(lr.file_size, ' B')
               WHEN lr.file_size < 1048576 THEN CONCAT(ROUND(lr.file_size/1024, 1), ' KB')
               ELSE CONCAT(ROUND(lr.file_size/1048576, 1), ' MB')
           END as formatted_size,
           st.full_name as uploaded_by_name
    FROM library_resources lr
    LEFT JOIN staff st ON lr.uploaded_by = st.id AND lr.uploaded_by_type = 'staff'
    LEFT JOIN admin_users au ON lr.uploaded_by = au.id AND lr.uploaded_by_type = 'admin'
    $where_clause
    $order_by
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$resources = $stmt->fetchAll();

// Get distinct subjects for filter dropdown
$stmt = $pdo->prepare("
    SELECT DISTINCT subject 
    FROM library_resources 
    WHERE (class = ? OR class = 'All' OR class = '') 
    AND subject IS NOT NULL 
    AND subject != ''
    ORDER BY subject
");
$stmt->execute([$student_class]);
$available_subjects = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get distinct file types
$stmt = $pdo->prepare("
    SELECT DISTINCT 
        CASE 
            WHEN file_type LIKE '%.pdf%' THEN 'PDF'
            WHEN file_type LIKE '%.doc%' OR file_type LIKE '%.docx%' THEN 'DOC'
            WHEN file_type LIKE '%.ppt%' OR file_type LIKE '%.pptx%' THEN 'PPT'
            WHEN file_type LIKE '%.xls%' OR file_type LIKE '%.xlsx%' THEN 'EXCEL'
            WHEN file_type LIKE '%.jpg%' OR file_type LIKE '%.jpeg%' OR file_type LIKE '%.png%' OR file_type LIKE '%.gif%' THEN 'IMAGE'
            WHEN file_type LIKE '%.mp4%' OR file_type LIKE '%.avi%' OR file_type LIKE '%.mov%' OR file_type LIKE '%.wmv%' THEN 'VIDEO'
            WHEN file_type LIKE '%.mp3%' OR file_type LIKE '%.wav%' OR file_type LIKE '%.ogg%' THEN 'AUDIO'
            WHEN file_type LIKE '%.zip%' OR file_type LIKE '%.rar%' THEN 'ARCHIVE'
            ELSE 'OTHER'
        END as file_category
    FROM library_resources 
    WHERE (class = ? OR class = 'All' OR class = '')
    ORDER BY file_category
");
$stmt->execute([$student_class]);
$available_types_result = $stmt->fetchAll(PDO::FETCH_COLUMN);
$available_types = array_unique($available_types_result);

// Get recently added resources (last 7 days)
$stmt = $pdo->prepare("
    SELECT COUNT(*) as recent_count 
    FROM library_resources 
    WHERE (class = ? OR class = 'All' OR class = '') 
    AND uploaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stmt->execute([$student_class]);
$recent_count = $stmt->fetchColumn();

// Get total resources count
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_count 
    FROM library_resources 
    WHERE (class = ? OR class = 'All' OR class = '')
");
$stmt->execute([$student_class]);
$total_count = $stmt->fetchColumn();

// Get resource statistics by type
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN file_type LIKE '%.pdf%' THEN 1 ELSE 0 END) as pdf_count,
        SUM(CASE WHEN file_type LIKE '%.doc%' OR file_type LIKE '%.docx%' THEN 1 ELSE 0 END) as doc_count,
        SUM(CASE WHEN file_type LIKE '%.ppt%' OR file_type LIKE '%.pptx%' THEN 1 ELSE 0 END) as ppt_count,
        SUM(CASE WHEN file_type LIKE '%.xls%' OR file_type LIKE '%.xlsx%' THEN 1 ELSE 0 END) as excel_count,
        SUM(CASE WHEN file_type LIKE '%.jpg%' OR file_type LIKE '%.jpeg%' OR file_type LIKE '%.png%' OR file_type LIKE '%.gif%' THEN 1 ELSE 0 END) as image_count,
        SUM(CASE WHEN file_type LIKE '%.mp4%' OR file_type LIKE '%.avi%' OR file_type LIKE '%.mov%' OR file_type LIKE '%.wmv%' THEN 1 ELSE 0 END) as video_count,
        SUM(CASE WHEN file_type LIKE '%.mp3%' OR file_type LIKE '%.wav%' OR file_type LIKE '%.ogg%' THEN 1 ELSE 0 END) as audio_count,
        SUM(CASE WHEN file_type LIKE '%.zip%' OR file_type LIKE '%.rar%' THEN 1 ELSE 0 END) as archive_count
    FROM library_resources 
    WHERE (class = ? OR class = 'All' OR class = '')
");
$stmt->execute([$student_class]);
$type_stats = $stmt->fetch();

// Get recently added resources for display
$stmt = $pdo->prepare("
    SELECT lr.*, 
           CASE 
               WHEN lr.file_size < 1024 THEN CONCAT(lr.file_size, ' B')
               WHEN lr.file_size < 1048576 THEN CONCAT(ROUND(lr.file_size/1024, 1), ' KB')
               ELSE CONCAT(ROUND(lr.file_size/1048576, 1), ' MB')
           END as formatted_size
    FROM library_resources lr
    WHERE (lr.class = ? OR lr.class = 'All' OR lr.class = '')
    ORDER BY lr.uploaded_at DESC
    LIMIT 6
");
$stmt->execute([$student_class]);
$recent_resources = $stmt->fetchAll();

// Track resource view (for analytics)
function trackResourceView($pdo, $resource_id, $student_id)
{
    try {
        // First check if the table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS resource_views (
            id INT PRIMARY KEY AUTO_INCREMENT,
            resource_id INT NOT NULL,
            student_id INT NOT NULL,
            view_count INT DEFAULT 1,
            first_viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_resource_student (resource_id, student_id)
        )");

        $stmt = $pdo->prepare("
            INSERT INTO resource_views (resource_id, student_id, view_count) 
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE view_count = view_count + 1, last_viewed_at = NOW()
        ");
        $stmt->execute([$resource_id, $student_id]);
        return true;
    } catch (Exception $e) {
        error_log("Resource view tracking error: " . $e->getMessage());
        return false;
    }
}

// Display header
echo displayHeader('E-Library');
?>

<div class="main-container">
    <!-- Welcome Header -->
    <div style="background: linear-gradient(135deg, <?php echo COLOR_PRIMARY; ?>, <?php echo COLOR_SECONDARY; ?>); 
                color: white; padding: 25px; border-radius: 15px; margin-bottom: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div>
                <h1 style="margin: 0 0 10px 0;">📚 Digital Library</h1>
                <p style="margin: 0; opacity: 0.9;">Access learning materials, textbooks, and educational resources</p>
            </div>
            <div>
                <a href="index.php" class="btn" style="background: rgba(255,255,255,0.2);">← Back to Dashboard</a>
            </div>
        </div>
    </div>

    <!-- Display flash message -->
    <?php displayFlashMessage(); ?>

    <!-- Statistics Cards -->
    <div class="content-card">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Library Overview</h2>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
            <div style="background: linear-gradient(135deg, #3498db, #2980b9); color: white; padding: 15px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2em; font-weight: bold;"><?php echo $total_count; ?></div>
                <div>Total Resources</div>
            </div>

            <div style="background: linear-gradient(135deg, #2ecc71, #27ae60); color: white; padding: 15px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2em; font-weight: bold;"><?php echo $recent_count; ?></div>
                <div>New This Week</div>
            </div>

            <div style="background: linear-gradient(135deg, #9b59b6, #8e44ad); color: white; padding: 15px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2em; font-weight: bold;"><?php echo $type_stats['pdf_count'] ?? 0; ?></div>
                <div>PDF Documents</div>
            </div>

            <div style="background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; padding: 15px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2em; font-weight: bold;"><?php echo $type_stats['video_count'] ?? 0; ?></div>
                <div>Videos</div>
            </div>

            <div style="background: linear-gradient(135deg, #f39c12, #e67e22); color: white; padding: 15px; border-radius: 10px; text-align: center;">
                <div style="font-size: 2em; font-weight: bold;"><?php echo $type_stats['image_count'] ?? 0; ?></div>
                <div>Images</div>
            </div>
        </div>
    </div>

    <!-- Filters and Search -->
    <div class="content-card">
        <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <!-- Search -->
            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Search Resources</label>
                <input type="text" name="search" class="form-control" placeholder="Search by title or subject..."
                    value="<?php echo htmlspecialchars($search_query); ?>">
            </div>

            <!-- Subject Filter -->
            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Subject</label>
                <select name="subject" class="form-control" onchange="this.form.submit()">
                    <option value="all" <?php echo $subject_filter === 'all' ? 'selected' : ''; ?>>All Subjects</option>
                    <?php foreach ($available_subjects as $subject): ?>
                        <option value="<?php echo htmlspecialchars($subject); ?>" <?php echo $subject_filter === $subject ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($subject); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Type Filter -->
            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Resource Type</label>
                <select name="type" class="form-control" onchange="this.form.submit()">
                    <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                    <?php foreach ($available_types as $type): ?>
                        <?php if (!empty($type)): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $type_filter === $type ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Sort By -->
            <div>
                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: <?php echo COLOR_PRIMARY; ?>;">Sort By</label>
                <select name="sort" class="form-control" onchange="this.form.submit()">
                    <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="title_asc" <?php echo $sort_by === 'title_asc' ? 'selected' : ''; ?>>Title (A-Z)</option>
                    <option value="title_desc" <?php echo $sort_by === 'title_desc' ? 'selected' : ''; ?>>Title (Z-A)</option>
                    <option value="size_asc" <?php echo $sort_by === 'size_asc' ? 'selected' : ''; ?>>Size (Smallest)</option>
                    <option value="size_desc" <?php echo $sort_by === 'size_desc' ? 'selected' : ''; ?>>Size (Largest)</option>
                </select>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; align-items: flex-end; gap: 10px;">
                <button type="submit" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>; padding: 12px 20px;">
                    🔍 Apply Filters
                </button>
                <a href="library.php" class="btn" style="background: #95a5a6; padding: 12px 20px;">
                    🗑️ Clear All
                </a>
            </div>
        </form>
    </div>

    <!-- Quick Access Categories -->
    <div class="content-card">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">Quick Access</h2>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
            <a href="library.php?type=PDF" class="btn" style="background: #e74c3c; text-align: center;">
                <div style="font-size: 2em;">📕</div>
                <div>PDF Books</div>
                <small><?php echo $type_stats['pdf_count'] ?? 0; ?> files</small>
            </a>

            <a href="library.php?type=DOC" class="btn" style="background: #3498db; text-align: center;">
                <div style="font-size: 2em;">📘</div>
                <div>Documents</div>
                <small><?php echo $type_stats['doc_count'] ?? 0; ?> files</small>
            </a>

            <a href="library.php?type=PPT" class="btn" style="background: #f39c12; text-align: center;">
                <div style="font-size: 2em;">📙</div>
                <div>Presentations</div>
                <small><?php echo $type_stats['ppt_count'] ?? 0; ?> files</small>
            </a>

            <a href="library.php?type=IMAGE" class="btn" style="background: #2ecc71; text-align: center;">
                <div style="font-size: 2em;">🖼️</div>
                <div>Images</div>
                <small><?php echo $type_stats['image_count'] ?? 0; ?> files</small>
            </a>

            <a href="library.php?type=VIDEO" class="btn" style="background: #9b59b6; text-align: center;">
                <div style="font-size: 2em;">🎬</div>
                <div>Videos</div>
                <small><?php echo $type_stats['video_count'] ?? 0; ?> files</small>
            </a>

            <a href="library.php?type=AUDIO" class="btn" style="background: #1abc9c; text-align: center;">
                <div style="font-size: 2em;">🎵</div>
                <div>Audio</div>
                <small><?php echo $type_stats['audio_count'] ?? 0; ?> files</small>
            </a>
        </div>
    </div>

    <!-- Recently Added Section -->
    <?php if (!empty($recent_resources)): ?>
        <div class="content-card">
            <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                <span>📦 Recently Added</span>
                <a href="library.php?sort=newest" style="font-size: 0.9em; text-decoration: none; color: <?php echo COLOR_SECONDARY; ?>;">
                    View All →
                </a>
            </h2>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                <?php foreach ($recent_resources as $resource):
                    $file_icon = getFileIcon($resource['file_type']);
                    $time_ago = timeAgo($resource['uploaded_at']);
                ?>
                    <div style="border: 1px solid #e0e0e0; border-radius: 10px; overflow: hidden; transition: transform 0.3s ease; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                        <div style="background: #f8f9fa; padding: 15px; text-align: center; border-bottom: 1px solid #e0e0e0;">
                            <div style="font-size: 3em; margin-bottom: 10px;"><?php echo $file_icon; ?></div>
                            <div style="font-weight: bold; color: <?php echo COLOR_PRIMARY; ?>; font-size: 1.1em;">
                                <?php echo htmlspecialchars(mb_strimwidth($resource['title'], 0, 30, '...')); ?>
                            </div>
                        </div>

                        <div style="padding: 15px;">
                            <p style="margin: 0 0 10px 0; font-size: 0.9em; color: #666;">
                                <strong>Subject:</strong> <?php echo htmlspecialchars($resource['subject']); ?><br>
                                <strong>Type:</strong> <?php echo htmlspecialchars($resource['file_type']); ?><br>
                                <strong>Size:</strong> <?php echo $resource['formatted_size']; ?><br>
                                <strong>Added:</strong> <?php echo $time_ago; ?>
                            </p>

                            <div style="display: flex; gap: 10px; margin-top: 15px;">
                                <?php if (canPreview($resource['file_type'])): ?>
                                    <a href="#" onclick="previewResource(<?php echo $resource['id']; ?>)"
                                        class="btn" style="background: <?php echo COLOR_SECONDARY; ?>; padding: 8px 15px; font-size: 0.9em; flex: 1;">
                                        👁️ Preview
                                    </a>
                                <?php endif; ?>
                                <a href="download-resource.php?id=<?php echo $resource['id']; ?>"
                                    class="btn" style="background: <?php echo COLOR_SUCCESS; ?>; padding: 8px 15px; font-size: 0.9em; flex: 1;">
                                    ⬇️ Download
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- All Resources -->
    <div class="content-card">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <span>📚 All Resources (<?php echo count($resources); ?>)</span>
            <span style="font-size: 0.9em; font-weight: normal; color: #666;">
                Showing <?php echo count($resources); ?> of <?php echo $total_count; ?> resources
            </span>
        </h2>

        <?php if (empty($resources)): ?>
            <div style="text-align: center; padding: 40px; color: #666;">
                <div style="font-size: 3em; margin-bottom: 20px;">📭</div>
                <h3>No resources found</h3>
                <p>There are no resources matching your current filters.</p>
                <a href="library.php" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>; margin-top: 15px;">
                    View All Resources
                </a>
            </div>
        <?php else: ?>
            <div style="display: grid; gap: 15px;">
                <?php foreach ($resources as $resource):
                    $file_icon = getFileIcon($resource['file_type']);
                    $time_ago = timeAgo($resource['uploaded_at']);
                    trackResourceView($pdo, $resource['id'], $student_id); // Track the view
                ?>
                    <div style="border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                        <div style="display: flex; gap: 20px; align-items: flex-start;">
                            <!-- File Icon -->
                            <div style="flex-shrink: 0; width: 60px; height: 60px; background: #f8f9fa; 
                                    border-radius: 10px; display: flex; align-items: center; justify-content: center; 
                                    font-size: 2em; border: 2px solid #e0e0e0;">
                                <?php echo $file_icon; ?>
                            </div>

                            <!-- Resource Details -->
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 10px 0; color: <?php echo COLOR_PRIMARY; ?>;">
                                    <?php echo htmlspecialchars($resource['title']); ?>
                                </h3>

                                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                                    <?php if (!empty($resource['subject'])): ?>
                                        <span style="background: #e3f2fd; color: #1976d2; padding: 4px 12px; border-radius: 15px; font-size: 0.85em; display: inline-flex; align-items: center;">
                                            📚 <?php echo htmlspecialchars($resource['subject']); ?>
                                        </span>
                                    <?php endif; ?>

                                    <span style="background: #f3e5f5; color: #7b1fa2; padding: 4px 12px; border-radius: 15px; font-size: 0.85em; display: inline-flex; align-items: center;">
                                        📄 <?php echo htmlspecialchars($resource['file_type']); ?>
                                    </span>

                                    <span style="background: #e8f5e9; color: #388e3c; padding: 4px 12px; border-radius: 15px; font-size: 0.85em; display: inline-flex; align-items: center;">
                                        💾 <?php echo $resource['formatted_size']; ?>
                                    </span>

                                    <span style="background: #fff3e0; color: #f57c00; padding: 4px 12px; border-radius: 15px; font-size: 0.85em; display: inline-flex; align-items: center;">
                                        📅 <?php echo $time_ago; ?>
                                    </span>

                                    <?php if (!empty($resource['uploaded_by_name'])): ?>
                                        <span style="background: #fce4ec; color: #c2185b; padding: 4px 12px; border-radius: 15px; font-size: 0.85em; display: inline-flex; align-items: center;">
                                            👤 <?php echo htmlspecialchars($resource['uploaded_by_name']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Action Buttons -->
                                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <a href="download-resource.php?id=<?php echo $resource['id']; ?>"
                                        class="btn" style="background: <?php echo COLOR_SUCCESS; ?>; padding: 10px 20px; display: inline-flex; align-items: center; gap: 5px;">
                                        ⬇️ Download
                                    </a>

                                    <?php if (canPreview($resource['file_type'])): ?>
                                        <button onclick="previewResource(<?php echo $resource['id']; ?>)"
                                            class="btn" style="background: <?php echo COLOR_SECONDARY; ?>; padding: 10px 20px; display: inline-flex; align-items: center; gap: 5px;">
                                            👁️ Preview
                                        </button>
                                    <?php endif; ?>

                                    <button onclick="showResourceInfo(<?php echo $resource['id']; ?>)"
                                        class="btn" style="background: #9b59b6; padding: 10px 20px; display: inline-flex; align-items: center; gap: 5px;">
                                        ℹ️ Details
                                    </button>

                                    <button onclick="shareResource(<?php echo $resource['id']; ?>)"
                                        class="btn" style="background: #f39c12; padding: 10px 20px; display: inline-flex; align-items: center; gap: 5px;">
                                        🔗 Share
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Help Section -->
    <div class="content-card">
        <h2 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 15px;">Library Guide</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid <?php echo COLOR_SECONDARY; ?>;">
                <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_PRIMARY; ?>;">📖 How to Use</h4>
                <p style="margin: 0; font-size: 0.9em; color: #666;">
                    • Search resources by title or subject<br>
                    • Filter by subject or file type<br>
                    • Preview supported files before downloading<br>
                    • Download for offline access
                </p>
            </div>

            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid <?php echo COLOR_SUCCESS; ?>;">
                <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_PRIMARY; ?>;">💾 Supported Files</h4>
                <p style="margin: 0; font-size: 0.9em; color: #666;">
                    • PDF: Textbooks, notes<br>
                    • DOC/DOCX: Worksheets<br>
                    • PPT: Presentations<br>
                    • Images: JPG, PNG, GIF<br>
                    • Videos: MP4, AVI, MOV<br>
                    • Audio: MP3, WAV
                </p>
            </div>

            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid <?php echo COLOR_WARNING; ?>;">
                <h4 style="margin: 0 0 10px 0; color: <?php echo COLOR_PRIMARY; ?>;">📱 Tips</h4>
                <p style="margin: 0; font-size: 0.9em; color: #666;">
                    • Download for offline study<br>
                    • Use search for quick access<br>
                    • Check "Recently Added" for updates<br>
                    • Contact teacher for specific requests
                </p>
            </div>
        </div>
    </div>

</div>

<!-- Resource Info Modal -->
<div id="infoModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
     background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; width: 90%; max-width: 500px; border-radius: 15px; padding: 30px; max-height: 80vh; overflow-y: auto;">
        <h3 style="color: <?php echo COLOR_PRIMARY; ?>; margin-bottom: 20px;">📚 Resource Information</h3>
        <div id="infoContent">
            <!-- Info content will be loaded via AJAX -->
        </div>
        <div style="text-align: center; margin-top: 20px;">
            <button onclick="closeInfoModal()" class="btn" style="background: <?php echo COLOR_SECONDARY; ?>;">
                Close
            </button>
        </div>
    </div>
</div>

<script>
    // Preview function
    function previewResource(resourceId) {
        // For images, PDFs, and text files, we can show inline preview
        // For other files, redirect to download
        window.open('preview-resource.php?id=' + resourceId, '_blank', 'width=800,height=600');
    }

    // Info modal functions
    function showResourceInfo(resourceId) {
        // Simple implementation without AJAX for now
        // Redirect to a details page
        window.location.href = 'resource-details.php?id=' + resourceId;
    }

    function closeInfoModal() {
        document.getElementById('infoModal').style.display = 'none';
    }

    // Share function
    function shareResource(resourceId) {
        // Get current URL
        const url = window.location.origin + '/impact_digital_cbt/student/download-resource.php?id=' + resourceId;

        // Try to use the Web Share API if available
        if (navigator.share) {
            navigator.share({
                    title: 'Library Resource',
                    text: 'Check out this learning resource',
                    url: url
                })
                .then(() => console.log('Shared successfully'))
                .catch(error => console.log('Sharing failed:', error));
        } else {
            // Fallback: copy to clipboard
            navigator.clipboard.writeText(url).then(() => {
                alert('Resource link copied to clipboard! 📋');
            }).catch(err => {
                // Fallback for older browsers
                const tempInput = document.createElement('input');
                tempInput.value = url;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);
                alert('Resource link copied to clipboard! 📋');
            });
        }
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        const infoModal = document.getElementById('infoModal');

        if (event.target === infoModal) {
            closeInfoModal();
        }
    }

    // Auto-refresh notification for new resources
    let lastCheckTime = new Date().getTime();

    function checkForNewResources() {
        fetch('check-new-resources.php?last_check=' + lastCheckTime)
            .then(response => response.json())
            .then(data => {
                if (data.new_count > 0) {
                    showNewResourcesNotification(data.new_count);
                    lastCheckTime = new Date().getTime();
                }
            })
            .catch(error => console.error('Error checking for new resources:', error));
    }

    function showNewResourcesNotification(count) {
        const notification = document.createElement('div');
        notification.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: linear-gradient(135deg, ${'<?php echo COLOR_SUCCESS; ?>'}, #27ae60);
        color: white;
        padding: 15px 20px;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        z-index: 1000;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: slideIn 0.3s ease;
    `;

        notification.innerHTML = `
        <div style="font-size: 1.5em;">🎉</div>
        <div>
            <strong>${count} new resource${count > 1 ? 's' : ''} added!</strong>
            <div style="font-size: 0.8em; opacity: 0.9;">Click to refresh</div>
        </div>
    `;

        notification.onclick = () => {
            notification.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => {
                if (document.body.contains(notification)) {
                    document.body.removeChild(notification);
                }
                location.reload();
            }, 300);
        };

        document.body.appendChild(notification);

        // Auto-remove after 10 seconds
        setTimeout(() => {
            if (document.body.contains(notification)) {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (document.body.contains(notification)) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }
        }, 10000);
    }

    // Add CSS animations
    const style = document.createElement('style');
    style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
    document.head.appendChild(style);

    // Check for new resources every 2 minutes
    setInterval(checkForNewResources, 120000);

    // Initialize
    checkForNewResources();
</script>

<?php
// Helper functions
function getFileIcon($file_type)
{
    $ext = strtolower(pathinfo($file_type, PATHINFO_EXTENSION));
    $icons = [
        'pdf' => '📕',
        'doc' => '📘',
        'docx' => '📘',
        'ppt' => '📙',
        'pptx' => '📙',
        'xls' => '📗',
        'xlsx' => '📗',
        'jpg' => '🖼️',
        'jpeg' => '🖼️',
        'png' => '🖼️',
        'gif' => '🖼️',
        'mp4' => '🎬',
        'avi' => '🎬',
        'mov' => '🎬',
        'wmv' => '🎬',
        'mp3' => '🎵',
        'wav' => '🎵',
        'ogg' => '🎵',
        'txt' => '📄',
        'zip' => '📦',
        'rar' => '📦',
        'html' => '🌐',
        'htm' => '🌐'
    ];

    return $icons[$ext] ?? '📁';
}

function timeAgo($datetime)
{
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins != 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours != 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days != 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $weeks = floor($diff / 604800);
        return $weeks . ' week' . ($weeks != 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

function canPreview($file_type)
{
    $ext = strtolower(pathinfo($file_type, PATHINFO_EXTENSION));
    $previewable = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'html', 'htm'];
    return in_array($ext, $previewable);
}

echo displayFooter();
?>