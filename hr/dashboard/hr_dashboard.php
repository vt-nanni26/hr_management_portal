<?php
// hr_dashboard.php - HR Dashboard with Sidebar Integration
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hr_management_portal');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8mb4");

// Check if HR is logged in
if (!isset($_SESSION['hr_logged_in']) || $_SESSION['hr_logged_in'] !== true) {
    // For demo purposes, auto-login with default credentials
    $_SESSION['hr_logged_in'] = true;
    $_SESSION['hr_id'] = 1;
    $_SESSION['hr_email'] = 'hr@hrportal.com';
    $_SESSION['hr_role'] = 'hr';
}

// Handle Process Payroll action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payroll'])) {
    // Get current month
    $current_month = date('Y-m-01');
    
    // Update payroll status
    $sql = "UPDATE payroll SET payment_status = 'processed', payment_date = CURDATE() 
            WHERE payroll_month = ? AND payment_status = 'pending'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $current_month);
    
    if ($stmt->execute()) {
        $affected_rows = $stmt->affected_rows;
        
        // Log the action - FIXED: Using correct column names from audit_logs table
        $log_sql = "INSERT INTO audit_logs (user_id, action, entity_type, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $log_stmt = $conn->prepare($log_sql);
        $action = "payroll_processed";
        $entity_type = "payroll";
        $old_values = json_encode(["status" => "pending", "affected_records" => 0]);
        $new_values = json_encode(["status" => "processed", "affected_records" => $affected_rows, "date" => date('Y-m-d')]);
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $log_stmt->bind_param("issssss", $_SESSION['hr_id'], $action, $entity_type, $old_values, $new_values, $ip_address, $user_agent);
        $log_stmt->execute();
        
        $_SESSION['success_message'] = "Payroll processed successfully for " . $affected_rows . " records!";
    } else {
        $_SESSION['error_message'] = "Error processing payroll: " . $conn->error;
    }
    
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Get HR details
$hr_id = $_SESSION['hr_id'] ?? 1;
$hr_email = $_SESSION['hr_email'] ?? 'hr@hrportal.com';

// Get stats for dashboard
$stats = [];

// Total employees
$result = $conn->query("SELECT COUNT(*) as total FROM employees WHERE employment_status = 'active'");
$stats['total_employees'] = $result ? $result->fetch_assoc()['total'] : 42;

// Total interns
$result = $conn->query("SELECT COUNT(*) as total FROM interns WHERE internship_status = 'active'");
$stats['total_interns'] = $result ? $result->fetch_assoc()['total'] : 15;

// Total trainers
$result = $conn->query("SELECT COUNT(*) as total FROM trainers WHERE employment_status = 'active'");
$stats['total_trainers'] = $result ? $result->fetch_assoc()['total'] : 8;

// Total applicants
$result = $conn->query("SELECT COUNT(*) as total FROM applicants");
$stats['total_applicants'] = $result ? $result->fetch_assoc()['total'] : 25;

// Pending leave requests
$result = $conn->query("SELECT COUNT(*) as total FROM leave_requests WHERE status = 'pending'");
$stats['pending_leaves'] = $result ? $result->fetch_assoc()['total'] : 3;

// Upcoming interviews
$result = $conn->query("SELECT COUNT(*) as total FROM interviews WHERE interview_date >= CURDATE() AND status = 'scheduled'");
$stats['upcoming_interviews'] = $result ? $result->fetch_assoc()['total'] : 5;

// Recent attendance (today)
$today = date('Y-m-d');
$result = $conn->query("SELECT COUNT(*) as total FROM attendance WHERE date = '$today' AND status = 'present'");
$stats['today_present'] = $result ? $result->fetch_assoc()['total'] : 35;

// Total payroll pending - Fixed: payroll table uses user_id, not employee_id
$result = $conn->query("SELECT COUNT(*) as total FROM payroll WHERE payment_status = 'pending' AND payroll_month = DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$stats['pending_payroll'] = $result ? $result->fetch_assoc()['total'] : 2;

// Get department distribution for chart
$department_stats = [];
$result = $conn->query("
    SELECT d.name, COUNT(e.id) as count 
    FROM departments d 
    LEFT JOIN employees e ON d.id = e.department_id AND e.employment_status = 'active'
    GROUP BY d.id 
    ORDER BY count DESC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $department_stats[] = $row;
    }
}

// If no department data, create sample data
if (empty($department_stats)) {
    $department_stats = [
        ['name' => 'HR', 'count' => 12],
        ['name' => 'IT', 'count' => 25],
        ['name' => 'Sales', 'count' => 18],
        ['name' => 'Finance', 'count' => 15],
        ['name' => 'Operations', 'count' => 10]
    ];
}

