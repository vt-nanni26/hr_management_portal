<?php
session_start();

// Set the timezone to your local timezone (e.g., 'Asia/Kolkata')
date_default_timezone_set('Asia/Kolkata'); // Change this to your timezone

require_once "../../db_connection.php";

// Check if user is logged in and is employee
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'employee') {
    header("Location: login_emp.php");
    exit();
}

// Get user_id from session
$user_id = $_SESSION['user_id'];

// Fetch employee details by user_id
$stmt = $conn->prepare("
    SELECT e.*, d.name as department_name, s.name as shift_name, s.start_time, s.end_time 
    FROM employees e 
    LEFT JOIN departments d ON e.department_id = d.id 
    LEFT JOIN shifts s ON e.shift_id = s.id 
    WHERE e.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();

// If no employee found, try to fetch by email from session
if (!$employee && isset($_SESSION['email'])) {
    $email = $_SESSION['email'];
    $stmt = $conn->prepare("
        SELECT e.*, d.name as department_name, s.name as shift_name, s.start_time, s.end_time 
        FROM employees e 
        LEFT JOIN departments d ON e.department_id = d.id 
        LEFT JOIN shifts s ON e.shift_id = s.id 
        WHERE e.email = ?
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee = $result->fetch_assoc();
}

// Initialize employee data with defaults if null
if (!$employee) {
    $employee = [
        'first_name' => 'Employee',
        'last_name' => '',
        'designation' => 'Not assigned',
        'emp_id' => 'N/A',
        'department_name' => 'Not assigned',
        'shift_id' => null,
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'joining_date' => date('Y-m-d'),
        'profile_picture' => null
    ];
} else {
    // Set default values if null in actual data
    $employee['first_name'] = $employee['first_name'] ?? 'Employee';
    $employee['last_name'] = $employee['last_name'] ?? '';
    $employee['designation'] = $employee['designation'] ?? 'Not assigned';
    $employee['emp_id'] = $employee['emp_id'] ?? 'N/A';
    $employee['department_name'] = $employee['department_name'] ?? 'Not assigned';
    $employee['shift_id'] = $employee['shift_id'] ?? null;
    $employee['start_time'] = $employee['start_time'] ?? '09:00:00';
    $employee['end_time'] = $employee['end_time'] ?? '18:00:00';
    $employee['joining_date'] = $employee['joining_date'] ?? date('Y-m-d');
    $employee['profile_picture'] = $employee['profile_picture'] ?? null;
}

// Fetch today's attendance
$today = date('Y-m-d');
$attendance_stmt = $conn->prepare("
    SELECT * FROM attendance 
    WHERE user_id = ? AND user_type = 'employee' AND date = ?
");
$attendance_stmt->bind_param("is", $user_id, $today);
$attendance_stmt->execute();
$attendance_result = $attendance_stmt->get_result();
$attendance = $attendance_result->fetch_assoc();

// Fetch leave requests
$leave_stmt = $conn->prepare("
    SELECT lr.*, lt.name as leave_type 
    FROM leave_requests lr 
    JOIN leave_types lt ON lr.leave_type_id = lt.id 
    WHERE lr.user_id = ? AND lr.user_type = 'employee' 
    ORDER BY lr.created_at DESC 
    LIMIT 5
");
$leave_stmt->bind_param("i", $user_id);
$leave_stmt->execute();
$leave_result = $leave_stmt->get_result();
$leave_requests = [];
while ($row = $leave_result->fetch_assoc()) {
    $leave_requests[] = $row;
}

// Fetch recent notifications
$notif_stmt = $conn->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$notif_result = $notif_stmt->get_result();
$notifications = [];
while ($row = $notif_result->fetch_assoc()) {
    $notifications[] = $row;
}

// Fetch upcoming holidays
$holiday_stmt = $conn->prepare("
    SELECT * FROM holidays 
    WHERE holiday_date >= CURDATE() 
    ORDER BY holiday_date ASC 
    LIMIT 5
");
$holiday_stmt->execute();
$holiday_result = $holiday_stmt->get_result();
$holidays = [];
while ($row = $holiday_result->fetch_assoc()) {
    $holidays[] = $row;
}

// Check if check-in is allowed
$canCheckIn = false;
$canCheckOut = false;
$current_time = date('H:i:s');

if ($employee['shift_id']) {
    $shift_start = strtotime($employee['start_time'] ?? '09:00:00');
    $shift_end = strtotime($employee['end_time'] ?? '18:00:00');
    $current_timestamp = strtotime($current_time);
    
    // Allow check-in 1 hour before shift and until 2 hours after shift starts
    $canCheckIn = ($current_timestamp >= ($shift_start - 3600)) && 
                  ($current_timestamp <= ($shift_start + 7200)) && 
                  (!$attendance || !$attendance['check_in']);
    
    // Allow check-out if checked in and not checked out
    $canCheckOut = $attendance && $attendance['check_in'] && !$attendance['check_out'] && 
                   ($current_timestamp >= ($shift_end - 1800));
}

// Calculate additional stats
$user_id = $_SESSION['user_id'];

// Get total present days
$total_present_stmt = $conn->prepare("
    SELECT COUNT(*) as total_present 
    FROM attendance 
    WHERE user_id = ? 
    AND user_type = 'employee' 
    AND status = 'present'
");
$total_present_stmt->bind_param("i", $user_id);
$total_present_stmt->execute();
$total_present_result = $total_present_stmt->get_result();
$total_present_row = $total_present_result->fetch_assoc();
$total_present = $total_present_row['total_present'] ?? 0;

// Get total late minutes for current month
$total_late_stmt = $conn->prepare("
    SELECT COALESCE(SUM(late_minutes), 0) as total_late 
    FROM attendance 
    WHERE user_id = ? 
    AND user_type = 'employee' 
    AND MONTH(date) = MONTH(CURDATE()) 
    AND YEAR(date) = YEAR(CURDATE())
");
$total_late_stmt->bind_param("i", $user_id);
$total_late_stmt->execute();
$total_late_result = $total_late_stmt->get_result();
$total_late_row = $total_late_result->fetch_assoc();
$total_late = $total_late_row['total_late'] ?? 0;

// Get attendance for current month
$current_month_attendance_stmt = $conn->prepare("
    SELECT COUNT(*) as total_days 
    FROM attendance 
    WHERE user_id = ? 
    AND user_type = 'employee' 
    AND MONTH(date) = MONTH(CURDATE()) 
    AND YEAR(date) = YEAR(CURDATE())
    AND status = 'present'
");
$current_month_attendance_stmt->bind_param("i", $user_id);
$current_month_attendance_stmt->execute();
$current_month_attendance_result = $current_month_attendance_stmt->get_result();
$current_month_attendance_row = $current_month_attendance_result->fetch_assoc();
$current_month_present = $current_month_attendance_row['total_days'] ?? 0;

// Calculate attendance rate
$attendance_rate = ($total_present > 0) ? round(($total_present / 90) * 100, 1) : 0; // Assuming 90 days for quarter

// Calculate leave balance
$leave_balance_stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN lt.is_paid = 1 THEN lr.total_days ELSE 0 END) as paid_leaves_taken,
        SUM(CASE WHEN lt.is_paid = 0 THEN lr.total_days ELSE 0 END) as unpaid_leaves_taken
    FROM leave_requests lr 
    JOIN leave_types lt ON lr.leave_type_id = lt.id 
    WHERE lr.user_id = ? AND lr.user_type = 'employee' 
    AND lr.status = 'approved'
    AND YEAR(lr.start_date) = YEAR(CURDATE())
");
$leave_balance_stmt->bind_param("i", $_SESSION['user_id']);
$leave_balance_stmt->execute();
$leave_balance_result = $leave_balance_stmt->get_result();
$leave_balance = $leave_balance_result->fetch_assoc();

$paid_leaves_taken = $leave_balance['paid_leaves_taken'] ?? 0;
$unpaid_leaves_taken = $leave_balance['unpaid_leaves_taken'] ?? 0;
$total_paid_leaves_allowed = 12; // Assuming 12 paid leaves per year
$paid_leaves_remaining = $total_paid_leaves_allowed - $paid_leaves_taken;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #1f2937;
            --light: #f9fafb;
            --gray: #6b7280;
            --sidebar-width: 260px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--primary) 0%, var(--secondary) 100%);
            padding: 25px 0;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .logo {
            padding: 0 25px 30px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 30px;
        }

        .logo h2 {
            color: white;
            font-size: 24px;
            font-weight: 700;
        }

        .logo span {
            color: #ffd166;
        }

        .nav-links {
            list-style: none;
            padding: 0 20px;
        }

        .nav-links li {
            margin-bottom: 10px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transform: translateX(10px);
        }

        .nav-links a.active {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .nav-links a i {
            font-size: 18px;
            margin-right: 15px;
            width: 24px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 25px;
            transition: margin-left 0.3s ease;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            animation: slideDown 0.5s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .greeting h1 {
            font-size: 28px;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .greeting p {
            color: var(--gray);
            font-size: 14px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-profile {
            position: relative;
            cursor: pointer;
        }

        .profile-img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary);
            transition: transform 0.3s ease;
        }

        .profile-img:hover {
            transform: scale(1.1);
        }

        .online-status {
            position: absolute;
            bottom: 5px;
            right: 5px;
            width: 12px;
            height: 12px;
            background: var(--success);
            border: 2px solid white;
            border-radius: 50%;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12);
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .card:hover::before {
            transform: scaleX(1);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }

        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }

        /* Attendance Card */
        .attendance-card {
            grid-column: span 2;
        }

        .attendance-status {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .time-display {
            font-size: 36px;
            font-weight: 700;
            color: var(--dark);
            font-family: monospace;
        }

        .status-badge {
            padding: 8px 20px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
        }

        .status-present {
            background: linear-gradient(135deg, #d1fae5, #10b981);
            color: white;
        }

        .status-absent {
            background: linear-gradient(135deg, #fee2e2, #ef4444);
            color: white;
        }

        .attendance-actions {
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(245, 158, 11, 0.3);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        /* Stats Cards */
        .stat-card {
            text-align: center;
        }

        .stat-number {
            font-size: 36px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .stat-label {
            color: var(--gray);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Lists */
        .list {
            list-style: none;
        }

        .list-item {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: background 0.3s ease;
        }

        .list-item:hover {
            background: #f9fafb;
            border-radius: 10px;
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .list-content h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .list-content p {
            font-size: 12px;
            color: var(--gray);
        }

        /* Charts */
        .chart-container {
            height: 300px;
            position: relative;
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
            z-index: 2000;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal.active {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .modal.active .modal-content {
            transform: scale(1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            color: var(--gray);
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close-modal:hover {
            color: var(--danger);
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideInLeft {
            from { transform: translateX(-30px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideInRight {
            from { transform: translateX(30px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideInUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .animate-fade-in { animation: fadeIn 0.5s ease-out; }
        .animate-slide-left { animation: slideInLeft 0.5s ease-out; }
        .animate-slide-right { animation: slideInRight 0.5s ease-out; }
        .animate-slide-up { animation: slideInUp 0.5s ease-out; }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .attendance-card {
                grid-column: span 1;
            }
            
            .menu-toggle {
                display: block !important;
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .attendance-actions {
                flex-direction: column;
            }
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            color: var(--dark);
            cursor: pointer;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger);
            color: white;
            font-size: 12px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Stats Container */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stats-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,.06);
            transition: transform 0.3s ease;
        }

        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,.1);
        }

        .stats-card h3 {
            margin: 0;
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stats-card p {
            font-size: 28px;
            margin: 10px 0 0;
            color: #2563eb;
            font-weight: bold;
        }

        .stats-card .stats-icon {
            font-size: 24px;
            color: #667eea;
            margin-bottom: 15px;
        }

        .stats-section-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo">
            <h2>HR<span>Portal</span></h2>
            <p style="color: rgba(255,255,255,0.7); font-size: 12px; margin-top: 5px;">Employee Dashboard</p>
        </div>
        
        <ul class="nav-links">
            <li><a href="employee_dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="profile_emp.php"><i class="fas fa-user-circle"></i> Profile</a></li>
            <li><a href="attendance_emp.php"><i class="fas fa-calendar-alt"></i> Attendance</a></li>
            <li><a href="payroll_emp.php"><i class="fas fa-file-invoice-dollar"></i> Payroll</a></li>
            <li><a href="leave_emp.php"><i class="fas fa-plane"></i> Leave</a></li>
            <li><a href="documents_emp.php"><i class="fas fa-file-alt"></i> Documents</a></li>
            <li><a href="../../logout_emp.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="greeting">
                <h1>Welcome back, <?php echo htmlspecialchars($employee['first_name']); ?>! 👋</h1>
                <p><?php echo date('l, F j, Y'); ?> • <?php echo date('h:i A'); ?></p>
            </div>
            
            <div class="user-info">
                <div class="user-profile">
                    <img src="<?php echo !empty($employee['profile_picture']) ? htmlspecialchars($employee['profile_picture']) : 'https://ui-avatars.com/api/?name=' . urlencode($employee['first_name'] . ' ' . $employee['last_name']) . '&background=667eea&color=fff'; ?>" 
                         alt="Profile" class="profile-img">
                    <div class="online-status"></div>
                </div>
                <div>
                    <h4><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h4>
                    <p style="color: var(--gray); font-size: 14px;"><?php echo htmlspecialchars($employee['designation']); ?></p>
                </div>
            </div>
        </div>

        <!-- Stats Section -->
        <div class="stats-section-title">Attendance Statistics</div>
        <div class="stats-container">
            <div class="stats-card animate-slide-left">
                <div class="stats-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h3>Total Present Days</h3>
                <p><?php echo $total_present; ?></p>
            </div>
            
            <div class="stats-card animate-slide-up">
                <div class="stats-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3>Late Minutes (This Month)</h3>
                <p><?php echo $total_late; ?></p>
            </div>
            
            <div class="stats-card animate-slide-right">
                <div class="stats-icon">
                    <i class="fas fa-percentage"></i>
                </div>
                <h3>Attendance Rate</h3>
                <p><?php echo $attendance_rate; ?>%</p>
            </div>

            <div class="stats-card animate-slide-left">
                <div class="stats-icon">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <h3>This Month Present</h3>
                <p><?php echo $current_month_present; ?></p>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Attendance Card -->
            <div class="card attendance-card animate-slide-up">
                <div class="card-header">
                    <h3 class="card-title">Today's Attendance</h3>
                    <div class="card-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                
                <div class="attendance-status">
                    <div class="time-display" id="liveClock"><?php echo date('h:i:s A'); ?></div>
                    <?php if ($attendance): ?>
                        <div class="status-badge status-present">
                            <i class="fas fa-check-circle"></i> Present
                        </div>
                    <?php else: ?>
                        <div class="status-badge status-absent">
                            <i class="fas fa-times-circle"></i> Not Checked In
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="attendance-actions">
                    <?php if ($canCheckIn || (!$attendance && !$employee['shift_id'])): ?>
                        <form method="POST" action="attendance_emp.php" style="display: inline;">
                            <button type="submit" name="check_in" class="btn btn-success">
                                <i class="fas fa-sign-in-alt"></i> Check In
                            </button>
                        </form>
                    <?php else: ?>
                        <button class="btn btn-success" disabled>
                            <i class="fas fa-sign-in-alt"></i> Check In
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($canCheckOut || ($attendance && $attendance['check_in'] && !$attendance['check_out'])): ?>
                        <form method="POST" action="attendance_emp.php" style="display: inline;">
                            <button type="submit" name="check_out" class="btn btn-warning">
                                <i class="fas fa-sign-out-alt"></i> Check Out
                            </button>
                        </form>
                    <?php else: ?>
                        <button class="btn btn-warning" disabled>
                            <i class="fas fa-sign-out-alt"></i> Check Out
                        </button>
                    <?php endif; ?>
                    
                    <a href="leave_emp.php" class="btn btn-primary">
                        <i class="fas fa-plane"></i> Apply Leave
                    </a>
                </div>
                
                <?php if ($attendance): ?>
                    <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <span>Check In:</span>
                            <strong><?php echo date('h:i A', strtotime($attendance['check_in'])); ?></strong>
                        </div>
                        <?php if ($attendance['check_out']): ?>
                            <div style="display: flex; justify-content: space-between;">
                                <span>Check Out:</span>
                                <strong><?php echo date('h:i A', strtotime($attendance['check_out'])); ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Employee Info Card -->
            <div class="card animate-slide-left">
                <div class="card-header">
                    <h3 class="card-title">Employee Information</h3>
                    <div class="card-icon">
                        <i class="fas fa-id-card"></i>
                    </div>
                </div>
                
                <ul class="list">
                    <li class="list-item">
                        <div class="list-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                            <i class="fas fa-id-badge"></i>
                        </div>
                        <div class="list-content">
                            <h4>Employee ID</h4>
                            <p><?php echo htmlspecialchars($employee['emp_id']); ?></p>
                        </div>
                    </li>
                    <li class="list-item">
                        <div class="list-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="list-content">
                            <h4>Department</h4>
                            <p><?php echo htmlspecialchars($employee['department_name']); ?></p>
                        </div>
                    </li>
                    <li class="list-item">
                        <div class="list-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="list-content">
                            <h4>Shift Timing</h4>
                            <p><?php echo date('h:i A', strtotime($employee['start_time'])); ?> - <?php echo date('h:i A', strtotime($employee['end_time'])); ?></p>
                        </div>
                    </li>
                    <li class="list-item">
                        <div class="list-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div class="list-content">
                            <h4>Joining Date</h4>
                            <p><?php echo date('M d, Y', strtotime($employee['joining_date'])); ?></p>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Leave Balance Card -->
            <div class="card stat-card animate-slide-right">
                <div class="card-header">
                    <h3 class="card-title">Leave Balance</h3>
                    <div class="card-icon">
                        <i class="fas fa-plane"></i>
                    </div>
                </div>
                
                <div class="stat-number"><?php echo max(0, $paid_leaves_remaining); ?></div>
                <div class="stat-label">Paid Leaves Remaining</div>
                
                <div style="margin-top: 20px;">
                    <canvas id="leaveChart" height="200"></canvas>
                </div>
            </div>

            <!-- Notifications Card -->
            <div class="card animate-slide-up">
                <div class="card-header">
                    <h3 class="card-title">Notifications</h3>
                    <div class="card-icon">
                        <i class="fas fa-bell"></i>
                        <?php if (count($notifications) > 0): ?>
                            <span class="notification-badge"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <ul class="list">
                    <?php if (count($notifications) > 0): ?>
                        <?php foreach ($notifications as $notif): ?>
                            <li class="list-item">
                                <div class="list-icon" style="background: linear-gradient(135deg, 
                                    <?php echo $notif['type'] == 'success' ? '#10b981' : 
                                           ($notif['type'] == 'warning' ? '#f59e0b' : 
                                           ($notif['type'] == 'error' ? '#ef4444' : '#3b82f6')); ?>, 
                                    <?php echo $notif['type'] == 'success' ? '#059669' : 
                                           ($notif['type'] == 'warning' ? '#d97706' : 
                                           ($notif['type'] == 'error' ? '#dc2626' : '#1d4ed8')); ?>);">
                                    <i class="fas fa-<?php echo $notif['type'] == 'success' ? 'check' : 
                                                       ($notif['type'] == 'warning' ? 'exclamation-triangle' : 
                                                       ($notif['type'] == 'error' ? 'times' : 'info-circle')); ?>"></i>
                                </div>
                                <div class="list-content">
                                    <h4><?php echo htmlspecialchars($notif['title']); ?></h4>
                                    <p><?php echo htmlspecialchars(substr($notif['message'], 0, 50)); ?>...</p>
                                    <small style="color: var(--gray);"><?php echo date('M d, h:i A', strtotime($notif['created_at'])); ?></small>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-item" style="text-align: center; padding: 30px; color: var(--gray);">
                            <i class="fas fa-bell-slash" style="font-size: 48px; margin-bottom: 15px;"></i>
                            <p>No new notifications</p>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Upcoming Holidays -->
            <div class="card animate-slide-left">
                <div class="card-header">
                    <h3 class="card-title">Upcoming Holidays</h3>
                    <div class="card-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                </div>
                
                <ul class="list">
                    <?php if (count($holidays) > 0): ?>
                        <?php foreach ($holidays as $holiday): ?>
                            <li class="list-item">
                                <div class="list-icon" style="background: linear-gradient(135deg, #ec4899, #be185d);">
                                    <i class="fas fa-gift"></i>
                                </div>
                                <div class="list-content">
                                    <h4><?php echo htmlspecialchars($holiday['name']); ?></h4>
                                    <p><?php echo date('D, M d, Y', strtotime($holiday['holiday_date'])); ?></p>
                                    <small style="color: var(--gray);"><?php echo htmlspecialchars($holiday['description']); ?></small>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-item" style="text-align: center; padding: 30px; color: var(--gray);">
                            <i class="fas fa-calendar-times" style="font-size: 48px; margin-bottom: 15px;"></i>
                            <p>No upcoming holidays</p>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Recent Leave Requests -->
            <div class="card animate-slide-right">
                <div class="card-header">
                    <h3 class="card-title">Recent Leave Requests</h3>
                    <div class="card-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                </div>
                
                <ul class="list">
                    <?php if (count($leave_requests) > 0): ?>
                        <?php foreach ($leave_requests as $leave): ?>
                            <li class="list-item">
                                <div class="list-icon" style="background: linear-gradient(135deg, 
                                    <?php echo $leave['status'] == 'approved' ? '#10b981' : 
                                           ($leave['status'] == 'rejected' ? '#ef4444' : '#f59e0b'); ?>, 
                                    <?php echo $leave['status'] == 'approved' ? '#059669' : 
                                           ($leave['status'] == 'rejected' ? '#dc2626' : '#d97706'); ?>);">
                                    <i class="fas fa-<?php echo $leave['status'] == 'approved' ? 'check' : 
                                                       ($leave['status'] == 'rejected' ? 'times' : 'clock'); ?>"></i>
                                </div>
                                <div class="list-content">
                                    <h4><?php echo htmlspecialchars($leave['leave_type']); ?></h4>
                                    <p><?php echo date('M d', strtotime($leave['start_date'])); ?> - <?php echo date('M d, Y', strtotime($leave['end_date'])); ?></p>
                                    <small style="color: var(--gray);">Status: <strong><?php echo ucfirst($leave['status']); ?></strong></small>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-item" style="text-align: center; padding: 30px; color: var(--gray);">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px;"></i>
                            <p>No recent leave requests</p>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <script>
        // Live Clock
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour12: true, 
                hour: '2-digit', 
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('liveClock').textContent = timeString;
        }
        setInterval(updateClock, 1000);
        
        // Menu Toggle
        document.getElementById('menuToggle').addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('active');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });
        
        // Leave Chart
        const leaveCtx = document.getElementById('leaveChart')?.getContext('2d');
        if (leaveCtx) {
            new Chart(leaveCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Paid Leaves Taken', 'Unpaid Leaves Taken', 'Paid Leaves Remaining'],
                    datasets: [{
                        data: [
                            <?php echo $paid_leaves_taken; ?>,
                            <?php echo $unpaid_leaves_taken; ?>,
                            <?php echo max(0, $paid_leaves_remaining); ?>
                        ],
                        backgroundColor: [
                            '#ef4444',
                            '#f59e0b',
                            '#10b981'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
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
                            }
                        }
                    },
                    cutout: '70%'
                }
            });
        }
        
        // Add hover effects to cards
        const cards = document.querySelectorAll('.card');
        cards.forEach(card => {
            card.addEventListener('mouseenter', () => {
                card.style.transform = 'translateY(-10px)';
            });
            
            card.addEventListener('mouseleave', () => {
                card.style.transform = 'translateY(0)';
            });
        });
        
        // Notification bell animation
        const bell = document.querySelector('.fa-bell');
        if (bell) {
            setInterval(() => {
                bell.style.transform = 'rotate(15deg)';
                setTimeout(() => {
                    bell.style.transform = 'rotate(-15deg)';
                }, 200);
                setTimeout(() => {
                    bell.style.transform = 'rotate(0deg)';
                }, 400);
            }, 5000);
        }
        
        // Stats cards hover effect
        const statsCards = document.querySelectorAll('.stats-card');
        statsCards.forEach(card => {
            card.addEventListener('mouseenter', () => {
                card.style.transform = 'translateY(-5px)';
                card.style.boxShadow = '0 10px 25px rgba(0,0,0,.1)';
            });
            
            card.addEventListener('mouseleave', () => {
                card.style.transform = 'translateY(0)';
                card.style.boxShadow = '0 4px 12px rgba(0,0,0,.06)';
            });
        });
    </script>
</body>
</html>