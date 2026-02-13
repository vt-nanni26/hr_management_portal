<?php
/**
 * generate_payslip.php - Universal Payslip Generator for HR Portal
 * Supports: Employees, Interns, Trainers
 * Integrates with payroll_manage.php via direct links.
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hr_management_portal');

define('COMPANY_NAME', 'HR Management Portal');
define('COMPANY_ADDRESS', '123 Business Park, City - 123456');
define('OVERTIME_RATE_PER_HOUR', 100);
define('WORKING_HOURS_PER_DAY', 8);

define('PAYSLIP_DIR', __DIR__ . '/payslips/');
if (!file_exists(PAYSLIP_DIR)) mkdir(PAYSLIP_DIR, 0755, true);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) die("Database connection failed: " . $conn->connect_error);
$conn->set_charset('utf8mb4');

// ---------- Helper functions ----------
function getActiveUsers($conn, $user_type) {
    $users = [];
    if ($user_type === 'employee') {
        $sql = "SELECT user_id, emp_id as code, first_name, last_name, email, 
                       designation as title, department_id 
                FROM employees WHERE employment_status = 'active' ORDER BY first_name";
    } elseif ($user_type === 'intern') {
        $sql = "SELECT user_id, intern_id as code, first_name, last_name, email, 
                       course as title, department_id 
                FROM interns WHERE internship_status = 'active' ORDER BY first_name";
    } elseif ($user_type === 'trainer') {
        $sql = "SELECT user_id, trainer_id as code, first_name, last_name, email, 
                       specialization as title, NULL as department_id 
                FROM trainers WHERE employment_status = 'active' ORDER BY first_name";
    } else {
        return [];
    }
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) $users[] = $row;
    }
    return $users;
}

function getUserDetails($conn, $user_id, $user_type) {
    if ($user_type === 'employee') {
        $sql = "SELECT e.*, d.name as department_name, s.name as shift_name 
                FROM employees e 
                LEFT JOIN departments d ON e.department_id = d.id 
                LEFT JOIN shifts s ON e.shift_id = s.id 
                WHERE e.user_id = ?";
    } elseif ($user_type === 'intern') {
        $sql = "SELECT i.*, d.name as department_name, s.name as shift_name,
                       CONCAT(e.first_name,' ',e.last_name) as supervisor_name
                FROM interns i 
                LEFT JOIN departments d ON i.department_id = d.id 
                LEFT JOIN shifts s ON i.shift_id = s.id 
                LEFT JOIN employees e ON i.supervisor_id = e.id
                WHERE i.user_id = ?";
    } elseif ($user_type === 'trainer') {
        $sql = "SELECT * FROM trainers WHERE user_id = ?";
    } else {
        return null;
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getAttendanceSummary($conn, $user_id, $user_type, $year, $month) {
    $start_date = sprintf('%04d-%02d-01', $year, $month);
    $end_date   = date('Y-m-t', strtotime($start_date));

    $sql = "SELECT 
                COUNT(CASE WHEN status = 'present' THEN 1 END) as days_present,
                COUNT(CASE WHEN status = 'absent' THEN 1 END) as days_absent,
                COUNT(CASE WHEN status = 'half_day' THEN 1 END) as half_days,
                COUNT(CASE WHEN status = 'holiday' THEN 1 END) as holidays,
                COUNT(CASE WHEN status = 'week_off' THEN 1 END) as week_offs,
                COALESCE(SUM(late_minutes), 0) as total_late_minutes,
                COALESCE(SUM(overtime_minutes), 0) as total_overtime_minutes
            FROM attendance 
            WHERE user_id = ? AND user_type = ? AND date BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isss', $user_id, $user_type, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $summary = $result->fetch_assoc();
    
    $default = [
        'days_present' => 0, 'days_absent' => 0, 'half_days' => 0,
        'holidays' => 0, 'week_offs' => 0,
        'total_late_minutes' => 0, 'total_overtime_minutes' => 0
    ];
    if (!$summary) return $default;
    
    foreach ($default as $key => $val) {
        $summary[$key] = (int)($summary[$key] ?? 0);
    }
    $summary['paid_days'] = $summary['days_present'] 
                          + ($summary['half_days'] * 0.5) 
                          + $summary['holidays'] 
                          + $summary['week_offs'];
    $summary['month_days'] = (int)date('t', strtotime($start_date));
    return $summary;
}

function getLeaveSummary($conn, $user_id, $user_type, $year, $month) {
    $start_date = sprintf('%04d-%02d-01', $year, $month);
    $end_date   = date('Y-m-t', strtotime($start_date));

    $sql = "SELECT COUNT(*) as leave_count, SUM(total_days) as total_leave_days
            FROM leave_requests 
            WHERE user_id = ? AND user_type = ? AND status = 'approved'
              AND start_date <= ? AND end_date >= ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isss', $user_id, $user_type, $end_date, $start_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return [
        'leave_count'      => (int)($row['leave_count'] ?? 0),
        'total_leave_days' => (float)($row['total_leave_days'] ?? 0)
    ];
}

function getEmployeeSalaryComponents($conn, $user_id, $effective_date) {
    $emp = getUserDetails($conn, $user_id, 'employee');
    if (!$emp) return ['earnings' => [], 'deductions' => []];
    $basic_salary = (float)($emp['salary'] ?? 0);

    $sql = "SELECT id, name, type, calculation_type, default_value, is_taxable
            FROM salary_components ORDER BY type DESC, id";
    $result = $conn->query($sql);
    $earnings = []; $deductions = [];

    while ($row = $result->fetch_assoc()) {
        $amount = 0; $percentage = null;
        $struct_sql = "SELECT amount, percentage FROM salary_structures 
                      WHERE user_id = ? AND component_id = ? AND user_type = 'employee'
                      AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
                      ORDER BY effective_from DESC LIMIT 1";
        $stmt = $conn->prepare($struct_sql);
        $stmt->bind_param('iiss', $user_id, $row['id'], $effective_date, $effective_date);
        $stmt->execute();
        $struct = $stmt->get_result()->fetch_assoc();

        if ($row['calculation_type'] == 'fixed') {
            $amount = (float)($struct['amount'] ?? $row['default_value'] ?? 0);
        } elseif ($row['calculation_type'] == 'percentage') {
            $percentage = (float)($struct['percentage'] ?? $row['default_value'] ?? 0);
            $amount = $basic_salary * $percentage / 100;
        }

        $comp = [
            'id'               => $row['id'],
            'name'             => $row['name'],
            'type'             => $row['type'],
            'calculation_type' => $row['calculation_type'],
            'default_value'    => $row['default_value'],
            'percentage'       => $percentage,
            'amount'           => round($amount, 2),
            'is_taxable'       => $row['is_taxable']
        ];
        if ($row['type'] == 'earning') $earnings[] = $comp;
        else $deductions[] = $comp;
    }
    return ['earnings' => $earnings, 'deductions' => $deductions];
}

function getBaseSalaryData($conn, $user_id, $user_type) {
    if ($user_type === 'intern') {
        $sql = "SELECT stipend_amount as base_amount, 'Stipend' as salary_type 
                FROM interns WHERE user_id = ? AND internship_status = 'active'";
    } elseif ($user_type === 'trainer') {
        $sql = "SELECT 
                    CASE 
                        WHEN trainer_type = 'full_time' THEN monthly_salary 
                        ELSE hourly_rate * 160 
                    END as base_amount,
                    CASE 
                        WHEN trainer_type = 'full_time' THEN 'Monthly Salary'
                        ELSE 'Hourly (160h)'
                    END as salary_type
                FROM trainers WHERE user_id = ? AND employment_status = 'active'";
    } else {
        return null;
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function calculatePayroll($conn, $user_id, $user_type, $year, $month) {
    $effective_date = sprintf('%04d-%02d-15', $year, $month);

    $user = getUserDetails($conn, $user_id, $user_type);
    if (!$user) return ['error' => 'User not found'];

    $attendance    = getAttendanceSummary($conn, $user_id, $user_type, $year, $month);
    $leave_summary = getLeaveSummary($conn, $user_id, $user_type, $year, $month);

    // --- Calculate base salary & components ---
    if ($user_type === 'employee') {
        $components = getEmployeeSalaryComponents($conn, $user_id, $effective_date);
        $earnings   = $components['earnings'];
        $deductions = $components['deductions'];
        $gross_earnings = array_sum(array_column($earnings, 'amount'));
        $base_salary = (float)($user['salary'] ?? 0);
        $daily_rate = $attendance['month_days'] > 0 ? $gross_earnings / $attendance['month_days'] : 0;
    } elseif ($user_type === 'intern') {
        $base_data = getBaseSalaryData($conn, $user_id, 'intern');
        $base_salary = (float)($base_data['base_amount'] ?? 0);
        $gross_earnings = $base_salary;
        $earnings = [['name' => 'Stipend', 'amount' => $base_salary, 'id' => 0]];
        $deductions = [];
        if ($base_salary > 0) {
            $deductions[] = ['name' => 'Intern Deduction (1%)', 'amount' => round($base_salary * 0.01, 2), 'id' => 999];
        }
        $daily_rate = $attendance['month_days'] > 0 ? $base_salary / $attendance['month_days'] : 0;
    } elseif ($user_type === 'trainer') {
        $base_data = getBaseSalaryData($conn, $user_id, 'trainer');
        $base_salary = (float)($base_data['base_amount'] ?? 0);
        $gross_earnings = $base_salary;
        $earnings = [['name' => 'Training Fee', 'amount' => $base_salary, 'id' => 0]];
        $deductions = [['name' => 'Professional Tax', 'amount' => 200, 'id' => 7]];
        $daily_rate = $attendance['month_days'] > 0 ? $base_salary / $attendance['month_days'] : 0;
    } else {
        return ['error' => 'Unsupported user type'];
    }

    // --- Adjust for attendance ---
    $absent_days = $attendance['days_absent'] + $leave_summary['total_leave_days'];
    $working_days_factor = ($attendance['month_days'] - $absent_days - ($attendance['half_days'] * 0.5)) / max(1, $attendance['month_days']);
    $pro_rated_salary = $gross_earnings * max(0, $working_days_factor);

    // Overtime
    $overtime_hours = $attendance['total_overtime_minutes'] / 60;
    $overtime_pay   = $overtime_hours * OVERTIME_RATE_PER_HOUR;

    $total_earnings = $pro_rated_salary + $overtime_pay;

    // --- Deductions (percentage handling for employees) ---
    $total_deductions = 0;
    foreach ($deductions as &$d) {
        if ($user_type === 'employee' && isset($d['calculation_type']) && $d['calculation_type'] == 'percentage' && $d['percentage'] > 0) {
            if (stripos($d['name'], 'PF') !== false || stripos($d['name'], 'ESI') !== false) {
                $base = $user['salary'];
            } elseif (stripos($d['name'], 'TDS') !== false) {
                $base = $total_earnings;
            } else {
                $base = $user['salary'];
            }
            $d['amount'] = round($base * $d['percentage'] / 100, 2);
        }
        $total_deductions += $d['amount'];
    }

    $net_salary = max(0, $total_earnings - $total_deductions);

    return [
        'user'               => $user,
        'attendance'         => $attendance,
        'leave_summary'      => $leave_summary,
        'earnings'           => $earnings,
        'deductions'         => $deductions,
        'gross_earnings'     => round($gross_earnings, 2),
        'pro_rated_salary'   => round($pro_rated_salary, 2),
        'overtime_pay'       => round($overtime_pay, 2),
        'total_earnings'     => round($total_earnings, 2),
        'total_deductions'   => round($total_deductions, 2),
        'net_salary'         => round($net_salary, 2),
        'absent_days'        => $absent_days,
        'paid_days'          => $attendance['paid_days'],
        'month_days'         => $attendance['month_days'],
        'overtime_hours'     => round($overtime_hours, 2),
        'daily_rate'         => round($daily_rate, 2),
        'base_salary'        => round($base_salary, 2)
    ];
}

function savePayroll($conn, $user_id, $user_type, $year, $month, $payroll_data, $pdf_path = null) {
    $payroll_month = sprintf('%04d-%02d-01', $year, $month);

    $sql = "SELECT id FROM payroll WHERE user_id = ? AND user_type = ? AND payroll_month = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $user_id, $user_type, $payroll_month);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    $total_earnings   = $payroll_data['total_earnings'];
    $total_deductions = $payroll_data['total_deductions'];
    $net_salary       = $payroll_data['net_salary'];
    $days_present     = $payroll_data['attendance']['days_present'] ?? 0;
    $days_absent      = $payroll_data['absent_days'] ?? 0;
    $late_count       = ($payroll_data['attendance']['total_late_minutes'] ?? 0) > 0 ? 1 : 0;
    $leave_count      = $payroll_data['leave_summary']['leave_count'] ?? 0;
    $overtime_hours   = $payroll_data['overtime_hours'] ?? 0;

    if ($existing) {
        $sql = "UPDATE payroll SET 
                total_earnings = ?, total_deductions = ?, net_salary = ?,
                days_present = ?, days_absent = ?, late_count = ?, leave_count = ?, overtime_hours = ?,
                payment_status = 'pending', updated_at = NOW()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ddddiiidi', 
            $total_earnings, $total_deductions, $net_salary,
            $days_present, $days_absent, $late_count, $leave_count, $overtime_hours,
            $existing['id']
        );
        $stmt->execute();
        $payroll_id = $existing['id'];
    } else {
        $sql = "INSERT INTO payroll 
                (payroll_month, user_id, user_type, total_earnings, total_deductions, net_salary,
                 days_present, days_absent, late_count, leave_count, overtime_hours, payment_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sisddddiiid', 
            $payroll_month, $user_id, $user_type, 
            $total_earnings, $total_deductions, $net_salary,
            $days_present, $days_absent, $late_count, $leave_count, $overtime_hours
        );
        $stmt->execute();
        $payroll_id = $stmt->insert_id;
    }

    // Save payslip reference
    if ($pdf_path && $payroll_id) {
        $check = $conn->prepare("SELECT id FROM payslips WHERE payroll_id = ? AND user_id = ?");
        $check->bind_param('ii', $payroll_id, $user_id);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        if ($exists) {
            $upd = $conn->prepare("UPDATE payslips SET file_path = ?, generated_at = NOW() WHERE id = ?");
            $upd->bind_param('si', $pdf_path, $exists['id']);
            $upd->execute();
        } else {
            $ins = $conn->prepare("INSERT INTO payslips (payroll_id, user_id, file_path) VALUES (?, ?, ?)");
            $ins->bind_param('iis', $payroll_id, $user_id, $pdf_path);
            $ins->execute();
        }
    }

    return $payroll_id ?? false;
}

function generateHTMLPayslip($payroll_data, $user_type, $year, $month) {
    $user = $payroll_data['user'];
    $month_name = date('F Y', mktime(0,0,0,$month,1,$year));
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Payslip</title>
        <style>
            body { font-family:Arial,sans-serif; margin:20px; }
            .payslip { max-width:800px; margin:auto; border:1px solid #ddd; padding:20px; }
            .header { text-align:center; border-bottom:2px solid #333; }
            .company-name { font-size:24px; font-weight:bold; color:#333; }
            .section { margin-top:20px; }
            .section-title { font-size:18px; font-weight:bold; background:#f0f0f0; padding:8px; }
            table { width:100%; border-collapse:collapse; margin-top:10px; }
            th, td { border:1px solid #ddd; padding:8px; text-align:left; }
            th { background:#f2f2f2; }
            .text-right { text-align:right; }
            .total-row { font-weight:bold; background:#f9f9f9; }
            .net-salary { font-size:20px; font-weight:bold; color:#28a745; text-align:right; margin-top:20px; }
            .footer { margin-top:30px; font-size:12px; color:#666; text-align:center; border-top:1px solid #ddd; padding-top:10px; }
        </style>
        </head><body><div class="payslip">
        <div class="header">
            <div class="company-name">' . COMPANY_NAME . '</div>
            <div>' . COMPANY_ADDRESS . '</div>
            <h2>Payslip for ' . $month_name . '</h2>
        </div>
        <div class="section">
            <div class="section-title">Employee Details</div>
            <table><tr><td><strong>Name:</strong></td><td>' . htmlspecialchars($user['first_name']??'').' '.htmlspecialchars($user['last_name']??'') . '</td>
                <td><strong>ID:</strong></td><td>' . ($user['emp_id'] ?? $user['intern_id'] ?? $user['trainer_id'] ?? '') . '</td></tr>
            <tr><td><strong>Type:</strong></td><td>' . ucfirst($user_type) . '</td>
                <td><strong>Designation:</strong></td><td>' . htmlspecialchars($user['designation'] ?? $user['course'] ?? $user['specialization'] ?? '') . '</td></tr>
            </table>
        </div>
        <div class="section">
            <div class="section-title">Attendance</div>
            <table><tr><th>Present</th><th>Absent</th><th>Half Days</th><th>Overtime (hrs)</th></tr>
            <tr><td>' . $payroll_data['attendance']['days_present'] . '</td>
                <td>' . $payroll_data['absent_days'] . '</td>
                <td>' . $payroll_data['attendance']['half_days'] . '</td>
                <td>' . $payroll_data['overtime_hours'] . '</td></tr></table>
        </div>
        <div class="section">
            <div class="section-title">Earnings</div>
            <table>';
    foreach ($payroll_data['earnings'] as $e) {
        $html .= '<tr><td>' . htmlspecialchars($e['name']) . '</td><td class="text-right">₹' . number_format($e['amount'],2) . '</td></tr>';
    }
    $html .= '<tr><td><strong>Pro-rated Salary</strong></td><td class="text-right">₹' . number_format($payroll_data['pro_rated_salary'],2) . '</td></tr>
              <tr><td><strong>Overtime Pay</strong></td><td class="text-right">₹' . number_format($payroll_data['overtime_pay'],2) . '</td></tr>
              <tr class="total-row"><td><strong>Total Earnings</strong></td><td class="text-right"><strong>₹' . number_format($payroll_data['total_earnings'],2) . '</strong></td></tr>
            </table>
        </div>
        <div class="section">
            <div class="section-title">Deductions</div>
            <table>';
    foreach ($payroll_data['deductions'] as $d) {
        $html .= '<tr><td>' . htmlspecialchars($d['name']) . '</td><td class="text-right">₹' . number_format($d['amount'],2) . '</td></tr>';
    }
    $html .= '<tr class="total-row"><td><strong>Total Deductions</strong></td><td class="text-right"><strong>₹' . number_format($payroll_data['total_deductions'],2) . '</strong></td></tr>
            </table>
        </div>
        <div class="net-salary">Net Salary: ₹ ' . number_format($payroll_data['net_salary'],2) . '</div>
        <div class="footer">Generated on ' . date('d-m-Y H:i:s') . ' | Computer generated – no signature required.</div>
    </div></body></html>';
    return $html;
}

// ---------- MAIN LOGIC ----------
$selected_user_id   = $_GET['user_id']   ?? $_POST['user_id']   ?? 0;
$selected_user_type = $_GET['user_type'] ?? $_POST['user_type'] ?? 'employee';
$selected_year      = $_GET['year']      ?? $_POST['year']      ?? date('Y');
$selected_month     = $_GET['month']     ?? $_POST['month']     ?? date('m');

$payroll_result = null;
$message = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'load' && !empty($_POST['user_id'])) {
        $payroll_result = calculatePayroll($conn, (int)$_POST['user_id'], $_POST['user_type'], (int)$_POST['year'], (int)$_POST['month']);
        if (isset($payroll_result['error'])) {
            $error = $payroll_result['error'];
            $payroll_result = null;
        }
    }

    if ($action === 'save') {
        $user_id   = (int)$_POST['user_id'];
        $user_type = $_POST['user_type'];
        $year      = (int)$_POST['year'];
        $month     = (int)$_POST['month'];

        // Reconstruct arrays from POST
        $attendance = [
            'days_present'             => (int)($_POST['days_present'] ?? 0),
            'half_days'               => (int)($_POST['half_days'] ?? 0),
            'holidays'                => (int)($_POST['holidays'] ?? 0),
            'week_offs'               => (int)($_POST['week_offs'] ?? 0),
            'total_late_minutes'      => 0,
            'total_overtime_minutes'  => (int)(($_POST['overtime_hours'] ?? 0) * 60)
        ];
        $attendance['paid_days'] = $attendance['days_present'] 
                                 + $attendance['half_days'] * 0.5 
                                 + $attendance['holidays'] 
                                 + $attendance['week_offs'];
        $attendance['month_days']   = (int)($_POST['month_days'] ?? 30);
        $attendance['days_absent']  = (int)($_POST['absent_days'] ?? 0);

        $leave_summary = [
            'leave_count'      => (int)($_POST['leave_count'] ?? 0),
            'total_leave_days' => (float)($_POST['absent_days'] ?? 0)
        ];

        $earnings = []; $deductions = [];
        if (isset($_POST['comp_id']) && is_array($_POST['comp_id'])) {
            foreach ($_POST['comp_id'] as $i => $cid) {
                $comp = [
                    'id'     => $cid,
                    'name'   => $_POST['comp_name'][$i],
                    'type'   => $_POST['comp_type'][$i],
                    'amount' => (float)($_POST['comp_amount'][$i] ?? 0)
                ];
                if ($_POST['comp_type'][$i] == 'earning') $earnings[] = $comp;
                else $deductions[] = $comp;
            }
        }

        $payroll_data = [
            'user'               => getUserDetails($conn, $user_id, $user_type),
            'attendance'         => $attendance,
            'leave_summary'      => $leave_summary,
            'earnings'           => $earnings,
            'deductions'         => $deductions,
            'total_earnings'     => (float)($_POST['total_earnings'] ?? 0),
            'total_deductions'   => (float)($_POST['total_deductions'] ?? 0),
            'net_salary'         => (float)($_POST['net_salary'] ?? 0),
            'absent_days'        => (int)($_POST['absent_days'] ?? 0),
            'month_days'         => (int)($_POST['month_days'] ?? 30),
            'overtime_hours'     => (float)($_POST['overtime_hours'] ?? 0),
            'pro_rated_salary'   => (float)($_POST['pro_rated_salary'] ?? 0),
            'overtime_pay'       => (float)($_POST['overtime_pay'] ?? 0)
        ];

        $pdf_path = null;
        if (isset($_POST['generate_pdf']) && $_POST['generate_pdf'] == '1') {
            $html = generateHTMLPayslip($payroll_data, $user_type, $year, $month);
            $code = $payroll_data['user']['emp_id'] ?? $payroll_data['user']['intern_id'] ?? $payroll_data['user']['trainer_id'] ?? 'user';
            $filename = 'payslip_' . $code . '_' . $year . '_' . sprintf('%02d',$month) . '.html';
            $filepath = PAYSLIP_DIR . $filename;
            file_put_contents($filepath, $html);
            $pdf_path = $filepath;
        }

        $payroll_id = savePayroll($conn, $user_id, $user_type, $year, $month, $payroll_data, $pdf_path);
        if ($payroll_id) {
            $message = 'Payslip saved successfully.';
            if ($pdf_path) {
                $message .= ' <a href="payslips/' . basename($pdf_path) . '" target="_blank">View Payslip</a>';
            }
        } else {
            $error = 'Failed to save payslip.';
        }

        // Reload
        $payroll_result = calculatePayroll($conn, $user_id, $user_type, $year, $month);
    }
}

// If parameters exist via GET, auto-load
if ($selected_user_id && empty($_POST)) {
    $payroll_result = calculatePayroll($conn, $selected_user_id, $selected_user_type, $selected_year, $selected_month);
    if (isset($payroll_result['error'])) {
        $error = $payroll_result['error'];
        $payroll_result = null;
    }
}

// Get user list for dropdown based on selected type
$users_list = getActiveUsers($conn, $selected_user_type);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Payslip - HR Portal</title>
    <style>
        body { font-family:Arial,sans-serif; background:#f5f5f5; margin:20px; }
        .container { max-width:1200px; margin:auto; background:white; padding:20px; border-radius:8px; box-shadow:0 0 10px rgba(0,0,0,0.1); }
        h1, h2, h3 { color:#333; }
        label { display:block; margin-top:10px; font-weight:bold; }
        select, input[type=number], input[type=text], textarea { width:100%; padding:8px; margin-top:5px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box; }
        input[readonly] { background:#e9e9e9; }
        .form-row { display:flex; gap:20px; margin-bottom:15px; flex-wrap:wrap; }
        .form-group { flex:1; min-width:200px; }
        table { width:100%; border-collapse:collapse; margin-top:20px; }
        th, td { border:1px solid #ddd; padding:10px; text-align:left; }
        th { background:#f2f2f2; }
        .text-right { text-align:right; }
        .btn { background:#007bff; color:white; padding:10px 20px; border:none; border-radius:4px; cursor:pointer; font-size:16px; margin-right:10px; }
        .btn:hover { background:#0056b3; }
        .btn-success { background:#28a745; }
        .btn-warning { background:#ffc107; color:#212529; }
        .message { padding:15px; margin-bottom:20px; border-radius:4px; }
        .success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
        .error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
        .amount-input { width:150px; text-align:right; }
        .employee-card { background:#f8f9fa; padding:15px; border-radius:4px; margin-bottom:20px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Generate / Edit Payslip</h1>
        <p><a href="payroll_manage.php">← Back to Payroll Management</a></p>

        <?php if ($message): ?><div class="message success"><?php echo $message; ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="message error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <!-- Selection Form -->
        <form method="post" id="loadForm">
            <input type="hidden" name="action" value="load">
            <div class="form-row">
                <div class="form-group">
                    <label>User Type</label>
                    <select name="user_type" id="user_type" onchange="this.form.submit()">
                        <option value="employee" <?php echo $selected_user_type=='employee'?'selected':''; ?>>Employee</option>
                        <option value="intern"   <?php echo $selected_user_type=='intern'?'selected':''; ?>>Intern</option>
                        <option value="trainer"  <?php echo $selected_user_type=='trainer'?'selected':''; ?>>Trainer</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>User</label>
                    <select name="user_id" required onchange="this.form.submit()">
                        <option value="">-- Select --</option>
                        <?php foreach ($users_list as $u): ?>
                        <option value="<?php echo $u['user_id']; ?>" <?php echo $selected_user_id==$u['user_id']?'selected':''; ?>>
                            <?php echo htmlspecialchars($u['first_name'].' '.$u['last_name'].' ('.$u['code'].' - '.($u['title']??'').')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Month</label>
                    <select name="month" onchange="this.form.submit()">
                        <?php for($m=1;$m<=12;$m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $selected_month==$m?'selected':''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Year</label>
                    <select name="year" onchange="this.form.submit()">
                        <?php for($y=date('Y')-2;$y<=date('Y')+1;$y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $selected_year==$y?'selected':''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </form>

        <?php if ($payroll_result && !isset($payroll_result['error'])): 
            $user = $payroll_result['user'];
            $att  = $payroll_result['attendance'];
        ?>
        <hr>
        <h2>Payslip for <?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?> 
            (<?php echo ucfirst($selected_user_type); ?>) - <?php echo date('F Y', mktime(0,0,0,$selected_month,1,$selected_year)); ?>
        </h2>

        <form method="post" id="payslipForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="user_id"   value="<?php echo $selected_user_id; ?>">
            <input type="hidden" name="user_type" value="<?php echo $selected_user_type; ?>">
            <input type="hidden" name="month"     value="<?php echo $selected_month; ?>">
            <input type="hidden" name="year"      value="<?php echo $selected_year; ?>">
            <input type="hidden" name="month_days"    value="<?php echo $att['month_days']; ?>">
            <input type="hidden" name="leave_count"   value="<?php echo $payroll_result['leave_summary']['leave_count']; ?>">

            <!-- User Details (readonly) -->
            <div class="employee-card">
                <div class="form-row">
                    <div class="form-group"><label>ID</label><input type="text" value="<?php echo htmlspecialchars($user['emp_id']??$user['intern_id']??$user['trainer_id']??''); ?>" readonly></div>
                    <div class="form-group"><label>Name</label><input type="text" value="<?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?>" readonly></div>
                    <div class="form-group"><label>Designation</label><input type="text" value="<?php echo htmlspecialchars($user['designation']??$user['course']??$user['specialization']??''); ?>" readonly></div>
                </div>
            </div>

            <!-- Attendance (editable) -->
            <h3>Attendance</h3>
            <div class="form-row">
                <div class="form-group"><label>Present Days</label><input type="number" name="days_present" value="<?php echo $att['days_present']; ?>" min="0" class="amount-input" onchange="updateProRatedSalary()"></div>
                <div class="form-group"><label>Absent Days</label><input type="number" name="absent_days" id="absent_days" value="<?php echo $payroll_result['absent_days']; ?>" min="0" class="amount-input" onchange="updateProRatedSalary()"></div>
                <div class="form-group"><label>Half Days</label><input type="number" name="half_days" id="half_days" value="<?php echo $att['half_days']; ?>" min="0" class="amount-input" onchange="updateProRatedSalary()"></div>
                <div class="form-group"><label>Overtime (hrs)</label><input type="number" name="overtime_hours" id="overtime_hours" value="<?php echo $payroll_result['overtime_hours']; ?>" min="0" step="0.5" class="amount-input" onchange="updateOvertimePay()"></div>
            </div>

            <!-- Earnings Table -->
            <h3>Earnings</h3>
            <table id="earningsTable">
                <thead><tr><th>Component</th><th class="text-right">Amount (₹)</th></tr></thead>
                <tbody>
                    <?php foreach ($payroll_result['earnings'] as $idx => $comp): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($comp['name']); ?>
                            <input type="hidden" name="comp_id[]" value="<?php echo $comp['id'] ?? 0; ?>">
                            <input type="hidden" name="comp_name[]" value="<?php echo htmlspecialchars($comp['name']); ?>">
                            <input type="hidden" name="comp_type[]" value="earning">
                        </td>
                        <td class="text-right">
                            <input type="number" name="comp_amount[]" value="<?php echo $comp['amount']; ?>" step="0.01" min="0" class="amount-input" onchange="recalculateTotals()">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><strong>Pro-rated Salary</strong></td>
                        <td class="text-right">
                            <input type="number" name="pro_rated_salary" id="pro_rated_salary" value="<?php echo $payroll_result['pro_rated_salary']; ?>" step="0.01" readonly class="amount-input" style="background:#e9e9e9;">
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Overtime Pay</strong></td>
                        <td class="text-right">
                            <input type="number" name="overtime_pay" id="overtime_pay" value="<?php echo $payroll_result['overtime_pay']; ?>" step="0.01" readonly class="amount-input" style="background:#e9e9e9;">
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr style="font-weight:bold; background:#f0f0f0;">
                        <td>Total Earnings</td>
                        <td class="text-right">
                            <input type="number" name="total_earnings" id="total_earnings" value="<?php echo $payroll_result['total_earnings']; ?>" step="0.01" readonly style="background:#e9e9e9; width:150px; text-align:right;">
                        </td>
                    </tr>
                </tfoot>
            </table>

            <!-- Deductions Table -->
            <h3>Deductions</h3>
            <table id="deductionsTable">
                <thead><tr><th>Component</th><th class="text-right">Amount (₹)</th></tr></thead>
                <tbody>
                    <?php foreach ($payroll_result['deductions'] as $idx => $comp): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($comp['name']); ?>
                            <input type="hidden" name="comp_id[]" value="<?php echo $comp['id'] ?? 0; ?>">
                            <input type="hidden" name="comp_name[]" value="<?php echo htmlspecialchars($comp['name']); ?>">
                            <input type="hidden" name="comp_type[]" value="deduction">
                        </td>
                        <td class="text-right">
                            <input type="number" name="comp_amount[]" value="<?php echo $comp['amount']; ?>" step="0.01" min="0" class="amount-input" onchange="recalculateTotals()">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:bold; background:#f0f0f0;">
                        <td>Total Deductions</td>
                        <td class="text-right">
                            <input type="number" name="total_deductions" id="total_deductions" value="<?php echo $payroll_result['total_deductions']; ?>" step="0.01" readonly style="background:#e9e9e9; width:150px; text-align:right;">
                        </td>
                    </tr>
                </tfoot>
            </table>

            <!-- Net Salary -->
            <div style="margin-top:20px; padding:15px; background:#f8f9fa; border-radius:4px;">
                <div style="display:flex; justify-content:flex-end; align-items:center;">
                    <label style="font-size:18px; font-weight:bold; margin-right:20px;">Net Salary (₹):</label>
                    <input type="number" name="net_salary" id="net_salary" value="<?php echo $payroll_result['net_salary']; ?>" step="0.01" readonly style="font-size:20px; width:200px; text-align:right; padding:10px; background:#e9e9e9; border:1px solid #ccc; border-radius:4px;">
                </div>
            </div>

            <!-- Remarks -->
            <div style="margin-top:20px;">
                <label>Remarks</label>
                <textarea name="remarks" rows="2" placeholder="Optional notes"><?php echo htmlspecialchars($_POST['remarks'] ?? ''); ?></textarea>
            </div>

            <!-- Buttons -->
            <div style="margin-top:30px; display:flex; gap:20px; justify-content:center;">
                <button type="submit" name="save_only" value="1" class="btn btn-success">Save to Database</button>
                <button type="submit" name="generate_pdf" value="1" class="btn btn-warning">Generate HTML Payslip</button>
                <button type="submit" name="save_and_generate" value="1" class="btn" style="background:#17a2b8;">Save & Generate</button>
            </div>
        </form>

        <script>
        const OVERTIME_RATE = <?php echo OVERTIME_RATE_PER_HOUR; ?>;
        const GROSS_EARNINGS = <?php echo $payroll_result['gross_earnings']; ?>;
        const MONTH_DAYS = <?php echo $att['month_days']; ?>;

        function updateProRatedSalary() {
            let present = parseFloat(document.querySelector('input[name="days_present"]').value) || 0;
            let absent  = parseFloat(document.getElementById('absent_days').value) || 0;
            let half    = parseFloat(document.getElementById('half_days').value) || 0;
            let workingDays = MONTH_DAYS - absent - (half * 0.5);
            let dailyRate = GROSS_EARNINGS / MONTH_DAYS;
            let proRated = workingDays * dailyRate;
            document.getElementById('pro_rated_salary').value = proRated.toFixed(2);
            recalculateTotals();
        }

        function updateOvertimePay() {
            let hrs = parseFloat(document.getElementById('overtime_hours').value) || 0;
            document.getElementById('overtime_pay').value = (hrs * OVERTIME_RATE).toFixed(2);
            recalculateTotals();
        }

        function recalculateTotals() {
            let totalEarnings = 0;
            document.querySelectorAll('#earningsTable tbody input.amount-input').forEach(el => {
                totalEarnings += parseFloat(el.value) || 0;
            });
            totalEarnings += parseFloat(document.getElementById('pro_rated_salary').value) || 0;
            totalEarnings += parseFloat(document.getElementById('overtime_pay').value) || 0;
            document.getElementById('total_earnings').value = totalEarnings.toFixed(2);

            let totalDeductions = 0;
            document.querySelectorAll('#deductionsTable tbody input.amount-input').forEach(el => {
                totalDeductions += parseFloat(el.value) || 0;
            });
            document.getElementById('total_deductions').value = totalDeductions.toFixed(2);

            let net = totalEarnings - totalDeductions;
            document.getElementById('net_salary').value = net.toFixed(2);
        }

        window.onload = function() {
            recalculateTotals();
        }
        </script>
        <?php endif; ?>
    </div>
</body>
</html>
