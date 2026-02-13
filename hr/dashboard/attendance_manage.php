<?php
// attendance_manage.php - Complete Attendance Management System with Bulk Upload

// Start output buffering to prevent header errors
ob_start();

session_start();

// Include sidebar component - check if it outputs anything
require_once 'sidebar_hr.php';

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'emp_system');

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
    header('Location: hr_dashboard.php');
    exit();
}

// Get HR details
$hr_id = $_SESSION['hr_id'] ?? 1;
$hr_email = $_SESSION['hr_email'] ?? 'hr@hrportal.com';

// Get HR name from database
$hr_name = 'HR Manager';
$hr_user_id = $_SESSION['user_id'] ?? 2;
$hr_sql = "SELECT first_name, last_name FROM employees WHERE user_id = ?";
$hr_stmt = $conn->prepare($hr_sql);
if ($hr_stmt) {
    $hr_stmt->bind_param("i", $hr_user_id);
    $hr_stmt->execute();
    $hr_result = $hr_stmt->get_result();
    if ($hr_row = $hr_result->fetch_assoc()) {
        $hr_name = $hr_row['first_name'] . ' ' . $hr_row['last_name'];
    }
    $hr_stmt->close();
}

// =========================
// BULK ATTENDANCE UPLOAD PROCESSING
// =========================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_upload_attendance'])) {
    
    // Check if file was uploaded
    if (isset($_FILES['attendance_file']) && $_FILES['attendance_file']['error'] == 0) {
        
        $file = $_FILES['attendance_file'];
        $file_name = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Allowed file extensions
        $allowed_ext = ['xlsx', 'xls', 'csv'];
        
        if (in_array($file_ext, $allowed_ext)) {
            
            // Check if PhpSpreadsheet is installed
            if (!file_exists('../../vendor/autoload.php')) {
                $_SESSION['bulk_error_message'] = "PhpSpreadsheet is not installed. Please run 'composer require phpoffice/phpspreadsheet'";
            } else {
                // Load PhpSpreadsheet
                require_once '../../vendor/autoload.php';
                
                try {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_tmp);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rows = $worksheet->toArray();
                    
                    // Remove header row
                    $header = array_shift($rows);
                    
                    $success_count = 0;
                    $error_count = 0;
                    $errors = [];
                    $processed_records = [];
                    
                    // Get attendance rules for calculations
                    $rules_query = "SELECT rule_type, value FROM attendance_rules";
                    $rules_result = $conn->query($rules_query);
                    $rules = [];
                    if ($rules_result) {
                        while ($rule = $rules_result->fetch_assoc()) {
                            $rules[$rule['rule_type']] = $rule['value'];
                        }
                    }
                    
                    $late_threshold = isset($rules['late_threshold']) ? $rules['late_threshold'] : 15;
                    $overtime_threshold = isset($rules['overtime_threshold']) ? $rules['overtime_threshold'] : 480;
                    
                    // Process each row
                    foreach ($rows as $index => $row) {
                        $row_num = $index + 2; // Excel row number
                        
                        // Skip empty rows
                        if (empty(array_filter($row))) {
                            continue;
                        }
                        
                        $user_identifier = trim($row[0] ?? ''); // USER ID (EMP001, INT001, TRN001)
                        $date = trim($row[1] ?? '');
                        $check_in = !empty(trim($row[2] ?? '')) ? trim($row[2]) : null;
                        $check_out = !empty(trim($row[3] ?? '')) ? trim($row[3]) : null;
                        $status = strtolower(trim($row[4] ?? ''));
                        $remarks = trim($row[5] ?? '');
                        
                        // Validate required fields
                        if (empty($user_identifier) || empty($date) || empty($status)) {
                            $errors[] = "Row $row_num: Missing required fields (User ID, Date, or Status)";
                            $error_count++;
                            continue;
                        }
                        
                        // Validate date format
                        if (!preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $date)) {
                            $errors[] = "Row $row_num: Invalid date format. Use YYYY-MM-DD";
                            $error_count++;
                            continue;
                        }
                        
                        // Validate status
                        $valid_statuses = ['present', 'absent', 'half_day', 'holiday', 'week_off'];
                        if (!in_array($status, $valid_statuses)) {
                            $errors[] = "Row $row_num: Invalid status '$status'. Must be: " . implode(', ', $valid_statuses);
                            $error_count++;
                            continue;
                        }
                        
                        // Check if date is not in future
                        if (strtotime($date) > strtotime('today')) {
                            $errors[] = "Row $row_num: Cannot mark attendance for future dates";
                            $error_count++;
                            continue;
                        }
                        
                        // Determine user type and get user_id
                        $user_id = null;
                        $user_type = null;
                        $shift_id = null;
                        
                        if (strpos($user_identifier, 'EMP') === 0) {
                            // Employee
                            $emp_sql = "SELECT user_id, shift_id FROM employees WHERE emp_id = ? AND employment_status = 'active'";
                            $emp_stmt = $conn->prepare($emp_sql);
                            if ($emp_stmt) {
                                $emp_stmt->bind_param("s", $user_identifier);
                                $emp_stmt->execute();
                                $emp_result = $emp_stmt->get_result();
                                if ($emp_row = $emp_result->fetch_assoc()) {
                                    $user_id = $emp_row['user_id'];
                                    $shift_id = $emp_row['shift_id'];
                                    $user_type = 'employee';
                                }
                                $emp_stmt->close();
                            }
                        } elseif (strpos($user_identifier, 'INT') === 0) {
                            // Intern
                            $intern_sql = "SELECT user_id, shift_id FROM interns WHERE intern_id = ? AND internship_status = 'active'";
                            $intern_stmt = $conn->prepare($intern_sql);
                            if ($intern_stmt) {
                                $intern_stmt->bind_param("s", $user_identifier);
                                $intern_stmt->execute();
                                $intern_result = $intern_stmt->get_result();
                                if ($intern_row = $intern_result->fetch_assoc()) {
                                    $user_id = $intern_row['user_id'];
                                    $shift_id = $intern_row['shift_id'];
                                    $user_type = 'intern';
                                }
                                $intern_stmt->close();
                            }
                        } elseif (strpos($user_identifier, 'TRN') === 0) {
                            // Trainer
                            $trainer_sql = "SELECT user_id FROM trainers WHERE trainer_id = ? AND employment_status = 'active'";
                            $trainer_stmt = $conn->prepare($trainer_sql);
                            if ($trainer_stmt) {
                                $trainer_stmt->bind_param("s", $user_identifier);
                                $trainer_stmt->execute();
                                $trainer_result = $trainer_stmt->get_result();
                                if ($trainer_row = $trainer_result->fetch_assoc()) {
                                    $user_id = $trainer_row['user_id'];
                                    $user_type = 'trainer';
                                }
                                $trainer_stmt->close();
                            }
                        }
                        
                        if (!$user_id || !$user_type) {
                            $errors[] = "Row $row_num: User ID '$user_identifier' not found or inactive in database";
                            $error_count++;
                            continue;
                        }
                        
                        // Calculate late minutes and overtime based on shift
                        $late_minutes = 0;
                        $overtime_minutes = 0;
                        $early_departure_minutes = 0;
                        
                        if ($check_in && $shift_id && $user_type == 'employee') {
                            // Get shift timings
                            $shift_time_sql = "SELECT start_time, end_time FROM shifts WHERE id = ?";
                            $shift_time_stmt = $conn->prepare($shift_time_sql);
                            if ($shift_time_stmt) {
                                $shift_time_stmt->bind_param("i", $shift_id);
                                $shift_time_stmt->execute();
                                $shift_time_result = $shift_time_stmt->get_result();
                                
                                if ($shift_time_row = $shift_time_result->fetch_assoc()) {
                                    $shift_start = $shift_time_row['start_time'];
                                    $shift_end = $shift_time_row['end_time'];
                                    
                                    // Calculate late minutes
                                    $check_in_time = strtotime($check_in);
                                    $shift_start_time = strtotime($shift_start);
                                    
                                    if ($check_in_time > $shift_start_time) {
                                        $late_minutes = floor(($check_in_time - $shift_start_time) / 60);
                                        // Only count if exceeds threshold
                                        if ($late_minutes < $late_threshold) {
                                            $late_minutes = 0;
                                        }
                                    }
                                    
                                    // Calculate overtime and early departure
                                    if ($check_out) {
                                        $check_out_time = strtotime($check_out);
                                        $shift_end_time = strtotime($shift_end);
                                        
                                        // Handle overnight shifts
                                        if ($shift_end_time < $shift_start_time) {
                                            $shift_end_time += 24 * 3600;
                                            if ($check_out_time < $shift_start_time) {
                                                $check_out_time += 24 * 3600;
                                            }
                                        }
                                        
                                        if ($check_out_time > $shift_end_time) {
                                            $overtime_minutes = floor(($check_out_time - $shift_end_time) / 60);
                                            // Only count if exceeds threshold
                                            if ($overtime_minutes < $overtime_threshold) {
                                                $overtime_minutes = 0;
                                            }
                                        }
                                        
                                        if ($check_out_time < $shift_end_time) {
                                            $early_departure_minutes = floor(($shift_end_time - $check_out_time) / 60);
                                        }
                                    }
                                }
                                $shift_time_stmt->close();
                            }
                        }
                        
                        // Check if attendance already exists
                        $check_sql = "SELECT id FROM attendance WHERE user_id = ? AND user_type = ? AND date = ?";
                        $check_stmt = $conn->prepare($check_sql);
                        if ($check_stmt) {
                            $check_stmt->bind_param("iss", $user_id, $user_type, $date);
                            $check_stmt->execute();
                            $check_result = $check_stmt->get_result();
                            
                            if ($check_result->num_rows > 0) {
                                // Update existing attendance
                                $row_data = $check_result->fetch_assoc();
                                $attendance_id = $row_data['id'];
                                
                                $update_sql = "UPDATE attendance SET 
                                              status = ?, 
                                              check_in = ?, 
                                              check_out = ?, 
                                              shift_id = ?, 
                                              late_minutes = ?, 
                                              early_departure_minutes = ?, 
                                              overtime_minutes = ?, 
                                              remarks = ?,
                                              updated_at = NOW()
                                              WHERE id = ?";
                                
                                $update_stmt = $conn->prepare($update_sql);
                                if ($update_stmt) {
                                    $update_stmt->bind_param("sssiiissi", 
                                        $status, 
                                        $check_in, 
                                        $check_out, 
                                        $shift_id, 
                                        $late_minutes, 
                                        $early_departure_minutes, 
                                        $overtime_minutes, 
                                        $remarks, 
                                        $attendance_id
                                    );
                                    
                                    if ($update_stmt->execute()) {
                                        $success_count++;
                                        $processed_records[] = "Updated: $user_identifier - $date - $status";
                                        
                                        // Create notification for the user
                                        $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_entity, related_entity_id) 
                                                           VALUES (?, 'Attendance Updated (Bulk)', 'Your attendance for $date has been updated via bulk upload. Status: " . ucfirst($status) . "', 'info', 'attendance', ?)";
                                        $notification_stmt = $conn->prepare($notification_sql);
                                        if ($notification_stmt) {
                                            $notification_stmt->bind_param("ii", $user_id, $attendance_id);
                                            $notification_stmt->execute();
                                            $notification_stmt->close();
                                        }
                                        
                                        // Log the action
                                        $audit_sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at) 
                                                     VALUES (?, 'bulk_update_attendance', 'attendance', ?, ?, ?, NOW())";
                                        $audit_stmt = $conn->prepare($audit_sql);
                                        if ($audit_stmt) {
                                            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                                            $audit_stmt->bind_param("iiss", $hr_user_id, $attendance_id, $_SERVER['REMOTE_ADDR'], $user_agent);
                                            $audit_stmt->execute();
                                            $audit_stmt->close();
                                        }
                                    } else {
                                        $errors[] = "Row $row_num: Error updating attendance - " . $conn->error;
                                        $error_count++;
                                    }
                                    $update_stmt->close();
                                }
                            } else {
                                // Insert new attendance
                                $insert_sql = "INSERT INTO attendance (user_id, user_type, date, status, check_in, check_out, shift_id, late_minutes, early_departure_minutes, overtime_minutes, remarks, created_at) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                                
                                $insert_stmt = $conn->prepare($insert_sql);
                                if ($insert_stmt) {
                                    $insert_stmt->bind_param("isssssiiiss", 
                                        $user_id, 
                                        $user_type, 
                                        $date, 
                                        $status, 
                                        $check_in, 
                                        $check_out, 
                                        $shift_id, 
                                        $late_minutes, 
                                        $early_departure_minutes, 
                                        $overtime_minutes, 
                                        $remarks
                                    );
                                    
                                    if ($insert_stmt->execute()) {
                                        $attendance_id = $insert_stmt->insert_id;
                                        $success_count++;
                                        $processed_records[] = "Added: $user_identifier - $date - $status";
                                        
                                        // Create notification for the user
                                        $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_entity, related_entity_id) 
                                                           VALUES (?, 'Attendance Marked (Bulk)', 'Your attendance for $date has been marked via bulk upload. Status: " . ucfirst($status) . "', 'success', 'attendance', ?)";
                                        $notification_stmt = $conn->prepare($notification_sql);
                                        if ($notification_stmt) {
                                            $notification_stmt->bind_param("ii", $user_id, $attendance_id);
                                            $notification_stmt->execute();
                                            $notification_stmt->close();
                                        }
                                        
                                        // Log the action
                                        $audit_sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at) 
                                                     VALUES (?, 'bulk_create_attendance', 'attendance', ?, ?, ?, NOW())";
                                        $audit_stmt = $conn->prepare($audit_sql);
                                        if ($audit_stmt) {
                                            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                                            $audit_stmt->bind_param("iiss", $hr_user_id, $attendance_id, $_SERVER['REMOTE_ADDR'], $user_agent);
                                            $audit_stmt->execute();
                                            $audit_stmt->close();
                                        }
                                    } else {
                                        $errors[] = "Row $row_num: Error inserting attendance - " . $conn->error;
                                        $error_count++;
                                    }
                                    $insert_stmt->close();
                                }
                            }
                            $check_stmt->close();
                        }
                    }
                    
                    // Update attendance summary after bulk upload
                    if ($success_count > 0) {
                        $update_summary = $conn->prepare("
                            INSERT INTO attendance_summary 
                            (user_id, user_type, summary_month, total_present, total_absent, total_half_days, total_late_days, total_overtime_minutes, created_at, updated_at)
                            SELECT 
                                user_id,
                                user_type,
                                DATE_FORMAT(date, '%Y-%m-01') as summary_month,
                                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as total_present,
                                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as total_absent,
                                SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as total_half_days,
                                SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as total_late_days,
                                SUM(overtime_minutes) as total_overtime_minutes,
                                NOW(),
                                NOW()
                            FROM attendance
                            WHERE date >= DATE_FORMAT(NOW(), '%Y-%m-01')
                            GROUP BY user_id, user_type, DATE_FORMAT(date, '%Y-%m-01')
                            ON DUPLICATE KEY UPDATE
                                total_present = VALUES(total_present),
                                total_absent = VALUES(total_absent),
                                total_half_days = VALUES(total_half_days),
                                total_late_days = VALUES(total_late_days),
                                total_overtime_minutes = VALUES(total_overtime_minutes),
                                updated_at = NOW()
                        ");
                        if ($update_summary) {
                            $update_summary->execute();
                            $update_summary->close();
                        }
                    }
                    
                    if ($success_count > 0) {
                        $_SESSION['bulk_success_message'] = "Successfully processed $success_count attendance records. Failed: $error_count";
                        $_SESSION['bulk_processed_records'] = $processed_records;
                        $_SESSION['bulk_errors'] = $errors;
                    } else {
                        $_SESSION['bulk_error_message'] = "Failed to process any records. Please check the errors below.";
                        $_SESSION['bulk_errors'] = $errors;
                    }
                    
                } catch (Exception $e) {
                    $_SESSION['bulk_error_message'] = "Error processing file: " . $e->getMessage();
                }
            }
        } else {
            $_SESSION['bulk_error_message'] = "Invalid file type. Please upload .xlsx, .xls, or .csv files only.";
        }
    } else {
        $error_code = $_FILES['attendance_file']['error'] ?? 'Unknown';
        $_SESSION['bulk_error_message'] = "Please select a file to upload. Error code: $error_code";
    }
    
    // Clear output buffer before redirect
    ob_clean();
    
    // Redirect to avoid form resubmission
    header('Location: attendance_manage.php');
    exit();
}

