<?php
// intern_manage.php - Intern Management System
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
$dbname = "emp_system";
$username = "root";
$password = "";
$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Initialize variables
$action = $_GET['action'] ?? '';
$intern_id = $_GET['id'] ?? 0;
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_intern'])) {
        // Add new intern
        $intern_code = 'INT' . date('Ymd') . rand(100, 999);
        $first_name = $_POST['first_name'] ?? '';
        $last_name = $_POST['last_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $college = $_POST['college'] ?? '';
        $course = $_POST['course'] ?? '';
        $university = $_POST['university'] ?? '';
        $department_id = $_POST['department_id'] ?? 0;
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $stipend = $_POST['stipend'] ?? 0;
        $stipend_type = $_POST['stipend_type'] ?? 'stipend';
        $supervisor_id = $_POST['supervisor_id'] ?? 0;
        $mentor_id = $_POST['mentor_id'] ?? 0;
        // Set mentor_id to NULL if not provided or invalid
        $mentor_id = (empty($mentor_id) || $mentor_id == 0) ? NULL : $mentor_id;
        $internship_status = $_POST['internship_status'] ?? 'active';
        
        // Create interns table if it doesn't exist (using exact structure from your SQL)
        $create_table = $conn->query("CREATE TABLE IF NOT EXISTS interns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            intern_id VARCHAR(50) UNIQUE NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            department_id INT DEFAULT NULL,
            shift_id INT DEFAULT NULL,
            contact_number VARCHAR(20),
            university VARCHAR(255),
            course VARCHAR(100),
            academic_year VARCHAR(50),
            supervisor_id INT DEFAULT NULL,
            mentor_id INT DEFAULT NULL,
            start_date DATE NOT NULL,
            end_date DATE DEFAULT NULL,
            internship_status ENUM('active','completed','terminated','extended') DEFAULT 'active',
            stipend_type ENUM('paid','unpaid','stipend') DEFAULT 'unpaid',
            stipend_amount DECIMAL(10,2) DEFAULT 0.00,
            bank_account_number VARCHAR(50),
            bank_name VARCHAR(100),
            ifsc_code VARCHAR(20),
            pan_number VARCHAR(20),
            aadhar_number VARCHAR(20),
            profile_picture VARCHAR(255),
            resume_path VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
            FOREIGN KEY (supervisor_id) REFERENCES employees(id) ON DELETE SET NULL,
            FOREIGN KEY (mentor_id) REFERENCES employees(id) ON DELETE SET NULL
        )");
        
        if (!$create_table) {
            $error = "Error creating interns table: " . $conn->error;
        } else {
            $sql = "INSERT INTO interns (
                intern_id, first_name, last_name, email, contact_number, 
                university, course, department_id, start_date, end_date,
                stipend_amount, stipend_type, supervisor_id, mentor_id, internship_status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $error = "Prepare failed: " . $conn->error;
            } else {
                $stmt->bind_param(
                    "sssssssisssiiss",
                    $intern_code, $first_name, $last_name, $email, $phone,
                    $university, $course, $department_id, $start_date, $end_date,
                    $stipend, $stipend_type, $supervisor_id, $mentor_id, $internship_status
                );
                
                if ($stmt->execute()) {
                    $message = "Intern added successfully! Intern ID: $intern_code";
                } else {
                    $error = "Error adding intern: " . $conn->error;
                }
                $stmt->close();
            }
        }
    } elseif (isset($_POST['update_intern'])) {
        // Update intern
        $first_name = $_POST['first_name'] ?? '';
        $last_name = $_POST['last_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $college = $_POST['college'] ?? '';
        $course = $_POST['course'] ?? '';
        $university = $_POST['university'] ?? '';
        $department_id = $_POST['department_id'] ?? 0;
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $stipend = $_POST['stipend'] ?? 0;
        $stipend_type = $_POST['stipend_type'] ?? 'stipend';
        $supervisor_id = $_POST['supervisor_id'] ?? 0;
        $mentor_id = $_POST['mentor_id'] ?? 0;
        // Set mentor_id to NULL if not provided or invalid
        $mentor_id = (empty($mentor_id) || $mentor_id == 0) ? NULL : $mentor_id;
        $internship_status = $_POST['internship_status'] ?? '';
        
        $sql = "UPDATE interns SET 
            first_name = ?, last_name = ?, email = ?, contact_number = ?,
            university = ?, course = ?, department_id = ?,
            start_date = ?, end_date = ?, stipend_amount = ?, stipend_type = ?,
            supervisor_id = ?, mentor_id = ?, internship_status = ?
            WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(
                "ssssssisssiiisi",
                $first_name, $last_name, $email, $phone,
                $university, $course, $department_id,
                $start_date, $end_date, $stipend, $stipend_type,
                $supervisor_id, $mentor_id, $internship_status, $intern_id
            );
            
            if ($stmt->execute()) {
                $message = "Intern updated successfully!";
            } else {
                $error = "Error updating intern: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error = "Prepare failed: " . $conn->error;
        }
    } elseif (isset($_GET['action']) && $_GET['action'] == 'delete' && $intern_id > 0) {
        // Delete intern
        $sql = "DELETE FROM interns WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $intern_id);
            
            if ($stmt->execute()) {
                $message = "Intern deleted successfully!";
                header("Location: intern_manage.php?message=" . urlencode($message));
                exit();
            } else {
                $error = "Error deleting intern: " . $conn->error;
            }
            $stmt->close();
        }
    }
}

