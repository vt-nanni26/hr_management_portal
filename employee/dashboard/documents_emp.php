<?php
session_start();
require_once "../../db_connection.php";

// Check if user is logged in and is employee
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'employee') {
    header("Location: login_emp.php");
    exit();
}

// Fetch employee details
$stmt = $conn->prepare("SELECT * FROM employees WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();

// Fetch documents
$documents_stmt = $conn->prepare("
    SELECT d.*, u.email as verified_by_email 
    FROM documents d 
    LEFT JOIN users u ON d.verified_by = u.id 
    WHERE d.user_id = ? AND d.user_type = 'employee' 
    ORDER BY d.uploaded_at DESC
");
$documents_stmt->bind_param("i", $_SESSION['user_id']);
$documents_stmt->execute();
$documents_result = $documents_stmt->get_result();
$documents = [];

while ($row = $documents_result->fetch_assoc()) {
    $documents[] = $row;
}

// Handle document upload
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $document_type = $_POST['document_type'];
    $document_name = $_POST['document_name'];
    
    // File upload handling
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['document_file'];
        $file_name = basename($file['name']);
        $file_size = $file['size'];
        $file_tmp = $file['tmp_name'];
        $file_mime = mime_content_type($file_tmp);
        
        // Create uploads directory if it doesn't exist
        $upload_dir = "../../uploads/employees/" . $_SESSION['user_id'] . "/";
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Generate unique filename
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $unique_name = uniqid() . '_' . time() . '.' . $file_ext;
        $file_path = $upload_dir . $unique_name;
        
        // Allowed file types
        $allowed_types = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
        ];
        
        if (!array_key_exists($file_mime, $allowed_types)) {
            $message = "Invalid file type. Allowed types: PDF, JPG, PNG, DOC, DOCX";
            $message_type = "error";
        } elseif ($file_size > 10 * 1024 * 1024) { // 10MB limit
            $message = "File size too large. Maximum size is 10MB";
            $message_type = "error";
        } elseif (move_uploaded_file($file_tmp, $file_path)) {
            // Insert document record
            $insert_stmt = $conn->prepare("
                INSERT INTO documents 
                (user_id, user_type, document_type, document_name, file_path, file_size, mime_type, uploaded_at)
                VALUES (?, 'employee', ?, ?, ?, ?, ?, NOW())
            ");
            $insert_stmt->bind_param("isssis", $_SESSION['user_id'], $document_type, $document_name, $file_path, $file_size, $file_mime);
            
            if ($insert_stmt->execute()) {
                $message = "Document uploaded successfully!";
                $message_type = "success";
                
                // Refresh documents list
                $documents_stmt->execute();
                $documents_result = $documents_stmt->get_result();
                $documents = [];
                while ($row = $documents_result->fetch_assoc()) {
                    $documents[] = $row;
                }
            } else {
                $message = "Error uploading document. Please try again.";
                $message_type = "error";
                // Remove uploaded file
                unlink($file_path);
            }
        } else {
            $message = "Error uploading file. Please try again.";
            $message_type = "error";
        }
    } else {
        $message = "Please select a file to upload.";
        $message_type = "error";
    }
}