// =========================
// EXPORT ATTENDANCE TO EXCEL
// =========================
if (isset($_GET['export_attendance'])) {
    $monthInput = $_GET['month'] ?? date('Y-m');
    [$year, $month] = explode('-', $monthInput);

    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

    // Clear output buffer before sending headers
    ob_clean();
    
    // Set headers for Excel
    header('Content-Type: application/vnd.ms-excel');
    header("Content-Disposition: attachment; filename=Attendance_Report_$month-$year.xls");
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Start HTML table for Excel
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }';
    echo 'th { background-color: #4f46e5; color: white; border: 1px solid #ddd; padding: 8px; text-align: center; font-weight: bold; font-size: 12px; }';
    echo 'td { border: 1px solid #ddd; padding: 8px; text-align: center; font-size: 11px; }';
    echo '.header-row { background-color: #4f46e5; color: white; font-weight: bold; }';
    echo '.summary-row { background-color: #e8f4fd; }';
    echo '.total-row { background-color: #d4edda; font-weight: bold; }';
    echo '.title { text-align: center; font-size: 20px; font-weight: bold; padding: 20px; color: #1e293b; }';
    echo '.subtitle { text-align: center; font-size: 16px; padding: 10px; color: #475569; }';
    echo '.legend { background-color: #f8fafc; padding: 10px; border: 1px solid #e2e8f0; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';

    echo '<div class="title">HR MANAGEMENT PORTAL</div>';
    echo '<div class="subtitle">MONTHLY ATTENDANCE REPORT</div>';
    echo '<div class="subtitle">Month: ' . date('F', mktime(0,0,0,$month,1)) . ' ' . $year . '</div>';
    echo '<br>';
    
    echo '<table border="1">';
    
    echo '<tr class="header-row">';
    echo '<th rowspan="2">S.No.</th>';
    echo '<th rowspan="2">ID</th>';
    echo '<th rowspan="2">Employee Name</th>';
    echo '<th rowspan="2">User Type</th>';
    echo '<th colspan="' . $daysInMonth . '">DAILY ATTENDANCE</th>';
    echo '<th colspan="7">SUMMARY</th>';
    echo '</tr>';
    
    echo '<tr class="header-row">';
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateObj = DateTime::createFromFormat('Y-m-d', "$year-$month-$d");
        $dayName = $dateObj->format('D');
        echo "<th>$d<br>$dayName</th>";
    }
    
    echo '<th>Present</th>';
    echo '<th>Absent</th>';
    echo '<th>Half Day</th>';
    echo '<th>Holiday</th>';
    echo '<th>Week Off</th>';
    echo '<th>Late<br>(Hrs)</th>';
    echo '<th>OT<br>(Hrs)</th>';
    echo '</tr>';

    // GET ALL ACTIVE USERS
    $users = [];
    
    // Get active employees
    $emp_sql = "SELECT u.id as user_id, 
                       e.emp_id,
                       CONCAT(e.first_name, ' ', e.last_name) as name,
                       'Employee' as user_type,
                       e.emp_id as identifier
                FROM users u 
                JOIN employees e ON u.id = e.user_id 
                WHERE u.role = 'employee' AND u.is_active = 1 
                AND e.employment_status = 'active'
                ORDER BY e.first_name";
    
    $emp_result = $conn->query($emp_sql);
    if ($emp_result) {
        while ($row = $emp_result->fetch_assoc()) {
            $users[] = $row;
        }
    }

    // Get active interns
    $intern_sql = "SELECT u.id as user_id,
                          i.intern_id,
                          CONCAT(i.first_name, ' ', i.last_name) as name,
                          'Intern' as user_type,
                          i.intern_id as identifier
                   FROM users u 
                   JOIN interns i ON u.id = i.user_id 
                   WHERE u.role = 'intern' AND u.is_active = 1 
                   AND i.internship_status = 'active'
                   ORDER BY i.first_name";
    
    $intern_result = $conn->query($intern_sql);
    if ($intern_result) {
        while ($row = $intern_result->fetch_assoc()) {
            $users[] = $row;
        }
    }

    // Get active trainers
    $trainer_sql = "SELECT u.id as user_id,
                           t.trainer_id,
                           CONCAT(t.first_name, ' ', t.last_name) as name,
                           'Trainer' as user_type,
                           t.trainer_id as identifier
                    FROM users u 
                    JOIN trainers t ON u.id = t.user_id 
                    WHERE u.role = 'trainer' AND u.is_active = 1 
                    AND t.employment_status = 'active'
                    ORDER BY t.first_name";
    
    $trainer_result = $conn->query($trainer_sql);
    if ($trainer_result) {
        while ($row = $trainer_result->fetch_assoc()) {
            $users[] = $row;
        }
    }

    $serial_no = 1;
    $total_present = 0;
    $total_absent = 0;
    $total_half_day = 0;
    $total_holiday = 0;
    $total_week_off = 0;
    $total_late_hours = 0;
    $total_ot_hours = 0;

    foreach ($users as $user) {
        $user_type_lower = strtolower($user['user_type']);
        $user_id = $user['user_id'];
        $user_identifier = $user['identifier'];
        
        $present_count = 0;
        $absent_count = 0;
        $half_day_count = 0;
        $holiday_count = 0;
        $week_off_count = 0;
        $total_late_minutes = 0;
        $total_overtime_minutes = 0;
        
        echo '<tr>';
        echo '<td>' . $serial_no++ . '</td>';
        echo '<td>' . $user_identifier . '</td>';
        echo '<td>' . $user['name'] . '</td>';
        echo '<td>' . $user['user_type'] . '</td>';
        
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $current_date = sprintf('%04d-%02d-%02d', $year, $month, $d);
            
            $attendance_sql = "SELECT status, late_minutes, overtime_minutes 
                               FROM attendance 
                               WHERE user_id = ? 
                               AND user_type = ? 
                               AND date = ?";
            $stmt = $conn->prepare($attendance_sql);
            if ($stmt) {
                $stmt->bind_param("iss", $user_id, $user_type_lower, $current_date);
                $stmt->execute();
                $att_result = $stmt->get_result();
                
                if ($att_result->num_rows > 0) {
                    $att_data = $att_result->fetch_assoc();
                    $status = $att_data['status'];
                    
                    switch ($status) {
                        case 'present': 
                            $present_count++; 
                            $status_code = 'P';
                            break;
                        case 'absent': 
                            $absent_count++; 
                            $status_code = 'A';
                            break;
                        case 'half_day': 
                            $half_day_count++; 
                            $status_code = 'HD';
                            break;
                        case 'holiday': 
                            $holiday_count++; 
                            $status_code = 'H';
                            break;
                        case 'week_off': 
                            $week_off_count++; 
                            $status_code = 'WO';
                            break;
                        default: 
                            $status_code = '';
                    }
                    
                    $total_late_minutes += $att_data['late_minutes'] ?? 0;
                    $total_overtime_minutes += $att_data['overtime_minutes'] ?? 0;
                    
                    echo '<td>' . $status_code . '</td>';
                } else {
                    echo '<td></td>';
                }
                
                $stmt->close();
            } else {
                echo '<td></td>';
            }
        }
        
        $late_hours = $total_late_minutes > 0 ? number_format($total_late_minutes / 60, 1) : '0';
        $ot_hours = $total_overtime_minutes > 0 ? number_format($total_overtime_minutes / 60, 1) : '0';
        
        echo '<td class="summary-row">' . $present_count . '</td>';
        echo '<td class="summary-row">' . $absent_count . '</td>';
        echo '<td class="summary-row">' . $half_day_count . '</td>';
        echo '<td class="summary-row">' . $holiday_count . '</td>';
        echo '<td class="summary-row">' . $week_off_count . '</td>';
        echo '<td class="summary-row">' . $late_hours . '</td>';
        echo '<td class="summary-row">' . $ot_hours . '</td>';
        
        echo '</tr>';
        
        $total_present += $present_count;
        $total_absent += $absent_count;
        $total_half_day += $half_day_count;
        $total_holiday += $holiday_count;
        $total_week_off += $week_off_count;
        $total_late_hours += $total_late_minutes;
        $total_ot_hours += $total_overtime_minutes;
    }

    echo '<tr class="total-row">';
    echo '<td colspan="' . ($daysInMonth + 4) . '" style="text-align: right; font-weight: bold;">TOTALS:</td>';
    echo '<td>' . $total_present . '</td>';
    echo '<td>' . $total_absent . '</td>';
    echo '<td>' . $total_half_day . '</td>';
    echo '<td>' . $total_holiday . '</td>';
    echo '<td>' . $total_week_off . '</td>';
    echo '<td>' . number_format($total_late_hours / 60, 1) . '</td>';
    echo '<td>' . number_format($total_ot_hours / 60, 1) . '</td>';
    echo '</tr>';

    echo '</table>';
    
    echo '<br><br>';
    echo '<div class="legend">';
    echo '<strong>LEGEND:</strong> ';
    echo 'P = Present | ';
    echo 'A = Absent | ';
    echo 'HD = Half Day | ';
    echo 'H = Holiday | ';
    echo 'WO = Week Off';
    echo '</div>';
    
    echo '<br><br>';
    echo '<table style="border: none; width: 100%;">';
    echo '<tr>';
    echo '<td style="border: none; width: 33%; text-align: center;">';
    echo '<div style="border-top: 1px solid #000; width: 80%; margin: 0 auto; padding-top: 5px;">';
    echo 'Prepared By<br>HR Department';
    echo '</div>';
    echo '</td>';
    echo '<td style="border: none; width: 33%; text-align: center;">';
    echo '<div style="border-top: 1px solid #000; width: 80%; margin: 0 auto; padding-top: 5px;">';
    echo 'Checked By<br>Department Head';
    echo '</div>';
    echo '</td>';
    echo '<td style="border: none; width: 33%; text-align: center;">';
    echo '<div style="border-top: 1px solid #000; width: 80%; margin: 0 auto; padding-top: 5px;">';
    echo 'Approved By<br>Management';
    echo '</div>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';
    
    echo '<br><br>';
    echo '<div style="text-align: right; color: #64748b; font-size: 12px;">';
    echo 'Generated on: ' . date('d-M-Y H:i:s');
    echo '</div>';
    
    echo '</body>';
    echo '</html>';
    
    // Flush output buffer and exit
    ob_end_flush();
    exit;
}

