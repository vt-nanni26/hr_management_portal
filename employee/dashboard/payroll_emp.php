<?php
// payroll_emp.php - Enhanced Payroll System with HTML & Print Functionality
session_start();
require_once "../../db_connection.php";

// Check if user is logged in and is employee
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'employee') {
    header("Location: login_emp.php");
    exit();
}

// Handle all payslip actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $payroll_id = intval($_GET['id']);
    $action = $_GET['action'];
    
    switch($action) {
        case 'view_html':
            generateEnhancedPayslipHTML($payroll_id);
            exit;
        case 'download_html':
            generateEnhancedPayslipHTML($payroll_id, true);
            exit;
        case 'print_html':
            generateEnhancedPayslipHTML($payroll_id, false, true);
            exit;
        case 'regenerate':
            regeneratePayslip($payroll_id);
            exit;
    }
}

// Function to generate enhanced HTML payslip
function generateEnhancedPayslipHTML($payroll_id, $download = false, $print_mode = false) {
    global $conn;
    
    $data = fetchPayslipData($payroll_id);
    if (!$data) {
        die("Payslip not found or access denied");
    }
    
    extract($data); // $payroll, $attendance, $components, $calculations, $total_earnings, $total_deductions, $net_salary
    
    // Generate HTML
    $html = generateHTMLTemplate($payroll, $attendance, $components, $total_earnings, $total_deductions, $net_salary, $calculations, $print_mode);
    
    if ($download) {
        // Force download
        $filename = 'Payslip_' . $payroll['emp_id'] . '_' . date('F_Y', strtotime($payroll['payroll_month'])) . '.html';
        header('Content-Type: text/html');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $html;
        exit;
    } else {
        // Display in browser
        echo $html;
        exit;
    }
}

