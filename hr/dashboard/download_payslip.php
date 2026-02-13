<?php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['employee_id'], $_GET['id'])) {
    die("Unauthorized");
}

$employee_id = $_SESSION['employee_id'];
$payslip_id  = intval($_GET['id']);

$stmt = $conn->prepare("
    SELECT file_path
    FROM payslips
    WHERE id = ? AND employee_id = ?
");
$stmt->bind_param("ii", $payslip_id, $employee_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    $file = "../" . $row['file_path'];

    if (file_exists($file)) {
        header("Content-Type: application/pdf");
        header("Content-Disposition: attachment; filename=" . basename($file));
        header("Content-Length: " . filesize($file));
        readfile($file);
        exit;
    }
}

echo "Payslip not found.";
