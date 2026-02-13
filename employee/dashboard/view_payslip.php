<?php
// view_payslip.php - Employee Payslip with Database Integration
session_start();
require_once "../../db_connection.php";

// Check if employee is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'employee') {
    header("Location: login_emp.php");
    exit();
}

// Get payroll ID from GET parameter
$payroll_id = isset($_GET['payroll_id']) ? intval($_GET['payroll_id']) : 0;

if ($payroll_id <= 0) {
    die("Invalid payroll ID");
}

// Fetch payroll and employee details
$stmt = $conn->prepare("
    SELECT 
        p.*,
        e.first_name,
        e.last_name,
        e.emp_id,
        e.designation,
        e.salary,
        e.bank_account_number,
        e.bank_name,
        e.ifsc_code,
        d.name as department_name
    FROM payroll p
    JOIN employees e ON p.user_id = e.user_id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE p.id = ? AND p.user_id = ? AND p.user_type = 'employee'
");

$stmt->bind_param("ii", $payroll_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Payslip not found or access denied");
}

$payroll = $result->fetch_assoc();

// Fetch salary components for this payroll
$components_stmt = $conn->prepare("
    SELECT 
        sc.name,
        sc.type,
        sc.calculation_type,
        ps.amount
    FROM payroll_salary_components ps
    JOIN salary_components sc ON ps.component_id = sc.id
    WHERE ps.payroll_id = ?
    ORDER BY sc.type DESC, sc.id
");

$components_stmt->bind_param("i", $payroll_id);
$components_stmt->execute();
$components_result = $components_stmt->get_result();
$components = [];

while ($row = $components_result->fetch_assoc()) {
    $components[] = $row;
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

// Include FPDF
require('fpdf/fpdf.php');

// Create PDF
class PDF extends FPDF
{
    // Page header
    function Header()
    {
        // Company Logo
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, 'HR Portal Pvt. Ltd.', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, '123 Corporate Street, Mumbai - 400001', 0, 1, 'C');
        $this->Cell(0, 5, 'Email: hr@hrportal.com | Phone: +91-22-12345678', 0, 1, 'C');
        $this->Ln(5);
        
        // Payslip title
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, 'PAYSLIP', 0, 1, 'C');
        $this->Ln(5);
        
        // Line
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(5);
    }
    
    // Page footer
    function Footer()
    {
        // Position at 1.5 cm from bottom
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

// Create PDF instance
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// Set font
$pdf->SetFont('Arial', '', 10);

// Company and Employee Details Table
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(95, 8, 'Company Details', 1, 0, 'C');
$pdf->Cell(95, 8, 'Employee Details', 1, 1, 'C');

$pdf->SetFont('Arial', '', 9);

// Company Details
$pdf->Cell(95, 6, 'HR Portal Pvt. Ltd.', 'LR', 0, 'L');
$pdf->Cell(95, 6, 'Employee ID: ' . $payroll['emp_id'], 'LR', 1, 'L');

$pdf->Cell(95, 6, '123 Corporate Street', 'LR', 0, 'L');
$pdf->Cell(95, 6, 'Name: ' . $payroll['first_name'] . ' ' . $payroll['last_name'], 'LR', 1, 'L');

$pdf->Cell(95, 6, 'Mumbai - 400001', 'LR', 0, 'L');
$pdf->Cell(95, 6, 'Designation: ' . $payroll['designation'], 'LR', 1, 'L');

$pdf->Cell(95, 6, 'GSTIN: 27AAACH0000A1Z5', 'LR', 0, 'L');
$pdf->Cell(95, 6, 'Department: ' . $payroll['department_name'], 'LR', 1, 'L');

$pdf->Cell(95, 6, 'PAN: AAACH0000A', 'LR', 0, 'L');
$pdf->Cell(95, 6, 'Bank: ' . $payroll['bank_name'], 'LR', 1, 'L');

$pdf->Cell(95, 6, '', 'LRB', 0, 'L');
$pdf->Cell(95, 6, 'Account: ' . $payroll['bank_account_number'], 'LRB', 1, 'L');

$pdf->Ln(10);

// Pay Period and Payment Details
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(95, 8, 'Pay Period', 1, 0, 'C');
$pdf->Cell(95, 8, 'Payment Details', 1, 1, 'C');

$pdf->SetFont('Arial', '', 9);
$pdf->Cell(95, 6, 'Month: ' . date('F Y', strtotime($payroll['payroll_month'])), 'LR', 0, 'L');
$pdf->Cell(95, 6, 'Payment Date: ' . date('d-m-Y', strtotime($payroll['payment_date'])), 'LR', 1, 'L');

$pdf->Cell(95, 6, 'Days Present: ' . $payroll['days_present'], 'LR', 0, 'L');
$pdf->Cell(95, 6, 'Payment Status: ' . ucfirst($payroll['payment_status']), 'LR', 1, 'L');

$pdf->Cell(95, 6, 'Days Absent: ' . $payroll['days_absent'], 'LRB', 0, 'L');
$pdf->Cell(95, 6, 'Transaction ID: ' . ($payroll['transaction_id'] ?: 'N/A'), 'LRB', 1, 'L');

$pdf->Ln(10);

// Earnings Section
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 8, 'EARNINGS', 0, 1, 'L');
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(120, 8, 'Description', 1, 0, 'C');
$pdf->Cell(40, 8, 'Amount (₹)', 1, 1, 'C');

$pdf->SetFont('Arial', '', 9);
$total_earnings_display = 0;
foreach ($components as $component) {
    if ($component['type'] === 'earning') {
        $pdf->Cell(120, 7, $component['name'], 'LR', 0, 'L');
        $pdf->Cell(40, 7, number_format($component['amount'], 2), 'LR', 1, 'R');
        $total_earnings_display += $component['amount'];
    }
}

// Add basic salary if no earnings found
if ($total_earnings_display == 0) {
    $pdf->Cell(120, 7, 'Basic Salary', 'LR', 0, 'L');
    $pdf->Cell(40, 7, number_format($payroll['salary'], 2), 'LR', 1, 'R');
    $total_earnings_display = $payroll['salary'];
}

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(120, 7, 'Total Earnings', 'LRT', 0, 'R');
$pdf->Cell(40, 7, number_format($total_earnings_display, 2), 'LRT', 1, 'R');

$pdf->Ln(10);

// Deductions Section
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 8, 'DEDUCTIONS', 0, 1, 'L');
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(120, 8, 'Description', 1, 0, 'C');
$pdf->Cell(40, 8, 'Amount (₹)', 1, 1, 'C');

$pdf->SetFont('Arial', '', 9);
$total_deductions_display = 0;
foreach ($components as $component) {
    if ($component['type'] === 'deduction') {
        $pdf->Cell(120, 7, $component['name'], 'LR', 0, 'L');
        $pdf->Cell(40, 7, number_format($component['amount'], 2), 'LR', 1, 'R');
        $total_deductions_display += $component['amount'];
    }
}

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(120, 7, 'Total Deductions', 'LRT', 0, 'R');
$pdf->Cell(40, 7, number_format($total_deductions_display, 2), 'LRT', 1, 'R');

$pdf->Ln(10);

// Net Salary Section
$net_salary = $total_earnings_display - $total_deductions_display;
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(120, 10, 'NET SALARY PAYABLE', 1, 0, 'C');
$pdf->Cell(40, 10, '₹' . number_format($net_salary, 2), 1, 1, 'C');

$pdf->Ln(15);

// Notes and Footer
$pdf->SetFont('Arial', 'I', 9);
$pdf->MultiCell(0, 5, 'Notes:', 0, 'L');
$pdf->MultiCell(0, 5, '1. This is a computer generated payslip.', 0, 'L');
$pdf->MultiCell(0, 5, '2. Please keep this payslip for your records.', 0, 'L');
$pdf->MultiCell(0, 5, '3. Any discrepancies must be reported within 7 days.', 0, 'L');

$pdf->Ln(10);
$pdf->Cell(0, 5, '_________________________________', 0, 1, 'R');
$pdf->Cell(0, 5, 'Authorized Signatory', 0, 1, 'R');

$pdf->Ln(5);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(0, 5, 'Generated on: ' . date('d-m-Y H:i:s'), 0, 1, 'L');

// Generate filename
$filename = 'Payslip_' . $payroll['emp_id'] . '_' . date('F_Y', strtotime($payroll['payroll_month'])) . '.pdf';

// Check if we're in download mode
if (isset($_GET['download']) && $_GET['download'] == 'true') {
    // Save to server first (optional)
    $filepath = __DIR__ . '/payslips/' . $filename;
    
    // Ensure directory exists
    if (!is_dir(__DIR__ . '/payslips')) {
        mkdir(__DIR__ . '/payslips', 0777, true);
    }
    
    // Save PDF to file
    $pdf->Output('F', $filepath);
    
    // Update database with file path
    $update_stmt = $conn->prepare("
        INSERT INTO payslips (payroll_id, user_id, file_path, generated_at) 
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE file_path = ?, generated_at = NOW()
    ");
    
    $update_stmt->bind_param("iiss", 
        $payroll_id, 
        $_SESSION['user_id'], 
        $filepath, 
        $filepath
    );
    $update_stmt->execute();
    
    // Force download
    $pdf->Output('D', $filename);
    exit;
} else {
    // Just display in browser
    $pdf->Output('I', $filename);
    exit;
}
?>