<?php
// payroll_manage.php - Payroll Management System
session_start();

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'emp_system');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Check if HR is logged in
if (!isset($_SESSION['hr_logged_in']) || $_SESSION['hr_logged_in'] !== true) {
    header("Location: login_hr.php");
    exit();
}

// Regenerate session ID periodically for security
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

$hr_id = $_SESSION['hr_id'] ?? 0;
$hr_email = $_SESSION['hr_email'] ?? 'hr@hrportal.com';

// Validate HR ID
if (!filter_var($hr_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    header("Location: login_hr.php");
    exit();
}

// ============ FUNCTION DEFINITIONS ============

/**
 * Calculate payroll for a user
 */
function calculatePayroll($user_id, $user_type, $month, $conn) {
    if (!is_numeric($user_id) || empty($user_type) || empty($month)) {
        return null;
    }
    
    // Get salary data
    $salary_data = getSalaryData($user_id, $user_type, $conn);
    
    if (!$salary_data || !isset($salary_data['base_amount']) || floatval($salary_data['base_amount']) <= 0) {
        return null;
    }
    
    // Get attendance for the month
    $start_date = date('Y-m-01', strtotime($month));
    $end_date = date('Y-m-t', strtotime($month));
    
    $attendance_query = "
        SELECT 
            COUNT(CASE WHEN status = 'present' THEN 1 END) as present_days,
            COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days,
            COUNT(CASE WHEN late_minutes > 0 THEN 1 END) as late_days,
            SUM(late_minutes) as total_late_minutes,
            SUM(overtime_minutes) as total_overtime_minutes
        FROM attendance 
        WHERE user_id = ? 
        AND user_type = ?
        AND date BETWEEN ? AND ?
    ";
    
    $stmt = $conn->prepare($attendance_query);
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("isss", $user_id, $user_type, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $attendance = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    
    if (!$attendance) {
        $attendance = [
            'present_days' => 0,
            'absent_days' => 0,
            'late_days' => 0,
            'total_late_minutes' => 0,
            'total_overtime_minutes' => 0
        ];
    }
    
    // Get leave count for the month
    $leave_query = "
        SELECT COUNT(*) as leave_count 
        FROM leave_requests 
        WHERE user_id = ? 
        AND user_type = ?
        AND status = 'approved'
        AND (
            (start_date BETWEEN ? AND ?)
            OR (end_date BETWEEN ? AND ?)
            OR (? BETWEEN start_date AND end_date)
        )
    ";
    
    $stmt = $conn->prepare($leave_query);
    if ($stmt) {
        $stmt->bind_param("issssss", $user_id, $user_type, $start_date, $end_date, $start_date, $end_date, $start_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $leave_data = $result ? $result->fetch_assoc() : null;
        $leave_count = $leave_data ? $leave_data['leave_count'] : 0;
        $stmt->close();
    } else {
        $leave_count = 0;
    }
    
    // Calculate earnings and deductions
    $base_salary = floatval($salary_data['base_amount']);
    $total_earnings = $base_salary;
    $total_deductions = 0;
    
    // Add allowances based on user type
    if ($user_type === 'employee') {
        // For employees
        $hra = $base_salary * 0.4; // HRA 40%
        $conveyance = 1600;
        $medical = 1250;
        $total_earnings += $hra + $conveyance + $medical;
        
        // Deductions
        $pf = $total_earnings * 0.12; // PF 12%
        $professional_tax = 200;
        $total_deductions += $pf + $professional_tax;
        
        // Simple TDS calculation (10% on earnings above 2.5L annually)
        $annual_earning = $total_earnings * 12;
        $tds = 0;
        if ($annual_earning > 250000) {
            $tds = ($annual_earning - 250000) * 0.10 / 12;
            $total_deductions += $tds;
        }
        
        // Store detailed breakdown
        $salary_data['hra'] = $hra;
        $salary_data['conveyance'] = $conveyance;
        $salary_data['medical'] = $medical;
        $salary_data['pf'] = $pf;
        $salary_data['professional_tax'] = $professional_tax;
        $salary_data['tds'] = $tds;
    } elseif ($user_type === 'intern') {
        // For interns - minimal deductions
        $intern_deduction = $total_earnings * 0.01; // 1% deduction
        $total_deductions += $intern_deduction;
        $salary_data['intern_deduction'] = $intern_deduction;
    } elseif ($user_type === 'trainer') {
        // For trainers
        $trainer_deduction = $total_earnings * 0.10; // 10% deduction
        $professional_tax = 200;
        $total_deductions += $trainer_deduction + $professional_tax;
        $salary_data['trainer_deduction'] = $trainer_deduction;
        $salary_data['professional_tax'] = $professional_tax;
    }
    
    // Adjust for attendance
    $working_days = 22; // Assuming 22 working days per month
    $actual_present_days = intval($attendance['present_days']);
    $attendance_factor = $working_days > 0 ? $actual_present_days / $working_days : 1;
    
    // Ensure attendance factor is between 0 and 1
    $attendance_factor = max(0, min(1, $attendance_factor));
    
    $total_earnings = $total_earnings * $attendance_factor;
    
    // Adjust deductions proportionally if earnings changed
    if ($attendance_factor < 1) {
        $total_deductions = $total_deductions * $attendance_factor;
    }
    
    // Calculate net salary
    $net_salary = $total_earnings - $total_deductions;
    
    // Overtime calculation
    $overtime_hours = floatval($attendance['total_overtime_minutes']) / 60;
    $overtime_pay = 0;
    if ($overtime_hours > 0) {
        $hourly_rate = $base_salary / (22 * 8); // Hourly rate based on 22 days * 8 hours
        $overtime_pay = $overtime_hours * $hourly_rate * 1.5; // 1.5x for overtime
        $net_salary += $overtime_pay;
    }
    
    // Ensure net salary is not negative
    $net_salary = max(0, $net_salary);
    
    return [
        'total_earnings' => round($total_earnings, 2),
        'total_deductions' => round($total_deductions, 2),
        'net_salary' => round($net_salary, 2),
        'days_present' => intval($attendance['present_days']),
        'days_absent' => intval($attendance['absent_days']),
        'late_count' => intval($attendance['late_days']),
        'leave_count' => intval($leave_count),
        'overtime_hours' => round($overtime_hours, 2),
        'overtime_pay' => round($overtime_pay, 2),
        'base_salary' => round($base_salary, 2),
        'working_days' => $working_days,
        'salary_details' => $salary_data
    ];
}

/**
 * Get salary data for a user
 */
function getSalaryData($user_id, $user_type, $conn) {
    if (!is_numeric($user_id) || empty($user_type)) {
        return null;
    }
    
    $user_id = intval($user_id);
    $base_amount = 0;
    $additional_data = [];
    
    if ($user_type === 'employee') {
        $sql = "SELECT salary, designation FROM employees WHERE user_id = ? AND employment_status = 'active'";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $base_amount = floatval($row['salary']);
                $additional_data = [
                    'designation' => $row['designation'],
                    'salary_type' => 'Monthly Salary'
                ];
            }
            $stmt->close();
        }
    } elseif ($user_type === 'intern') {
        $sql = "SELECT stipend_amount, course FROM interns WHERE user_id = ? AND internship_status = 'active'";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $base_amount = floatval($row['stipend_amount'] ?? 0);
                $additional_data = [
                    'course' => $row['course'],
                    'salary_type' => 'Stipend'
                ];
            }
            $stmt->close();
        }
    } elseif ($user_type === 'trainer') {
        $sql = "SELECT monthly_salary, hourly_rate, trainer_type, specialization FROM trainers WHERE user_id = ? AND employment_status = 'active'";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if ($row['trainer_type'] === 'full_time') {
                    $base_amount = floatval($row['monthly_salary'] ?? 0);
                    $salary_type = 'Monthly Salary';
                } else {
                    // For part-time/freelance, calculate based on assumed hours
                    $hourly_rate = floatval($row['hourly_rate'] ?? 0);
                    $base_amount = $hourly_rate * 160; // Assuming 160 hours/month
                    $salary_type = 'Hourly Rate (' . $hourly_rate . '/hr)';
                }
                $additional_data = [
                    'trainer_type' => $row['trainer_type'],
                    'specialization' => $row['specialization'],
                    'salary_type' => $salary_type
                ];
            }
            $stmt->close();
        }
    }
    
    return $base_amount > 0 ? array_merge(['base_amount' => $base_amount], $additional_data) : null;
}

