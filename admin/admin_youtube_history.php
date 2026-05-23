<?php
session_start();
require '../db.php';

// Check if user is admin
// You should implement proper authentication here

// First, let's ensure youtube_history table exists with correct structure
$check_table = $conn->query("SHOW TABLES LIKE 'youtube_history'");
if ($check_table->num_rows == 0) {
    // Create table if doesn't exist
    $create_sql = "CREATE TABLE IF NOT EXISTS youtube_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        video_id INT NOT NULL,
        viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (video_id) REFERENCES youtube_videos(id) ON DELETE CASCADE
    )";
    $conn->query($create_sql);
} else {
    // Check if student_id column is INT, if not alter it
    $check_column = $conn->query("SHOW COLUMNS FROM youtube_history LIKE 'student_id'");
    if ($check_column && $check_column->num_rows > 0) {
        $column_info = $check_column->fetch_assoc();
        if (strpos($column_info['Type'], 'varchar') !== false || strpos($column_info['Type'], 'char') !== false) {
            // Alter column to INT
            $alter_sql = "ALTER TABLE youtube_history MODIFY student_id INT NOT NULL";
            $conn->query($alter_sql);
        }
    }
}

// Get total views count
$total_views = 0;
$views_result = $conn->query("SELECT COUNT(*) as total FROM youtube_history");
if ($views_result) {
    $total_views = $views_result->fetch_assoc()['total'];
}

// Get history with student names if possible
$sql = "
SELECT 
    yh.id,
    yh.viewed_at,
    yh.student_id,
    y.title as video_title,
    y.class_name,
    y.medium,
    CONCAT(COALESCE(se.first_name, sh.first_name, 'Unknown'), ' ', COALESCE(se.last_name, sh.last_name, 'Student')) as student_name,
    COALESCE(se.medium, sh.medium) as student_medium
FROM youtube_history yh
LEFT JOIN youtube_videos y ON yh.video_id = y.id
LEFT JOIN student_english se ON yh.student_id = se.id
LEFT JOIN student_hindi sh ON yh.student_id = sh.id
ORDER BY yh.viewed_at DESC
LIMIT 100
";

$result = $conn->query($sql);

