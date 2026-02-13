<?php
// notification_manage.php - Notification Management for HR Dashboard
session_start();

// Include database connection
require_once '../../db_connection.php';

// Email configuration
define('EMAIL_ENABLED', true);
define('EMAIL_HOST', 'smtp.gmail.com');
define('EMAIL_PORT', 587);
define('EMAIL_USERNAME', 'svmedicaps@gmail.com');
define('EMAIL_PASSWORD', 'idsehhtvafsciitg');
define('EMAIL_FROM', 'svmedicaps@gmail.com');
define('EMAIL_FROM_NAME', 'HR Portal');
define('EMAIL_SECURE', 'tls');

// Include PHPMailer
require_once __DIR__ . '/../../vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../../vendor/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if HR is logged in
if (!isset($_SESSION['hr_id'])) {
    header("Location: login_hr.php");
    exit();
}

// Get HR details
$hr_id = $_SESSION['hr_id'] ?? 1;
$hr_email = $_SESSION['hr_email'] ?? 'hr@hrportal.com';
$hr_role = $_SESSION['hr_role'] ?? 'hr';

// Process actions
$action = $_GET['action'] ?? '';
$notification_id = $_GET['id'] ?? 0;
$message = '';

// Function to send email notification
function sendEmailNotification($user_email, $title, $message_text, $type = 'info') {
    if (!EMAIL_ENABLED || empty($user_email)) {
        return false;
    }
    
    try {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host       = EMAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = EMAIL_USERNAME;
        $mail->Password   = EMAIL_PASSWORD;
        $mail->SMTPSecure = EMAIL_SECURE;
        $mail->Port       = EMAIL_PORT;
        
        // Recipients
        $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
        $mail->addAddress($user_email);
        
        // Email subject
        $subject = "[HR Portal] $title";
        $mail->Subject = $subject;
        
        // Email body with HTML formatting
        $type_colors = [
            'info' => '#3b82f6',
            'warning' => '#f59e0b',
            'success' => '#10b981',
            'error' => '#ef4444'
        ];
        
        $color = $type_colors[$type] ?? '#3b82f6';
        $type_label = ucfirst($type);
        
        $html_body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: $color; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background-color: #f8fafc; padding: 30px; border-radius: 0 0 8px 8px; }
                .notification-type { display: inline-block; background-color: $color; color: white; padding: 5px 10px; border-radius: 4px; font-size: 12px; margin-bottom: 15px; }
                .message-box { background-color: white; padding: 20px; border-radius: 6px; border-left: 4px solid $color; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; color: #64748b; font-size: 12px; }
                .btn { display: inline-block; background-color: $color; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-top: 15px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>HR Portal Notification</h1>
                </div>
                <div class='content'>
                    <div class='notification-type'>$type_label Notification</div>
                    <h2>$title</h2>
                    <div class='message-box'>
                        <p>" . nl2br(htmlspecialchars($message_text)) . "</p>
                    </div>
                    <p>This notification has been sent to you from the HR Portal.</p>
                    <p>Please login to your HR Portal account to view more details.</p>
                    <div style='text-align: center; margin-top: 25px;'>
                        <a href='http://localhost/emp_system/hr/dashboard/' class='btn'>Go to HR Portal</a>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated message from HR Portal. Please do not reply to this email.</p>
                    <p>&copy; " . date('Y') . " HR Portal. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        // Plain text version
        $plain_body = "HR Portal Notification\n";
        $plain_body .= "====================\n\n";
        $plain_body .= "Title: $title\n";
        $plain_body .= "Type: $type_label\n\n";
        $plain_body .= "Message:\n";
        $plain_body .= "$message_text\n\n";
        $plain_body .= "This notification has been sent to you from the HR Portal.\n";
        $plain_body .= "Please login to your HR Portal account to view more details.\n\n";
        $plain_body .= "This is an automated message from HR Portal. Please do not reply to this email.\n";
        
        // Set email body
        $mail->isHTML(true);
        $mail->Body = $html_body;
        $mail->AltBody = $plain_body;
        
        // Send email
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $e->getMessage());
        return false;
    }
}

// Function to get user email by ID
function getUserEmail($conn, $user_id) {
    if ($user_id == 0) {
        return null;
    }
    
    // Try to get email from employees table first
    $sql = "SELECT email FROM employees WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['email'];
    }
    $stmt->close();
    
    // If not found in employees, try users table
    $sql = "SELECT email FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['email'];
    }
    
    return null;
}

// Function to get employee name
function getEmployeeName($conn, $user_id) {
    if ($user_id == 0) {
        return 'All Employees';
    }
    
    // Try to get name from employees table
    $sql = "SELECT CONCAT(first_name, ' ', last_name) as full_name FROM employees WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['full_name'] ?? 'Unknown Employee';
    }
    $stmt->close();
    
    // If not found in employees, try users table
    $sql = "SELECT email FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['email'] ?? 'Unknown User';
    }
    
    return 'Unknown Employee';
}