/**
 * Get username by user ID and type
 */
function getUsername($user_id, $user_type, $conn) {
    if (!is_numeric($user_id) || empty($user_type)) {
        return 'Unknown User';
    }
    
    $user_id = intval($user_id);
    $name = 'Unknown User';
    
    if ($user_type === 'employee') {
        $sql = "SELECT CONCAT(first_name, ' ', last_name) as name FROM employees WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $name = $row['name'];
            }
            $stmt->close();
        }
    } elseif ($user_type === 'intern') {
        $sql = "SELECT CONCAT(first_name, ' ', last_name) as name FROM interns WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $name = $row['name'];
            }
            $stmt->close();
        }
    } elseif ($user_type === 'trainer') {
        $sql = "SELECT CONCAT(first_name, ' ', last_name) as name FROM trainers WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $name = $row['name'];
            }
            $stmt->close();
        }
    }
    
    return $name;
}

/**
 * Get users for payroll processing
 */
function getUsersForPayroll($conn, $search = '', $type = '') {
    $users = [];
    
    // Employees
    if (!$type || $type === 'employee') {
        $sql = "SELECT e.user_id, 'employee' as user_type, CONCAT(e.first_name, ' ', e.last_name) as name, 
                       e.email, e.designation, d.name as department, 
                       COALESCE(e.salary, 0) as base_amount,
                       e.emp_id as user_code
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                WHERE e.employment_status = 'active'";
        
        if ($search) {
            $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ? OR e.emp_id LIKE ?)";
            $search_param = "%$search%";
        } else {
            $search_param = "";
        }
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($search) {
                $stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $stmt->close();
        }
    }
    
    // Interns
    if (!$type || $type === 'intern') {
        $sql = "SELECT i.user_id, 'intern' as user_type, CONCAT(i.first_name, ' ', i.last_name) as name,
                       i.email, i.course as designation, d.name as department, 
                       COALESCE(i.stipend_amount, 0) as base_amount,
                       i.intern_id as user_code
                FROM interns i
                LEFT JOIN departments d ON i.department_id = d.id
                WHERE i.internship_status = 'active'";
        
        if ($search) {
            $sql .= " AND (i.first_name LIKE ? OR i.last_name LIKE ? OR i.email LIKE ? OR i.intern_id LIKE ?)";
            $search_param = "%$search%";
        }
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($search) {
                $stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $stmt->close();
        }
    }
    
    // Trainers
    if (!$type || $type === 'trainer') {
        $sql = "SELECT t.user_id, 'trainer' as user_type, CONCAT(t.first_name, ' ', t.last_name) as name,
                       t.email, t.specialization as designation, '' as department, 
                       COALESCE(t.monthly_salary, t.hourly_rate * 160, 0) as base_amount,
                       t.trainer_id as user_code
                FROM trainers t
                WHERE t.employment_status = 'active'";
        
        if ($search) {
            $sql .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR t.email LIKE ? OR t.trainer_id LIKE ?)";
            $search_param = "%$search%";
        }
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($search) {
                $stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $stmt->close();
        }
    }
    
    return $users;
}

/**
 * Get payroll records with proper filtering
 */
function getPayrollRecords($conn, $filter_month = '', $filter_status = '', $search = '') {
    $sql = "SELECT p.*, 
                   u.email,
                   CASE 
                       WHEN p.user_type = 'employee' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM employees WHERE user_id = p.user_id)
                       WHEN p.user_type = 'intern' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM interns WHERE user_id = p.user_id)
                       WHEN p.user_type = 'trainer' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM trainers WHERE user_id = p.user_id)
                   END as user_name,
                   CASE 
                       WHEN p.user_type = 'employee' THEN (SELECT designation FROM employees WHERE user_id = p.user_id)
                       WHEN p.user_type = 'intern' THEN (SELECT course FROM interns WHERE user_id = p.user_id)
                       WHEN p.user_type = 'trainer' THEN (SELECT specialization FROM trainers WHERE user_id = p.user_id)
                   END as user_designation,
                   CASE 
                       WHEN p.user_type = 'employee' THEN (SELECT emp_id FROM employees WHERE user_id = p.user_id)
                       WHEN p.user_type = 'intern' THEN (SELECT intern_id FROM interns WHERE user_id = p.user_id)
                       WHEN p.user_type = 'trainer' THEN (SELECT trainer_id FROM trainers WHERE user_id = p.user_id)
                   END as user_code
            FROM payroll p
            LEFT JOIN users u ON p.user_id = u.id
            WHERE 1=1";
    
    $params = [];
    $types = '';
    
    if ($filter_month) {
        $sql .= " AND p.payroll_month = ?";
        $params[] = $filter_month;
        $types .= "s";
    }
    
    if ($filter_status) {
        $sql .= " AND p.payment_status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
    
    if ($search) {
        $sql .= " AND (u.email LIKE ? OR 
                      p.user_id IN (SELECT user_id FROM employees WHERE CONCAT(first_name, ' ', last_name) LIKE ?) OR
                      p.user_id IN (SELECT user_id FROM interns WHERE CONCAT(first_name, ' ', last_name) LIKE ?) OR
                      p.user_id IN (SELECT user_id FROM trainers WHERE CONCAT(first_name, ' ', last_name) LIKE ?))";
        $search_param = "%$search%";
        for ($i = 0; $i < 4; $i++) {
            $params[] = $search_param;
            $types .= "s";
        }
    }
    
    $sql .= " ORDER BY p.payroll_month DESC, p.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($stmt && !empty($params)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } elseif ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }
    
    $payrolls = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $payrolls[] = $row;
        }
    }
    
    return $payrolls;
}

/**
 * Update payroll record with edited amounts
 */
function updatePayrollWithEditedAmounts($payroll_id, $edited_data, $conn) {
    // Check if the payroll table has the required columns
    $check_columns = $conn->query("SHOW COLUMNS FROM payroll LIKE 'base_salary'");
    
    if ($check_columns->num_rows > 0) {
        // Table has the extra columns
        $sql = "UPDATE payroll SET 
                total_earnings = ?,
                total_deductions = ?,
                net_salary = ?,
                base_salary = ?,
                overtime_pay = ?,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            // Calculate net salary
            $net_salary = $edited_data['total_earnings'] - $edited_data['total_deductions'];
            
            $stmt->bind_param("dddddi",
                $edited_data['total_earnings'],
                $edited_data['total_deductions'],
                $net_salary,
                $edited_data['base_salary'],
                $edited_data['overtime_pay'],
                $payroll_id
            );
            
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        }
    } else {
        // Table doesn't have the extra columns - use minimal update
        $sql = "UPDATE payroll SET 
                total_earnings = ?,
                total_deductions = ?,
                net_salary = ?,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            // Calculate net salary
            $net_salary = $edited_data['total_earnings'] - $edited_data['total_deductions'];
            
            $stmt->bind_param("dddi",
                $edited_data['total_earnings'],
                $edited_data['total_deductions'],
                $net_salary,
                $payroll_id
            );
            
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        }
    }
    
    return false;
}

