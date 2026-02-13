<?php
// interview_manage.php - HR Interview Management
session_start();

// Check if HR is logged in
if (!isset($_SESSION['hr_logged_in']) || $_SESSION['hr_logged_in'] !== true) {
    // For demo purposes, auto-login
    $_SESSION['hr_logged_in'] = true;
    $_SESSION['hr_id'] = 1;
    $_SESSION['hr_email'] = 'hr@hrportal.com';
    $_SESSION['hr_role'] = 'hr';
}

// Database configuration
$host = "localhost";
$dbname = "emp_system";
$username = "root";
$password = "";
$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Handle form submissions
$message = '';
$message_type = '';

// Add new interview
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_interview'])) {
    $applicant_id = $conn->real_escape_string($_POST['applicant_id']);
    $interview_date = $conn->real_escape_string($_POST['interview_date']);
    $interview_time = $conn->real_escape_string($_POST['interview_time']);
    $interview_type = $conn->real_escape_string($_POST['interview_type']);
    $interviewer_id = $conn->real_escape_string($_POST['interviewer_id']);
    $notes = $conn->real_escape_string($_POST['notes'] ?? '');
    
    $interview_datetime = $interview_date . ' ' . $interview_time . ':00';
    
    $sql = "INSERT INTO interviews (applicant_id, interview_date, interview_type, interviewer_id, status, feedback) 
            VALUES ('$applicant_id', '$interview_datetime', '$interview_type', '$interviewer_id', 'scheduled', '$notes')";
    
    if ($conn->query($sql)) {
        // Update applicant status to interview
        $update_sql = "UPDATE applicants SET application_status = 'interview' WHERE id = '$applicant_id'";
        $conn->query($update_sql);
        
        $message = "Interview scheduled successfully!";
        $message_type = "success";
    } else {
        $message = "Error scheduling interview: " . $conn->error;
        $message_type = "error";
    }
}

// Update interview status
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $interview_id = $conn->real_escape_string($_POST['interview_id']);
    $status = $conn->real_escape_string($_POST['status']);
    $feedback = $conn->real_escape_string($_POST['feedback'] ?? '');
    $rating = $conn->real_escape_string($_POST['rating'] ?? NULL);
    
    $sql = "UPDATE interviews SET status = '$status', feedback = '$feedback', rating = '$rating' WHERE id = '$interview_id'";
    
    if ($conn->query($sql)) {
        // If interview is completed, check if we need to update applicant status
        if ($status == 'completed') {
            // Get applicant ID for this interview
            $app_sql = "SELECT applicant_id FROM interviews WHERE id = '$interview_id'";
            $result = $conn->query($app_sql);
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $applicant_id = $row['applicant_id'];
                
                // Update applicant status based on rating
                if ($rating >= 4) {
                    $update_sql = "UPDATE applicants SET application_status = 'selected' WHERE id = '$applicant_id'";
                } elseif ($rating <= 2) {
                    $update_sql = "UPDATE applicants SET application_status = 'rejected' WHERE id = '$applicant_id'";
                } else {
                    $update_sql = "UPDATE applicants SET application_status = 'screening' WHERE id = '$applicant_id'";
                }
                $conn->query($update_sql);
            }
        }
        
        $message = "Interview status updated successfully!";
        $message_type = "success";
    } else {
        $message = "Error updating interview: " . $conn->error;
        $message_type = "error";
    }
}

// Delete interview
if (isset($_GET['delete'])) {
    $interview_id = $conn->real_escape_string($_GET['delete']);
    
    $sql = "DELETE FROM interviews WHERE id = '$interview_id'";
    
    if ($conn->query($sql)) {
        $message = "Interview deleted successfully!";
        $message_type = "success";
    } else {
        $message = "Error deleting interview: " . $conn->error;
        $message_type = "error";
    }
}

// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch data for dropdowns
$applicants = [];
$sql = "SELECT id, CONCAT(first_name, ' ', last_name) as name, position_applied, application_status 
        FROM applicants 
        WHERE application_status IN ('applied', 'screening', 'interview') 
        ORDER BY applied_date DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $applicants[] = $row;
    }
}

$employees = [];
$sql = "SELECT id, CONCAT(first_name, ' ', last_name) as name, designation 
        FROM employees 
        WHERE employment_status = 'active' 
        ORDER BY first_name";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
}

