<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set the timezone to your local timezone
date_default_timezone_set('Asia/Kolkata');

// Check if user is logged in and is employee
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'employee') {
    header("Location: login_emp.php");
    exit();
}

// Database connection
require_once "../../db_connection.php";

// Check database connection
if (!$conn) {
    die("Database connection failed. Please check your database configuration.");
}

// Function to check if date is holiday
function isHoliday($date, $conn) {
    $stmt = $conn->prepare("SELECT * FROM holidays WHERE holiday_date = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// Function to check if date is Sunday
function isSunday($date) {
    return date('w', strtotime($date)) == 0;
}

// Function to check if date is Saturday
function isSaturday($date) {
    return date('w', strtotime($date)) == 6;
}

// Function to get holiday name
function getHolidayName($date, $conn) {
    $stmt = $conn->prepare("SELECT name FROM holidays WHERE holiday_date = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['name'];
    }
    return null;
}

// Function to automatically mark absent for working days
function autoMarkAbsent($user_id, $date, $conn) {
    // Don't mark on Sundays or holidays
    if (isSunday($date) || isHoliday($date, $conn)) {
        return false;
    }
    
    // Check if attendance already exists
    $stmt = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND user_type = 'employee' AND date = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("is", $user_id, $date);
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        return false;
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        // Check if it's a working day (Monday to Saturday, except holidays)
        $dayOfWeek = date('w', strtotime($date));
        if ($dayOfWeek >= 1 && $dayOfWeek <= 6) { // Monday to Saturday
            // Check if it's a future date
            $today = date('Y-m-d');
            if ($date >= $today) {
                return false; // Don't mark absent for future dates
            }
            
            // Insert absent record
            $insert_stmt = $conn->prepare("
                INSERT INTO attendance (user_id, user_type, date, status, created_at, updated_at)
                VALUES (?, 'employee', ?, 'absent', NOW(), NOW())
                ON DUPLICATE KEY UPDATE status = 'absent', updated_at = NOW()
            ");
            
            if (!$insert_stmt) {
                error_log("Insert prepare failed: " . $conn->error);
                return false;
            }
            
            $insert_stmt->bind_param("is", $user_id, $date);
            return $insert_stmt->execute();
        }
    }
    return false;
}

// Fetch employee details
$stmt = $conn->prepare("SELECT e.*, s.start_time, s.end_time FROM employees e LEFT JOIN shifts s ON e.shift_id = s.id WHERE e.user_id = ?");
if (!$stmt) {
    die("Failed to prepare employee query: " . $conn->error);
}

$stmt->bind_param("i", $_SESSION['user_id']);
if (!$stmt->execute()) {
    die("Failed to execute employee query: " . $stmt->error);
}

$result = $stmt->get_result();
$employee = $result->fetch_assoc();

// Initialize employee data with defaults if null
if (!$employee) {
    $employee = [
        'first_name' => 'Employee',
        'last_name' => '',
        'designation' => 'Not assigned',
        'emp_id' => 'N/A',
        'shift_id' => null,
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'profile_picture' => null
    ];
}

// Set default values if null
$employee['first_name'] = $employee['first_name'] ?? 'Employee';
$employee['last_name'] = $employee['last_name'] ?? '';
$employee['designation'] = $employee['designation'] ?? 'Not assigned';
$employee['emp_id'] = $employee['emp_id'] ?? 'N/A';
$employee['shift_id'] = $employee['shift_id'] ?? null;
$employee['start_time'] = $employee['start_time'] ?? '09:00:00';
$employee['end_time'] = $employee['end_time'] ?? '18:00:00';
$employee['profile_picture'] = $employee['profile_picture'] ?? null;

// Get current month and year - Use GET parameters or default to current month
$current_month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$current_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Validate month range
if ($current_month < 1 || $current_month > 12) {
    $current_month = date('n');
}

// Calculate start and end date for the month
$start_date = date("$current_year-$current_month-01");
$end_date = date("$current_year-$current_month-t", strtotime($start_date));

// Auto-mark absent for past dates in current month
if (date('Y-m') == "$current_year-" . sprintf("%02d", $current_month)) {
    $today = date('Y-m-d');
    $start = new DateTime($start_date);
    $end = new DateTime($today);
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    
    foreach ($period as $date) {
        $date_str = $date->format('Y-m-d');
        autoMarkAbsent($_SESSION['user_id'], $date_str, $conn);
    }
}

// Fetch attendance for the month with all details
$attendance_stmt = $conn->prepare("
    SELECT a.*, h.name as holiday_name 
    FROM attendance a 
    LEFT JOIN holidays h ON a.date = h.holiday_date
    WHERE a.user_id = ? AND a.user_type = 'employee' 
    AND a.date BETWEEN ? AND ?
    ORDER BY a.date DESC
");

if (!$attendance_stmt) {
    die("Failed to prepare attendance query: " . $conn->error);
}

$attendance_stmt->bind_param("iss", $_SESSION['user_id'], $start_date, $end_date);
if (!$attendance_stmt->execute()) {
    die("Failed to execute attendance query: " . $attendance_stmt->error);
}

$attendance_result = $attendance_stmt->get_result();
$attendance_records = [];
$attendance_by_date = [];

while ($row = $attendance_result->fetch_assoc()) {
    $attendance_records[] = $row;
    $attendance_by_date[$row['date']] = $row;
}

// Debug: Check what dates are being fetched
error_log("Fetching attendance between: $start_date and $end_date");
error_log("Total records fetched: " . count($attendance_records));
foreach ($attendance_records as $record) {
    error_log("Attendance record: " . $record['date'] . " - " . $record['status']);
}

// Fetch holidays for the month
$holidays_stmt = $conn->prepare("SELECT holiday_date, name FROM holidays WHERE holiday_date BETWEEN ? AND ?");
if (!$holidays_stmt) {
    die("Failed to prepare holidays query: " . $conn->error);
}

$holidays_stmt->bind_param("ss", $start_date, $end_date);
if (!$holidays_stmt->execute()) {
    die("Failed to execute holidays query: " . $holidays_stmt->error);
}

$holidays_result = $holidays_stmt->get_result();
$holidays = [];
while ($row = $holidays_result->fetch_assoc()) {
    $holidays[$row['holiday_date']] = $row['name'];
}

// Calculate attendance statistics
$total_days = date('t', strtotime($start_date));
$present_days = 0;
$absent_days = 0;
$half_days = 0;
$holiday_days = 0;
$sunday_days = 0;
$saturday_working_days = 0;
$late_minutes_total = 0;
$overtime_minutes_total = 0;
$working_days = 0;

// Calculate statistics
for ($day = 1; $day <= $total_days; $day++) {
    $current_date = date("$current_year-$current_month-" . sprintf("%02d", $day));
    $day_of_week = date('w', strtotime($current_date));
    
    if ($day_of_week == 0) { // Sunday
        $sunday_days++;
    } elseif (isset($holidays[$current_date])) {
        $holiday_days++;
    } elseif ($day_of_week == 6) { // Saturday - Working day
        $saturday_working_days++;
        $working_days++;
    } else { // Monday to Friday
        $working_days++;
    }
    
    if (isset($attendance_by_date[$current_date])) {
        $record = $attendance_by_date[$current_date];
        if ($record['status'] === 'present') $present_days++;
        if ($record['status'] === 'absent') $absent_days++;
        if ($record['status'] === 'half_day') $half_days++;
        $late_minutes_total += $record['late_minutes'];
        $overtime_minutes_total += $record['overtime_minutes'];
    }
}

// Total working days including Saturdays
$total_working_days = $working_days;

// Fetch today's attendance
$today = date('Y-m-d');
$today_attendance_stmt = $conn->prepare("
    SELECT * FROM attendance 
    WHERE user_id = ? AND user_type = 'employee' AND date = ?
");

if (!$today_attendance_stmt) {
    die("Failed to prepare today's attendance query: " . $conn->error);
}

$today_attendance_stmt->bind_param("is", $_SESSION['user_id'], $today);
if (!$today_attendance_stmt->execute()) {
    die("Failed to execute today's attendance query: " . $today_attendance_stmt->error);
}

$today_attendance_result = $today_attendance_stmt->get_result();
$today_attendance = $today_attendance_result->fetch_assoc();

// Handle check-in/check-out
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['check_in'])) {
        // Don't allow check-in on Sundays or holidays
        // Saturday is allowed as working day
        if (isSunday($today)) {
            $_SESSION['error'] = "Cannot check-in on Sunday (Week Off)";
            header("Location: attendance_emp.php");
            exit();
        } elseif (isHoliday($today, $conn)) {
            $holiday_name = getHolidayName($today, $conn);
            $_SESSION['error'] = "Cannot check-in on holiday: " . $holiday_name;
            header("Location: attendance_emp.php");
            exit();
        }
        
        $current_time = date('H:i:s');
        $status = 'present';
        $late_minutes = 0;
        
        // Calculate late minutes if shift exists
        if (isset($employee['shift_id']) && isset($employee['start_time'])) {
            $shift_start = strtotime($employee['start_time']);
            $current_timestamp = strtotime($current_time);
            
            if ($current_timestamp > $shift_start + 900) { // 15 minutes grace period
                $late_minutes = floor(($current_timestamp - $shift_start) / 60) - 15;
                if ($late_minutes < 0) $late_minutes = 0;
            }
        }
        
        $insert_stmt = $conn->prepare("
            INSERT INTO attendance (user_id, user_type, date, shift_id, check_in, status, late_minutes, created_at, updated_at)
            VALUES (?, 'employee', CURDATE(), ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
            check_in = VALUES(check_in),
            status = VALUES(status),
            late_minutes = VALUES(late_minutes),
            updated_at = NOW()
        ");
        
        if (!$insert_stmt) {
            $_SESSION['error'] = "Database error. Please try again.";
            header("Location: attendance_emp.php");
            exit();
        }
        
        $shift_id = $employee['shift_id'] ?? null;
        $insert_stmt->bind_param("iissi", $_SESSION['user_id'], $shift_id, $current_time, $status, $late_minutes);
        
        if ($insert_stmt->execute()) {
            $_SESSION['success'] = "Checked in successfully at " . date('h:i A');
        } else {
            $_SESSION['error'] = "Failed to check in";
        }
        
        header("Location: attendance_emp.php");
        exit();
    }
    
    if (isset($_POST['check_out'])) {
        $current_time = date('H:i:s');
        $early_departure = 0;
        $overtime_minutes = 0;
        
        // Calculate early departure and overtime if shift exists
        if (isset($employee['shift_id']) && isset($employee['end_time'])) {
            $shift_end = strtotime($employee['end_time']);
            $current_timestamp = strtotime($current_time);
            
            // Calculate early departure
            if ($current_timestamp < $shift_end - 1800) { // 30 minutes before shift end
                $early_departure = floor(($shift_end - $current_timestamp) / 60);
            }
            
            // Calculate overtime (work beyond shift end + 30 minutes grace)
            if ($current_timestamp > $shift_end + 1800) { // 30 minutes after shift end
                $overtime_minutes = floor(($current_timestamp - $shift_end - 1800) / 60);
                if ($overtime_minutes < 0) $overtime_minutes = 0;
            }
        }
        
        $update_stmt = $conn->prepare("
            UPDATE attendance 
            SET check_out = ?, early_departure_minutes = ?, overtime_minutes = ?, updated_at = NOW()
            WHERE user_id = ? AND user_type = 'employee' AND date = CURDATE()
        ");
        
        if (!$update_stmt) {
            $_SESSION['error'] = "Database error. Please try again.";
            header("Location: attendance_emp.php");
            exit();
        }
        
        $update_stmt->bind_param("siii", $current_time, $early_departure, $overtime_minutes, $_SESSION['user_id']);
        
        if ($update_stmt->execute()) {
            $_SESSION['success'] = "Checked out successfully at " . date('h:i A');
        } else {
            $_SESSION['error'] = "Failed to check out";
        }
        
        header("Location: attendance_emp.php");
        exit();
    }
}

// Get months for dropdown
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - HR Portal</title>
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
            grid-template-columns: 1fr 2fr;
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

        /* Today's Attendance */
        .today-attendance {
            text-align: center;
        }

        .time-display {
            font-size: 36px;
            font-weight: 700;
            color: var(--dark);
            font-family: monospace;
            margin-bottom: 20px;
        }

        .attendance-status {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 600;
        }

        .status-present {
            background: #d1fae5;
            color: #065f46;
        }

        .status-absent {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-holiday {
            background: #ede9fe;
            color: #5b21b6;
        }

        .status-weekend {
            background: #e0f2fe;
            color: #0369a1;
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
            margin: 5px;
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(245, 158, 11, 0.3);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 20px;
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

        /* Calendar */
        .month-selector {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            background: #f9fafb;
            padding: 15px;
            border-radius: 10px;
        }

        .month-nav {
            display: flex;
            gap: 10px;
        }

        .nav-btn {
            background: white;
            border: 2px solid #e5e7eb;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .nav-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .calendar {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
        }

        .calendar-header {
            text-align: center;
            font-weight: 600;
            color: var(--dark);
            padding: 10px;
            background: #f3f4f6;
            border-radius: 8px;
        }

        .calendar-day {
            aspect-ratio: 1;
            padding: 8px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            position: relative;
            cursor: pointer;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .calendar-day:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .calendar-day.empty {
            border: none;
            background: transparent;
            cursor: default;
        }

        .calendar-day.empty:hover {
            transform: none;
            border-color: transparent;
            box-shadow: none;
        }

        .calendar-day.today {
            border-color: var(--primary);
            background: rgba(102, 126, 234, 0.1);
        }

        /* Status-specific styles */
        .calendar-day.present {
            background: #d1fae5;
            color: #065f46;
            border-color: #10b981;
        }

        .calendar-day.absent {
            background: #fee2e2;
            color: #991b1b;
            border-color: #ef4444;
        }

        .calendar-day.half_day {
            background: #fef3c7;
            color: #92400e;
            border-color: #f59e0b;
        }

        .calendar-day.holiday {
            background: #ede9fe;
            color: #5b21b6;
            border-color: #8b5cf6;
        }

        .calendar-day.sunday {
            background: #e0f2fe;
            color: #0369a1;
            border-color: #3b82f6;
        }

        .calendar-day.future {
            background: white;
            color: var(--dark);
            border-color: #e5e7eb;
        }

        .calendar-day.saturday_working {
            background: #f9fafb;
            color: #4b5563;
            border-color: #e5e7eb;
            border-style: dashed;
        }

        .calendar-day.saturday_working.present {
            background: #d1fae5;
            color: #065f46;
            border-color: #10b981;
            border-style: solid;
        }

        .calendar-day.saturday_working.absent {
            background: #fee2e2;
            color: #991b1b;
            border-color: #ef4444;
            border-style: solid;
        }

        .calendar-day.saturday_working.half_day {
            background: #fef3c7;
            color: #92400e;
            border-color: #f59e0b;
            border-style: solid;
        }

        .day-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            margin-bottom: 5px;
        }

        .day-number {
            font-size: 16px;
            font-weight: 700;
            min-width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .day-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-left: 4px;
        }

        .indicator-present {
            background: var(--success);
        }

        .indicator-absent {
            background: var(--danger);
        }

        .indicator-half {
            background: var(--warning);
        }

        .indicator-holiday {
            background: #8b5cf6;
        }

        .indicator-sunday {
            background: #3b82f6;
        }

        .indicator-future {
            background: #9ca3af;
        }

        .day-content {
            width: 100%;
            font-size: 11px;
            text-align: center;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            min-height: 24px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .day-time {
            font-size: 10px;
            color: var(--gray);
            margin-top: 2px;
            font-weight: 500;
            background: rgba(255, 255, 255, 0.7);
            padding: 1px 4px;
            border-radius: 3px;
        }

        .day-status {
            font-size: 9px;
            font-weight: 700;
            margin-top: 2px;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-present-badge {
            background: rgba(16, 185, 129, 0.2);
            color: #065f46;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-absent-badge {
            background: rgba(239, 68, 68, 0.2);
            color: #991b1b;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .status-half-badge {
            background: rgba(245, 158, 11, 0.2);
            color: #92400e;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .status-holiday-badge {
            background: rgba(139, 92, 246, 0.2);
            color: #5b21b6;
            border: 1px solid rgba(139, 92, 246, 0.3);
        }

        .status-sunday-badge {
            background: rgba(59, 130, 246, 0.2);
            color: #0369a1;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .status-future-badge {
            background: rgba(156, 163, 175, 0.2);
            color: #4b5563;
            border: 1px solid rgba(156, 163, 175, 0.3);
        }

        .day-late {
            font-size: 8px;
            color: #dc2626;
            font-weight: 600;
            margin-top: 1px;
            background: rgba(220, 38, 38, 0.1);
            padding: 1px 3px;
            border-radius: 2px;
        }

        .day-overtime {
            font-size: 8px;
            color: #059669;
            font-weight: 600;
            margin-top: 1px;
            background: rgba(5, 150, 105, 0.1);
            padding: 1px 3px;
            border-radius: 2px;
        }

        /* Attendance Table */
        .table-container {
            overflow-x: auto;
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
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-present {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }

        .badge-absent {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }

        .badge-half {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #f59e0b;
        }

        .badge-holiday {
            background: #ede9fe;
            color: #5b21b6;
            border: 1px solid #8b5cf6;
        }

        .badge-sunday {
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #3b82f6;
        }

        .badge-future {
            background: #f3f4f6;
            color: #6b7280;
            border: 1px solid #e5e7eb;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            color: var(--dark);
            cursor: pointer;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1001;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 600;
            color: var(--dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray);
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .modal-day-info {
            background: #f9fafb;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .day-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 15px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 12px;
            color: var(--gray);
            margin-bottom: 5px;
        }

        .detail-value {
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
        }

        /* Alert Messages */
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        /* Debug Styles */
        .debug-panel {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            font-family: monospace;
            font-size: 12px;
        }

        .debug-panel h4 {
            margin-bottom: 10px;
            color: #495057;
        }

        .debug-panel pre {
            background: #e9ecef;
            padding: 10px;
            border-radius: 3px;
            overflow: auto;
            max-height: 200px;
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
            
            .calendar {
                grid-template-columns: repeat(7, 1fr);
                gap: 5px;
            }
            
            .calendar-day {
                padding: 5px;
            }
            
            .day-number {
                font-size: 14px;
            }
            
            .day-content {
                font-size: 9px;
                -webkit-line-clamp: 2;
            }
            
            .month-selector {
                flex-direction: column;
                gap: 15px;
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
            <li><a href="attendance_emp.php" class="active"><i class="fas fa-calendar-alt"></i> Attendance</a></li>
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
                <h1>Attendance Management</h1>
                <p>Track your daily attendance and view history</p>
            </div>
            
            <div class="user-info">
                <img src="<?php echo !empty($employee['profile_picture']) ? htmlspecialchars($employee['profile_picture']) : 'https://ui-avatars.com/api/?name=' . urlencode($employee['first_name'] . ' ' . $employee['last_name']) . '&background=667eea&color=fff'; ?>" 
                     alt="Profile" class="profile-img">
                <div>
                    <h4><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h4>
                    <p style="color: var(--gray); font-size: 14px;"><?php echo htmlspecialchars($employee['designation']); ?></p>
                    <p style="color: var(--gray); font-size: 12px;">ID: <?php echo htmlspecialchars($employee['emp_id']); ?></p>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Debug Panel -->
        <!-- <div class="debug-panel">
            <h4>Debug Information:</h4>
            <p>User ID: <?php echo $_SESSION['user_id']; ?></p>
            <p>Month: <?php echo $current_month . ' (' . $months[$current_month] . ')'; ?></p>
            <p>Year: <?php echo $current_year; ?></p>
            <p>Date Range: <?php echo $start_date . ' to ' . $end_date; ?></p>
            <p>Total Attendance Records: <?php echo count($attendance_records); ?></p>
            <p>Today: <?php echo $today; ?> (<?php echo date('l', strtotime($today)); ?>)</p>
            
            Show attendance data for debugging -->
            <!-- <h4>Attendance Data for February 2026:</h4>
            <ul>
                <?php 
                // Show specific dates from your image
                $important_dates = ['2026-02-07', '2026-02-09', '2026-02-10'];
                foreach ($important_dates as $check_date) {
                    if (isset($attendance_by_date[$check_date])) {
                        echo "<li><strong>$check_date</strong>: PRESENT - Check In: " . $attendance_by_date[$check_date]['check_in'] . "</li>";
                    } else {
                        echo "<li><strong>$check_date</strong>: NOT FOUND in database</li>";
                    }
                }
                ?>
            </ul>
        </div> -->

        <div class="content">
            <!-- Today's Attendance Card -->
            <div class="card">
                <h3 class="card-title">Today's Attendance</h3>
                
                <div class="today-attendance">
                    <div class="time-display" id="liveClock"><?php echo date('h:i:s A'); ?></div>
                    
                    <?php 
                    // Check if today is Sunday or holiday
                    if (isSunday($today)): ?>
                        <div class="attendance-status status-weekend">
                            <i class="fas fa-sun"></i> Today is Sunday (Week Off)
                        </div>
                        <p style="color: var(--gray); margin-top: 20px;">
                            <i class="fas fa-info-circle"></i> No attendance marking on Sundays
                        </p>
                    <?php elseif (isHoliday($today, $conn)): 
                        $holiday_name = getHolidayName($today, $conn);
                    ?>
                        <div class="attendance-status status-holiday">
                            <i class="fas fa-calendar-star"></i> Today is Holiday: <?php echo htmlspecialchars($holiday_name ?? 'Holiday'); ?>
                        </div>
                        <p style="color: var(--gray); margin-top: 20px;">
                            <i class="fas fa-info-circle"></i> No attendance marking on holidays
                        </p>
                    <?php elseif ($today_attendance): ?>
                        <div class="attendance-status status-present">
                            <i class="fas fa-check-circle"></i> 
                            <?php 
                            if ($today_attendance['check_out']) {
                                echo 'Checked Out at ' . date('h:i A', strtotime($today_attendance['check_out']));
                            } else {
                                echo 'Checked In at ' . date('h:i A', strtotime($today_attendance['check_in']));
                            }
                            ?>
                            <?php if ($today_attendance['late_minutes'] > 0): ?>
                                <br><small>Late: <?php echo $today_attendance['late_minutes']; ?> minutes</small>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!$today_attendance['check_out']): ?>
                            <form method="POST" style="margin-top: 20px;">
                                <button type="submit" name="check_out" class="btn btn-warning">
                                    <i class="fas fa-sign-out-alt"></i> Check Out
                                </button>
                            </form>
                        <?php else: ?>
                            <p style="color: var(--gray); margin-top: 20px;">
                                <i class="fas fa-check"></i> Attendance completed for today
                            </p>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="attendance-status status-absent">
                            <i class="fas fa-times-circle"></i> Not Checked In
                        </div>
                        
                        <form method="POST" style="margin-top: 20px;">
                            <button type="submit" name="check_in" class="btn btn-success">
                                <i class="fas fa-sign-in-alt"></i> Check In Now
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $present_days; ?></div>
                        <div class="stat-label">Present Days</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $absent_days; ?></div>
                        <div class="stat-label">Absent Days</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $half_days; ?></div>
                        <div class="stat-label">Half Days</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $total_working_days; ?></div>
                        <div class="stat-label">Working Days</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $holiday_days; ?></div>
                        <div class="stat-label">Holidays</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo $sunday_days; ?></div>
                        <div class="stat-label">Sundays Off</div>
                    </div>
                </div>
            </div>

            <!-- Attendance Calendar Card -->
           
        <!-- Attendance History Card -->
        <div class="card" style="margin-top: 25px;">
            <h3 class="card-title">Attendance History - <?php echo $months[$current_month] . ' ' . $current_year; ?></h3>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Status</th>
                            <th>Late (mins)</th>
                            <th>Early Departure</th>
                            <th>Overtime</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($attendance_records) > 0): ?>
                            <?php foreach ($attendance_records as $record): 
                                $date = $record['date'];
                                $is_sunday = isSunday($date);
                                $is_saturday = isSaturday($date);
                                $is_holiday = isset($holidays[$date]);
                                $is_future = ($date > $today);
                            ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($record['date'])); ?></td>
                                    <td><?php echo date('D', strtotime($record['date'])); ?></td>
                                    <td>
                                        <?php if ($is_sunday || $is_holiday || $is_future): ?>
                                            --
                                        <?php else: ?>
                                            <?php echo $record['check_in'] ? date('h:i A', strtotime($record['check_in'])) : '--'; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_sunday || $is_holiday || $is_future): ?>
                                            --
                                        <?php else: ?>
                                            <?php echo $record['check_out'] ? date('h:i A', strtotime($record['check_out'])) : '--'; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $badge_class = '';
                                        $status_text = $record['status'];
                                        
                                        if ($is_sunday) {
                                            $badge_class = 'badge-sunday';
                                            $status_text = 'Sunday';
                                        } elseif ($is_holiday) {
                                            $badge_class = 'badge-holiday';
                                            $status_text = 'Holiday';
                                        } elseif ($is_future) {
                                            $badge_class = 'badge-future';
                                            $status_text = 'Future';
                                        } else {
                                            switch($record['status']) {
                                                case 'present': $badge_class = 'badge-present'; $status_text = 'Present'; break;
                                                case 'absent': $badge_class = 'badge-absent'; $status_text = 'Absent'; break;
                                                case 'half_day': $badge_class = 'badge-half'; $status_text = 'Half Day'; break;
                                                default: $badge_class = 'badge-future'; $status_text = 'No Record';
                                            }
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $badge_class; ?>">
                                            <?php echo htmlspecialchars($status_text); ?>
                                        </span>
                                    </td>
                                    <td><?php echo ($record['late_minutes'] > 0 && !$is_sunday && !$is_holiday && !$is_future) ? $record['late_minutes'] : '--'; ?></td>
                                    <td><?php echo ($record['early_departure_minutes'] > 0 && !$is_sunday && !$is_holiday && !$is_future) ? $record['early_departure_minutes'] : '--'; ?></td>
                                    <td><?php echo ($record['overtime_minutes'] > 0 && !$is_sunday && !$is_holiday && !$is_future) ? $record['overtime_minutes'] : '--'; ?></td>
                                    <td>
                                        <?php if ($is_sunday): ?>
                                            Sunday
                                        <?php elseif ($is_saturday): ?>
                                            Saturday (Working Day)
                                        <?php elseif ($is_holiday): ?>
                                            <?php echo htmlspecialchars($record['holiday_name'] ?? 'Holiday'); ?>
                                        <?php elseif ($is_future): ?>
                                            Future Date
                                        <?php elseif (!empty($record['remarks'])): ?>
                                            <?php echo htmlspecialchars($record['remarks']); ?>
                                        <?php else: ?>
                                            --
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 40px; color: var(--gray);">
                                    <i class="fas fa-calendar-times" style="font-size: 48px; margin-bottom: 15px;"></i>
                                    <p>No attendance records found for this month</p>
                                    <p style="font-size: 12px; margin-top: 10px;">
                                        Try checking in today to create your first attendance record!
                                    </p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Day Details Modal -->
    <div class="modal" id="dayDetailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Attendance Details</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-day-info">
                    <h4 id="modalDate"></h4>
                    <div class="day-details" id="modalDetails"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Live Clock - Updates every second with REAL TIME
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
        updateClock(); // Initialize immediately
        setInterval(updateClock, 1000); // Update every second
        
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
        
        // Show day details modal
        function showDayDetails(element) {
            const date = element.getAttribute('data-date');
            const day = element.getAttribute('data-day');
            const month = element.getAttribute('data-month');
            const year = element.getAttribute('data-year');
            const status = element.getAttribute('data-status');
            const holiday = element.getAttribute('data-holiday');
            const checkIn = element.getAttribute('data-checkin');
            const checkOut = element.getAttribute('data-checkout');
            const late = element.getAttribute('data-late');
            const overtime = element.getAttribute('data-overtime');
            const isHoliday = element.getAttribute('data-is-holiday') === '1';
            const isSunday = element.getAttribute('data-is-sunday') === '1';
            const isSaturday = element.getAttribute('data-is-saturday') === '1';
            const isFuture = element.getAttribute('data-is-future') === '1';
            
            // Format date
            const dateObj = new Date(year, month - 1, day);
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const formattedDate = dateObj.toLocaleDateString('en-US', options);
            
            // Update modal content
            document.getElementById('modalDate').textContent = formattedDate;
            
            let detailsHTML = '';
            
            if (isSunday) {
                detailsHTML = `
                    <div class="detail-item">
                        <span class="detail-label">Type</span>
                        <span class="detail-value" style="color: #3b82f6;">Sunday (Week Off)</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <span class="detail-value">Week Off</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Attendance</span>
                        <span class="detail-value">Not Required</span>
                    </div>
                `;
            } else if (isHoliday && holiday) {
                detailsHTML = `
                    <div class="detail-item">
                        <span class="detail-label">Type</span>
                        <span class="detail-value" style="color: #5b21b6;">Holiday</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Holiday Name</span>
                        <span class="detail-value">${holiday}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <span class="detail-value">Holiday</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Attendance</span>
                        <span class="detail-value">Not Required</span>
                    </div>
                `;
            } else if (isFuture) {
                detailsHTML = `
                    <div class="detail-item">
                        <span class="detail-label">Date</span>
                        <span class="detail-value">Future Date</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <span class="detail-value">Not Yet Recorded</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Attendance</span>
                        <span class="detail-value">Will be recorded on this date</span>
                    </div>
                `;
            } else {
                // Show actual attendance status from database
                let statusText = '';
                let statusColor = '';
                let dayType = isSaturday ? 'Saturday (Working Day)' : 'Working Day';
                
                switch(status) {
                    case 'present':
                        statusText = 'Present';
                        statusColor = '#065f46';
                        break;
                    case 'absent':
                        statusText = 'Absent';
                        statusColor = '#991b1b';
                        break;
                    case 'half_day':
                        statusText = 'Half Day';
                        statusColor = '#92400e';
                        break;
                    case 'sunday':
                        statusText = 'Sunday';
                        statusColor = '#3b82f6';
                        dayType = 'Week Off';
                        break;
                    case 'holiday':
                        statusText = 'Holiday';
                        statusColor = '#5b21b6';
                        dayType = 'Holiday';
                        break;
                    default:
                        statusText = 'Absent';
                        statusColor = '#991b1b';
                }
                
                const checkInTime = checkIn ? formatTime(checkIn) : '--';
                const checkOutTime = checkOut ? formatTime(checkOut) : '--';
                const lateText = late > 0 ? `${late} minutes` : 'On Time';
                const overtimeText = overtime > 0 ? `${overtime} minutes` : '--';
                
                detailsHTML = `
                    <div class="detail-item">
                        <span class="detail-label">Day Type</span>
                        <span class="detail-value">${dayType}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <span class="detail-value" style="color: ${statusColor};">${statusText}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Check In</span>
                        <span class="detail-value">${checkInTime}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Check Out</span>
                        <span class="detail-value">${checkOutTime}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Late Arrival</span>
                        <span class="detail-value">${lateText}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Overtime</span>
                        <span class="detail-value">${overtimeText}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Working Hours</span>
                        <span class="detail-value">${calculateWorkingHours(checkIn, checkOut)}</span>
                    </div>
                `;
            }
            
            document.getElementById('modalDetails').innerHTML = detailsHTML;
            document.getElementById('dayDetailsModal').style.display = 'flex';
        }
        
        // Format time from HH:MM:SS to hh:MM AM/PM
        function formatTime(timeStr) {
            if (!timeStr) return '--';
            const [hours, minutes] = timeStr.split(':');
            const hour = parseInt(hours);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const displayHour = hour % 12 || 12;
            return `${displayHour}:${minutes} ${ampm}`;
        }
        
        // Calculate working hours
        function calculateWorkingHours(checkIn, checkOut) {
            if (!checkIn || !checkOut) return '--';
            
            const start = new Date(`2000-01-01T${checkIn}`);
            const end = new Date(`2000-01-01T${checkOut}`);
            
            const diffMs = end - start;
            const diffHrs = Math.floor(diffMs / (1000 * 60 * 60));
            const diffMins = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
            
            return `${diffHrs}h ${diffMins}m`;
        }
        
        // Close modal
        function closeModal() {
            document.getElementById('dayDetailsModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        document.getElementById('dayDetailsModal').addEventListener('click', (e) => {
            if (e.target.id === 'dayDetailsModal') {
                closeModal();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
        
        // Highlight today's date in calendar
        document.addEventListener('DOMContentLoaded', () => {
            const today = new Date();
            const todayStr = today.toISOString().split('T')[0];
            const todayElement = document.querySelector(`.calendar-day[data-date="${todayStr}"]`);
            if (todayElement) {
                todayElement.style.boxShadow = '0 0 0 3px rgba(102, 126, 234, 0.3)';
                todayElement.style.transform = 'scale(1.05)';
            }
        });
        
        // Add hover effects for calendar days
        document.querySelectorAll('.calendar-day:not(.empty)').forEach(day => {
            day.addEventListener('mouseenter', function() {
                if (!this.classList.contains('today')) {
                    this.style.transform = 'translateY(-3px) scale(1.03)';
                }
            });
            
            day.addEventListener('mouseleave', function() {
                if (!this.classList.contains('today')) {
                    this.style.transform = 'translateY(0) scale(1)';
                }
            });
        });
    </script>
</body>
</html>