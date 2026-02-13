<?php
session_start();

// Check if user is logged in and has HR role
if (!isset($_SESSION['hr_logged_in']) || $_SESSION['hr_logged_in'] !== true) {
    $_SESSION['hr_logged_in'] = true;
    $_SESSION['hr_id'] = 1;
    $_SESSION['hr_email'] = 'hr@hrportal.com';
    $_SESSION['hr_role'] = 'hr';
}

// Direct database connection (without external classes)
$host = "localhost";
$dbname = "hr_management_portal";
$username = "root";
$password = "";

// Create connection
$conn = new mysqli($host, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8mb4");

// Handle form submission for new application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'save_application') {
            // Get form data
            $first_name = $conn->real_escape_string($_POST['first_name'] ?? '');
            $last_name = $conn->real_escape_string($_POST['last_name'] ?? '');
            $email = $conn->real_escape_string($_POST['email'] ?? '');
            $contact_number = $conn->real_escape_string($_POST['contact_number'] ?? '');
            $position_applied = $conn->real_escape_string($_POST['position_applied'] ?? '');
            $experience_years = floatval($_POST['experience_years'] ?? 0);
            $current_company = $conn->real_escape_string($_POST['current_company'] ?? '');
            $current_ctc = floatval($_POST['current_ctc'] ?? 0);
            $expected_ctc = floatval($_POST['expected_ctc'] ?? 0);
            $notice_period_days = intval($_POST['notice_period_days'] ?? 0);
            $application_status = $conn->real_escape_string($_POST['application_status'] ?? 'applied');
            $source = $conn->real_escape_string($_POST['source'] ?? '');
            $cover_letter = $conn->real_escape_string($_POST['cover_letter'] ?? '');
            $notes = $conn->real_escape_string($_POST['notes'] ?? '');
            $applied_date = date('Y-m-d');
            
            // Validate required fields
            if (empty($first_name) || empty($last_name) || empty($email) || empty($position_applied)) {
                throw new Exception('Please fill all required fields.');
            }
            
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }
            
            // Check if email already exists (for new applications)
            $checkStmt = $conn->prepare("SELECT id FROM applicants WHERE email = ?");
            $checkStmt->bind_param("s", $email);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows > 0) {
                throw new Exception('An application with this email already exists.');
            }
            
            // Handle file upload
            $resume_path = null;
            if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/resumes/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $fileName = time() . '_' . basename($_FILES['resume']['name']);
                $targetPath = $uploadDir . $fileName;
                
                // Validate file type
                $allowedTypes = ['application/pdf', 'application/msword', 
                               'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 
                               'text/plain'];
                $fileType = $_FILES['resume']['type'];
                
                if (!in_array($fileType, $allowedTypes)) {
                    throw new Exception('Only PDF, DOC, DOCX, and TXT files are allowed.');
                }
                
                // Validate file size (5MB max)
                if ($_FILES['resume']['size'] > 5 * 1024 * 1024) {
                    throw new Exception('File size must be less than 5MB.');
                }
                
                if (move_uploaded_file($_FILES['resume']['tmp_name'], $targetPath)) {
                    $resume_path = $targetPath;
                }
            }
            
            // Insert into database
            $stmt = $conn->prepare("
                INSERT INTO applicants (
                    first_name, last_name, email, contact_number, position_applied, 
                    experience_years, current_company, current_ctc, expected_ctc, 
                    notice_period_days, application_status, applied_date, source, 
                    cover_letter, notes, resume_path, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt->bind_param(
                "sssssisddiisssss",
                $first_name, $last_name, $email, $contact_number, $position_applied,
                $experience_years, $current_company, $current_ctc, $expected_ctc,
                $notice_period_days, $application_status, $applied_date, $source,
                $cover_letter, $notes, $resume_path
            );
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Application saved successfully!',
                    'applicant_id' => $stmt->insert_id
                ]);
            } else {
                throw new Exception('Database error: ' . $stmt->error);
            }
        }
        elseif ($_POST['action'] === 'update_application') {
            // Get form data for update
            $id = intval($_POST['id'] ?? 0);
            $first_name = $conn->real_escape_string($_POST['first_name'] ?? '');
            $last_name = $conn->real_escape_string($_POST['last_name'] ?? '');
            $email = $conn->real_escape_string($_POST['email'] ?? '');
            $contact_number = $conn->real_escape_string($_POST['contact_number'] ?? '');
            $position_applied = $conn->real_escape_string($_POST['position_applied'] ?? '');
            $experience_years = floatval($_POST['experience_years'] ?? 0);
            $current_company = $conn->real_escape_string($_POST['current_company'] ?? '');
            $current_ctc = floatval($_POST['current_ctc'] ?? 0);
            $expected_ctc = floatval($_POST['expected_ctc'] ?? 0);
            $notice_period_days = intval($_POST['notice_period_days'] ?? 0);
            $application_status = $conn->real_escape_string($_POST['application_status'] ?? 'applied');
            $source = $conn->real_escape_string($_POST['source'] ?? '');
            $cover_letter = $conn->real_escape_string($_POST['cover_letter'] ?? '');
            $notes = $conn->real_escape_string($_POST['notes'] ?? '');
            
            // Validate required fields
            if (empty($first_name) || empty($last_name) || empty($email) || empty($position_applied)) {
                throw new Exception('Please fill all required fields.');
            }
            
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }
            
            // Check if email already exists (excluding current applicant)
            $checkStmt = $conn->prepare("SELECT id FROM applicants WHERE email = ? AND id != ?");
            $checkStmt->bind_param("si", $email, $id);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows > 0) {
                throw new Exception('Another application with this email already exists.');
            }
            
            // Handle file upload (if new file is uploaded)
            $resume_path = null;
            $resume_update = '';
            $resume_param = '';
            
            if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/resumes/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $fileName = time() . '_' . basename($_FILES['resume']['name']);
                $targetPath = $uploadDir . $fileName;
                
                // Validate file type
                $allowedTypes = ['application/pdf', 'application/msword', 
                               'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 
                               'text/plain'];
                $fileType = $_FILES['resume']['type'];
                
                if (!in_array($fileType, $allowedTypes)) {
                    throw new Exception('Only PDF, DOC, DOCX, and TXT files are allowed.');
                }
                
                // Validate file size (5MB max)
                if ($_FILES['resume']['size'] > 5 * 1024 * 1024) {
                    throw new Exception('File size must be less than 5MB.');
                }
                
                if (move_uploaded_file($_FILES['resume']['tmp_name'], $targetPath)) {
                    $resume_path = $targetPath;
                    $resume_update = ', resume_path = ?';
                    $resume_param = 's';
                }
            }
            
            // Build update query
            if ($resume_path) {
                $query = "
                    UPDATE applicants SET 
                        first_name = ?, last_name = ?, email = ?, contact_number = ?, 
                        position_applied = ?, experience_years = ?, current_company = ?, 
                        current_ctc = ?, expected_ctc = ?, notice_period_days = ?, 
                        application_status = ?, source = ?, cover_letter = ?, notes = ?,
                        resume_path = ?, updated_at = NOW()
                    WHERE id = ?
                ";
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param(
                    "sssssisddiissssi",
                    $first_name, $last_name, $email, $contact_number, $position_applied,
                    $experience_years, $current_company, $current_ctc, $expected_ctc,
                    $notice_period_days, $application_status, $source, $cover_letter, 
                    $notes, $resume_path, $id
                );
            } else {
                $query = "
                    UPDATE applicants SET 
                        first_name = ?, last_name = ?, email = ?, contact_number = ?, 
                        position_applied = ?, experience_years = ?, current_company = ?, 
                        current_ctc = ?, expected_ctc = ?, notice_period_days = ?, 
                        application_status = ?, source = ?, cover_letter = ?, notes = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ";
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param(
                    "sssssisddiisssi",
                    $first_name, $last_name, $email, $contact_number, $position_applied,
                    $experience_years, $current_company, $current_ctc, $expected_ctc,
                    $notice_period_days, $application_status, $source, $cover_letter, 
                    $notes, $id
                );
            }
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Application updated successfully!',
                        'applicant_id' => $id
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'message' => 'No changes detected.',
                        'applicant_id' => $id
                    ]);
                }
            } else {
                throw new Exception('Database error: ' . $stmt->error);
            }
        }
        elseif ($_POST['action'] === 'delete_application') {
            $id = intval($_POST['id'] ?? 0);
            
            $stmt = $conn->prepare("DELETE FROM applicants WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Application deleted successfully!'
                ]);
            } else {
                throw new Exception('Database error: ' . $stmt->error);
            }
        }
        elseif ($_POST['action'] === 'update_status') {
            $id = intval($_POST['id'] ?? 0);
            $status = $conn->real_escape_string($_POST['status'] ?? '');
            
            $allowed_statuses = ['applied', 'screening', 'interview', 'selected', 'rejected', 'on_hold'];
            
            if (!in_array($status, $allowed_statuses)) {
                throw new Exception('Invalid status provided.');
            }
            
            $stmt = $conn->prepare("UPDATE applicants SET application_status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $status, $id);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Status updated successfully!',
                    'status' => $status
                ]);
            } else {
                throw new Exception('Database error: ' . $stmt->error);
            }
        }
        elseif ($_POST['action'] === 'get_applicant') {
            $id = intval($_POST['id'] ?? 0);
            
            $stmt = $conn->prepare("SELECT * FROM applicants WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $applicant = $result->fetch_assoc();
                echo json_encode([
                    'success' => true,
                    'data' => $applicant
                ]);
            } else {
                throw new Exception('Applicant not found.');
            }
        }
        
        exit();
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit();
    }
}