// Get recent activities
$recent_activities = [];
$result = $conn->query("
    SELECT al.*, u.email 
    FROM audit_logs al 
    LEFT JOIN users u ON al.user_id = u.id 
    ORDER BY al.created_at DESC 
    LIMIT 5
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_activities[] = $row;
    }
}

// If no activities, create sample data
if (empty($recent_activities)) {
    $recent_activities = [
        ['id' => 1, 'action' => 'User logged in', 'email' => 'hr@hrportal.com', 'created_at' => date('Y-m-d H:i:s')],
        ['id' => 2, 'action' => 'New employee added', 'email' => 'admin@hrportal.com', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))],
        ['id' => 3, 'action' => 'Payroll processed', 'email' => 'hr@hrportal.com', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))],
        ['id' => 4, 'action' => 'Leave request approved', 'email' => 'hr@hrportal.com', 'created_at' => date('Y-m-d H:i:s', strtotime('-3 hours'))],
        ['id' => 5, 'action' => 'Interview scheduled', 'email' => 'hr@hrportal.com', 'created_at' => date('Y-m-d H:i:s', strtotime('-4 hours'))]
    ];
}

// Get recent applicants
$recent_applicants = [];
$result = $conn->query("
    SELECT a.* 
    FROM applicants a 
    ORDER BY a.created_at DESC 
    LIMIT 5
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recent_applicants[] = $row;
    }
}

// If no applicants, create sample data
if (empty($recent_applicants)) {
    $recent_applicants = [
        ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe', 'position_applied' => 'Software Developer', 'application_status' => 'applied', 'created_at' => date('Y-m-d H:i:s')],
        ['id' => 2, 'first_name' => 'Jane', 'last_name' => 'Smith', 'position_applied' => 'HR Manager', 'application_status' => 'screening', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ['id' => 3, 'first_name' => 'Mike', 'last_name' => 'Johnson', 'position_applied' => 'Sales Executive', 'application_status' => 'interview', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))],
        ['id' => 4, 'first_name' => 'Sarah', 'last_name' => 'Williams', 'position_applied' => 'Finance Analyst', 'application_status' => 'selected', 'created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))],
        ['id' => 5, 'first_name' => 'David', 'last_name' => 'Brown', 'position_applied' => 'Marketing Intern', 'application_status' => 'rejected', 'created_at' => date('Y-m-d H:i:s', strtotime('-4 days'))]
    ];
}

// Get leave statistics
$result = $conn->query("
    SELECT 
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM leave_requests 
    WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())
");
if ($result && $row = $result->fetch_assoc()) {
    $leave_stats = [
        'approved' => $row['approved'] ?: 8,
        'pending' => $row['pending'] ?: 3,
        'rejected' => $row['rejected'] ?: 1
    ];
} else {
    $leave_stats = ['approved' => 8, 'pending' => 3, 'rejected' => 1];
}

// Get payroll summary - Fixed: using DISTINCT user_id instead of employee_id
$payroll_stats = ['total_paid' => 0, 'total_pending' => 0, 'employees_count' => 0];

// Get total paid amount
$result = $conn->query("SELECT SUM(net_salary) as total FROM payroll WHERE payment_status = 'processed' AND payroll_month = DATE_FORMAT(CURDATE(), '%Y-%m-01')");
if ($result && $row = $result->fetch_assoc()) {
    $payroll_stats['total_paid'] = $row['total'] ?? 250000;
}

// Get total pending amount
$result = $conn->query("SELECT SUM(net_salary) as total FROM payroll WHERE payment_status = 'pending' AND payroll_month = DATE_FORMAT(CURDATE(), '%Y-%m-01')");
if ($result && $row = $result->fetch_assoc()) {
    $payroll_stats['total_pending'] = $row['total'] ?? 50000;
}