// Handle AJAX request for attendance details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_attendance_details') {
    $attendance_id = intval($_GET['id'] ?? 0);
    if ($attendance_id > 0) {
        $sql = "SELECT a.*, 
                       e.first_name as emp_first_name, e.last_name as emp_last_name, e.emp_id,
                       i.first_name as intern_first_name, i.last_name as intern_last_name, i.intern_id,
                       t.first_name as trainer_first_name, t.last_name as trainer_last_name, t.trainer_id,
                       s.name as shift_name, s.start_time, s.end_time
                FROM attendance a 
                LEFT JOIN employees e ON a.user_id = e.user_id AND a.user_type = 'employee'
                LEFT JOIN interns i ON a.user_id = i.user_id AND a.user_type = 'intern'
                LEFT JOIN trainers t ON a.user_id = t.user_id AND a.user_type = 'trainer'
                LEFT JOIN shifts s ON a.shift_id = s.id
                WHERE a.id = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $attendance_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $record = $result->fetch_assoc();
                
                // Clear output buffer
                ob_clean();
                
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'data' => $record]);
            } else {
                ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Attendance record not found']);
            }
            $stmt->close();
        } else {
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
    } else {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid attendance ID']);
    }
    $conn->close();
    ob_end_flush();
    exit();
}

// Handle delete action from URL parameter
if (isset($_GET['delete'])) {
    $attendance_id = intval($_GET['delete']);
    
    // First, get the attendance record details for notification
    $get_sql = "SELECT user_id, user_type, date FROM attendance WHERE id = ?";
    $get_stmt = $conn->prepare($get_sql);
    if ($get_stmt) {
        $get_stmt->bind_param("i", $attendance_id);
        $get_stmt->execute();
        $get_result = $get_stmt->get_result();
        
        if ($attendance_record = $get_result->fetch_assoc()) {
            // Create notification for the user
            $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_entity, related_entity_id) 
                               VALUES (?, 'Attendance Deleted', 'Your attendance record for " . $attendance_record['date'] . " has been deleted by HR.', 'warning', 'attendance', ?)";
            $notification_stmt = $conn->prepare($notification_sql);
            if ($notification_stmt) {
                $notification_stmt->bind_param("ii", $attendance_record['user_id'], $attendance_id);
                $notification_stmt->execute();
                $notification_stmt->close();
            }
        }
        $get_stmt->close();
    }
    
    // Delete the attendance record
    $delete_sql = "DELETE FROM attendance WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    if ($delete_stmt) {
        $delete_stmt->bind_param("i", $attendance_id);
        
        if ($delete_stmt->execute()) {
            $success_message = "Attendance record deleted successfully!";
            
            // Log the action
            $audit_sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at) 
                         VALUES (?, 'delete_attendance', 'attendance', ?, ?, ?, NOW())";
            $audit_stmt = $conn->prepare($audit_sql);
            if ($audit_stmt) {
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $audit_stmt->bind_param("iiss", $hr_user_id, $attendance_id, $_SERVER['REMOTE_ADDR'], $user_agent);
                $audit_stmt->execute();
                $audit_stmt->close();
            }
            
            $_SESSION['success_message'] = $success_message;
        } else {
            $_SESSION['error_message'] = "Error deleting attendance record: " . $conn->error;
        }
        $delete_stmt->close();
    }
    
    // Clear output buffer
    ob_clean();
    
    header('Location: attendance_manage.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['mark_attendance'])) {
        $user_id = $_POST['user_id'];
        $user_type = $_POST['user_type'];
        $date = $_POST['date'];
        $status = $_POST['status'];
        $check_in = !empty($_POST['check_in']) ? $_POST['check_in'] : NULL;
        $check_out = !empty($_POST['check_out']) ? $_POST['check_out'] : NULL;
        $remarks = $_POST['remarks'] ?? '';
        
        // Validate date is not in future
        if (strtotime($date) > strtotime('today')) {
            $_SESSION['error_message'] = "Cannot mark attendance for future dates!";
        } else {
            // Get attendance rules
            $rules_query = "SELECT rule_type, value FROM attendance_rules";
            $rules_result = $conn->query($rules_query);
            $rules = [];
            if ($rules_result) {
                while ($rule = $rules_result->fetch_assoc()) {
                    $rules[$rule['rule_type']] = $rule['value'];
                }
            }
            
            $late_threshold = isset($rules['late_threshold']) ? $rules['late_threshold'] : 15;
            $overtime_threshold = isset($rules['overtime_threshold']) ? $rules['overtime_threshold'] : 480;
            
            // Check if attendance already exists
            $check_sql = "SELECT id FROM attendance WHERE user_id = ? AND user_type = ? AND date = ?";
            $check_stmt = $conn->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->bind_param("iss", $user_id, $user_type, $date);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                // Calculate late minutes and overtime based on shift
                $late_minutes = 0;
                $overtime_minutes = 0;
                $early_departure_minutes = 0;
                $shift_id = NULL;
                
                if ($check_in && $user_type == 'employee') {
                    // Get employee's shift from employees table
                    $shift_sql = "SELECT shift_id FROM employees WHERE user_id = ?";
                    $shift_stmt = $conn->prepare($shift_sql);
                    if ($shift_stmt) {
                        $shift_stmt->bind_param("i", $user_id);
                        $shift_stmt->execute();
                        $shift_result = $shift_stmt->get_result();
                        
                        if ($shift_row = $shift_result->fetch_assoc()) {
                            $shift_id = $shift_row['shift_id'];
                            
                            // Get shift timings
                            if ($shift_id) {
                                $shift_time_sql = "SELECT start_time, end_time FROM shifts WHERE id = ?";
                                $shift_time_stmt = $conn->prepare($shift_time_sql);
                                if ($shift_time_stmt) {
                                    $shift_time_stmt->bind_param("i", $shift_id);
                                    $shift_time_stmt->execute();
                                    $shift_time_result = $shift_time_stmt->get_result();
                                    
                                    if ($shift_time_row = $shift_time_result->fetch_assoc()) {
                                        $shift_start = $shift_time_row['start_time'];
                                        $shift_end = $shift_time_row['end_time'];
                                        
                                        // Calculate late minutes
                                        $check_in_time = strtotime($check_in);
                                        $shift_start_time = strtotime($shift_start);
                                        
                                        if ($check_in_time > $shift_start_time) {
                                            $late_minutes = floor(($check_in_time - $shift_start_time) / 60);
                                            if ($late_minutes < $late_threshold) {
                                                $late_minutes = 0;
                                            }
                                        }
                                        
                                        // Calculate overtime and early departure
                                        if ($check_out) {
                                            $check_out_time = strtotime($check_out);
                                            $shift_end_time = strtotime($shift_end);
                                            
                                            // Handle overnight shifts
                                            if ($shift_end_time < $shift_start_time) {
                                                $shift_end_time += 24 * 3600;
                                                if ($check_out_time < $shift_start_time) {
                                                    $check_out_time += 24 * 3600;
                                                }
                                            }
                                            
                                            if ($check_out_time > $shift_end_time) {
                                                $overtime_minutes = floor(($check_out_time - $shift_end_time) / 60);
                                                if ($overtime_minutes < $overtime_threshold) {
                                                    $overtime_minutes = 0;
                                                }
                                            }
                                            
                                            if ($check_out_time < $shift_end_time) {
                                                $early_departure_minutes = floor(($shift_end_time - $check_out_time) / 60);
                                            }
                                        }
                                    }
                                    $shift_time_stmt->close();
                                }
                            }
                        }
                        $shift_stmt->close();
                    }
                }
                
                if ($check_result->num_rows > 0) {
                    // Update existing attendance
                    $row = $check_result->fetch_assoc();
                    $attendance_id = $row['id'];
                    
                    $update_sql = "UPDATE attendance SET 
                                  status = ?, 
                                  check_in = ?, 
                                  check_out = ?, 
                                  shift_id = ?, 
                                  late_minutes = ?, 
                                  early_departure_minutes = ?, 
                                  overtime_minutes = ?, 
                                  remarks = ?,
                                  updated_at = NOW()
                                  WHERE id = ?";
                    
                    $update_stmt = $conn->prepare($update_sql);
                    if ($update_stmt) {
                        $update_stmt->bind_param("sssiiissi", 
                            $status, 
                            $check_in, 
                            $check_out, 
                            $shift_id, 
                            $late_minutes, 
                            $early_departure_minutes, 
                            $overtime_minutes, 
                            $remarks, 
                            $attendance_id
                        );
                        
                        if ($update_stmt->execute()) {
                            $_SESSION['success_message'] = "Attendance updated successfully!";
                            
                            // Create notification for the user
                            $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_entity, related_entity_id) 
                                               VALUES (?, 'Attendance Updated', 'Your attendance for " . $date . " has been updated. New status: " . ucfirst($status) . "', 'info', 'attendance', ?)";
                            $notification_stmt = $conn->prepare($notification_sql);
                            if ($notification_stmt) {
                                $notification_stmt->bind_param("ii", $user_id, $attendance_id);
                                $notification_stmt->execute();
                                $notification_stmt->close();
                            }
                            
                            // Log the action
                            $audit_sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at) 
                                         VALUES (?, 'update_attendance', 'attendance', ?, ?, ?, NOW())";
                            $audit_stmt = $conn->prepare($audit_sql);
                            if ($audit_stmt) {
                                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                                $audit_stmt->bind_param("iiss", $hr_user_id, $attendance_id, $_SERVER['REMOTE_ADDR'], $user_agent);
                                $audit_stmt->execute();
                                $audit_stmt->close();
                            }
                        } else {
                            $_SESSION['error_message'] = "Error updating attendance: " . $conn->error;
                        }
                        $update_stmt->close();
                    }
                } else {
                    // Insert new attendance
                    $insert_sql = "INSERT INTO attendance (user_id, user_type, date, status, check_in, check_out, shift_id, late_minutes, early_departure_minutes, overtime_minutes, remarks, created_at) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $insert_stmt = $conn->prepare($insert_sql);
                    if ($insert_stmt) {
                        $insert_stmt->bind_param("isssssiiiss", 
                            $user_id, 
                            $user_type, 
                            $date, 
                            $status, 
                            $check_in, 
                            $check_out, 
                            $shift_id, 
                            $late_minutes, 
                            $early_departure_minutes, 
                            $overtime_minutes, 
                            $remarks
                        );
                        
                        if ($insert_stmt->execute()) {
                            $attendance_id = $insert_stmt->insert_id;
                            $_SESSION['success_message'] = "Attendance marked successfully!";
                            
                            // Create notification for the user
                            $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_entity, related_entity_id) 
                                               VALUES (?, 'Attendance Marked', 'Your attendance for " . $date . " has been marked. Status: " . ucfirst($status) . "', 'success', 'attendance', ?)";
                            $notification_stmt = $conn->prepare($notification_sql);
                            if ($notification_stmt) {
                                $notification_stmt->bind_param("ii", $user_id, $attendance_id);
                                $notification_stmt->execute();
                                $notification_stmt->close();
                            }
                            
                            // Log the action
                            $audit_sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at) 
                                         VALUES (?, 'create_attendance', 'attendance', ?, ?, ?, NOW())";
                            $audit_stmt = $conn->prepare($audit_sql);
                            if ($audit_stmt) {
                                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                                $audit_stmt->bind_param("iiss", $hr_user_id, $attendance_id, $_SERVER['REMOTE_ADDR'], $user_agent);
                                $audit_stmt->execute();
                                $audit_stmt->close();
                            }
                        } else {
                            $_SESSION['error_message'] = "Error marking attendance: " . $conn->error;
                        }
                        $insert_stmt->close();
                    }
                }
                $check_stmt->close();
                
                // Update attendance summary
                $update_summary = $conn->prepare("
                    INSERT INTO attendance_summary 
                    (user_id, user_type, summary_month, total_present, total_absent, total_half_days, total_late_days, total_overtime_minutes, created_at, updated_at)
                    SELECT 
                        user_id,
                        user_type,
                        DATE_FORMAT(date, '%Y-%m-01') as summary_month,
                        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as total_present,
                        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as total_absent,
                        SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as total_half_days,
                        SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as total_late_days,
                        SUM(overtime_minutes) as total_overtime_minutes,
                        NOW(),
                        NOW()
                    FROM attendance
                    WHERE user_id = ? AND user_type = ? AND date >= DATE_FORMAT(NOW(), '%Y-%m-01')
                    GROUP BY user_id, user_type, DATE_FORMAT(date, '%Y-%m-01')
                    ON DUPLICATE KEY UPDATE
                        total_present = VALUES(total_present),
                        total_absent = VALUES(total_absent),
                        total_half_days = VALUES(total_half_days),
                        total_late_days = VALUES(total_late_days),
                        total_overtime_minutes = VALUES(total_overtime_minutes),
                        updated_at = NOW()
                ");
                if ($update_summary) {
                    $update_summary->bind_param("is", $user_id, $user_type);
                    $update_summary->execute();
                    $update_summary->close();
                }
            }
        }
        
        // Clear output buffer
        ob_clean();
        
        header('Location: attendance_manage.php');
        exit();
    } elseif (isset($_POST['bulk_action'])) {
        $action = $_POST['bulk_action'];
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids)) {
            $ids = implode(',', array_map('intval', $selected_ids));
            
            if ($action == 'delete') {
                // Get user info for notifications
                $user_sql = "SELECT id, user_id, user_type, date FROM attendance WHERE id IN ($ids)";
                $user_result = $conn->query($user_sql);
                
                $delete_sql = "DELETE FROM attendance WHERE id IN ($ids)";
                if ($conn->query($delete_sql)) {
                    $_SESSION['success_message'] = count($selected_ids) . " attendance records deleted successfully!";
                    
                    // Create notifications
                    if ($user_result) {
                        while ($row = $user_result->fetch_assoc()) {
                            $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_entity, related_entity_id) 
                                               VALUES (?, 'Attendance Deleted', 'Your attendance record for " . $row['date'] . " has been deleted by HR.', 'warning', 'attendance', ?)";
                            $notification_stmt = $conn->prepare($notification_sql);
                            if ($notification_stmt) {
                                $notification_stmt->bind_param("ii", $row['user_id'], $row['id']);
                                $notification_stmt->execute();
                                $notification_stmt->close();
                            }
                        }
                    }
                    
                    // Log the action
                    $audit_sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at) 
                                 VALUES (?, 'bulk_delete_attendance', 'attendance', ?, ?, ?, NOW())";
                    $audit_stmt = $conn->prepare($audit_sql);
                    if ($audit_stmt) {
                        $entity_ids = implode(',', $selected_ids);
                        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        $audit_stmt->bind_param("iiss", $hr_user_id, $entity_ids, $_SERVER['REMOTE_ADDR'], $user_agent);
                        $audit_stmt->execute();
                        $audit_stmt->close();
                    }
                } else {
                    $_SESSION['error_message'] = "Error deleting attendance records: " . $conn->error;
                }
            } elseif ($action == 'approve') {
                $update_sql = "UPDATE attendance SET status = 'present', updated_at = NOW() WHERE id IN ($ids)";
                if ($conn->query($update_sql)) {
                    $_SESSION['success_message'] = count($selected_ids) . " attendance records approved!";
                    
                    // Get updated records for notifications
                    $updated_sql = "SELECT id, user_id, date FROM attendance WHERE id IN ($ids)";
                    $updated_result = $conn->query($updated_sql);
                    if ($updated_result) {
                        while ($row = $updated_result->fetch_assoc()) {
                            $notification_sql = "INSERT INTO notifications (user_id, title, message, type, related_entity, related_entity_id) 
                                               VALUES (?, 'Attendance Approved', 'Your attendance for " . $row['date'] . " has been approved by HR.', 'success', 'attendance', ?)";
                            $notification_stmt = $conn->prepare($notification_sql);
                            if ($notification_stmt) {
                                $notification_stmt->bind_param("ii", $row['user_id'], $row['id']);
                                $notification_stmt->execute();
                                $notification_stmt->close();
                            }
                        }
                    }
                    
                    // Log the action
                    $audit_sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at) 
                                 VALUES (?, 'bulk_approve_attendance', 'attendance', ?, ?, ?, NOW())";
                    $audit_stmt = $conn->prepare($audit_sql);
                    if ($audit_stmt) {
                        $entity_ids = implode(',', $selected_ids);
                        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        $audit_stmt->bind_param("iiss", $hr_user_id, $entity_ids, $_SERVER['REMOTE_ADDR'], $user_agent);
                        $audit_stmt->execute();
                        $audit_stmt->close();
                    }
                } else {
                    $_SESSION['error_message'] = "Error approving attendance records: " . $conn->error;
                }
            }
            
            // Update attendance summary for affected users
            $summary_sql = "UPDATE attendance_summary AS a
                          JOIN (
                              SELECT 
                                  user_id,
                                  user_type,
                                  DATE_FORMAT(date, '%Y-%m-01') as summary_month,
                                  SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as total_present,
                                  SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as total_absent,
                                  SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as total_half_days,
                                  SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as total_late_days,
                                  SUM(overtime_minutes) as total_overtime_minutes
                              FROM attendance
                              WHERE id IN ($ids)
                              GROUP BY user_id, user_type, DATE_FORMAT(date, '%Y-%m-01')
                          ) AS b ON a.user_id = b.user_id AND a.user_type = b.user_type AND a.summary_month = b.summary_month
                          SET 
                              a.total_present = b.total_present,
                              a.total_absent = b.total_absent,
                              a.total_half_days = b.total_half_days,
                              a.total_late_days = b.total_late_days,
                              a.total_overtime_minutes = b.total_overtime_minutes,
                              a.updated_at = NOW()";
            $conn->query($summary_sql);
            
        } else {
            $_SESSION['error_message'] = "Please select at least one record!";
        }
        
        // Clear output buffer
        ob_clean();
        
        header('Location: attendance_manage.php');
        exit();
    }
}

