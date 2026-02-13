<?php
/**
 * ATTENDANCE TEMPLATE GENERATOR
 * HR Management Portal
 * 
 * Location: hr/dashboard/download_attendance_template.php
 * Description: Generates Excel template - COMPLETELY EMPTY, ONLY HEADER ROW
 */

session_start();
if (!isset($_SESSION['hr_logged_in']) || $_SESSION['hr_logged_in'] !== true) {
    header('Location: hr_dashboard.php');
    exit();
}

// Database configuration
$conn = new mysqli('localhost', 'root', '', 'hr_management_portal');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Load PhpSpreadsheet
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// ============================================
// CREATE SPREADSHEET
// ============================================

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Attendance');

/* ===============================
   HEADER ROW - ROW 1 ONLY
   NO SAMPLE DATA - COMPLETELY EMPTY
================================ */

$sheet->setCellValue('A1', 'USER ID');
$sheet->setCellValue('B1', 'DATE (YYYY-MM-DD)');
$sheet->setCellValue('C1', 'CHECK IN (HH:MM:SS)');
$sheet->setCellValue('D1', 'CHECK OUT (HH:MM:SS)');
$sheet->setCellValue('E1', 'STATUS');
$sheet->setCellValue('F1', 'REMARKS');

// Style the header
$sheet->getStyle('A1:F1')->getFont()->setBold(true);
$sheet->getStyle('A1:F1')->getFill()
      ->setFillType(Fill::FILL_SOLID)
      ->getStartColor()->setRGB('4472C4');
$sheet->getStyle('A1:F1')->getFont()->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A1:F1')->getBorders()->getAllBorders()
      ->setBorderStyle(Border::BORDER_THIN);

// Center align header
$sheet->getStyle('A1:F1')->getAlignment()
      ->setHorizontal(Alignment::HORIZONTAL_CENTER)
      ->setVertical(Alignment::VERTICAL_CENTER);

// Set column widths
$sheet->getColumnDimension('A')->setWidth(20);
$sheet->getColumnDimension('B')->setWidth(20);
$sheet->getColumnDimension('C')->setWidth(20);
$sheet->getColumnDimension('D')->setWidth(20);
$sheet->getColumnDimension('E')->setWidth(20);
$sheet->getColumnDimension('F')->setWidth(45);

// Format date column as text to prevent auto-formatting
$sheet->getStyle('B2:B1048576')
      ->getNumberFormat()
      ->setFormatCode(NumberFormat::FORMAT_TEXT);

// Add autofilter
$sheet->setAutoFilter('A1:F1');

// NO SAMPLE DATA - COMPLETELY EMPTY TEMPLATE
// Rows 2 onwards are completely empty

/* ===============================
   OUTPUT FILE
================================ */

$conn->close();

// Clean output buffer
if (ob_get_level()) ob_end_clean();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Attendance_Template.xlsx"');
header('Cache-Control: max-age=0');
header('Cache-Control: private');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>