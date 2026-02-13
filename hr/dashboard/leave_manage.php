<?php
// leave_manage.php - Complete Leave Management System for HR Dashboard
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hr_management_portal');

// Check if HR is logged in
if (!isset($_SESSION['hr_logged_in']) || $_SESSION['hr_logged_in'] !== true) {
    // For demo purposes, auto-login
    $_SESSION['hr_logged_in'] = true;
    $_SESSION['hr_id'] = 1;
    $_SESSION['hr_email'] = 'hr@hrportal.com';
    $_SESSION['hr_role'] = 'hr';
}

// Create database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Get HR details
$hr_id = $_SESSION['hr_id'] ?? 1;
$hr_email = $_SESSION['hr_email'] ?? 'hr@hrportal.com';

// Handle AJAX requests first
if (isset($_GET['ajax'])) {
    $ajax_action = $_GET['ajax'] ?? '';
    
    if ($ajax_action === 'get_leave_details') {
        $id = $_GET['id'] ?? 0;
        if ($id) {
            getLeaveDetails($id, $conn);
        }
        exit;
    }
    
    if ($ajax_action === 'get_pending_count') {
        getPendingCount($conn);
        exit;
    }
}

// Handle form submissions
$message = '';
$message_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_leave'])) {
        $leave_id = intval($_POST['leave_id']);
        $result = $conn->query("UPDATE leave_requests SET status = 'approved', approved_by = $hr_id, approved_at = NOW() WHERE id = $leave_id");
        if ($result) {
            $message = "Leave request approved successfully!";
            $message_type = "success";
            
            // Add audit log
            $conn->query("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, created_at) 
                         VALUES ($hr_id, 'Leave Approved', 'leave_requests', $leave_id, NOW())");
        } else {
            $message = "Error approving leave request.";
            $message_type = "error";
        }
    } 
    elseif (isset($_POST['reject_leave'])) {
        $leave_id = intval($_POST['leave_id']);
        $reason = $conn->real_escape_string($_POST['rejection_reason'] ?? '');
        $result = $conn->query("UPDATE leave_requests SET status = 'rejected', approved_by = $hr_id, approved_at = NOW(), rejection_reason = '$reason' WHERE id = $leave_id");
        if ($result) {
            $message = "Leave request rejected successfully!";
            $message_type = "success";
            
            // Add audit log
            $conn->query("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, created_at) 
                         VALUES ($hr_id, 'Leave Rejected', 'leave_requests', $leave_id, NOW())");
        } else {
            $message = "Error rejecting leave request.";
            $message_type = "error";
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$user_type_filter = $_GET['user_type'] ?? '';
$leave_type_filter = $_GET['leave_type'] ?? '';
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Get leave requests with filters
$leave_requests = getLeaveRequests($conn, $status_filter, $user_type_filter, $leave_type_filter, $search, $date_from, $date_to);

// Get leave types for filter
$leave_types = [];
$result = $conn->query("SELECT * FROM leave_types ORDER BY name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $leave_types[] = $row;
    }
}

// Get statistics
$stats = getLeaveStatistics($conn);

// Get recent leave approvals (last 7 days)
$recent_approvals = getRecentApprovals($conn);

// Get leave by type statistics for chart
$leave_type_stats = getLeaveTypeStats($conn);

$conn->close();

// Function definitions
function getLeaveRequests($conn, $status_filter, $user_type_filter, $leave_type_filter, $search, $date_from, $date_to) {
    $query = "
        SELECT lr.*, 
               u.email as user_email,
               lt.name as leave_type_name,
               CONCAT(
                   CASE 
                       WHEN e.first_name IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name)
                       WHEN i.first_name IS NOT NULL THEN CONCAT(i.first_name, ' ', i.last_name)
                       WHEN t.first_name IS NOT NULL THEN CONCAT(t.first_name, ' ', t.last_name)
                       ELSE 'User'
                   END
               ) as user_name,
               CASE lr.user_type
                   WHEN 'employee' THEN 'Employee'
                   WHEN 'intern' THEN 'Intern'
                   WHEN 'trainer' THEN 'Trainer'
                   ELSE 'Unknown'
               END as user_type_display
        FROM leave_requests lr
        LEFT JOIN users u ON lr.user_id = u.id
        LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
        LEFT JOIN employees e ON lr.user_id = e.user_id AND lr.user_type = 'employee'
        LEFT JOIN interns i ON lr.user_id = i.user_id AND lr.user_type = 'intern'
        LEFT JOIN trainers t ON lr.user_id = t.user_id AND lr.user_type = 'trainer'
        WHERE 1=1
    ";
    
    // Apply filters
    if ($status_filter) {
        $query .= " AND lr.status = '" . $conn->real_escape_string($status_filter) . "'";
    }
    if ($user_type_filter) {
        $query .= " AND lr.user_type = '" . $conn->real_escape_string($user_type_filter) . "'";
    }
    if ($leave_type_filter) {
        $query .= " AND lr.leave_type_id = " . intval($leave_type_filter);
    }
    if ($date_from) {
        $query .= " AND lr.start_date >= '" . $conn->real_escape_string($date_from) . "'";
    }
    if ($date_to) {
        $query .= " AND lr.end_date <= '" . $conn->real_escape_string($date_to) . "'";
    }
    if ($search) {
        $search_escaped = $conn->real_escape_string($search);
        $query .= " AND (
            u.email LIKE '%$search_escaped%' OR 
            e.first_name LIKE '%$search_escaped%' OR 
            e.last_name LIKE '%$search_escaped%' OR
            i.first_name LIKE '%$search_escaped%' OR 
            i.last_name LIKE '%$search_escaped%' OR
            t.first_name LIKE '%$search_escaped%' OR 
            t.last_name LIKE '%$search_escaped%'
        )";
    }
    
    $query .= " ORDER BY lr.created_at DESC";
    
    $requests = [];
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Ensure all required fields exist
            $row['user_type_display'] = $row['user_type_display'] ?? 'Unknown';
            $row['user_name'] = $row['user_name'] ?? 'User';
            $row['user_email'] = $row['user_email'] ?? 'N/A';
            $row['leave_type_name'] = $row['leave_type_name'] ?? 'Unknown';
            $requests[] = $row;
        }
    }
    return $requests;
}