// Get messages from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
$bulk_success_message = $_SESSION['bulk_success_message'] ?? null;
$bulk_error_message = $_SESSION['bulk_error_message'] ?? null;
$bulk_processed_records = $_SESSION['bulk_processed_records'] ?? [];
$bulk_errors = $_SESSION['bulk_errors'] ?? [];

// Clear session messages
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);
unset($_SESSION['bulk_success_message']);
unset($_SESSION['bulk_error_message']);
unset($_SESSION['bulk_processed_records']);
unset($_SESSION['bulk_errors']);

// Set default date filter
$filter_date = $_GET['date'] ?? '';
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_user_type = $_GET['user_type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Get attendance data based on filters
$where_conditions = [];
$params = [];
$types = "";

if ($filter_date && $filter_date != 'all') {
    $where_conditions[] = "a.date = ?";
    $params[] = $filter_date;
    $types .= "s";
}

if ($filter_month && $filter_month != 'all' && !$filter_date) {
    $where_conditions[] = "DATE_FORMAT(a.date, '%Y-%m') = ?";
    $params[] = $filter_month;
    $types .= "s";
}

if ($filter_user_type && $filter_user_type != 'all') {
    $where_conditions[] = "a.user_type = ?";
    $params[] = $filter_user_type;
    $types .= "s";
}

if ($filter_status && $filter_status != 'all') {
    $where_conditions[] = "a.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

if ($search_query) {
    $where_conditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR i.first_name LIKE ? OR i.last_name LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ? OR e.emp_id LIKE ? OR i.intern_id LIKE ? OR t.trainer_id LIKE ?)";
    $search_param = "%$search_query%";
    $params = array_merge($params, array_fill(0, 9, $search_param));
    $types .= str_repeat("s", 9);
}

$where_clause = "";
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM attendance a 
             LEFT JOIN employees e ON a.user_id = e.user_id AND a.user_type = 'employee'
             LEFT JOIN interns i ON a.user_id = i.user_id AND a.user_type = 'intern'
             LEFT JOIN trainers t ON a.user_id = t.user_id AND a.user_type = 'trainer'
             $where_clause";

$count_stmt = $conn->prepare($count_sql);
if ($count_stmt) {
    if (!empty($types) && !empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $total_records = 0;
}

// Pagination
$page = $_GET['page'] ?? 1;
$records_per_page = 20;
$total_pages = ceil($total_records / $records_per_page);
$offset = ($page - 1) * $records_per_page;

// Get attendance records
$sql = "SELECT a.*, 
               e.first_name as emp_first_name, e.last_name as emp_last_name, e.emp_id,
               i.first_name as intern_first_name, i.last_name as intern_last_name, i.intern_id,
               t.first_name as trainer_first_name, t.last_name as trainer_last_name, t.trainer_id,
               s.name as shift_name, s.start_time, s.end_time
        FROM attendance a 
        LEFT JOIN employees e ON a.user_id = e.user_id AND a.user_type = 'employee'
        LEFT JOIN interns i ON a.user_id = i.user_id AND a.user_type = 'intern'
        LEFT JOIN trainers t ON a.user_id = t.user_id AND a.user_type = 'trainer'
        LEFT JOIN shifts s ON a.shift_id = s.id
        $where_clause
        ORDER BY a.date DESC, a.created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$attendance_data = [];
if ($stmt) {
    if (!empty($types) && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $attendance_data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get statistics for today
$today = date('Y-m-d');
$stats_sql = "SELECT 
    COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
    COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
    COUNT(CASE WHEN status = 'half_day' THEN 1 END) as half_day_count,
    COUNT(CASE WHEN status = 'holiday' THEN 1 END) as holiday_count,
    COUNT(CASE WHEN status = 'week_off' THEN 1 END) as week_off_count,
    SUM(late_minutes) as total_late_minutes,
    SUM(overtime_minutes) as total_overtime_minutes
    FROM attendance WHERE date = ?";

$stats_stmt = $conn->prepare($stats_sql);
$today_stats = [];
if ($stats_stmt) {
    $stats_stmt->bind_param("s", $today);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $today_stats = $stats_result->fetch_assoc();
    $stats_stmt->close();
}

// Get all shifts for dropdown
$shifts_result = $conn->query("SELECT * FROM shifts ORDER BY start_time");

// Get all users for attendance marking (active employees, interns, trainers)
$active_users = [];

// Get active employees
$employees_sql = "SELECT u.id, e.first_name, e.last_name, e.emp_id, 'employee' as user_type 
                 FROM users u 
                 JOIN employees e ON u.id = e.user_id 
                 WHERE u.role = 'employee' AND u.is_active = 1 AND e.employment_status = 'active' 
                 ORDER BY e.first_name";
$employees_result = $conn->query($employees_sql);
if ($employees_result) {
    while ($row = $employees_result->fetch_assoc()) {
        $active_users[] = $row;
    }
}

// Get active interns
$interns_sql = "SELECT u.id, i.first_name, i.last_name, i.intern_id, 'intern' as user_type 
               FROM users u 
               JOIN interns i ON u.id = i.user_id 
               WHERE u.role = 'intern' AND u.is_active = 1 AND i.internship_status = 'active' 
               ORDER BY i.first_name";
$interns_result = $conn->query($interns_sql);
if ($interns_result) {
    while ($row = $interns_result->fetch_assoc()) {
        $active_users[] = $row;
    }
}

// Get active trainers
$trainers_sql = "SELECT u.id, t.first_name, t.last_name, t.trainer_id, 'trainer' as user_type 
                FROM users u 
                JOIN trainers t ON u.id = t.user_id 
                WHERE u.role = 'trainer' AND u.is_active = 1 AND t.employment_status = 'active' 
                ORDER BY t.first_name";
$trainers_result = $conn->query($trainers_sql);
if ($trainers_result) {
    while ($row = $trainers_result->fetch_assoc()) {
        $active_users[] = $row;
    }
}

// Get holidays for current year
$holidays_query = "SELECT holiday_date, name FROM holidays WHERE YEAR(holiday_date) = ?";
$holidays_stmt = $conn->prepare($holidays_query);
$holidays_list = [];
if ($holidays_stmt) {
    $current_year = date('Y');
    $holidays_stmt->bind_param("i", $current_year);
    $holidays_stmt->execute();
    $holidays_result = $holidays_stmt->get_result();
    while ($row = $holidays_result->fetch_assoc()) {
        $holidays_list[$row['holiday_date']] = $row['name'];
    }
    $holidays_stmt->close();
}

// Get attendance record for editing/viewing
$edit_record = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_sql = "SELECT a.*, 
                        e.first_name as emp_first_name, e.last_name as emp_last_name, e.emp_id,
                        i.first_name as intern_first_name, i.last_name as intern_last_name, i.intern_id,
                        t.first_name as trainer_first_name, t.last_name as trainer_last_name, t.trainer_id,
                        s.name as shift_name, s.start_time, s.end_time
                 FROM attendance a 
                 LEFT JOIN employees e ON a.user_id = e.user_id AND a.user_type = 'employee'
                 LEFT JOIN interns i ON a.user_id = i.user_id AND a.user_type = 'intern'
                 LEFT JOIN trainers t ON a.user_id = t.user_id AND a.user_type = 'trainer'
                 LEFT JOIN shifts s ON a.shift_id = s.id
                 WHERE a.id = ?";
    $edit_stmt = $conn->prepare($edit_sql);
    if ($edit_stmt) {
        $edit_stmt->bind_param("i", $edit_id);
        $edit_stmt->execute();
        $edit_result = $edit_stmt->get_result();
        if ($edit_result->num_rows > 0) {
            $edit_record = $edit_result->fetch_assoc();
        }
        $edit_stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Your existing CSS styles remain exactly the same */
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
        
        .user-profile {
            display: flex;
            align-items: center;
            background: white;
            padding: 12px 20px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 250px;
        }
        
        .user-profile:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 15px;
            font-size: 18px;
        }
        
        .user-info h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .user-info span {
            font-size: 13px;
            color: var(--gray);
        }
        
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
            animation: slideUp 0.5s ease-out;
            animation-fill-mode: both;
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
            transition: transform 0.3s ease;
        }
        
        .stat-icon.attendance { background: linear-gradient(135deg, var(--primary) 0%, var(--info) 100%); }
        .stat-icon.absent { background: linear-gradient(135deg, var(--danger) 0%, #f87171 100%); }
        .stat-icon.half-day { background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%); }
        .stat-icon.late { background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%); }
        
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
        
        .stat-change {
            display: flex;
            align-items: center;
            margin-top: 15px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .stat-change.positive { color: var(--success); }
        .stat-change.negative { color: var(--danger); }
        .stat-change.warning { color: var(--warning); }
        
        .export-section {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        
        .export-section h3 {
            font-size: 18px;
            margin-bottom: 15px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .export-section h3 i {
            color: var(--success);
        }
        
        .export-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .export-form .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .export-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
            font-size: 14px;
        }
        
        .export-form label i {
            margin-right: 8px;
            color: var(--primary);
        }
        
        .export-form .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .export-form .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .export-btn {
            padding: 12px 24px;
            background: var(--success);
            color: white;
            border: none;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            height: 46px;
        }
        
        .export-btn:hover {
            background: #0da271;
            transform: translateY(-2px);
        }
        
        .upload-section {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            border-left: 4px solid var(--success);
        }
        
        .upload-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .upload-header h3 {
            font-size: 18px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .upload-header h3 i {
            color: var(--success);
        }
        
        .template-buttons {
            display: flex;
            gap: 10px;
        }
        
        .template-btn {
            padding: 10px 20px;
            background: #f8fafc;
            color: var(--dark);
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .template-btn:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }
        
        .template-btn i {
            color: var(--primary);
        }
        
        .upload-form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        
        .upload-form .file-input-group {
            flex: 1;
            min-width: 300px;
        }
        
        .upload-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
            font-size: 14px;
        }
        
        .upload-form label i {
            margin-right: 8px;
            color: var(--success);
        }
        
        .file-input {
            width: 100%;
            padding: 10px;
            border: 2px dashed #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .file-input:hover {
            border-color: var(--success);
            background: #f0fdf4;
        }
        
        .upload-btn {
            padding: 12px 30px;
            background: var(--success);
            color: white;
            border: none;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            height: 46px;
        }
        
        .upload-btn:hover {
            background: #0da271;
            transform: translateY(-2px);
        }
        
        .upload-info {
            margin-top: 15px;
            padding: 15px;
            background: #f0fdf4;
            border-radius: 10px;
            color: #065f46;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .upload-results {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
            border-left: 4px solid var(--info);
            animation: slideDown 0.5s ease;
        }
        
        .results-summary {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .result-badge {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .result-badge.success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .result-badge.error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .result-details {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }
        
        .result-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .result-table th {
            background: #f8fafc;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            background: #f8fafc;
        }
        
        .result-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }
        
        .result-table tr:hover {
            background: #f8fafc;
        }
        
        .record-success {
            color: var(--success);
            font-weight: 500;
        }
        
        .record-error {
            color: var(--danger);
        }
        
        .filter-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        
        .filter-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
            font-size: 14px;
        }
        
        .filter-group label i {
            margin-right: 8px;
            color: var(--primary);
        }
        
        .filter-input, .filter-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .filter-btn, .reset-btn, .add-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .filter-btn {
            background: var(--primary);
            color: white;
        }
        
        .filter-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .reset-btn {
            background: #e2e8f0;
            color: var(--dark);
            text-decoration: none;
        }
        
        .reset-btn:hover {
            background: #cbd5e1;
            transform: translateY(-2px);
        }
        
        .add-btn {
            background: var(--success);
            color: white;
        }
        
        .add-btn:hover {
            background: #0da271;
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.5s ease;
        }
        
        .alert.success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success);
        }
        
        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }
        
        .bulk-actions {
            background: #f8fafc;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }
        
        .bulk-select {
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            min-width: 200px;
        }
        
        .bulk-btn {
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
        }
        
        .bulk-btn:hover:not(:disabled) {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .bulk-btn:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
        }
        
        .table-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .table-header {
            padding: 20px 25px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .export-table-btn {
            padding: 8px 16px;
            background: var(--info);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .export-table-btn:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }
        
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
        
        .no-data {
            text-align: center;
            padding: 50px !important;
            color: var(--gray);
        }
        
        .no-data i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .user-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .avatar-small {
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
        
        .type-badge, .status-badge, .late-badge, .overtime-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .type-badge.employee { background: #dbeafe; color: #1e40af; }
        .type-badge.intern { background: #d1fae5; color: #065f46; }
        .type-badge.trainer { background: #f3e8ff; color: #6b21a8; }
        
        .status-badge.success { background: #d1fae5; color: #065f46; }
        .status-badge.danger { background: #fee2e2; color: #991b1b; }
        .status-badge.warning { background: #fef3c7; color: #92400e; }
        .status-badge.info { background: #dbeafe; color: #1e40af; }
        .status-badge.secondary { background: #e2e8f0; color: #475569; }
        
        .late-badge { background: #fef3c7; color: #92400e; }
        .overtime-badge { background: #d1fae5; color: #065f46; }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .edit-btn, .view-btn, .delete-btn {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .edit-btn {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .edit-btn:hover {
            background: #93c5fd;
            transform: scale(1.1);
        }
        
        .view-btn {
            background: #d1fae5;
            color: #065f46;
        }
        
        .view-btn:hover {
            background: #a7f3d0;
            transform: scale(1.1);
        }
        
        .delete-btn {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .delete-btn:hover {
            background: #fca5a5;
            transform: scale(1.1);
        }
        
        .pagination {
            padding: 20px 25px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            border-top: 1px solid #e2e8f0;
        }
        
        .page-link {
            padding: 8px 16px;
            background: #f8fafc;
            color: var(--dark);
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .page-link:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }
        
        .page-numbers {
            display: flex;
            gap: 5px;
        }
        
        .page-number {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            color: var(--dark);
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .page-number:hover, .page-number.active {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }
        
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
            animation: fadeIn 0.3s ease;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
        }
        
        .modal-header {
            padding: 20px 25px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .close-modal:hover {
            color: var(--danger);
            transform: rotate(90deg);
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .view-details {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .detail-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            flex: 1;
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
        }
        
        .detail-value {
            flex: 2;
            color: var(--gray);
            font-size: 14px;
        }
        
        .detail-value .type-badge,
        .detail-value .status-badge {
            font-size: 12px;
            padding: 4px 8px;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group {
            flex: 1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
            font-size: 14px;
        }
        
        .form-group label i {
            margin-right: 8px;
            color: var(--primary);
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .modal-footer {
            padding: 20px 25px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .cancel-btn, .submit-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .cancel-btn {
            background: #e2e8f0;
            color: var(--dark);
        }
        
        .cancel-btn:hover {
            background: #cbd5e1;
            transform: translateY(-2px);
        }
        
        .submit-btn {
            background: var(--success);
            color: white;
        }
        
        .submit-btn:hover {
            background: #0da271;
            transform: translateY(-2px);
        }
        
        .loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            gap: 15px;
        }
        
        .fa-spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
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
        
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-row {
                flex-direction: column;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .export-form {
                flex-direction: column;
            }
            
            .export-form .form-group {
                min-width: 100%;
            }
            
            .upload-form {
                flex-direction: column;
            }
            
            .upload-form .file-input-group {
                min-width: 100%;
            }
            
            .upload-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .template-buttons {
                width: 100%;
            }
            
            .template-btn {
                flex: 1;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .detail-row {
                flex-direction: column;
                gap: 5px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .user-profile {
                width: 100%;
                min-width: auto;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .template-buttons {
                flex-direction: column;
            }
        }
        
        .btn-success {
            background-color: var(--success);
            border-color: var(--success);
        }
        
        .btn-success:hover {
            background-color: #0da271;
            border-color: #0da271;
        }
        
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border-radius: 16px 16px 0 0 !important;
            padding: 15px 20px;
        }
    </style>
</head>
<body>
    <!-- Sidebar is already included via sidebar_hr.php -->
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-calendar-check"></i> Attendance Management</h1>
                <p>Manage employee, intern, and trainer attendance records</p>
            </div>
            
            <div style="display: flex; align-items: center; gap: 10px;">
                <div class="user-profile">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($hr_name, 0, 1)); ?>
                    </div>
                    <div class="user-info">
                        <h4><?php echo htmlspecialchars($hr_name); ?></h4>
                        <span><?php echo htmlspecialchars($hr_email); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon attendance">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $today_stats['present_count'] ?? 0; ?></h3>
                    <p>Present Today</p>
                </div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> Today
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon absent">
                    <i class="fas fa-user-slash"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $today_stats['absent_count'] ?? 0; ?></h3>
                    <p>Absent Today</p>
                </div>
                <div class="stat-change negative">
                    <i class="fas fa-arrow-down"></i> Today
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon half-day">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $today_stats['half_day_count'] ?? 0; ?></h3>
                    <p>Half Day Today</p>
                </div>
                <div class="stat-change warning">
                    <i class="fas fa-clock"></i> Today
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon late">
                    <i class="fas fa-running"></i>
                </div>
                <div class="stat-info">
                    <h3><?php 
                        $late_mins = $today_stats['total_late_minutes'] ?? 0;
                        echo floor($late_mins / 60) . 'h ' . ($late_mins % 60) . 'm'; 
                    ?></h3>
                    <p>Total Late Time</p>
                </div>
                <div class="stat-change warning">
                    <i class="fas fa-clock"></i> Today
                </div>
            </div>
        </div>
        
        <!-- Export Section -->
        <div class="export-section">
            <h3><i class="fas fa-file-export"></i> Export Monthly Attendance Report</h3>
            <form method="get" class="export-form">
                <input type="hidden" name="export_attendance" value="1">
                <div class="form-group">
                    <label for="export_month"><i class="fas fa-calendar-alt"></i> Select Month</label>
                    <input type="month" id="export_month" name="month" required 
                           value="<?php echo date('Y-m'); ?>" class="form-input">
                </div>
                <button type="submit" class="export-btn">
                    <i class="fas fa-file-excel"></i> Export to Excel
                </button>
            </form>
            <small style="color: var(--gray); margin-top: 10px; display: block;">
                <i class="fas fa-info-circle"></i> This will export a comprehensive monthly attendance report for all active users
            </small>
        </div>
        
        <!-- Bulk Upload Section -->
        <div class="upload-section">
            <div class="upload-header">
                <h3><i class="fas fa-cloud-upload-alt"></i> Bulk Upload Attendance</h3>
                <div class="template-buttons">
                    <a href="download_attendance_template.php" class="template-btn" target="_blank">
                        <i class="fas fa-download"></i> Download Template
                    </a>
                   
                </div>
            </div>
            
            <form method="post" enctype="multipart/form-data" class="upload-form" id="bulkUploadForm">
                <input type="hidden" name="bulk_upload_attendance" value="1">
                <div class="file-input-group">
                    <label for="attendance_file"><i class="fas fa-file-excel"></i> Select Excel/CSV File *</label>
                    <input type="file" id="attendance_file" name="attendance_file" required 
                           accept=".xlsx,.xls,.csv" class="file-input">
                </div>
                <button type="submit" class="upload-btn" id="uploadBtn">
                    <i class="fas fa-cloud-upload-alt"></i> Upload & Process
                </button>
            </form>
            
            <div class="upload-info">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Instructions:</strong> Upload Excel/CSV file with columns: User ID, Date, Check In, Check Out, Status, Remarks.
                    <br>Use the template button above to download a sample template with correct format.
                </div>
            </div>
        </div>
        
        <!-- Bulk Upload Results -->
        <?php if ($bulk_success_message || $bulk_error_message || !empty($bulk_processed_records) || !empty($bulk_errors)): ?>
        <div class="upload-results">
            <div class="upload-header">
                <h3><i class="fas fa-clipboard-list"></i> Bulk Upload Results</h3>
                <button onclick="this.parentElement.parentElement.style.display='none'" class="template-btn" style="border: none;">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
            
            <div class="results-summary">
                <?php if ($bulk_success_message): ?>
                <div class="result-badge success">
                    <i class="fas fa-check-circle"></i> <?php echo $bulk_success_message; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($bulk_error_message): ?>
                <div class="result-badge error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $bulk_error_message; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($bulk_processed_records)): ?>
            <div style="margin-bottom: 15px;">
                <strong><i class="fas fa-check-circle" style="color: var(--success);"></i> Successfully Processed Records:</strong>
            </div>
            <div class="result-details">
                <table class="result-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Record</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bulk_processed_records as $index => $record): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td class="record-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($record); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($bulk_errors)): ?>
            <div style="margin-bottom: 15px; margin-top: 20px;">
                <strong><i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> Errors:</strong>
            </div>
            <div class="result-details">
                <table class="result-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bulk_errors as $index => $error): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td class="record-error"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($error); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Main Content Area -->
        <div class="content-area">
            <!-- Messages -->
            <?php if ($success_message): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="" class="filter-form">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="date"><i class="fas fa-calendar-day"></i> Date</label>
                            <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>" class="filter-input" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="month"><i class="fas fa-calendar-alt"></i> Month</label>
                            <input type="month" id="month" name="month" value="<?php echo htmlspecialchars($filter_month); ?>" class="filter-input">
                        </div>
                        
                        <div class="filter-group">
                            <label for="user_type"><i class="fas fa-users"></i> User Type</label>
                            <select id="user_type" name="user_type" class="filter-select">
                                <option value="all" <?php echo $filter_user_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="employee" <?php echo $filter_user_type == 'employee' ? 'selected' : ''; ?>>Employees</option>
                                <option value="intern" <?php echo $filter_user_type == 'intern' ? 'selected' : ''; ?>>Interns</option>
                                <option value="trainer" <?php echo $filter_user_type == 'trainer' ? 'selected' : ''; ?>>Trainers</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="status"><i class="fas fa-check-circle"></i> Status</label>
                            <select id="status" name="status" class="filter-select">
                                <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="present" <?php echo $filter_status == 'present' ? 'selected' : ''; ?>>Present</option>
                                <option value="absent" <?php echo $filter_status == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                <option value="half_day" <?php echo $filter_status == 'half_day' ? 'selected' : ''; ?>>Half Day</option>
                                <option value="holiday" <?php echo $filter_status == 'holiday' ? 'selected' : ''; ?>>Holiday</option>
                                <option value="week_off" <?php echo $filter_status == 'week_off' ? 'selected' : ''; ?>>Week Off</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="filter-row">
                        <div class="filter-group" style="flex: 2;">
                            <label for="search"><i class="fas fa-search"></i> Search</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_query); ?>" 
                                   placeholder="Search by name or ID..." class="filter-input">
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="filter-btn">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="attendance_manage.php" class="reset-btn">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                            <button type="button" onclick="openMarkAttendanceModal()" class="add-btn">
                                <i class="fas fa-plus"></i> Mark Attendance
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Bulk Actions -->
            <div class="bulk-actions">
                <form method="POST" action="" id="bulkForm" onsubmit="return confirmBulkAction()">
                    <input type="hidden" name="bulk_action" id="bulk_action_input" value="">
                    <select id="bulk_action_select" class="bulk-select" onchange="updateBulkAction()">
                        <option value="">Bulk Actions</option>
                        <option value="approve">Mark as Present</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button type="submit" id="bulkSubmitBtn" class="bulk-btn" disabled>
                        <i class="fas fa-play"></i> Apply
                    </button>
                </form>
            </div>
            
            <!-- Attendance Table -->
            <div class="table-card">
                <div class="table-header">
                    <h3>Attendance Records (<?php echo $total_records; ?> records found)</h3>
                    <div class="table-actions">
                        <button onclick="exportFilteredToExcel()" class="export-table-btn">
                            <i class="fas fa-file-export"></i> Export Filtered Data
                        </button>
                    </div>
                </div>
                
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th width="30">
                                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                                </th>
                                <th>Name</th>
                                <th>User Type</th>
                                <th>Date</th>
                                <th>Shift</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Status</th>
                                <th>Late</th>
                                <th>Overtime</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($attendance_data)): ?>
                            <tr>
                                <td colspan="11" class="no-data">
                                    <i class="fas fa-calendar-times"></i>
                                    <p>No attendance records found</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($attendance_data as $record): ?>
                                <?php 
                                $full_name = '';
                                $user_id_display = '';
                                
                                if ($record['user_type'] == 'employee') {
                                    $full_name = ($record['emp_first_name'] ?? '') . ' ' . ($record['emp_last_name'] ?? '');
                                    $user_id_display = $record['emp_id'] ?? 'N/A';
                                } elseif ($record['user_type'] == 'intern') {
                                    $full_name = ($record['intern_first_name'] ?? '') . ' ' . ($record['intern_last_name'] ?? '');
                                    $user_id_display = $record['intern_id'] ?? 'N/A';
                                } elseif ($record['user_type'] == 'trainer') {
                                    $full_name = ($record['trainer_first_name'] ?? '') . ' ' . ($record['trainer_last_name'] ?? '');
                                    $user_id_display = $record['trainer_id'] ?? 'N/A';
                                }
                                
                                $status_class = '';
                                switch ($record['status']) {
                                    case 'present': $status_class = 'success'; break;
                                    case 'absent': $status_class = 'danger'; break;
                                    case 'half_day': $status_class = 'warning'; break;
                                    case 'holiday': $status_class = 'info'; break;
                                    case 'week_off': $status_class = 'secondary'; break;
                                    default: $status_class = 'secondary';
                                }
                                
                                $check_in = $record['check_in'] ? date('H:i', strtotime($record['check_in'])) : '-';
                                $check_out = $record['check_out'] ? date('H:i', strtotime($record['check_out'])) : '-';
                                $late_minutes = $record['late_minutes'] > 0 ? 
                                    floor($record['late_minutes'] / 60) . 'h ' . ($record['late_minutes'] % 60) . 'm' : '-';
                                $overtime_minutes = $record['overtime_minutes'] > 0 ? 
                                    floor($record['overtime_minutes'] / 60) . 'h ' . ($record['overtime_minutes'] % 60) . 'm' : '-';
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_ids[]" value="<?php echo $record['id']; ?>" 
                                               class="select-checkbox" onchange="updateBulkButton()">
                                    </td>
                                    <td>
                                        <div class="user-cell">
                                            <div class="avatar-small">
                                                <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($full_name); ?></strong>
                                                <small><?php echo $user_id_display; ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="type-badge <?php echo $record['user_type']; ?>">
                                            <?php echo ucfirst($record['user_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('d M, Y', strtotime($record['date'])); ?>
                                        <br><small><?php echo date('D', strtotime($record['date'])); ?></small>
                                    </td>
                                    <td><?php echo $record['shift_name'] ?? '-'; ?></td>
                                    <td><?php echo $check_in; ?></td>
                                    <td><?php echo $check_out; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $record['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($record['late_minutes'] > 0): ?>
                                        <span class="late-badge">
                                            <i class="fas fa-clock"></i> <?php echo $late_minutes; ?>
                                        </span>
                                        <?php else: ?>
                                        -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($record['overtime_minutes'] > 0): ?>
                                        <span class="overtime-badge">
                                            <i class="fas fa-business-time"></i> <?php echo $overtime_minutes; ?>
                                        </span>
                                        <?php else: ?>
                                        -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick="editAttendance(<?php echo $record['id']; ?>)" 
                                                    class="edit-btn" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="viewAttendanceDetails(<?php echo $record['id']; ?>)" 
                                                    class="view-btn" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button onclick="deleteAttendance(<?php echo $record['id']; ?>)" 
                                                    class="delete-btn" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page-1; ?>&date=<?php echo urlencode($filter_date); ?>&month=<?php echo urlencode($filter_month); ?>&user_type=<?php echo urlencode($filter_user_type); ?>&status=<?php echo urlencode($filter_status); ?>&search=<?php echo urlencode($search_query); ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                    <?php endif; ?>
                    
                    <div class="page-numbers">
                        <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                        <a href="?page=<?php echo $i; ?>&date=<?php echo urlencode($filter_date); ?>&month=<?php echo urlencode($filter_month); ?>&user_type=<?php echo urlencode($filter_user_type); ?>&status=<?php echo urlencode($filter_status); ?>&search=<?php echo urlencode($search_query); ?>" 
                           class="page-number <?php echo $i == $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                    </div>
                    
                    <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page+1; ?>&date=<?php echo urlencode($filter_date); ?>&month=<?php echo urlencode($filter_month); ?>&user_type=<?php echo urlencode($filter_user_type); ?>&status=<?php echo urlencode($filter_status); ?>&search=<?php echo urlencode($search_query); ?>" class="page-link">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Mark Attendance Modal -->
    <div id="markAttendanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-plus"></i> Mark Attendance</h3>
                <button class="close-modal" onclick="closeMarkAttendanceModal()">&times;</button>
            </div>
            <form method="POST" action="" id="attendanceForm">
                <input type="hidden" name="mark_attendance" value="1">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="modal_user_id"><i class="fas fa-user"></i> Select User *</label>
                            <select id="modal_user_id" name="user_id" required class="form-select" onchange="updateUserType()">
                                <option value="">Select User</option>
                                <?php foreach ($active_users as $user): ?>
                                <?php 
                                $user_identifier = '';
                                if ($user['user_type'] == 'employee') {
                                    $user_identifier = $user['emp_id'] ?? '';
                                } elseif ($user['user_type'] == 'intern') {
                                    $user_identifier = $user['intern_id'] ?? '';
                                } elseif ($user['user_type'] == 'trainer') {
                                    $user_identifier = $user['trainer_id'] ?? '';
                                }
                                ?>
                                <option value="<?php echo $user['id']; ?>" data-type="<?php echo $user['user_type']; ?>">
                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'] . ' (' . $user_identifier . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" id="modal_user_type" name="user_type" value="">
                        </div>
                        
                        <div class="form-group">
                            <label for="modal_date"><i class="fas fa-calendar"></i> Date *</label>
                            <input type="date" id="modal_date" name="date" required 
                                   value="<?php echo date('Y-m-d'); ?>" class="form-input" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="modal_status"><i class="fas fa-check-circle"></i> Status *</label>
                            <select id="modal_status" name="status" required class="form-select" onchange="toggleTimeFields()">
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                                <option value="half_day">Half Day</option>
                                <option value="holiday">Holiday</option>
                                <option value="week_off">Week Off</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="timeFields" class="form-row">
                        <div class="form-group">
                            <label for="modal_check_in"><i class="fas fa-sign-in-alt"></i> Check In Time</label>
                            <input type="time" id="modal_check_in" name="check_in" class="form-input">
                        </div>
                        
                        <div class="form-group">
                            <label for="modal_check_out"><i class="fas fa-sign-out-alt"></i> Check Out Time</label>
                            <input type="time" id="modal_check_out" name="check_out" class="form-input">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group" style="flex: 1;">
                            <label for="modal_remarks"><i class="fas fa-comment"></i> Remarks</label>
                            <textarea id="modal_remarks" name="remarks" class="form-textarea" 
                                      placeholder="Optional remarks..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="cancel-btn" onclick="closeMarkAttendanceModal()">Cancel</button>
                    <button type="submit" class="submit-btn">
                        <i class="fas fa-save"></i> Save Attendance
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Attendance Modal -->
    <div id="viewAttendanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Attendance Details</h3>
                <button class="close-modal" onclick="closeViewAttendanceModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="view-details" id="attendanceDetails">
                    <!-- Details will be loaded via AJAX -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="cancel-btn" onclick="closeViewAttendanceModal()">Close</button>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openMarkAttendanceModal() {
            document.getElementById('markAttendanceModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
            toggleTimeFields();
        }
        
        function closeMarkAttendanceModal() {
            document.getElementById('markAttendanceModal').style.display = 'none';
            document.body.style.overflow = 'auto';
            document.getElementById('attendanceForm').reset();
            document.getElementById('modal_user_type').value = '';
        }
        
        function openViewAttendanceModal() {
            document.getElementById('viewAttendanceModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        
        function closeViewAttendanceModal() {
            document.getElementById('viewAttendanceModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function updateUserType() {
            const select = document.getElementById('modal_user_id');
            const selectedOption = select.options[select.selectedIndex];
            const userType = selectedOption.getAttribute('data-type');
            document.getElementById('modal_user_type').value = userType || '';
        }
        
        function toggleTimeFields() {
            const status = document.getElementById('modal_status').value;
            const timeFields = document.getElementById('timeFields');
            
            if (status === 'present' || status === 'half_day') {
                timeFields.style.display = 'flex';
                document.getElementById('modal_check_in').required = true;
            } else {
                timeFields.style.display = 'none';
                document.getElementById('modal_check_in').required = false;
                document.getElementById('modal_check_in').value = '';
                document.getElementById('modal_check_out').value = '';
            }
        }
        
        function toggleSelectAll(source) {
            const checkboxes = document.querySelectorAll('.select-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = source.checked;
            });
            updateBulkButton();
        }
        
        function updateBulkAction() {
            const select = document.getElementById('bulk_action_select');
            const actionInput = document.getElementById('bulk_action_input');
            actionInput.value = select.value;
            updateBulkButton();
        }
        
        function updateBulkButton() {
            const checkboxes = document.querySelectorAll('.select-checkbox:checked');
            const bulkBtn = document.getElementById('bulkSubmitBtn');
            const bulkAction = document.getElementById('bulk_action_input').value;
            
            if (checkboxes.length > 0 && bulkAction) {
                bulkBtn.disabled = false;
            } else {
                bulkBtn.disabled = true;
            }
        }
        
        function confirmBulkAction() {
            const action = document.getElementById('bulk_action_input').value;
            const count = document.querySelectorAll('.select-checkbox:checked').length;
            
            if (action === 'delete') {
                return confirm(`Are you sure you want to delete ${count} attendance record(s)?`);
            } else if (action === 'approve') {
                return confirm(`Are you sure you want to mark ${count} attendance record(s) as present?`);
            }
            return true;
        }
        
        function editAttendance(id) {
            window.location.href = `attendance_manage.php?edit=${id}`;
        }
        
        function viewAttendanceDetails(id) {
            document.getElementById('attendanceDetails').innerHTML = `
                <div class="loading">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Loading attendance details...</p>
                </div>
            `;
            
            openViewAttendanceModal();
            
            fetch(`attendance_manage.php?ajax=get_attendance_details&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayAttendanceDetails(data.data);
                    } else {
                        document.getElementById('attendanceDetails').innerHTML = `
                            <div class="alert error">
                                <i class="fas fa-exclamation-circle"></i> ${data.message}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('attendanceDetails').innerHTML = `
                        <div class="alert error">
                            <i class="fas fa-exclamation-circle"></i> Failed to load attendance details
                        </div>
                    `;
                });
        }
        
        function displayAttendanceDetails(record) {
            let fullName = '';
            let userId = '';
            
            if (record.user_type === 'employee') {
                fullName = (record.emp_first_name || '') + ' ' + (record.emp_last_name || '');
                userId = record.emp_id || 'N/A';
            } else if (record.user_type === 'intern') {
                fullName = (record.intern_first_name || '') + ' ' + (record.intern_last_name || '');
                userId = record.intern_id || 'N/A';
            } else if (record.user_type === 'trainer') {
                fullName = (record.trainer_first_name || '') + ' ' + (record.trainer_last_name || '');
                userId = record.trainer_id || 'N/A';
            }
            
            const date = new Date(record.date);
            const formattedDate = date.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            const checkIn = record.check_in ? 
                new Date('1970-01-01T' + record.check_in).toLocaleTimeString('en-US', {hour: '2-digit', minute:'2-digit'}) : 'Not recorded';
            const checkOut = record.check_out ? 
                new Date('1970-01-01T' + record.check_out).toLocaleTimeString('en-US', {hour: '2-digit', minute:'2-digit'}) : 'Not recorded';
            
            const lateMinutes = record.late_minutes > 0 ? 
                Math.floor(record.late_minutes / 60) + 'h ' + (record.late_minutes % 60) + 'm' : 'None';
            const overtimeMinutes = record.overtime_minutes > 0 ? 
                Math.floor(record.overtime_minutes / 60) + 'h ' + (record.overtime_minutes % 60) + 'm' : 'None';
            const earlyDeparture = record.early_departure_minutes > 0 ? 
                Math.floor(record.early_departure_minutes / 60) + 'h ' + (record.early_departure_minutes % 60) + 'm' : 'None';
            
            let workingHours = 'N/A';
            if (record.check_in && record.check_out) {
                const start = new Date('1970-01-01T' + record.check_in);
                const end = new Date('1970-01-01T' + record.check_out);
                const diffMs = end - start;
                const diffHrs = Math.floor(diffMs / 3600000);
                const diffMins = Math.floor((diffMs % 3600000) / 60000);
                workingHours = diffHrs + 'h ' + diffMins + 'm';
            }
            
            const createdAt = new Date(record.created_at).toLocaleString();
            const updatedAt = record.updated_at ? new Date(record.updated_at).toLocaleString() : 'Never';
            
            let statusClass = '';
            switch (record.status) {
                case 'present': statusClass = 'success'; break;
                case 'absent': statusClass = 'danger'; break;
                case 'half_day': statusClass = 'warning'; break;
                case 'holiday': statusClass = 'info'; break;
                case 'week_off': statusClass = 'secondary'; break;
                default: statusClass = 'secondary';
            }
            
            let userTypeClass = '';
            switch (record.user_type) {
                case 'employee': userTypeClass = 'employee'; break;
                case 'intern': userTypeClass = 'intern'; break;
                case 'trainer': userTypeClass = 'trainer'; break;
            }
            
            const html = `
                <div class="detail-row">
                    <div class="detail-label">Name:</div>
                    <div class="detail-value">${fullName}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">ID:</div>
                    <div class="detail-value">${userId}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">User Type:</div>
                    <div class="detail-value">
                        <span class="type-badge ${userTypeClass}">${record.user_type.charAt(0).toUpperCase() + record.user_type.slice(1)}</span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Date:</div>
                    <div class="detail-value">${formattedDate}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Status:</div>
                    <div class="detail-value">
                        <span class="status-badge ${statusClass}">${record.status.replace('_', ' ').toUpperCase()}</span>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Shift:</div>
                    <div class="detail-value">${record.shift_name || 'Not assigned'}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Check In:</div>
                    <div class="detail-value">${checkIn}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Check Out:</div>
                    <div class="detail-value">${checkOut}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Working Hours:</div>
                    <div class="detail-value">${workingHours}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Late Time:</div>
                    <div class="detail-value">${lateMinutes}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Overtime:</div>
                    <div class="detail-value">${overtimeMinutes}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Early Departure:</div>
                    <div class="detail-value">${earlyDeparture}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Remarks:</div>
                    <div class="detail-value">${record.remarks || 'No remarks'}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Created At:</div>
                    <div class="detail-value">${createdAt}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Updated At:</div>
                    <div class="detail-value">${updatedAt}</div>
                </div>
            `;
            
            document.getElementById('attendanceDetails').innerHTML = html;
        }
        
        function deleteAttendance(id) {
            if (confirm('Are you sure you want to delete this attendance record?')) {
                window.location.href = `attendance_manage.php?delete=${id}`;
            }
        }
        
        function exportFilteredToExcel() {
            const params = new URLSearchParams();
            params.append('export_attendance', '1');
            
            const month = document.getElementById('month').value;
            if (month) {
                params.append('month', month);
            } else {
                const now = new Date();
                const currentMonth = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
                params.append('month', currentMonth);
            }
            
            window.location.href = `attendance_manage.php?${params.toString()}`;
        }
        
        document.getElementById('bulkUploadForm')?.addEventListener('submit', function() {
            const uploadBtn = document.getElementById('uploadBtn');
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            uploadBtn.disabled = true;
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeMarkAttendanceModal();
                    closeViewAttendanceModal();
                }
            });
            
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        if (this.id === 'markAttendanceModal') {
                            closeMarkAttendanceModal();
                        } else if (this.id === 'viewAttendanceModal') {
                            closeViewAttendanceModal();
                        }
                    }
                });
            });
            
            <?php if (isset($_GET['edit']) && $edit_record): ?>
            setTimeout(() => {
                const userSelect = document.getElementById('modal_user_id');
                for (let i = 0; i < userSelect.options.length; i++) {
                    if (userSelect.options[i].value == '<?php echo $edit_record["user_id"]; ?>') {
                        userSelect.selectedIndex = i;
                        break;
                    }
                }
                document.getElementById('modal_user_type').value = '<?php echo $edit_record["user_type"]; ?>';
                document.getElementById('modal_date').value = '<?php echo $edit_record["date"]; ?>';
                document.getElementById('modal_status').value = '<?php echo $edit_record["status"]; ?>';
                <?php if ($edit_record["check_in"]): ?>
                document.getElementById('modal_check_in').value = '<?php echo date("H:i", strtotime($edit_record["check_in"])); ?>';
                <?php endif; ?>
                <?php if ($edit_record["check_out"]): ?>
                document.getElementById('modal_check_out').value = '<?php echo date("H:i", strtotime($edit_record["check_out"])); ?>';
                <?php endif; ?>
                document.getElementById('modal_remarks').value = '<?php echo addslashes($edit_record["remarks"] ?? ""); ?>';
                updateUserType();
                toggleTimeFields();
                openMarkAttendanceModal();
                
                const url = new URL(window.location);
                url.searchParams.delete('edit');
                window.history.replaceState({}, '', url);
            }, 100);
            <?php endif; ?>
            
            const bulkSelect = document.getElementById('bulk_action_select');
            if (bulkSelect) {
                bulkSelect.addEventListener('change', updateBulkAction);
            }
            
            const today = new Date().toISOString().split('T')[0];
            const modalDate = document.getElementById('modal_date');
            if (modalDate) modalDate.max = today;
            const filterDate = document.getElementById('date');
            if (filterDate) filterDate.max = today;
            
            const exportMonth = document.getElementById('export_month');
            if (exportMonth && !exportMonth.value) {
                const now = new Date();
                exportMonth.value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
            }
        });
    </script>
</body>
</html>
<?php
// End output buffering and send output
ob_end_flush();
?>