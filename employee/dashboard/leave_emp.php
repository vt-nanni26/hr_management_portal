<?php
session_start();
require_once "../../db_connection.php";

// Check if user is logged in and is employee
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'employee') {
    header("Location: login_emp.php");
    exit();
}

// Fetch employee details with null handling
$stmt = $conn->prepare("SELECT * FROM employees WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();

// Initialize employee data with defaults if null
if (!$employee) {
    $employee = [
        'first_name' => 'Employee',
        'last_name' => '',
        'designation' => 'Not assigned',
        'profile_picture' => null
    ];
}

// Fetch leave types
$leave_types_stmt = $conn->prepare("SELECT * FROM leave_types ORDER BY id");
$leave_types_stmt->execute();
$leave_types_result = $leave_types_stmt->get_result();
$leave_types = [];

while ($row = $leave_types_result->fetch_assoc()) {
    $leave_types[] = $row;
}

// Fetch leave requests
$leave_stmt = $conn->prepare("
    SELECT lr.*, lt.name as leave_type, lt.is_paid 
    FROM leave_requests lr 
    JOIN leave_types lt ON lr.leave_type_id = lt.id 
    WHERE lr.user_id = ? AND lr.user_type = 'employee' 
    ORDER BY lr.created_at DESC
");
$leave_stmt->bind_param("i", $_SESSION['user_id']);
$leave_stmt->execute();
$leave_result = $leave_stmt->get_result();
$leave_requests = [];

while ($row = $leave_result->fetch_assoc()) {
    $leave_requests[] = $row;
}

// Handle new leave request
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_leave'])) {
    $leave_type_id = $_POST['leave_type_id'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = $_POST['reason'];
    
    // Calculate total days
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $total_days = $end->diff($start)->days + 1; // Include both start and end dates
    
    // Check if dates are valid
    if ($start_date > $end_date) {
        $message = "End date must be after start date!";
        $message_type = "error";
    } elseif ($total_days <= 0) {
        $message = "Invalid date range!";
        $message_type = "error";
    } else {
        // Insert leave request
        $insert_stmt = $conn->prepare("
            INSERT INTO leave_requests 
            (user_id, user_type, leave_type_id, start_date, end_date, total_days, reason, status, created_at, updated_at)
            VALUES (?, 'employee', ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
        ");
        $insert_stmt->bind_param("iissis", $_SESSION['user_id'], $leave_type_id, $start_date, $end_date, $total_days, $reason);
        
        if ($insert_stmt->execute()) {
            $message = "Leave request submitted successfully!";
            $message_type = "success";
            
            // Refresh leave requests
            $leave_stmt->execute();
            $leave_result = $leave_stmt->get_result();
            $leave_requests = [];
            while ($row = $leave_result->fetch_assoc()) {
                $leave_requests[] = $row;
            }
        } else {
            $message = "Error submitting leave request. Please try again.";
            $message_type = "error";
        }
    }
}

// Handle leave cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_leave'])) {
    $leave_id = $_POST['leave_id'];
    
    $cancel_stmt = $conn->prepare("
        UPDATE leave_requests 
        SET status = 'cancelled', updated_at = NOW() 
        WHERE id = ? AND user_id = ? AND status = 'pending'
    ");
    $cancel_stmt->bind_param("ii", $leave_id, $_SESSION['user_id']);
    
    if ($cancel_stmt->execute() && $cancel_stmt->affected_rows > 0) {
        $message = "Leave request cancelled successfully!";
        $message_type = "success";
        
        // Refresh leave requests
        $leave_stmt->execute();
        $leave_result = $leave_stmt->get_result();
        $leave_requests = [];
        while ($row = $leave_result->fetch_assoc()) {
            $leave_requests[] = $row;
        }
    } else {
        $message = "Cannot cancel this leave request.";
        $message_type = "error";
    }
}

// Calculate leave statistics
$approved_leaves = array_filter($leave_requests, function($request) {
    return $request['status'] === 'approved';
});

$pending_leaves = array_filter($leave_requests, function($request) {
    return $request['status'] === 'pending';
});

$total_approved_days = array_sum(array_column($approved_leaves, 'total_days'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Management - HR Portal</title>
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

        /* Message Display */
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

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-item {
            background: #f9fafb;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--gray);
            text-transform: uppercase;
        }

        /* Form Styles */
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

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(239, 68, 68, 0.3);
        }

        /* Leave Types */
        .leave-types {
            margin-top: 20px;
        }

        .leave-type-item {
            background: #f9fafb;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Leave Table */
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: #f9fafb;
            font-weight: 600;
            color: var(--dark);
        }

        tr:hover {
            background: #f9fafb;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-cancelled {
            background: #e5e7eb;
            color: #374151;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            color: var(--dark);
            cursor: pointer;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
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
            <li><a href="profile_emp.php"><i class="fas fa-user-circle"></i> Profile</a></li>
            <li><a href="attendance_emp.php"><i class="fas fa-calendar-alt"></i> Attendance</a></li>
            <li><a href="payroll_emp.php"><i class="fas fa-file-invoice-dollar"></i> Payroll</a></li>
            <li><a href="leave_emp.php" class="active"><i class="fas fa-plane"></i> Leave</a></li>
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
                <h1>Leave Management</h1>
                <p>Apply for leave and track your requests</p>
            </div>
            
            <div class="user-info">
                <img src="<?php echo !empty($employee['profile_picture']) ? htmlspecialchars($employee['profile_picture']) : 'https://ui-avatars.com/api/?name=' . urlencode(($employee['first_name'] ?? 'Employee') . ' ' . ($employee['last_name'] ?? '')) . '&background=667eea&color=fff'; ?>" 
                     alt="Profile" class="profile-img">
                <div>
                    <h4><?php echo htmlspecialchars(($employee['first_name'] ?? 'Employee') . ' ' . ($employee['last_name'] ?? '')); ?></h4>
                    <p style="color: var(--gray); font-size: 14px;"><?php echo htmlspecialchars($employee['designation'] ?? 'Not assigned'); ?></p>
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
            <!-- Apply Leave Card -->
            <div class="card">
                <h3 class="card-title">Apply for Leave</h3>
                
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo count($leave_requests); ?></div>
                        <div class="stat-label">Total Requests</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo count($approved_leaves); ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo count($pending_leaves); ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $total_approved_days; ?></div>
                        <div class="stat-label">Days Taken</div>
                    </div>
                </div>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="leave_type_id">Leave Type</label>
                        <select id="leave_type_id" name="leave_type_id" class="form-control" required>
                            <option value="">Select Leave Type</option>
                            <?php foreach ($leave_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>">
                                    <?php echo htmlspecialchars($type['name']); ?> 
                                    (<?php echo $type['is_paid'] ? 'Paid' : 'Unpaid'; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" required
                               min="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" required
                               min="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="reason">Reason</label>
                        <textarea id="reason" name="reason" class="form-control" rows="3" 
                                  placeholder="Please provide a reason for your leave..." required></textarea>
                    </div>

                    <button type="submit" name="apply_leave" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-paper-plane"></i> Submit Leave Request
                    </button>
                </form>
            </div>

            <!-- Leave Types Card -->
            <div class="card">
                <h3 class="card-title">Available Leave Types</h3>
                
                <?php if (count($leave_types) > 0): ?>
                    <div class="leave-types">
                        <?php foreach ($leave_types as $type): ?>
                            <div class="leave-type-item">
                                <div>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($type['name']); ?></div>
                                    <small style="color: var(--gray);">
                                        <?php echo $type['is_paid'] ? 'Paid Leave' : 'Unpaid Leave'; ?> • 
                                        Max <?php echo $type['max_days_per_year']; ?> days/year
                                    </small>
                                </div>
                                <div>
                                    <span style="color: var(--primary); font-weight: 600;">
                                        <?php echo $type['max_days_per_year']; ?> days
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <p>No leave types available</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Leave History Card -->
        <div class="card" style="margin-top: 25px;">
            <h3 class="card-title">Leave History</h3>
            
            <?php if (count($leave_requests) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Leave Type</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Total Days</th>
                                <th>Status</th>
                                <th>Applied On</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leave_requests as $request): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($request['leave_type']); ?>
                                        <?php if ($request['is_paid']): ?>
                                            <span style="color: var(--success); font-size: 12px;">(Paid)</span>
                                        <?php else: ?>
                                            <span style="color: var(--warning); font-size: 12px;">(Unpaid)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d-m-Y', strtotime($request['start_date'])); ?></td>
                                    <td><?php echo date('d-m-Y', strtotime($request['end_date'])); ?></td>
                                    <td><?php echo $request['total_days']; ?> days</td>
                                    <td>
                                        <?php 
                                        $badge_class = '';
                                        switch($request['status']) {
                                            case 'pending': $badge_class = 'badge-pending'; break;
                                            case 'approved': $badge_class = 'badge-approved'; break;
                                            case 'rejected': $badge_class = 'badge-rejected'; break;
                                            case 'cancelled': $badge_class = 'badge-cancelled'; break;
                                            default: $badge_class = 'badge-pending';
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $badge_class; ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d-m-Y', strtotime($request['created_at'])); ?></td>
                                    <td>
                                        <?php if ($request['status'] === 'pending'): ?>
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="leave_id" value="<?php echo $request['id']; ?>">
                                                <button type="submit" name="cancel_leave" class="btn btn-danger" 
                                                        onclick="return confirm('Are you sure you want to cancel this leave request?')">
                                                    <i class="fas fa-times"></i> Cancel
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color: var(--gray); font-size: 12px;">No actions available</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No leave requests found</p>
                </div>
            <?php endif; ?>
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
        
        // Set end date min to start date
        document.getElementById('start_date').addEventListener('change', function() {
            document.getElementById('end_date').min = this.value;
        });
    </script>
</body>
</html>