if (!$result) {
    error_log("Query failed: " . $conn->error);
    // Fallback to simple query if above fails
    $sql = "
    SELECT yh.id, yh.viewed_at, yh.student_id, y.title as video_title, 
           y.class_name, y.medium, 'Unknown Student' as student_name
    FROM youtube_history yh
    LEFT JOIN youtube_videos y ON yh.video_id = y.id
    ORDER BY yh.viewed_at DESC
    LIMIT 100
    ";
    $result = $conn->query($sql);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube History | Ruchi Classes Admin</title>
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
            background-attachment: fixed;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 15px;
            padding: 20px 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo-img {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            object-fit: contain;
            border: 3px solid #2563eb;
            background: white;
            padding: 3px;
        }
        
        .logo-text {
            font-size: 24px;
            font-weight: bold;
            color: #2563eb;
        }
        
        .logo-text span {
            display: block;
            font-size: 12px;
            font-weight: normal;
            color: #666;
        }
        
        .nav-buttons {
            display: flex;
            gap: 10px;
        }
        
        .nav-btn {
            padding: 10px 20px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .nav-btn:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.3);
        }
        
        .nav-btn.secondary {
            background: #f8fafc;
            color: #1e293b;
            border: 1px solid #e2e8f0;
        }
        
        .nav-btn.secondary:hover {
            background: #f1f5f9;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #2563eb, #3b82f6);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            font-size: 40px;
            color: #2563eb;
            margin-bottom: 15px;
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: bold;
            color: #1e293b;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .main-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .page-title {
            font-size: 28px;
            color: #1e293b;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .page-subtitle {
            color: #64748b;
            margin-bottom: 30px;
        }
        
        .table-container {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        .history-table th {
            background: #f8fafc;
            padding: 15px;
            text-align: left;
            color: #475569;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        
        .history-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            color: #1e293b;
            vertical-align: top;
        }
        
        .history-table tr:hover {
            background: #f8fafc;
        }
        
        .history-table tr:last-child td {
            border-bottom: none;
        }
        
        .student-info {
            min-width: 200px;
        }
        
        .student-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 5px;
        }
        
        .student-details {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 13px;
        }
        
        .student-id {
            font-family: 'Courier New', monospace;
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 4px;
            color: #475569;
        }
        
        .video-info {
            max-width: 300px;
        }
        
        .video-title {
            font-weight: 500;
            margin-bottom: 5px;
            line-height: 1.4;
        }
        
        .badge-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-class {
            background: #dbeafe;
            color: #1d4ed8;
        }
        
        .badge-medium {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-hindi {
            background: #dcfce7;
            color: #166534;
        }
        
        .badge-english {
            background: #f3e8ff;
            color: #6b21a8;
        }
        
        .badge-both {
            background: #fce7f3;
            color: #9d174d;
        }
        
        .time-info {
            min-width: 180px;
        }
        
        .viewed-date {
            font-weight: 500;
            margin-bottom: 3px;
        }
        
        .viewed-time {
            font-size: 13px;
            color: #64748b;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        .no-data i {
            font-size: 60px;
            margin-bottom: 20px;
            color: #cbd5e1;
            opacity: 0.5;
        }
        
        .no-data h3 {
            font-size: 22px;
            margin-bottom: 10px;
            color: #475569;
        }
        
        .no-data p {
            max-width: 400px;
            margin: 0 auto;
            line-height: 1.6;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .table-actions {
            display: flex;
            gap: 10px;
        }
        
        .action-btn {
            padding: 8px 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: #475569;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .action-btn:hover {
            background: #f1f5f9;
            color: #1e293b;
        }
        
        .action-btn.primary {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }
        
        .action-btn.primary:hover {
            background: #1d4ed8;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
                padding: 15px;
            }
            
            .logo-container {
                justify-content: center;
            }
            
            .nav-buttons {
                justify-content: center;
                flex-wrap: wrap;
                width: 100%;
            }
            
            .nav-btn {
                flex: 1;
                min-width: 160px;
                justify-content: center;
            }
            
            .main-content {
                padding: 20px;
            }
            
            .table-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .table-actions {
                justify-content: center;
            }
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .stat-card {
                padding: 20px;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 10px;
            }
            
            .nav-btn {
                min-width: 140px;
                padding: 8px 15px;
                font-size: 14px;
            }
            
            .page-title {
                font-size: 24px;
                flex-direction: column;
                text-align: center;
                gap: 5px;
            }
            
            .action-btn {
                padding: 6px 12px;
                font-size: 13px;
            }
            
            .history-table th,
            .history-table td {
                padding: 12px 10px;
            }
        }
        
        /* Loading animation for table */
        .loading {
            position: relative;
            opacity: 0.7;
        }
        
        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 40px;
            height: 40px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #2563eb;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo-container">
                <img src="../assets/Ruchi logo.jpg" alt="Ruchi Classes Logo" class="logo-img">
                <div class="logo-text">
                    Ruchi Classes
                    <span>YouTube Watch History</span>
                </div>
            </div>
            <div class="nav-buttons">
                <a href="admin_dashboard.php" class="nav-btn secondary">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
                <a href="admin_videos.php" class="nav-btn">
                    <i class="fas fa-plus-circle"></i>
                    Add Video
                </a>
                <a href="admin_youtube.php" class="nav-btn secondary">
                    <i class="fas fa-list"></i>
                    Video List
                </a>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="stats-cards">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-history"></i>
                </div>
                <div class="stat-value"><?php echo number_format($total_views); ?></div>
                <div class="stat-label">Total Video Views</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-video"></i>
                </div>
                <div class="stat-value">
                    <?php 
                    $video_count = 0;
                    $video_result = $conn->query("SELECT COUNT(*) as total FROM youtube_videos");
                    if ($video_result) {
                        $video_count = $video_result->fetch_assoc()['total'];
                    }
                    echo number_format($video_count);
                    ?>
                </div>
                <div class="stat-label">Available Videos</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value">
                    <?php 
                    $student_count = 0;
                    $eng_count = 0;
                    $hin_count = 0;
                    $eng_result = $conn->query("SELECT COUNT(*) as total FROM student_english");
                    $hin_result = $conn->query("SELECT COUNT(*) as total FROM student_hindi");
                    
                    if ($eng_result) $eng_count = $eng_result->fetch_assoc()['total'];
                    if ($hin_result) $hin_count = $hin_result->fetch_assoc()['total'];
                    
                    $student_count = $eng_count + $hin_count;
                    echo number_format($student_count);
                    ?>
                </div>
                <div class="stat-label">Total Students</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-value">
                    <?php 
                    $avg_views = $student_count > 0 ? round($total_views / $student_count, 1) : 0;
                    echo $avg_views;
                    ?>
                </div>
                <div class="stat-label">Avg Views per Student</div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <div class="table-header">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-history"></i>
                        YouTube Watch History
                    </h1>
                    <p class="page-subtitle">Track which students have watched which educational videos</p>
                </div>
                <div class="table-actions">
                    <button class="action-btn" onclick="refreshTable()">
                        <i class="fas fa-sync-alt"></i>
                        Refresh
                    </button>
                    <button class="action-btn primary" onclick="exportToCSV()">
                        <i class="fas fa-download"></i>
                        Export CSV
                    </button>
                </div>
            </div>
            
            <?php if ($result && $result->num_rows > 0): ?>
            <div class="table-container">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Student Information</th>
                            <th>Video Details</th>
                            <th>Class & Medium</th>
                            <th>Viewing Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $result->fetch_assoc()): 
                            $viewed_at = strtotime($row['viewed_at']);
                            $medium = strtolower($row['medium'] ?? '');
                            $student_medium = strtolower($row['student_medium'] ?? '');
                        ?>
                        <tr>
                            <td class="student-info">
                                <div class="student-name">
                                    <?php echo htmlspecialchars($row['student_name']); ?>
                                </div>
                                <div class="student-details">
                                    <span class="student-id">ID: <?php echo htmlspecialchars($row['student_id']); ?></span>
                                    <?php if ($student_medium): ?>
                                    <span class="badge badge-<?php echo $student_medium; ?>">
                                        <i class="fas fa-language"></i>
                                        <?php echo ucfirst($student_medium); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="video-info">
                                <div class="video-title">
                                    <?php echo htmlspecialchars($row['video_title'] ?? 'Unknown Video'); ?>
                                </div>
                            </td>
                            <td>
                                <div class="badge-container">
                                    <?php if ($row['class_name']): ?>
                                    <span class="badge badge-class">
                                        <i class="fas fa-graduation-cap"></i>
                                        Class <?php echo htmlspecialchars($row['class_name']); ?>
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($medium): ?>
                                    <?php 
                                    $badge_class = 'badge-medium';
                                    if ($medium == 'hindi') $badge_class = 'badge-hindi';
                                    elseif ($medium == 'english') $badge_class = 'badge-english';
                                    elseif ($medium == 'both') $badge_class = 'badge-both';
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <i class="fas fa-language"></i>
                                        <?php echo ucfirst($medium); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="time-info">
                                <div class="viewed-date">
                                    <?php echo date('F j, Y', $viewed_at); ?>
                                </div>
                                <div class="viewed-time">
                                    <?php echo date('h:i A', $viewed_at); ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-history"></i>
                <h3>No watch history found</h3>
                <p>Students haven't watched any videos yet. Videos will appear here once students start watching.</p>
            </div>
            <?php endif; ?>
            
            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 14px; text-align: center;">
                <p>Showing last 100 records. Total views: <?php echo number_format($total_views); ?></p>
            </div>
        </div>
    </div>

    <script>
        function refreshTable() {
            const tableBody = document.querySelector('.history-table tbody');
            const noDataDiv = document.querySelector('.no-data');
            
            // Show loading state
            if (tableBody) {
                tableBody.classList.add('loading');
            }
            
            // Reload the page after a short delay
            setTimeout(() => {
                window.location.reload();
            }, 500);
        }
        
        function exportToCSV() {
            // Get all table data
            const rows = [];
            const headers = [];
            
            // Get headers
            document.querySelectorAll('.history-table thead th').forEach(header => {
                headers.push(header.textContent.trim());
            });
            rows.push(headers.join(','));
            
            // Get data rows
            document.querySelectorAll('.history-table tbody tr').forEach(row => {
                const rowData = [];
                row.querySelectorAll('td').forEach(cell => {
                    // Clean up cell content
                    let text = cell.textContent.trim();
                    // Remove extra whitespace and newlines
                    text = text.replace(/\s+/g, ' ');
                    // Handle commas in CSV
                    if (text.includes(',') || text.includes('"')) {
                        text = '"' + text.replace(/"/g, '""') + '"';
                    }
                    rowData.push(text);
                });
                rows.push(rowData.join(','));
            });
            
            // Create CSV content
            const csvContent = rows.join('\n');
            
            // Create download link
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', `youtube-history-${new Date().toISOString().slice(0,10)}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Show success message
            alert('CSV file downloaded successfully!');
        }
        
        // Auto-refresh every 30 seconds if on the page
        let refreshInterval;
        
        function startAutoRefresh() {
            refreshInterval = setInterval(() => {
                console.log('Auto-refreshing history data...');
                refreshTable();
            }, 30000); // 30 seconds
        }
        
        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        }
        
        // Start auto-refresh when page loads
        document.addEventListener('DOMContentLoaded', () => {
            // Only auto-refresh if we have data
            const hasData = <?php echo ($result && $result->num_rows > 0) ? 'true' : 'false'; ?>;
            if (hasData) {
                startAutoRefresh();
            }
            
            // Stop auto-refresh when user leaves page
            window.addEventListener('blur', stopAutoRefresh);
            window.addEventListener('focus', () => {
                if (hasData) startAutoRefresh();
            });
        });
        
        // Handle beforeunload to stop interval
        window.addEventListener('beforeunload', stopAutoRefresh);
    </script>
</body>
</html>