// Fetch interviews data with applicant and interviewer details
$interviews = [];
$sql = "SELECT i.*, 
               CONCAT(a.first_name, ' ', a.last_name) as applicant_name,
               a.position_applied,
               a.email as applicant_email,
               a.contact_number as applicant_phone,
               CONCAT(e.first_name, ' ', e.last_name) as interviewer_name,
               e.designation as interviewer_designation
        FROM interviews i
        LEFT JOIN applicants a ON i.applicant_id = a.id
        LEFT JOIN employees e ON i.interviewer_id = e.id
        ORDER BY i.interview_date DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $interviews[] = $row;
    }
}

// Get statistics
$upcoming_interviews = 0;
$today_interviews = 0;
$completed_interviews = 0;

$sql = "SELECT 
        COUNT(CASE WHEN interview_date >= NOW() AND status = 'scheduled' THEN 1 END) as upcoming,
        COUNT(CASE WHEN DATE(interview_date) = CURDATE() AND status = 'scheduled' THEN 1 END) as today,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed
        FROM interviews";
$result = $conn->query($sql);
if ($result && $row = $result->fetch_assoc()) {
    $upcoming_interviews = $row['upcoming'];
    $today_interviews = $row['today'];
    $completed_interviews = $row['completed'];
}

// Get interview type distribution
$interview_types = [];
$sql = "SELECT interview_type, COUNT(*) as count FROM interviews GROUP BY interview_type";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $interview_types[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interview Management - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
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
        
        /* Main Content - Adjusted for Sidebar */
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
            animation: slideDown 0.5s ease;
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
            position: relative;
            overflow: hidden;
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
            margin-bottom: 20px;
            font-size: 24px;
            color: white;
        }
        
        .stat-icon.upcoming { background: linear-gradient(135deg, var(--primary) 0%, var(--info) 100%); }
        .stat-icon.today { background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%); }
        .stat-icon.completed { background: linear-gradient(135deg, var(--success) 0%, #34d399 100%); }
        
        .stat-info h3 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--dark);
        }
        
        .stat-info p {
            color: var(--gray);
            font-size: 14px;
        }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .content-card {
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
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: var(--dark);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
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
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
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
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-badge.scheduled { background: #dbeafe; color: #1e40af; }
        .status-badge.completed { background: #d1fae5; color: #065f46; }
        .status-badge.cancelled { background: #fee2e2; color: #991b1b; }
        .status-badge.rescheduled { background: #fef3c7; color: #92400e; }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-edit {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .btn-edit:hover {
            background: #bfdbfe;
        }
        
        .btn-delete {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .btn-delete:hover {
            background: #fecaca;
        }
        
        /* Rating Stars */
        .rating {
            display: flex;
            gap: 2px;
        }
        
        .star {
            color: #e2e8f0;
            font-size: 14px;
        }
        
        .star.filled {
            color: #f59e0b;
        }
        
        /* Message Styles */
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
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
            color: #dc2626;
            border-left: 4px solid #ef4444;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            font-size: 20px;
            color: var(--dark);
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray);
        }
        
        /* Chart Container */
        .chart-container {
            height: 300px;
            position: relative;
        }
        
        /* Animations */
        @keyframes slideDown {
            from { 
                opacity: 0;
                transform: translateY(-30px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-row {
                grid-template-columns: 1fr;
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
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar_hr.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-handshake"></i> Interview Management</h1>
                <p>Schedule and manage candidate interviews</p>
            </div>
            
            <button class="btn-primary" onclick="openAddModal()">
                <i class="fas fa-calendar-plus"></i> Schedule New Interview
            </button>
        </div>
        
        <!-- Message Display -->
        <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo $message; ?>
        </div>
        <?php endif; ?>
        
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon upcoming">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $upcoming_interviews; ?></h3>
                    <p>Upcoming Interviews</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon today">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $today_interviews; ?></h3>
                    <p>Today's Interviews</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon completed">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $completed_interviews; ?></h3>
                    <p>Completed Interviews</p>
                </div>
            </div>
        </div>
        
        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Schedule Interview Form -->
            <div class="content-card">
                <div class="card-header">
                    <h3>Schedule Interview</h3>
                </div>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="applicant_id">Select Applicant *</label>
                        <select name="applicant_id" id="applicant_id" class="form-control" required>
                            <option value="">-- Select Applicant --</option>
                            <?php foreach ($applicants as $applicant): ?>
                            <option value="<?php echo $applicant['id']; ?>">
                                <?php echo htmlspecialchars($applicant['name']) . ' - ' . htmlspecialchars($applicant['position_applied']) . ' (' . htmlspecialchars($applicant['application_status']) . ')'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="interview_date">Interview Date *</label>
                            <input type="date" name="interview_date" id="interview_date" class="form-control" required 
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="interview_time">Interview Time *</label>
                            <input type="time" name="interview_time" id="interview_time" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="interview_type">Interview Type *</label>
                            <select name="interview_type" id="interview_type" class="form-control" required>
                                <option value="technical">Technical</option>
                                <option value="hr">HR</option>
                                <option value="managerial">Managerial</option>
                                <option value="final">Final</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="interviewer_id">Interviewer *</label>
                            <select name="interviewer_id" id="interviewer_id" class="form-control" required>
                                <option value="">-- Select Interviewer --</option>
                                <?php foreach ($employees as $employee): ?>
                                <option value="<?php echo $employee['id']; ?>">
                                    <?php echo htmlspecialchars($employee['name']) . ' - ' . htmlspecialchars($employee['designation']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Additional Notes</label>
                        <textarea name="notes" id="notes" class="form-control" rows="3" 
                                  placeholder="Any special instructions or notes for the interview..."></textarea>
                    </div>
                    
                    <button type="submit" name="add_interview" class="btn-primary" style="width: 100%;">
                        <i class="fas fa-calendar-plus"></i> Schedule Interview
                    </button>
                </form>
            </div>
            
            <!-- Interview Type Distribution -->
            <div class="content-card">
                <div class="card-header">
                    <h3>Interview Type Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="interviewTypeChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Interview List -->
        <div class="content-card">
            <div class="card-header">
                <h3>Scheduled Interviews</h3>
                <div>
                    <select id="filterStatus" class="form-control" style="width: auto;" onchange="filterTable()">
                        <option value="">All Status</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="rescheduled">Rescheduled</option>
                    </select>
                </div>
            </div>
            
            <div class="table-container">
                <table class="data-table" id="interviewTable">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Position</th>
                            <th>Date & Time</th>
                            <th>Type</th>
                            <th>Interviewer</th>
                            <th>Status</th>
                            <th>Rating</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($interviews as $interview): 
                            $interview_date = new DateTime($interview['interview_date']);
                            $is_past = $interview_date < new DateTime();
                            $status_class = strtolower($interview['status']);
                        ?>
                        <tr data-status="<?php echo $status_class; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($interview['applicant_name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($interview['applicant_email']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($interview['position_applied']); ?></td>
                            <td>
                                <strong><?php echo $interview_date->format('M d, Y'); ?></strong><br>
                                <small><?php echo $interview_date->format('h:i A'); ?></small>
                                <?php if ($is_past && $interview['status'] == 'scheduled'): ?>
                                <br><span style="color: var(--danger); font-size: 11px;">(Overdue)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="text-transform: capitalize;">
                                    <?php echo htmlspecialchars($interview['interview_type']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($interview['interviewer_name']): ?>
                                <?php echo htmlspecialchars($interview['interviewer_name']); ?><br>
                                <small><?php echo htmlspecialchars($interview['interviewer_designation']); ?></small>
                                <?php else: ?>
                                <span style="color: var(--gray);">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo htmlspecialchars($interview['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($interview['rating']): ?>
                                <div class="rating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span class="star <?php echo $i <= $interview['rating'] ? 'filled' : ''; ?>">
                                        ★
                                    </span>
                                    <?php endfor; ?>
                                    <span style="margin-left: 5px; font-weight: 600;">
                                        <?php echo $interview['rating']; ?>/5
                                    </span>
                                </div>
                                <?php else: ?>
                                <span style="color: var(--gray);">Not rated</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <?php if ($interview['status'] == 'scheduled'): ?>
                                    <button class="btn-sm btn-edit" onclick="openUpdateModal(<?php echo $interview['id']; ?>, '<?php echo $interview['applicant_name']; ?>')">
                                        <i class="fas fa-edit"></i> Update
                                    </button>
                                    <?php endif; ?>
                                    <button class="btn-sm btn-delete" onclick="confirmDelete(<?php echo $interview['id']; ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Update Status Modal -->
    <div class="modal" id="updateModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Interview Status</h3>
                <button class="close-btn" onclick="closeUpdateModal()">&times;</button>
            </div>
            <form method="POST" action="" id="updateForm">
                <input type="hidden" name="interview_id" id="modalInterviewId">
                
                <div class="form-group">
                    <label for="status">Status *</label>
                    <select name="status" id="modalStatus" class="form-control" required onchange="toggleRating()">
                        <option value="scheduled">Scheduled</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="rescheduled">Rescheduled</option>
                    </select>
                </div>
                
                <div class="form-group" id="ratingGroup">
                    <label for="rating">Rating (1-5)</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="range" name="rating" id="modalRating" min="1" max="5" step="1" 
                               class="form-control" style="flex: 1;">
                        <span id="ratingValue" style="font-weight: 600; min-width: 50px;">3</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="feedback">Feedback</label>
                    <textarea name="feedback" id="modalFeedback" class="form-control" rows="4" 
                              placeholder="Enter interview feedback..."></textarea>
                </div>
                
                <button type="submit" name="update_status" class="btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> Update Status
                </button>
            </form>
        </div>
    </div>
    
    <script>
        // Initialize Interview Type Chart
        const typeCtx = document.getElementById('interviewTypeChart').getContext('2d');
        const typeLabels = <?php echo json_encode(array_column($interview_types, 'interview_type')); ?>;
        const typeData = <?php echo json_encode(array_column($interview_types, 'count')); ?>;
        
        const interviewTypeChart = new Chart(typeCtx, {
            type: 'pie',
            data: {
                labels: typeLabels.map(type => type.charAt(0).toUpperCase() + type.slice(1)),
                datasets: [{
                    data: typeData,
                    backgroundColor: [
                        '#4f46e5',
                        '#10b981',
                        '#f59e0b',
                        '#ec4899',
                        '#06b6d4'
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
                            usePointStyle: true
                        }
                    }
                }
            }
        });
        
        // Filter table by status
        function filterTable() {
            const filter = document.getElementById('filterStatus').value.toLowerCase();
            const rows = document.querySelectorAll('#interviewTable tbody tr');
            
            rows.forEach(row => {
                const status = row.getAttribute('data-status');
                if (!filter || status === filter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        // Open update modal
        function openUpdateModal(interviewId, applicantName) {
            document.getElementById('modalInterviewId').value = interviewId;
            document.getElementById('updateModal').classList.add('active');
            document.querySelector('#updateModal h3').textContent = `Update Interview - ${applicantName}`;
        }
        
        // Close update modal
        function closeUpdateModal() {
            document.getElementById('updateModal').classList.remove('active');
        }
        
        // Toggle rating field based on status
        function toggleRating() {
            const status = document.getElementById('modalStatus').value;
            const ratingGroup = document.getElementById('ratingGroup');
            
            if (status === 'completed') {
                ratingGroup.style.display = 'block';
            } else {
                ratingGroup.style.display = 'none';
            }
        }
        
        // Update rating value display
        document.getElementById('modalRating').addEventListener('input', function() {
            document.getElementById('ratingValue').textContent = this.value + '/5';
        });
        
        // Confirm delete
        function confirmDelete(interviewId) {
            if (confirm('Are you sure you want to delete this interview? This action cannot be undone.')) {
                window.location.href = 'interview_manage.php?delete=' + interviewId;
            }
        }
        
        // Set minimum date to today for date input
        document.getElementById('interview_date').min = new Date().toISOString().split('T')[0];
        
        // Set default time to next hour
        const now = new Date();
        const nextHour = new Date(now.getTime() + 60 * 60 * 1000);
        document.getElementById('interview_time').value = 
            nextHour.getHours().toString().padStart(2, '0') + ':00';
            
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('updateModal');
            if (event.target === modal) {
                closeUpdateModal();
            }
        });
        
        // Initialize toggleRating
        toggleRating();
    </script>
</body>
</html>

 ?>