// Handle test email request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['test_email'])) {
    $test_email = $_POST['email'] ?? $hr_email;
    $test_title = "Test Email from HR Portal";
    $test_message = "This is a test email to verify that the email notification system is working properly.\n\nIf you're receiving this email, the notification system is configured correctly.";
    
    if (sendEmailNotification($test_email, $test_title, $test_message, 'info')) {
        $message = '<div class="alert success">Test email sent successfully to ' . htmlspecialchars($test_email) . '!</div>';
    } else {
        $message = '<div class="alert error">Failed to send test email. Check your PHPMailer configuration.</div>';
    }
}

// Handle send notification to specific employee by ID (Employee ID tab)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_employee_notification'])) {
    $employee_id = $_POST['employee_id'] ?? '';
    $subject = $_POST['subject'] ?? '';
    $body = $_POST['message'] ?? '';
    
    if (!empty($employee_id) && !empty($subject) && !empty($body)) {
        // Check if employee exists
        $stmt = $conn->prepare("SELECT id, email, CONCAT(first_name, ' ', last_name) as full_name FROM employees WHERE id = ?");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $employeeEmail = $row['email'];
            $employeeName = $row['full_name'];
            
            // Send email using PHPMailer
            if (sendEmailNotification($employeeEmail, $subject, $body, 'info')) {
                // Save to notifications table
                $sql = "INSERT INTO notifications (user_id, title, message, type, is_read, created_at) 
                        VALUES (?, ?, ?, 'info', 0, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iss", $employee_id, $subject, $body);
                $stmt->execute();
                
                $message = '<div class="alert success">Notification sent successfully to ' . htmlspecialchars($employeeName) . ' (ID: ' . $employee_id . ')!</div>';
            } else {
                $message = '<div class="alert error">Failed to send email. Please check your PHPMailer configuration.</div>';
            }
        } else {
            $message = '<div class="alert error">Employee not found with ID: ' . htmlspecialchars($employee_id) . '</div>';
        }
        $stmt->close();
    } else {
        $message = '<div class="alert warning">Please fill all required fields.</div>';
    }
}

// Handle general notification sending (Single, Multiple, Departments, All) - CORRECTED
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {

    $title        = trim($_POST['title'] ?? '');
    $message_text = trim($_POST['message_general'] ?? '');
    $type         = $_POST['type'] ?? 'info';
    $send_email   = isset($_POST['send_email']) && $_POST['send_email'] == '1';
    $active_tab   = $_POST['active_tab'] ?? '';

    $recipients = [];

    if (empty($title) || empty($message_text)) {
        $message = '<div class="alert error">Title and message are required.</div>';
        goto END_NOTIFICATION;
    }

    switch ($active_tab) {

        /* ================= SINGLE EMPLOYEE ================= */
        case 'single':

            if (empty($_POST['user_id']) || $_POST['user_id'] == '0') {
                $message = '<div class="alert error">Please select a valid employee.</div>';
                goto END_NOTIFICATION;
            }

            $recipients[] = (int) $_POST['user_id'];
            break;

        /* ================= MULTIPLE EMPLOYEES ================= */
        case 'multiple':

            if (empty($_POST['user_ids']) || !is_array($_POST['user_ids'])) {
                $message = '<div class="alert error">Please select at least one employee.</div>';
                goto END_NOTIFICATION;
            }

            foreach ($_POST['user_ids'] as $uid) {
                if (!empty($uid)) {
                    $recipients[] = (int) $uid;
                }
            }
            break;

        /* ================= DEPARTMENTS ================= */
        case 'groups':

            if (empty($_POST['group_type'])) {
                $message = '<div class="alert error">Please select a department.</div>';
                goto END_NOTIFICATION;
            }

            $sql = "SELECT id FROM employees 
                    WHERE department_id = ? AND employment_status = 'active'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $_POST['group_type']);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row['id'];
            }
            $stmt->close();
            break;

        /* ================= ALL EMPLOYEES ================= */
        case 'all':

            $sql = "SELECT id FROM employees WHERE employment_status = 'active'";
            $result = $conn->query($sql);

            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row['id'];
            }
            break;

        default:
            $message = '<div class="alert error">Invalid notification mode.</div>';
            goto END_NOTIFICATION;
    }

    $recipients = array_unique($recipients);

    if (empty($recipients)) {
        $message = '<div class="alert error">No valid recipients found.</div>';
        goto END_NOTIFICATION;
    }

    $stmt = $conn->prepare(
        "INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
         VALUES (?, ?, ?, ?, 0, NOW())"
    );

    $sent = 0;
    foreach ($recipients as $uid) {
        $stmt->bind_param("isss", $uid, $title, $message_text, $type);
        if ($stmt->execute()) {
            $sent++;

            if ($send_email) {
                $email = getUserEmail($conn, $uid);
                if ($email) {
                    sendEmailNotification($email, $title, $message_text, $type);
                }
            }
        }
    }
    $stmt->close();

    $message = '<div class="alert success">
        <i class="fas fa-check-circle"></i>
        Notification sent to ' . $sent . ' employee(s).
    </div>';

END_NOTIFICATION:
}

