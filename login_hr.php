<?php
// login_hr.php
require_once 'db_connection.php';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (!empty($email) && !empty($password)) {
        // Check if user exists and is HR
        $sql = "SELECT * FROM users WHERE email = ? AND role = 'hr' AND is_active = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password_hash'])) {
                // Update last login
                $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("i", $user['id']);
                $update_stmt->execute();
                
                // Set session
                $_SESSION['hr_logged_in'] = true;
                $_SESSION['hr_id'] = $user['id'];
                $_SESSION['hr_email'] = $user['email'];
                $_SESSION['hr_role'] = $user['role'];
                
                // Redirect to dashboard
                header("Location: hr/dashboard/hr_dashboard.php");
                exit();
            } else {
                $error = "Invalid password!";
            }
        } else {
            $error = "HR account not found or inactive!";
        }
    } else {
        $error = "Please fill in all fields!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Login - HR Management Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            overflow-x: hidden;
        }
        
        .login-container {
            display: flex;
            width: 100%;
            max-width: 1100px;
            min-height: 650px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 25px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: fadeIn 0.8s ease-out;
        }
        
        .login-left {
            flex: 1;
            background: linear-gradient(135deg, #4f46e5 0%, #7e22ce 100%);
            color: white;
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        .login-left::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 30px 30px;
            animation: float 20s linear infinite;
        }
        
        .login-right {
            flex: 1.2;
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            margin-bottom: 40px;
            animation: slideInLeft 0.8s ease-out;
        }
        
        .logo-icon {
            width: 60px;
            height: 60px;
            background: white;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .logo-icon i {
            font-size: 28px;
            color: #4f46e5;
        }
        
        .logo-text {
            font-size: 24px;
            font-weight: 700;
        }
        
        .welcome-text {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 15px;
            line-height: 1.2;
        }
        
        .subtitle {
            font-size: 16px;
            opacity: 0.9;
            line-height: 1.6;
            margin-bottom: 40px;
        }
        
        .features {
            list-style: none;
            margin-top: 30px;
        }
        
        .features li {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            font-size: 15px;
            animation: slideInLeft 1s ease-out;
            animation-fill-mode: both;
        }
        
        .features li:nth-child(1) { animation-delay: 0.2s; }
        .features li:nth-child(2) { animation-delay: 0.4s; }
        .features li:nth-child(3) { animation-delay: 0.6s; }
        
        .features i {
            width: 36px;
            height: 36px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 16px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 40px;
            animation: slideInRight 0.8s ease-out;
        }
        
        .login-header h1 {
            font-size: 32px;
            color: #333;
            margin-bottom: 10px;
        }
        
        .login-header p {
            color: #666;
            font-size: 16px;
        }
        
        .form-group {
            margin-bottom: 25px;
            animation: slideInRight 1s ease-out;
            animation-fill-mode: both;
        }
        
        .form-group:nth-child(1) { animation-delay: 0.2s; }
        .form-group:nth-child(2) { animation-delay: 0.4s; }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-with-icon i {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: #7e22ce;
            font-size: 18px;
            z-index: 2;
        }
        
        .form-control {
            width: 100%;
            padding: 18px 20px 18px 55px;
            font-size: 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #7e22ce;
            background: white;
            box-shadow: 0 5px 15px rgba(126, 34, 206, 0.1);
        }
        
        .login-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #4f46e5 0%, #7e22ce 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(126, 34, 206, 0.3);
            margin-top: 10px;
            animation: slideInRight 1.2s ease-out;
        }
        
        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(126, 34, 206, 0.4);
        }
        
        .login-btn:active {
            transform: translateY(0);
        }
        
        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 25px;
            animation: slideInDown 0.5s ease-out;
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
        
        .hr-info {
            background: #f0f9ff;
            border: 1px solid #e0f2fe;
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
            animation: slideInUp 1s ease-out;
        }
        
        .hr-info h3 {
            color: #0369a1;
            margin-bottom: 10px;
            font-size: 18px;
            display: flex;
            align-items: center;
        }
        
        .hr-info h3 i {
            margin-right: 10px;
        }
        
        .hr-info p {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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
        
        @keyframes float {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(-30px, -30px) rotate(360deg); }
        }
        
        @media (max-width: 900px) {
            .login-container {
                flex-direction: column;
                max-width: 500px;
            }
            
            .login-left, .login-right {
                padding: 40px 30px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Left Panel -->
        <div class="login-left">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="logo-text">HR Portal</div>
            </div>
            
            <h1 class="welcome-text">Welcome to HR Management System</h1>
            <p class="subtitle">Streamline your human resources processes with our comprehensive management portal designed for efficiency and productivity.</p>
            
            <ul class="features">
                <li><i class="fas fa-check-circle"></i> Manage employee records & attendance</li>
                <li><i class="fas fa-check-circle"></i> Process payroll & generate reports</li>
                <li><i class="fas fa-check-circle"></i> Track applicants & schedule interviews</li>
            </ul>
        </div>
        
        <!-- Right Panel -->
        <div class="login-right">
            <div class="login-header">
                <h1>HR Login</h1>
                <p>Enter your credentials to access the HR dashboard</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($hr_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($hr_message); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <div class="input-with-icon">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" class="form-control" placeholder="HR Email Address" value="hr@hrportal.com" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" class="form-control" placeholder="Password" required>
                    </div>
                </div>
                
                <button type="submit" class="login-btn">
                    <i class="fas fa-sign-in-alt"></i> Login to Dashboard
                </button>
            </form>
            
           
        </div>
    </div>
    
    <script>
        // Add some interactive animations
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.parentElement.style.transform = 'scale(1)';
            });
        });
        
        // Add typing effect for welcome text
        document.addEventListener('DOMContentLoaded', function() {
            const welcomeText = document.querySelector('.welcome-text');
            const originalText = welcomeText.textContent;
            welcomeText.textContent = '';
            
            let i = 0;
            function typeWriter() {
                if (i < originalText.length) {
                    welcomeText.textContent += originalText.charAt(i);
                    i++;
                    setTimeout(typeWriter, 50);
                }
            }
            
            setTimeout(typeWriter, 1000);
        });
    </script>
</body>
</html>