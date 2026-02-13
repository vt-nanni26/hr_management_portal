
<?php
// emp_manage.php - Employee Management System with enhanced UI/UX
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hr_management_portal');

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
    header("Location: login_hr.php");
    exit();
}

// Function to sync employee data with user data
function syncEmployeeWithUser($employee_id, $email, $first_name, $last_name, $role = 'employee', $connection, $password = null) {
    try {
        // Check if user already exists for this employee
        $checkUserQuery = "SELECT id FROM users WHERE email = ?";
        $stmt = $connection->prepare($checkUserQuery);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $existingUser = $result->fetch_assoc();
        $stmt->close();
        
        if ($existingUser) {
            // User exists, update the employee record with user_id
            $updateEmployeeQuery = "UPDATE employees SET user_id = ? WHERE id = ?";
            $stmt = $connection->prepare($updateEmployeeQuery);
            $stmt->bind_param("ii", $existingUser['id'], $employee_id);
            $stmt->execute();
            $stmt->close();
            
            // Update user role if needed
            $updateUserQuery = "UPDATE users SET role = ? WHERE id = ?";
            $stmt = $connection->prepare($updateUserQuery);
            $stmt->bind_param("si", $role, $existingUser['id']);
            $stmt->execute();
            $stmt->close();
            
            return [
                'success' => true,
                'message' => 'Employee linked to existing user account',
                'user_id' => $existingUser['id']
            ];
        } else {
            // Create new user account for employee
            if ($password === null) {
                // Set default password to '1234567'
                $password = '1234567';
            }
            
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $createUserQuery = "INSERT INTO users (email, password_hash, role, is_active, created_at) VALUES (?, ?, ?, 1, NOW())";
            $stmt = $connection->prepare($createUserQuery);
            $stmt->bind_param("sss", $email, $hashedPassword, $role);
            $stmt->execute();
            $user_id = $stmt->insert_id;
            $stmt->close();
            
            // Update employee record with user_id
            $updateEmployeeQuery = "UPDATE employees SET user_id = ? WHERE id = ?";
            $stmt = $connection->prepare($updateEmployeeQuery);
            $stmt->bind_param("ii", $user_id, $employee_id);
            $stmt->execute();
            $stmt->close();
            
            return [
                'success' => true,
                'message' => 'User account created successfully',
                'user_id' => $user_id,
                'password' => $password
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error syncing with user account: ' . $e->getMessage()
        ];
    }
}

// Function to update user password
function updateUserPassword($user_id, $new_password, $connection) {
    try {
        $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
        $updateQuery = "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $connection->prepare($updateQuery);
        $stmt->bind_param("si", $hashedPassword, $user_id);
        $stmt->execute();
        $stmt->close();
        
        return [
            'success' => true,
            'message' => 'Password updated successfully'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error updating password: ' . $e->getMessage()
        ];
    }
}

// Function to generate random password
function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+';
    $password = '';
    $charLength = strlen($chars);
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, $charLength - 1)];
    }
    
    return $password;
}

// Function to validate password strength
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\'":\\\\|,.<>\/?]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return $errors;
}

