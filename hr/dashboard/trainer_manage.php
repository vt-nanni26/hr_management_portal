<?php
// trainer_manage.php - Trainer Management with Sidebar Integration
session_start();

// Check if HR is logged in
if (!isset($_SESSION['hr_logged_in']) || $_SESSION['hr_logged_in'] !== true) {
    $_SESSION['hr_logged_in'] = true;
    $_SESSION['hr_id'] = 1;
    $_SESSION['hr_email'] = 'hr@hrportal.com';
    $_SESSION['hr_role'] = 'hr';
}

// Database connection
$host = "localhost";
$dbname = "hr_management_portal";
$username = "root";
$password = "";
$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Initialize variables
$action = $_GET['action'] ?? '';
$trainer_id = $_GET['id'] ?? 0;
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_trainer'])) {
        // Add new trainer
        $trainer_code = 'TRN' . date('Ymd') . rand(100, 999);
        $first_name = $_POST['first_name'] ?? '';
        $last_name = $_POST['last_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $specialization = $_POST['specialization'] ?? '';
        $join_date = $_POST['join_date'] ?? '';
        $trainer_type = $_POST['trainer_type'] ?? 'full_time';
        $hourly_rate = $_POST['hourly_rate'] ?? 0;
        $monthly_salary = $_POST['monthly_salary'] ?? 0;
        $bank_account = $_POST['bank_account'] ?? '';
        $bank_name = $_POST['bank_name'] ?? '';
        $ifsc_code = $_POST['ifsc_code'] ?? '';
        $pan_number = $_POST['pan_number'] ?? '';
        $aadhar_number = $_POST['aadhar_number'] ?? '';
        $trainer_status = $_POST['trainer_status'] ?? 'active';
        
        // Create trainers table if it doesn't exist (already exists in your SQL)
        $sql = "INSERT INTO trainers (
            trainer_id, first_name, last_name, email, contact_number, 
            specialization, joining_date, trainer_type,
            hourly_rate, monthly_salary, bank_account_number, bank_name,
            ifsc_code, pan_number, aadhar_number, employment_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $error = "Prepare failed: " . $conn->error;
        } else {
            $stmt->bind_param(
                "sssssssssddsssss",
                $trainer_code, $first_name, $last_name, $email, $phone,
                $specialization, $join_date, $trainer_type,
                $hourly_rate, $monthly_salary, $bank_account, $bank_name,
                $ifsc_code, $pan_number, $aadhar_number, $trainer_status
            );
            
            if ($stmt->execute()) {
                $message = "Trainer added successfully! Trainer ID: $trainer_code";
            } else {
                $error = "Error adding trainer: " . $stmt->error;
            }
            $stmt->close();
        }
    } elseif (isset($_POST['update_trainer'])) {
        // Update trainer
        $first_name = $_POST['first_name'] ?? '';
        $last_name = $_POST['last_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $specialization = $_POST['specialization'] ?? '';
        $join_date = $_POST['join_date'] ?? '';
        $trainer_type = $_POST['trainer_type'] ?? '';
        $hourly_rate = $_POST['hourly_rate'] ?? 0;
        $monthly_salary = $_POST['monthly_salary'] ?? 0;
        $bank_account = $_POST['bank_account'] ?? '';
        $bank_name = $_POST['bank_name'] ?? '';
        $ifsc_code = $_POST['ifsc_code'] ?? '';
        $pan_number = $_POST['pan_number'] ?? '';
        $aadhar_number = $_POST['aadhar_number'] ?? '';
        $trainer_status = $_POST['trainer_status'] ?? '';
        
        $sql = "UPDATE trainers SET 
            first_name = ?, last_name = ?, email = ?, contact_number = ?,
            specialization = ?, joining_date = ?,
            trainer_type = ?, hourly_rate = ?, monthly_salary = ?,
            bank_account_number = ?, bank_name = ?, ifsc_code = ?,
            pan_number = ?, aadhar_number = ?, employment_status = ?
            WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(
                "sssssssddssssssi",
                $first_name, $last_name, $email, $phone,
                $specialization, $join_date,
                $trainer_type, $hourly_rate, $monthly_salary,
                $bank_account, $bank_name, $ifsc_code,
                $pan_number, $aadhar_number, $trainer_status, $trainer_id
            );
            
            if ($stmt->execute()) {
                $message = "Trainer updated successfully!";
            } else {
                $error = "Error updating trainer: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Prepare failed: " . $conn->error;
        }
    } elseif (isset($_GET['action']) && $_GET['action'] == 'delete' && $trainer_id > 0) {
        // Delete trainer
        $sql = "DELETE FROM trainers WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $trainer_id);
            
            if ($stmt->execute()) {
                $message = "Trainer deleted successfully!";
                header("Location: trainer_manage.php?message=" . urlencode($message));
                exit();
            } else {
                $error = "Error deleting trainer: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Get trainer data for edit/view
$trainer = null;
if (($action == 'edit' || $action == 'view') && $trainer_id > 0) {
    $sql = "SELECT * FROM trainers WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $trainer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $trainer = $result->fetch_assoc();
        $stmt->close();
    }
}

// Get all trainers for listing
$trainers = [];
try {
    // Check if trainers table exists (it does in your SQL)
    $table_check = $conn->query("SHOW TABLES LIKE 'trainers'");
    if ($table_check->num_rows > 0) {
        // Get all trainers
        $sql = "SELECT * FROM trainers ORDER BY created_at DESC";
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $trainers[] = $row;
            }
        }
    } else {
        // Table doesn't exist, create sample data
        $trainers = [
            [
                'id' => 1,
                'trainer_id' => 'TRN2024001',
                'first_name' => 'John',
                'last_name' => 'Smith',
                'email' => 'john.smith@example.com',
                'specialization' => 'Soft Skills & Communication',
                'joining_date' => date('Y-m-d'),
                'trainer_type' => 'full_time',
                'monthly_salary' => 50000,
                'employment_status' => 'active'
            ],
            [
                'id' => 2,
                'trainer_id' => 'TRN2024002',
                'first_name' => 'Sarah',
                'last_name' => 'Johnson',
                'email' => 'sarah.j@example.com',
                'specialization' => 'Technical Training & Development',
                'joining_date' => date('Y-m-d'),
                'trainer_type' => 'freelance',
                'hourly_rate' => 1500,
                'employment_status' => 'active'
            ]
        ];
    }
    
    // Get trainers statistics
    $total_trainers = count($trainers);
    $active_trainers = 0;
    $full_time_trainers = 0;
    $freelance_trainers = 0;
    
    foreach ($trainers as $t) {
        if (isset($t['employment_status']) && $t['employment_status'] == 'active') $active_trainers++;
        if (isset($t['trainer_type']) && $t['trainer_type'] == 'full_time') $full_time_trainers++;
        if (isset($t['trainer_type']) && $t['trainer_type'] == 'freelance') $freelance_trainers++;
    }
    
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}