// ============ MAIN LOGIC ============

// Initialize variables
$message = '';
$error = '';
$success = '';
$search = '';
$filter_month = '';
$filter_status = '';
$filter_type = '';

// CSRF token generation and validation
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper function for input sanitization
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// Handle form submissions with CSRF protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid CSRF token";
    } else {
        if (isset($_POST['process_payroll'])) {
            // Process payroll for selected users
            $selected_users = $_POST['selected_users'] ?? [];
            $payroll_month = $_POST['payroll_month'] ?? '';
            
            // Validate payroll month
            if (!preg_match('/^\d{4}-\d{2}$/', $payroll_month)) {
                $error = "Invalid payroll month format";
            } elseif (!empty($selected_users) && $payroll_month) {
                $processed_count = 0;
                $errors = [];
                
                foreach ($selected_users as $user_data) {
                    if (strpos($user_data, '_') !== false) {
                        list($user_id, $user_type) = explode('_', $user_data);
                        
                        // Validate user_id is numeric
                        if (is_numeric($user_id) && in_array($user_type, ['employee', 'intern', 'trainer'])) {
                            // Calculate payroll
                            $payroll_data = calculatePayroll($user_id, $user_type, $payroll_month, $conn);
                            
                            if ($payroll_data) {
                                // Check if payroll table has extra columns
                                $check_columns = $conn->query("SHOW COLUMNS FROM payroll LIKE 'base_salary'");
                                if ($check_columns->num_rows > 0) {
                                    // Table has extra columns
                                    $stmt = $conn->prepare("
                                        INSERT INTO payroll (payroll_month, user_id, user_type, total_earnings, total_deductions, net_salary, 
                                                           days_present, days_absent, late_count, leave_count, overtime_hours, 
                                                           overtime_pay, base_salary, working_days, payment_status)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'processed')
                                        ON DUPLICATE KEY UPDATE
                                        total_earnings = VALUES(total_earnings),
                                        total_deductions = VALUES(total_deductions),
                                        net_salary = VALUES(net_salary),
                                        days_present = VALUES(days_present),
                                        days_absent = VALUES(days_absent),
                                        late_count = VALUES(late_count),
                                        leave_count = VALUES(leave_count),
                                        overtime_hours = VALUES(overtime_hours),
                                        overtime_pay = VALUES(overtime_pay),
                                        base_salary = VALUES(base_salary),
                                        working_days = VALUES(working_days),
                                        payment_status = VALUES(payment_status),
                                        updated_at = CURRENT_TIMESTAMP
                                    ");
                                    
                                    if ($stmt) {
                                        $stmt->bind_param("sisddddiiiiddi", 
                                            $payroll_month,
                                            $user_id,
                                            $user_type,
                                            $payroll_data['total_earnings'],
                                            $payroll_data['total_deductions'],
                                            $payroll_data['net_salary'],
                                            $payroll_data['days_present'],
                                            $payroll_data['days_absent'],
                                            $payroll_data['late_count'],
                                            $payroll_data['leave_count'],
                                            $payroll_data['overtime_hours'],
                                            $payroll_data['overtime_pay'],
                                            $payroll_data['base_salary'],
                                            $payroll_data['working_days']
                                        );
                                        
                                        if ($stmt->execute()) {
                                            $processed_count++;
                                            
                                            // Create audit log with prepared statement
                                            $username = getUsername($user_id, $user_type, $conn);
                                            $action = "Payroll processed for " . $username . " - Month: " . date('F Y', strtotime($payroll_month));
                                            $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, created_at) VALUES (?, ?, NOW())");
                                            $audit_stmt->bind_param("is", $hr_id, $action);
                                            $audit_stmt->execute();
                                            $audit_stmt->close();
                                        } else {
                                            $errors[] = "Failed to process payroll for user ID: $user_id";
                                        }
                                        $stmt->close();
                                    }
                                } else {
                                    // Table doesn't have extra columns
                                    $stmt = $conn->prepare("
                                        INSERT INTO payroll (payroll_month, user_id, user_type, total_earnings, total_deductions, net_salary, 
                                                           days_present, days_absent, late_count, leave_count, overtime_hours, 
                                                           payment_status)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'processed')
                                        ON DUPLICATE KEY UPDATE
                                        total_earnings = VALUES(total_earnings),
                                        total_deductions = VALUES(total_deductions),
                                        net_salary = VALUES(net_salary),
                                        days_present = VALUES(days_present),
                                        days_absent = VALUES(days_absent),
                                        late_count = VALUES(late_count),
                                        leave_count = VALUES(leave_count),
                                        overtime_hours = VALUES(overtime_hours),
                                        payment_status = VALUES(payment_status),
                                        updated_at = CURRENT_TIMESTAMP
                                    ");
                                    
                                    if ($stmt) {
                                        $stmt->bind_param("sisddddiiii", 
                                            $payroll_month,
                                            $user_id,
                                            $user_type,
                                            $payroll_data['total_earnings'],
                                            $payroll_data['total_deductions'],
                                            $payroll_data['net_salary'],
                                            $payroll_data['days_present'],
                                            $payroll_data['days_absent'],
                                            $payroll_data['late_count'],
                                            $payroll_data['leave_count'],
                                            $payroll_data['overtime_hours']
                                        );
                                        
                                        if ($stmt->execute()) {
                                            $processed_count++;
                                            
                                            // Create audit log with prepared statement
                                            $username = getUsername($user_id, $user_type, $conn);
                                            $action = "Payroll processed for " . $username . " - Month: " . date('F Y', strtotime($payroll_month));
                                            $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, created_at) VALUES (?, ?, NOW())");
                                            $audit_stmt->bind_param("is", $hr_id, $action);
                                            $audit_stmt->execute();
                                            $audit_stmt->close();
                                        } else {
                                            $errors[] = "Failed to process payroll for user ID: $user_id";
                                        }
                                        $stmt->close();
                                    }
                                }
                            } else {
                                $errors[] = "Failed to calculate payroll for user ID: $user_id";
                            }
                        }
                    }
                }
                
                if ($processed_count > 0) {
                    $success = "Successfully processed payroll for $processed_count user(s) for " . date('F Y', strtotime($payroll_month));
                    if (!empty($errors)) {
                        $error .= " Some errors occurred: " . implode(", ", $errors);
                    }
                } else {
                    $error = "No payroll was processed. " . (!empty($errors) ? implode(", ", $errors) : "Please check user selection.");
                }
            } else {
                $error = "Please select users and specify payroll month";
            }
        } elseif (isset($_POST['mark_paid'])) {
            // Mark selected payrolls as paid
            $selected_payrolls = $_POST['selected_payrolls'] ?? [];
            $payment_date = $_POST['payment_date'] ?? '';
            $transaction_id = $_POST['transaction_id'] ?? '';
            $payroll_id = $_POST['payroll_id'] ?? '';
            
            // Determine if it's single or bulk payment
            if (!empty($payroll_id)) {
                $selected_payrolls = [$payroll_id];
            }
            
            if (!empty($selected_payrolls) && $payment_date) {
                // Validate payment date
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
                    $error = "Invalid payment date format";
                } else {
                    $paid_count = 0;
                    $errors = [];
                    
                    foreach ($selected_payrolls as $payroll_id) {
                        if (is_numeric($payroll_id)) {
                            $stmt = $conn->prepare("UPDATE payroll SET payment_status = 'paid', payment_date = ?, transaction_id = ? WHERE id = ?");
                            
                            if ($stmt) {
                                $stmt->bind_param("ssi", $payment_date, $transaction_id, $payroll_id);
                                
                                if ($stmt->execute()) {
                                    $paid_count++;
                                    
                                    // Get payroll details for audit log
                                    $payroll_result = $conn->query("SELECT user_id, user_type, payroll_month FROM payroll WHERE id = $payroll_id");
                                    if ($payroll_row = $payroll_result->fetch_assoc()) {
                                        $username = getUsername($payroll_row['user_id'], $payroll_row['user_type'], $conn);
                                        $action = "Payroll marked as paid for $username - Month: " . date('F Y', strtotime($payroll_row['payroll_month']));
                                        $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, created_at) VALUES (?, ?, NOW())");
                                        $audit_stmt->bind_param("is", $hr_id, $action);
                                        $audit_stmt->execute();
                                        $audit_stmt->close();
                                    }
                                } else {
                                    $errors[] = "Failed to mark payroll ID: $payroll_id as paid";
                                }
                                $stmt->close();
                            }
                        }
                    }
                    
                    if ($paid_count > 0) {
                        $success = "Successfully marked $paid_count payroll(s) as paid";
                        if (!empty($errors)) {
                            $error .= " Some errors occurred: " . implode(", ", $errors);
                        }
                    } else {
                        $error = "No payrolls were marked as paid. " . (!empty($errors) ? implode(", ", $errors) : "Please check selection.");
                    }
                }
            } else {
                $error = "Please select payrolls and specify payment date";
            }
        } elseif (isset($_POST['quick_process'])) {
            // Quick process all active users for current month
            $payroll_month = $_POST['payroll_month'] ?? date('Y-m');
            
            // Get all active users
            $users = getUsersForPayroll($conn, '', '');
            
            if (!empty($users)) {
                $processed_count = 0;
                $errors = [];
                
                foreach ($users as $user) {
                    // Calculate payroll
                    $payroll_data = calculatePayroll($user['user_id'], $user['user_type'], $payroll_month, $conn);
                    
                    if ($payroll_data) {
                        // Check if payroll table has extra columns
                        $check_columns = $conn->query("SHOW COLUMNS FROM payroll LIKE 'base_salary'");
                        if ($check_columns->num_rows > 0) {
                            // Table has extra columns
                            $stmt = $conn->prepare("
                                INSERT INTO payroll (payroll_month, user_id, user_type, total_earnings, total_deductions, net_salary, 
                                                   days_present, days_absent, late_count, leave_count, overtime_hours, 
                                                   overtime_pay, base_salary, working_days, payment_status)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'processed')
                                ON DUPLICATE KEY UPDATE
                                total_earnings = VALUES(total_earnings),
                                total_deductions = VALUES(total_deductions),
                                net_salary = VALUES(net_salary),
                                days_present = VALUES(days_present),
                                days_absent = VALUES(days_absent),
                                late_count = VALUES(late_count),
                                leave_count = VALUES(leave_count),
                                overtime_hours = VALUES(overtime_hours),
                                overtime_pay = VALUES(overtime_pay),
                                base_salary = VALUES(base_salary),
                                working_days = VALUES(working_days),
                                payment_status = VALUES(payment_status),
                                updated_at = CURRENT_TIMESTAMP
                            ");
                            
                            if ($stmt) {
                                $stmt->bind_param("sisddddiiiiddi", 
                                    $payroll_month,
                                    $user['user_id'],
                                    $user['user_type'],
                                    $payroll_data['total_earnings'],
                                    $payroll_data['total_deductions'],
                                    $payroll_data['net_salary'],
                                    $payroll_data['days_present'],
                                    $payroll_data['days_absent'],
                                    $payroll_data['late_count'],
                                    $payroll_data['leave_count'],
                                    $payroll_data['overtime_hours'],
                                    $payroll_data['overtime_pay'],
                                    $payroll_data['base_salary'],
                                    $payroll_data['working_days']
                                );
                                
                                if ($stmt->execute()) {
                                    $processed_count++;
                                } else {
                                    $errors[] = "Failed to process payroll for user: " . $user['name'];
                                }
                                $stmt->close();
                            }
                        } else {
                            // Table doesn't have extra columns
                            $stmt = $conn->prepare("
                                INSERT INTO payroll (payroll_month, user_id, user_type, total_earnings, total_deductions, net_salary, 
                                                   days_present, days_absent, late_count, leave_count, overtime_hours, 
                                                   payment_status)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'processed')
                                ON DUPLICATE KEY UPDATE
                                total_earnings = VALUES(total_earnings),
                                total_deductions = VALUES(total_deductions),
                                net_salary = VALUES(net_salary),
                                days_present = VALUES(days_present),
                                days_absent = VALUES(days_absent),
                                late_count = VALUES(late_count),
                                leave_count = VALUES(leave_count),
                                overtime_hours = VALUES(overtime_hours),
                                payment_status = VALUES(payment_status),
                                updated_at = CURRENT_TIMESTAMP
                            ");
                            
                            if ($stmt) {
                                $stmt->bind_param("sisddddiiii", 
                                    $payroll_month,
                                    $user['user_id'],
                                    $user['user_type'],
                                    $payroll_data['total_earnings'],
                                    $payroll_data['total_deductions'],
                                    $payroll_data['net_salary'],
                                    $payroll_data['days_present'],
                                    $payroll_data['days_absent'],
                                    $payroll_data['late_count'],
                                    $payroll_data['leave_count'],
                                    $payroll_data['overtime_hours']
                                );
                                
                                if ($stmt->execute()) {
                                    $processed_count++;
                                } else {
                                    $errors[] = "Failed to process payroll for user: " . $user['name'];
                                }
                                $stmt->close();
                            }
                        }
                    } else {
                        $errors[] = "Failed to calculate payroll for user: " . $user['name'];
                    }
                }
                
                if ($processed_count > 0) {
                    $success = "Successfully processed payroll for $processed_count user(s) for " . date('F Y', strtotime($payroll_month));
                    if (!empty($errors)) {
                        $error .= " Some errors occurred: " . implode(", ", $errors);
                    }
                    
                    // Create audit log
                    $action = "Quick payroll processing completed for " . date('F Y', strtotime($payroll_month)) . " - $processed_count users";
                    $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, created_at) VALUES (?, ?, NOW())");
                    $audit_stmt->bind_param("is", $hr_id, $action);
                    $audit_stmt->execute();
                    $audit_stmt->close();
                } else {
                    $error = "No payroll was processed. " . (!empty($errors) ? implode(", ", $errors) : "Please check user selection.");
                }
            } else {
                $error = "No active users found for payroll processing";
            }
        } elseif (isset($_POST['save_edited_payslip'])) {
            // Save edited payslip amounts
            $payroll_id = $_POST['payroll_id'] ?? '';
            
            if ($payroll_id) {
                // Get edited amounts
                $edited_data = [
                    'total_earnings' => floatval($_POST['total_earnings'] ?? 0),
                    'total_deductions' => floatval($_POST['total_deductions'] ?? 0),
                    'base_salary' => floatval($_POST['base_salary'] ?? 0),
                    'overtime_pay' => floatval($_POST['overtime_pay'] ?? 0)
                ];
                
                // Get user_id from payroll record
                $user_id = 0;
                $result = $conn->query("SELECT user_id FROM payroll WHERE id = $payroll_id");
                if ($row = $result->fetch_assoc()) {
                    $user_id = $row['user_id'];
                }
                
                // Update payroll record
                if (updatePayrollWithEditedAmounts($payroll_id, $edited_data, $conn)) {
                    $success = "Payroll amounts updated successfully";
                    
                    // Create audit log
                    $action = "Payroll amounts edited for payroll ID: $payroll_id";
                    $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, created_at) VALUES (?, ?, NOW())");
                    $audit_stmt->bind_param("is", $hr_id, $action);
                    $audit_stmt->execute();
                    $audit_stmt->close();
                } else {
                    $error = "Failed to update payroll amounts";
                }
            } else {
                $error = "Invalid payroll ID";
            }
        }
    }
}