// Initialize variables
$action = $_GET['action'] ?? '';
$employee_id = $_GET['id'] ?? 0;
$message = '';
$error = '';
$success = false;
$password_message = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_employee'])) {
        // Add new employee
        $emp_id = 'EMP' . date('Ymd') . rand(100, 999);
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $department_id = intval($_POST['department_id'] ?? 0);
        $designation = trim($_POST['designation'] ?? '');
        $joining_date = $_POST['joining_date'] ?? '';
        $employment_status = $_POST['employment_status'] ?? 'active';
        $salary = floatval($_POST['salary'] ?? 0);
        $address = trim($_POST['address'] ?? '');
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format";
        } elseif (strlen($contact_number) < 10) {
            $error = "Contact number must be at least 10 digits";
        } elseif ($salary < 0) {
            $error = "Salary cannot be negative";
        } else {
            // Check if employee with this email already exists
            $checkEmailQuery = "SELECT id FROM employees WHERE email = ?";
            $stmt = $conn->prepare($checkEmailQuery);
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $error = "An employee with this email already exists";
                $stmt->close();
            } else {
                $stmt->close();
                
                $sql = "INSERT INTO employees (
                    emp_id, first_name, last_name, email, contact_number, 
                    department_id, designation, joining_date, 
                    employment_status, salary, address, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param(
                        "sssssisssds",
                        $emp_id, $first_name, $last_name, $email, $contact_number,
                        $department_id, $designation, $joining_date,
                        $employment_status, $salary, $address
                    );
                    
                    if ($stmt->execute()) {
                        $employee_id = $stmt->insert_id;
                        $success = true;
                        $message = "Employee added successfully! Employee Code: $emp_id";
                        
                        // Automatically create user account for every employee
                        $password = '1234567';
                        $sync_result = syncEmployeeWithUser($employee_id, $email, $first_name, $last_name, 'employee', $conn, $password);
                        
                        if ($sync_result['success']) {
                            $message .= "<br>✓ User account created automatically with default password: <strong>1234567</strong>";
                            $message .= "<br>✓ Employee can login at: <a href='login_emp.php' target='_blank'>login_emp.php</a>";
                            if (isset($sync_result['user_id'])) {
                                $password_message = "User account created. User ID: " . $sync_result['user_id'];
                            }
                        } else {
                            $error .= "<br>" . $sync_result['message'];
                        }
                        
                        // Clear form after successful submission
                        if ($success) {
                            $first_name = $last_name = $email = $contact_number = $designation = $address = '';
                            $salary = 0;
                        }
                    } else {
                        $error = "Error adding employee: " . $conn->error;
                    }
                    $stmt->close();
                } else {
                    $error = "Error preparing statement: " . $conn->error;
                }
            }
        }
    } elseif (isset($_POST['update_employee'])) {
        // Update employee
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $department_id = intval($_POST['department_id'] ?? 0);
        $designation = trim($_POST['designation'] ?? '');
        $joining_date = $_POST['joining_date'] ?? '';
        $employment_status = $_POST['employment_status'] ?? '';
        $salary = floatval($_POST['salary'] ?? 0);
        $address = trim($_POST['address'] ?? '');
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format";
        } else {
            // Check if email already exists for another employee
            $checkEmailQuery = "SELECT id, user_id FROM employees WHERE email = ? AND id != ?";
            $stmt = $conn->prepare($checkEmailQuery);
            $stmt->bind_param("si", $email, $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $error = "This email is already assigned to another employee";
                $stmt->close();
            } else {
                $stmt->close();
                
                $sql = "UPDATE employees SET 
                    first_name = ?, last_name = ?, email = ?, contact_number = ?,
                    department_id = ?, designation = ?, joining_date = ?,
                    employment_status = ?, salary = ?, address = ?, updated_at = NOW()
                    WHERE id = ?";
                
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param(
                        "sssiisssdsi",
                        $first_name, $last_name, $email, $contact_number,
                        $department_id, $designation, $joining_date,
                        $employment_status, $salary, $address, $employee_id
                    );
                    
                    if ($stmt->execute()) {
                        $success = true;
                        $message = "Employee updated successfully!";
                        
                        // Sync with user account if exists
                        $getUserQuery = "SELECT user_id FROM employees WHERE id = ?";
                        $stmt2 = $conn->prepare($getUserQuery);
                        $stmt2->bind_param("i", $employee_id);
                        $stmt2->execute();
                        $result2 = $stmt2->get_result();
                        $employee_data = $result2->fetch_assoc();
                        $stmt2->close();
                        
                        if ($employee_data && $employee_data['user_id']) {
                            // Update user email if changed
                            $updateUserQuery = "UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?";
                            $stmt3 = $conn->prepare($updateUserQuery);
                            $stmt3->bind_param("si", $email, $employee_data['user_id']);
                            $stmt3->execute();
                            $stmt3->close();
                            
                            $message .= "<br>✓ User account email updated.";
                        } else {
                            // If no user account exists, create one with default password
                            $getEmployeeQuery = "SELECT email, first_name, last_name FROM employees WHERE id = ?";
                            $stmt4 = $conn->prepare($getEmployeeQuery);
                            $stmt4->bind_param("i", $employee_id);
                            $stmt4->execute();
                            $result4 = $stmt4->get_result();
                            $employee_info = $result4->fetch_assoc();
                            $stmt4->close();
                            
                            if ($employee_info) {
                                $password = '1234567';
                                $sync_result = syncEmployeeWithUser($employee_id, $email, $employee_info['first_name'], $employee_info['last_name'], 'employee', $conn, $password);
                                
                                if ($sync_result['success']) {
                                    $message .= "<br>✓ User account created automatically with default password: <strong>1234567</strong>";
                                    $message .= "<br>✓ Employee can login at: <a href='login_emp.php' target='_blank'>login_emp.php</a>";
                                } else {
                                    $error .= "<br>" . $sync_result['message'];
                                }
                            }
                        }
                    } else {
                        $error = "Error updating employee: " . $conn->error;
                    }
                    $stmt->close();
                }
            }
        }
    } elseif (isset($_POST['update_password'])) {
        // Update user password
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($new_password) || empty($confirm_password)) {
            $error = "Both password fields are required";
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match";
        } else {
            $password_errors = validatePasswordStrength($new_password);
            if (!empty($password_errors)) {
                $error = implode("<br>", $password_errors);
            } else {
                // Get user_id from employee
                $getUserQuery = "SELECT user_id FROM employees WHERE id = ?";
                $stmt = $conn->prepare($getUserQuery);
                $stmt->bind_param("i", $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $employee_data = $result->fetch_assoc();
                $stmt->close();
                
                if ($employee_data && $employee_data['user_id']) {
                    $update_result = updateUserPassword($employee_data['user_id'], $new_password, $conn);
                    if ($update_result['success']) {
                        $success = true;
                        $password_message = $update_result['message'];
                    } else {
                        $error = $update_result['message'];
                    }
                } else {
                    // If no user account exists, create one with default password
                    $getEmployeeQuery = "SELECT email, first_name, last_name FROM employees WHERE id = ?";
                    $stmt = $conn->prepare($getEmployeeQuery);
                    $stmt->bind_param("i", $employee_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $employee_info = $result->fetch_assoc();
                    $stmt->close();
                    
                    if ($employee_info) {
                        $password = '1234567';
                        $sync_result = syncEmployeeWithUser($employee_id, $employee_info['email'], $employee_info['first_name'], $employee_info['last_name'], 'employee', $conn, $password);
                        
                        if ($sync_result['success']) {
                            $success = true;
                            $password_message = "✓ User account created with default password: 1234567<br>✓ Employee can login at: <a href='login_emp.php' target='_blank'>login_emp.php</a>";
                        } else {
                            $error = $sync_result['message'];
                        }
                    } else {
                        $error = "Employee not found";
                    }
                }
            }
        }
    } elseif (isset($_POST['create_user_account'])) {
        // Create user account for existing employee
        $custom_password = $_POST['custom_password'] ?? '';
        $generate_password = isset($_POST['generate_password']) ? 1 : 0;
        
        // Get employee data
        $getEmployeeQuery = "SELECT id, email, first_name, last_name FROM employees WHERE id = ?";
        $stmt = $conn->prepare($getEmployeeQuery);
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $employee = $result->fetch_assoc();
        $stmt->close();
        
        if ($employee) {
            $password = null;
            
            if ($generate_password) {
                $password = generateRandomPassword();
            } elseif (!empty($custom_password)) {
                $password_errors = validatePasswordStrength($custom_password);
                if (!empty($password_errors)) {
                    $error = implode("<br>", $password_errors);
                } else {
                    $password = $custom_password;
                }
            } else {
                // Use default password if none specified
                $password = '1234567';
            }
            
            if (!$error && $password !== null) {
                $sync_result = syncEmployeeWithUser($employee_id, $employee['email'], $employee['first_name'], $employee['last_name'], 'employee', $conn, $password);
                if ($sync_result['success']) {
                    $success = true;
                    $message = "User account created successfully!";
                    if (isset($sync_result['password'])) {
                        $password_message = "Password: " . htmlspecialchars($sync_result['password']) . "<br>Employee can login at: <a href='login_emp.php' target='_blank'>login_emp.php</a>";
                    }
                } else {
                    $error = $sync_result['message'];
                }
            }
        } else {
            $error = "Employee not found";
        }
    }
}