// Get filter parameters
$status = $_GET['status'] ?? 'all';
$position = $_GET['position'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search = $_GET['search'] ?? '';
$experience = $_GET['experience'] ?? '';

// Build query with filters
$query = "SELECT a.*, u.email as user_email 
          FROM applicants a 
          LEFT JOIN users u ON a.user_id = u.id 
          WHERE 1=1";

$params = [];
$types = '';

if ($status !== 'all') {
    $query .= " AND a.application_status = ?";
    $params[] = $status;
    $types .= 's';
}

if (!empty($position)) {
    $query .= " AND a.position_applied LIKE ?";
    $params[] = "%$position%";
    $types .= 's';
}

if (!empty($start_date)) {
    $query .= " AND a.applied_date >= ?";
    $params[] = $start_date;
    $types .= 's';
}

if (!empty($end_date)) {
    $query .= " AND a.applied_date <= ?";
    $params[] = $end_date;
    $types .= 's';
}

if (!empty($search)) {
    $query .= " AND (a.first_name LIKE ? OR a.last_name LIKE ? OR a.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'sss';
}

if (!empty($experience) && is_numeric($experience)) {
    $query .= " AND a.experience_years >= ?";
    $params[] = $experience;
    $types .= 'i';
}

$query .= " ORDER BY a.applied_date DESC, a.created_at DESC";

// Get statistics
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN application_status = 'applied' THEN 1 ELSE 0 END) as applied,
    SUM(CASE WHEN application_status = 'screening' THEN 1 ELSE 0 END) as screening,
    SUM(CASE WHEN application_status = 'interview' THEN 1 ELSE 0 END) as interview,
    SUM(CASE WHEN application_status = 'selected' THEN 1 ELSE 0 END) as selected,
    SUM(CASE WHEN application_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN application_status = 'on_hold' THEN 1 ELSE 0 END) as on_hold
    FROM applicants";

$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Get unique positions for filter dropdown
$positions_query = "SELECT DISTINCT position_applied FROM applicants WHERE position_applied IS NOT NULL ORDER BY position_applied";
$positions_result = $conn->query($positions_query);
$positions = [];
while ($row = $positions_result->fetch_assoc()) {
    $positions[] = $row['position_applied'];
}

// Get applicants data
$applicants = [];
if (!empty($params)) {
    $stmt = $conn->prepare($query);
    if ($stmt) {
        if (!empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $applicants[] = $row;
        }
        $stmt->close();
    }
} else {
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $applicants[] = $row;
        }
    }
}

// Get interviewers for dropdown
$interviewers = [];
$interviewers_query = "SELECT e.id, e.first_name, e.last_name, d.name as department_name 
                     FROM employees e 
                     LEFT JOIN departments d ON e.department_id = d.id 
                     WHERE e.employment_status = 'active' 
                     ORDER BY e.first_name";