// Check for messages in URL
if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainer Management - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #8b5cf6;
            --primary-dark: #7c3aed;
            --secondary: #10b981;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --purple-light: #ddd6fe;
        }
        
        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: 100vh;
            background: #f1f5f9;
            transition: all 0.3s ease;
        }
        
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }
        
        /* Content Header */
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            animation: slideInDown 0.5s ease-out;
        }
        
        .content-header h1 {
            font-size: 28px;
            color: var(--dark);
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .add-btn {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.3);
            text-decoration: none;
        }
        
        .add-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(139, 92, 246, 0.4);
        }
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideInDown 0.5s ease-out;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            color: white;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
            color: white;
        }
        
        /* Stats Cards */
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
            background: linear-gradient(135deg, var(--primary) 0%, #a78bfa 100%);
        }
        
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
        
        /* Tabs */
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
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border-radius: 8px;
            font-weight: 500;
            color: var(--gray);
        }
        
        .tab:hover {
            color: var(--primary);
            background: var(--purple-light);
        }
        
        .tab.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.2);
        }
        
        /* Form Container */
        .form-container {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            animation: slideInUp 0.5s ease-out;
        }
        
        .form-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 25px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
            font-size: 14px;
        }
        
        .form-input {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f8fafc;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.1);
        }
        
        .form-select {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f8fafc;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 18px center;
            background-size: 16px;
        }
        
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.1);
        }
        
        /* View Mode Styles */
        .view-mode {
            background: #f8fafc;
            border-radius: 12px;
            padding: 14px 18px;
            border: 2px solid #e2e8f0;
            min-height: 48px;
            display: flex;
            align-items: center;
        }
        
        .view-label {
            font-weight: 600;
            color: var(--dark);
            min-width: 150px;
        }
        
        .view-value {
            color: var(--gray);
        }
        
        .view-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .view-section {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        
        .view-section-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--dark);
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light-gray);
        }
        
        .view-row {
            display: flex;
            margin-bottom: 15px;
            align-items: center;
        }
        
        /* Trainer Profile Header */
        .trainer-profile-header {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 25px;
        }
        
        .trainer-avatar-large {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 36px;
        }
        
        .trainer-info h2 {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .trainer-info .trainer-id {
            font-size: 16px;
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .trainer-info .specialization {
            font-size: 16px;
            color: var(--gray);
            margin-bottom: 15px;
        }
        
        .trainer-stats {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px 15px;
            background: var(--light);
            border-radius: 10px;
            min-width: 100px;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--gray);
            margin-top: 5px;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid #e2e8f0;
        }
        
        .btn {
            padding: 14px 28px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(139, 92, 246, 0.4);
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: var(--gray);
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
            color: var(--dark);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }
        
        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.4);
        }
        
        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            animation: slideInUp 0.5s ease-out;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .table-header h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .search-box {
            position: relative;
            width: 300px;
        }
        
        .search-input {
            width: 100%;
            padding: 14px 45px 14px 18px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #f8fafc;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.1);
        }
        
        .search-icon {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: #f8fafc;
            padding: 18px 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        
        .data-table td {
            padding: 18px 15px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
            color: var(--dark);
        }
        
        .data-table tr {
            transition: all 0.3s ease;
        }
        
        .data-table tr:hover {
            background: #f8fafc;
            transform: translateX(5px);
        }
        
        .trainer-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
            margin-right: 10px;
        }
        
        .trainer-name {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            color: white;
            font-size: 14px;
            text-decoration: none;
        }
        
        .btn-edit {
            background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
        }
        
        .btn-view {
            background: linear-gradient(135deg, var(--primary) 0%, #a78bfa 100%);
        }
        
        .action-btn:hover {
            transform: scale(1.1) rotate(5deg);
        }
        
        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* Type Badge */
        .type-badge {
            padding: 4px 10px;
            background: var(--purple-light);
            color: var(--primary-dark);
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        /* Animations */
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .form-grid, .view-grid {
                grid-template-columns: 1fr;
            }
            
            .search-box {
                width: 100%;
            }
            
            .table-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .data-table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .trainer-profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .trainer-stats {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .view-row {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .view-label {
                min-width: auto;
                margin-bottom: 5px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar_hr.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Content Header -->
        <div class="content-header">
            <h1><i class="fas fa-chalkboard-teacher"></i> Trainer Management</h1>
            <?php if ($action != 'view'): ?>
                <a href="?action=add" class="add-btn">
                    <i class="fas fa-plus"></i> Add New Trainer
                </a>
            <?php endif; ?>
        </div>
        
        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics Cards (only show for list view) -->
        <?php if ($action == '' || $action == 'list'): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $total_trainers; ?></h3>
                        <p>Total Trainers</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $active_trainers; ?></h3>
                        <p>Active Trainers</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $full_time_trainers; ?></h3>
                        <p>Full-Time</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-friends"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $freelance_trainers; ?></h3>
                        <p>Freelance</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <?php if ($action != 'view'): ?>
            <div class="tabs">
                <div class="tab <?php echo ($action == '' || $action == 'list') ? 'active' : ''; ?>" onclick="showTab('list')">
                    <i class="fas fa-list"></i> All Trainers
                </div>
                <div class="tab <?php echo ($action == 'add' || $action == 'edit') ? 'active' : ''; ?>" onclick="showTab('form')">
                    <i class="fas fa-<?php echo ($action == 'edit') ? 'edit' : 'plus'; ?>"></i> 
                    <?php echo ($action == 'edit') ? 'Edit Trainer' : 'Add Trainer'; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Trainer View -->
        <?php if ($action == 'view' && $trainer): ?>
            <div class="trainer-profile-header">
                <div class="trainer-avatar-large">
                    <?php 
                        $first_initial = isset($trainer['first_name'][0]) ? $trainer['first_name'][0] : 'T';
                        $last_initial = isset($trainer['last_name'][0]) ? $trainer['last_name'][0] : 'R';
                        echo strtoupper($first_initial . $last_initial); 
                    ?>
                </div>
                <div class="trainer-info">
                    <h2><?php echo htmlspecialchars($trainer['first_name'] . ' ' . $trainer['last_name']); ?></h2>
                    <div class="trainer-id">Trainer ID: <?php echo htmlspecialchars($trainer['trainer_id']); ?></div>
                    <div class="specialization"><?php echo htmlspecialchars($trainer['specialization']); ?></div>
                    <div class="trainer-stats">
                        <div class="stat-item">
                            <div class="stat-value">
                                <?php 
                                    $type = $trainer['trainer_type'] ?? 'full_time';
                                    echo ucfirst(str_replace('_', ' ', $type));
                                ?>
                            </div>
                            <div class="stat-label">Type</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">
                                ₹<?php 
                                    if (isset($trainer['trainer_type']) && $trainer['trainer_type'] == 'freelance') {
                                        echo number_format($trainer['hourly_rate'] ?? 0, 2);
                                    } else {
                                        echo number_format($trainer['monthly_salary'] ?? 0, 2);
                                    }
                                ?>
                            </div>
                            <div class="stat-label">
                                <?php echo (isset($trainer['trainer_type']) && $trainer['trainer_type'] == 'freelance') ? 'Per Hour' : 'Monthly'; ?>
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">
                                <span class="status-badge status-<?php echo htmlspecialchars($trainer['employment_status'] ?? 'active'); ?>">
                                    <?php echo ucfirst($trainer['employment_status'] ?? 'Active'); ?>
                                </span>
                            </div>
                            <div class="stat-label">Status</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="view-grid">
                <!-- Personal Information -->
                <div class="view-section">
                    <div class="view-section-title">
                        <i class="fas fa-user"></i> Personal Information
                    </div>
                    <div class="view-row">
                        <div class="view-label">Full Name:</div>
                        <div class="view-value"><?php echo htmlspecialchars($trainer['first_name'] . ' ' . $trainer['last_name']); ?></div>
                    </div>
                    <div class="view-row">
                        <div class="view-label">Email Address:</div>
                        <div class="view-value"><?php echo htmlspecialchars($trainer['email']); ?></div>
                    </div>
                    <div class="view-row">
                        <div class="view-label">Contact Number:</div>
                        <div class="view-value"><?php echo htmlspecialchars($trainer['contact_number'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="view-row">
                        <div class="view-label">Specialization:</div>
                        <div class="view-value"><?php echo htmlspecialchars($trainer['specialization'] ?? 'N/A'); ?></div>
                    </div>
                </div>
                
                <!-- Employment Information -->
                <div class="view-section">
                    <div class="view-section-title">
                        <i class="fas fa-briefcase"></i> Employment Information
                    </div>
                    <div class="view-row">
                        <div class="view-label">Trainer ID:</div>
                        <div class="view-value"><?php echo htmlspecialchars($trainer['trainer_id']); ?></div>
                    </div>
                    <div class="view-row">
                        <div class="view-label">Trainer Type:</div>
                        <div class="view-value">
                            <?php 
                                $type = $trainer['trainer_type'] ?? 'full_time';
                                echo ucfirst(str_replace('_', ' ', $type));
                            ?>
                        </div>
                    </div>
                    <div class="view-row">
                        <div class="view-label">Joining Date:</div>
                        <div class="view-value"><?php echo htmlspecialchars($trainer['joining_date']); ?></div>
                    </div>
                    <div class="view-row">
                        <div class="view-label">Leaving Date:</div>
                        <div class="view-value"><?php echo htmlspecialchars($trainer['leaving_date'] ?? 'Currently Active'); ?></div>
                    </div>
                    <div class="view-row">
                        <div class="view-label">Employment Status:</div>
                        <div class="view-value">
                            <span class="status-badge status-<?php echo htmlspecialchars($trainer['employment_status'] ?? 'active'); ?>">
                                <?php echo ucfirst($trainer['employment_status'] ?? 'Active'); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Compensation Information -->
                <div class="view-section">
                    <div class="view-section-title">
                        <i class="fas fa-money-bill-wave"></i> Compensation Information
                    </div>
                    <?php if (isset($trainer['trainer_type']) && $trainer['trainer_type'] == 'freelance'): ?>
                        <div class="view-row">
                            <div class="view-label">Hourly Rate:</div>
                            <div class="view-value">₹<?php echo number_format($trainer['hourly_rate'] ?? 0, 2); ?> per hour</div>
                        </div>
                    <?php else: ?>
                        <div class="view-row">
                            <div class="view-label">Monthly Salary:</div>
                            <div class="view-value">₹<?php echo number_format($trainer['monthly_salary'] ?? 0, 2); ?> per month</div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Bank Details -->
                <div class="view-section">
                    <div class="view-section-title">
                        <i class="fas fa-university"></i> Bank Details
                    </div>
                    <div class="view-row">
                        <div class="view-label">Bank Account Number:</div>
                        <div class="view-value"><?php echo htmlspecialchars($trainer['bank_account_number'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="view-row">
                        <div class="view-label">Bank Name:</div>
                        <div class="view-value"><?php echo htmlspecialchars($trainer['bank_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="view-row">
                        <div class="view-label">IFSC Code:</div>
                        <div class="view-value"><?php echo htmlspecialchars($trainer['ifsc_code'] ?? 'N/A'); ?></div>
                    </div>
                </div>
                
                <!-- Government Documents -->
                <div class="view-section">
                    <div class="view-section-title">
                        <i class="fas fa-id-card"></i> Government Documents
                    </div>
                    <div class="view-row">
                        <div class="view-label">PAN Number:</div>
                        <div class="view-value"><?php echo htmlspecialchars($trainer['pan_number'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="view-row">
                        <div class="view-label">Aadhar Number:</div>
                        <div class="view-value"><?php echo htmlspecialchars($trainer['aadhar_number'] ?? 'N/A'); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons for View -->
            <div class="form-actions">
                <a href="?action=edit&id=<?php echo $trainer_id; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Trainer
                </a>
                <a href="trainer_manage.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="?action=delete&id=<?php echo $trainer_id; ?>" 
                   class="btn btn-danger" 
                   onclick="return confirm('Are you sure you want to delete this trainer?')">
                    <i class="fas fa-trash"></i> Delete Trainer
                </a>
            </div>
        <?php endif; ?>
        
        <!-- Trainer Form -->
        <?php if (($action == 'add' || $action == 'edit') && $action != 'view'): ?>
            <div class="form-container" id="form-tab">
                <div class="form-title">
                    <i class="fas fa-<?php echo ($action == 'edit') ? 'edit' : 'chalkboard-teacher'; ?>"></i>
                    <?php echo ($action == 'edit') ? 'Edit Trainer' : 'Add New Trainer'; ?>
                </div>
                
                <form method="POST" action="">
                    <?php if ($action == 'edit'): ?>
                        <input type="hidden" name="update_trainer" value="1">
                    <?php else: ?>
                        <input type="hidden" name="add_trainer" value="1">
                    <?php endif; ?>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['first_name'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['last_name'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Email Address *</label>
                            <input type="email" name="email" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['email'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['contact_number'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Specialization *</label>
                            <input type="text" name="specialization" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['specialization'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Join Date *</label>
                            <input type="date" name="join_date" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['joining_date'] ?? date('Y-m-d')); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Trainer Type *</label>
                            <select name="trainer_type" class="form-select" required onchange="toggleSalaryFields()">
                                <option value="full_time" <?php echo (isset($trainer['trainer_type']) && $trainer['trainer_type'] == 'full_time') ? 'selected' : ''; ?>>Full Time</option>
                                <option value="part_time" <?php echo (isset($trainer['trainer_type']) && $trainer['trainer_type'] == 'part_time') ? 'selected' : ''; ?>>Part Time</option>
                                <option value="freelance" <?php echo (isset($trainer['trainer_type']) && $trainer['trainer_type'] == 'freelance') ? 'selected' : ''; ?>>Freelance</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="hourlyRateField" style="<?php echo (!isset($trainer['trainer_type']) || $trainer['trainer_type'] != 'freelance') ? 'display: none;' : ''; ?>">
                            <label class="form-label">Hourly Rate (₹)</label>
                            <input type="number" name="hourly_rate" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['hourly_rate'] ?? ''); ?>" 
                                   step="0.01" min="0">
                        </div>
                        
                        <div class="form-group" id="monthlySalaryField" style="<?php echo (isset($trainer['trainer_type']) && $trainer['trainer_type'] == 'freelance') ? 'display: none;' : ''; ?>">
                            <label class="form-label">Monthly Salary (₹)</label>
                            <input type="number" name="monthly_salary" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['monthly_salary'] ?? ''); ?>" 
                                   step="0.01" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Bank Account Number</label>
                            <input type="text" name="bank_account" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['bank_account_number'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['bank_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">IFSC Code</label>
                            <input type="text" name="ifsc_code" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['ifsc_code'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">PAN Number</label>
                            <input type="text" name="pan_number" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['pan_number'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Aadhar Number</label>
                            <input type="text" name="aadhar_number" class="form-input" 
                                   value="<?php echo htmlspecialchars($trainer['aadhar_number'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Trainer Status *</label>
                            <select name="trainer_status" class="form-select" required>
                                <option value="active" <?php echo (isset($trainer['employment_status']) && $trainer['employment_status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo (isset($trainer['employment_status']) && $trainer['employment_status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <?php if ($action == 'edit'): ?>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Trainer
                            </button>
                            <a href="?action=view&id=<?php echo $trainer_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="?action=delete&id=<?php echo $trainer_id; ?>" 
                               class="btn btn-danger" 
                               onclick="return confirm('Are you sure you want to delete this trainer?')">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        <?php else: ?>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Add Trainer
                            </button>
                        <?php endif; ?>
                        <a href="trainer_manage.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        
        <!-- Trainer List -->
        <?php if ($action == '' || $action == 'list'): ?>
            <div class="table-container" id="list-tab">
                <div class="table-header">
                    <h2>All Trainers (<?php echo count($trainers); ?>)</h2>
                    <div class="search-box">
                        <input type="text" class="search-input" placeholder="Search trainers..." 
                               onkeyup="searchTable()" id="searchInput">
                        <i class="fas fa-search search-icon"></i>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table" id="trainerTable">
                        <thead>
                            <tr>
                                <th>Trainer</th>
                                <th>Trainer ID</th>
                                <th>Specialization</th>
                                <th>Type</th>
                                <th>Compensation</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($trainers as $tr): ?>
                                <tr>
                                    <td>
                                        <div class="trainer-name">
                                            <div class="trainer-avatar">
                                                <?php 
                                                    $first_initial = isset($tr['first_name'][0]) ? $tr['first_name'][0] : 'T';
                                                    $last_initial = isset($tr['last_name'][0]) ? $tr['last_name'][0] : 'R';
                                                    echo strtoupper($first_initial . $last_initial); 
                                                ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 500;">
                                                    <?php echo htmlspecialchars(($tr['first_name'] ?? '') . ' ' . ($tr['last_name'] ?? '')); ?>
                                                </div>
                                                <div style="font-size: 12px; color: var(--gray);">
                                                    <?php echo htmlspecialchars($tr['email'] ?? ''); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($tr['trainer_id'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($tr['specialization'] ?? 'General Training'); ?></td>
                                    <td>
                                        <div class="type-badge">
                                            <?php 
                                                $type = $tr['trainer_type'] ?? 'full_time';
                                                echo ucfirst(str_replace('_', ' ', $type));
                                            ?>
                                        </div>
                                    </td>
                                    <td style="font-weight: 600;">
                                        <?php if (isset($tr['trainer_type']) && $tr['trainer_type'] == 'freelance'): ?>
                                            ₹<?php echo number_format($tr['hourly_rate'] ?? 0, 2); ?>/hr
                                        <?php else: ?>
                                            ₹<?php echo number_format($tr['monthly_salary'] ?? 0, 2); ?>/mo
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars($tr['employment_status'] ?? 'active'); ?>">
                                            <?php echo ucfirst($tr['employment_status'] ?? 'Active'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?action=view&id=<?php echo $tr['id'] ?? 0; ?>" class="action-btn btn-view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="?action=edit&id=<?php echo $tr['id'] ?? 0; ?>" class="action-btn btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="?action=delete&id=<?php echo $tr['id'] ?? 0; ?>" 
                                               class="action-btn btn-delete" 
                                               title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this trainer?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Tab functionality
        function showTab(tabName) {
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            if (tabName === 'list') {
                window.location.href = 'trainer_manage.php';
            } else if (tabName === 'form') {
                window.location.href = 'trainer_manage.php?action=add';
            }
        }
        
        // Toggle salary fields based on trainer type
        function toggleSalaryFields() {
            const trainerType = document.querySelector('select[name="trainer_type"]').value;
            const hourlyRateField = document.getElementById('hourlyRateField');
            const monthlySalaryField = document.getElementById('monthlySalaryField');
            
            if (trainerType === 'freelance') {
                hourlyRateField.style.display = 'block';
                monthlySalaryField.style.display = 'none';
            } else {
                hourlyRateField.style.display = 'none';
                monthlySalaryField.style.display = 'block';
            }
        }
        
        // Search functionality
        function searchTable() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toUpperCase();
            const table = document.getElementById('trainerTable');
            const tr = table.getElementsByTagName('tr');
            
            for (let i = 1; i < tr.length; i++) {
                const td = tr[i].getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < td.length - 1; j++) {
                    if (td[j]) {
                        const txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                tr[i].style.display = found ? '' : 'none';
            }
        }
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredFields = this.querySelectorAll('[required]');
                    let isValid = true;
                    
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            isValid = false;
                            field.style.borderColor = '#ef4444';
                            field.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                            
                            // Add error message
                            if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('error-message')) {
                                const errorMsg = document.createElement('div');
                                errorMsg.className = 'error-message';
                                errorMsg.style.color = '#ef4444';
                                errorMsg.style.fontSize = '12px';
                                errorMsg.style.marginTop = '5px';
                                errorMsg.textContent = 'This field is required';
                                field.parentNode.appendChild(errorMsg);
                            }
                        } else {
                            field.style.borderColor = '';
                            field.style.boxShadow = '';
                            
                            // Remove error message
                            const errorMsg = field.parentNode.querySelector('.error-message');
                            if (errorMsg) {
                                errorMsg.remove();
                            }
                        }
                    });
                    
                    if (!isValid) {
                        e.preventDefault();
                    }
                });
            });
            
            // Add animations to table rows
            const tableRows = document.querySelectorAll('.data-table tbody tr');
            tableRows.forEach((row, index) => {
                row.style.animationDelay = `${index * 0.05}s`;
                row.style.opacity = '0';
                row.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    row.style.transition = 'all 0.5s ease';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, 100);
            });
        });
    </script>
</body>
</html>