// Handle delete action
if ($action == 'delete' && $employee_id > 0) {
    // Get user_id before deleting
    $getUserQuery = "SELECT user_id FROM employees WHERE id = ?";
    $stmt = $conn->prepare($getUserQuery);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee = $result->fetch_assoc();
    $stmt->close();
    
    // Delete employee
    $sql = "DELETE FROM employees WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $employee_id);
        
        if ($stmt->execute()) {
            // Also delete user account if exists
            if ($employee && $employee['user_id']) {
                $deleteUserQuery = "DELETE FROM users WHERE id = ?";
                $stmt2 = $conn->prepare($deleteUserQuery);
                $stmt2->bind_param("i", $employee['user_id']);
                $stmt2->execute();
                $stmt2->close();
            }
            
            $success = true;
            $message = "Employee deleted successfully!";
            header("Location: emp_manage.php?message=" . urlencode($message) . "&success=" . ($success ? '1' : '0'));
            exit();
        } else {
            $error = "Error deleting employee: " . $conn->error;
        }
        $stmt->close();
    }
}

// Get employee data for edit/view
$employee = null;
$view_mode = false;
$user_account_exists = false;
if (($action == 'edit' || $action == 'view') && $employee_id > 0) {
    $sql = "SELECT e.*, d.name as department_name, u.id as user_id, u.role as user_role 
            FROM employees e 
            LEFT JOIN departments d ON e.department_id = d.id 
            LEFT JOIN users u ON e.user_id = u.id
            WHERE e.id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $employee = $result->fetch_assoc();
        $stmt->close();
    }
    
    if ($employee && $employee['user_id']) {
        $user_account_exists = true;
    }
    
    if ($action == 'view') {
        $view_mode = true;
    }
}

// Get departments for dropdown
$departments = [];
$dept_result = $conn->query("SELECT * FROM departments ORDER BY name");
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row;
    }
}

// Pagination setup
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $limit;

// Get all employees for listing with pagination (only if not in view/edit mode)
$employees = [];
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$dept_filter = $_GET['department'] ?? 'all';

// Build base query for counting total records
$count_sql = "SELECT COUNT(*) as total 
              FROM employees e 
              LEFT JOIN departments d ON e.department_id = d.id 
              WHERE 1=1";
              
// Build query for fetching data with pagination
$data_sql = "SELECT e.*, d.name as department_name, 
                    CASE WHEN e.user_id IS NOT NULL THEN 1 ELSE 0 END as has_user_account
             FROM employees e 
             LEFT JOIN departments d ON e.department_id = d.id 
             WHERE 1=1";
             
$params = [];
$types = '';
$where_conditions = [];

if ($search) {
    $where_conditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ? OR e.emp_id LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    $types .= 'ssss';
}

if ($status_filter != 'all') {
    $where_conditions[] = "e.employment_status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($dept_filter != 'all' && is_numeric($dept_filter)) {
    $where_conditions[] = "e.department_id = ?";
    $params[] = $dept_filter;
    $types .= 'i';
}

// Add WHERE conditions to both queries
if (!empty($where_conditions)) {
    $where_clause = " AND " . implode(" AND ", $where_conditions);
    $count_sql .= $where_clause;
    $data_sql .= $where_clause;
}

// Add ORDER BY and LIMIT for data query
$data_sql .= " ORDER BY e.created_at DESC LIMIT ?, ?";
$params_count = $params; // Copy for count query
$params[] = $offset;
$params[] = $limit;
$types .= 'ii';

// Execute count query to get total records
$total_records = 0;
if (!empty($params_count)) {
    $stmt_count = $conn->prepare($count_sql);
    if ($stmt_count) {
        // Create variables for bind_param
        $bind_params = [&$types];
        foreach ($params_count as $key => $value) {
            $bind_params[] = &$params_count[$key];
        }
        
        call_user_func_array([$stmt_count, 'bind_param'], $bind_params);
        $stmt_count->execute();
        $count_result = $stmt_count->get_result();
        $count_row = $count_result->fetch_assoc();
        $total_records = $count_row['total'];
        $stmt_count->close();
    }
} else {
    $stmt_count = $conn->prepare($count_sql);
    if ($stmt_count) {
        $stmt_count->execute();
        $count_result = $stmt_count->get_result();
        $count_row = $count_result->fetch_assoc();
        $total_records = $count_row['total'];
        $stmt_count->close();
    }
}

// Calculate total pages
$total_pages = ceil($total_records / $limit);
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
    // Update offset in params
    $params[count($params) - 2] = $offset;
}