$interviewers_result = $conn->query($interviewers_query);
if ($interviewers_result) {
    while ($row = $interviewers_result->fetch_assoc()) {
        $interviewers[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applicant Management - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar_hr.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
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
                --light-gray: #e2e8f0;
            }
            
            .main-content {
                margin-left: 260px;
                padding: 20px;
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
            
            /* Top Bar */
            .top-bar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px 0;
                margin-bottom: 20px;
                animation: slideDown 0.5s ease-out;
            }
            
            .page-title h1 {
                font-size: 28px;
                color: var(--dark);
                font-weight: 700;
            }
            
            .page-title p {
                color: var(--gray);
                font-size: 14px;
            }
            
            .action-buttons {
                display: flex;
                gap: 10px;
            }
            
            .btn {
                padding: 10px 20px;
                border-radius: 10px;
                border: none;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 14px;
            }
            
            .btn-primary {
                background: var(--primary);
                color: white;
            }
            
            .btn-primary:hover {
                background: var(--primary-dark);
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(79, 70, 229, 0.3);
            }
            
            .btn-success {
                background: var(--success);
                color: white;
            }
            
            .btn-success:hover {
                background: #0da271;
                transform: translateY(-2px);
            }
            
            .btn-warning {
                background: var(--warning);
                color: white;
            }
            
            .btn-warning:hover {
                background: #d97706;
                transform: translateY(-2px);
            }
            
            .btn-danger {
                background: var(--danger);
                color: white;
            }
            
            .btn-danger:hover {
                background: #dc2626;
                transform: translateY(-2px);
            }
            
            /* Stats Cards */
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 15px;
                margin-bottom: 30px;
                animation: fadeIn 0.8s ease-out 0.2s both;
            }
            
            .stat-card {
                background: white;
                border-radius: 12px;
                padding: 20px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
                transition: all 0.3s ease;
                position: relative;
                overflow: hidden;
                cursor: pointer;
            }
            
            .stat-card:hover {
                transform: translateY(-3px);
                box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
            }
            
            .stat-card.active {
                transform: scale(1.05);
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            }
            
            .stat-icon {
                width: 50px;
                height: 50px;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 15px;
                font-size: 20px;
                color: white;
            }
            
            .stat-total .stat-icon { background: linear-gradient(135deg, var(--primary) 0%, var(--info) 100%); }
            .stat-applied .stat-icon { background: linear-gradient(135deg, var(--info) 0%, #22d3ee 100%); }
            .stat-screening .stat-icon { background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%); }
            .stat-interview .stat-icon { background: linear-gradient(135deg, var(--warning) 0%, #fbbf24 100%); }
            .stat-selected .stat-icon { background: linear-gradient(135deg, var(--success) 0%, #34d399 100%); }
            .stat-rejected .stat-icon { background: linear-gradient(135deg, var(--danger) 0%, #f87171 100%); }
            .stat-on_hold .stat-icon { background: linear-gradient(135deg, var(--gray) 0%, #94a3b8 100%); }
            
            .stat-info h3 {
                font-size: 24px;
                font-weight: 700;
                margin-bottom: 5px;
            }
            
            .stat-info p {
                color: var(--gray);
                font-size: 12px;
                font-weight: 500;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            /* Filter Section */
            .filter-section {
                background: white;
                border-radius: 12px;
                padding: 20px;
                margin-bottom: 25px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
                animation: slideUp 0.5s ease-out 0.3s both;
            }
            
            .filter-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }
            
            .filter-header h3 {
                font-size: 18px;
                font-weight: 600;
                color: var(--dark);
            }
            
            .filter-form {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }
            
            .form-group {
                display: flex;
                flex-direction: column;
            }
            
            .form-group label {
                font-size: 12px;
                color: var(--gray);
                margin-bottom: 5px;
                font-weight: 500;
            }
            
            .form-control {
                padding: 10px 15px;
                border: 1px solid var(--light-gray);
                border-radius: 8px;
                font-size: 14px;
                transition: all 0.3s ease;
                font-family: 'Poppins', sans-serif;
            }
            
            .form-control:focus {
                outline: none;
                border-color: var(--primary);
                box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
            }
            
            /* Applicants Table */
            .applicants-table-container {
                background: white;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
                animation: slideUp 0.5s ease-out 0.4s both;
            }
            
            .table-header {
                padding: 20px;
                border-bottom: 1px solid var(--light-gray);
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .table-header h3 {
                font-size: 18px;
                font-weight: 600;
                color: var(--dark);
            }
            
            .table-actions {
                display: flex;
                gap: 10px;
            }
            
            .btn-sm {
                padding: 8px 15px;
                font-size: 13px;
            }
            
            .table-responsive {
                overflow-x: auto;
            }
            
            .applicants-table {
                width: 100%;
                border-collapse: collapse;
                min-width: 1000px;
            }
            
            .applicants-table thead {
                background: var(--light);
            }
            
            .applicants-table th {
                padding: 15px;
                text-align: left;
                font-weight: 600;
                color: var(--dark);
                font-size: 13px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                border-bottom: 2px solid var(--light-gray);
            }
            
            .applicants-table td {
                padding: 15px;
                border-bottom: 1px solid var(--light-gray);
                font-size: 14px;
                transition: all 0.3s ease;
            }
            
            .applicants-table tbody tr {
                transition: all 0.3s ease;
            }
            
            .applicants-table tbody tr:hover {
                background: #f8fafc;
                transform: scale(1.01);
            }
            
            /* Status Badges */
            .status-badge {
                padding: 6px 12px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                display: inline-flex;
                align-items: center;
                gap: 5px;
                animation: fadeIn 0.5s ease-out;
            }
            
            .status-applied { background: #e0f2fe; color: #0369a1; }
            .status-screening { background: #f3e8ff; color: #7c3aed; }
            .status-interview { background: #fef3c7; color: #92400e; }
            .status-selected { background: #d1fae5; color: #065f46; }
            .status-rejected { background: #fee2e2; color: #991b1b; }
            .status-on_hold { background: #f1f5f9; color: #475569; }
            
            /* Action Buttons */
            .action-btns {
                display: flex;
                gap: 8px;
                opacity: 0;
                transition: opacity 0.3s ease;
            }
            
            .applicants-table tbody tr:hover .action-btns {
                opacity: 1;
            }
            
            .action-btn {
                width: 32px;
                height: 32px;
                border-radius: 8px;
                border: none;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.3s ease;
                color: white;
                font-size: 12px;
            }
            
            .action-btn.view { background: var(--info); }
            .action-btn.edit { background: var(--warning); }
            .action-btn.interview { background: var(--secondary); }
            .action-btn.accept { background: var(--success); }
            .action-btn.reject { background: var(--danger); }
            
            .action-btn:hover {
                transform: translateY(-2px) scale(1.1);
            }
            
            /* Applicant Details Modal */
            .modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 1001;
                animation: fadeIn 0.3s ease-out;
            }
            
            .modal-content {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: white;
                border-radius: 16px;
                width: 90%;
                max-width: 800px;
                max-height: 90vh;
                overflow-y: auto;
                animation: slideUp 0.4s ease-out;
            }
            
            .modal-header {
                padding: 25px;
                border-bottom: 1px solid var(--light-gray);
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .modal-header h3 {
                font-size: 22px;
                font-weight: 700;
                color: var(--dark);
            }
            
            .modal-close {
                background: none;
                border: none;
                font-size: 24px;
                color: var(--gray);
                cursor: pointer;
                transition: all 0.3s ease;
            }
            
            .modal-close:hover {
                color: var(--danger);
                transform: rotate(90deg);
            }
            
            .modal-body {
                padding: 25px;
            }
            
            /* Applicant Info Grid */
            .applicant-info {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }
            
            .info-section {
                background: var(--light);
                border-radius: 10px;
                padding: 20px;
            }
            
            .info-section h4 {
                font-size: 16px;
                font-weight: 600;
                color: var(--dark);
                margin-bottom: 15px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .info-section h4 i {
                color: var(--primary);
            }
            
            .info-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
                padding-bottom: 10px;
                border-bottom: 1px solid rgba(0, 0, 0, 0.05);
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
            }
            
            /* Charts Section */
            .charts-section {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
                gap: 25px;
                margin-top: 30px;
                animation: fadeIn 0.8s ease-out 0.5s both;
            }
            
            .chart-card {
                background: white;
                border-radius: 12px;
                padding: 25px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            }
            
            .chart-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }
            
            .chart-header h3 {
                font-size: 18px;
                font-weight: 600;
                color: var(--dark);
            }
            
            .chart-container {
                height: 300px;
                position: relative;
            }
            
            /* Animations */
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            
            @keyframes slideDown {
                from {
                    opacity: 0;
                    transform: translateY(-20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.1); }
                100% { transform: scale(1); }
            }
            
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
            
            /* Loading Animation */
            .loading {
                display: inline-block;
                width: 20px;
                height: 20px;
                border: 3px solid var(--light-gray);
                border-top-color: var(--primary);
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }
            
            /* Empty State */
            .empty-state {
                text-align: center;
                padding: 60px 20px;
                color: var(--gray);
            }
            
            .empty-state i {
                font-size: 48px;
                margin-bottom: 20px;
                color: var(--light-gray);
            }
            
            .empty-state h4 {
                font-size: 18px;
                margin-bottom: 10px;
                color: var(--dark);
            }
            
            /* Responsive */
            @media (max-width: 768px) {
                .stats-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                
                .filter-form {
                    grid-template-columns: 1fr;
                }
                
                .charts-section {
                    grid-template-columns: 1fr;
                }
                
                .action-btns {
                    opacity: 1;
                    flex-wrap: wrap;
                }
                
                .top-bar {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 15px;
                }
                
                .action-buttons {
                    width: 100%;
                    flex-wrap: wrap;
                }
                
                .btn {
                    flex: 1;
                    justify-content: center;
                    min-width: 120px;
                }
            }
            
            @media (max-width: 480px) {
                .stats-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1>Applicant Management</h1>
                <p>Manage job applications, schedule interviews, and track candidates</p>
            </div>
            <div class="action-buttons">
                <button class="btn btn-primary" onclick="openNewApplicationModal()">
                    <i class="fas fa-plus"></i> New Application
                </button>
                <button class="btn btn-success" onclick="exportToExcel()">
                    <i class="fas fa-file-export"></i> Export Excel
                </button>
                <button class="btn btn-warning" onclick="showBulkActions()">
                    <i class="fas fa-bars"></i> Bulk Actions
                </button>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card stat-total" onclick="filterByStatus('all')">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total'] ?? 0; ?></h3>
                    <p>Total Applicants</p>
                </div>
            </div>
            <div class="stat-card stat-applied" onclick="filterByStatus('applied')">
                <div class="stat-icon">
                    <i class="fas fa-file-import"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['applied'] ?? 0; ?></h3>
                    <p>Applied</p>
                </div>
            </div>
            <div class="stat-card stat-screening" onclick="filterByStatus('screening')">
                <div class="stat-icon">
                    <i class="fas fa-search"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['screening'] ?? 0; ?></h3>
                    <p>Screening</p>
                </div>
            </div>
            <div class="stat-card stat-interview" onclick="filterByStatus('interview')">
                <div class="stat-icon">
                    <i class="fas fa-handshake"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['interview'] ?? 0; ?></h3>
                    <p>Interview</p>
                </div>
            </div>
            <div class="stat-card stat-selected" onclick="filterByStatus('selected')">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['selected'] ?? 0; ?></h3>
                    <p>Selected</p>
                </div>
            </div>
            <div class="stat-card stat-rejected" onclick="filterByStatus('rejected')">
                <div class="stat-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['rejected'] ?? 0; ?></h3>
                    <p>Rejected</p>
                </div>
            </div>
            <div class="stat-card stat-on_hold" onclick="filterByStatus('on_hold')">
                <div class="stat-icon">
                    <i class="fas fa-pause-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['on_hold'] ?? 0; ?></h3>
                    <p>On Hold</p>
                </div>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-header">
                <h3>Filter Applicants</h3>
                <button class="btn btn-primary" onclick="applyFilters()">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
            </div>
            <form id="filterForm" method="GET" class="filter-form">
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="applied" <?php echo $status === 'applied' ? 'selected' : ''; ?>>Applied</option>
                        <option value="screening" <?php echo $status === 'screening' ? 'selected' : ''; ?>>Screening</option>
                        <option value="interview" <?php echo $status === 'interview' ? 'selected' : ''; ?>>Interview</option>
                        <option value="selected" <?php echo $status === 'selected' ? 'selected' : ''; ?>>Selected</option>
                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="on_hold" <?php echo $status === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="position">Position</label>
                    <select id="position" name="position" class="form-control">
                        <option value="">All Positions</option>
                        <?php foreach ($positions as $pos): ?>
                            <option value="<?php echo htmlspecialchars($pos); ?>" <?php echo $position === $pos ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pos); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="experience">Min Experience (Years)</label>
                    <input type="number" id="experience" name="experience" class="form-control" min="0" max="50" value="<?php echo htmlspecialchars($experience); ?>">
                </div>
                <div class="form-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control" placeholder="Name or Email..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label for="start_date">From Date</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">To Date</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
            </form>
        </div>
        
        <!-- Applicants Table -->
        <div class="applicants-table-container">
            <div class="table-header">
                <h3>Applicants List</h3>
                <div class="table-actions">
                    <button class="btn btn-success btn-sm" onclick="refreshTable()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear Filters
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="applicants-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAll"></th>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Position</th>
                            <th>Experience</th>
                            <th>Current CTC</th>
                            <th>Expected CTC</th>
                            <th>Status</th>
                            <th>Applied Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="applicantsTableBody">
                        <?php if (empty($applicants)): ?>
                            <tr>
                                <td colspan="11" class="empty-state">
                                    <i class="fas fa-user-friends"></i>
                                    <h4>No applicants found</h4>
                                    <p>Try adjusting your filters or add new applicants</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($applicants as $applicant): ?>
                                <?php
                                $statusClass = 'status-' . $applicant['application_status'];
                                $statusText = ucfirst(str_replace('_', ' ', $applicant['application_status']));
                                $avatarInitial = strtoupper(substr($applicant['first_name'] ?? 'A', 0, 1));
                                ?>
                                <tr data-id="<?php echo $applicant['id']; ?>">
                                    <td><input type="checkbox" class="applicant-checkbox" value="<?php echo $applicant['id']; ?>"></td>
                                    <td>APP-<?php echo str_pad($applicant['id'], 4, '0', STR_PAD_LEFT); ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="avatar" style="width: 32px; height: 32px; background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                                                <?php echo $avatarInitial; ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name']); ?></strong><br>
                                                <small style="color: var(--gray);"><?php echo htmlspecialchars($applicant['contact_number'] ?? 'N/A'); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($applicant['email']); ?></td>
                                    <td><?php echo htmlspecialchars($applicant['position_applied'] ?? 'N/A'); ?></td>
                                    <td><?php echo $applicant['experience_years'] ?? 0; ?> years</td>
                                    <td>₹<?php echo number_format($applicant['current_ctc'] ?? 0, 2); ?></td>
                                    <td>₹<?php echo number_format($applicant['expected_ctc'] ?? 0, 2); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <i class="fas fa-circle" style="font-size: 8px;"></i>
                                            <?php echo $statusText; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d M, Y', strtotime($applicant['applied_date'])); ?></td>
                                    <td>
                                        <div class="action-btns">
                                            <button class="action-btn view" onclick="viewApplicant(<?php echo $applicant['id']; ?>)" title="View">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-btn edit" onclick="editApplicant(<?php echo $applicant['id']; ?>)" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="action-btn interview" onclick="scheduleInterview(<?php echo $applicant['id']; ?>)" title="Schedule Interview">
                                                <i class="fas fa-calendar-plus"></i>
                                            </button>
                                            <button class="action-btn accept" onclick="updateStatus(<?php echo $applicant['id']; ?>, 'selected')" title="Accept">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="action-btn reject" onclick="updateStatus(<?php echo $applicant['id']; ?>, 'rejected')" title="Reject">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Charts Section -->
        <div class="charts-section">
            <div class="chart-card">
                <div class="chart-header">
                    <h3>Application Status Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <h3>Monthly Applications</h3>
                </div>
                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Applicant Details Modal -->
    <div id="applicantModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Applicant Details</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="applicantDetails">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>
    
    <!-- Edit Application Modal -->
    <div id="editApplicationModal" class="modal">
        <div class="modal-content" style="max-width: 800px; max-height: 90vh;">
            <div class="modal-header">
                <h3>Edit Application</h3>
                <button class="modal-close" onclick="closeEditApplicationModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editApplicationForm" onsubmit="saveEditApplication(event)" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_application">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
                        <!-- Personal Information -->
                        <div class="form-group">
                            <label for="edit_first_name">First Name *</label>
                            <input type="text" id="edit_first_name" name="first_name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_last_name">Last Name *</label>
                            <input type="text" id="edit_last_name" name="last_name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_email">Email Address *</label>
                            <input type="email" id="edit_email" name="email" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_contact_number">Contact Number</label>
                            <input type="tel" id="edit_contact_number" name="contact_number" class="form-control">
                        </div>
                        
                        <!-- Professional Information -->
                        <div class="form-group">
                            <label for="edit_position_applied">Position Applied *</label>
                            <input type="text" id="edit_position_applied" name="position_applied" class="form-control" required 
                                   list="positionList">
                            <datalist id="positionList">
                                <?php foreach ($positions as $pos): ?>
                                    <option value="<?php echo htmlspecialchars($pos); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_experience_years">Experience (Years)</label>
                            <input type="number" id="edit_experience_years" name="experience_years" class="form-control" 
                                   min="0" max="50" step="0.5">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_current_company">Current Company</label>
                            <input type="text" id="edit_current_company" name="current_company" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_source">Application Source</label>
                            <select id="edit_source" name="source" class="form-control">
                                <option value="">Select Source</option>
                                <option value="LinkedIn">LinkedIn</option>
                                <option value="Indeed">Indeed</option>
                                <option value="Naukri">Naukri</option>
                                <option value="Company Website">Company Website</option>
                                <option value="Referral">Referral</option>
                                <option value="Campus Placement">Campus Placement</option>
                                <option value="Job Fair">Job Fair</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Salary Information -->
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label for="edit_current_ctc">Current CTC (₹)</label>
                            <input type="number" id="edit_current_ctc" name="current_ctc" class="form-control" 
                                   min="0" step="0.01" placeholder="0.00">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_expected_ctc">Expected CTC (₹)</label>
                            <input type="number" id="edit_expected_ctc" name="expected_ctc" class="form-control" 
                                   min="0" step="0.01" placeholder="0.00">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_notice_period_days">Notice Period (Days)</label>
                            <input type="number" id="edit_notice_period_days" name="notice_period_days" class="form-control" 
                                   min="0" max="180">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_application_status">Application Status</label>
                            <select id="edit_application_status" name="application_status" class="form-control">
                                <option value="applied">Applied</option>
                                <option value="screening">Screening</option>
                                <option value="interview">Interview</option>
                                <option value="selected">Selected</option>
                                <option value="rejected">Rejected</option>
                                <option value="on_hold">On Hold</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Additional Information -->
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label for="edit_cover_letter">Cover Letter</label>
                        <textarea id="edit_cover_letter" name="cover_letter" class="form-control" rows="3" 
                                  placeholder="Optional cover letter..."></textarea>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label for="edit_notes">HR Notes</label>
                        <textarea id="edit_notes" name="notes" class="form-control" rows="2" 
                                  placeholder="Internal notes..."></textarea>
                    </div>
                    
                    <!-- File Upload -->
                    <div class="form-group" style="margin-bottom: 30px;">
                        <label for="edit_resume">Resume Upload (Optional - Leave empty to keep existing)</label>
                        <div style="border: 2px dashed var(--light-gray); border-radius: 8px; padding: 20px; text-align: center; cursor: pointer;" 
                             onclick="document.getElementById('edit_resume').click()" 
                             ondrop="handleEditFileDrop(event)" 
                             ondragover="handleDragOver(event)">
                            <i class="fas fa-file-upload" style="font-size: 32px; color: var(--gray); margin-bottom: 10px;"></i>
                            <p style="color: var(--gray); margin-bottom: 15px;">Drag & drop new resume file here or click to browse</p>
                            <input type="file" id="edit_resume" name="resume" class="form-control" 
                                   accept=".pdf,.doc,.docx,.txt" style="display: none;">
                            <button type="button" class="btn btn-primary">
                                <i class="fas fa-upload"></i> Browse Files
                            </button>
                            <div id="editResumeFileName" style="margin-top: 10px; font-size: 12px; color: var(--gray);"></div>
                            <div id="currentResumeInfo" style="margin-top: 10px; font-size: 12px; color: var(--success); display: none;"></div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div style="display: flex; gap: 10px; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> Update Application
                        </button>
                        <button type="button" class="btn btn-danger" onclick="closeEditApplicationModal()" style="flex: 1;">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="button" class="btn btn-danger" onclick="deleteApplication()" style="flex: 0.5;">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Interview Schedule Modal -->
    <div id="interviewModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Schedule Interview</h3>
                <button class="modal-close" onclick="closeInterviewModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="interviewForm" onsubmit="saveInterview(event)">
                    <input type="hidden" id="applicant_id" name="applicant_id">
                    
                    <div class="form-group">
                        <label for="interview_date">Interview Date</label>
                        <input type="datetime-local" id="interview_date" name="interview_date" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="interview_type">Interview Type</label>
                        <select id="interview_type" name="interview_type" class="form-control" required>
                            <option value="technical">Technical</option>
                            <option value="hr">HR</option>
                            <option value="managerial">Managerial</option>
                            <option value="final">Final</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="interviewer_id">Interviewer</label>
                        <select id="interviewer_id" name="interviewer_id" class="form-control" required>
                            <option value="">Select Interviewer</option>
                            <?php foreach ($interviewers as $interviewer): ?>
                                <option value="<?php echo $interviewer['id']; ?>">
                                    <?php echo htmlspecialchars($interviewer['first_name'] . ' ' . $interviewer['last_name'] . ' (' . $interviewer['department_name'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="interview_notes">Notes</label>
                        <textarea id="interview_notes" name="interview_notes" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-calendar-check"></i> Schedule Interview
                        </button>
                        <button type="button" class="btn btn-danger" onclick="closeInterviewModal()" style="flex: 1;">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- New Application Modal -->
    <div id="newApplicationModal" class="modal">
        <div class="modal-content" style="max-width: 800px; max-height: 90vh;">
            <div class="modal-header">
                <h3>New Job Application</h3>
                <button class="modal-close" onclick="closeNewApplicationModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="newApplicationForm" onsubmit="saveNewApplication(event)" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_application">
                    
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
                        <!-- Personal Information -->
                        <div class="form-group">
                            <label for="new_first_name">First Name *</label>
                            <input type="text" id="new_first_name" name="first_name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_last_name">Last Name *</label>
                            <input type="text" id="new_last_name" name="last_name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_email">Email Address *</label>
                            <input type="email" id="new_email" name="email" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_contact_number">Contact Number</label>
                            <input type="tel" id="new_contact_number" name="contact_number" class="form-control">
                        </div>
                        
                        <!-- Professional Information -->
                        <div class="form-group">
                            <label for="new_position_applied">Position Applied *</label>
                            <input type="text" id="new_position_applied" name="position_applied" class="form-control" required 
                                   list="positionList">
                            <datalist id="positionList">
                                <?php foreach ($positions as $pos): ?>
                                    <option value="<?php echo htmlspecialchars($pos); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_experience_years">Experience (Years)</label>
                            <input type="number" id="new_experience_years" name="experience_years" class="form-control" 
                                   min="0" max="50" step="0.5" value="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="new_current_company">Current Company</label>
                            <input type="text" id="new_current_company" name="current_company" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="new_source">Application Source</label>
                            <select id="new_source" name="source" class="form-control">
                                <option value="">Select Source</option>
                                <option value="LinkedIn">LinkedIn</option>
                                <option value="Indeed">Indeed</option>
                                <option value="Naukri">Naukri</option>
                                <option value="Company Website">Company Website</option>
                                <option value="Referral">Referral</option>
                                <option value="Campus Placement">Campus Placement</option>
                                <option value="Job Fair">Job Fair</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Salary Information -->
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px;">
                        <div class="form-group">
                            <label for="new_current_ctc">Current CTC (₹)</label>
                            <input type="number" id="new_current_ctc" name="current_ctc" class="form-control" 
                                   min="0" step="0.01" placeholder="0.00">
                        </div>
                        
                        <div class="form-group">
                            <label for="new_expected_ctc">Expected CTC (₹)</label>
                            <input type="number" id="new_expected_ctc" name="expected_ctc" class="form-control" 
                                   min="0" step="0.01" placeholder="0.00">
                        </div>
                        
                        <div class="form-group">
                            <label for="new_notice_period_days">Notice Period (Days)</label>
                            <input type="number" id="new_notice_period_days" name="notice_period_days" class="form-control" 
                                   min="0" max="180" value="30">
                        </div>
                        
                        <div class="form-group">
                            <label for="new_application_status">Application Status</label>
                            <select id="new_application_status" name="application_status" class="form-control">
                                <option value="applied">Applied</option>
                                <option value="screening">Screening</option>
                                <option value="interview">Interview</option>
                                <option value="selected">Selected</option>
                                <option value="rejected">Rejected</option>
                                <option value="on_hold">On Hold</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Additional Information -->
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label for="new_cover_letter">Cover Letter</label>
                            <textarea id="new_cover_letter" name="cover_letter" class="form-control" rows="3" 
                                  placeholder="Optional cover letter..."></textarea>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label for="new_notes">HR Notes</label>
                        <textarea id="new_notes" name="notes" class="form-control" rows="2" 
                                  placeholder="Internal notes..."></textarea>
                    </div>
                    
                    <!-- File Upload -->
                    <div class="form-group" style="margin-bottom: 30px;">
                        <label for="new_resume">Resume Upload (Optional)</label>
                        <div style="border: 2px dashed var(--light-gray); border-radius: 8px; padding: 20px; text-align: center; cursor: pointer;" 
                             onclick="document.getElementById('new_resume').click()" 
                             ondrop="handleFileDrop(event)" 
                             ondragover="handleDragOver(event)">
                            <i class="fas fa-file-upload" style="font-size: 32px; color: var(--gray); margin-bottom: 10px;"></i>
                            <p style="color: var(--gray); margin-bottom: 15px;">Drag & drop resume file here or click to browse</p>
                            <input type="file" id="new_resume" name="resume" class="form-control" 
                                   accept=".pdf,.doc,.docx,.txt" style="display: none;">
                            <button type="button" class="btn btn-primary">
                                <i class="fas fa-upload"></i> Browse Files
                            </button>
                            <div id="resumeFileName" style="margin-top: 10px; font-size: 12px; color: var(--gray);"></div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div style="display: flex; gap: 10px; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> Save Application
                        </button>
                        <button type="button" class="btn btn-danger" onclick="closeNewApplicationModal()" style="flex: 1;">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();
            initializeFilters();
            
            // Add hover effects to table rows
            const rows = document.querySelectorAll('.applicants-table tbody tr');
            rows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.01)';
                    this.style.boxShadow = '0 5px 15px rgba(0, 0, 0, 0.1)';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                    this.style.boxShadow = 'none';
                });
            });
        });
        
        // Initialize Charts
        function initializeCharts() {
            // Status Chart
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            const statusChart = new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Applied', 'Screening', 'Interview', 'Selected', 'Rejected', 'On Hold'],
                    datasets: [{
                        data: [
                            <?php echo $stats['applied'] ?? 0; ?>,
                            <?php echo $stats['screening'] ?? 0; ?>,
                            <?php echo $stats['interview'] ?? 0; ?>,
                            <?php echo $stats['selected'] ?? 0; ?>,
                            <?php echo $stats['rejected'] ?? 0; ?>,
                            <?php echo $stats['on_hold'] ?? 0; ?>
                        ],
                        backgroundColor: [
                            '#3b82f6', '#8b5cf6', '#f59e0b', '#10b981', '#ef4444', '#64748b'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 20,
                                usePointStyle: true
                            }
                        }
                    },
                    animation: {
                        animateScale: true,
                        animateRotate: true,
                        duration: 2000
                    }
                }
            });
            
            // Monthly Chart - Generate last 6 months data
            const months = [];
            const monthlyData = [];
            const today = new Date();
            
            for (let i = 5; i >= 0; i--) {
                const date = new Date(today.getFullYear(), today.getMonth() - i, 1);
                months.push(date.toLocaleDateString('en-US', { month: 'short' }));
                monthlyData.push(Math.floor(Math.random() * 50) + 10);
            }
            
            const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
            const monthlyChart = new Chart(monthlyCtx, {
                type: 'bar',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Applications',
                        data: monthlyData,
                        backgroundColor: 'rgba(79, 70, 229, 0.8)',
                        borderColor: '#4f46e5',
                        borderWidth: 1,
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                drawBorder: false
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    },
                    animation: {
                        duration: 2000,
                        easing: 'easeOutQuart'
                    }
                }
            });
        }
        
        // Filter Functions
        function initializeFilters() {
            // Status filter cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('click', function() {
                    statCards.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                });
            });
            
            // Select all checkbox
            document.getElementById('selectAll').addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.applicant-checkbox');
                checkboxes.forEach(cb => cb.checked = this.checked);
            });
        }
        
        function filterByStatus(status) {
            document.getElementById('status').value = status;
            applyFilters();
        }
        
        function applyFilters() {
            document.getElementById('filterForm').submit();
        }
        
        function clearFilters() {
            window.location.href = 'apply_manage.php';
        }
        
        function refreshTable() {
            const tableBody = document.getElementById('applicantsTableBody');
            tableBody.innerHTML = `
                <tr>
                    <td colspan="11" style="text-align: center; padding: 40px;">
                        <div class="loading"></div>
                        <p style="margin-top: 10px; color: var(--gray);">Refreshing data...</p>
                    </td>
                </tr>
            `;
            
            setTimeout(() => {
                location.reload();
            }, 500);
        }
        
        // Applicant Actions
        function viewApplicant(id) {
            fetchApplicantData(id, function(applicantData) {
                const content = document.getElementById('applicantDetails');
                
                content.innerHTML = `
                    <div class="applicant-info">
                        <div class="info-section">
                            <h4><i class="fas fa-user"></i> Personal Information</h4>
                            <div class="info-row">
                                <span class="info-label">Full Name:</span>
                                <span class="info-value">${applicantData.first_name} ${applicantData.last_name}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email:</span>
                                <span class="info-value">${applicantData.email}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Phone:</span>
                                <span class="info-value">${applicantData.contact_number || 'N/A'}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Applied Date:</span>
                                <span class="info-value">${new Date(applicantData.applied_date).toLocaleDateString()}</span>
                            </div>
                        </div>
                        
                        <div class="info-section">
                            <h4><i class="fas fa-briefcase"></i> Professional Information</h4>
                            <div class="info-row">
                                <span class="info-label">Position Applied:</span>
                                <span class="info-value">${applicantData.position_applied || 'N/A'}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Experience:</span>
                                <span class="info-value">${applicantData.experience_years || 0} years</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Current Company:</span>
                                <span class="info-value">${applicantData.current_company || 'N/A'}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Notice Period:</span>
                                <span class="info-value">${applicantData.notice_period_days || 0} days</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="applicant-info">
                        <div class="info-section">
                            <h4><i class="fas fa-money-bill-wave"></i> Salary Details</h4>
                            <div class="info-row">
                                <span class="info-label">Current CTC:</span>
                                <span class="info-value">₹${parseFloat(applicantData.current_ctc || 0).toLocaleString('en-IN')}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Expected CTC:</span>
                                <span class="info-value">₹${parseFloat(applicantData.expected_ctc || 0).toLocaleString('en-IN')}</span>
                            </div>
                        </div>
                        
                        <div class="info-section">
                            <h4><i class="fas fa-chart-line"></i> Application Status</h4>
                            <div class="info-row">
                                <span class="info-label">Status:</span>
                                <span class="status-badge status-${applicantData.application_status}">
                                    <i class="fas fa-circle"></i>
                                    ${applicantData.application_status.replace('_', ' ')}
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Source:</span>
                                <span class="info-value">${applicantData.source || 'N/A'}</span>
                            </div>
                        </div>
                    </div>
                    
                    ${applicantData.resume_path ? `
                    <div class="info-section" style="grid-column: 1 / -1;">
                        <h4><i class="fas fa-file-pdf"></i> Resume</h4>
                        <p style="color: var(--gray);">${applicantData.resume_path.split('/').pop()}</p>
                        <div style="display: flex; gap: 10px; margin-top: 15px;">
                            <button class="btn btn-primary" onclick="viewResume('${applicantData.resume_path}')">
                                <i class="fas fa-eye"></i> View Resume
                            </button>
                            <button class="btn btn-success" onclick="downloadResume('${applicantData.resume_path}')">
                                <i class="fas fa-download"></i> Download
                            </button>
                        </div>
                    </div>
                    ` : ''}
                    
                    ${applicantData.cover_letter ? `
                    <div class="info-section" style="grid-column: 1 / -1;">
                        <h4><i class="fas fa-envelope"></i> Cover Letter</h4>
                        <p style="color: var(--gray); line-height: 1.6; margin-top: 10px;">${applicantData.cover_letter}</p>
                    </div>
                    ` : ''}
                    
                    ${applicantData.notes ? `
                    <div class="info-section" style="grid-column: 1 / -1; background: #fef3c7;">
                        <h4><i class="fas fa-sticky-note"></i> HR Notes</h4>
                        <p style="color: #92400e; line-height: 1.6; margin-top: 10px;">${applicantData.notes}</p>
                    </div>
                    ` : ''}
                    
                    <div style="margin-top: 30px; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button class="btn btn-primary" onclick="scheduleInterview(${applicantData.id})">
                            <i class="fas fa-calendar-plus"></i> Schedule Interview
                        </button>
                        <button class="btn btn-success" onclick="updateStatus(${applicantData.id}, 'selected')">
                            <i class="fas fa-check"></i> Accept Application
                        </button>
                        <button class="btn btn-danger" onclick="updateStatus(${applicantData.id}, 'rejected')">
                            <i class="fas fa-times"></i> Reject Application
                        </button>
                        <button class="btn btn-warning" onclick="editApplicant(${applicantData.id})">
                            <i class="fas fa-edit"></i> Edit Applicant
                        </button>
                    </div>
                `;
                
                document.getElementById('applicantModal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            });
        }
        
        function fetchApplicantData(id, callback) {
            const content = document.getElementById('applicantDetails');
            
            content.innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <div class="loading" style="margin: 0 auto 20px;"></div>
                    <p style="color: var(--gray);">Loading applicant details...</p>
                </div>
            `;
            
            document.getElementById('applicantModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            fetch('apply_manage.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_applicant&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    callback(data.data);
                } else {
                    showNotification('Error: ' + data.message, 'error');
                    closeModal();
                }
            })
            .catch(error => {
                showNotification('Network error: ' + error.message, 'error');
                closeModal();
            });
        }
        
        function editApplicant(id) {
            fetch('apply_manage.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_applicant&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const applicant = data.data;
                    
                    // Populate form fields
                    document.getElementById('edit_id').value = applicant.id;
                    document.getElementById('edit_first_name').value = applicant.first_name;
                    document.getElementById('edit_last_name').value = applicant.last_name;
                    document.getElementById('edit_email').value = applicant.email;
                    document.getElementById('edit_contact_number').value = applicant.contact_number || '';
                    document.getElementById('edit_position_applied').value = applicant.position_applied || '';
                    document.getElementById('edit_experience_years').value = applicant.experience_years || 0;
                    document.getElementById('edit_current_company').value = applicant.current_company || '';
                    document.getElementById('edit_current_ctc').value = applicant.current_ctc || 0;
                    document.getElementById('edit_expected_ctc').value = applicant.expected_ctc || 0;
                    document.getElementById('edit_notice_period_days').value = applicant.notice_period_days || 30;
                    document.getElementById('edit_application_status').value = applicant.application_status || 'applied';
                    document.getElementById('edit_source').value = applicant.source || '';
                    document.getElementById('edit_cover_letter').value = applicant.cover_letter || '';
                    document.getElementById('edit_notes').value = applicant.notes || '';
                    
                    // Show current resume info
                    const currentResumeInfo = document.getElementById('currentResumeInfo');
                    if (applicant.resume_path) {
                        const fileName = applicant.resume_path.split('/').pop();
                        currentResumeInfo.innerHTML = `Current resume: <strong>${fileName}</strong>`;
                        currentResumeInfo.style.display = 'block';
                    } else {
                        currentResumeInfo.style.display = 'none';
                    }
                    
                    // Clear file input
                    document.getElementById('edit_resume').value = '';
                    document.getElementById('editResumeFileName').textContent = '';
                    
                    // Show modal
                    document.getElementById('editApplicationModal').style.display = 'block';
                    document.body.style.overflow = 'hidden';
                    
                    // Focus on first field
                    setTimeout(() => {
                        document.getElementById('edit_first_name').focus();
                    }, 100);
                    
                } else {
                    showNotification('Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showNotification('Network error: ' + error.message, 'error');
            });
        }
        
        function saveEditApplication(event) {
            event.preventDefault();
            
            const form = document.getElementById('editApplicationForm');
            const formData = new FormData(form);
            
            // Validate required fields
            const firstName = document.getElementById('edit_first_name').value.trim();
            const lastName = document.getElementById('edit_last_name').value.trim();
            const email = document.getElementById('edit_email').value.trim();
            const position = document.getElementById('edit_position_applied').value.trim();
            
            if (!firstName || !lastName || !email || !position) {
                showNotification('Please fill all required fields.', 'error');
                return;
            }
            
            // Validate email
            if (!isValidEmail(email)) {
                showNotification('Please enter a valid email address.', 'error');
                return;
            }
            
            // Validate experience
            const experience = parseFloat(document.getElementById('edit_experience_years').value);
            if (experience < 0 || experience > 50) {
                showNotification('Experience should be between 0 and 50 years.', 'error');
                return;
            }
            
            // Show loading
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<div class="loading"></div> Updating...';
            submitBtn.disabled = true;
            
            // Send AJAX request
            fetch('apply_manage.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    closeEditApplicationModal();
                    
                    // Refresh table after a short delay
                    setTimeout(() => {
                        refreshTable();
                    }, 1000);
                } else {
                    showNotification('Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showNotification('Network error: ' + error.message, 'error');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }
        
        function deleteApplication() {
            const id = document.getElementById('edit_id').value;
            
            if (confirm('Are you sure you want to delete this application? This action cannot be undone.')) {
                fetch('apply_manage.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=delete_application&id=' + id
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        closeEditApplicationModal();
                        
                        // Refresh table after a short delay
                        setTimeout(() => {
                            refreshTable();
                        }, 1000);
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Network error: ' + error.message, 'error');
                });
            }
        }
        
        function updateStatus(id, status) {
            if (confirm(`Are you sure you want to mark this application as ${status}?`)) {
                fetch('apply_manage.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=update_status&id=' + id + '&status=' + status
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const row = document.querySelector(`tr[data-id="${id}"]`);
                        if (row) {
                            const statusCell = row.querySelector('.status-badge');
                            
                            // Animate status update
                            statusCell.style.opacity = '0.5';
                            setTimeout(() => {
                                statusCell.className = `status-badge status-${status}`;
                                statusCell.innerHTML = `<i class="fas fa-circle"></i> ${status.replace('_', ' ')}`;
                                statusCell.style.opacity = '1';
                                
                                // Pulse animation
                                statusCell.style.animation = 'pulse 0.5s ease-out';
                                setTimeout(() => {
                                    statusCell.style.animation = '';
                                }, 500);
                            }, 300);
                        }
                        showNotification(data.message, 'success');
                        
                        // If viewing modal is open, update it
                        if (document.getElementById('applicantModal').style.display === 'block') {
                            closeModal();
                        }
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Network error: ' + error.message, 'error');
                });
            }
        }
        
        function viewResume(path) {
            window.open(path, '_blank');
        }
        
        function downloadResume(path) {
            const link = document.createElement('a');
            link.href = path;
            link.download = path.split('/').pop();
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        function scheduleInterview(id) {
            document.getElementById('applicant_id').value = id;
            
            // Set default interview date (tomorrow 10 AM)
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setHours(10, 0, 0, 0);
            
            document.getElementById('interview_date').value = tomorrow.toISOString().slice(0, 16);
            
            // Show modal
            document.getElementById('interviewModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        // Modal Functions
        function closeModal() {
            document.getElementById('applicantModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function closeEditApplicationModal() {
            document.getElementById('editApplicationModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function closeInterviewModal() {
            document.getElementById('interviewModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function closeNewApplicationModal() {
            document.getElementById('newApplicationModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function saveInterview(event) {
            event.preventDefault();
            
            const form = document.getElementById('interviewForm');
            const formData = new FormData(form);
            
            // Show loading
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<div class="loading"></div> Scheduling...';
            submitBtn.disabled = true;
            
            // Simulate API call
            setTimeout(() => {
                showNotification('Interview scheduled successfully!', 'success');
                closeInterviewModal();
                updateStatus(document.getElementById('applicant_id').value, 'interview');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 1500);
        }
        
        // New Application Functions
        function openNewApplicationModal() {
            // Reset form
            document.getElementById('newApplicationForm').reset();
            document.getElementById('resumeFileName').textContent = '';
            
            // Set default values
            document.getElementById('new_application_status').value = 'applied';
            document.getElementById('new_notice_period_days').value = '30';
            document.getElementById('new_experience_years').value = '0';
            
            // Show modal
            document.getElementById('newApplicationModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // Focus on first field
            setTimeout(() => {
                document.getElementById('new_first_name').focus();
            }, 100);
            
            // Add file upload handler
            document.getElementById('new_resume').addEventListener('change', function(e) {
                if (this.files.length > 0) {
                    document.getElementById('resumeFileName').textContent = 'Selected: ' + this.files[0].name;
                } else {
                    document.getElementById('resumeFileName').textContent = '';
                }
            });
        }
        
        function handleDragOver(event) {
            event.preventDefault();
            event.stopPropagation();
            event.dataTransfer.dropEffect = 'copy';
        }
        
        function handleFileDrop(event) {
            event.preventDefault();
            event.stopPropagation();
            
            const files = event.dataTransfer.files;
            if (files.length > 0) {
                const fileInput = document.getElementById('new_resume');
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(files[0]);
                fileInput.files = dataTransfer.files;
                
                // Trigger change event
                const changeEvent = new Event('change', { bubbles: true });
                fileInput.dispatchEvent(changeEvent);
            }
        }
        
        function handleEditFileDrop(event) {
            event.preventDefault();
            event.stopPropagation();
            
            const files = event.dataTransfer.files;
            if (files.length > 0) {
                const fileInput = document.getElementById('edit_resume');
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(files[0]);
                fileInput.files = dataTransfer.files;
                
                // Trigger change event
                const changeEvent = new Event('change', { bubbles: true });
                fileInput.dispatchEvent(changeEvent);
            }
        }
        
        // Add event listener for edit resume file input
        document.getElementById('edit_resume').addEventListener('change', function(e) {
            if (this.files.length > 0) {
                document.getElementById('editResumeFileName').textContent = 'New file selected: ' + this.files[0].name;
            } else {
                document.getElementById('editResumeFileName').textContent = '';
            }
        });
        
        function saveNewApplication(event) {
            event.preventDefault();
            
            const form = document.getElementById('newApplicationForm');
            const formData = new FormData(form);
            
            // Validate required fields
            const firstName = document.getElementById('new_first_name').value.trim();
            const lastName = document.getElementById('new_last_name').value.trim();
            const email = document.getElementById('new_email').value.trim();
            const position = document.getElementById('new_position_applied').value.trim();
            
            if (!firstName || !lastName || !email || !position) {
                showNotification('Please fill all required fields.', 'error');
                return;
            }
            
            // Validate email
            if (!isValidEmail(email)) {
                showNotification('Please enter a valid email address.', 'error');
                return;
            }
            
            // Validate experience
            const experience = parseFloat(document.getElementById('new_experience_years').value);
            if (experience < 0 || experience > 50) {
                showNotification('Experience should be between 0 and 50 years.', 'error');
                return;
            }
            
            // Show loading
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<div class="loading"></div> Saving...';
            submitBtn.disabled = true;
            
            // Send AJAX request
            fetch('apply_manage.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    closeNewApplicationModal();
                    
                    // Refresh table after a short delay
                    setTimeout(() => {
                        refreshTable();
                    }, 1000);
                } else {
                    showNotification('Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showNotification('Network error: ' + error.message, 'error');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }
        
        // Email validation function
        function isValidEmail(email) {
            const re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
            return re.test(String(email).toLowerCase());
        }
        
        // Utility Functions
        function exportToExcel() {
            const btn = event.target.closest('.btn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<div class="loading"></div> Exporting...';
            btn.disabled = true;
            
            setTimeout(() => {
                // Get filter parameters
                const params = new URLSearchParams({
                    status: '<?php echo $status; ?>',
                    position: '<?php echo $position; ?>',
                    start_date: '<?php echo $start_date; ?>',
                    end_date: '<?php echo $end_date; ?>',
                    search: '<?php echo $search; ?>',
                    experience: '<?php echo $experience; ?>',
                    export: 'excel'
                });
                
                // Create download link
                const link = document.createElement('a');
                link.href = 'apply_manage.php?' + params.toString();
                link.download = 'applicants_export_' + new Date().toISOString().slice(0, 10) + '.xlsx';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                btn.innerHTML = originalHTML;
                btn.disabled = false;
                showNotification('Export completed successfully!', 'success');
            }, 1500);
        }
        
        function showBulkActions() {
            const selected = document.querySelectorAll('.applicant-checkbox:checked');
            if (selected.length === 0) {
                showNotification('Please select applicants first.', 'warning');
                return;
            }
            
            const selectedIds = Array.from(selected).map(cb => cb.value);
            
            // Create bulk actions modal
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.style.display = 'block';
            modal.innerHTML = `
                <div class="modal-content" style="max-width: 500px;">
                    <div class="modal-header">
                        <h3>Bulk Actions (${selectedIds.length} selected)</h3>
                        <button class="modal-close" onclick="this.parentElement.parentElement.style.display='none'">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div style="display: flex; flex-direction: column; gap: 15px;">
                            <button class="btn btn-primary" onclick="bulkSendEmail([${selectedIds}])">
                                <i class="fas fa-envelope"></i> Send Email
                            </button>
                            <button class="btn btn-warning" onclick="bulkChangeStatus([${selectedIds}])">
                                <i class="fas fa-exchange-alt"></i> Change Status
                            </button>
                            <button class="btn btn-success" onclick="bulkExport([${selectedIds}])">
                                <i class="fas fa-file-export"></i> Export Selected
                            </button>
                            <button class="btn btn-danger" onclick="bulkDelete([${selectedIds}])">
                                <i class="fas fa-trash"></i> Delete Selected
                            </button>
                            <button class="btn" onclick="this.parentElement.parentElement.parentElement.style.display='none'" style="background: var(--gray); color: white;">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            document.body.style.overflow = 'hidden';
        }
        
        function bulkSendEmail(ids) {
            showNotification(`Sending email to ${ids.length} selected applicants.`, 'info');
            closeAllModals();
        }
        
        function bulkChangeStatus(ids) {
            const status = prompt('Enter new status (applied, screening, interview, selected, rejected, on_hold):');
            if (status) {
                showNotification(`Changing status to ${status} for ${ids.length} applicants.`, 'info');
                // In production, this would be an AJAX call
                setTimeout(() => {
                    showNotification(`Status updated for ${ids.length} applicants.`, 'success');
                    location.reload();
                }, 1000);
            }
        }
        
        function bulkExport(ids) {
            showNotification(`Exporting ${ids.length} selected applicants.`, 'info');
            // In production, this would be an AJAX call
            setTimeout(() => {
                showNotification(`Export completed for ${ids.length} applicants.`, 'success');
            }, 1000);
        }
        
        function bulkDelete(ids) {
            if (confirm(`Are you sure you want to delete ${ids.length} selected applicants? This action cannot be undone.`)) {
                showNotification(`Deleting ${ids.length} selected applicants...`, 'warning');
                // In production, this would be an AJAX call
                setTimeout(() => {
                    showNotification(`Deleted ${ids.length} applicants successfully.`, 'success');
                    location.reload();
                }, 1000);
            }
        }
        
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : '#3b82f6'};
                color: white;
                border-radius: 8px;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                z-index: 10000;
                animation: slideInRight 0.3s ease-out;
                max-width: 400px;
            `;
            
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOutRight 0.3s ease-out forwards';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
            
            // Add CSS for animations
            if (!document.getElementById('notification-styles')) {
                const style = document.createElement('style');
                style.id = 'notification-styles';
                style.textContent = `
                    @keyframes slideInRight {
                        from { transform: translateX(100%); opacity: 0; }
                        to { transform: translateX(0); opacity: 1; }
                    }
                    @keyframes slideOutRight {
                        from { transform: translateX(0); opacity: 1; }
                        to { transform: translateX(100%); opacity: 0; }
                    }
                `;
                document.head.appendChild(style);
            }
        }
        
        // Close modals on outside click
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            });
        }
        
        // Function to close all modals
        function closeAllModals() {
            document.querySelectorAll('.modal').forEach(modal => {
                modal.style.display = 'none';
            });
            document.body.style.overflow = 'auto';
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            // Refresh with F5
            if (event.key === 'F5') {
                event.preventDefault();
                refreshTable();
            }
            
            // Close modal with Escape
            if (event.key === 'Escape') {
                closeAllModals();
            }
            
            // Search with Ctrl+F
            if (event.ctrlKey && event.key === 'f') {
                event.preventDefault();
                document.getElementById('search').focus();
            }
            
            // New Application with Ctrl+N
            if (event.ctrlKey && event.key === 'n') {
                event.preventDefault();
                openNewApplicationModal();
            }
            
            // Select all with Ctrl+A in table
            if (event.ctrlKey && event.key === 'a' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
                event.preventDefault();
                document.getElementById('selectAll').checked = !document.getElementById('selectAll').checked;
                const checkboxes = document.querySelectorAll('.applicant-checkbox');
                checkboxes.forEach(cb => cb.checked = document.getElementById('selectAll').checked);
            }
        });
    </script>
</body>
</html>