// Handle GET parameters for filtering
if (isset($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
}
if (isset($_GET['filter_month'])) {
    $filter_month = $_GET['filter_month'];
}
if (isset($_GET['filter_status'])) {
    $filter_status = $_GET['filter_status'];
}
if (isset($_GET['filter_type'])) {
    $filter_type = $_GET['filter_type'];
}

// Get distinct payroll months for filter
$months_result = $conn->query("SELECT DISTINCT payroll_month FROM payroll ORDER BY payroll_month DESC");
$payroll_months = [];
if ($months_result) {
    while ($row = $months_result->fetch_assoc()) {
        $payroll_months[] = $row['payroll_month'];
    }
}

// Get statistics
$total_payrolls = 0;
$pending_payrolls = 0;
$processed_payrolls = 0;
$paid_payrolls = 0;
$total_amount = 0;

$result = $conn->query("SELECT COUNT(*) as total FROM payroll");
if ($result) {
    $total_payrolls = $result->fetch_assoc()['total'] ?? 0;
}

$result = $conn->query("SELECT COUNT(*) as total FROM payroll WHERE payment_status = 'pending'");
if ($result) {
    $pending_payrolls = $result->fetch_assoc()['total'] ?? 0;
}

$result = $conn->query("SELECT COUNT(*) as total FROM payroll WHERE payment_status = 'processed'");
if ($result) {
    $processed_payrolls = $result->fetch_assoc()['total'] ?? 0;
}

$result = $conn->query("SELECT COUNT(*) as total FROM payroll WHERE payment_status = 'paid'");
if ($result) {
    $paid_payrolls = $result->fetch_assoc()['total'] ?? 0;
}

$result = $conn->query("SELECT SUM(net_salary) as total FROM payroll WHERE payment_status = 'paid'");
if ($result) {
    $total_amount = $result->fetch_assoc()['total'] ?? 0;
}

// Get users for payroll processing
$users = getUsersForPayroll($conn, $search, $filter_type);

// Get payroll records
$payrolls = getPayrollRecords($conn, $filter_month, $filter_status, $search);

// Get pending payrolls for bulk actions
$pending_payrolls_list = [];
$pending_query = $conn->query("
    SELECT p.*, u.email,
           CASE 
               WHEN p.user_type = 'employee' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM employees WHERE user_id = p.user_id)
               WHEN p.user_type = 'intern' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM interns WHERE user_id = p.user_id)
               WHEN p.user_type = 'trainer' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM trainers WHERE user_id = p.user_id)
           END as user_name,
           CASE 
               WHEN p.user_type = 'employee' THEN (SELECT emp_id FROM employees WHERE user_id = p.user_id)
               WHEN p.user_type = 'intern' THEN (SELECT intern_id FROM interns WHERE user_id = p.user_id)
               WHEN p.user_type = 'trainer' THEN (SELECT trainer_id FROM trainers WHERE user_id = p.user_id)
           END as user_code
    FROM payroll p
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.payment_status IN ('pending', 'processed')
    ORDER BY p.payroll_month DESC
    LIMIT 50