// Handle bulk actions
// if ($_SERVER['REQUEST_METHOD'] == 'POST') {
//     if (isset($_POST['mark_all_read'])) {
//         $sql = "UPDATE notifications SET is_read = 1 WHERE is_read = 0";
//         if ($conn->query($sql)) {
//             $message = '<div class="alert success">All notifications marked as read!</div>';
//         } else {
//             $message = '<div class="alert error">Error marking notifications as read.</div>';
//         }
//     }
//     elseif (isset($_POST['delete_all_read'])) {
//         $sql = "DELETE FROM notifications WHERE is_read = 1";
//         if ($conn->query($sql)) {
//             $message = '<div class="alert success">All read notifications deleted!</div>';
//         } else {
//             $message = '<div class="alert error">Error deleting notifications.</div>';
//         }
//     }
// }

// Handle individual notification actions
if ($action && $notification_id) {
    if ($action == 'mark_read') {
        $sql = "UPDATE notifications SET is_read = 1 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $notification_id);
        $stmt->execute();
        $stmt->close();
        $message = '<div class="alert success">Notification marked as read!</div>';
    }
    elseif ($action == 'delete') {
        $sql = "DELETE FROM notifications WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $notification_id);
        $stmt->execute();
        $stmt->close();
        $message = '<div class="alert success">Notification deleted!</div>';
    }
}

// Get notification statistics
$stats = [];
$result = $conn->query("SELECT COUNT(*) as total FROM notifications");
$stats['total_notifications'] = $result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) as total FROM notifications WHERE is_read = 0");
$stats['unread_notifications'] = $result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) as total FROM notifications WHERE is_read = 1");
$stats['read_notifications'] = $result->fetch_assoc()['total'];

// Get notifications with user information
$sql = "SELECT n.*, 
               COALESCE(e.email, u.email) as email,
               CONCAT(e.first_name, ' ', e.last_name) as emp_name
        FROM notifications n 
        LEFT JOIN employees e ON n.user_id = e.id 
        LEFT JOIN users u ON n.user_id = u.id 
        ORDER BY n.created_at DESC 
        LIMIT 50";
$notifications_result = $conn->query($sql);