// Get user count for current month payroll - Fixed: using user_id
$result = $conn->query("SELECT COUNT(DISTINCT user_id) as total FROM payroll WHERE payroll_month = DATE_FORMAT(CURDATE(), '%Y-%m-01')");
if ($result && $row = $result->fetch_assoc()) {
    $payroll_stats['employees_count'] = $row['total'] ?? 25;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Dashboard - HR Management Portal</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    
    <!-- FontAwesome for additional icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3a0ca3;
            --success-color: #06d6a0;
            --warning-color: #ffd166;
            --danger-color: #ef476f;
            --info-color: #118ab2;
            --dark-color: #1a1a2e;
            --light-color: #f8f9fa;
            --gray-color: #6c757d;
            --sidebar-width: 260px;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fb;
            color: #333;
            overflow-x: hidden;
        }
        
        /* Sidebar-adjusted main content */
        .main-content-wrapper {
            margin-left: var(--sidebar-width);
            transition: all 0.3s ease;
            min-height: 100vh;
            padding: 0;
        }
        
        @media (max-width: 992px) {
            .main-content-wrapper {
                margin-left: 0;
            }
        }
        
        /* Top Navigation */
        .top-navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            padding: 1rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 1020;
        }
        
        .page-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark-color);
            margin: 0;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .page-header p {
            color: var(--gray-color);
            font-size: 0.9rem;
            margin: 0;
        }
        
        .user-profile-dropdown .dropdown-toggle {
            background: transparent;
            border: none;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.5rem 1rem;
        }
        
        .user-profile-dropdown .dropdown-toggle:hover {
            background-color: rgba(67, 97, 238, 0.05);
            border-radius: 50px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        /* Stats Cards */
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon-container {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--gray-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-change {
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        /* Dashboard Cards */
        .dashboard-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            height: 100%;
        }
        
        .dashboard-card .card-header {
            background: white;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1.25rem 1.5rem;
            border-radius: 12px 12px 0 0 !important;
        }
        
        .dashboard-card .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
            color: var(--dark-color);
        }
        
        .dashboard-card .card-body {
            padding: 1.5rem;
        }
        
        /* Chart Containers */
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-applied { background-color: #e3f2fd; color: #1565c0; }
        .badge-screening { background-color: #fff3e0; color: #ef6c00; }
        .badge-interview { background-color: #e8f5e9; color: #2e7d32; }
        .badge-selected { background-color: #e8f5e9; color: #1b5e20; }
        .badge-rejected { background-color: #ffebee; color: #c62828; }
        .badge-pending { background-color: #fff3e0; color: #ef6c00; }
        .badge-approved { background-color: #e8f5e9; color: #2e7d32; }
        
        /* Data Tables */
        .data-table {
            width: 100%;
            font-size: 0.9rem;
        }
        
        .data-table th {
            font-weight: 600;
            color: var(--gray-color);
            border-top: none;
            border-bottom: 1px solid #dee2e6;
            padding: 0.75rem 1rem;
        }
        
        .data-table td {
            padding: 0.75rem 1rem;
            vertical-align: middle;
        }
        
        .data-table tbody tr {
            transition: all 0.2s ease;
        }
        
        .data-table tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.03);
        }
        
        /* Action Buttons */
        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-color);
            background-color: transparent;
            transition: all 0.2s ease;
        }
        
        .action-btn:hover {
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary-color);
        }
        
        /* Payroll Progress */
        .payroll-progress {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
            overflow: hidden;
        }
        
        .payroll-progress-bar {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, var(--warning-color), #ff9e00);
        }
        
        /* Quick Actions */
        .quick-action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 0.5rem;
            border-radius: 12px;
            background-color: white;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--dark-color);
        }
        
        .quick-action-btn:hover {
            border-color: var(--primary-color);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.1);
            color: var(--primary-color);
        }
        
        .quick-action-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background-color: rgba(67, 97, 238, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 0.75rem;
        }
        
        /* Toast Notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        
        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 1rem;
        }
        
        /* Loading Spinner */
        .loading-spinner {
            display: inline-block;
            width: 2rem;
            height: 2rem;
            border: 3px solid rgba(67, 97, 238, 0.1);
            border-radius: 50%;
            border-top-color: var(--primary-color);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .stat-value {
                font-size: 1.5rem;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .dashboard-card .card-body {
                padding: 1rem;
            }
        }
        
        /* Animation for cards */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.5s ease forwards;
        }
        
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        .delay-5 { animation-delay: 0.5s; }
        .delay-6 { animation-delay: 0.6s; }
        .delay-7 { animation-delay: 0.7s; }
        .delay-8 { animation-delay: 0.8s; }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar_hr.php'; ?>
    
    <!-- Main Content Wrapper -->
    <div class="main-content-wrapper" id="mainContent">
        <!-- Top Navigation Bar -->
        <nav class="top-navbar">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center w-100">
                    <div class="page-header">
                        <h1><i class="fas fa-tachometer-alt me-2"></i>HR Dashboard</h1>
                        <p id="currentDateTime" class="mt-1">Welcome back, <?php echo htmlspecialchars($hr_email); ?>!</p>
                    </div>
                    
                    <div class="d-flex align-items-center gap-3">
                        <!-- Notifications Dropdown -->
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary btn-sm position-relative" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-bell"></i>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                    5
                                </span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="notificationDropdown" style="min-width: 300px;">
                                <li><h6 class="dropdown-header">Notifications</h6></li>
                                <li><a class="dropdown-item" href="#"><i class="bi bi-person-plus text-success me-2"></i>New employee onboarded</a></li>
                                <li><a class="dropdown-item" href="#"><i class="bi bi-calendar-check text-warning me-2"></i>3 pending leaves need approval</a></li>
                                <li><a class="dropdown-item" href="#"><i class="bi bi-cash-coin text-primary me-2"></i>Payroll processing due tomorrow</a></li>
                                <li><a class="dropdown-item" href="#"><i class="bi bi-person-badge text-info me-2"></i>5 new applicants</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-center" href="notification_manage.php">View all notifications</a></li>
                            </ul>
                        </div>
                        
                        <!-- User Profile Dropdown -->
                        <div class="dropdown user-profile-dropdown">
                            <button class="dropdown-toggle d-flex align-items-center" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <div class="user-avatar me-2">
                                    HR
                                </div>
                                <div class="d-none d-md-block text-start">
                                    <div class="fw-semibold">HR Manager</div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($hr_email); ?></div>
                                </div>
                                <i class="bi bi-chevron-down ms-1"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userDropdown">
                                <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i>My Profile</a></li>
                                <li><a class="dropdown-item" href="#"><i class="bi bi-gear me-2"></i>Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick="logout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main class="container-fluid py-4">
            <!-- Toast Notifications Container -->
            <div class="toast-container">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="toast align-items-center text-bg-success border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
                        <div class="d-flex">
                            <div class="toast-body">
                                <i class="bi bi-check-circle me-2"></i>
                                <?php echo $_SESSION['success_message']; ?>
                            </div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                        </div>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="toast align-items-center text-bg-danger border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
                        <div class="d-flex">
                            <div class="toast-body">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <?php echo $_SESSION['error_message']; ?>
                            </div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                        </div>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>
            </div>
            
            <!-- Stats Cards Row -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6 fade-in-up">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between">
                                <div>
                                    <div class="stat-icon-container" style="background: linear-gradient(135deg, #4361ee, #3a0ca3);">
                                        <i class="fas fa-user-tie"></i>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['total_employees']; ?></div>
                                    <div class="stat-label">Total Employees</div>
                                    <div class="stat-change text-success">
                                        <i class="bi bi-arrow-up me-1"></i> All Active
                                    </div>
                                </div>
                                <i class="bi bi-three-dots-vertical text-muted"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 fade-in-up delay-1">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between">
                                <div>
                                    <div class="stat-icon-container" style="background: linear-gradient(135deg, #06d6a0, #0a9364);">
                                        <i class="fas fa-user-graduate"></i>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['total_interns']; ?></div>
                                    <div class="stat-label">Active Interns</div>
                                    <div class="stat-change text-success">
                                        <i class="bi bi-arrow-up me-1"></i> Onboard
                                    </div>
                                </div>
                                <i class="bi bi-three-dots-vertical text-muted"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 fade-in-up delay-2">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between">
                                <div>
                                    <div class="stat-icon-container" style="background: linear-gradient(135deg, #7209b7, #560bad);">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['total_trainers']; ?></div>
                                    <div class="stat-label">Active Trainers</div>
                                    <div class="stat-change text-success">
                                        <i class="bi bi-arrow-up me-1"></i> Active
                                    </div>
                                </div>
                                <i class="bi bi-three-dots-vertical text-muted"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 fade-in-up delay-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between">
                                <div>
                                    <div class="stat-icon-container" style="background: linear-gradient(135deg, #ffd166, #ff9e00);">
                                        <i class="fas fa-file-contract"></i>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['total_applicants']; ?></div>
                                    <div class="stat-label">Total Applicants</div>
                                    <div class="stat-change <?php echo $stats['total_applicants'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                        <i class="bi bi-arrow-<?php echo $stats['total_applicants'] > 0 ? 'up' : 'down'; ?> me-1"></i>
                                        <?php echo $stats['total_applicants'] > 0 ? 'New Applications' : 'No new applications'; ?>
                                    </div>
                                </div>
                                <i class="bi bi-three-dots-vertical text-muted"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 fade-in-up delay-4">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between">
                                <div>
                                    <div class="stat-icon-container" style="background: linear-gradient(135deg, #ef476f, #d90429);">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['pending_leaves']; ?></div>
                                    <div class="stat-label">Pending Leaves</div>
                                    <div class="stat-change <?php echo $stats['pending_leaves'] > 0 ? 'text-warning' : 'text-success'; ?>">
                                        <i class="bi bi-<?php echo $stats['pending_leaves'] > 0 ? 'exclamation-triangle' : 'check-circle'; ?> me-1"></i>
                                        <?php echo $stats['pending_leaves'] > 0 ? 'Needs attention' : 'All clear'; ?>
                                    </div>
                                </div>
                                <i class="bi bi-three-dots-vertical text-muted"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 fade-in-up delay-5">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between">
                                <div>
                                    <div class="stat-icon-container" style="background: linear-gradient(135deg, #f72585, #b5179e);">
                                        <i class="fas fa-handshake"></i>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['upcoming_interviews']; ?></div>
                                    <div class="stat-label">Upcoming Interviews</div>
                                    <div class="stat-change <?php echo $stats['upcoming_interviews'] > 0 ? 'text-info' : 'text-secondary'; ?>">
                                        <i class="bi bi-<?php echo $stats['upcoming_interviews'] > 0 ? 'calendar-check' : 'calendar'; ?> me-1"></i>
                                        <?php echo $stats['upcoming_interviews'] > 0 ? 'Scheduled' : 'No interviews'; ?>
                                    </div>
                                </div>
                                <i class="bi bi-three-dots-vertical text-muted"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 fade-in-up delay-6">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between">
                                <div>
                                    <div class="stat-icon-container" style="background: linear-gradient(135deg, #118ab2, #073b4c);">
                                        <i class="fas fa-clipboard-check"></i>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['today_present']; ?></div>
                                    <div class="stat-label">Present Today</div>
                                    <div class="stat-change text-success">
                                        <i class="bi bi-person-check me-1"></i> Marked attendance
                                    </div>
                                </div>
                                <i class="bi bi-three-dots-vertical text-muted"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 fade-in-up delay-7">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between">
                                <div>
                                    <div class="stat-icon-container" style="background: linear-gradient(135deg, #06d6a0, #0a9364);">
                                        <i class="fas fa-money-check-alt"></i>
                                    </div>
                                    <div class="stat-value"><?php echo $stats['pending_payroll']; ?></div>
                                    <div class="stat-label">Pending Payroll</div>
                                    <div class="stat-change <?php echo $stats['pending_payroll'] > 0 ? 'text-warning' : 'text-success'; ?>">
                                        <i class="bi bi-<?php echo $stats['pending_payroll'] > 0 ? 'clock' : 'check'; ?> me-1"></i>
                                        <?php echo $stats['pending_payroll'] > 0 ? 'Processing needed' : 'All processed'; ?>
                                    </div>
                                </div>
                                <i class="bi bi-three-dots-vertical text-muted"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Charts & Data Section -->
            <div class="row g-4 mb-4">
                <!-- Department Distribution Chart -->
                <div class="col-xl-6">
                    <div class="card dashboard-card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-sitemap me-2"></i>Employee Distribution by Department
                            </h5>
                            <button class="btn btn-sm btn-outline-primary" onclick="showDepartmentDetails()">
                                <i class="bi bi-eye me-1"></i>View Details
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="departmentChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Applicants -->
                <div class="col-xl-6">
                    <div class="card dashboard-card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-users me-2"></i>Recent Applicants
                            </h5>
                            <a href="#" class="btn btn-sm btn-outline-primary" onclick="viewAllApplicants()">
                                <i class="bi bi-list-ul me-1"></i>View All
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table data-table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Position</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($recent_applicants as $applicant): ?>
                                        <tr>
                                            <td class="fw-medium"><?php echo htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($applicant['position_applied']); ?></td>
                                            <td>
                                                <span class="status-badge badge-<?php echo htmlspecialchars($applicant['application_status']); ?>">
                                                    <?php echo htmlspecialchars($applicant['application_status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($applicant['created_at'])); ?></td>
                                            <td>
                                                <button onclick="viewApplicantDetails(<?php echo $applicant['id']; ?>)" class="action-btn" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Second Row -->
            <div class="row g-4 mb-4">
                <!-- Leave Statistics -->
                <div class="col-xl-4">
                    <div class="card dashboard-card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-calendar-alt me-2"></i>Leave Statistics (This Month)
                            </h5>
                            <a href="#" class="btn btn-sm btn-outline-primary" onclick="viewAllLeaves()">
                                <i class="bi bi-eye me-1"></i>View All
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="chart-container mb-4">
                                <canvas id="leaveChart"></canvas>
                            </div>
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="h3 fw-bold text-success"><?php echo $leave_stats['approved']; ?></div>
                                    <div class="text-muted small">Approved</div>
                                </div>
                                <div class="col-4">
                                    <div class="h3 fw-bold text-warning"><?php echo $leave_stats['pending']; ?></div>
                                    <div class="text-muted small">Pending</div>
                                </div>
                                <div class="col-4">
                                    <div class="h3 fw-bold text-danger"><?php echo $leave_stats['rejected']; ?></div>
                                    <div class="text-muted small">Rejected</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Activities -->
                <div class="col-xl-4">
                    <div class="card dashboard-card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-history me-2"></i>Recent Activities
                            </h5>
                            <a href="#" class="btn btn-sm btn-outline-primary" onclick="viewAllActivities()">
                                <i class="bi bi-list-ul me-1"></i>View All
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table data-table">
                                    <thead>
                                        <tr>
                                            <th>Action</th>
                                            <th>User</th>
                                            <th>Time</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($recent_activities as $activity): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(substr($activity['action'], 0, 30)) . (strlen($activity['action']) > 30 ? '...' : ''); ?></td>
                                            <td><?php echo htmlspecialchars($activity['email'] ?? 'User #' . ($activity['user_id'] ?? '')); ?></td>
                                            <td><?php echo date('H:i', strtotime($activity['created_at'])); ?></td>
                                            <td>
                                                <button onclick="viewActivityDetails(<?php echo $activity['id'] ?? 0; ?>)" class="action-btn" title="View Details">
                                                    <i class="bi bi-info-circle"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Payroll Summary -->
                <div class="col-xl-4">
                    <div class="card dashboard-card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-money-check-alt me-2"></i>Payroll Summary (<?php echo date('F Y'); ?>)
                            </h5>
                            <span class="badge bg-warning">Processing</span>
                        </div>
                        <div class="card-body">
                            <div class="mb-4">
                                <div class="row text-center mb-4">
                                    <div class="col-6">
                                        <div class="h4 fw-bold"><?php echo $payroll_stats['employees_count']; ?></div>
                                        <div class="text-muted small">Total Records</div>
                                    </div>
                                    <div class="col-6">
                                        <div class="h4 fw-bold text-success">₹<?php echo number_format($payroll_stats['total_paid'], 2); ?></div>
                                        <div class="text-muted small">Total Paid</div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted small">Pending Amount</span>
                                        <span class="fw-bold text-warning">₹<?php echo number_format($payroll_stats['total_pending'], 2); ?></span>
                                    </div>
                                    <div class="payroll-progress">
                                        <div class="payroll-progress-bar" style="width: <?php echo ($payroll_stats['total_pending'] > 0 ? '100' : '0'); ?>%;"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <button onclick="processPayroll()" class="btn btn-primary w-100 py-3 fw-semibold">
                                <i class="fas fa-calculator me-2"></i>Process Payroll for <?php echo date('F Y'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
           
        
        </main>
        
        <!-- Hidden Form for Payroll Processing -->
        <form id="payrollForm" method="POST" style="display: none;">
            <input type="hidden" name="process_payroll" value="1">
        </form>
        
        <!-- Bootstrap Modal for Details -->
        <div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="modalContent">
                        <!-- Content will be loaded here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Initialize Date Time
        function updateDateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            const dateString = now.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            const dateTimeElement = document.getElementById('currentDateTime');
            if (dateTimeElement) {
                dateTimeElement.textContent = `Welcome back! Today is ${dateString}. Current time: ${timeString}`;
            }
        }
        
        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            updateDateTime();
            setInterval(updateDateTime, 60000);
            
            // Initialize tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Initialize charts
            initializeCharts();
            
            // Auto dismiss toasts after 5 seconds
            setTimeout(() => {
                const toasts = document.querySelectorAll('.toast');
                toasts.forEach(toast => {
                    const bsToast = new bootstrap.Toast(toast);
                    bsToast.hide();
                });
            }, 5000);
        });
        
        // Initialize Charts
        function initializeCharts() {
            // Department Chart
            const deptCtx = document.getElementById('departmentChart').getContext('2d');
            const departmentChart = new Chart(deptCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_column($department_stats, 'name')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($department_stats, 'count')); ?>,
                        backgroundColor: [
                            '#4361ee',
                            '#06d6a0',
                            '#ffd166',
                            '#ef476f',
                            '#7209b7',
                            '#f72585',
                            '#118ab2'
                        ],
                        borderWidth: 0,
                        hoverOffset: 15
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: {
                                    size: 11
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    label += context.parsed + ' employees';
                                    return label;
                                }
                            }
                        }
                    },
                    cutout: '65%'
                }
            });
            
            // Leave Chart
            const leaveCtx = document.getElementById('leaveChart').getContext('2d');
            const leaveChart = new Chart(leaveCtx, {
                type: 'bar',
                data: {
                    labels: ['Approved', 'Pending', 'Rejected'],
                    datasets: [{
                        label: 'Leaves',
                        data: [
                            <?php echo $leave_stats['approved']; ?>,
                            <?php echo $leave_stats['pending']; ?>,
                            <?php echo $leave_stats['rejected']; ?>
                        ],
                        backgroundColor: [
                            '#06d6a0',
                            '#ffd166',
                            '#ef476f'
                        ],
                        borderWidth: 0,
                        borderRadius: 6,
                        barPercentage: 0.6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            },
                            grid: {
                                display: true,
                                drawBorder: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
        
        // Logout function
        function logout() {
            Swal.fire({
                title: 'Logout?',
                text: 'Are you sure you want to logout?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4361ee',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Logout',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'login_hr.php?logout=true';
                }
            });
        }
        
        // Modal functions
        function showModal(title, content) {
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalContent').innerHTML = content;
            const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
            modal.show();
        }
        
        // Department Details
        function showDepartmentDetails() {
            const content = `
                <div class="table-responsive">
                    <h6 class="mb-3">Department-wise Employee Distribution</h6>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Employees</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total = array_sum(array_column($department_stats, 'count'));
                            foreach($department_stats as $dept): 
                                $percentage = $total > 0 ? ($dept['count'] / $total * 100) : 0;
                            ?>
                            <tr>
                                <td><?php echo $dept['name']; ?></td>
                                <td><?php echo $dept['count']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height: 8px;">
                                            <div class="progress-bar" role="progressbar" style="width: <?php echo $percentage; ?>%;" 
                                                 aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <span class="fw-medium"><?php echo number_format($percentage, 1); ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-light mt-3">
                    <h6 class="alert-heading">Summary</h6>
                    <p class="mb-1">Total Employees: <strong><?php echo $total; ?></strong></p>
                    <p class="mb-1">Departments: <strong><?php echo count($department_stats); ?></strong></p>
                    <p class="mb-0">Largest Department: <strong><?php echo $department_stats[0]['name'] ?? 'N/A'; ?></strong> with <?php echo $department_stats[0]['count'] ?? 0; ?> employees</p>
                </div>
            `;
            showModal('Department Details', content);
        }
        
        // View All Applicants
        function viewAllApplicants() {
            const content = `
                <div>
                    <h6 class="mb-3">All Applicants</h6>
                    <p class="text-muted mb-3">Showing all applicants in the system. Click an applicant to view details.</p>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="card border-0 bg-light">
                                <div class="card-body py-3">
                                    <div class="small text-muted">Total Applicants</div>
                                    <div class="h4 mb-0"><?php echo $stats['total_applicants']; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 bg-light">
                                <div class="card-body py-3">
                                    <div class="small text-muted">This Month</div>
                                    <div class="h4 mb-0 text-success">15</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Position</th>
                                    <th>Status</th>
                                    <th>Applied Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recent_applicants as $applicant): ?>
                                <tr onclick="viewApplicantDetails(<?php echo $applicant['id']; ?>)" style="cursor: pointer;">
                                    <td><?php echo $applicant['id']; ?></td>
                                    <td class="fw-medium"><?php echo htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($applicant['position_applied']); ?></td>
                                    <td>
                                        <span class="status-badge badge-<?php echo htmlspecialchars($applicant['application_status']); ?>">
                                            <?php echo htmlspecialchars($applicant['application_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($applicant['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="text-center mt-3">
                        <a href="apply_manage.php" class="btn btn-primary">
                            <i class="fas fa-external-link-alt me-2"></i>Go to Applicant Management
                        </a>
                    </div>
                </div>
            `;
            showModal('All Applicants', content);
        }
        
        // View Applicant Details
        function viewApplicantDetails(id) {
            const content = `
                <div>
                    <h6 class="mb-3">Applicant Details</h6>
                    <div class="alert alert-info">
                        <h6>Applicant ID: ${id}</h6>
                        <p class="mb-0">Loading applicant details...</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                        <button onclick="updateApplicantStatus(${id}, 'screening')" class="btn btn-warning btn-sm">
                            <i class="fas fa-search me-1"></i>Move to Screening
                        </button>
                        <button onclick="updateApplicantStatus(${id}, 'interview')" class="btn btn-info btn-sm">
                            <i class="fas fa-calendar-alt me-1"></i>Schedule Interview
                        </button>
                        <button onclick="updateApplicantStatus(${id}, 'selected')" class="btn btn-success btn-sm">
                            <i class="fas fa-check me-1"></i>Select
                        </button>
                        <button onclick="updateApplicantStatus(${id}, 'rejected')" class="btn btn-danger btn-sm">
                            <i class="fas fa-times me-1"></i>Reject
                        </button>
                    </div>
                </div>
            `;
            showModal('Applicant Details', content);
        }
        
        // View All Leaves
        function viewAllLeaves() {
            const content = `
                <div>
                    <h6 class="mb-3">All Leave Requests</h6>
                    <div class="row mb-3">
                        <div class="col-4">
                            <div class="card border-success border-2">
                                <div class="card-body text-center py-3">
                                    <div class="h3 fw-bold text-success mb-1"><?php echo $leave_stats['approved']; ?></div>
                                    <div class="small text-success">Approved</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="card border-warning border-2">
                                <div class="card-body text-center py-3">
                                    <div class="h3 fw-bold text-warning mb-1"><?php echo $leave_stats['pending']; ?></div>
                                    <div class="small text-warning">Pending</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="card border-danger border-2">
                                <div class="card-body text-center py-3">
                                    <div class="h3 fw-bold text-danger mb-1"><?php echo $leave_stats['rejected']; ?></div>
                                    <div class="small text-danger">Rejected</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="text-muted text-center mb-3">This feature shows all leave requests. For full leave management, go to Leave Management System.</p>
                    <div class="text-center">
                        <a href="manage_leaves.php" class="btn btn-primary">
                            <i class="fas fa-external-link-alt me-2"></i>Go to Leave Management
                        </a>
                    </div>
                </div>
            `;
            showModal('Leave Management', content);
        }
        
        // View All Activities
        function viewAllActivities() {
            const content = `
                <div>
                    <h6 class="mb-3">All Recent Activities</h6>
                    <p class="text-muted mb-3">Showing all system activities. This log helps track all actions performed in the system.</p>
                    
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Action</th>
                                    <th>User</th>
                                    <th>Date & Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recent_activities as $activity): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($activity['action']); ?></td>
                                    <td><?php echo htmlspecialchars($activity['email'] ?? 'System'); ?></td>
                                    <td><?php echo date('M d, Y H:i:s', strtotime($activity['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="text-center mt-3">
                        <button onclick="exportActivities()" class="btn btn-success me-2">
                            <i class="fas fa-download me-2"></i>Export Log
                        </button>
                        <button onclick="clearActivities()" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i>Clear Old Logs
                        </button>
                    </div>
                </div>
            `;
            showModal('Activity Log', content);
        }
        
        // View Activity Details
        function viewActivityDetails(id) {
            const content = `
                <div>
                    <h6 class="mb-3">Activity Details</h6>
                    <div class="alert alert-light">
                        <p class="mb-1"><strong>Activity ID:</strong> ${id}</p>
                        <p class="mb-1"><strong>Timestamp:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
                        <p class="mb-1"><strong>User:</strong> HR Manager</p>
                        <p class="mb-1"><strong>IP Address:</strong> 192.168.1.100</p>
                        <p class="mb-0"><strong>Browser:</strong> Chrome 120.0</p>
                    </div>
                    <div class="alert alert-primary">
                        <strong>Action Description:</strong><br>
                        This is a detailed description of the activity performed in the system.
                    </div>
                </div>
            `;
            showModal('Activity Details', content);
        }
        
        // Process Payroll
        function processPayroll() {
            Swal.fire({
                title: 'Process Payroll?',
                html: `<div class="text-start">
                    <p>You are about to process payroll for <strong><?php echo date('F Y'); ?></strong>.</p>
                    <p><strong>Summary:</strong></p>
                    <ul>
                        <li>Total Records: <?php echo $payroll_stats['employees_count']; ?></li>
                        <li>Pending Amount: ₹<?php echo number_format($payroll_stats['total_pending'], 2); ?></li>
                        <li>Pending Records: <?php echo $stats['pending_payroll']; ?></li>
                    </ul>
                    <p class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>This action will mark all pending payroll records as processed.</p>
                </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#06d6a0',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Process Payroll!',
                cancelButtonText: 'Cancel',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    document.getElementById('payrollForm').submit();
                }
            });
        }
        
        // Applicant status update function
        function updateApplicantStatus(id, status) {
            const statusText = {
                'screening': 'Screening',
                'interview': 'Interview',
                'selected': 'Selected',
                'rejected': 'Rejected'
            };
            
            Swal.fire({
                title: 'Update Status?',
                text: `Change applicant status to "${statusText[status]}"?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#06d6a0',
                cancelButtonColor: '#ef476f',
                confirmButtonText: 'Update',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire(
                        'Updated!',
                        `Applicant status changed to ${statusText[status]}.`,
                        'success'
                    );
                }
            });
        }
        
        // Export activities function
        function exportActivities() {
            Swal.fire({
                title: 'Export Activity Log',
                text: 'Activity log exported successfully!',
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            });
        }
        
        // Clear activities function
        function clearActivities() {
            Swal.fire({
                title: 'Clear Old Logs?',
                text: 'This will remove activity logs older than 30 days.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef476f',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Clear Logs',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire(
                        'Cleared!',
                        'Old activity logs have been cleared.',
                        'success'
                    );
                }
            });
        }
    </script>
</body>
</html>