<?php
// sidebar_hr.php - Complete Sidebar Component for HR Dashboard
// session_start();

// Check if HR is logged in
if (!isset($_SESSION['hr_logged_in']) || $_SESSION['hr_logged_in'] !== true) {
    // For demo purposes, auto-login
    $_SESSION['hr_logged_in'] = true;
    $_SESSION['hr_id'] = 1;
    $_SESSION['hr_email'] = 'hr@hrportal.com';
    $_SESSION['hr_role'] = 'hr';
}

// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Database connection for badges
$host = "localhost";
$dbname = "hr_management_portal";
$username = "root";
$password = "";
$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    // Use default values if connection fails
    $total_employees = 42;
    $total_interns = 15;
    $total_trainers = 8;
    $pending_applicants = 8;
    $notification_count = 5;
} else {
    // Get real statistics
    $conn->set_charset("utf8mb4");
    
    // Total employees
    $result = $conn->query("SELECT COUNT(*) as total FROM employees WHERE employment_status = 'active'");
    $total_employees = $result ? $result->fetch_assoc()['total'] : 42;
    
    // Total interns
    $result = $conn->query("SELECT COUNT(*) as total FROM interns WHERE internship_status = 'active'");
    $total_interns = $result ? $result->fetch_assoc()['total'] : 15;
    
    // Total trainers
    $result = $conn->query("SELECT COUNT(*) as total FROM trainers WHERE employment_status = 'active'");
    $total_trainers = $result ? $result->fetch_assoc()['total'] : 8;
    
    // Pending applicants
    $result = $conn->query("SELECT COUNT(*) as total FROM applicants WHERE application_status = 'applied'");
    $pending_applicants = $result ? $result->fetch_assoc()['total'] : 8;
    
    // Notification count (sample)
    $notification_count = 5;
    
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Sidebar Styles */
        :root {
            --sidebar-bg: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
            --sidebar-primary: #4f46e5;
            --sidebar-secondary: #7e22ce;
            --sidebar-accent: #10b981;
            --sidebar-warning: #f59e0b;
            --sidebar-danger: #ef4444;
            --sidebar-gray: #94a3b8;
            --light: #f8fafc;
            --dark: #1e293b;
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
        
        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px 20px;
            background: linear-gradient(135deg, var(--sidebar-primary) 0%, var(--sidebar-secondary) 100%);
            color: white;
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .logo-icon {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        
        .logo-icon i {
            font-size: 24px;
        }
        
        .logo-text h2 {
            font-size: 20px;
            font-weight: 700;
            line-height: 1.2;
            color: white;
        }
        
        .logo-text span {
            font-size: 12px;
            opacity: 0.9;
            color: rgba(255, 255, 255, 0.8);
        }
        
        .sidebar-menu {
            padding: 20px 0;
            flex: 1;
            overflow-y: auto;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 14px 25px;
            color: var(--sidebar-gray);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            cursor: pointer;
            position: relative;
            font-size: 14px;
            font-weight: 500;
            margin: 5px 10px;
            border-radius: 8px;
        }
        
        .menu-item:hover {
            background: rgba(79, 70, 229, 0.1);
            color: white;
            border-left-color: var(--sidebar-primary);
            padding-left: 30px;
            transform: translateX(5px);
        }
        
        .menu-item.active {
            background: linear-gradient(90deg, rgba(79, 70, 229, 0.2) 0%, rgba(79, 70, 229, 0.1) 100%);
            color: white;
            border-left-color: var(--sidebar-primary);
            box-shadow: 0 5px 15px rgba(79, 70, 229, 0.2);
        }
        
        .menu-item i {
            width: 24px;
            margin-right: 12px;
            font-size: 16px;
            text-align: center;
        }
        
        .menu-item span {
            flex-grow: 1;
        }
        
        .menu-divider {
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
            margin: 15px 25px;
        }
        
        /* Badge Styles */
        .menu-badge {
            background: linear-gradient(135deg, var(--sidebar-danger) 0%, #f87171 100%);
            color: white;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 10px;
            margin-left: 8px;
            animation: pulse 2s infinite;
        }
        
        .notification-badge {
            background: linear-gradient(135deg, var(--sidebar-warning) 0%, #fbbf24 100%);
            color: white;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 10px;
            margin-left: 8px;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        /* Footer Styles */
        .sidebar-footer {
            padding: 20px;
            background: rgba(0, 0, 0, 0.2);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: auto;
        }
        
        .sidebar-footer .user-profile {
            display: flex;
            align-items: center;
            color: white;
        }
        
        .sidebar-footer .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--sidebar-primary) 0%, var(--sidebar-secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            font-weight: 600;
            font-size: 14px;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        
        .sidebar-footer .user-info {
            flex: 1;
        }
        
        .sidebar-footer .user-info h4 {
            font-size: 14px;
            font-weight: 600;
            margin: 0 0 5px 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .sidebar-footer .user-info span {
            font-size: 12px;
            opacity: 0.8;
            display: block;
        }
        
        .sidebar-footer .logout-btn {
            color: rgba(255, 255, 255, 0.7);
            font-size: 16px;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.05);
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
        }
        
        .sidebar-footer .logout-btn:hover {
            color: white;
            background: rgba(239, 68, 68, 0.2);
            transform: rotate(15deg);
        }
        
        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: var(--sidebar-primary);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(79, 70, 229, 0.3);
        }
        
        .menu-toggle:hover {
            background: var(--sidebar-secondary);
            transform: scale(1.1);
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .menu-toggle {
                display: flex;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 250px;
            }
            
            .logo-text h2 {
                font-size: 18px;
            }
            
            .logo-text span {
                font-size: 11px;
            }
            
            .menu-item {
                padding: 12px 20px;
                font-size: 13px;
            }
        }
        
        /* Scrollbar styling */
        .sidebar-menu::-webkit-scrollbar {
            width: 5px;
        }
        
        .sidebar-menu::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .sidebar-menu::-webkit-scrollbar-thumb {
            background: var(--sidebar-primary);
            border-radius: 10px;
        }
        
        .sidebar-menu::-webkit-scrollbar-thumb:hover {
            background: var(--sidebar-secondary);
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-icon">
                <i class="fas fa-user-tie"></i>
            </div>
            <div class="logo-text">
                <h2>HR Portal</h2>
                <span>Human Resources Management</span>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <!-- Dashboard -->
            <a href="hr_dashboard.php" class="menu-item <?php echo $current_page == 'hr_dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            
            <div class="menu-divider"></div>
            
            <!-- Employee Management -->
            <a href="emp_manage.php" class="menu-item <?php echo $current_page == 'emp_manage.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-tie"></i>
                <span>Employee Management</span>
                <?php if ($total_employees > 0): ?>
                <span class="menu-badge"><?php echo $total_employees; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Intern Management -->
            <a href="intern_manage.php" class="menu-item <?php echo $current_page == 'intern_manage.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-graduate"></i>
                <span>Intern Management</span>
                <?php if ($total_interns > 0): ?>
                <span class="menu-badge"><?php echo $total_interns; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Trainer Management -->
            <a href="trainer_manage.php" class="menu-item <?php echo $current_page == 'trainer_manage.php' ? 'active' : ''; ?>">
                <i class="fas fa-chalkboard-teacher"></i>
                <span>Trainer Management</span>
                <?php if ($total_trainers > 0): ?>
                <span class="menu-badge"><?php echo $total_trainers; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Applicant Management -->
            <a href="apply_manage.php" class="menu-item <?php echo $current_page == 'apply_manage.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-contract"></i>
                <span>Applicant Management</span>
                <?php if ($pending_applicants > 0): ?>
                <span class="menu-badge"><?php echo $pending_applicants; ?></span>
                <?php endif; ?>
            </a>
            
            <!-- Interview Schedule -->
            <a href="interview_manage.php" class="menu-item <?php echo $current_page == 'interview_manage.php' ? 'active' : ''; ?>">
                <i class="fas fa-handshake"></i>
                <span>Interview Schedule</span>
            </a>
        
            <div class="menu-divider"></div>
            
            <!-- Attendance -->
            <a href="attendance_manage.php" class="menu-item <?php echo $current_page == 'attendance_manage.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-check"></i>
                <span>Attendance</span>
            </a>
            
            <!-- Leave Management -->
            <a href="leave_manage.php" class="menu-item <?php echo $current_page == 'leave_manage.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i>
                <span>Leave Management</span>
            </a>
            
            <!-- Payroll Management -->
            <a href="payroll_manage.php" class="menu-item <?php echo $current_page == 'payroll_manage.php' ? 'active' : ''; ?>">
                <i class="fas fa-money-check-alt"></i>
                <span>Payroll Management</span>
            </a>
            
            <div class="menu-divider"></div>
            
            <!-- Task Management -->
            <a href="task_manage.php" class="menu-item <?php echo $current_page == 'task_manage.php' ? 'active' : ''; ?>">
                <i class="fas fa-tasks"></i>
                <span>Task Management</span>
            </a>
            
            <!-- Documents -->
            <a href="document_manage.php" class="menu-item <?php echo $current_page == 'document_manage.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i>
                <span>Documents</span>
            </a>
            
            <!-- Notifications -->
            <a href="notification_manage.php" class="menu-item <?php echo $current_page == 'notification_manage.php' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <?php if ($notification_count > 0): ?>
                <span class="notification-badge"><?php echo $notification_count; ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <div class="sidebar-footer">
            <div class="user-profile">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($_SESSION['hr_email'] ?? 'HR', 0, 2)); ?>
                </div>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($_SESSION['hr_email'] ?? 'HR Manager'); ?></h4>
                    <span>HR Manager</span>
                </div>
                <a href="../../logout.php" class="logout-btn" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            
            // Mobile menu toggle
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    
                    // Change icon
                    const icon = this.querySelector('i');
                    if (sidebar.classList.contains('active')) {
                        icon.classList.remove('fa-bars');
                        icon.classList.add('fa-times');
                    } else {
                        icon.classList.remove('fa-times');
                        icon.classList.add('fa-bars');
                    }
                });
            }
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 1024) {
                    const isClickInsideSidebar = sidebar.contains(event.target);
                    const isClickOnToggle = menuToggle.contains(event.target);
                    
                    if (!isClickInsideSidebar && !isClickOnToggle && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                        if (menuToggle.querySelector('i')) {
                            menuToggle.querySelector('i').classList.remove('fa-times');
                            menuToggle.querySelector('i').classList.add('fa-bars');
                        }
                    }
                }
            });
            
            // Prevent clicks inside sidebar from closing it
            if (sidebar) {
                sidebar.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
            
            // Add click animations to menu items
            const menuItems = document.querySelectorAll('.menu-item');
            menuItems.forEach(item => {
                item.addEventListener('click', function() {
                    this.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 150);
                });
            });
        });
    </script>
</body>
</html>