// Function to fetch payslip data
function fetchPayslipData($payroll_id) {
    global $conn;
    
    // Fetch payroll data with employee details
    $stmt = $conn->prepare("
        SELECT p.*, e.*, d.name as department_name 
        FROM payroll p
        JOIN employees e ON p.user_id = e.user_id
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE p.id = ? AND p.user_id = ? AND p.user_type = 'employee'
    ");
    $stmt->bind_param("ii", $payroll_id, $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return false;
    }

    $payroll = $result->fetch_assoc();
    
    // Fetch attendance summary
    $attendance_stmt = $conn->prepare("
        SELECT 
            COUNT(CASE WHEN status = 'present' THEN 1 END) as days_present,
            COUNT(CASE WHEN status = 'absent' THEN 1 END) as days_absent,
            COUNT(CASE WHEN status = 'half_day' THEN 1 END) as half_days,
            SUM(late_minutes) as total_late_minutes,
            SUM(overtime_minutes) as total_overtime_minutes
        FROM attendance 
        WHERE user_id = ? 
        AND user_type = 'employee'
        AND YEAR(date) = YEAR(?)
        AND MONTH(date) = MONTH(?)
    ");
    $attendance_stmt->bind_param("iss", $_SESSION['user_id'], $payroll['payroll_month'], $payroll['payroll_month']);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->get_result();
    $attendance = $attendance_result->fetch_assoc();
    
    if (!$attendance) {
        $attendance = [
            'days_present' => $payroll['days_present'] ?? 0,
            'days_absent' => $payroll['days_absent'] ?? 0,
            'half_days' => 0,
            'total_late_minutes' => 0,
            'total_overtime_minutes' => $payroll['overtime_hours'] ? ($payroll['overtime_hours'] * 60) : 0
        ];
    }
    
    // Fetch salary components
    $components_stmt = $conn->prepare("
        SELECT sc.name, sc.type, sc.calculation_type, ss.amount, sc.default_value
        FROM salary_structures ss
        JOIN salary_components sc ON ss.component_id = sc.id
        WHERE ss.user_id = ? AND ss.user_type = 'employee'
        AND (ss.effective_to IS NULL OR ss.effective_to >= ?)
        ORDER BY sc.type DESC, sc.id
    ");
    $components_stmt->bind_param("is", $_SESSION['user_id'], $payroll['payroll_month']);
    $components_stmt->execute();
    $components_result = $components_stmt->get_result();
    $components = [];
    
    if ($components_result->num_rows === 0) {
        $basic_salary = $payroll['total_earnings'] * 0.6;
        $components = [
            ['name' => 'Basic Salary', 'type' => 'earning', 'amount' => $basic_salary, 'calculation_type' => 'fixed'],
            ['name' => 'HRA (House Rent Allowance)', 'type' => 'earning', 'amount' => $payroll['total_earnings'] * 0.3, 'calculation_type' => 'percentage'],
            ['name' => 'Special Allowance', 'type' => 'earning', 'amount' => $payroll['total_earnings'] * 0.1, 'calculation_type' => 'fixed'],
            ['name' => 'Conveyance Allowance', 'type' => 'earning', 'amount' => 1600, 'calculation_type' => 'fixed'],
            ['name' => 'Medical Allowance', 'type' => 'earning', 'amount' => 1250, 'calculation_type' => 'fixed'],
            ['name' => 'PF Contribution (12% of Basic)', 'type' => 'deduction', 'amount' => $payroll['total_deductions'] * 0.6, 'calculation_type' => 'percentage'],
            ['name' => 'Professional Tax', 'type' => 'deduction', 'amount' => 200, 'calculation_type' => 'fixed'],
            ['name' => 'TDS (Tax Deducted at Source)', 'type' => 'deduction', 'amount' => $payroll['total_deductions'] * 0.4, 'calculation_type' => 'percentage'],
            ['name' => 'ESI Contribution (0.75%)', 'type' => 'deduction', 'amount' => ($payroll['total_earnings'] * 0.0075), 'calculation_type' => 'percentage']
        ];
    } else {
        while ($row = $components_result->fetch_assoc()) {
            $components[] = $row;
        }
    }
    
    // Calculate totals
    $total_earnings = 0;
    $total_deductions = 0;
    foreach ($components as $component) {
        if ($component['type'] === 'earning') {
            $total_earnings += $component['amount'];
        } else {
            $total_deductions += $component['amount'];
        }
    }
    
    // Calculate additional values
    $working_hours_per_day = 8;
    $year = date('Y', strtotime($payroll['payroll_month']));
    $month = date('m', strtotime($payroll['payroll_month']));
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    
    // Count working days (Monday to Friday)
    $total_working_days_in_month = 0;
    for ($day = 1; $day <= $days_in_month; $day++) {
        $timestamp = mktime(0, 0, 0, $month, $day, $year);
        $weekday = date('N', $timestamp);
        if ($weekday <= 5) {
            $total_working_days_in_month++;
        }
    }
    
    $total_working_days = $attendance['days_present'] + ($attendance['half_days'] * 0.5);
    $regular_hours = $total_working_days * $working_hours_per_day;
    $overtime_hours = $attendance['total_overtime_minutes'] / 60;
    $total_hours = $regular_hours + $overtime_hours;
    
    // Calculate hourly rate and overtime
    $basic_salary = 0;
    foreach ($components as $component) {
        if (strpos($component['name'], 'Basic Salary') !== false) {
            $basic_salary = $component['amount'];
            break;
        }
    }
    if ($basic_salary == 0) {
        $basic_salary = $total_earnings * 0.6;
    }
    
    $daily_rate = $basic_salary / $total_working_days_in_month;
    $hourly_rate = $daily_rate / $working_hours_per_day;
    $overtime_rate = $hourly_rate * 1.5;
    $overtime_pay = $overtime_hours * $overtime_rate;
    
    // Calculate net salary
    $net_salary = $total_earnings - $total_deductions;
    
    $calculations = [
        'total_working_days_in_month' => $total_working_days_in_month,
        'total_working_days' => $total_working_days,
        'regular_hours' => $regular_hours,
        'overtime_hours' => $overtime_hours,
        'total_hours' => $total_hours,
        'basic_salary' => $basic_salary,
        'daily_rate' => $daily_rate,
        'hourly_rate' => $hourly_rate,
        'overtime_rate' => $overtime_rate,
        'overtime_pay' => $overtime_pay
    ];
    
    return compact('payroll', 'attendance', 'components', 'calculations', 'total_earnings', 'total_deductions', 'net_salary');
}

// Function to generate HTML template
function generateHTMLTemplate($payroll, $attendance, $components, $total_earnings, $total_deductions, $net_salary, $calculations, $print_mode = false) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>HR Portal - Payslip</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            <?php if ($print_mode): ?>
            /* Print-specific styles */
            @media print {
                body {
                    margin: 0;
                    padding: 0;
                    background: white !important;
                    color: black !important;
                }
                
                .container {
                    max-width: 100% !important;
                    width: 100% !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    box-shadow: none !important;
                    border-radius: 0 !important;
                }
                
                .action-bar {
                    display: none !important;
                }
                
                .net-salary {
                    cursor: default !important;
                }
                
                .net-salary:hover {
                    transform: none !important;
                }
                
                .attendance-item:hover,
                .payment-item:hover,
                .amount-table tr:hover td {
                    transform: none !important;
                    box-shadow: none !important;
                }
                
                /* Ensure proper page breaks */
                .page-break {
                    page-break-before: always;
                }
                
                /* Remove background colors for better printing */
                .header {
                    background: #1a365d !important;
                    color: white !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                
                .confidential-banner {
                    background: #c53030 !important;
                    color: white !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                
                .net-salary {
                    background: #1a365d !important;
                    color: white !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                
                .footer {
                    background: #1a365d !important;
                    color: #cbd5e0 !important;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
            }
            <?php endif; ?>
            
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            
            body {
                background-color: #f5f7fa;
                color: #333;
                padding: 20px;
                line-height: 1.6;
            }
            
            .container {
                max-width: 1100px;
                margin: 0 auto;
                background-color: white;
                border-radius: 10px;
                box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
                overflow: hidden;
            }
            
            .header {
                background: linear-gradient(135deg, #1a365d 0%, #2d3748 100%);
                color: white;
                padding: 25px 30px;
                text-align: center;
                position: relative;
            }
            
            .header h1 {
                font-size: 28px;
                margin-bottom: 5px;
                letter-spacing: 1px;
            }
            
            .header p {
                opacity: 0.9;
                font-size: 14px;
            }
            
            .company-logo {
                position: absolute;
                left: 30px;
                top: 50%;
                transform: translateY(-50%);
                font-size: 36px;
                color: #ffd166;
            }
            
            .confidential-banner {
                background-color: #c53030;
                color: white;
                text-align: center;
                padding: 8px;
                font-weight: bold;
                font-size: 14px;
                letter-spacing: 1px;
            }
            
            .details-section {
                display: flex;
                flex-wrap: wrap;
                border-bottom: 1px solid #e2e8f0;
            }
            
            .company-details, .employee-details {
                flex: 1;
                min-width: 300px;
                padding: 25px;
            }
            
            .company-details {
                border-right: 1px solid #e2e8f0;
                background-color: #f8fafc;
            }
            
            .section-title {
                color: #1a365d;
                font-size: 18px;
                margin-bottom: 15px;
                padding-bottom: 8px;
                border-bottom: 2px solid #4299e1;
            }
            
            .detail-row {
                margin-bottom: 10px;
                display: flex;
            }
            
            .detail-label {
                font-weight: 600;
                min-width: 140px;
                color: #4a5568;
            }
            
            .detail-value {
                color: #2d3748;
            }
            
            .pay-period {
                text-align: center;
                padding: 20px;
                background-color: #edf2f7;
                font-size: 20px;
                font-weight: 600;
                color: #2d3748;
                border-bottom: 1px solid #e2e8f0;
            }
            
            .attendance-section {
                padding: 20px 25px;
                border-bottom: 1px solid #e2e8f0;
                background-color: #f8fafc;
            }
            
            .attendance-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-top: 15px;
            }
            
            .attendance-item {
                background-color: white;
                padding: 15px;
                border-radius: 8px;
                border-left: 4px solid #4299e1;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
                transition: all 0.3s ease;
            }
            
            .attendance-item:hover {
                transform: translateY(-3px);
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            }
            
            .attendance-label {
                font-size: 14px;
                color: #4a5568;
                margin-bottom: 5px;
            }
            
            .attendance-value {
                font-size: 18px;
                font-weight: 600;
                color: #1a365d;
            }
            
            .attendance-value.leaves {
                color: #c53030;
            }
            
            .attendance-value.ot {
                color: #38a169;
            }
            
            .attendance-value.late {
                color: #d69e2e;
            }
            
            .earnings-deductions {
                display: flex;
                flex-wrap: wrap;
                padding: 20px;
                background-color: white;
            }
            
            .earnings, .deductions {
                flex: 1;
                min-width: 300px;
                padding: 20px;
            }
            
            .earnings {
                border-right: 1px solid #e2e8f0;
            }
            
            .amount-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            
            .amount-table th {
                background-color: #edf2f7;
                padding: 12px 15px;
                text-align: left;
                color: #4a5568;
                font-weight: 600;
                border-bottom: 1px solid #cbd5e0;
            }
            
            .amount-table td {
                padding: 12px 15px;
                border-bottom: 1px solid #e2e8f0;
                transition: background-color 0.2s;
            }
            
            .amount-table tr:hover td {
                background-color: #f7fafc;
            }
            
            .amount-table td:last-child {
                text-align: right;
                font-family: 'Courier New', monospace;
                font-weight: 600;
            }
            
            .rupee-symbol {
                font-family: Arial, sans-serif;
            }
            
            .total-row {
                font-weight: bold;
                background-color: #f7fafc;
            }
            
            .total-row td {
                border-top: 2px solid #cbd5e0;
            }
            
            .net-salary {
                text-align: center;
                padding: 30px;
                background: linear-gradient(to right, #1a365d, #2d3748);
                color: white;
                font-size: 28px;
                font-weight: bold;
                cursor: pointer;
                transition: all 0.3s ease;
                border-bottom: 1px solid #2d3748;
            }
            
            .net-salary:hover {
                background: linear-gradient(to right, #2d3748, #1a365d);
                transform: scale(1.02);
            }
            
            .payment-info {
                padding: 25px;
                background-color: #f7fafc;
                border-top: 1px solid #e2e8f0;
            }
            
            .payment-details {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 30px;
                margin-top: 15px;
            }
            
            .payment-item {
                background-color: white;
                padding: 15px;
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
                border-left: 4px solid #4299e1;
            }
            
            .payment-label {
                font-weight: 600;
                color: #4a5568;
                font-size: 14px;
                margin-bottom: 5px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .payment-value {
                color: #2d3748;
                font-size: 16px;
            }
            
            .status-paid {
                color: #38a169;
                font-weight: bold;
            }
            
            .status-pending {
                color: #d69e2e;
                font-weight: bold;
            }
            
            .status-processed {
                color: #4299e1;
                font-weight: bold;
            }
            
            .footer {
                text-align: center;
                padding: 20px;
                background-color: #1a365d;
                color: #cbd5e0;
                font-size: 13px;
                border-top: 1px solid #2d3748;
            }
            
            .footer p {
                margin-bottom: 8px;
            }
            
            .calculation-note {
                background-color: #f0fff4;
                border: 1px solid #c6f6d5;
                border-radius: 6px;
                padding: 15px;
                margin: 20px;
                font-size: 14px;
                color: #22543d;
            }
            
            .calculation-note h4 {
                margin-bottom: 10px;
                color: #1a365d;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .calculation-note ul {
                margin-left: 20px;
            }
            
            .calculation-note li {
                margin-bottom: 5px;
            }
            
            @media (max-width: 768px) {
                .company-details, .employee-details, .earnings, .deductions {
                    border-right: none;
                    border-bottom: 1px solid #e2e8f0;
                }
                
                .details-section, .earnings-deductions {
                    flex-direction: column;
                }
                
                .attendance-grid {
                    grid-template-columns: 1fr;
                }
                
                .company-logo {
                    position: relative;
                    left: 0;
                    top: 0;
                    transform: none;
                    margin-bottom: 15px;
                }
                
                .payment-details {
                    grid-template-columns: 1fr;
                }
            }
            
            .currency-cell {
                font-family: 'Courier New', monospace;
                font-weight: 600;
                text-align: right !important;
            }
            
            .salary-breakdown {
                background-color: #f8fafc;
                padding: 15px;
                border-radius: 8px;
                margin: 10px 0;
                font-size: 14px;
                border-left: 4px solid #4299e1;
            }
            
            .ot-details {
                background-color: #f0fff4;
                border-left: 4px solid #38a169;
            }
            
            .leave-details {
                background-color: #fff5f5;
                border-left: 4px solid #c53030;
            }
            
            .action-bar {
                display: flex;
                justify-content: center;
                gap: 15px;
                margin: 20px 0;
                padding: 0 20px;
            }
            
            .action-btn {
                padding: 12px 20px;
                background-color: #4299e1;
                color: white;
                border: none;
                border-radius: 5px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .action-btn:hover {
                background-color: #3182ce;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(66, 153, 225, 0.3);
            }
            
            .action-btn.print {
                background-color: #38a169;
            }
            
            .action-btn.print:hover {
                background-color: #2f855a;
            }
            
            .action-btn.download {
                background-color: #ed8936;
            }
            
            .action-btn.download:hover {
                background-color: #dd6b20;
            }
            
            .payslip-id {
                position: absolute;
                right: 30px;
                top: 50%;
                transform: translateY(-50%);
                font-size: 14px;
                opacity: 0.8;
            }
            
            .bank-details {
                background-color: #fffaf0;
                border: 1px solid #fbd38d;
                border-radius: 6px;
                padding: 15px;
                margin-top: 20px;
            }
            
            .bank-details h4 {
                color: #dd6b20;
                margin-bottom: 10px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .bank-info {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin-top: 10px;
            }
            
            .bank-item {
                padding: 10px;
                background-color: white;
                border-radius: 5px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .bank-label {
                font-size: 12px;
                color: #718096;
                margin-bottom: 5px;
            }
            
            .bank-value {
                font-weight: 600;
                color: #2d3748;
            }
        </style>
    </head>
    <body>
        <?php if (!$print_mode): ?>
        <div class="action-bar">
            <button class="action-btn print" onclick="window.print()">
                <i class="fas fa-print"></i> Print Payslip
            </button>
            <button class="action-btn download" onclick="downloadHTML()">
                <i class="fas fa-download"></i> Download as HTML
            </button>
            <button class="action-btn" onclick="printOptimizedPayslip()">
                <i class="fas fa-print"></i> Optimized Print
            </button>
            <button class="action-btn" onclick="closeWindow()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
        <?php endif; ?>
        
        <div class="container">
            <div class="header">
                <div class="company-logo">
                    <i class="fas fa-building"></i>
                </div>
                <h1>HR PORTAL - PAYSLIP</h1>
                <p>Official Salary Statement with Attendance Records</p>
                <div class="payslip-id">
                    ID: PS<?php echo date('Ym', strtotime($payroll['payroll_month'])) . $payroll['emp_id']; ?>
                </div>
            </div>
            
            <div class="confidential-banner">
                <i class="fas fa-lock"></i> Confidential Document - For Employee Use Only
            </div>
            
            <div class="details-section">
                <div class="company-details">
                    <h2 class="section-title"><i class="fas fa-building"></i> Company Details</h2>
                    <div class="detail-row">
                        <div class="detail-label">Company Name:</div>
                        <div class="detail-value">HR Portal Pvt. Ltd.</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Address:</div>
                        <div class="detail-value">123 Corporate Street, Mumbai - 400001</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">GSTIN:</div>
                        <div class="detail-value">27AAACH0000A1Z5</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">PAN:</div>
                        <div class="detail-value">AAACH0000A</div>
                    </div>
                </div>
                
                <div class="employee-details">
                    <h2 class="section-title"><i class="fas fa-user"></i> Employee Details</h2>
                    <div class="detail-row">
                        <div class="detail-label">Employee ID:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($payroll['emp_id']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Name:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($payroll['first_name'] . ' ' . $payroll['last_name']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Designation:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($payroll['designation']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Department:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($payroll['department_name']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">PAN Number:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($payroll['pan_number'] ?? 'Not Provided'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="pay-period">
                <i class="fas fa-calendar-alt"></i> Pay Period: <?php echo date('F Y', strtotime($payroll['payroll_month'])); ?>
            </div>
            
            <div class="attendance-section">
                <h2 class="section-title"><i class="fas fa-calendar-check"></i> Attendance & Working Hours</h2>
                <div class="attendance-grid">
                    <div class="attendance-item">
                        <div class="attendance-label">Total Working Days</div>
                        <div class="attendance-value"><?php echo $calculations['total_working_days_in_month']; ?></div>
                    </div>
                    <div class="attendance-item">
                        <div class="attendance-label">Present Days</div>
                        <div class="attendance-value"><?php echo $attendance['days_present']; ?></div>
                    </div>
                    <div class="attendance-item">
                        <div class="attendance-label"><i class="fas fa-plane"></i> Half Days</div>
                        <div class="attendance-value"><?php echo $attendance['half_days']; ?></div>
                    </div>
                    <div class="attendance-item">
                        <div class="attendance-label">Regular Hours</div>
                        <div class="attendance-value"><?php echo number_format($calculations['regular_hours'], 1); ?> hrs</div>
                    </div>
                    <div class="attendance-item">
                        <div class="attendance-label ot"><i class="fas fa-clock"></i> Overtime Hours</div>
                        <div class="attendance-value ot"><?php echo number_format($calculations['overtime_hours'], 1); ?> hrs</div>
                    </div>
                    <div class="attendance-item">
                        <div class="attendance-label">Total Hours</div>
                        <div class="attendance-value"><?php echo number_format($calculations['total_hours'], 1); ?> hrs</div>
                    </div>
                    <div class="attendance-item">
                        <div class="attendance-label late"><i class="fas fa-exclamation-triangle"></i> Late Minutes</div>
                        <div class="attendance-value late"><?php echo $attendance['total_late_minutes']; ?> mins</div>
                    </div>
                </div>
                
                <?php if ($calculations['hourly_rate'] > 0): ?>
                <div class="salary-breakdown">
                    <strong><i class="fas fa-calculator"></i> Hourly Rate Calculation:</strong> Basic Salary (₹<?php echo number_format($calculations['basic_salary'], 2); ?>) / <?php echo $calculations['total_working_days_in_month'] * 8; ?> regular hours = ₹<?php echo number_format($calculations['hourly_rate'], 3); ?> per hour
                </div>
                
                <?php if ($calculations['overtime_hours'] > 0): ?>
                <div class="salary-breakdown ot-details">
                    <strong><i class="fas fa-clock"></i> Overtime Calculation:</strong> <?php echo number_format($calculations['overtime_hours'], 1); ?> OT hours × ₹<?php echo number_format($calculations['overtime_rate'], 3); ?> (1.5× hourly rate) = ₹<?php echo number_format($calculations['overtime_pay'], 2); ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <div class="earnings-deductions">
                <div class="earnings">
                    <h2 class="section-title"><i class="fas fa-plus-circle" style="color: #38a169;"></i> Earnings (₹)</h2>
                    <table class="amount-table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Amount (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $earnings_total = 0;
                            foreach ($components as $component): 
                                if ($component['type'] === 'earning'): 
                                    $earnings_total += $component['amount'];
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($component['name']); ?></td>
                                <td class="currency-cell"><?php echo number_format($component['amount'], 2); ?></td>
                            </tr>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                            <?php if ($calculations['overtime_pay'] > 0 && !$overtime_included): ?>
                            <tr>
                                <td>Overtime Pay (<?php echo number_format($calculations['overtime_hours'], 1); ?> hours)</td>
                                <td class="currency-cell"><?php echo number_format($calculations['overtime_pay'], 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="total-row">
                                <td><strong>Total Earnings</strong></td>
                                <td class="currency-cell"><strong>₹<?php echo number_format($total_earnings, 2); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="deductions">
                    <h2 class="section-title"><i class="fas fa-minus-circle" style="color: #c53030;"></i> Deductions (₹)</h2>
                    <table class="amount-table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Amount (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $deductions_total = 0;
                            foreach ($components as $component): 
                                if ($component['type'] === 'deduction'): 
                                    $deductions_total += $component['amount'];
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($component['name']); ?></td>
                                <td class="currency-cell"><?php echo number_format($component['amount'], 2); ?></td>
                            </tr>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                            <tr class="total-row">
                                <td><strong>Total Deductions</strong></td>
                                <td class="currency-cell"><strong>₹<?php echo number_format($total_deductions, 2); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="net-salary" onclick="showSalaryBreakdown()" title="Click to view detailed calculation">
                <i class="fas fa-rupee-sign"></i> NET SALARY: ₹<?php echo number_format($net_salary, 2); ?>
            </div>
            
            <div class="payment-info">
                <h2 class="section-title"><i class="fas fa-credit-card"></i> Payment Information</h2>
                <div class="payment-details">
                    <div class="payment-item">
                        <div class="payment-label"><i class="fas fa-info-circle"></i> Payment Status</div>
                        <?php 
                        $status_class = 'status-pending';
                        if ($payroll['payment_status'] === 'paid') $status_class = 'status-paid';
                        elseif ($payroll['payment_status'] === 'processed') $status_class = 'status-processed';
                        ?>
                        <div class="payment-value <?php echo $status_class; ?>">
                            <?php echo ucfirst($payroll['payment_status']); ?>
                        </div>
                    </div>
                    <div class="payment-item">
                        <div class="payment-label"><i class="fas fa-calendar-day"></i> Payment Date</div>
                        <div class="payment-value">
                            <?php echo $payroll['payment_date'] ? date('d-m-Y', strtotime($payroll['payment_date'])) : 'Pending'; ?>
                        </div>
                    </div>
                    <div class="payment-item">
                        <div class="payment-label"><i class="fas fa-university"></i> Bank</div>
                        <div class="payment-value"><?php echo htmlspecialchars($payroll['bank_name']); ?></div>
                    </div>
                    <div class="payment-item">
                        <div class="payment-label"><i class="fas fa-credit-card"></i> Account No</div>
                        <div class="payment-value">XXXXXX<?php echo substr($payroll['bank_account_number'], -4); ?></div>
                    </div>
                    <div class="payment-item">
                        <div class="payment-label"><i class="fas fa-receipt"></i> Transaction ID</div>
                        <div class="payment-value"><?php echo htmlspecialchars($payroll['transaction_id'] ?? 'N/A'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="footer">
                <p><i class="fas fa-check-circle"></i> This is a computer generated payslip. No signature required.</p>
                <p>Generated on: <?php echo date('d-m-Y H:i:s'); ?></p>
                <p style="margin-top: 10px; font-size: 12px; opacity: 0.7;">
                    <i class="fas fa-copyright"></i> <?php echo date('Y'); ?> HR Portal Pvt. Ltd. All rights reserved.
                </p>
            </div>
        </div>

        <script>
            function downloadHTML() {
                const urlParams = new URLSearchParams(window.location.search);
                const payrollId = urlParams.get('id');
                window.location.href = `?action=download_html&id=${payrollId}`;
            }
            
            function printOptimizedPayslip() {
                const urlParams = new URLSearchParams(window.location.search);
                const payrollId = urlParams.get('id');
                window.open(`?action=print_html&id=${payrollId}`, '_blank');
            }
            
            function closeWindow() {
                if (window.history.length > 1) {
                    window.history.back();
                } else {
                    window.close();
                }
            }
            
            function showSalaryBreakdown() {
                alert("Net Salary Calculation:\n\nTotal Earnings: ₹<?php echo number_format($total_earnings, 2); ?>\nTotal Deductions: ₹<?php echo number_format($total_deductions, 2); ?>\n\nNet Salary: ₹<?php echo number_format($total_earnings, 2); ?> - ₹<?php echo number_format($total_deductions, 2); ?> = ₹<?php echo number_format($net_salary, 2); ?>");
            }
            
            <?php if ($print_mode): ?>
            // Auto-print when in print mode
            window.onload = function() {
                window.print();
                setTimeout(function() {
                    window.close();
                }, 1000);
            };
            <?php endif; ?>
            
            // Add print event listener
            window.addEventListener('beforeprint', function() {
                // Add print-specific class
                document.body.classList.add('print-mode');
            });
            
            window.addEventListener('afterprint', function() {
                // Remove print-specific class
                document.body.classList.remove('print-mode');
            });
        </script>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

// Function to regenerate payslip
function regeneratePayslip($payroll_id) {
    global $conn;
    
    $stmt = $conn->prepare("DELETE FROM payslips WHERE payroll_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $payroll_id, $_SESSION['user_id']);
    $stmt->execute();
    
    header("Location: ?action=view_html&id=" . $payroll_id);
    exit;
}

// Fetch employee details for dashboard
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
        'last_name' => 'User',
        'emp_id' => 'N/A',
        'designation' => 'Not assigned',
        'department_name' => 'Not assigned',
        'shift_name' => 'Not assigned',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'salary' => 0.00,
        'bank_name' => 'Not Provided',
        'bank_account_number' => 'Not Provided',
        'ifsc_code' => 'Not Provided',
        'profile_picture' => null
    ];
}

// Set default values
$employee['first_name'] = $employee['first_name'] ?? 'Employee';
$employee['last_name'] = $employee['last_name'] ?? 'User';
$employee['emp_id'] = $employee['emp_id'] ?? 'N/A';
$employee['designation'] = $employee['designation'] ?? 'Not assigned';
$employee['department_name'] = $employee['department_name'] ?? 'Not assigned';
$employee['shift_name'] = $employee['shift_name'] ?? 'Not assigned';
$employee['start_time'] = $employee['start_time'] ?? '09:00';
$employee['end_time'] = $employee['end_time'] ?? '18:00';
$employee['salary'] = $employee['salary'] ?? 0.00;
$employee['bank_name'] = $employee['bank_name'] ?? 'Not Provided';
$employee['bank_account_number'] = $employee['bank_account_number'] ?? 'Not Provided';
$employee['ifsc_code'] = $employee['ifsc_code'] ?? 'Not Provided';
$employee['profile_picture'] = $employee['profile_picture'] ?? null;

// Fetch payroll records
$payroll_stmt = $conn->prepare("
    SELECT p.*, ps.file_path as payslip_path, ps.generated_at as payslip_generated
    FROM payroll p
    LEFT JOIN payslips ps ON p.id = ps.payroll_id AND ps.user_id = ?
    WHERE p.user_id = ? AND p.user_type = 'employee' 
    ORDER BY p.payroll_month DESC
");
$payroll_stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
$payroll_stmt->execute();
$payroll_result = $payroll_stmt->get_result();
$payroll_records = [];

while ($row = $payroll_result->fetch_assoc()) {
    $payroll_records[] = $row;
}

// Fetch salary structure
$salary_stmt = $conn->prepare("
    SELECT sc.name, sc.type, ss.amount, sc.calculation_type 
    FROM salary_structures ss 
    JOIN salary_components sc ON ss.component_id = sc.id 
    WHERE ss.user_id = ? AND ss.user_type = 'employee' 
    AND (ss.effective_to IS NULL OR ss.effective_to >= CURDATE())
    ORDER BY sc.type DESC, sc.id
");
$salary_stmt->bind_param("i", $_SESSION['user_id']);
$salary_stmt->execute();
$salary_result = $salary_stmt->get_result();
$salary_components = [];

while ($row = $salary_result->fetch_assoc()) {
    $salary_components[] = $row;
}

// Calculate totals
$total_earnings = 0;
$total_deductions = 0;

foreach ($salary_components as $component) {
    if ($component['type'] === 'earning') {
        $total_earnings += $component['amount'];
    } else {
        $total_deductions += $component['amount'];
    }
}

// If no salary components, use employee salary
if (count($salary_components) === 0) {
    $total_earnings = $employee['salary'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Payroll System - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Dashboard CSS remains the same as previous version */
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
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
        }

        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Salary Overview */
        .salary-overview {
            text-align: center;
            margin-bottom: 30px;
        }

        .salary-amount {
            font-size: 48px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 10px;
            font-family: 'Courier New', monospace;
        }

        .salary-period {
            color: var(--gray);
            font-size: 14px;
        }

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
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            background: #f3f4f6;
            transform: translateY(-2px);
        }

        .stat-number {
            font-size: 20px;
            font-weight: 700;
            color: var(--success);
            margin-bottom: 5px;
            font-family: 'Courier New', monospace;
        }

        .stat-number.deduction {
            color: var(--danger);
        }

        .stat-label {
            font-size: 12px;
            color: var(--gray);
            text-transform: uppercase;
        }

        /* Payroll History Table */
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: #f9fafb;
            font-weight: 600;
            color: var(--dark);
            position: sticky;
            top: 0;
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
            display: inline-block;
        }

        .badge-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-processed {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Buttons */
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info), #1d4ed8);
            color: white;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(59, 130, 246, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(245, 158, 11, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
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
            opacity: 0.5;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-small {
            padding: 8px 14px;
            font-size: 12px;
        }

        .rupee-amount {
            font-family: 'Courier New', monospace;
            font-weight: 600;
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
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .salary-amount {
                font-size: 36px;
            }
        }

        /* Loading Overlay */
        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Salary Breakdown */
        .salary-breakdown-card {
            margin-top: 25px;
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 15px;
            border-bottom: 1px solid #e5e7eb;
            transition: background-color 0.2s;
        }

        .breakdown-item:hover {
            background-color: #f9fafb;
        }

        .breakdown-item:last-child {
            border-bottom: none;
            font-weight: bold;
            background: #f7fafc;
            border-radius: 8px;
        }

        .breakdown-label {
            color: var(--gray);
        }

        .breakdown-value {
            font-weight: 600;
            color: var(--dark);
        }

        /* Preview Section */
        .payslip-preview {
            margin-top: 30px;
            text-align: center;
            padding: 30px;
            background: linear-gradient(135deg, #667eea0d 0%, #764ba20d 100%);
            border-radius: 15px;
            border: 2px dashed var(--primary);
        }

        .preview-features {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: center;
            margin: 20px 0;
        }

        .feature-item {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            text-align: center;
            min-width: 150px;
            transition: all 0.3s ease;
        }

        .feature-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }

        .feature-icon {
            font-size: 28px;
            margin-bottom: 10px;
            display: block;
        }

        .feature-text {
            font-weight: 600;
            font-size: 14px;
        }

        /* Tooltips */
        .btn[title] {
            position: relative;
        }

        .btn[title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 5px;
        }

        /* Animation for table rows */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        tbody tr {
            animation: fadeIn 0.5s ease forwards;
            opacity: 0;
        }

        tbody tr:nth-child(1) { animation-delay: 0.1s; }
        tbody tr:nth-child(2) { animation-delay: 0.2s; }
        tbody tr:nth-child(3) { animation-delay: 0.3s; }
        tbody tr:nth-child(4) { animation-delay: 0.4s; }
        tbody tr:nth-child(5) { animation-delay: 0.5s; }
        tbody tr:nth-child(6) { animation-delay: 0.6s; }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading" id="loading">
        <div class="loading-spinner"></div>
    </div>

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
            <li><a href="payroll_emp.php" class="active"><i class="fas fa-file-invoice-dollar"></i> Payroll</a></li>
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
                <h1>Enhanced Payroll System</h1>
                <p>Generate and print professional payslips</p>
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

        <div class="content">
            <!-- Salary Overview Card -->
            <div class="card">
                <h3 class="card-title"><i class="fas fa-chart-line"></i> Salary Overview</h3>
                
                <div class="salary-overview">
                    <div class="salary-amount rupee-amount">₹<?php echo number_format($employee['salary'], 2); ?></div>
                    <div class="salary-period">Monthly Salary</div>
                </div>

                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number rupee-amount">₹<?php echo number_format($total_earnings, 2); ?></div>
                        <div class="stat-label">Total Earnings</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number deduction rupee-amount">₹<?php echo number_format($total_deductions, 2); ?></div>
                        <div class="stat-label">Total Deductions</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number rupee-amount">₹<?php echo number_format($employee['salary'] - $total_deductions, 2); ?></div>
                        <div class="stat-label">Net Salary</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo count($payroll_records); ?></div>
                        <div class="stat-label">Processed Months</div>
                    </div>
                </div>

                <!-- Salary Breakdown -->
                <div class="salary-breakdown-card">
                    <h4 style="margin-bottom: 15px; color: var(--dark); display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-list-alt"></i> Salary Structure
                    </h4>
                    
                    <?php if (count($salary_components) > 0): ?>
                        <div style="margin-bottom: 20px;">
                            <!-- Earnings -->
                            <div style="margin-bottom: 15px;">
                                <h5 style="color: var(--success); margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-plus-circle"></i> Earnings
                                </h5>
                                <?php 
                                $earnings_total = 0;
                                foreach ($salary_components as $component): ?>
                                    <?php if ($component['type'] === 'earning'): ?>
                                        <?php $earnings_total += $component['amount']; ?>
                                        <div class="breakdown-item">
                                            <span class="breakdown-label"><?php echo htmlspecialchars($component['name']); ?></span>
                                            <span class="breakdown-value rupee-amount">₹<?php echo number_format($component['amount'], 2); ?></span>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <div class="breakdown-item">
                                    <span class="breakdown-label"><strong>Total Earnings</strong></span>
                                    <span class="breakdown-value rupee-amount"><strong>₹<?php echo number_format($earnings_total, 2); ?></strong></span>
                                </div>
                            </div>
                            
                            <!-- Deductions -->
                            <div>
                                <h5 style="color: var(--danger); margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-minus-circle"></i> Deductions
                                </h5>
                                <?php 
                                $deductions_total = 0;
                                foreach ($salary_components as $component): ?>
                                    <?php if ($component['type'] === 'deduction'): ?>
                                        <?php $deductions_total += $component['amount']; ?>
                                        <div class="breakdown-item">
                                            <span class="breakdown-label"><?php echo htmlspecialchars($component['name']); ?></span>
                                            <span class="breakdown-value rupee-amount">₹<?php echo number_format($component['amount'], 2); ?></span>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <div class="breakdown-item">
                                    <span class="breakdown-label"><strong>Total Deductions</strong></span>
                                    <span class="breakdown-value rupee-amount"><strong>₹<?php echo number_format($deductions_total, 2); ?></strong></span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Net Salary Display -->
                        <div style="background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; padding: 20px; border-radius: 10px; margin-top: 20px; text-align: center;">
                            <div style="font-size: 12px; opacity: 0.9; margin-bottom: 5px;">Net Salary (Monthly)</div>
                            <div style="font-size: 28px; font-weight: 700;" class="rupee-amount">
                                ₹<?php echo number_format($employee['salary'] - $deductions_total, 2); ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 20px;">
                            <i class="fas fa-file-invoice-dollar"></i>
                            <p>No salary structure configured</p>
                            <p style="font-size: 14px; margin-top: 10px;">Default salary: 
                                <span class="rupee-amount">₹<?php echo number_format($employee['salary'], 2); ?></span>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payroll History Card -->
            <div class="card">
                <h3 class="card-title"><i class="fas fa-history"></i> Payroll History</h3>
                
                <?php if (count($payroll_records) > 0): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Earnings (₹)</th>
                                    <th>Deductions (₹)</th>
                                    <th>Net Salary (₹)</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payroll_records as $record): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo date('F Y', strtotime($record['payroll_month'])); ?></strong>
                                            <?php if ($record['payslip_generated']): ?>
                                                <br>
                                                <small style="color: var(--gray); font-size: 11px;">
                                                    Generated: <?php echo date('d/m/y', strtotime($record['payslip_generated'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="rupee-amount">₹<?php echo number_format($record['total_earnings'], 2); ?></td>
                                        <td class="rupee-amount">₹<?php echo number_format($record['total_deductions'], 2); ?></td>
                                        <td><strong class="rupee-amount">₹<?php echo number_format($record['net_salary'], 2); ?></strong></td>
                                        <td>
                                            <?php 
                                            $badge_class = '';
                                            switch($record['payment_status']) {
                                                case 'paid': $badge_class = 'badge-paid'; break;
                                                case 'pending': $badge_class = 'badge-pending'; break;
                                                case 'processed': $badge_class = 'badge-processed'; break;
                                                default: $badge_class = 'badge-pending';
                                            }
                                            ?>
                                            <span class="status-badge <?php echo $badge_class; ?>">
                                                <?php echo ucfirst($record['payment_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="?action=view_html&id=<?php echo $record['id']; ?>" 
                                                   target="_blank" 
                                                   class="btn btn-primary btn-small"
                                                   onclick="showLoading()"
                                                   title="View in Browser (HTML)">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <a href="?action=download_html&id=<?php echo $record['id']; ?>" 
                                                   class="btn btn-success btn-small"
                                                   onclick="showLoading()"
                                                   title="Download as HTML">
                                                    <i class="fas fa-download"></i> HTML
                                                </a>
                                                <a href="?action=print_html&id=<?php echo $record['id']; ?>" 
                                                   target="_blank" 
                                                   class="btn btn-info btn-small"
                                                   onclick="showLoading()"
                                                   title="Optimized Print View">
                                                    <i class="fas fa-print"></i> Print
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>No payroll records found</p>
                        <p style="font-size: 14px; margin-top: 10px;">Your salary will be processed at the end of the month.</p>
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
        
        // Loading overlay functions
        function showLoading() {
            document.getElementById('loading').style.display = 'flex';
            return true;
        }
        
        function hideLoading() {
            document.getElementById('loading').style.display = 'none';
        }
        
        // Auto-hide loading after 5 seconds (safety)
        setTimeout(hideLoading, 5000);
        
        // Hide loading when page is fully loaded
        window.addEventListener('load', hideLoading);
        
        // Format currency with Indian numbering system
        document.querySelectorAll('.rupee-amount').forEach(element => {
            let text = element.textContent;
            if (text.startsWith('₹')) {
                let amount = text.substring(1);
                element.textContent = '₹' + formatIndianCurrency(amount);
            }
        });
        
        function formatIndianCurrency(amount) {
            amount = amount.replace(/,/g, '');
            if (isNaN(amount)) return amount;
            
            let parts = amount.split('.');
            let integerPart = parts[0];
            let decimalPart = parts.length > 1 ? '.' + parts[1] : '';
            
            let lastThree = integerPart.substring(integerPart.length - 3);
            let otherNumbers = integerPart.substring(0, integerPart.length - 3);
            if (otherNumbers != '') {
                lastThree = ',' + lastThree;
            }
            let formatted = otherNumbers.replace(/\B(?=(\d{2})+(?!\d))/g, ",") + lastThree + decimalPart;
            return formatted;
        }
        
        // Handle download clicks
        document.addEventListener('click', function(e) {
            if (e.target.closest('a[href*="download"]') || e.target.closest('a[href*="print_html"]')) {
                showLoading();
            }
        });
        
        // Show tooltips for action buttons
        const actionButtons = document.querySelectorAll('.action-buttons .btn');
        actionButtons.forEach(btn => {
            const text = btn.textContent.trim();
            let title = '';
            
            if (text.includes('View')) {
                title = 'View payslip in browser (HTML format)';
            } else if (text.includes('HTML')) {
                title = 'Download payslip (HTML format)';
            } else if (text.includes('Print')) {
                title = 'Open optimized print view';
            }
            
            if (title) {
                btn.title = title;
            }
        });
        
        // Add animation to cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animation = 'fadeIn 0.5s ease forwards';
                }
            });
        }, observerOptions);
        
        // Observe all cards
        document.querySelectorAll('.card').forEach(card => {
            observer.observe(card);
        });
        
        // Add confirmation for regenerating payslips
        const regenerateLinks = document.querySelectorAll('a[href*="regenerate"]');
        regenerateLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                if (!confirm('This will regenerate the payslip. Continue?')) {
                    e.preventDefault();
                }
            });
        });
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + P for print (when on payslip page)
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                if (window.location.href.includes('action=view')) {
                    window.print();
                }
            }
            
            // Escape key to close sidebar on mobile
            if (e.key === 'Escape' && window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('active');
            }
        });
        
        // Enhance table row hover effects
        const tableRows = document.querySelectorAll('tbody tr');
        tableRows.forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.01)';
                this.style.transition = 'transform 0.2s ease';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
            });
        });
    </script>
</body>
</html>