");

if ($pending_query) {
    while ($row = $pending_query->fetch_assoc()) {
        $pending_payrolls_list[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Management - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* CSS remains exactly the same – unchanged */
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        
        .stat-icon.payroll-total { background: linear-gradient(135deg, var(--primary) 0%, var(--info) 100%); }
        .stat-icon.pending { background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%); }
        .stat-icon.processed { background: linear-gradient(135deg, var(--success) 0%, #34d399 100%); }
        .stat-icon.paid { background: linear-gradient(135deg, #10b981 0%, #34d399 100%); }
        
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
        
        .tabs {
            display: flex;
            background: white;
            border-radius: 12px;
            padding: 5px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .tab {
            flex: 1;
            padding: 15px 20px;
            text-align: center;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
            color: var(--gray);
        }
        
        .tab.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 5px 15px rgba(79, 70, 229, 0.2);
        }
        
        .content-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .card-header h3 {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .filter-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 200px;
        }
        
        .filter-label {
            font-size: 12px;
            color: var(--gray);
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .filter-input, .filter-select {
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .filter-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            align-self: flex-end;
        }
        
        .filter-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
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
        
        .checkbox-cell {
            width: 50px;
            text-align: center;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.processed { background: #dbeafe; color: #1e40af; }
        .status-badge.paid { background: #d1fae5; color: #065f46; }
        .status-badge.failed { background: #fee2e2; color: #991b1b; }
        
        .type-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            display: inline-block;
        }
        
        .type-badge.employee { background: #e0e7ff; color: #3730a3; }
        .type-badge.intern { background: #f0fdf4; color: #166534; }
        .type-badge.trainer { background: #fdf4ff; color: #86198f; }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .action-btn {
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.3s ease;
        }
        
        .action-btn.primary {
            background: var(--primary);
            color: white;
        }
        
        .action-btn.primary:hover {
            background: var(--primary-dark);
        }
        
        .action-btn.success {
            background: var(--success);
            color: white;
        }
        
        .action-btn.success:hover {
            background: #0da271;
        }
        
        .action-btn.warning {
            background: var(--warning);
            color: white;
        }
        
        .action-btn.warning:hover {
            background: #d97706;
        }
        
        .action-btn.info {
            background: var(--info);
            color: white;
        }
        
        .action-btn.info:hover {
            background: #2563eb;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }
        
        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .amount {
            font-weight: 600;
            color: var(--dark);
        }
        
        .amount.positive {
            color: var(--success);
        }
        
        .bulk-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            align-items: center;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 16px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .edit-form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 20px;
        }
        
        .edit-form-group {
            margin-bottom: 15px;
        }
        
        .edit-form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark);
            font-size: 14px;
        }
        
        .edit-form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
        }
        
        .edit-form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .amount-preview {
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            margin: 15px 0;
            border: 1px solid #e2e8f0;
        }
        
        .amount-preview-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .amount-preview-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
            font-weight: bold;
            font-size: 16px;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-bar {
                flex-direction: column;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .edit-form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .main-content {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php 
    if (file_exists('sidebar_hr.php')) {
        include 'sidebar_hr.php'; 
    } else {
        echo '<div style="position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: white; padding: 20px; box-shadow: 2px 0 10px rgba(0,0,0,0.1);">
            <h3>HR Portal</h3>
            <ul style="list-style: none; padding: 0; margin-top: 30px;">
                <li><a href="hr_dashboard.php" style="display: block; padding: 10px; text-decoration: none; color: var(--dark);">Dashboard</a></li>
                <li><a href="payroll_manage.php" style="display: block; padding: 10px; text-decoration: none; color: var(--primary); font-weight: bold;">Payroll</a></li>
                <li><a href="emp_manage.php" style="display: block; padding: 10px; text-decoration: none; color: var(--dark);">Employees</a></li>
                <li><a href="logout.php" style="display: block; padding: 10px; text-decoration: none; color: var(--danger);">Logout</a></li>
            </ul>
        </div>';
    }
    ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-money-check-alt"></i> Payroll Management</h1>
                <p id="currentDateTime">Manage and process payroll for employees, interns, and trainers</p>
            </div>
            
            <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div class="user-profile" style="min-width: 250px;">
                    <div class="user-avatar" style="background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);">
                        HR
                    </div>
                    <div class="user-info">
                        <h4>HR Manager</h4>
                        <span><?php echo htmlspecialchars($hr_email); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon payroll-total">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $total_payrolls; ?></h3>
                    <p>Total Payrolls</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon pending">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $pending_payrolls; ?></h3>
                    <p>Pending</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon processed">
                    <i class="fas fa-cogs"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $processed_payrolls; ?></h3>
                    <p>Processed</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon paid">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3>₹<?php echo number_format($total_amount, 2); ?></h3>
                    <p>Total Paid Amount</p>
                </div>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="showTab('process')">
                <i class="fas fa-calculator"></i> Process Payroll
            </div>
            <div class="tab" onclick="showTab('records')">
                <i class="fas fa-history"></i> Payroll Records
            </div>
            <div class="tab" onclick="showTab('bulk')">
                <i class="fas fa-bolt"></i> Bulk Actions
            </div>
        </div>
        
        <!-- Messages -->
        <?php if ($success): ?>
        <div class="alert success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>
        
        <!-- Tab 1: Process Payroll -->
        <div id="processTab" class="tab-content">
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-calculator"></i> Process New Payroll</h3>
                </div>
                
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" action="" style="display: contents;">
                        <div class="filter-group">
                            <div class="filter-label">Search Users</div>
                            <input type="text" name="search" class="filter-input" placeholder="Search by name, email, or ID..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <div class="filter-label">User Type</div>
                            <select name="filter_type" class="filter-select">
                                <option value="">All Types</option>
                                <option value="employee" <?php echo $filter_type === 'employee' ? 'selected' : ''; ?>>Employees</option>
                                <option value="intern" <?php echo $filter_type === 'intern' ? 'selected' : ''; ?>>Interns</option>
                                <option value="trainer" <?php echo $filter_type === 'trainer' ? 'selected' : ''; ?>>Trainers</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="filter-btn">
                            <i class="fas fa-search"></i> Search
                        </button>
                        
                        <a href="payroll_manage.php" class="filter-btn" style="background: var(--gray);">
                            <i class="fas fa-redo"></i> Clear
                        </a>
                    </form>
                </div>
                
                <!-- Process Payroll Form -->
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Payroll Month</label>
                        <input type="month" name="payroll_month" class="form-input" required 
                               value="<?php echo date('Y-m'); ?>">
                    </div>
                    
                    <!-- Users Table -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="checkbox-cell">
                                        <input type="checkbox" id="selectAll">
                                    </th>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Designation/Department</th>
                                    <th>Base Amount</th>
                                    <th>Email</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px; color: var(--gray);">
                                        <i class="fas fa-users" style="font-size: 48px; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                                        No users found matching your criteria
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td class="checkbox-cell">
                                        <input type="checkbox" name="selected_users[]" 
                                               value="<?php echo $user['user_id'] . '_' . $user['user_type']; ?>">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                        <div style="font-size: 12px; color: var(--gray);">
                                            <?php echo htmlspecialchars($user['user_code']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="type-badge <?php echo $user['user_type']; ?>">
                                            <?php echo ucfirst($user['user_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($user['designation']); ?>
                                        <?php if (!empty($user['department'])): ?>
                                        <div style="font-size: 12px; color: var(--gray);">
                                            <?php echo htmlspecialchars($user['department']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="amount">
                                        ₹<?php echo number_format($user['base_amount'], 2); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($user['email']); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if (!empty($users)): ?>
                    <div style="margin-top: 25px; display: flex; justify-content: flex-end;">
                        <button type="submit" name="process_payroll" class="action-btn primary" style="padding: 12px 30px;">
                            <i class="fas fa-calculator"></i> Process Selected Payroll
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <!-- Tab 2: Payroll Records -->
        <div id="recordsTab" class="tab-content" style="display: none;">
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Payroll Records</h3>
                    <div>
                        <button onclick="exportToExcel()" class="action-btn success">
                            <i class="fas fa-file-excel"></i> Export
                        </button>
                    </div>
                </div>
                
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" action="" style="display: contents;">
                        <div class="filter-group">
                            <div class="filter-label">Search</div>
                            <input type="text" name="search" class="filter-input" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <div class="filter-label">Month</div>
                            <select name="filter_month" class="filter-select">
                                <option value="">All Months</option>
                                <?php foreach ($payroll_months as $month): ?>
                                <option value="<?php echo $month; ?>" <?php echo $filter_month === $month ? 'selected' : ''; ?>>
                                    <?php echo date('F Y', strtotime($month)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <div class="filter-label">Status</div>
                            <select name="filter_status" class="filter-select">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="processed" <?php echo $filter_status === 'processed' ? 'selected' : ''; ?>>Processed</option>
                                <option value="paid" <?php echo $filter_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="filter-btn">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                    </form>
                </div>
                
                <!-- Payroll Records Table -->
                <div class="table-container">
                    <table class="data-table" id="payrollTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Type</th>
                                <th>Month</th>
                                <th>Earnings</th>
                                <th>Deductions</th>
                                <th>Net Salary</th>
                                <th>Days Present</th>
                                <th>Status</th>
                                <th>Payment Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payrolls)): ?>
                            <tr>
                                <td colspan="11" style="text-align: center; padding: 40px; color: var(--gray);">
                                    <i class="fas fa-file-invoice-dollar" style="font-size: 48px; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                                    No payroll records found
                                </td>
                                </tr>
                            <?php else: ?>
                            <?php foreach ($payrolls as $payroll): ?>
                            <tr>
                                <td>#<?php echo str_pad($payroll['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($payroll['user_name'] ?? 'N/A'); ?></strong>
                                    <div style="font-size: 12px; color: var(--gray);">
                                        <?php echo htmlspecialchars($payroll['email']); ?>
                                        <br>
                                        ID: <?php echo htmlspecialchars($payroll['user_code'] ?? 'N/A'); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="type-badge <?php echo $payroll['user_type']; ?>">
                                        <?php echo ucfirst($payroll['user_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('F Y', strtotime($payroll['payroll_month'])); ?>
                                </td>
                                <td class="amount positive">
                                    ₹<?php echo number_format($payroll['total_earnings'], 2); ?>
                                </td>
                                <td class="amount">
                                    ₹<?php echo number_format($payroll['total_deductions'], 2); ?>
                                </td>
                                <td class="amount positive" style="font-weight: 700;">
                                    ₹<?php echo number_format($payroll['net_salary'], 2); ?>
                                </td>
                                <td>
                                    <?php echo $payroll['days_present']; ?> days
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $payroll['payment_status']; ?>">
                                        <?php echo ucfirst($payroll['payment_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo $payroll['payment_date'] ? date('d M Y', strtotime($payroll['payment_date'])) : '-'; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($payroll['payment_status'] === 'processed' || $payroll['payment_status'] === 'paid'): ?>
                                        <button onclick="editPayslip(<?php echo $payroll['id']; ?>, '<?php echo htmlspecialchars($payroll['user_name'] ?? 'N/A'); ?>', <?php echo $payroll['total_earnings']; ?>, <?php echo $payroll['total_deductions']; ?>, <?php echo $payroll['total_earnings'] * 0.6; ?>, <?php echo $payroll['overtime_hours'] * 100; ?>)" class="action-btn info">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <!-- Link to generate_payslip.php with proper parameters -->
                                        <a href="generate_payslip.php?user_id=<?php echo $payroll['user_id']; ?>&user_type=<?php echo $payroll['user_type']; ?>&year=<?php echo date('Y', strtotime($payroll['payroll_month'])); ?>&month=<?php echo date('m', strtotime($payroll['payroll_month'])); ?>" class="action-btn success">
                                            <i class="fas fa-file-invoice"></i> Payslip
                                        </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($payroll['payment_status'] !== 'paid'): ?>
                                        <button onclick="markAsPaid(<?php echo $payroll['id']; ?>)" class="action-btn primary">
                                            <i class="fas fa-check"></i> Pay
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Summary -->
                <?php if (!empty($payrolls)): ?>
                <div style="margin-top: 30px; padding: 20px; background: #f8fafc; border-radius: 12px;">
                    <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                        <div>
                            <div style="font-size: 12px; color: var(--gray);">Total Records</div>
                            <div style="font-size: 24px; font-weight: bold;"><?php echo count($payrolls); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: var(--gray);">Total Earnings</div>
                            <div style="font-size: 24px; font-weight: bold; color: var(--success);">
                                ₹<?php echo number_format(array_sum(array_column($payrolls, 'total_earnings')), 2); ?>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: var(--gray);">Total Deductions</div>
                            <div style="font-size: 24px; font-weight: bold;">
                                ₹<?php echo number_format(array_sum(array_column($payrolls, 'total_deductions')), 2); ?>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: var(--gray);">Total Net Salary</div>
                            <div style="font-size: 24px; font-weight: bold; color: var(--success);">
                                ₹<?php echo number_format(array_sum(array_column($payrolls, 'net_salary')), 2); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Tab 3: Bulk Actions -->
        <div id="bulkTab" class="tab-content" style="display: none;">
            <div class="content-card">
                <div class="card-header">
                    <h3><i class="fas fa-bolt"></i> Bulk Payroll Actions</h3>
                </div>
                
                <!-- Bulk Mark as Paid -->
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <h4 style="margin-bottom: 20px; color: var(--dark);">Mark Multiple Payrolls as Paid</h4>
                    
                    <div class="form-group">
                        <label class="form-label">Select Payrolls to Mark as Paid</label>
                        <div class="table-container" style="max-height: 300px; overflow-y: auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th class="checkbox-cell">
                                            <input type="checkbox" id="selectAllPaid">
                                        </th>
                                        <th>ID</th>
                                        <th>User</th>
                                        <th>Type</th>
                                        <th>Month</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($pending_payrolls_list)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 40px; color: var(--gray);">
                                            No pending payrolls found
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($pending_payrolls_list as $payroll): ?>
                                    <tr>
                                        <td class="checkbox-cell">
                                            <input type="checkbox" name="selected_payrolls[]" value="<?php echo $payroll['id']; ?>">
                                        </td>
                                        <td>#<?php echo str_pad($payroll['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($payroll['user_name'] ?? 'N/A'); ?>
                                            <div style="font-size: 11px; color: var(--gray);">
                                                <?php echo htmlspecialchars($payroll['user_code'] ?? ''); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="type-badge <?php echo $payroll['user_type']; ?>">
                                                <?php echo ucfirst($payroll['user_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('F Y', strtotime($payroll['payroll_month'])); ?></td>
                                        <td class="amount positive">₹<?php echo number_format($payroll['net_salary'], 2); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $payroll['payment_status']; ?>">
                                                <?php echo ucfirst($payroll['payment_status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Payment Date</label>
                        <input type="date" name="payment_date" class="form-input" required 
                               value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Transaction ID (Optional)</label>
                        <input type="text" name="transaction_id" class="form-input" 
                               placeholder="Enter transaction reference...">
                    </div>
                    
                    <div style="margin-top: 25px;">
                        <button type="submit" name="mark_paid" class="action-btn primary" style="padding: 12px 30px;">
                            <i class="fas fa-check-circle"></i> Mark Selected as Paid
                        </button>
                    </div>
                </form>
                
                <hr style="margin: 40px 0; border: none; border-top: 1px solid #e2e8f0;">
                
                <!-- Quick Process Current Month -->
                <div>
                    <h4 style="margin-bottom: 20px; color: var(--dark);">Quick Process - Current Month</h4>
                    <p style="color: var(--gray); margin-bottom: 20px;">
                        Process payroll for all active users for <?php echo date('F Y'); ?>
                    </p>
                    
                    <form method="POST" action="" id="quickProcessForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="payroll_month" value="<?php echo date('Y-m'); ?>">
                        
                        <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                            <div style="padding: 15px; background: #f0f9ff; border-radius: 8px; border: 1px solid #bae6fd;">
                                <strong>Will process all active users</strong><br>
                                <small style="color: var(--gray);">Employees, Interns, and Trainers with active status</small>
                            </div>
                            
                            <button type="submit" name="quick_process" class="action-btn warning" style="padding: 12px 30px;">
                                <i class="fas fa-bolt"></i> Quick Process Current Month
                            </button>
                        </div>
                    </form>
                </div>
                
                <hr style="margin: 40px 0; border: none; border-top: 1px solid #e2e8f0;">
                
                <!-- Bulk Delete -->
                <div>
                    <h4 style="margin-bottom: 20px; color: var(--dark);">Bulk Delete Payroll Records</h4>
                    <p style="color: var(--gray); margin-bottom: 20px; font-size: 14px;">
                        <i class="fas fa-exclamation-triangle" style="color: var(--warning);"></i>
                        Warning: This action cannot be undone. Only delete payroll records that were created in error.
                    </p>
                    
                    <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                        <div class="filter-group">
                            <div class="filter-label">Select Month to Delete</div>
                            <select id="deleteMonth" class="filter-select">
                                <option value="">Select Month</option>
                                <?php foreach ($payroll_months as $month): ?>
                                <option value="<?php echo $month; ?>">
                                    <?php echo date('F Y', strtotime($month)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <div class="filter-label">Status to Delete</div>
                            <select id="deleteStatus" class="filter-select">
                                <option value="pending">Pending Only</option>
                                <option value="all">All Status</option>
                            </select>
                        </div>
                        
                        <button onclick="confirmBulkDelete()" class="action-btn" style="background: var(--danger); color: white;">
                            <i class="fas fa-trash"></i> Delete Records
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Mark as Paid Modal -->
    <div id="markPaidModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;">Mark Payroll as Paid</h3>
            <form method="POST" action="" id="markPaidForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" id="modalPayrollId" name="payroll_id">
                
                <div class="form-group">
                    <label class="form-label">Payment Date</label>
                    <input type="date" name="payment_date" class="form-input" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Transaction ID (Optional)</label>
                    <input type="text" name="transaction_id" class="form-input" placeholder="Enter transaction reference...">
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 25px;">
                    <button type="button" onclick="closeModal()" class="action-btn" style="background: var(--gray); color: white;">
                        Cancel
                    </button>
                    <button type="submit" name="mark_paid" class="action-btn primary">
                        <i class="fas fa-check"></i> Mark as Paid
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Payslip Modal -->
    <div id="editPayslipModal" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px;" id="editModalTitle">Edit Payslip Amounts</h3>
            <form method="POST" action="" id="editPayslipForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" id="editPayrollId" name="payroll_id">
                
                <div class="edit-form-grid">
                    <div class="edit-form-group">
                        <label class="edit-form-label">Total Earnings (₹)</label>
                        <input type="number" step="0.01" id="editTotalEarnings" name="total_earnings" class="edit-form-input" required>
                    </div>
                    
                    <div class="edit-form-group">
                        <label class="edit-form-label">Total Deductions (₹)</label>
                        <input type="number" step="0.01" id="editTotalDeductions" name="total_deductions" class="edit-form-input" required>
                    </div>
                    
                    <div class="edit-form-group">
                        <label class="edit-form-label">Basic Salary (₹)</label>
                        <input type="number" step="0.01" id="editBaseSalary" name="base_salary" class="edit-form-input" required>
                    </div>
                    
                    <div class="edit-form-group">
                        <label class="edit-form-label">Overtime Pay (₹)</label>
                        <input type="number" step="0.01" id="editOvertimePay" name="overtime_pay" class="edit-form-input" required>
                    </div>
                </div>
                
                <div class="amount-preview">
                    <div class="amount-preview-row">
                        <span>Total Earnings:</span>
                        <span id="previewEarnings">₹0.00</span>
                    </div>
                    <div class="amount-preview-row">
                        <span>Total Deductions:</span>
                        <span id="previewDeductions">₹0.00</span>
                    </div>
                    <div class="amount-preview-row">
                        <span>Net Salary:</span>
                        <span id="previewNetSalary" style="color: var(--success);">₹0.00</span>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 25px;">
                    <button type="button" onclick="closeEditModal()" class="action-btn" style="background: var(--gray); color: white;">
                        Cancel
                    </button>
                    <button type="submit" name="save_edited_payslip" class="action-btn success">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <!-- The "Save & Generate" button has been removed -->
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Tab functionality
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById(tabName + 'Tab').style.display = 'block';
            event.currentTarget.classList.add('active');
            sessionStorage.setItem('activePayrollTab', tabName);
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const activeTab = sessionStorage.getItem('activePayrollTab') || 'process';
            const tabElement = document.querySelector(`.tab[onclick="showTab('${activeTab}')]`);
            if (tabElement) tabElement.click();
        });
        
        // Checkbox select all
        document.getElementById('selectAll')?.addEventListener('change', function() {
            document.querySelectorAll('#processTab input[name="selected_users[]"]').forEach(cb => cb.checked = this.checked);
        });
        document.getElementById('selectAllPaid')?.addEventListener('change', function() {
            document.querySelectorAll('#bulkTab input[name="selected_payrolls[]"]').forEach(cb => cb.checked = this.checked);
        });
        
        // Modal functions
        function markAsPaid(payrollId) {
            document.getElementById('modalPayrollId').value = payrollId;
            document.getElementById('markPaidModal').style.display = 'flex';
        }
        function closeModal() {
            document.getElementById('markPaidModal').style.display = 'none';
        }
        
        function editPayslip(payrollId, userName, totalEarnings, totalDeductions, baseSalary, overtimePay) {
            document.getElementById('editPayrollId').value = payrollId;
            document.getElementById('editModalTitle').textContent = `Edit Payslip - ${userName}`;
            document.getElementById('editTotalEarnings').value = totalEarnings;
            document.getElementById('editTotalDeductions').value = totalDeductions;
            document.getElementById('editBaseSalary').value = baseSalary;
            document.getElementById('editOvertimePay').value = overtimePay;
            updatePayslipPreview();
            document.getElementById('editPayslipModal').style.display = 'flex';
        }
        function closeEditModal() {
            document.getElementById('editPayslipModal').style.display = 'none';
        }
        function updatePayslipPreview() {
            let earnings = parseFloat(document.getElementById('editTotalEarnings').value) || 0;
            let deductions = parseFloat(document.getElementById('editTotalDeductions').value) || 0;
            let net = earnings - deductions;
            document.getElementById('previewEarnings').textContent = '₹' + earnings.toFixed(2);
            document.getElementById('previewDeductions').textContent = '₹' + deductions.toFixed(2);
            document.getElementById('previewNetSalary').textContent = '₹' + net.toFixed(2);
        }
        document.getElementById('editTotalEarnings')?.addEventListener('input', updatePayslipPreview);
        document.getElementById('editTotalDeductions')?.addEventListener('input', updatePayslipPreview);
        
        // Close modals when clicking outside
        document.getElementById('markPaidModal')?.addEventListener('click', e => { if (e.target === this) closeModal(); });
        document.getElementById('editPayslipModal')?.addEventListener('click', e => { if (e.target === this) closeEditModal(); });
        
        // Date/time update
        function updateDateTime() {
            let now = new Date();
            let time = now.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
            let date = now.toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
            let el = document.getElementById('currentDateTime');
            if (el) el.innerHTML = `Manage and process payroll for employees, interns, and trainers | ${date} ${time}`;
        }
        updateDateTime();
        setInterval(updateDateTime, 60000);
        
        // Export to Excel
        function exportToExcel() {
            let table = document.getElementById('payrollTable');
            let csv = [];
            let headers = [];
            table.querySelectorAll('thead th').forEach(th => headers.push(th.innerText));
            csv.push(headers.join(','));
            table.querySelectorAll('tbody tr').forEach(row => {
                let rowData = [];
                row.querySelectorAll('td').forEach(cell => {
                    if (!cell.querySelector('.action-buttons') && !cell.querySelector('.status-badge') && !cell.querySelector('.type-badge')) {
                        rowData.push(cell.innerText.replace(/,/g, ''));
                    } else {
                        rowData.push(cell.innerText.replace(/\n/g, ' ').replace(/,/g, ''));
                    }
                });
                csv.push(rowData.join(','));
            });
            let blob = new Blob([csv.join('\n')], { type: 'text/csv' });
            let url = window.URL.createObjectURL(blob);
            let a = document.createElement('a');
            a.href = url;
            a.download = 'payroll_records_' + new Date().toISOString().slice(0,10) + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
        
        // Bulk delete confirmation
        function confirmBulkDelete() {
            let month = document.getElementById('deleteMonth').value;
            let status = document.getElementById('deleteStatus').value;
            if (!month) { alert('Please select a month to delete'); return; }
            let monthName = new Date(month + '-01').toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            let statusText = status === 'all' ? 'all statuses' : 'pending only';
            if (confirm(`Delete payroll records for ${monthName} (${statusText})?\n\nThis cannot be undone!`)) {
                alert('Bulk delete would be implemented server-side.');
                document.getElementById('deleteMonth').value = '';
                document.getElementById('deleteStatus').value = 'pending';
            }
        }
        
        // Quick process confirmation
        document.getElementById('quickProcessForm')?.addEventListener('submit', function(e) {
            if (!confirm('Process payroll for ALL active users this month?\n\nThis may take a moment.')) e.preventDefault();
        });
        
        // Auto‑clear messages after 5 seconds
        setTimeout(() => {
            if (document.querySelector('.alert.success') || document.querySelector('.alert.error')) {
                window.location.href = window.location.pathname;
            }
        }, 5000);
    </script>
</body>
</html>
