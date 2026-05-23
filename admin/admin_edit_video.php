<?php
session_start();
require '../db.php';

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit;
}

$video_id = $_GET['id'] ?? 0;
$video = null;
$success = '';
$error = '';

// Get video details
$stmt = $conn->prepare("SELECT * FROM youtube_videos WHERE id = ?");
$stmt->bind_param('i', $video_id);
$stmt->execute();
$result = $stmt->get_result();
$video = $result->fetch_assoc();
$stmt->close();

if (!$video) {
    header('Location: admin_videos.php');
    exit;
}

// Update video
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $link = trim($_POST['link']);
    $class = $_POST['class'];
    $medium = $_POST['medium'];
    $subject = trim($_POST['subject']);
    $chapter = trim($_POST['chapter']);
    $is_active = $_POST['is_active'] ?? 0;
    
    if (empty($title) || empty($link) || empty($class) || empty($medium)) {
        $error = "Please fill all required fields!";
    } else {
        // Extract video ID
        $video_id_new = '';
        if (preg_match('/youtu\.be\/([^\?]+)/', $link, $matches)) {
            $video_id_new = $matches[1];
        } elseif (preg_match('/youtube\.com.*[?&]v=([^&]+)/', $link, $matches)) {
            $video_id_new = $matches[1];
        } else {
            $video_id_new = preg_replace('/[^a-zA-Z0-9_-]/', '', $link);
        }
        
        // Update video
        $sql = "UPDATE youtube_videos SET 
                title = ?, description = ?, video_id = ?, file_path = ?, 
                class_name = ?, medium = ?, subject = ?, chapter = ?, 
                is_active = ?, updated_at = NOW() 
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssssssssii', $title, $description, $video_id_new, $link, 
                         $class, $medium, $subject, $chapter, $is_active, $video['id']);
        
        if ($stmt->execute()) {
            $success = "Video updated successfully!";
            // Refresh video data
            $video['title'] = $title;
            $video['description'] = $description;
            $video['video_id'] = $video_id_new;
            $video['file_path'] = $link;
            $video['class_name'] = $class;
            $video['medium'] = $medium;
            $video['subject'] = $subject;
            $video['chapter'] = $chapter;
            $video['is_active'] = $is_active;
        } else {
            $error = "Error updating video: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Video | Ruchi Classes</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f7fa; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .header { background: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
        .logo-container { display: flex; align-items: center; gap: 15px; }
        .logo-img { width: 50px; height: 50px; border-radius: 10px; object-fit: cover; border: 3px solid #2563eb; }
        .logo-text { font-size: 24px; font-weight: bold; color: #2563eb; }
        .logo-text span { display: block; font-size: 12px; font-weight: normal; color: #666; }
        .nav-buttons { display: flex; gap: 10px; margin-top: 15px; }
        .nav-btn { padding: 10px 20px; background: #2563eb; color: white; text-decoration: none; border-radius: 8px; display: inline-flex; align-items: center; gap: 8px; }
        .nav-btn:hover { background: #1d4ed8; }
        .nav-btn.secondary { background: #f8fafc; color: #1e293b; border: 1px solid #e2e8f0; }
        .main-content { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); }
        .page-title { font-size: 26px; color: #1e293b; margin-bottom: 10px; display: flex; align-items: center; gap: 10px; }
        .page-subtitle { color: #64748b; margin-bottom: 25px; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; color: #475569; font-weight: 500; }
        .form-input, .form-textarea, .form-select { width: 100%; padding: 12px 15px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; }
        .form-input:focus, .form-textarea:focus, .form-select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        .form-textarea { min-height: 100px; resize: vertical; }
        .form-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23475569' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 15px center; background-size: 12px; }
        .checkbox-group { display: flex; align-items: center; gap: 10px; }
        .checkbox-label { font-size: 14px; color: #475569; }
        .btn-submit { background: #2563eb; color: white; padding: 12px 30px; border: none; border-radius: 8px; font-size: 16px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 10px; }
        .btn-submit:hover { background: #1d4ed8; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
        .video-preview { margin-top: 20px; padding: 20px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; }
        .preview-title { font-size: 16px; font-weight: 600; color: #475569; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .preview-thumbnail { max-width: 200px; border-radius: 8px; overflow: hidden; margin-bottom: 10px; }
        .preview-thumbnail img { width: 100%; height: auto; }
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .main-content { padding: 20px; }
            .nav-buttons { flex-direction: column; }
            .nav-btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-container">
                <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes Logo" class="logo-img">
                <div class="logo-text">
                    Ruchi Classes
                    <span>Edit Video</span>
                </div>
            </div>
            <div class="nav-buttons">
                <a href="admin_videos.php" class="nav-btn secondary">
                    <i class="fas fa-arrow-left"></i>
                    Back to Videos
                </a>
                <a href="admin_dashboard.php" class="nav-btn secondary">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
            </div>
        </div>
        
        <div class="main-content">
            <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
            <?php elseif ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
            <?php endif; ?>
            
            <h1 class="page-title">
                <i class="fas fa-edit"></i>
                Edit Video: <?php echo htmlspecialchars($video['title']); ?>
            </h1>
            <p class="page-subtitle">Update the video details below</p>
            
            <div class="video-preview">
                <div class="preview-title">
                    <i class="fas fa-eye"></i>
                    Video Preview
                </div>
                <div class="preview-thumbnail">
                    <img src="https://img.youtube.com/vi/<?php echo $video['video_id']; ?>/hqdefault.jpg" 
                         alt="Video Thumbnail"
                         onerror="this.src='https://via.placeholder.com/200x112/1e293b/ffffff?text=Thumbnail'">
                </div>
                <div style="font-size: 14px; color: #475569;">
                    Video ID: <code><?php echo $video['video_id']; ?></code>
                </div>
            </div>
            
            <form method="POST" action="" style="margin-top: 25px;">
                <div class="form-group">
                    <label class="form-label">Video Title *</label>
                    <input type="text" name="title" class="form-input" required value="<?php echo htmlspecialchars($video['title']); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-textarea"><?php echo htmlspecialchars($video['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">YouTube URL or Video ID *</label>
                    <input type="text" name="link" class="form-input" required placeholder="https://youtube.com/watch?v=..." value="<?php echo htmlspecialchars($video['file_path']); ?>">
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label class="form-label">Class *</label>
                        <select name="class" class="form-select" required>
                            <option value="8" <?php echo $video['class_name'] == '8' ? 'selected' : ''; ?>>Class 8</option>
                            <option value="9" <?php echo $video['class_name'] == '9' ? 'selected' : ''; ?>>Class 9</option>
                            <option value="10" <?php echo $video['class_name'] == '10' ? 'selected' : ''; ?>>Class 10</option>
                            <option value="11" <?php echo $video['class_name'] == '11' ? 'selected' : ''; ?>>Class 11</option>
                            <option value="12" <?php echo $video['class_name'] == '12' ? 'selected' : ''; ?>>Class 12</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Medium *</label>
                        <select name="medium" class="form-select" required>
                            <option value="hindi" <?php echo $video['medium'] == 'hindi' ? 'selected' : ''; ?>>Hindi</option>
                            <option value="english" <?php echo $video['medium'] == 'english' ? 'selected' : ''; ?>>English</option>
                            <option value="both" <?php echo $video['medium'] == 'both' ? 'selected' : ''; ?>>Both</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" name="subject" class="form-input" value="<?php echo htmlspecialchars($video['subject'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Chapter/Topic</label>
                        <input type="text" name="chapter" class="form-input" value="<?php echo htmlspecialchars($video['chapter'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_active" id="is_active" value="1" <?php echo $video['is_active'] ? 'checked' : ''; ?>>
                        <label class="checkbox-label" for="is_active">Active (Show to students)</label>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i>
                    Update Video
                </button>
            </form>
        </div>
    </div>
</body>
</html>