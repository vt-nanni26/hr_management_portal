<?php
session_start();
require_once 'db_connection.php'; // Database connection

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    
    // Validate credentials
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND role = 'employee' AND is_active = 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        // Log login attempt
        $login_stmt = $conn->prepare("INSERT INTO login_attempts (email, success, ip_address, user_agent, attempt_time) VALUES (?, 1, ?, ?, UNIX_TIMESTAMP())");
        $login_stmt->bind_param("sss", $email, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        $login_stmt->execute();
        
        // Update last login
        $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $update_stmt->bind_param("i", $user['id']);
        $update_stmt->execute();
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        
        // Redirect to dashboard
        header("Location: employee/dashboard/employee_dashboard.php");
        exit();
    } else {
        // Log failed attempt
        $failed_stmt = $conn->prepare("INSERT INTO login_attempts (email, success, ip_address, user_agent, attempt_time) VALUES (?, 0, ?, ?, UNIX_TIMESTAMP())");
        $failed_stmt->bind_param("sss", $email, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        $failed_stmt->execute();
        
        $error = "Invalid email or password!";
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="HR Portal Employee Login - Access your HR dashboard securely">
    <title>Employee Login - HR Portal</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --primary-light: #7c8de0;
            --secondary-color: #764ba2;
            --light-bg: rgba(255, 255, 255, 0.1);
            --glass-effect: rgba(255, 255, 255, 0.15);
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--primary-gradient);
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Animated Background */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 0.6;
        }
        
        .bg-circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            animation: float 15s infinite linear;
        }
        
        .bg-circle:nth-child(1) {
            width: 80px;
            height: 80px;
            top: 10%;
            left: 10%;
            animation-delay: 0s;
        }
        
        .bg-circle:nth-child(2) {
            width: 120px;
            height: 120px;
            top: 60%;
            right: 10%;
            animation-delay: -5s;
        }
        
        .bg-circle:nth-child(3) {
            width: 60px;
            height: 60px;
            bottom: 20%;
            left: 20%;
            animation-delay: -10s;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
            }
            33% {
                transform: translateY(-30px) rotate(120deg);
            }
            66% {
                transform: translateY(15px) rotate(240deg);
            }
        }
        
        /* Glass Effect Login Card */
        .glass-card {
            background: var(--glass-effect);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.1);
        }
        
        .login-container {
            animation: fadeInUp 0.8s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logo-section {
            transition: transform 0.3s ease;
        }
        
        .logo-section:hover {
            transform: translateY(-5px);
        }
        
        .form-control-glass {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding-left: 45px;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .form-control-glass:focus {
            background: rgba(255, 255, 255, 0.15);
            border-color: white;
            box-shadow: 0 0 0 0.25rem rgba(255, 255, 255, 0.25);
            color: white;
        }
        
        .form-control-glass::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }
        
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.7);
            transition: color 0.3s ease;
            z-index: 5;
        }
        
        .form-control-glass:focus + .input-icon {
            color: white;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
            z-index: 5;
        }
        
        .password-toggle:hover {
            color: white;
        }
        
        .btn-gradient {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            color: white;
            border-radius: 12px;
            padding: 12px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }
        
        .btn-gradient:active {
            transform: translateY(0);
        }
        
        .btn-gradient::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: 0.5s;
        }
        
        .btn-gradient:hover::after {
            left: 100%;
        }
        
        .footer-link {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: color 0.3s ease;
            font-size: 0.9rem;
        }
        
        .footer-link:hover {
            color: white;
            text-decoration: underline;
        }
        
        .error-toast {
            animation: slideInRight 0.5s ease-out;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(255, 255, 255, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(255, 255, 255, 0);
            }
        }
        
        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .glass-card {
                margin: 1rem;
                padding: 1.5rem !important;
            }
            
            .login-container {
                padding: 1rem !important;
            }
        }
        
        @media (max-height: 600px) {
            .login-container {
                padding-top: 1rem !important;
                padding-bottom: 1rem !important;
            }
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center h-100">
    <!-- Animated Background -->
    <div class="bg-animation">
        <div class="bg-circle"></div>
        <div class="bg-circle"></div>
        <div class="bg-circle"></div>
    </div>
    
    <!-- Toast Container for Notifications -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1050;">
        <?php if (isset($error)): ?>
        <div class="toast error-toast show" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="5000">
            <div class="toast-header bg-danger text-white">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong class="me-auto">Login Failed</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body bg-light">
                <i class="bi bi-x-circle-fill text-danger me-2"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Main Login Container -->
    <div class="container login-container">
        <div class="row justify-content-center align-items-center">
            <div class="col-12 col-md-8 col-lg-6 col-xl-5">
                <div class="glass-card p-4 p-md-5">
                    <!-- Logo Section -->
                    <div class="logo-section text-center mb-4">
                        <div class="mb-3">
                            <i class="fas fa-user-tie display-4 text-white mb-3 pulse" style="font-size: 3.5rem;"></i>
                        </div>
                        <h1 class="text-white fw-bold mb-2">
                            HR<span class="text-warning">Portal</span>
                        </h1>
                        <p class="text-white-50 mb-4">Employee Access Portal</p>
                    </div>
                    
                    <!-- Login Form -->
                    <div class="login-form">
                        <h2 class="text-white text-center mb-3 fw-semibold">Employee Login</h2>
                        <p class="text-white-50 text-center mb-4">
                            <small>Access your HR dashboard and manage your information securely</small>
                        </p>
                        
                        <form method="POST" action="" id="loginForm" novalidate>
                            <!-- Email Input -->
                            <div class="mb-4 position-relative">
                                <i class="bi bi-envelope-fill input-icon"></i>
                                <input 
                                    type="email" 
                                    class="form-control form-control-glass py-3" 
                                    name="email" 
                                    placeholder="Enter your company email" 
                                    required
                                    aria-label="Email address"
                                    aria-describedby="emailHelp"
                                    autocomplete="email"
                                    autofocus
                                >
                                <div id="emailHelp" class="form-text text-white-50 mt-1">
                                    Use your company registered email
                                </div>
                            </div>
                            
                            <!-- Password Input -->
                            <div class="mb-4 position-relative">
                                <i class="bi bi-lock-fill input-icon"></i>
                                <input 
                                    type="password" 
                                    class="form-control form-control-glass py-3 password-field" 
                                    name="password" 
                                    placeholder="Enter your password" 
                                    required
                                    aria-label="Password"
                                    autocomplete="current-password"
                                    id="passwordInput"
                                >
                                <button 
                                    type="button" 
                                    class="password-toggle" 
                                    id="togglePassword"
                                    aria-label="Toggle password visibility"
                                >
                                    <i class="bi bi-eye-fill"></i>
                                </button>
                                <div class="d-flex justify-content-end mt-1">
                                    <a href="forgot_password.php" class="footer-link small">
                                        <i class="bi bi-key me-1"></i> Forgot Password?
                                    </a>
                                </div>
                            </div>
                            
                            <!-- Remember Me & Submit -->
                            <div class="mb-4">
                                <div class="form-check">
                                    <input 
                                        class="form-check-input" 
                                        type="checkbox" 
                                        name="remember" 
                                        id="rememberMe"
                                        aria-label="Remember me on this device"
                                    >
                                    <label class="form-check-label text-white-50" for="rememberMe">
                                        <i class="bi bi-check2-circle me-1"></i> Remember me
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Submit Button -->
                            <div class="d-grid mb-4">
                                <button 
                                    type="submit" 
                                    class="btn btn-gradient py-3 fw-semibold"
                                    id="loginButton"
                                >
                                    <i class="bi bi-box-arrow-in-right me-2"></i> 
                                    <span class="login-text">Login to Dashboard</span>
                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                </button>
                            </div>
                            
                            <!-- Help Links -->
                            <div class="text-center mb-3">
                                <p class="text-white-50 mb-2">
                                    <small>Need assistance logging in?</small>
                                </p>
                                <div class="d-flex justify-content-center gap-3">
                                    <a href="#" class="footer-link" aria-label="Help center">
                                        <i class="bi bi-question-circle me-1"></i> Help
                                    </a>
                                    <a href="#" class="footer-link" aria-label="Contact support">
                                        <i class="bi bi-headset me-1"></i> Support
                                    </a>
                                    <a href="#" class="footer-link" aria-label="Privacy policy">
                                        <i class="bi bi-shield-check me-1"></i> Privacy
                                    </a>
                                </div>
                            </div>
                            
                            <!-- Security Notice -->
                            <div class="alert alert-info bg-transparent border-info text-white-50 mt-3" role="alert">
                                <div class="d-flex">
                                    <i class="bi bi-info-circle-fill me-2 fs-5"></i>
                                    <div>
                                        <small>
                                            <strong>Secure Login:</strong> Your credentials are encrypted and protected.
                                            Never share your password with anyone.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="text-center mt-4">
                    <p class="text-white-50 small">
                        &copy; <?php echo date('Y'); ?> HR Portal v2.0
                        <span class="mx-2">•</span>
                        Employee Access System
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Loading Overlay (Hidden by default) -->
    <div class="modal fade" id="loadingModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-transparent border-0">
                <div class="modal-body text-center">
                    <div class="spinner-border text-light" style="width: 3rem; height: 3rem;" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-white mt-3">Authenticating... Please wait</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password visibility toggle
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('passwordInput');
            
            if (togglePassword && passwordInput) {
                togglePassword.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    this.innerHTML = type === 'password' ? 
                        '<i class="bi bi-eye-fill"></i>' : 
                        '<i class="bi bi-eye-slash-fill"></i>';
                    this.setAttribute('aria-label', 
                        type === 'password' ? 'Show password' : 'Hide password'
                    );
                });
            }
            
            // Form submission handling
            const loginForm = document.getElementById('loginForm');
            const loginButton = document.getElementById('loginButton');
            const loginText = document.querySelector('.login-text');
            const spinner = loginButton.querySelector('.spinner-border');
            
            if (loginForm) {
                loginForm.addEventListener('submit', function(e) {
                    // Show loading state
                    loginText.classList.add('d-none');
                    spinner.classList.remove('d-none');
                    loginButton.disabled = true;
                    
                    // Show loading modal
                    const loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'));
                    loadingModal.show();
                });
            }
            
            // Auto-hide error toast after 5 seconds
            const errorToast = document.querySelector('.error-toast');
            if (errorToast) {
                setTimeout(() => {
                    const toast = new bootstrap.Toast(errorToast);
                    toast.hide();
                }, 5000);
            }
            
            // Form validation feedback
            const inputs = document.querySelectorAll('.form-control-glass');
            inputs.forEach(input => {
                input.addEventListener('blur', function() {
                    if (this.value.trim() === '') {
                        this.classList.add('is-invalid');
                    } else {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    }
                });
                
                input.addEventListener('input', function() {
                    if (this.value.trim() !== '') {
                        this.classList.remove('is-invalid');
                    }
                });
            });
            
            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+Enter to submit form
                if (e.ctrlKey && e.key === 'Enter') {
                    if (loginForm) {
                        loginForm.requestSubmit();
                    }
                }
                
                // Escape to clear form
                if (e.key === 'Escape') {
                    loginForm.reset();
                    inputs.forEach(input => {
                        input.classList.remove('is-valid', 'is-invalid');
                    });
                }
            });
            
            // Focus management for accessibility
            if (document.activeElement.tagName !== 'INPUT') {
                const firstInput = document.querySelector('input[type="email"]');
                if (firstInput) {
                    firstInput.focus();
                }
            }
        });
        
        // Add visual feedback for form interactions
        document.querySelectorAll('.form-control-glass').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'translateY(-2px)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>