// Get all active employees for sending notifications
$employees_result = $conn->query("SELECT 
    e.id, 
    e.email, 
    CONCAT(e.first_name, ' ', e.last_name) as full_name,
    e.department_id,
    d.name as department_name,
    e.emp_id
    FROM employees e 
    LEFT JOIN departments d ON e.department_id = d.id 
    WHERE e.employment_status = 'active'
    ORDER BY e.first_name, e.last_name");

// Get departments for grouping
$depts_result = $conn->query("SELECT DISTINCT d.id, d.name as department 
                              FROM departments d 
                              INNER JOIN employees e ON d.id = e.department_id 
                              WHERE e.employment_status = 'active'
                              ORDER BY d.name");

// Get notification type distribution
$type_stats = [];
$result = $conn->query("SELECT type, COUNT(*) as count FROM notifications GROUP BY type");
while ($row = $result->fetch_assoc()) {
    $type_stats[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Management - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            text-align: center;
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
            margin: 0 auto 20px;
            font-size: 24px;
            color: white;
        }
        
        .stat-icon.total { background: linear-gradient(135deg, var(--primary) 0%, var(--info) 100%); }
        .stat-icon.unread { background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%); }
        .stat-icon.read { background: linear-gradient(135deg, var(--success) 0%, #34d399 100%); }
        
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
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .dashboard-card {
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
        }
        
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .notifications-list {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .notification-item {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: flex-start;
            transition: all 0.3s ease;
        }
        
        .notification-item:hover {
            background: #f8fafc;
        }
        
        .notification-item.unread {
            background: #f0f9ff;
            border-left: 4px solid var(--info);
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            flex-shrink: 0;
            color: white;
        }
        
        .notification-icon.info { background: var(--info); }
        .notification-icon.warning { background: var(--warning); }
        .notification-icon.success { background: var(--success); }
        .notification-icon.error { background: var(--danger); }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--dark);
        }
        
        .notification-message {
            color: var(--gray);
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .notification-meta {
            font-size: 12px;
            color: var(--gray);
        }
        
        .notification-actions {
            display: flex;
            gap: 10px;
            margin-left: 15px;
        }
        
        .action-btn {
            padding: 5px 10px;
            border-radius: 6px;
            border: none;
            background: #f1f5f9;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 12px;
        }
        
        .action-btn:hover {
            background: var(--primary);
            color: white;
        }
        
        .action-btn.delete:hover {
            background: var(--danger);
        }
        
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
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .multi-select-container {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px;
            max-height: 200px;
            overflow-y: auto;
            background: white;
        }
        
        .user-checkbox-item {
            display: flex;
            align-items: center;
            padding: 8px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .user-checkbox-item:last-child {
            border-bottom: none;
        }
        
        .user-checkbox-item:hover {
            background: #f8fafc;
        }
        
        .user-checkbox-item input[type="checkbox"] {
            margin-right: 10px;
        }
        
        .user-info-small {
            display: flex;
            flex-direction: column;
        }
        
        .user-email {
            font-size: 14px;
            color: var(--dark);
        }
        
        .user-role {
            font-size: 12px;
            color: var(--gray);
        }
        
        .recipient-tabs {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .tab-button {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: var(--gray);
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
            flex: 1;
            min-width: 120px;
            text-align: center;
        }
        
        .tab-button.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        
        .tab-button:hover:not(.active) {
            color: var(--dark);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .group-options {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .group-option {
            padding: 12px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .group-option:hover {
            background: #e2e8f0;
            border-color: var(--primary);
        }
        
        .group-option.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .group-option i {
            display: block;
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .selection-summary {
            background: #f0f9ff;
            border: 1px solid var(--info);
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .selection-summary strong {
            color: var(--primary);
        }
        
        .email-options {
            background: #fef3c7;
            border: 1px solid var(--warning);
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .email-option {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .email-option input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        
        .email-option label {
            margin-bottom: 0;
            font-weight: 600;
            color: var(--dark);
            cursor: pointer;
        }
        
        .email-note {
            font-size: 12px;
            color: var(--gray);
            margin-top: 5px;
            padding-left: 28px;
        }
        
        .email-preview {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            display: none;
        }
        
        .email-preview h5 {
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .preview-placeholder {
            background: white;
            border-radius: 6px;
            padding: 15px;
            border-left: 4px solid var(--info);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark);
        }
        
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }
        
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
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
        
        .alert.warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .bulk-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .type-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
            margin-right: 10px;
            margin-bottom: 5px;
        }
        
        .type-badge.info { background: #dbeafe; color: #1e40af; }
        .type-badge.warning { background: #fef3c7; color: #92400e; }
        .type-badge.success { background: #d1fae5; color: #065f46; }
        .type-badge.error { background: #fee2e2; color: #991b1b; }
        
        .email-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            margin-left: 10px;
        }
        
        .email-status.active {
            color: var(--success);
        }
        
        .email-status.inactive {
            color: var(--warning);
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
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            margin-left: 10px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: var(--success);
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
    </style>
</head>
<body>
    <?php include 'sidebar_hr.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-bell"></i> Notification Management</h1>
                <p id="currentDateTime">Manage and send notifications to users</p>
            </div>
            
            <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div class="user-profile">
                    <div class="user-avatar" style="width: 40px; height: 40px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                        <?php echo strtoupper(substr($hr_email, 0, 2)); ?>
                    </div>
                    <div class="user-info" style="margin-left: 10px;">
                        <h4 style="margin: 0; font-size: 14px;">HR Manager</h4>
                        <span style="font-size: 12px; color: var(--gray);"><?php echo htmlspecialchars($hr_email); ?></span>
                    </div>
                </div>
                <div class="email-status <?php echo EMAIL_ENABLED ? 'active' : 'inactive'; ?>">
                    <i class="fas fa-<?php echo EMAIL_ENABLED ? 'envelope-circle-check' : 'envelope'; ?>"></i>
                    <span>Email <?php echo EMAIL_ENABLED ? 'Enabled' : 'Disabled'; ?></span>
                </div>
            </div>
        </div>
        
        <?php echo $message; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_notifications']; ?></h3>
                    <p>Total Notifications</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon unread">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['unread_notifications']; ?></h3>
                    <p>Unread Notifications</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon read">
                    <i class="fas fa-envelope-open"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['read_notifications']; ?></h3>
                    <p>Read Notifications</p>
                </div>
            </div>
        </div>
        
        <div class="bulk-actions">
            <!-- <form method="POST" style="display: inline;">
                <button type="submit" name="mark_all_read" class="btn btn-primary">
                    <i class="fas fa-check-double"></i> Mark All as Read
                </button>
            </form> -->
            <!-- <form method="POST" style="display: inline;">
                <button type="submit" name="delete_all_read" class="btn btn-secondary" 
                        onclick="return confirm('Are you sure you want to delete all read notifications?')">
                    <i class="fas fa-trash"></i> Delete All Read
                </button>
            </form> -->
            <!-- <button type="button" class="btn btn-outline" onclick="testEmailFunctionality()">
                <i class="fas fa-envelope"></i> Test Email
            </button>
        </div> -->
        
        <!-- <div class="dashboard-grid"> -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>Send New Notification</h3>
                    <small>Select multiple recipients or groups</small>
                </div>
                <form method="POST" id="notificationForm">
                    <!-- Hidden input to track active tab -->
                    <input type="hidden" name="active_tab" id="active_tab" value="single">
                    
                    <div class="recipient-tabs">
                        <button type="button" class="tab-button active" data-tab="single">Single Employee</button>
                        <button type="button" class="tab-button" data-tab="multiple">Multiple Employees</button>
                        <button type="button" class="tab-button" data-tab="groups">Departments</button>
                        <button type="button" class="tab-button" data-tab="all">All Employees</button>
                        <button type="button" class="tab-button" data-tab="employee">Employee ID</button>
                    </div>
                    
                    <!-- Single Employee Tab -->
                    <!-- <div class="tab-content active" id="single-tab"> -->
                        <div class="form-group">
                            <label for="user_id">Select Employee</label>
                            <select name="user_id" id="user_id" class="form-control">
                                <option value="">Select Employee</option>
                                <?php 
                                if ($employees_result) {
                                    $employees_result->data_seek(0);
                                    while($emp = $employees_result->fetch_assoc()): ?>
                                        <option value="<?php echo $emp['id']; ?>">
                                            <?php echo htmlspecialchars($emp['full_name']) . ' - ' . htmlspecialchars($emp['emp_id']) . ' (' . ($emp['department_name'] ?? 'No Dept') . ')'; ?>
                                        </option>
                                    <?php endwhile; 
                                } ?>
                            </select>
                        <!-- </div> -->
                    <!-- </div> -->
                    
                    <!-- Multiple Employees Tab -->
                    <div class="tab-content" id="multiple-tab">
                        <div class="form-group">
                            <label>Select Multiple Employees</label>
                            <div class="multi-select-container" id="multiSelectContainer">
                                <?php 
                                if ($employees_result) {
                                    $employees_result->data_seek(0);
                                    while($emp = $employees_result->fetch_assoc()): ?>
                                        <div class="user-checkbox-item">
                                            <input type="checkbox" name="user_ids[]" value="<?php echo $emp['id']; ?>" 
                                                   class="user-checkbox" id="emp_<?php echo $emp['id']; ?>">
                                            <label for="emp_<?php echo $emp['id']; ?>" style="flex: 1; cursor: pointer;">
                                                <div class="user-info-small">
                                                    <span class="user-email"><?php echo htmlspecialchars($emp['full_name']); ?></span>
                                                    <span class="user-role"><?php echo htmlspecialchars($emp['emp_id']) . ' - ' . ($emp['department_name'] ?? 'No Department'); ?></span>
                                                </div>
                                            </label>
                                        </div>
                                    <?php endwhile; 
                                } ?>
                            </div>
                            <div style="margin-top: 10px; display: flex; gap: 10px;">
                                <button type="button" class="btn btn-secondary" onclick="selectAllUsers()" style="padding: 8px 16px;">
                                    <i class="fas fa-check-double"></i> Select All
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="deselectAllUsers()" style="padding: 8px 16px;">
                                    <i class="fas fa-times"></i> Deselect All
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Departments Tab -->
                    <div class="tab-content" id="groups-tab">
                        <div class="form-group">
                            <label>Select Department</label>
                            <input type="hidden" name="group_type" id="group_type" value="">
                            <div class="group-options">
                                <?php 
                                if ($depts_result && $depts_result->num_rows > 0):
                                    $depts_result->data_seek(0);
                                    while($dept = $depts_result->fetch_assoc()): 
                                        $dept_id = htmlspecialchars($dept['id']);
                                        $dept_name = htmlspecialchars($dept['department']);
                                        $icon = getDepartmentIcon($dept_name);
                                ?>
                                    <div class="group-option" data-role="<?php echo $dept_id; ?>">
                                        <i class="fas fa-<?php echo $icon; ?>"></i>
                                        <span><?php echo $dept_name; ?></span>
                                    </div>
                                <?php endwhile; 
                                else: ?>
                                    <div style="text-align: center; padding: 20px; color: var(--gray);">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <p>No departments found.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- All Employees Tab -->
                    <div class="tab-content" id="all-tab">
                        <div class="form-group">
                            <div style="text-align: center; padding: 30px; background: #f8fafc; border-radius: 10px;">
                                <i class="fas fa-users" style="font-size: 48px; color: var(--primary); margin-bottom: 15px;"></i>
                                <h4 style="color: var(--dark); margin-bottom: 10px;">Send to All Employees</h4>
                                <p style="color: var(--gray);">This will send the notification to all active employees.</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Employee ID Tab -->
                    <div class="tab-content" id="employee-tab">
                        <div class="form-group">
                            <label for="employee_id">Employee ID (Database ID)</label>
                            <input type="number" name="employee_id" id="employee_id" class="form-control" placeholder="Enter Employee Database ID" min="1">
                        </div>
                        <div class="form-group">
                            <label for="subject">Subject</label>
                            <input type="text" name="subject" id="subject" class="form-control" placeholder="Enter email subject">
                        </div>
                        <div class="form-group">
                            <label for="message">Message</label>
                            <textarea name="message" id="message" class="form-control" rows="4" placeholder="Enter notification message"></textarea>
                            <div id="charCount" style="text-align: right; font-size: 12px; color: var(--gray); margin-top: 5px;">
                                0 characters
                            </div>
                        </div>
                        <button type="submit" name="send_employee_notification" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Send to Employee
                        </button>
                    </div>
                    
                    <div class="selection-summary" id="selectionSummary" style="display: none;">
                        <i class="fas fa-info-circle"></i>
                        <span id="summaryText">No recipients selected</span>
                    </div>
                    
                    <!-- Email options - Only show for tabs 1-4 -->
                    <div class="email-options" id="emailOptions">
                        <div class="email-option">
                            <input type="checkbox" name="send_email" id="send_email" value="1" checked>
                            <label for="send_email">Also send email notification</label>
                            <div class="toggle-switch">
                                <input type="checkbox" id="email_toggle" checked>
                                <span class="toggle-slider"></span>
                            </div>
                        </div>
                        <div class="email-note">
                            <i class="fas fa-info-circle"></i>
                            An email will be sent to each selected employee's registered email address.
                            <?php if (!EMAIL_ENABLED): ?>
                                <br><strong style="color: var(--warning);">Note: Email functionality is currently disabled in configuration.</strong>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="email-preview" id="emailPreview">
                        <h5><i class="fas fa-eye"></i> Email Preview</h5>
                        <div class="preview-placeholder" id="previewContent">
                            Email preview will appear here...
                        </div>
                    </div>
                    
                    <!-- Notification details - Only show for tabs 1-4 -->
                    <div id="notificationDetails">
                        <div class="form-group">
                            <label for="type">Notification Type</label>
                            <select name="type" id="type" class="form-control" required onchange="updateEmailPreview()">
                                <option value="info">Info</option>
                                <option value="warning">Warning</option>
                                <option value="success">Success</option>
                                <option value="error">Error</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="title">Title</label>
                            <input type="text" name="title" id="title" class="form-control" 
                                   placeholder="Enter notification title" required oninput="updateEmailPreview()">
                        </div>
                        
                        <div class="form-group">
                            <label for="message_general">Message</label>
                            <textarea name="message_general" id="message_general" class="form-control" 
                                      rows="4" placeholder="Enter notification message" required oninput="updateEmailPreview()"></textarea>
                            <div id="charCountGeneral" style="text-align: right; font-size: 12px; color: var(--gray); margin-top: 5px;">
                                0 characters
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="button" class="btn btn-outline" onclick="showEmailPreview()">
                                <i class="fas fa-eye"></i> Preview Email
                            </button>
                            <button type="submit" name="send_notification" class="btn btn-primary" style="flex: 1;">
                                <i class="fas fa-paper-plane"></i> Send Notification
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- <div class="dashboard-card">
                <div class="card-header">
                    <h3>Recent Notifications</h3>
                </div>
                <div class="notifications-list">
                    <?php if ($notifications_result && $notifications_result->num_rows > 0): ?>
                        <?php while($notification = $notifications_result->fetch_assoc()): ?>
                            <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                                <div class="notification-icon <?php echo htmlspecialchars($notification['type']); ?>">
                                    <i class="fas fa-<?php 
                                        switch($notification['type']) {
                                            case 'warning': echo 'exclamation-triangle'; break;
                                            case 'success': echo 'check-circle'; break;
                                            case 'error': echo 'exclamation-circle'; break;
                                            default: echo 'info-circle';
                                        }
                                    ?>"></i>
                                </div>
                                
                                <div class="notification-content">
                                    <div class="notification-title">
                                        <?php echo htmlspecialchars($notification['title']); ?>
                                    </div>
                                    <div class="notification-message">
                                        <?php echo htmlspecialchars($notification['message']); ?>
                                    </div>
                                    <div class="notification-meta">
                                        To: <?php echo htmlspecialchars($notification['emp_name'] ?? ($notification['email'] ?? 'All Employees')); ?> 
                                        | <?php echo date('M d, Y H:i', strtotime($notification['created_at'])); ?>
                                        <span class="type-badge <?php echo htmlspecialchars($notification['type']); ?>">
                                            <?php echo ucfirst(htmlspecialchars($notification['type'])); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="notification-actions">
                                    <?php if (!$notification['is_read']): ?>
                                        <a href="?action=mark_read&id=<?php echo $notification['id']; ?>" 
                                           class="action-btn" title="Mark as Read">
                                            <i class="fas fa-envelope-open"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a href="?action=delete&id=<?php echo $notification['id']; ?>" 
                                       class="action-btn delete" 
                                       onclick="return confirm('Are you sure you want to delete this notification?')"
                                       title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: var(--gray);">
                            <i class="fas fa-bell-slash" style="font-size: 48px; margin-bottom: 20px;"></i>
                            <p>No notifications found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div> -->
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>Type Distribution</h3>
                </div>
                <div style="padding: 20px;">
                    <?php if (!empty($type_stats)): ?>
                        <?php foreach($type_stats as $type): ?>
                            <div style="margin-bottom: 15px;">
                                <span class="type-badge <?php echo htmlspecialchars($type['type']); ?>">
                                    <?php echo ucfirst(htmlspecialchars($type['type'])); ?>
                                </span>
                                <span style="float: right; font-weight: bold;">
                                    <?php echo $type['count']; ?>
                                </span>
                            </div>
                            <div style="height: 8px; background: #e2e8f0; border-radius: 4px; margin: 5px 0 15px;">
                                <div style="height: 100%; width: <?php echo ($type['count'] / max($stats['total_notifications'], 1) * 100); ?>%; 
                                    background: <?php 
                                        switch($type['type']) {
                                            case 'warning': echo 'var(--warning)'; break;
                                            case 'success': echo 'var(--success)'; break;
                                            case 'error': echo 'var(--danger)'; break;
                                            default: echo 'var(--info)';
                                        }
                                    ?>; border-radius: 4px;"></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; color: var(--gray); padding: 20px;">
                            No notification type data available.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            const emailOptions = document.getElementById('emailOptions');
            const notificationDetails = document.getElementById('notificationDetails');
            const employeeTab = document.getElementById('employee-tab');
            const activeTabInput = document.getElementById('active_tab');
            
            function updateTabDisplay() {
                const activeTab = document.querySelector('.tab-button.active').getAttribute('data-tab');
                
                // Update hidden input
                if (activeTabInput) {
                    activeTabInput.value = activeTab;
                }
                
                if (activeTab === 'employee') {
                    emailOptions.style.display = 'none';
                    notificationDetails.style.display = 'none';
                    if (employeeTab) employeeTab.style.display = 'block';
                } else {
                    emailOptions.style.display = 'block';
                    notificationDetails.style.display = 'block';
                    if (employeeTab) employeeTab.style.display = 'none';
                }
                
                updateSelectionSummary();
            }
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    tabContents.forEach(content => {
                        content.classList.remove('active');
                        if (content.id === tabId + '-tab') {
                            content.classList.add('active');
                        }
                    });
                    
                    updateTabDisplay();
                });
            });
            
            const groupOptions = document.querySelectorAll('.group-option');
            groupOptions.forEach(option => {
                option.addEventListener('click', function() {
                    const role = this.getAttribute('data-role');
                    
                    groupOptions.forEach(opt => opt.classList.remove('active'));
                    this.classList.add('active');
                    
                    document.getElementById('group_type').value = role;
                    updateSelectionSummary();
                });
            });
            
            const userCheckboxes = document.querySelectorAll('.user-checkbox');
            userCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateSelectionSummary);
            });
            
            const singleSelect = document.getElementById('user_id');
            if (singleSelect) {
                singleSelect.addEventListener('change', updateSelectionSummary);
            }
            
            const employeeIdInput = document.getElementById('employee_id');
            if (employeeIdInput) {
                employeeIdInput.addEventListener('input', updateSelectionSummary);
            }
            
            const emailCheckbox = document.getElementById('send_email');
            const emailToggle = document.getElementById('email_toggle');
            
            if (emailToggle) {
                emailToggle.addEventListener('change', function() {
                    if (emailCheckbox) emailCheckbox.checked = this.checked;
                });
            }
            
            if (emailCheckbox) {
                emailCheckbox.addEventListener('change', function() {
                    if (emailToggle) emailToggle.checked = this.checked;
                });
            }
            
            const messageTextarea = document.getElementById('message');
            const charCount = document.getElementById('charCount');
            
            if (messageTextarea && charCount) {
                messageTextarea.addEventListener('input', function() {
                    const length = this.value.length;
                    charCount.textContent = length + ' characters';
                    
                    if (length > 500) {
                        charCount.style.color = 'var(--warning)';
                    } else if (length > 200) {
                        charCount.style.color = 'var(--info)';
                    } else {
                        charCount.style.color = 'var(--success)';
                    }
                });
            }
            
            const messageGeneral = document.getElementById('message_general');
            const charCountGeneral = document.getElementById('charCountGeneral');
            
            if (messageGeneral && charCountGeneral) {
                messageGeneral.addEventListener('input', function() {
                    const length = this.value.length;
                    charCountGeneral.textContent = length + ' characters';
                    
                    if (length > 500) {
                        charCountGeneral.style.color = 'var(--warning)';
                    } else if (length > 200) {
                        charCountGeneral.style.color = 'var(--info)';
                    } else {
                        charCountGeneral.style.color = 'var(--success)';
                    }
                    
                    updateEmailPreview();
                });
            }
            
            updateTabDisplay();
            
            if (messageTextarea && charCount) {
                charCount.textContent = messageTextarea.value.length + ' characters';
            }
            if (messageGeneral && charCountGeneral) {
                charCountGeneral.textContent = messageGeneral.value.length + ' characters';
            }
        });
        
        function updateSelectionSummary() {
            const summaryElement = document.getElementById('selectionSummary');
            const summaryText = document.getElementById('summaryText');
            const activeTab = document.querySelector('.tab-button.active').getAttribute('data-tab');
            
            let summary = '';
            let selectedCount = 0;
            
            switch(activeTab) {
                case 'single':
                    const singleSelect = document.getElementById('user_id');
                    if (singleSelect && singleSelect.value) {
                        const selectedOption = singleSelect.options[singleSelect.selectedIndex];
                        summary = '1 employee selected: ' + selectedOption.text;
                        selectedCount = 1;
                    } else {
                        summary = 'No employee selected';
                    }
                    break;
                    
                case 'multiple':
                    const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
                    selectedCount = selectedCheckboxes.length;
                    summary = selectedCount + ' employee(s) selected';
                    break;
                    
                case 'groups':
                    const selectedGroup = document.querySelector('.group-option.active');
                    if (selectedGroup) {
                        const role = selectedGroup.textContent.trim();
                        summary = 'All ' + role + ' employees';
                        selectedCount = 1;
                    } else {
                        summary = 'No department selected';
                    }
                    break;
                    
                case 'all':
                    summary = 'All Employees';
                    selectedCount = 1;
                    break;
                    
                case 'employee':
                    const employeeId = document.getElementById('employee_id').value;
                    if (employeeId) {
                        summary = 'Employee ID: ' + employeeId;
                        selectedCount = 1;
                    } else {
                        summary = 'Enter Employee ID';
                    }
                    break;
            }
            
            if (summaryText) {
                summaryText.textContent = summary;
            }
            
            if (summaryElement) {
                if (selectedCount > 0) {
                    summaryElement.style.display = 'block';
                } else {
                    summaryElement.style.display = 'none';
                }
            }
        }
        
        function selectAllUsers() {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateSelectionSummary();
        }
        
        function deselectAllUsers() {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateSelectionSummary();
        }
        
        function updateEmailPreview() {
            const title = document.getElementById('title')?.value || '';
            const message = document.getElementById('message_general')?.value || '';
            const type = document.getElementById('type')?.value || 'info';
            
            if (!title || !message) {
                return;
            }
            
            const typeColors = {
                'info': '#3b82f6',
                'warning': '#f59e0b',
                'success': '#10b981',
                'error': '#ef4444'
            };
            
            const color = typeColors[type] || '#3b82f6';
            const typeLabel = type.charAt(0).toUpperCase() + type.slice(1);
            
            const previewHTML = `
                <div style="font-family: Arial, sans-serif; line-height: 1.6;">
                    <div style="background-color: ${color}; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
                        <h2 style="margin: 0;">HR Portal Notification</h2>
                    </div>
                    <div style="background-color: #f8fafc; padding: 20px; border-radius: 0 0 8px 8px;">
                        <div style="background-color: ${color}; color: white; padding: 5px 10px; border-radius: 4px; display: inline-block; font-size: 12px; margin-bottom: 10px;">
                            ${typeLabel} Notification
                        </div>
                        <h3 style="color: #1e293b; margin: 10px 0;">${title}</h3>
                        <div style="background-color: white; padding: 15px; border-radius: 6px; border-left: 4px solid ${color}; margin: 15px 0;">
                            <p style="margin: 0;">${message.replace(/\n/g, '<br>')}</p>
                        </div>
                        <p style="color: #64748b; font-size: 14px;">This is an automated email from HR Portal.</p>
                    </div>
                </div>
            `;
            
            const previewContent = document.getElementById('previewContent');
            if (previewContent) {
                previewContent.innerHTML = previewHTML;
            }
        }
        
        function showEmailPreview() {
            const title = document.getElementById('title')?.value || '';
            const message = document.getElementById('message_general')?.value || '';
            
            if (!title || !message) {
                alert('Please enter both title and message to preview.');
                return;
            }
            
            updateEmailPreview();
            const emailPreview = document.getElementById('emailPreview');
            if (emailPreview) {
                emailPreview.style.display = 'block';
            }
        }
        
        function testEmailFunctionality() {
            if (!confirm('This will send a test email to your HR email address. Continue?')) {
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const testEmailInput = document.createElement('input');
            testEmailInput.type = 'hidden';
            testEmailInput.name = 'test_email';
            testEmailInput.value = '1';
            form.appendChild(testEmailInput);
            
            const emailInput = document.createElement('input');
            emailInput.type = 'hidden';
            emailInput.name = 'email';
            emailInput.value = '<?php echo htmlspecialchars($hr_email); ?>';
            form.appendChild(emailInput);
            
            document.body.appendChild(form);
            form.submit();
        }
        
        function updateDateTime() {
            const now = new Date();
            const dateString = now.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            const dateTimeElement = document.getElementById('currentDateTime');
            if (dateTimeElement) {
                dateTimeElement.textContent = `Manage notifications | Today is ${dateString}`;
            }
        }
        
        updateDateTime();
        
        const notificationForm = document.getElementById('notificationForm');
        if (notificationForm) {
            notificationForm.addEventListener('submit', function(e) {
                const activeTab = document.querySelector('.tab-button.active').getAttribute('data-tab');
                
                if (activeTab === 'employee') {
                    const employeeId = document.getElementById('employee_id').value;
                    const subject = document.getElementById('subject').value;
                    const message = document.getElementById('message').value;
                    
                    if (!employeeId || !subject || !message) {
                        e.preventDefault();
                        alert('Please fill all required fields for employee notification.');
                        return false;
                    }
                } else {
                    let hasRecipients = false;
                    
                    switch(activeTab) {
                        case 'single':
                            const singleSelect = document.getElementById('user_id');
                            hasRecipients = singleSelect && singleSelect.value !== '';
                            break;
                            
                        case 'multiple':
                            const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
                            hasRecipients = selectedCheckboxes.length > 0;
                            break;
                            
                        case 'groups':
                            const selectedGroup = document.querySelector('.group-option.active');
                            hasRecipients = selectedGroup !== null;
                            break;
                            
                        case 'all':
                            hasRecipients = true;
                            break;
                    }
                    
                    if (!hasRecipients) {
                        e.preventDefault();
                        alert('Please select at least one recipient.');
                        return false;
                    }
                    
                    const title = document.getElementById('title').value.trim();
                    const messageGeneral = document.getElementById('message_general').value.trim();
                    
                    if (!title || !messageGeneral) {
                        e.preventDefault();
                        alert('Please fill in both title and message fields.');
                        return false;
                    }
                }
                
                return true;
            });
        }
    </script>
</body>
</html>

<?php
function getDepartmentIcon($department) {
    $department = strtolower($department);
    switch($department) {
        case 'hr': return 'user-tie';
        case 'it': return 'laptop-code';
        case 'sales': return 'chart-line';
        case 'marketing': return 'bullhorn';
        case 'finance': return 'money-bill-wave';
        case 'operations': return 'cogs';
        case 'engineering': return 'wrench';
        case 'support': return 'headset';
        case 'admin': return 'user-shield';
        case 'development': return 'code';
        case 'design': return 'paint-brush';
        default: return 'building';
    }
}
?>