// Execute data query to get paginated records (only if not in view/edit mode)
if ($action != 'view' && $action != 'edit') {
    $stmt = $conn->prepare($data_sql);
    if ($stmt) {
        if (!empty($params)) {
            // Create variables for bind_param
            $bind_params = [&$types];
            foreach ($params as $key => $value) {
                $bind_params[] = &$params[$key];
            }
            
            call_user_func_array([$stmt, 'bind_param'], $bind_params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        $stmt->close();
    }
}

// Get statistics
$stats = [];
$result = $conn->query("SELECT COUNT(*) as total FROM employees");
$stats['total'] = $result ? $result->fetch_assoc()['total'] : 0;

$result = $conn->query("SELECT COUNT(*) as active FROM employees WHERE employment_status = 'active'");
$stats['active'] = $result ? $result->fetch_assoc()['active'] : 0;

$result = $conn->query("SELECT AVG(salary) as avg_salary FROM employees WHERE employment_status = 'active'");
$stats['avg_salary'] = $result ? $result->fetch_assoc()['avg_salary'] : 0;

$result = $conn->query("SELECT COUNT(DISTINCT department_id) as dept_count FROM employees");
$stats['dept_count'] = $result ? $result->fetch_assoc()['dept_count'] : 0;

$result = $conn->query("SELECT COUNT(*) as users_with_account FROM employees WHERE user_id IS NOT NULL");
$stats['users_with_account'] = $result ? $result->fetch_assoc()['users_with_account'] : 0;

$conn->close();

// Helper function for avatar colors
function getAvatarColor($id) {
    $colors = [
        '#4f46e5', // Indigo
        '#10b981', // Emerald
        '#f59e0b', // Amber
        '#ef4444', // Red
        '#3b82f6', // Blue
        '#8b5cf6', // Violet
        '#ec4899', // Pink
        '#06b6d4'  // Cyan
    ];
    return $colors[$id % count($colors)];
}

// Function to get status badge class
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'active':
            return 'bg-success';
        case 'inactive':
            return 'bg-secondary';
        case 'on_leave':
            return 'bg-warning text-dark';
        case 'terminated':
            return 'bg-danger';
        default:
            return 'bg-success';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Management - HR Portal</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-dark: #4338ca;
            --secondary-color: #7e22ce;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --light-color: #f8fafc;
            --dark-color: #1e293b;
            --gray-color: #64748b;
            --sidebar-width: 250px;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        /* Sidebar styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            z-index: 1000;
            padding-top: 70px;
            overflow-y: auto;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        .sidebar .nav-link {
            color: #cbd5e1;
            padding: 12px 20px;
            margin: 4px 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .sidebar .nav-link i {
            width: 24px;
            text-align: center;
            margin-right: 10px;
        }
        
        /* Top navbar */
        .top-navbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: 70px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            z-index: 999;
            padding: 0 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 90px 30px 30px 30px;
            min-height: 100vh;
        }
        
        /* Stats cards */
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
            border-left: 4px solid var(--primary-color);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
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
        
        .stat-icon.total { background-color: var(--primary-color); }
        .stat-icon.active { background-color: var(--success-color); }
        .stat-icon.salary { background-color: var(--warning-color); }
        .stat-icon.departments { background-color: var(--info-color); }
        .stat-icon.accounts { background-color: var(--secondary-color); }
        
        /* Table styles */
        .data-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        
        .data-table thead th {
            background-color: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            color: #475569;
            padding: 15px 20px;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
        
        .data-table tbody td {
            padding: 15px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .data-table tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .employee-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
            margin-right: 12px;
        }
        
        /* Form styles */
        .form-container {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        
        .form-label {
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
        }
        
        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(79, 70, 229, 0.15);
        }
        
        /* Password strength indicator */
        .password-strength-meter {
            height: 4px;
            background-color: #e2e8f0;
            border-radius: 2px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .password-strength-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        
        .password-strength-weak { background-color: #ef4444; }
        .password-strength-medium { background-color: #f59e0b; }
        .password-strength-strong { background-color: #10b981; }
        
        /* Status badges */
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        /* Empty state */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #64748b;
        }
        
        .empty-state i {
            font-size: 64px;
            color: #cbd5e1;
            margin-bottom: 20px;
        }
        
        /* Pagination */
        .pagination .page-link {
            color: var(--primary-color);
            border: 1px solid #e2e8f0;
            margin: 0 2px;
            border-radius: 8px;
        }
        
        .pagination .page-link:hover {
            background-color: #f1f5f9;
            border-color: #cbd5e1;
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        /* Toast notifications */
        .toast-container {
            position: fixed;
            top: 90px;
            right: 30px;
            z-index: 1055;
        }
        
        .toast {
            border: none;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        /* View mode styles */
        .profile-header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        
        .profile-avatar-lg {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 36px;
            margin-right: 30px;
        }
        
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid #f1f5f9;
        }
        
        .info-card h5 {
            color: #475569;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-item {
            margin-bottom: 15px;
        }
        
        .info-label {
            font-size: 0.85rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 1rem;
            color: #1e293b;
            font-weight: 500;
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
            }
            
            .sidebar .nav-link span {
                display: none;
            }
            
            .sidebar .nav-link i {
                margin-right: 0;
            }
            
            .top-navbar,
            .main-content {
                margin-left: 70px;
            }
            
            .sidebar:hover {
                width: var(--sidebar-width);
            }
            
            .sidebar:hover .nav-link span {
                display: inline;
            }
            
            .sidebar:hover .nav-link i {
                margin-right: 10px;
            }
        }
        
        @media (max-width: 768px) {
            .top-navbar {
                padding: 0 15px;
            }
            
            .main-content {
                padding: 90px 15px 15px 15px;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-avatar-lg {
                margin-right: 0;
                margin-bottom: 20px;
            }
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            display: none;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
        }
        
        /* Custom button styles */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        
        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        /* Tab navigation */
        .nav-tabs .nav-link {
            color: #64748b;
            border: none;
            padding: 12px 24px;
            font-weight: 600;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
            background-color: transparent;
        }
        
        /* Card hover effects */
        .card-hover {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .card-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 30px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>
    
    <!-- Sidebar -->
    <?php include 'sidebar_hr.php'; ?>
    
    <!-- Top Navbar -->
    <nav class="top-navbar navbar navbar-expand-lg navbar-light bg-white">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <button class="btn btn-outline-secondary d-lg-none me-3" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="h4 mb-0 text-dark fw-bold"><i class="bi bi-people me-2"></i> Employee Management</h1>
            </div>
            
            <div class="navbar-nav ms-auto align-items-center">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px;">
                            <i class="bi bi-person"></i>
                        </div>
                        <span class="d-none d-md-inline">HR Admin</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#"><i class="bi bi-person me-2"></i> Profile</a></li>
                        <li><a class="dropdown-item" href="#"><i class="bi bi-gear me-2"></i> Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Toast Notifications -->
    <div class="toast-container">
        <?php if ($message): ?>
        <div class="toast align-items-center text-bg-success border-0 mb-3" role="alert" data-bs-autohide="true" data-bs-delay="5000">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo $message; ?>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="toast align-items-center text-bg-danger border-0 mb-3" role="alert" data-bs-autohide="true" data-bs-delay="5000">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $error; ?>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($password_message): ?>
        <div class="toast align-items-center text-bg-info border-0 mb-3" role="alert" data-bs-autohide="true" data-bs-delay="5000">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-key-fill me-2"></i>
                    <?php echo $password_message; ?>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Header with Actions -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="h3 fw-bold text-dark mb-1">
                    <?php 
                    if ($action == 'add') echo 'Add New Employee';
                    elseif ($action == 'edit') echo 'Edit Employee';
                    elseif ($action == 'view') echo 'Employee Details';
                    else echo 'Employee Management';
                    ?>
                </h2>
                <p class="text-muted mb-0">
                    <?php 
                    if ($action == 'add') echo 'Add a new employee to the system';
                    elseif ($action == 'edit') echo 'Edit employee information';
                    elseif ($action == 'view') echo 'View complete employee profile';
                    else echo 'Manage all employees in the organization';
                    ?>
                </p>
            </div>
            
            <?php if ($action != 'view' && $action != 'edit'): ?>
            <a href="?action=add" class="btn btn-primary">
                <i class="bi bi-person-plus me-2"></i> Add Employee
            </a>
            <?php endif; ?>
        </div>
        
        <!-- Stats Cards -->
        <?php if ($action == '' || $action == 'list'): ?>
        <div class="row g-4 mb-4">
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon total">
                        <i class="bi bi-people"></i>
                    </div>
                    <h3 class="h2 fw-bold mb-2"><?php echo $stats['total']; ?></h3>
                    <p class="text-muted mb-0">Total Employees</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon active">
                        <i class="bi bi-person-check"></i>
                    </div>
                    <h3 class="h2 fw-bold mb-2"><?php echo $stats['active']; ?></h3>
                    <p class="text-muted mb-0">Active Employees</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon salary">
                        <i class="bi bi-currency-rupee"></i>
                    </div>
                    <h3 class="h2 fw-bold mb-2">₹<?php echo number_format($stats['avg_salary'], 0); ?></h3>
                    <p class="text-muted mb-0">Avg. Salary</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon departments">
                        <i class="bi bi-building"></i>
                    </div>
                    <h3 class="h2 fw-bold mb-2"><?php echo $stats['dept_count']; ?></h3>
                    <p class="text-muted mb-0">Departments</p>
                </div>
            </div>
            
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon accounts">
                        <i class="bi bi-person-circle"></i>
                    </div>
                    <h3 class="h2 fw-bold mb-2"><?php echo $stats['users_with_account']; ?></h3>
                    <p class="text-muted mb-0">User Accounts</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Employee Form -->
        <?php if ($action == 'add' || $action == 'edit'): ?>
        <div class="row">
            <div class="col-12">
                <div class="form-container">
                    <!-- Default Password Info -->
                    <div class="alert alert-info mb-4">
                        <div class="d-flex">
                            <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                            <div>
                                <h5 class="alert-heading mb-2">Automatic User Account Creation</h5>
                                <p class="mb-1">✓ Every employee will automatically get a user account created</p>
                                <p class="mb-1">✓ Default password: <strong>1234567</strong></p>
                                <p class="mb-1">✓ Login URL: <a href="login_emp.php" target="_blank" class="alert-link">login_emp.php</a></p>
                                <p class="mb-0">✓ Employees should change their password after first login for security</p>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" action="" id="employeeForm">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" name="first_name" class="form-control form-control-lg" 
                                           value="<?php echo htmlspecialchars($employee['first_name'] ?? ''); ?>" 
                                           required maxlength="50">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" name="last_name" class="form-control form-control-lg" 
                                           value="<?php echo htmlspecialchars($employee['last_name'] ?? ''); ?>" 
                                           required maxlength="50">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control form-control-lg" 
                                           value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>" 
                                           required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                                    <input type="tel" name="contact_number" class="form-control form-control-lg" 
                                           value="<?php echo htmlspecialchars($employee['contact_number'] ?? ''); ?>" 
                                           required pattern="[0-9]{10,15}">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Department <span class="text-danger">*</span></label>
                                    <select name="department_id" class="form-select form-select-lg" required>
                                        <option value="">Select Department</option>
                                        <?php foreach($departments as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>" 
                                                <?php echo (isset($employee['department_id']) && $employee['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Designation <span class="text-danger">*</span></label>
                                    <input type="text" name="designation" class="form-control form-control-lg" 
                                           value="<?php echo htmlspecialchars($employee['designation'] ?? ''); ?>" 
                                           required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Joining Date <span class="text-danger">*</span></label>
                                    <input type="date" name="joining_date" class="form-control form-control-lg" 
                                           value="<?php echo htmlspecialchars($employee['joining_date'] ?? date('Y-m-d')); ?>" 
                                           required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Employment Status <span class="text-danger">*</span></label>
                                    <select name="employment_status" class="form-select form-select-lg" required>
                                        <option value="active" <?php echo (isset($employee['employment_status']) && $employee['employment_status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo (isset($employee['employment_status']) && $employee['employment_status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="on_leave" <?php echo (isset($employee['employment_status']) && $employee['employment_status'] == 'on_leave') ? 'selected' : ''; ?>>On Leave</option>
                                        <option value="terminated" <?php echo (isset($employee['employment_status']) && $employee['employment_status'] == 'terminated') ? 'selected' : ''; ?>>Terminated</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Salary (₹) <span class="text-danger">*</span></label>
                                    <input type="number" name="salary" class="form-control form-control-lg" 
                                           value="<?php echo htmlspecialchars($employee['salary'] ?? ''); ?>" 
                                           step="0.01" min="0" required>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <div class="mb-3">
                                    <label class="form-label">Address</label>
                                    <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($employee['address'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between mt-4 pt-4 border-top">
                            <div>
                                <?php if ($action == 'edit'): ?>
                                    <input type="hidden" name="update_employee" value="1">
                                    <button type="submit" class="btn btn-primary btn-lg px-4">
                                        <i class="bi bi-check-circle me-2"></i> Update Employee
                                    </button>
                                    <a href="?action=delete&id=<?php echo $employee_id; ?>" 
                                       class="btn btn-danger btn-lg ms-2" 
                                       onclick="return confirm('Are you sure you want to delete this employee? This will also delete their user account if it exists.')">
                                        <i class="bi bi-trash me-2"></i> Delete
                                    </a>
                                <?php elseif ($action == 'add'): ?>
                                    <input type="hidden" name="add_employee" value="1">
                                    <button type="submit" class="btn btn-primary btn-lg px-4">
                                        <i class="bi bi-plus-circle me-2"></i> Add Employee
                                    </button>
                                <?php endif; ?>
                            </div>
                            <a href="emp_manage.php" class="btn btn-outline-secondary btn-lg">
                                <i class="bi bi-x-circle me-2"></i> Cancel
                            </a>
                        </div>
                    </form>
                    
                    <!-- Password Update Form -->
                    <?php if ($action == 'edit' && $user_account_exists): ?>
                    <div class="mt-5 pt-4 border-top">
                        <h5 class="mb-4"><i class="bi bi-key me-2"></i> Update User Password</h5>
                        <form method="POST" action="" id="passwordForm" class="row g-3">
                            <input type="hidden" name="update_password" value="1">
                            <div class="col-md-6">
                                <label class="form-label">New Password</label>
                                <div class="input-group">
                                    <input type="password" name="new_password" id="newPassword" class="form-control" 
                                           placeholder="Enter new password" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('newPassword')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="password-strength-meter mt-2">
                                    <div class="password-strength-fill" id="passwordStrengthFill"></div>
                                </div>
                                <small class="text-muted" id="passwordStrengthText">Password strength</small>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Confirm Password</label>
                                <div class="input-group">
                                    <input type="password" name="confirm_password" id="confirmPassword" class="form-control" 
                                           placeholder="Confirm new password" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirmPassword')">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-info">
                                    <i class="bi bi-arrow-repeat me-2"></i> Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Employee View -->
        <?php if ($action == 'view' && $employee): ?>
        <div class="row">
            <div class="col-12">
                <div class="profile-header d-flex align-items-center">
                    <div class="profile-avatar-lg" style="background-color: <?php echo getAvatarColor($employee['id']); ?>">
                        <?php echo strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <h3 class="h2 fw-bold mb-2"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h3>
                        <p class="text-muted mb-1"><i class="bi bi-envelope me-2"></i> <?php echo htmlspecialchars($employee['email']); ?></p>
                        <p class="text-muted mb-1"><i class="bi bi-telephone me-2"></i> <?php echo htmlspecialchars($employee['contact_number']); ?></p>
                        <p class="text-muted mb-0"><i class="bi bi-person-badge me-2"></i> <?php echo htmlspecialchars($employee['emp_id'] ?? 'EMP' . $employee['id']); ?></p>
                    </div>
                    <div class="ms-auto">
                        <span class="status-badge <?php echo getStatusBadgeClass($employee['employment_status'] ?? 'active'); ?>">
                            <?php 
                            $icon = 'bi-person';
                            if ($employee['employment_status'] == 'active') $icon = 'bi-person-check';
                            elseif ($employee['employment_status'] == 'inactive') $icon = 'bi-person-x';
                            elseif ($employee['employment_status'] == 'on_leave') $icon = 'bi-moon';
                            elseif ($employee['employment_status'] == 'terminated') $icon = 'bi-person-dash';
                            ?>
                            <i class="bi <?php echo $icon; ?> me-1"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $employee['employment_status'] ?? 'active')); ?>
                        </span>
                    </div>
                </div>
                
                <!-- User Account Info -->
                <div class="info-card">
                    <h5><i class="bi bi-person-circle me-2"></i> User Account</h5>
                    <?php if ($user_account_exists): ?>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-label">Account Status</div>
                                <div class="info-value">
                                    <span class="badge bg-success">Active</span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">User ID</div>
                                <div class="info-value"><?php echo $employee['user_id']; ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-item">
                                <div class="info-label">Role</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['user_role'] ?? 'employee'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Login Instructions</div>
                                <div class="info-value">
                                    <small>
                                        <i class="bi bi-box-arrow-in-right me-1"></i> 
                                        <a href="login_emp.php" target="_blank">login_emp.php</a>
                                        <br>
                                        <i class="bi bi-key me-1"></i> Default Password: <strong>1234567</strong>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Password Reset Form -->
                    <div class="mt-4 pt-3 border-top">
                        <button class="btn btn-outline-info btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#resetPasswordForm">
                            <i class="bi bi-key me-1"></i> Reset Password
                        </button>
                        
                        <div class="collapse mt-3" id="resetPasswordForm">
                            <form method="POST" action="" class="row g-3">
                                <input type="hidden" name="update_password" value="1">
                                <div class="col-md-6">
                                    <label class="form-label">New Password</label>
                                    <div class="input-group">
                                        <input type="password" name="new_password" class="form-control" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword(this)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Confirm Password</label>
                                    <div class="input-group">
                                        <input type="password" name="confirm_password" class="form-control" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword(this)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-info btn-sm">
                                        <i class="bi bi-check-circle me-1"></i> Update Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <div class="d-flex">
                            <i class="bi bi-exclamation-triangle-fill fs-5 me-3"></i>
                            <div>
                                <h6 class="alert-heading">No User Account</h6>
                                <p class="mb-0">This employee doesn't have a user account yet. They won't be able to login to the system.</p>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" action="" class="mt-3">
                        <input type="hidden" name="create_user_account" value="1">
                        <input type="hidden" name="generate_password" value="1">
                        <button type="submit" class="btn btn-info">
                            <i class="bi bi-person-plus me-2"></i> Create User Account
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                
                <!-- Employee Details -->
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="info-card h-100">
                            <h5><i class="bi bi-briefcase me-2"></i> Employment Details</h5>
                            <div class="info-item">
                                <div class="info-label">Designation</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['designation']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Department</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['department_name'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Joining Date</div>
                                <div class="info-value"><?php echo date('d M Y', strtotime($employee['joining_date'] ?? $employee['created_at'])); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Employment Status</div>
                                <div class="info-value">
                                    <span class="status-badge <?php echo getStatusBadgeClass($employee['employment_status'] ?? 'active'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $employee['employment_status'] ?? 'active')); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="info-card h-100">
                            <h5><i class="bi bi-cash-coin me-2"></i> Salary Information</h5>
                            <div class="info-item">
                                <div class="info-label">Monthly Salary (₹)</div>
                                <div class="info-value"><?php echo number_format($employee['salary'] ?? 0, 2); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Annual Salary (₹)</div>
                                <div class="info-value"><?php echo number_format(($employee['salary'] ?? 0) * 12, 2); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12">
                        <div class="info-card">
                            <h5><i class="bi bi-info-circle me-2"></i> Additional Information</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">Employee ID</div>
                                        <div class="info-value"><?php echo htmlspecialchars($employee['emp_id'] ?? 'EMP' . $employee['id']); ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Record Created</div>
                                        <div class="info-value"><?php echo date('d M Y, h:i A', strtotime($employee['created_at'])); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <?php if (!empty($employee['address'])): ?>
                                    <div class="info-item">
                                        <div class="info-label">Address</div>
                                        <div class="info-value"><?php echo nl2br(htmlspecialchars($employee['address'])); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($employee['updated_at'])): ?>
                                    <div class="info-item">
                                        <div class="info-label">Last Updated</div>
                                        <div class="info-value"><?php echo date('d M Y, h:i A', strtotime($employee['updated_at'])); ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="d-flex justify-content-between mt-4 pt-4 border-top">
                    <div>
                        <a href="?action=edit&id=<?php echo $employee_id; ?>" class="btn btn-primary">
                            <i class="bi bi-pencil-square me-2"></i> Edit Employee
                        </a>
                        <a href="?action=delete&id=<?php echo $employee_id; ?>" 
                           class="btn btn-danger ms-2" 
                           onclick="return confirm('Are you sure you want to delete this employee? This will also delete their user account if it exists.')">
                            <i class="bi bi-trash me-2"></i> Delete Employee
                        </a>
                    </div>
                    <a href="emp_manage.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Employee List -->
        <?php if ($action == '' || $action == 'list'): ?>
        <div class="row">
            <div class="col-12">
                <div class="card data-table">
                    <div class="card-header bg-white border-bottom-0 py-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">All Employees</h5>
                            
                            <!-- Search and Filters -->
                            <form method="GET" action="" class="d-flex gap-2">
                                <div class="input-group" style="width: 300px;">
                                    <span class="input-group-text bg-transparent border-end-0">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0" name="search" 
                                           placeholder="Search employees..." value="<?php echo htmlspecialchars($search); ?>">
                                    <input type="hidden" name="page" value="1">
                                </div>
                                
                                <select name="status" class="form-select" style="width: 150px;" onchange="this.form.submit()">
                                    <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="on_leave" <?php echo $status_filter == 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                                    <option value="terminated" <?php echo $status_filter == 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                                </select>
                                
                                <select name="department" class="form-select" style="width: 180px;" onchange="this.form.submit()">
                                    <option value="all" <?php echo $dept_filter == 'all' ? 'selected' : ''; ?>>All Departments</option>
                                    <?php foreach($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" 
                                            <?php echo $dept_filter == $dept['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <?php if (empty($employees)): ?>
                        <div class="empty-state">
                            <i class="bi bi-people display-1"></i>
                            <h5 class="mt-3 mb-2">No Employees Found</h5>
                            <p class="text-muted mb-4">No employees match your search criteria. Try adjusting your filters or add a new employee.</p>
                            <a href="?action=add" class="btn btn-primary">
                                <i class="bi bi-person-plus me-2"></i> Add First Employee
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Employee ID</th>
                                        <th>Department</th>
                                        <th>Designation</th>
                                        <th>Join Date</th>
                                        <th>Salary</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($employees as $emp): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="employee-avatar" style="background-color: <?php echo getAvatarColor($emp['id']); ?>">
                                                    <?php echo strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <h6 class="mb-0"><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></h6>
                                                    <small class="text-muted"><?php echo htmlspecialchars($emp['email']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark"><?php echo htmlspecialchars($emp['emp_id'] ?? 'EMP' . $emp['id']); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($emp['department_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($emp['designation']); ?></td>
                                        <td><?php echo date('d M Y', strtotime($emp['joining_date'] ?? $emp['created_at'])); ?></td>
                                        <td>
                                            <span class="fw-bold">₹<?php echo number_format($emp['salary'] ?? 0, 2); ?></span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo getStatusBadgeClass($emp['employment_status'] ?? 'active'); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $emp['employment_status'] ?? 'active')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="?action=view&id=<?php echo $emp['id']; ?>" class="btn btn-outline-primary" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="?action=edit&id=<?php echo $emp['id']; ?>" class="btn btn-outline-success" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="?action=delete&id=<?php echo $emp['id']; ?>" 
                                                   class="btn btn-outline-danger" 
                                                   title="Delete"
                                                   onclick="return confirm('Are you sure you want to delete employee <?php echo $emp['id']; ?>? This will also delete their user account if it exists.')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($employees) && $total_pages > 1): ?>
                    <div class="card-footer bg-white border-top-0 py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="mb-0 text-muted">
                                    Showing <strong><?php echo (($page - 1) * $limit) + 1; ?>-<?php echo min($page * $limit, $total_records); ?></strong> 
                                    of <strong><?php echo $total_records; ?></strong> employees
                                </p>
                            </div>
                            
                            <nav aria-label="Page navigation">
                                <ul class="pagination mb-0">
                                    <?php
                                    // Previous button
                                    if ($page > 1): 
                                        $prev_params = $_GET;
                                        $prev_params['page'] = $page - 1;
                                        $prev_url = '?' . http_build_query($prev_params);
                                    ?>
                                        <li class="page-item">
                                            <a class="page-link" href="<?php echo $prev_url; ?>" aria-label="Previous">
                                                <span aria-hidden="true">&laquo;</span>
                                            </a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">&laquo;</span>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php
                                    // Page numbers
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++): 
                                        $page_params = $_GET;
                                        $page_params['page'] = $i;
                                        $page_url = '?' . http_build_query($page_params);
                                    ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo $page_url; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): 
                                        $next_params = $_GET;
                                        $next_params['page'] = $page + 1;
                                        $next_url = '?' . http_build_query($next_params);
                                    ?>
                                        <li class="page-item">
                                            <a class="page-link" href="<?php echo $next_url; ?>" aria-label="Next">
                                                <span aria-hidden="true">&raquo;</span>
                                            </a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">&raquo;</span>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>
    
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Initialize tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
        
        // Initialize toasts
        const toastElList = document.querySelectorAll('.toast');
        const toastList = [...toastElList].map(toastEl => {
            const toast = new bootstrap.Toast(toastEl);
            toast.show();
            return toast;
        });
        
        // Toggle password visibility
        function togglePassword(elementOrId) {
            let input, button;
            
            if (typeof elementOrId === 'string') {
                input = document.getElementById(elementOrId);
                button = input.nextElementSibling;
            } else {
                button = elementOrId;
                input = button.previousElementSibling;
            }
            
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
        
        // Password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            
            if (password.length >= 8) strength += 20;
            if (/[A-Z]/.test(password)) strength += 20;
            if (/[a-z]/.test(password)) strength += 20;
            if (/[0-9]/.test(password)) strength += 20;
            if (/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)) strength += 20;
            
            return strength;
        }
        
        // Update password strength indicator
        function updatePasswordStrength() {
            const passwordInput = document.getElementById('newPassword');
            const confirmInput = document.getElementById('confirmPassword');
            const strengthFill = document.getElementById('passwordStrengthFill');
            const strengthText = document.getElementById('passwordStrengthText');
            
            if (!passwordInput || !strengthFill) return;
            
            const strength = checkPasswordStrength(passwordInput.value);
            
            strengthFill.style.width = strength + '%';
            
            if (strength < 40) {
                strengthFill.className = 'password-strength-fill password-strength-weak';
                strengthText.textContent = 'Weak password';
                strengthText.className = 'text-danger';
            } else if (strength < 80) {
                strengthFill.className = 'password-strength-fill password-strength-medium';
                strengthText.textContent = 'Medium password';
                strengthText.className = 'text-warning';
            } else {
                strengthFill.className = 'password-strength-fill password-strength-strong';
                strengthText.textContent = 'Strong password';
                strengthText.className = 'text-success';
            }
            
            // Check if passwords match
            if (confirmInput && confirmInput.value) {
                if (passwordInput.value !== confirmInput.value) {
                    confirmInput.classList.add('is-invalid');
                } else {
                    confirmInput.classList.remove('is-invalid');
                }
            }
        }
        
        // Initialize password strength checking
        const newPasswordInput = document.getElementById('newPassword');
        if (newPasswordInput) {
            newPasswordInput.addEventListener('input', updatePasswordStrength);
        }
        
        const confirmPasswordInput = document.getElementById('confirmPassword');
        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', updatePasswordStrength);
        }
        
        // Form validation
        const employeeForm = document.getElementById('employeeForm');
        if (employeeForm) {
            employeeForm.addEventListener('submit', function(e) {
                let isValid = true;
                const requiredFields = this.querySelectorAll('[required]');
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        field.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        field.classList.remove('is-invalid');
                        
                        // Email validation
                        if (field.type === 'email' && !isValidEmail(field.value)) {
                            field.classList.add('is-invalid');
                            isValid = false;
                        }
                        
                        // Contact number validation
                        if (field.name === 'contact_number' && !isValidContactNumber(field.value)) {
                            field.classList.add('is-invalid');
                            isValid = false;
                        }
                        
                        // Salary validation
                        if (field.name === 'salary' && parseFloat(field.value) < 0) {
                            field.classList.add('is-invalid');
                            isValid = false;
                        }
                    }
                });
                
                if (isValid) {
                    // Show loading overlay
                    document.getElementById('loadingOverlay').style.display = 'flex';
                } else {
                    e.preventDefault();
                }
            });
        }
        
        // Password form validation
        const passwordForm = document.getElementById('passwordForm');
        if (passwordForm) {
            passwordForm.addEventListener('submit', function(e) {
                const newPassword = document.getElementById('newPassword');
                const confirmPassword = document.getElementById('confirmPassword');
                let isValid = true;
                
                if (!newPassword.value) {
                    newPassword.classList.add('is-invalid');
                    isValid = false;
                } else {
                    newPassword.classList.remove('is-invalid');
                }
                
                if (!confirmPassword.value) {
                    confirmPassword.classList.add('is-invalid');
                    isValid = false;
                } else {
                    confirmPassword.classList.remove('is-invalid');
                }
                
                if (newPassword.value && confirmPassword.value && newPassword.value !== confirmPassword.value) {
                    confirmPassword.classList.add('is-invalid');
                    isValid = false;
                }
                
                if (isValid) {
                    // Show loading overlay
                    document.getElementById('loadingOverlay').style.display = 'flex';
                } else {
                    e.preventDefault();
                }
            });
        }
        
        // Helper functions
        function isValidEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
        
        function isValidContactNumber(contactNumber) {
            const re = /^\d{10,15}$/;
            return re.test(contactNumber);
        }
        
        // Auto-hide toasts after 5 seconds
        setTimeout(() => {
            const toasts = document.querySelectorAll('.toast');
            toasts.forEach(toast => {
                const bsToast = bootstrap.Toast.getInstance(toast);
                if (bsToast) {
                    bsToast.hide();
                }
            });
        }, 5000);
        
        // Handle loading overlay for page transitions
        const links = document.querySelectorAll('a:not([target="_blank"]):not([href^="#"]):not([href^="javascript:"])');
        links.forEach(link => {
            link.addEventListener('click', function(e) {
                // Don't show loading for delete links (they have confirm dialogs)
                if (this.classList.contains('btn-danger') || this.getAttribute('onclick')?.includes('confirm')) {
                    return;
                }
                
                // Don't show loading for form submissions (handled in form submit)
                if (this.closest('form')) {
                    return;
                }
                
                document.getElementById('loadingOverlay').style.display = 'flex';
            });
        });
        
        // Hide loading overlay when page is fully loaded
        window.addEventListener('load', function() {
            document.getElementById('loadingOverlay').style.display = 'none';
        });
    </script>
</body>
</html>