function getLeaveStatistics($conn) {
    $stats = [
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
        'total' => 0
    ];
    
    $result = $conn->query("SELECT status, COUNT(*) as count FROM leave_requests GROUP BY status");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $stats[$row['status']] = $row['count'];
            $stats['total'] += $row['count'];
        }
    }
    return $stats;
}

function getRecentApprovals($conn) {
    $approvals = [];
    $result = $conn->query("
        SELECT lr.*, u.email as user_email, lt.name as leave_type_name,
               CONCAT(
                   CASE 
                       WHEN e.first_name IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name)
                       WHEN i.first_name IS NOT NULL THEN CONCAT(i.first_name, ' ', i.last_name)
                       WHEN t.first_name IS NOT NULL THEN CONCAT(t.first_name, ' ', t.last_name)
                       ELSE 'User'
                   END
               ) as user_name,
               CASE lr.user_type
                   WHEN 'employee' THEN 'Employee'
                   WHEN 'intern' THEN 'Intern'
                   WHEN 'trainer' THEN 'Trainer'
                   ELSE 'Unknown'
               END as user_type_display
        FROM leave_requests lr
        LEFT JOIN users u ON lr.user_id = u.id
        LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
        LEFT JOIN employees e ON lr.user_id = e.user_id AND lr.user_type = 'employee'
        LEFT JOIN interns i ON lr.user_id = i.user_id AND lr.user_type = 'intern'
        LEFT JOIN trainers t ON lr.user_id = t.user_id AND lr.user_type = 'trainer'
        WHERE lr.approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND lr.status = 'approved'
        ORDER BY lr.approved_at DESC
        LIMIT 5
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['user_type_display'] = $row['user_type_display'] ?? 'Unknown';
            $approvals[] = $row;
        }
    }
    return $approvals;
}

