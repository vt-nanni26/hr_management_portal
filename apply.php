
<?php
// apply.php
require_once 'db_connection.php';

$error = '';
$success = '';
$job_positions = [
    'Software Developer',
    'Senior Software Engineer',
    'Frontend Developer',
    'Backend Developer',
    'Full Stack Developer',
    'DevOps Engineer',
    'Data Scientist',
    'Data Analyst',
    'UX/UI Designer',
    'Product Manager',
    'HR Manager',
    'Marketing Executive',
    'Sales Executive',
    'Business Analyst',
    'Project Manager',
    'Quality Assurance Engineer',
    'Network Administrator',
    'System Administrator',
    'Technical Support',
    'Content Writer'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize inputs
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $position_applied = trim($_POST['position_applied'] ?? '');
    $experience_years = intval($_POST['experience_years'] ?? 0);
    $current_company = trim($_POST['current_company'] ?? '');
    $current_ctc = floatval($_POST['current_ctc'] ?? 0);
    $expected_ctc = floatval($_POST['expected_ctc'] ?? 0);
    $notice_period_days = intval($_POST['notice_period_days'] ?? 0);
    $cover_letter = trim($_POST['cover_letter'] ?? '');
    $source = trim($_POST['source'] ?? 'website');
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($email) || empty($contact_number) || empty($position_applied)) {
        $error = "Please fill in all required fields!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address!";
    } else {
        // Check if user already exists
        $sql = "SELECT id FROM users WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $user_id = null;
        
        if ($result->num_rows > 0) {
            // User exists, get user_id
            $user = $result->fetch_assoc();
            $user_id = $user['id'];
        } else {
            // Create new user account for applicant
            $temp_password = password_hash(uniqid(), PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (email, password_hash, role, is_active) VALUES (?, ?, 'applicant', 1)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $email, $temp_password);
            
            if ($stmt->execute()) {
                $user_id = $stmt->insert_id;
            }
        }
        
        if ($user_id) {
            // Handle file upload
            $resume_path = null;
            if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/resumes/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_ext = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
                $file_name = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $first_name . '_' . $last_name) . '.' . $file_ext;
                $file_path = $upload_dir . $file_name;
                
                // Allowed file types
                $allowed_types = ['pdf', 'doc', 'docx', 'txt'];
                if (in_array(strtolower($file_ext), $allowed_types)) {
                    if (move_uploaded_file($_FILES['resume']['tmp_name'], $file_path)) {
                        $resume_path = $file_path;
                    }
                }
            }
            
            // Insert application into database
            $sql = "INSERT INTO applicants (
                user_id, first_name, last_name, email, contact_number, 
                position_applied, experience_years, current_company, 
                current_ctc, expected_ctc, notice_period_days, 
                application_status, applied_date, resume_path, 
                cover_letter, source
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'applied', CURDATE(), ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "isssssisddssss",
                $user_id,
                $first_name,
                $last_name,
                $email,
                $contact_number,
                $position_applied,
                $experience_years,
                $current_company,
                $current_ctc,
                $expected_ctc,
                $notice_period_days,
                $resume_path,
                $cover_letter,
                $source
            );
            
            if ($stmt->execute()) {
                $success = "Your application has been submitted successfully! We'll contact you soon.";
                // Clear form
                $_POST = [];
            } else {
                $error = "Error submitting application. Please try again.";
            }
        } else {
            $error = "Error creating user account. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for a Job - HR Management Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: #333;
            overflow-x: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 0;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: float 30s linear infinite;
        }
        
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            animation: slideInDown 0.8s ease-out;
        }
        
        .logo-icon {
            width: 50px;
            height: 50px;
            background: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .logo-icon i {
            font-size: 24px;
            color: #4f46e5;
        }
        
        .logo-text {
            font-size: 28px;
            font-weight: 700;
            font-family: 'Montserrat', sans-serif;
        }
        
        .header h1 {
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 10px;
            animation: slideInDown 1s ease-out;
        }
        
        .header p {
            font-size: 18px;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
            animation: slideInDown 1.2s ease-out;
        }
        
        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }
        
        .application-container {
            display: flex;
            gap: 40px;
            margin-bottom: 60px;
        }
        
        .application-form {
            flex: 1.2;
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
            animation: slideInLeft 0.8s ease-out;
        }
        
        .info-sidebar {
            flex: 0.8;
            animation: slideInRight 0.8s ease-out;
        }
        
        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .form-header h2 {
            font-size: 32px;
            color: #333;
            margin-bottom: 10px;
        }
        
        .form-header p {
            color: #666;
            font-size: 16px;
        }
        
        .form-group {
            margin-bottom: 25px;
            animation: fadeIn 0.5s ease-out;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #444;
            font-size: 15px;
        }
        
        .required::after {
            content: " *";
            color: #e53e3e;
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #7e22ce;
            font-size: 16px;
            z-index: 2;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 15px 15px 15px 45px;
            font-size: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }
        
        textarea {
            padding: 15px;
            min-height: 120px;
            resize: vertical;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #7e22ce;
            background: white;
            box-shadow: 0 5px 15px rgba(126, 34, 206, 0.15);
        }
        
        .file-upload {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }
        
        .file-upload-btn {
            background: linear-gradient(135deg, #4f46e5 0%, #7e22ce 100%);
            color: white;
            padding: 15px 30px;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            border: none;
            width: 100%;
            font-size: 16px;
            font-weight: 500;
        }
        
        .file-upload-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(126, 34, 206, 0.3);
        }
        
        .file-upload-btn i {
            margin-right: 10px;
            font-size: 18px;
        }
        
        .file-upload input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-name {
            margin-top: 10px;
            font-size: 14px;
            color: #666;
            display: flex;
            align-items: center;
        }
        
        .file-name i {
            margin-right: 8px;
            color: #10b981;
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            padding: 18px 40px;
            font-size: 18px;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }
        
        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
        }
        
        .submit-btn i {
            margin-right: 10px;
            font-size: 20px;
        }
        
        .alert {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            animation: slideInDown 0.5s ease-out;
            display: flex;
            align-items: center;
        }
        
        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }
        
        .alert-success {
            background: #efe;
            color: #393;
            border: 1px solid #cfc;
        }
        
        .alert i {
            margin-right: 15px;
            font-size: 24px;
        }
        
        .info-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }
        
        .info-card h3 {
            font-size: 22px;
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        
        .info-card h3 i {
            margin-right: 12px;
            color: #4f46e5;
        }
        
        .info-card p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        
        .benefits-list {
            list-style: none;
        }
        
        .benefits-list li {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            font-size: 15px;
        }
        
        .benefits-list i {
            width: 30px;
            height: 30px;
            background: #f0f9ff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: #4f46e5;
            font-size: 14px;
        }
        
        .job-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
            flex: 1;
            margin: 0 5px;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #4f46e5;
            display: block;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 13px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .footer {
            text-align: center;
            padding: 30px 0;
            color: #666;
            font-size: 14px;
            border-top: 1px solid #e2e8f0;
            background: white;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            color: #4f46e5;
            text-decoration: none;
            font-weight: 500;
            margin-top: 20px;
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            color: #7e22ce;
            transform: translateX(-5px);
        }
        
        .back-link i {
            margin-right: 8px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideInDown {
            from { 
                opacity: 0;
                transform: translateY(-30px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideInLeft {
            from { 
                opacity: 0;
                transform: translateX(-30px);
            }
            to { 
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes slideInRight {
            from { 
                opacity: 0;
                transform: translateX(30px);
            }
            to { 
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes float {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(-30px, -30px) rotate(360deg); }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        .bounce {
            animation: bounce 2s infinite;
        }
        
        @media (max-width: 1024px) {
            .application-container {
                flex-direction: column;
            }
            
            .info-sidebar {
                order: -1;
            }
        }
        
        @media (max-width: 768px) {
            .header h1 {
                font-size: 32px;
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
            
            .job-stats {
                flex-direction: column;
                gap: 10px;
            }
            
            .stat-item {
                margin: 5px 0;
            }
        }
    </style>
</head>
<body>
    <!-- Header Section -->
    <header class="header">
        <div class="logo">
            <div class="logo-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="logo-text">HR Portal</div>
        </div>
        <h1>Join Our Team</h1>
        <p>Start your career journey with us. Apply for exciting opportunities that match your skills and aspirations.</p>
    </header>
    
    <div class="container">
        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($success); ?></div>
            </div>
        <?php endif; ?>
        
        <div class="application-container">
            <!-- Info Sidebar -->
            <div class="info-sidebar">
                <div class="info-card">
                    <h3><i class="fas fa-info-circle"></i> Application Tips</h3>
                    <p>Follow these tips to make your application stand out:</p>
                    <ul class="benefits-list">
                        <li><i class="fas fa-check"></i> Tailor your resume to the job description</li>
                        <li><i class="fas fa-check"></i> Highlight relevant experience and skills</li>
                        <li><i class="fas fa-check"></i> Keep your cover letter concise and impactful</li>
                        <li><i class="fas fa-check"></i> Double-check for spelling and grammar errors</li>
                        <li><i class="fas fa-check"></i> Include quantifiable achievements</li>
                    </ul>
                </div>
                
                <div class="info-card">
                    <h3><i class="fas fa-briefcase"></i> Why Join Us?</h3>
                    <p>We offer a dynamic work environment with opportunities for growth and development.</p>
                    <ul class="benefits-list">
                        <li><i class="fas fa-star"></i> Competitive salary packages</li>
                        <li><i class="fas fa-star"></i> Flexible work arrangements</li>
                        <li><i class="fas fa-star"></i> Learning and development programs</li>
                        <li><i class="fas fa-star"></i> Health and wellness benefits</li>
                        <li><i class="fas fa-star"></i> Collaborative team culture</li>
                    </ul>
                </div>
                
                
          </div>  
            
            <!-- Application Form -->
            <div class="application-form">
                <div class="form-header">
                    <h2>Job Application Form</h2>
                    <p>Fill in your details to apply for your dream job</p>
                </div>
                
                <form method="POST" action="" enctype="multipart/form-data" id="applicationForm">
                    <!-- Personal Information -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name" class="required">First Name</label>
                            <div class="input-with-icon">
                                <i class="fas fa-user"></i>
                                <input type="text" id="first_name" name="first_name" 
                                       value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" 
                                       placeholder="Enter your first name" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name" class="required">Last Name</label>
                            <div class="input-with-icon">
                                <i class="fas fa-user"></i>
                                <input type="text" id="last_name" name="last_name" 
                                       value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" 
                                       placeholder="Enter your last name" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email" class="required">Email Address</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope"></i>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                       placeholder="Enter your email address" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="contact_number" class="required">Phone Number</label>
                            <div class="input-with-icon">
                                <i class="fas fa-phone"></i>
                                <input type="tel" id="contact_number" name="contact_number" 
                                       value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>" 
                                       placeholder="Enter your phone number" required>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Job Details -->
                    <div class="form-group">
                        <label for="position_applied" class="required">Position Applied For</label>
                        <div class="input-with-icon">
                            <i class="fas fa-briefcase"></i>
                            <select id="position_applied" name="position_applied" required>
                                <option value="">Select a position</option>
                                <?php foreach ($job_positions as $position): ?>
                                    <option value="<?php echo htmlspecialchars($position); ?>"
                                        <?php echo (isset($_POST['position_applied']) && $_POST['position_applied'] === $position) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($position); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="experience_years">Years of Experience</label>
                            <div class="input-with-icon">
                                <i class="fas fa-chart-line"></i>
                                <input type="number" id="experience_years" name="experience_years" 
                                       value="<?php echo htmlspecialchars($_POST['experience_years'] ?? ''); ?>" 
                                       placeholder="e.g., 3" min="0" max="50">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="current_company">Current Company</label>
                            <div class="input-with-icon">
                                <i class="fas fa-building"></i>
                                <input type="text" id="current_company" name="current_company" 
                                       value="<?php echo htmlspecialchars($_POST['current_company'] ?? ''); ?>" 
                                       placeholder="Your current company">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="current_ctc">Current CTC (₹)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-rupee-sign"></i>
                                <input type="number" id="current_ctc" name="current_ctc" 
                                       value="<?php echo htmlspecialchars($_POST['current_ctc'] ?? ''); ?>" 
                                       placeholder="e.g., 800000" min="0" step="1000">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="expected_ctc">Expected CTC (₹)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-rupee-sign"></i>
                                <input type="number" id="expected_ctc" name="expected_ctc" 
                                       value="<?php echo htmlspecialchars($_POST['expected_ctc'] ?? ''); ?>" 
                                       placeholder="e.g., 1000000" min="0" step="1000">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notice_period_days">Notice Period (Days)</label>
                        <div class="input-with-icon">
                            <i class="fas fa-calendar-alt"></i>
                            <input type="number" id="notice_period_days" name="notice_period_days" 
                                   value="<?php echo htmlspecialchars($_POST['notice_period_days'] ?? '30'); ?>" 
                                   placeholder="e.g., 30" min="0" max="180">
                        </div>
                    </div>
                    
                    <!-- Resume Upload -->
                    <div class="form-group">
                        <label for="resume" class="required">Upload Resume/CV</label>
                        <div class="file-upload">
                            <button type="button" class="file-upload-btn">
                                <i class="fas fa-upload"></i> Choose File
                            </button>
                            <input type="file" id="resume" name="resume" accept=".pdf,.doc,.docx,.txt" required>
                        </div>
                        <div class="file-name" id="fileName">
                            <i class="fas fa-file"></i> No file chosen
                        </div>
                    </div>
                    
                    <!-- Cover Letter -->
                    <div class="form-group">
                        <label for="cover_letter">Cover Letter</label>
                        <textarea id="cover_letter" name="cover_letter" 
                                  placeholder="Tell us why you're a great fit for this position..."><?php echo htmlspecialchars($_POST['cover_letter'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Source -->
                    <div class="form-group">
                        <label for="source">How did you hear about us?</label>
                        <div class="input-with-icon">
                            <i class="fas fa-search"></i>
                            <select id="source" name="source">
                                <option value="website" <?php echo (isset($_POST['source']) && $_POST['source'] === 'website') ? 'selected' : ''; ?>>Company Website</option>
                                <option value="linkedin" <?php echo (isset($_POST['source']) && $_POST['source'] === 'linkedin') ? 'selected' : ''; ?>>LinkedIn</option>
                                <option value="job_portal" <?php echo (isset($_POST['source']) && $_POST['source'] === 'job_portal') ? 'selected' : ''; ?>>Job Portal</option>
                                <option value="referral" <?php echo (isset($_POST['source']) && $_POST['source'] === 'referral') ? 'selected' : ''; ?>>Employee Referral</option>
                                <option value="social_media" <?php echo (isset($_POST['source']) && $_POST['source'] === 'social_media') ? 'selected' : ''; ?>>Social Media</option>
                                <option value="other" <?php echo (isset($_POST['source']) && $_POST['source'] === 'other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Submit Button -->
                    <button type="submit" class="submit-btn pulse">
                        <i class="fas fa-paper-plane"></i> Submit Application
                    </button>
                </form>
                
                <a href="login_hr.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to HR Login
                </a>
            </div>
        </div>
    </div>
    
    <footer class="footer">
        <p>© <?php echo date('Y'); ?> HR Management Portal. All rights reserved.</p>
        <p>Need help? Contact us at: careers@hrportal.com</p>
    </footer>
    
    <script>
        // File upload display
        document.getElementById('resume').addEventListener('change', function(e) {
            const fileName = this.files[0] ? this.files[0].name : 'No file chosen';
            document.getElementById('fileName').innerHTML = `<i class="fas fa-file"></i> ${fileName}`;
            
            // Add animation
            const fileIcon = document.querySelector('#fileName i');
            fileIcon.classList.add('bounce');
            setTimeout(() => fileIcon.classList.remove('bounce'), 1000);
        });
        
        // Form validation and animation
        document.getElementById('applicationForm').addEventListener('submit', function(e) {
            let isValid = true;
            const requiredFields = this.querySelectorAll('[required]');
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.style.borderColor = '#e53e3e';
                    field.style.boxShadow = '0 0 0 3px rgba(229, 62, 62, 0.1)';
                    
                    // Add shake animation
                    field.classList.add('shake');
                    setTimeout(() => field.classList.remove('shake'), 500);
                } else {
                    field.style.borderColor = '#7e22ce';
                    field.style.boxShadow = '0 5px 15px rgba(126, 34, 206, 0.15)';
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                // Show error animation
                const submitBtn = document.querySelector('.submit-btn');
                submitBtn.style.transform = 'translateX(10px)';
                setTimeout(() => submitBtn.style.transform = 'translateX(-10px)', 100);
                setTimeout(() => submitBtn.style.transform = 'translateX(0)', 200);
            } else {
                // Show success animation
                const submitBtn = document.querySelector('.submit-btn');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                submitBtn.disabled = true;
            }
        });
        
        // Add shake animation class
        const style = document.createElement('style');
        style.textContent = `
            @keyframes shake {
                0%, 100% { transform: translateX(0); }
                25% { transform: translateX(-10px); }
                75% { transform: translateX(10px); }
            }
            .shake {
                animation: shake 0.5s ease-in-out;
            }
        `;
        document.head.appendChild(style);
        
        // Input focus effects
        document.querySelectorAll('input, select, textarea').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });
        
        // Animate form groups on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);
        
        // Observe form groups
        document.querySelectorAll('.form-group').forEach((group, index) => {
            group.style.opacity = '0';
            group.style.transform = 'translateY(20px)';
            group.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            group.style.transitionDelay = `${index * 0.1}s`;
            observer.observe(group);
        });
        
        // Counter animation for stats
        function animateCounter(element, target) {
            let current = 0;
            const increment = target / 50;
            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    current = target;
                    clearInterval(timer);
                }
                element.textContent = Math.floor(current);
            }, 50);
        }
        
        // Animate stats when they come into view
        const statObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const statNumber = entry.target.querySelector('.stat-number');
                    const target = parseInt(statNumber.textContent);
                    animateCounter(statNumber, target);
                    statObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });
        
        document.querySelectorAll('.stat-item').forEach(stat => {
            statObserver.observe(stat);
        });
    </script>
</body>
</html>
[file content end]