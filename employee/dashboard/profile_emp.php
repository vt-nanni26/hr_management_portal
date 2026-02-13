<?php
session_start();
require_once "../../db_connection.php";

// Check if user is logged in and is employee
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'employee') {
    header("Location: login_emp.php");
    exit();
}

// Fetch employee details
$stmt = $conn->prepare("
    SELECT e.*, d.name as department_name, s.name as shift_name, s.start_time, s.end_time 
    FROM employees e 
    LEFT JOIN departments d ON e.department_id = d.id 
    LEFT JOIN shifts s ON e.shift_id = s.id 
    WHERE e.user_id = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();

// Initialize employee data with defaults if null
if (!$employee) {
    $employee = [
        'first_name' => 'Employee',
        'last_name' => '',
        'email' => 'N/A',
        'designation' => 'Not assigned',
        'emp_id' => 'N/A',
        'department_name' => 'Not assigned',
        'shift_name' => 'Not assigned',
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'joining_date' => date('Y-m-d'),
        'profile_picture' => null,
        'contact_number' => '',
        'emergency_contact' => '',
        'address' => '',
        'employment_status' => 'active'
    ];
} else {
    // Ensure all required keys exist with default values
    $defaults = [
        'first_name' => 'Employee',
        'last_name' => '',
        'email' => 'N/A',
        'designation' => 'Not assigned',
        'emp_id' => 'N/A',
        'department_name' => 'Not assigned',
        'shift_name' => 'Not assigned',
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'joining_date' => date('Y-m-d'),
        'profile_picture' => null,
        'contact_number' => '',
        'emergency_contact' => '',
        'address' => '',
        'employment_status' => 'active'
    ];
    
    foreach ($defaults as $key => $value) {
        if (!isset($employee[$key]) || $employee[$key] === null) {
            $employee[$key] = $value;
        }
    }
}

// Handle profile update
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $contact_number = $_POST['contact_number'];
    $emergency_contact = $_POST['emergency_contact'];
    $address = $_POST['address'];
    
    // Check if employee record exists
    $check_stmt = $conn->prepare("SELECT id FROM employees WHERE user_id = ?");
    $check_stmt->bind_param("i", $_SESSION['user_id']);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Update existing employee information
        $update_stmt = $conn->prepare("
            UPDATE employees 
            SET first_name = ?, last_name = ?, contact_number = ?, 
                emergency_contact = ?, address = ?, updated_at = NOW() 
            WHERE user_id = ?
        ");
        $update_stmt->bind_param("sssssi", $first_name, $last_name, $contact_number, 
                                $emergency_contact, $address, $_SESSION['user_id']);
    } else {
        // Insert new employee record
        $emp_id = 'EMP' . str_pad($_SESSION['user_id'], 4, '0', STR_PAD_LEFT);
        $update_stmt = $conn->prepare("
            INSERT INTO employees 
            (user_id, emp_id, first_name, last_name, email, contact_number, 
             emergency_contact, address, joining_date, employment_status, designation, salary)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'active', 'Employee', 0.00)
        ");
        $update_stmt->bind_param("isssssss", $_SESSION['user_id'], $emp_id, $first_name, 
                                $last_name, $_SESSION['user_email'] ?? 'employee@example.com', 
                                $contact_number, $emergency_contact, $address);
    }
    
    if ($update_stmt->execute()) {
        $message = "Profile updated successfully!";
        $message_type = "success";
        
        // Refresh employee data
        $stmt->execute();
        $result = $stmt->get_result();
        $employee = $result->fetch_assoc();
        
        // Re-initialize with defaults if needed
        if ($employee) {
            foreach ($defaults as $key => $value) {
                if (!isset($employee[$key]) || $employee[$key] === null) {
                    $employee[$key] = $value;
                }
            }
        } else {
            $employee = $defaults;
            $employee['first_name'] = $first_name;
            $employee['last_name'] = $last_name;
            $employee['contact_number'] = $contact_number;
            $employee['emergency_contact'] = $emergency_contact;
            $employee['address'] = $address;
        }
    } else {
        $message = "Error updating profile. Please try again.";
        $message_type = "error";
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($new_password !== $confirm_password) {
        $message = "New passwords don't match!";
        $message_type = "error";
    } else {
        // Fetch current password hash
        $password_stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
        $password_stmt->bind_param("i", $_SESSION['user_id']);
        $password_stmt->execute();
        $password_result = $password_stmt->get_result();
        $user = $password_result->fetch_assoc();
        
        if ($user && password_verify($current_password, $user['password_hash'])) {
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_password_stmt = $conn->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $update_password_stmt->bind_param("si", $new_password_hash, $_SESSION['user_id']);
            
            if ($update_password_stmt->execute()) {
                $message = "Password changed successfully!";
                $message_type = "success";
            } else {
                $message = "Error changing password.";
                $message_type = "error";
            }
        } else {
            $message = "Current password is incorrect!";
            $message_type = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(255, 255, 255, 0.1);
            color: white;
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

        .profile-img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary);
        }

        /* Content */
        .content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        @media (max-width: 1024px) {
            .content {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
        }

        .profile-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .profile-picture {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid var(--primary);
            margin: 0 auto 20px;
            display: block;
        }

        .employee-id {
            background: #f3f4f6;
            padding: 10px 20px;
            border-radius: 50px;
            display: inline-block;
            font-weight: 600;
            color: var(--dark);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .info-item {
            background: #f9fafb;
            padding: 15px;
            border-radius: 10px;
        }

        .info-label {
            font-size: 12px;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
        }

        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .message.success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            color: var(--dark);
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
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
            <li><a href="employee_dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="profile_emp.php" class="active"><i class="fas fa-user-circle"></i> Profile</a></li>
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
                <h1>Profile Management</h1>
                <p>Update your personal information and settings</p>
            </div>
            
            <div class="user-info">
                <img src="<?php echo !empty($employee['profile_picture']) ? htmlspecialchars($employee['profile_picture']) : 'https://ui-avatars.com/api/?name=' . urlencode($employee['first_name'] . ' ' . $employee['last_name']) . '&background=667eea&color=fff'; ?>" 
                     alt="Profile" class="profile-img">
                <div>
                    <h4><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h4>
                    <p style="color: var(--gray); font-size: 14px;"><?php echo htmlspecialchars($employee['designation']); ?></p>
                </div>
            </div>
        </div>

        <!-- Message Display -->
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="content">
            <!-- Personal Information Card -->
            <div class="card">
                <h3 class="card-title">Personal Information</h3>
                
                <div class="profile-header">
                    <img src="<?php echo !empty($employee['profile_picture']) ? htmlspecialchars($employee['profile_picture']) : 'https://ui-avatars.com/api/?name=' . urlencode($employee['first_name'] . ' ' . $employee['last_name']) . '&background=667eea&color=fff'; ?>" 
                         alt="Profile" class="profile-picture">
                    <div class="employee-id">ID: <?php echo htmlspecialchars($employee['emp_id']); ?></div>
                </div>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" class="form-control" 
                               value="<?php echo htmlspecialchars($employee['first_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" class="form-control" 
                               value="<?php echo htmlspecialchars($employee['last_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" class="form-control" 
                               value="<?php echo htmlspecialchars($employee['email']); ?>" disabled>
                        <small style="color: var(--gray);">Contact HR to change email</small>
                    </div>

                    <div class="form-group">
                        <label for="contact_number">Contact Number</label>
                        <input type="tel" id="contact_number" name="contact_number" class="form-control" 
                               value="<?php echo htmlspecialchars($employee['contact_number']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="emergency_contact">Emergency Contact</label>
                        <input type="tel" id="emergency_contact" name="emergency_contact" class="form-control" 
                               value="<?php echo htmlspecialchars($employee['emergency_contact']); ?>">
                    </div>

                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" class="form-control" rows="3"><?php echo htmlspecialchars($employee['address']); ?></textarea>
                    </div>

                    <button type="submit" name="update_profile" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                </form>
            </div>

            <!-- Account Information Card -->
            <div class="card">
                <h3 class="card-title">Account Information</h3>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Employee ID</div>
                        <div class="info-value"><?php echo htmlspecialchars($employee['emp_id']); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Department</div>
                        <div class="info-value"><?php echo htmlspecialchars($employee['department_name']); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Designation</div>
                        <div class="info-value"><?php echo htmlspecialchars($employee['designation']); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Shift Timing</div>
                        <div class="info-value">
                            <?php 
                            if ($employee['start_time'] && $employee['end_time']) {
                                echo date('h:i A', strtotime($employee['start_time'])) . ' - ' . 
                                     date('h:i A', strtotime($employee['end_time']));
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Joining Date</div>
                        <div class="info-value"><?php echo date('M d, Y', strtotime($employee['joining_date'])); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Employment Status</div>
                        <div class="info-value">
                            <span style="color: var(--success); font-weight: 600;">
                                <?php echo ucfirst($employee['employment_status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <h3 class="card-title" style="margin-top: 40px;">Change Password</h3>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                    </div>

                    <button type="submit" name="change_password" class="btn btn-success">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
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
    </script>
</body>
</html>