// Document type options
$document_types = [
    'aadhar' => 'Aadhar Card',
    'pan' => 'PAN Card',
    'resume' => 'Resume',
    'passbook' => 'Bank Passbook',
    'offer_letter' => 'Offer Letter',
    'experience_letter' => 'Experience Letter',
    'degree_certificate' => 'Degree Certificate',
    'other' => 'Other'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents - HR Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
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
        }

        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
        }

        /* Message Display */
        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .message.success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        /* Form Styles */
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
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-input-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 15px;
            background: #f9fafb;
            border: 2px dashed #e5e7eb;
            border-radius: 10px;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-input-label:hover {
            border-color: var(--primary);
            background: rgba(102, 126, 234, 0.05);
        }

        .file-input-label.has-file {
            border-color: var(--success);
            color: var(--success);
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        /* Document List */
        .document-list {
            margin-top: 20px;
        }

        .document-item {
            background: #f9fafb;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
        }

        .document-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .document-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
        }

        .document-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .icon-aadhar {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        }

        .icon-pan {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .icon-resume {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .icon-passbook {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }

        .icon-letter {
            background: linear-gradient(135deg, #ec4899, #be185d);
        }

        .icon-certificate {
            background: linear-gradient(135deg, #06b6d4, #0891b2);
        }

        .icon-other {
            background: linear-gradient(135deg, #6b7280, #4b5563);
        }

        .document-details h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .document-details p {
            font-size: 12px;
            color: var(--gray);
        }

        .document-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 5px;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-verified {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .document-actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            color: white;
        }

        .btn-view {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        }

        .btn-download {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .btn-delete {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
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
        }

        /* Required Documents */
        .required-docs {
            margin-top: 30px;
        }

        .required-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
        }

        .required-item:last-child {
            border-bottom: none;
        }

        .check-icon {
            color: var(--success);
        }

        .cross-icon {
            color: var(--danger);
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
            
            .document-item {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }
            
            .document-status {
                align-items: stretch;
            }
            
            .document-actions {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
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
            <li><a href="payroll_emp.php"><i class="fas fa-file-invoice-dollar"></i> Payroll</a></li>
            <li><a href="leave_emp.php"><i class="fas fa-plane"></i> Leave</a></li>
            <li><a href="documents_emp.php" class="active"><i class="fas fa-file-alt"></i> Documents</a></li>
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
                <h1>Document Management</h1>
                <p>Upload and manage your documents</p>
            </div>
            
            <div class="user-info">
                <img src="<?php echo !empty($employee['profile_picture']) ? htmlspecialchars($employee['profile_picture']) : 'https://ui-avatars.com/api/?name=' . urlencode(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')) . '&background=667eea&color=fff'; ?>" 
                     alt="Profile" class="profile-img">
                <div>
                    <h4><?php echo htmlspecialchars(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')); ?></h4>
                    <p style="color: var(--gray); font-size: 14px;"><?php echo htmlspecialchars($employee['designation'] ?? 'Employee'); ?></p>
                </div>
            </div>
        </div>

        <!-- Message Display -->
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="content">
            <!-- Upload Document Card -->
            <div class="card">
                <h3 class="card-title">Upload New Document</h3>
                
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="document_type">Document Type</label>
                        <select id="document_type" name="document_type" class="form-control" required>
                            <option value="">Select Document Type</option>
                            <?php foreach ($document_types as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="document_name">Document Name</label>
                        <input type="text" id="document_name" name="document_name" class="form-control" 
                               placeholder="Enter document name" required>
                    </div>

                    <div class="form-group">
                        <label>Upload File</label>
                        <div class="file-input-wrapper">
                            <input type="file" id="document_file" name="document_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                            <label for="document_file" class="file-input-label" id="fileLabel">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <span>Choose a file (PDF, JPG, PNG, DOC, DOCX, max 10MB)</span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" name="upload_document" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-upload"></i> Upload Document
                    </button>
                </form>

                <!-- Required Documents -->
                <div class="required-docs">
                    <h4 style="color: var(--dark); margin-bottom: 15px;">Required Documents</h4>
                    <?php
                    $required_docs = ['aadhar', 'pan', 'passbook', 'resume'];
                    $uploaded_types = array_column($documents, 'document_type');
                    
                    foreach ($required_docs as $doc_type):
                        $is_uploaded = in_array($doc_type, $uploaded_types);
                    ?>
                        <div class="required-item">
                            <i class="fas fa-<?php echo $is_uploaded ? 'check-circle check-icon' : 'times-circle cross-icon'; ?>"></i>
                            <span><?php echo htmlspecialchars($document_types[$doc_type] ?? ucfirst($doc_type)); ?></span>
                            <?php if (!$is_uploaded): ?>
                                <span style="color: var(--danger); font-size: 12px; margin-left: auto;">Required</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Document List Card -->
            <div class="card">
                <h3 class="card-title">Uploaded Documents</h3>
                
                <?php if (count($documents) > 0): ?>
                    <div class="document-list">
                        <?php foreach ($documents as $doc): 
                            $icon_class = 'icon-' . ($doc['document_type'] ?? 'other');
                            $type_label = $document_types[$doc['document_type']] ?? ucfirst($doc['document_type'] ?? 'Other');
                        ?>
                            <div class="document-item">
                                <div class="document-info">
                                    <div class="document-icon <?php echo $icon_class; ?>">
                                        <?php 
                                        $icons = [
                                            'aadhar' => 'fas fa-id-card',
                                            'pan' => 'fas fa-file-invoice',
                                            'resume' => 'fas fa-file-alt',
                                            'passbook' => 'fas fa-university',
                                            'offer_letter' => 'fas fa-envelope-open-text',
                                            'experience_letter' => 'fas fa-briefcase',
                                            'degree_certificate' => 'fas fa-graduation-cap',
                                            'other' => 'fas fa-file'
                                        ];
                                        $icon = $icons[$doc['document_type'] ?? 'other'] ?? 'fas fa-file';
                                        ?>
                                        <i class="<?php echo $icon; ?>"></i>
                                    </div>
                                    <div class="document-details">
                                        <h4><?php echo htmlspecialchars($doc['document_name'] ?? 'Untitled Document'); ?></h4>
                                        <p><?php echo htmlspecialchars($type_label); ?></p>
                                        <p>
                                            <small>
                                                Uploaded: <?php echo date('d-m-Y', strtotime($doc['uploaded_at'] ?? date('Y-m-d'))); ?>
                                                • <?php echo round(($doc['file_size'] ?? 0) / 1024 / 1024, 2); ?> MB
                                            </small>
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="document-status">
                                    <span class="status-badge <?php echo ($doc['is_verified'] ?? 0) ? 'badge-verified' : 'badge-pending'; ?>">
                                        <?php echo ($doc['is_verified'] ?? 0) ? 'Verified' : 'Pending'; ?>
                                    </span>
                                    <?php if (($doc['is_verified'] ?? 0) && !empty($doc['verified_by_email'])): ?>
                                        <small style="color: var(--gray); font-size: 11px;">
                                            By: <?php echo htmlspecialchars($doc['verified_by_email']); ?>
                                        </small>
                                    <?php endif; ?>
                                    
                                    <div class="document-actions">
                                        <a href="<?php echo htmlspecialchars($doc['file_path'] ?? '#'); ?>" 
                                           target="_blank" class="action-btn btn-view" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="<?php echo htmlspecialchars($doc['file_path'] ?? '#'); ?>" 
                                           download class="action-btn btn-download" title="Download">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>No documents uploaded yet</p>
                        <p style="font-size: 14px; margin-top: 10px;">Upload your first document using the form</p>
                    </div>
                <?php endif; ?>
            </div>
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
        
        // File input label update
        document.getElementById('document_file').addEventListener('change', function(e) {
            const fileLabel = document.getElementById('fileLabel');
            if (this.files.length > 0) {
                const fileName = this.files[0].name;
                fileLabel.querySelector('span').textContent = fileName;
                fileLabel.classList.add('has-file');
            } else {
                fileLabel.querySelector('span').textContent = 'Choose a file (PDF, JPG, PNG, DOC, DOCX, max 10MB)';
                fileLabel.classList.remove('has-file');
            }
        });
    </script>
</body>
</html>