function getLeaveTypeStats($conn) {
    $stats = [];
    $result = $conn->query("
        SELECT lt.name, COUNT(lr.id) as count
        FROM leave_types lt
        LEFT JOIN leave_requests lr ON lt.id = lr.leave_type_id AND lr.status = 'approved'
        GROUP BY lt.id
        ORDER BY count DESC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $stats[] = $row;
        }
    }
    return $stats;
}

function getLeaveDetails($id, $conn) {
    $id = intval($id);
    $query = "
        SELECT lr.*, 
               u.email as user_email,
               lt.name as leave_type_name,
               lt.description as leave_type_desc,
               lt.max_days_per_year,
               lt.is_paid,
               CONCAT(
                   CASE 
                       WHEN e.first_name IS NOT NULL THEN CONCAT(e.first_name, ' ', e.last_name)
                       WHEN i.first_name IS NOT NULL THEN CONCAT(i.first_name, ' ', i.last_name)
                       WHEN t.first_name IS NOT NULL THEN CONCAT(t.first_name, ' ', t.last_name)
                       ELSE 'User'
                   END
               ) as user_name,
               lr.user_type,
               a.email as approved_by_email
        FROM leave_requests lr
        LEFT JOIN users u ON lr.user_id = u.id
        LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
        LEFT JOIN employees e ON lr.user_id = e.user_id AND lr.user_type = 'employee'
        LEFT JOIN interns i ON lr.user_id = i.user_id AND lr.user_type = 'intern'
        LEFT JOIN trainers t ON lr.user_id = t.user_id AND lr.user_type = 'trainer'
        LEFT JOIN users a ON lr.approved_by = a.id
        WHERE lr.id = $id
    ";
    
    $result = $conn->query($query);
    if (!$result || $result->num_rows === 0) {
        echo '<div style="color: var(--danger); text-align: center; padding: 20px;">
                <i class="fas fa-exclamation-circle fa-2x"></i>
                <p style="margin-top: 10px;">Leave request not found</p>
              </div>';
        exit;
    }
    
    $leave = $result->fetch_assoc();
    
    // Ensure all required fields exist
    $leave['user_name'] = $leave['user_name'] ?? 'User';
    $leave['user_email'] = $leave['user_email'] ?? 'N/A';
    $leave['leave_type_name'] = $leave['leave_type_name'] ?? 'Unknown';
    $leave['leave_type_desc'] = $leave['leave_type_desc'] ?? '';
    $leave['max_days_per_year'] = $leave['max_days_per_year'] ?? 0;
    $leave['is_paid'] = $leave['is_paid'] ?? 0;
    
    // Calculate if this is within policy
    $days_left = $leave['max_days_per_year'] - $leave['total_days'];
    $within_policy = $days_left >= 0;
    
    // Output HTML for modal
    ?>
    <div class="leave-details">
        <div class="detail-header">
            <div class="user-info-large">
                <div class="user-avatar-large">
                    <?php echo strtoupper(substr($leave['user_name'], 0, 2)); ?>
                </div>
                <div>
                    <h4><?php echo htmlspecialchars($leave['user_name']); ?></h4>
                    <p><?php echo htmlspecialchars($leave['user_email']); ?></p>
                    <span class="user-type-badge"><?php echo ucfirst($leave['user_type'] ?? 'Unknown'); ?></span>
                </div>
            </div>
            
            <div class="status-display">
                <span class="status-badge status-<?php echo $leave['status']; ?> large">
                    <?php echo ucfirst($leave['status']); ?>
                </span>
            </div>
        </div>
        
        <div class="detail-grid">
            <div class="detail-item">
                <label>Leave Type:</label>
                <strong><?php echo htmlspecialchars($leave['leave_type_name']); ?></strong>
                <small><?php echo htmlspecialchars($leave['leave_type_desc']); ?></small>
            </div>
            
            <div class="detail-item">
                <label>Date Range:</label>
                <strong>
                    <?php echo date('F d, Y', strtotime($leave['start_date'])); ?> 
                    to 
                    <?php echo date('F d, Y', strtotime($leave['end_date'])); ?>
                </strong>
            </div>
            
            <div class="detail-item">
                <label>Duration:</label>
                <strong><?php echo $leave['total_days']; ?> day(s)</strong>
            </div>
            
            <div class="detail-item">
                <label>Applied On:</label>
                <strong><?php echo date('F d, Y H:i', strtotime($leave['created_at'])); ?></strong>
            </div>
            
            <div class="detail-item">
                <label>Leave Policy:</label>
                <strong><?php echo $leave['max_days_per_year']; ?> days/year max</strong>
                <small style="color: <?php echo $within_policy ? 'var(--success)' : 'var(--danger)'; ?>">
                    <?php echo $within_policy ? 'Within policy' : 'Exceeds policy'; ?>
                </small>
            </div>
            
            <div class="detail-item">
                <label>Payment Status:</label>
                <strong><?php echo $leave['is_paid'] ? 'Paid Leave' : 'Unpaid Leave'; ?></strong>
            </div>
        </div>
        
        <div class="detail-section">
            <label>Reason for Leave:</label>
            <div class="reason-box">
                <?php echo nl2br(htmlspecialchars($leave['reason'] ?? 'No reason provided')); ?>
            </div>
        </div>
        
        <?php if ($leave['status'] != 'pending'): ?>
        <div class="detail-section">
            <label>Processed By:</label>
            <div class="processed-info">
                <strong><?php echo htmlspecialchars($leave['approved_by_email'] ?? 'N/A'); ?></strong>
                <small>on <?php echo $leave['approved_at'] ? date('F d, Y H:i', strtotime($leave['approved_at'])) : 'N/A'; ?></small>
            </div>
        </div>
        
        <?php if ($leave['status'] == 'rejected' && !empty($leave['rejection_reason'])): ?>
        <div class="detail-section">
            <label>Rejection Reason:</label>
            <div class="rejection-box">
                <?php echo nl2br(htmlspecialchars($leave['rejection_reason'])); ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <style>
        .leave-details {
            color: var(--dark);
        }
        
        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .user-info-large {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar-large {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }
        
        .user-info-large h4 {
            margin: 0 0 5px 0;
            font-size: 18px;
        }
        
        .user-info-large p {
            margin: 0;
            font-size: 14px;
            color: var(--gray);
        }
        
        .user-type-badge {
            background: var(--light);
            color: var(--dark);
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 5px;
            display: inline-block;
        }
        
        .status-badge.large {
            font-size: 14px;
            padding: 8px 20px;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .detail-item {
            background: #f8fafc;
            padding: 15px;
            border-radius: 10px;
        }
        
        .detail-item label {
            display: block;
            font-size: 12px;
            color: var(--gray);
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .detail-item strong {
            display: block;
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .detail-item small {
            font-size: 12px;
            color: var(--gray);
        }
        
        .detail-section {
            margin-bottom: 20px;
        }
        
        .detail-section label {
            display: block;
            font-weight: 500;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .reason-box, .rejection-box {
            background: #f8fafc;
            padding: 15px;
            border-radius: 10px;
            border-left: 4px solid var(--primary);
            font-size: 14px;
            line-height: 1.6;
        }
        
        .rejection-box {
            border-left-color: var(--danger);
            background: rgba(239, 68, 68, 0.05);
        }
        
        .processed-info {
            padding: 10px 0;
        }
        
        .processed-info strong {
            display: block;
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .processed-info small {
            font-size: 12px;
            color: var(--gray);
        }
    </style>
    <?php
    exit;
}

function getPendingCount($conn) {
    $result = $conn->query("SELECT COUNT(*) as pending FROM leave_requests WHERE status = 'pending'");
    $data = $result->fetch_assoc();
    header('Content-Type: application/json');
    echo json_encode(['pending' => $data['pending'] ?? 0]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Management - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar_hr.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-calendar-alt"></i> Leave Management</h1>
                <p>Manage employee, intern, and trainer leave requests</p>
            </div>
            
            <div style="display: flex; align-items: center; gap: 10px;">
                <a href="?status=pending" class="btn-primary">
                    <i class="fas fa-clock"></i> Pending Requests
                    <?php if ($stats['pending'] > 0): ?>
                    <span class="badge" id="pendingBadge"><?php echo $stats['pending']; ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        
        <!-- Message Display -->
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <?php echo $message; ?>
        </div>
        <?php endif; ?>
        
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total']; ?></h3>
                    <p>Total Requests</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon pending">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['pending']; ?></h3>
                    <p>Pending</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon approved">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['approved']; ?></h3>
                    <p>Approved</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon rejected">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['rejected']; ?></h3>
                    <p>Rejected</p>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3>Filter Leave Requests</h3>
            </div>
            <form method="GET" action="" class="filter-form">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>User Type</label>
                        <select name="user_type" onchange="this.form.submit()">
                            <option value="">All Types</option>
                            <option value="employee" <?php echo $user_type_filter == 'employee' ? 'selected' : ''; ?>>Employee</option>
                            <option value="intern" <?php echo $user_type_filter == 'intern' ? 'selected' : ''; ?>>Intern</option>
                            <option value="trainer" <?php echo $user_type_filter == 'trainer' ? 'selected' : ''; ?>>Trainer</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Leave Type</label>
                        <select name="leave_type" onchange="this.form.submit()">
                            <option value="">All Types</option>
                            <?php foreach ($leave_types as $type): ?>
                            <option value="<?php echo $type['id']; ?>" <?php echo $leave_type_filter == $type['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" value="<?php echo $date_from; ?>" onchange="this.form.submit()">
                    </div>
                    
                    <div class="filter-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" value="<?php echo $date_to; ?>" onchange="this.form.submit()">
                    </div>
                    
                    <div class="filter-group">
                        <label>Search</label>
                        <div class="search-box">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or email">
                            <button type="submit"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <a href="leave_manage.php" class="btn-secondary">Clear Filters</a>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Leave Requests Table -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3>Leave Requests</h3>
                <span class="badge"><?php echo count($leave_requests); ?> found</span>
            </div>
            
            <?php if (empty($leave_requests)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>No leave requests found</h3>
                <p>Try changing your filters or check back later.</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Type</th>
                            <th>Leave Type</th>
                            <th>Dates</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Applied On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leave_requests as $request): ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar-small">
                                        <?php echo strtoupper(substr($request['user_name'], 0, 2)); ?>
                                    </div>
                                    <div class="user-info-small">
                                        <strong><?php echo htmlspecialchars($request['user_name']); ?></strong>
                                        <span><?php echo htmlspecialchars($request['user_email']); ?></span>
                                        <small><?php echo $request['user_type_display']; ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo $request['user_type_display']; ?></td>
                            <td><?php echo htmlspecialchars($request['leave_type_name']); ?></td>
                            <td>
                                <?php echo date('M d, Y', strtotime($request['start_date'])); ?> 
                                to 
                                <?php echo date('M d, Y', strtotime($request['end_date'])); ?>
                            </td>
                            <td><?php echo $request['total_days']; ?> day(s)</td>
                            <td>
                                <span class="status-badge status-<?php echo $request['status']; ?>">
                                    <?php echo ucfirst($request['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($request['created_at'])); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button onclick="viewLeaveDetails(<?php echo $request['id']; ?>)" class="btn-icon" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <?php if ($request['status'] == 'pending'): ?>
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="leave_id" value="<?php echo $request['id']; ?>">
                                        <button type="submit" name="approve_leave" class="btn-icon success" title="Approve" onclick="return confirm('Approve this leave request?')">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                    <button onclick="rejectLeave(<?php echo $request['id']; ?>)" class="btn-icon danger" title="Reject">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($request['status'] != 'pending' && !empty($request['approved_by'])): ?>
                                    <span class="approved-by" title="Approved by HR">
                                        <i class="fas fa-user-check"></i>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Charts and Recent Approvals -->
        <div class="dashboard-grid">
            <!-- Leave Type Distribution -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>Leave Type Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="leaveTypeChart"></canvas>
                </div>
            </div>
            
            <!-- Recent Approvals -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>Recent Approvals (7 days)</h3>
                    <a href="?status=approved">View All</a>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Leave Type</th>
                                <th>Duration</th>
                                <th>Approved On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_approvals as $approval): ?>
                            <tr>
                                <td>
                                    <div class="user-cell">
                                        <div class="user-avatar-small">
                                            <?php echo strtoupper(substr($approval['user_name'] ?? 'U', 0, 1)); ?>
                                        </div>
                                        <div>
                                            <strong><?php echo htmlspecialchars($approval['user_name'] ?? 'User'); ?></strong>
                                            <small><?php echo $approval['user_type_display'] ?? 'Unknown'; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($approval['leave_type_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo $approval['total_days']; ?> days</td>
                                <td><?php echo $approval['approved_at'] ? date('M d', strtotime($approval['approved_at'])) : 'N/A'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal for Leave Details -->
    <div id="leaveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Leave Request Details</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="leaveDetails">
                <!-- Content will be loaded via AJAX -->
            </div>
        </div>
    </div>
    
    <!-- Modal for Reject Leave -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Leave Request</h3>
                <button class="close-modal" onclick="closeRejectModal()">&times;</button>
            </div>
            <form method="POST" action="" onsubmit="return validateRejectForm()">
                <div class="modal-body">
                    <input type="hidden" name="leave_id" id="rejectLeaveId">
                    <div class="form-group">
                        <label for="rejection_reason">Reason for Rejection</label>
                        <textarea name="rejection_reason" id="rejection_reason" rows="4" 
                                  placeholder="Please provide a reason for rejecting this leave request..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" name="reject_leave" class="btn-danger">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>
    
    <style>
        /* Main Content Styles */
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --secondary: #7e22ce;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: #f1f5f9;
            color: var(--dark);
            overflow-x: hidden;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }
        
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }
        
        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-title h1 {
            font-size: 32px;
            color: var(--dark);
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .page-title p {
            color: var(--gray);
            font-size: 14px;
        }
        
        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(79, 70, 229, 0.3);
        }
        
        .btn-secondary {
            background: var(--light);
            color: var(--dark);
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 500;
            display: inline-block;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .btn-icon {
            background: var(--light);
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--dark);
        }
        
        .btn-icon.success {
            color: var(--success);
        }
        
        .btn-icon.danger {
            color: var(--danger);
        }
        
        .btn-icon:hover {
            transform: translateY(-2px);
        }
        
        .btn-icon.success:hover {
            background: rgba(16, 185, 129, 0.1);
        }
        
        .btn-icon.danger:hover {
            background: rgba(239, 68, 68, 0.1);
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            font-size: 24px;
            color: white;
        }
        
        .stat-icon.total { background: linear-gradient(135deg, var(--primary) 0%, var(--info) 100%); }
        .stat-icon.pending { background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%); }
        .stat-icon.approved { background: linear-gradient(135deg, var(--success) 0%, #34d399 100%); }
        .stat-icon.rejected { background: linear-gradient(135deg, var(--danger) 0%, #f87171 100%); }
        
        .stat-info h3 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-info p {
            color: var(--gray);
            font-size: 14px;
        }
        
        /* Filters */
        .filter-form {
            padding: 20px 0;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--dark);
        }
        
        .filter-group select,
        .filter-group input {
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .search-box {
            display: flex;
            gap: 5px;
        }
        
        .search-box input {
            flex: 1;
        }
        
        .search-box button {
            background: var(--primary);
            color: white;
            border: none;
            width: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        /* Dashboard Cards */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .badge {
            background: var(--primary);
            color: white;
            font-size: 12px;
            padding: 3px 10px;
            border-radius: 12px;
            font-weight: 500;
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: #f8fafc;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        
        .data-table tr:hover {
            background: #f8fafc;
        }
        
        .user-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-avatar-small {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        
        .user-info-small {
            display: flex;
            flex-direction: column;
        }
        
        .user-info-small strong {
            font-weight: 600;
            font-size: 14px;
        }
        
        .user-info-small span,
        .user-info-small small {
            font-size: 12px;
            color: var(--gray);
        }
        
        /* Status Badges */
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .approved-by {
            color: var(--success);
            font-size: 14px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 20px;
            color: #e2e8f0;
        }
        
        .empty-state h3 {
            font-size: 18px;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 20px;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray);
            transition: color 0.3s ease;
        }
        
        .close-modal:hover {
            color: var(--dark);
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            resize: vertical;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        /* Chart Container */
        .chart-container {
            height: 250px;
            position: relative;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .main-content {
                padding: 15px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
    
    <script>
        // Initialize Leave Type Chart
        const leaveTypeCtx = document.getElementById('leaveTypeChart');
        if (leaveTypeCtx) {
            const leaveTypeChart = new Chart(leaveTypeCtx, {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode(array_column($leave_type_stats, 'name')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($leave_type_stats, 'count')); ?>,
                        backgroundColor: [
                            '#4f46e5',
                            '#10b981',
                            '#f59e0b',
                            '#ef4444',
                            '#8b5cf6',
                            '#ec4899'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
        
        // Modal Functions
        function viewLeaveDetails(leaveId) {
            // Show loading
            document.getElementById('leaveDetails').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: var(--primary);"></i>
                    <p style="margin-top: 10px;">Loading details...</p>
                </div>
            `;
            
            // Fetch leave details via AJAX
            fetch(`?ajax=get_leave_details&id=${leaveId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('leaveDetails').innerHTML = data;
                    document.getElementById('leaveModal').classList.add('active');
                })
                .catch(error => {
                    document.getElementById('leaveDetails').innerHTML = `
                        <div style="color: var(--danger); text-align: center; padding: 20px;">
                            <i class="fas fa-exclamation-circle fa-2x"></i>
                            <p style="margin-top: 10px;">Error loading leave details</p>
                        </div>
                    `;
                });
        }
        
        function rejectLeave(leaveId) {
            document.getElementById('rejectLeaveId').value = leaveId;
            document.getElementById('rejectModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('leaveModal').classList.remove('active');
        }
        
        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('active');
            document.getElementById('rejection_reason').value = '';
        }
        
        function validateRejectForm() {
            const reason = document.getElementById('rejection_reason').value.trim();
            if (!reason) {
                alert('Please provide a reason for rejection.');
                return false;
            }
            if (!confirm('Are you sure you want to reject this leave request?')) {
                return false;
            }
            return true;
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            const modal = document.getElementById('leaveModal');
            const rejectModal = document.getElementById('rejectModal');
            
            if (event.target === modal) {
                closeModal();
            }
            if (event.target === rejectModal) {
                closeRejectModal();
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeModal();
                closeRejectModal();
            }
        });
        
        // Auto-refresh pending count
        function updatePendingCount() {
            fetch('?ajax=get_pending_count')
                .then(response => response.json())
                .then(data => {
                    const badge = document.getElementById('pendingBadge');
                    if (badge && data.pending !== undefined) {
                        badge.textContent = data.pending;
                    }
                })
                .catch(error => console.error('Error updating count:', error));
        }
        
        // Update every 5 minutes
        setInterval(updatePendingCount, 300000);
        
        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            const tooltips = document.querySelectorAll('[title]');
            tooltips.forEach(el => {
                el.addEventListener('mouseenter', function(e) {
                    const tooltip = document.createElement('div');
                    tooltip.className = 'tooltip';
                    tooltip.textContent = this.title;
                    tooltip.style.position = 'absolute';
                    tooltip.style.background = 'var(--dark)';
                    tooltip.style.color = 'white';
                    tooltip.style.padding = '5px 10px';
                    tooltip.style.borderRadius = '4px';
                    tooltip.style.fontSize = '12px';
                    tooltip.style.zIndex = '10000';
                    tooltip.style.top = (e.clientY + 10) + 'px';
                    tooltip.style.left = (e.clientX + 10) + 'px';
                    document.body.appendChild(tooltip);
                    this._tooltip = tooltip;
                });
                
                el.addEventListener('mouseleave', function() {
                    if (this._tooltip) {
                        this._tooltip.remove();
                    }
                });
            });
        });
    </script>
</body>
</html>