// Get intern data for edit/view
$intern = null;
$intern_details = null;
if (($action == 'edit' || $action == 'view') && $intern_id > 0) {
    $sql = "SELECT i.*, d.name as department_name, 
                   e1.first_name as supervisor_first, e1.last_name as supervisor_last, e1.designation as supervisor_designation,
                   e2.first_name as mentor_first, e2.last_name as mentor_last, e2.designation as mentor_designation,
                   s.name as shift_name, s.start_time, s.end_time
            FROM interns i 
            LEFT JOIN departments d ON i.department_id = d.id 
            LEFT JOIN employees e1 ON i.supervisor_id = e1.id 
            LEFT JOIN employees e2 ON i.mentor_id = e2.id
            LEFT JOIN shifts s ON i.shift_id = s.id
            WHERE i.id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $intern_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $intern = $result->fetch_assoc();
        $intern_details = $intern; // For view mode
        $stmt->close();
    }
}

// Get all interns for listing
$interns = [];
$departments = [];
$supervisors = [];
$employees = [];

try {
    // Check if interns table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'interns'");
    if ($table_check->num_rows > 0) {
        // Get all interns with department info
        $sql = "SELECT i.*, d.name as department_name, 
                       e1.first_name as supervisor_first, e1.last_name as supervisor_last,
                       e2.first_name as mentor_first, e2.last_name as mentor_last
                FROM interns i 
                LEFT JOIN departments d ON i.department_id = d.id 
                LEFT JOIN employees e1 ON i.supervisor_id = e1.id 
                LEFT JOIN employees e2 ON i.mentor_id = e2.id 
                ORDER BY i.created_at DESC";
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $interns[] = $row;
            }
        }
    } else {
        // Table doesn't exist, create sample data
        $interns = [
            [
                'id' => 1,
                'intern_id' => 'INT2024001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'university' => 'ABC University',
                'course' => 'Computer Science',
                'department_name' => 'IT',
                'start_date' => date('Y-m-d'),
                'end_date' => date('Y-m-d', strtotime('+3 months')),
                'stipend_amount' => 10000,
                'stipend_type' => 'stipend',
                'supervisor_first' => 'Jane',
                'supervisor_last' => 'Smith',
                'mentor_first' => 'Mike',
                'mentor_last' => 'Brown',
                'internship_status' => 'active'
            ],
            [
                'id' => 2,
                'intern_id' => 'INT2024002',
                'first_name' => 'Sarah',
                'last_name' => 'Johnson',
                'email' => 'sarah.j@example.com',
                'university' => 'XYZ College',
                'course' => 'Business Administration',
                'department_name' => 'HR',
                'start_date' => date('Y-m-d'),
                'end_date' => date('Y-m-d', strtotime('+6 months')),
                'stipend_amount' => 8000,
                'stipend_type' => 'stipend',
                'supervisor_first' => 'Robert',
                'supervisor_last' => 'Wilson',
                'mentor_first' => 'Lisa',
                'mentor_last' => 'Taylor',
                'internship_status' => 'active'
            ]
        ];
    }
    
    // Get departments for dropdown
    $dept_result = $conn->query("SELECT * FROM departments ORDER BY name");
    if ($dept_result) {
        while ($row = $dept_result->fetch_assoc()) {
            $departments[] = $row;
        }
    } else {
        // Sample departments if table doesn't exist
        $departments = [
            ['id' => 1, 'name' => 'IT'],
            ['id' => 2, 'name' => 'HR'],
            ['id' => 3, 'name' => 'Finance'],
            ['id' => 4, 'name' => 'Marketing']
        ];
    }
    
    // Get employees for supervisor/mentor dropdown
    $emp_result = $conn->query("SELECT id, first_name, last_name, designation FROM employees WHERE employment_status = 'active' ORDER BY first_name");
    if ($emp_result) {
        while ($row = $emp_result->fetch_assoc()) {
            $employees[] = $row;
        }
    } else {
        // Sample employees if table doesn't exist
        $employees = [
            ['id' => 1, 'first_name' => 'John', 'last_name' => 'Manager', 'designation' => 'Team Lead'],
            ['id' => 2, 'first_name' => 'Jane', 'last_name' => 'Supervisor', 'designation' => 'HR Manager'],
            ['id' => 3, 'first_name' => 'Mike', 'last_name' => 'Brown', 'designation' => 'Senior Developer'],
            ['id' => 4, 'first_name' => 'Lisa', 'last_name' => 'Taylor', 'designation' => 'Project Manager']
        ];
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
    <title>Intern Management - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --secondary: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #e2e8f0;
            --green-light: #d1fae5;
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
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
            text-decoration: none;
        }
        
        .add-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
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
            background: linear-gradient(135deg, var(--primary) 0%, #34d399 100%);
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
            background: var(--green-light);
        }
        
        .tab.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.2);
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
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.1);
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
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.1);
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
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
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
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.1);
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
        
        .intern-avatar {
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
        
        .intern-name {
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
            background: linear-gradient(135deg, var(--primary) 0%, #34d399 100%);
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
        
        .status-completed {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-terminated {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .status-extended {
            background: #fef3c7;
            color: #92400e;
        }
        
        /* Duration Badge */
        .duration-badge {
            padding: 4px 10px;
            background: #e0f2fe;
            color: #0369a1;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        /* Stipend Badge */
        .stipend-badge {
            padding: 4px 10px;
            background: #f0fdf4;
            color: #166534;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        /* View Container */
        .view-container {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            animation: slideInUp 0.5s ease-out;
        }
        
        .view-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .view-title {
            font-size: 24px;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .intern-profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .profile-avatar {
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
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
        }
        
        .profile-info h2 {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .profile-info p {
            color: var(--gray);
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .profile-id {
            background: var(--green-light);
            color: var(--primary-dark);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
        }
        
        .view-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .view-section {
            background: #f8fafc;
            border-radius: 12px;
            padding: 25px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px dashed #e2e8f0;
        }
        
        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .info-label {
            font-weight: 500;
            color: var(--gray);
            font-size: 14px;
        }
        
        .info-value {
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
            text-align: right;
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
            .form-grid {
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
            
            .intern-profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .view-grid {
                grid-template-columns: 1fr;
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
            
            .profile-avatar {
                width: 80px;
                height: 80px;
                font-size: 28px;
            }
            
            .profile-info h2 {
                font-size: 22px;
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
            <h1><i class="fas fa-user-graduate"></i> Intern Management</h1>
            <?php if ($action != 'view'): ?>
                <a href="?action=add" class="add-btn">
                    <i class="fas fa-plus"></i> Add New Intern
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
        
        <!-- Statistics Cards -->
        <?php if ($action != 'view'): ?>
            <?php
                // Calculate statistics
                $total_interns = count($interns);
                $active_interns = 0;
                $paid_interns = 0;
                $stipend_interns = 0;
                
                foreach ($interns as $int) {
                    if (isset($int['internship_status']) && $int['internship_status'] == 'active') $active_interns++;
                    if (isset($int['stipend_type']) && $int['stipend_type'] == 'paid') $paid_interns++;
                    if (isset($int['stipend_type']) && $int['stipend_type'] == 'stipend') $stipend_interns++;
                }
            ?>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $total_interns; ?></h3>
                        <p>Total Interns</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $active_interns; ?></h3>
                        <p>Active Interns</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-money-check-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $paid_interns; ?></h3>
                        <p>Paid Interns</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $stipend_interns; ?></h3>
                        <p>Stipend Interns</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <?php if ($action != 'view'): ?>
            <div class="tabs">
                <div class="tab <?php echo ($action == '' || $action == 'list') ? 'active' : ''; ?>" onclick="showTab('list')">
                    <i class="fas fa-list"></i> All Interns
                </div>
                <div class="tab <?php echo ($action == 'add' || $action == 'edit') ? 'active' : ''; ?>" onclick="showTab('form')">
                    <i class="fas fa-<?php echo ($action == 'edit') ? 'edit' : 'plus'; ?>"></i> 
                    <?php echo ($action == 'edit') ? 'Edit Intern' : 'Add Intern'; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Intern Form -->
        <?php if ($action == 'add' || $action == 'edit'): ?>
            <div class="form-container" id="form-tab">
                <div class="form-title">
                    <i class="fas fa-<?php echo ($action == 'edit') ? 'edit' : 'user-graduate'; ?>"></i>
                    <?php echo ($action == 'edit') ? 'Edit Intern' : 'Add New Intern'; ?>
                </div>
                
                <form method="POST" action="">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" class="form-input" 
                                   value="<?php echo htmlspecialchars($intern['first_name'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" class="form-input" 
                                   value="<?php echo htmlspecialchars($intern['last_name'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Email Address *</label>
                            <input type="email" name="email" class="form-input" 
                                   value="<?php echo htmlspecialchars($intern['email'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone Number *</label>
                            <input type="tel" name="phone" class="form-input" 
                                   value="<?php echo htmlspecialchars($intern['contact_number'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">University/College *</label>
                            <input type="text" name="university" class="form-input" 
                                   value="<?php echo htmlspecialchars($intern['university'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Course/Program *</label>
                            <input type="text" name="course" class="form-input" 
                                   value="<?php echo htmlspecialchars($intern['course'] ?? ''); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Department *</label>
                            <select name="department_id" class="form-select" required>
                                <option value="">Select Department</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" 
                                        <?php echo (isset($intern['department_id']) && $intern['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Start Date *</label>
                            <input type="date" name="start_date" class="form-input" 
                                   value="<?php echo htmlspecialchars($intern['start_date'] ?? date('Y-m-d')); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">End Date *</label>
                            <input type="date" name="end_date" class="form-input" 
                                   value="<?php echo htmlspecialchars($intern['end_date'] ?? date('Y-m-d', strtotime('+3 months'))); ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Stipend Type *</label>
                            <select name="stipend_type" class="form-select" required>
                                <option value="unpaid" <?php echo (isset($intern['stipend_type']) && $intern['stipend_type'] == 'unpaid') ? 'selected' : ''; ?>>Unpaid</option>
                                <option value="stipend" <?php echo (isset($intern['stipend_type']) && $intern['stipend_type'] == 'stipend') ? 'selected' : ''; ?>>Stipend</option>
                                <option value="paid" <?php echo (isset($intern['stipend_type']) && $intern['stipend_type'] == 'paid') ? 'selected' : ''; ?>>Paid</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Stipend Amount (₹) *</label>
                            <input type="number" name="stipend" class="form-input" 
                                   value="<?php echo htmlspecialchars($intern['stipend_amount'] ?? ''); ?>" 
                                   step="0.01" min="0" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Supervisor *</label>
                            <select name="supervisor_id" class="form-select" required>
                                <option value="">Select Supervisor</option>
                                <?php foreach($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>" 
                                        <?php echo (isset($intern['supervisor_id']) && $intern['supervisor_id'] == $emp['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' - ' . $emp['designation']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Mentor</label>
                            <select name="mentor_id" class="form-select">
                                <option value="">Select Mentor (Optional)</option>
                                <?php foreach($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>" 
                                        <?php echo (isset($intern['mentor_id']) && $intern['mentor_id'] == $emp['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' - ' . $emp['designation']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Internship Status *</label>
                            <select name="internship_status" class="form-select" required>
                                <option value="active" <?php echo (isset($intern['internship_status']) && $intern['internship_status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="completed" <?php echo (isset($intern['internship_status']) && $intern['internship_status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="terminated" <?php echo (isset($intern['internship_status']) && $intern['internship_status'] == 'terminated') ? 'selected' : ''; ?>>Terminated</option>
                                <option value="extended" <?php echo (isset($intern['internship_status']) && $intern['internship_status'] == 'extended') ? 'selected' : ''; ?>>Extended</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <?php if ($action == 'edit'): ?>
                            <input type="hidden" name="update_intern" value="1">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Intern
                            </button>
                            <a href="?action=delete&id=<?php echo $intern_id; ?>" 
                               class="btn btn-danger" 
                               onclick="return confirm('Are you sure you want to delete this intern?')">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        <?php else: ?>
                            <input type="hidden" name="add_intern" value="1">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Add Intern
                            </button>
                        <?php endif; ?>
                        <a href="intern_manage.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        
        <!-- View Intern Details -->
        <?php if ($action == 'view' && $intern_details): ?>
            <div class="view-container">
                <div class="view-header">
                    <div class="view-title">
                        <i class="fas fa-user-graduate"></i> Intern Details
                    </div>
                    <div class="action-buttons">
                        <a href="intern_manage.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                        <a href="?action=edit&id=<?php echo $intern_id; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Intern
                        </a>
                    </div>
                </div>
                
                <div class="intern-profile-header">
                    <div class="profile-avatar">
                        <?php 
                            $first_initial = isset($intern_details['first_name'][0]) ? $intern_details['first_name'][0] : 'I';
                            $last_initial = isset($intern_details['last_name'][0]) ? $intern_details['last_name'][0] : 'N';
                            echo strtoupper($first_initial . $last_initial); 
                        ?>
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars(($intern_details['first_name'] ?? '') . ' ' . ($intern_details['last_name'] ?? '')); ?></h2>
                        <p><?php echo htmlspecialchars($intern_details['email'] ?? ''); ?></p>
                        <p><?php echo htmlspecialchars($intern_details['contact_number'] ?? ''); ?></p>
                        <span class="profile-id"><?php echo htmlspecialchars($intern_details['intern_id'] ?? ''); ?></span>
                        <span class="status-badge status-<?php echo htmlspecialchars($intern_details['internship_status'] ?? 'active'); ?>" style="margin-left: 10px;">
                            <?php echo ucfirst($intern_details['internship_status'] ?? 'Active'); ?>
                        </span>
                    </div>
                </div>
                
                <div class="view-grid">
                    <!-- Personal Information -->
                    <div class="view-section">
                        <div class="section-title">
                            <i class="fas fa-user"></i> Personal Information
                        </div>
                        <div class="info-row">
                            <span class="info-label">Full Name</span>
                            <span class="info-value"><?php echo htmlspecialchars(($intern_details['first_name'] ?? '') . ' ' . ($intern_details['last_name'] ?? '')); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Email Address</span>
                            <span class="info-value"><?php echo htmlspecialchars($intern_details['email'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Contact Number</span>
                            <span class="info-value"><?php echo htmlspecialchars($intern_details['contact_number'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Intern ID</span>
                            <span class="info-value"><?php echo htmlspecialchars($intern_details['intern_id'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Account Created</span>
                            <span class="info-value"><?php echo isset($intern_details['created_at']) ? date('d M Y, h:i A', strtotime($intern_details['created_at'])) : 'N/A'; ?></span>
                        </div>
                    </div>
                    
                    <!-- Academic Information -->
                    <div class="view-section">
                        <div class="section-title">
                            <i class="fas fa-graduation-cap"></i> Academic Information
                        </div>
                        <div class="info-row">
                            <span class="info-label">University/College</span>
                            <span class="info-value"><?php echo htmlspecialchars($intern_details['university'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Course/Program</span>
                            <span class="info-value"><?php echo htmlspecialchars($intern_details['course'] ?? 'Not provided'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Academic Year</span>
                            <span class="info-value"><?php echo htmlspecialchars($intern_details['academic_year'] ?? 'Not specified'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Department</span>
                            <span class="info-value"><?php echo htmlspecialchars($intern_details['department_name'] ?? 'Not assigned'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Shift</span>
                            <span class="info-value">
                                <?php if (isset($intern_details['shift_name'])): ?>
                                    <?php echo htmlspecialchars($intern_details['shift_name']); ?> 
                                    (<?php echo isset($intern_details['start_time']) ? date('h:i A', strtotime($intern_details['start_time'])) : ''; ?> - 
                                    <?php echo isset($intern_details['end_time']) ? date('h:i A', strtotime($intern_details['end_time'])) : ''; ?>)
                                <?php else: ?>
                                    Not assigned
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Internship Details -->
                    <div class="view-section">
                        <div class="section-title">
                            <i class="fas fa-briefcase"></i> Internship Details
                        </div>
                        <div class="info-row">
                            <span class="info-label">Start Date</span>
                            <span class="info-value"><?php echo isset($intern_details['start_date']) ? date('d M Y', strtotime($intern_details['start_date'])) : 'N/A'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">End Date</span>
                            <span class="info-value"><?php echo isset($intern_details['end_date']) ? date('d M Y', strtotime($intern_details['end_date'])) : 'N/A'; ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Duration</span>
                            <span class="info-value">
                                <?php 
                                    if (isset($intern_details['start_date']) && isset($intern_details['end_date'])) {
                                        $start = new DateTime($intern_details['start_date']);
                                        $end = new DateTime($intern_details['end_date']);
                                        $interval = $start->diff($end);
                                        echo $interval->format('%m months %d days');
                                    } else {
                                        echo 'N/A';
                                    }
                                ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Status</span>
                            <span class="info-value">
                                <span class="status-badge status-<?php echo htmlspecialchars($intern_details['internship_status'] ?? 'active'); ?>">
                                    <?php echo ucfirst($intern_details['internship_status'] ?? 'Active'); ?>
                                </span>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Stipend Type</span>
                            <span class="info-value"><?php echo ucfirst($intern_details['stipend_type'] ?? 'Unpaid'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Stipend Amount</span>
                            <span class="info-value">₹<?php echo number_format($intern_details['stipend_amount'] ?? 0, 2); ?></span>
                        </div>
                    </div>
                    
                    <!-- Supervision Details -->
                    <div class="view-section">
                        <div class="section-title">
                            <i class="fas fa-users"></i> Supervision & Mentorship
                        </div>
                        <div class="info-row">
                            <span class="info-label">Supervisor</span>
                            <span class="info-value">
                                <?php if (isset($intern_details['supervisor_first']) && isset($intern_details['supervisor_last'])): ?>
                                    <?php echo htmlspecialchars($intern_details['supervisor_first'] . ' ' . $intern_details['supervisor_last']); ?>
                                    <?php if (isset($intern_details['supervisor_designation'])): ?>
                                        <br><small style="color: var(--gray);"><?php echo htmlspecialchars($intern_details['supervisor_designation']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Not assigned
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Mentor</span>
                            <span class="info-value">
                                <?php if (isset($intern_details['mentor_first']) && isset($intern_details['mentor_last'])): ?>
                                    <?php echo htmlspecialchars($intern_details['mentor_first'] . ' ' . $intern_details['mentor_last']); ?>
                                    <?php if (isset($intern_details['mentor_designation'])): ?>
                                        <br><small style="color: var(--gray);"><?php echo htmlspecialchars($intern_details['mentor_designation']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Not assigned
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Additional Information -->
                <div class="view-section" style="margin-top: 20px;">
                    <div class="section-title">
                        <i class="fas fa-info-circle"></i> Additional Information
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <div>
                            <div class="info-label">Bank Account Number</div>
                            <div class="info-value"><?php echo htmlspecialchars($intern_details['bank_account_number'] ?? 'Not provided'); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Bank Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($intern_details['bank_name'] ?? 'Not provided'); ?></div>
                        </div>
                        <div>
                            <div class="info-label">IFSC Code</div>
                            <div class="info-value"><?php echo htmlspecialchars($intern_details['ifsc_code'] ?? 'Not provided'); ?></div>
                        </div>
                        <div>
                            <div class="info-label">PAN Number</div>
                            <div class="info-value"><?php echo htmlspecialchars($intern_details['pan_number'] ?? 'Not provided'); ?></div>
                        </div>
                        <div>
                            <div class="info-label">Aadhar Number</div>
                            <div class="info-value"><?php echo htmlspecialchars($intern_details['aadhar_number'] ?? 'Not provided'); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions" style="margin-top: 30px;">
                    <a href="intern_manage.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                    <a href="?action=edit&id=<?php echo $intern_id; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Intern
                    </a>
                    <a href="?action=delete&id=<?php echo $intern_id; ?>" 
                       class="btn btn-danger" 
                       onclick="return confirm('Are you sure you want to delete this intern?')">
                        <i class="fas fa-trash"></i> Delete Intern
                    </a>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Intern List -->
        <?php if ($action == '' || $action == 'list'): ?>
            <div class="table-container" id="list-tab">
                <div class="table-header">
                    <h2>All Interns (<?php echo count($interns); ?>)</h2>
                    <div class="search-box">
                        <input type="text" class="search-input" placeholder="Search interns..." 
                               onkeyup="searchTable()" id="searchInput">
                        <i class="fas fa-search search-icon"></i>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table" id="internTable">
                        <thead>
                            <tr>
                                <th>Intern</th>
                                <th>Intern ID</th>
                                <th>University & Course</th>
                                <th>Department</th>
                                <th>Duration</th>
                                <th>Stipend</th>
                                <th>Supervisor</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($interns as $int): 
                                // Calculate duration
                                $start_date = isset($int['start_date']) ? new DateTime($int['start_date']) : new DateTime();
                                $end_date = isset($int['end_date']) ? new DateTime($int['end_date']) : new DateTime();
                                $duration = $start_date->diff($end_date);
                                $months = ($duration->y * 12) + $duration->m;
                            ?>
                                <tr>
                                    <td>
                                        <div class="intern-name">
                                            <div class="intern-avatar">
                                                <?php 
                                                    $first_initial = isset($int['first_name'][0]) ? $int['first_name'][0] : 'I';
                                                    $last_initial = isset($int['last_name'][0]) ? $int['last_name'][0] : 'N';
                                                    echo strtoupper($first_initial . $last_initial); 
                                                ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 500;">
                                                    <?php echo htmlspecialchars(($int['first_name'] ?? '') . ' ' . ($int['last_name'] ?? '')); ?>
                                                </div>
                                                <div style="font-size: 12px; color: var(--gray);">
                                                    <?php echo htmlspecialchars($int['email'] ?? ''); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($int['intern_id'] ?? 'N/A'); ?></td>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo htmlspecialchars($int['university'] ?? ''); ?></div>
                                        <div style="font-size: 12px; color: var(--gray);"><?php echo htmlspecialchars($int['course'] ?? ''); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($int['department_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <div class="duration-badge">
                                            <?php echo $months; ?> months
                                        </div>
                                        <div style="font-size: 12px; color: var(--gray); margin-top: 4px;">
                                            <?php echo isset($int['start_date']) ? date('d M Y', strtotime($int['start_date'])) : 'N/A'; ?> - 
                                            <?php echo isset($int['end_date']) ? date('d M Y', strtotime($int['end_date'])) : 'N/A'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="stipend-badge">
                                            ₹<?php echo number_format($int['stipend_amount'] ?? 0, 2); ?>
                                        </div>
                                        <div style="font-size: 12px; color: var(--gray); margin-top: 4px;">
                                            <?php echo ucfirst($int['stipend_type'] ?? 'unpaid'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (isset($int['supervisor_first']) && isset($int['supervisor_last'])): ?>
                                            <div style="font-weight: 500;">
                                                <?php echo htmlspecialchars($int['supervisor_first'] . ' ' . $int['supervisor_last']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--gray); font-size: 12px;">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars($int['internship_status'] ?? 'active'); ?>">
                                            <?php echo ucfirst($int['internship_status'] ?? 'Active'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?action=view&id=<?php echo $int['id'] ?? 0; ?>" class="action-btn btn-view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="?action=edit&id=<?php echo $int['id'] ?? 0; ?>" class="action-btn btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="?action=delete&id=<?php echo $int['id'] ?? 0; ?>" 
                                               class="action-btn btn-delete" 
                                               title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this intern?')">
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
                window.location.href = 'intern_manage.php';
            } else if (tabName === 'form') {
                window.location.href = 'intern_manage.php?action=add';
            }
        }
        
        // Search functionality
        function searchTable() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toUpperCase();
            const table = document.getElementById('internTable');
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
                    
                    // Validate dates
                    const startDate = form.querySelector('[name="start_date"]');
                    const endDate = form.querySelector('[name="end_date"]');
                    
                    if (startDate && endDate && startDate.value && endDate.value) {
                        if (new Date(startDate.value) >= new Date(endDate.value)) {
                            isValid = false;
                            endDate.style.borderColor = '#ef4444';
                            endDate.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                            
                            if (!endDate.nextElementSibling || !endDate.nextElementSibling.classList.contains('error-message')) {
                                const errorMsg = document.createElement('div');
                                errorMsg.className = 'error-message';
                                errorMsg.style.color = '#ef4444';
                                errorMsg.style.fontSize = '12px';
                                errorMsg.style.marginTop = '5px';
                                errorMsg.textContent = 'End date must be after start date';
                                endDate.parentNode.appendChild(errorMsg);
                            }
                        }
                    }
                    
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