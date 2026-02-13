<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asdn cybermetics(hr portal)– Where People Meet Performance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary-color: #4f46e5;
            --secondary-color: #7e22ce;
            --accent-color: #f59e0b;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --dark-color: #1e293b;
            --light-color: #f8fafc;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: var(--light-color);
            color: var(--dark-color);
            overflow-x: hidden;
        }
        
        /* Navigation */
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 1.5rem 0;
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .navbar.scrolled {
            padding: 1rem 0;
            background: rgba(255, 255, 255, 0.98);
        }
        
        .navbar-brand {
            font-size: 1.8rem;
            font-weight: 800;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            text-decoration: none;
        }
        
        .navbar-brand:hover {
            color: transparent;
        }
        
        .nav-link {
            font-weight: 500;
            color: var(--dark-color) !important;
            margin: 0 0.5rem;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .nav-link:hover {
            color: var(--primary-color) !important;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary-color);
            transition: width 0.3s ease;
        }
        
        .nav-link:hover::after {
            width: 100%;
        }
        
        .nav-btn {
            padding: 0.5rem 1.5rem !important;
            border-radius: 8px;
            border: none;
            transition: all 0.3s ease !important;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .login-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white !important;
        }
        
        /* .apply-btn {
            background: linear-gradient(135deg, var(--success-color), #059669);
            color: white !important;
        } */
        
        .nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            color: white !important;
        }
        
        /* Hero Section */
        .hero-section {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding-top: 100px;
            position: relative;
            overflow: hidden;
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
        }
        
        .hero-title {
            font-size: 4rem;
            font-weight: 900;
            line-height: 1.1;
            margin-bottom: 1.5rem;
            color: white;
        }
        
        .hero-title span {
            background: linear-gradient(to right, #ffd700, #ffab00);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .hero-subtitle {
            font-size: 1.2rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2rem;
            max-width: 600px;
        }
        
        .hero-buttons {
            display: flex;
            gap: 1rem;
            margin-bottom: 3rem;
            flex-wrap: wrap;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #ffd700, #ffab00);
            border: none;
            padding: 1rem 2rem;
            font-weight: 600;
            border-radius: 10px;
            color: #1e293b;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(255, 215, 0, 0.3);
            color: #1e293b;
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 1rem 2rem;
            font-weight: 600;
            border-radius: 10px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-3px);
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            padding: 1rem 2rem;
            font-weight: 600;
            border-radius: 10px;
            color: white;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            animation: pulse 2s infinite;
        }
        
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(16, 185, 129, 0.3);
            color: white;
        }
        
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            }
        }
        
        /* Animated Background */
        .bg-animation {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
        }
        
        .bg-circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            animation: float 20s infinite linear;
        }
        
        .bg-circle:nth-child(1) {
            width: 300px;
            height: 300px;
            top: 10%;
            left: 5%;
            animation-delay: 0s;
        }
        
        .bg-circle:nth-child(2) {
            width: 200px;
            height: 200px;
            top: 60%;
            right: 10%;
            animation-delay: -5s;
        }
        
        .bg-circle:nth-child(3) {
            width: 150px;
            height: 150px;
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
        
        /* Features Section */
        .features-section {
            padding: 100px 0;
            background: white;
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 3rem;
        }
        
        .section-title h2 {
            font-size: 3rem;
            font-weight: 800;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 1rem;
        }
        
        .feature-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            height: 100%;
            border: 1px solid #f1f5f9;
            text-align: center;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12);
        }
        
        .feature-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-size: 1.8rem;
        }
        
        
        
        /* Portal Access Section */
        .portal-section {
            padding: 100px 0;
            background: var(--light-color);
        }
        
        .portal-card {
            background: white;
            border-radius: 20px;
            padding: 3rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }
        
        .portal-title {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .portal-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
        }
        
        .portal-option {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 15px;
            padding: 2.5rem;
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .portal-option::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transform: translateX(-100%);
            transition: transform 0.6s;
        }
        
        .portal-option:hover::before {
            transform: translateX(100%);
        }
        
        .portal-option:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(79, 70, 229, 0.3);
        }
        
        .portal-option.hr {
            background: linear-gradient(135deg, #4f46e5 0%, #7e22ce 100%);
        }
        
        .portal-option.employee {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .portal-icon {
            font-size: 3rem;
            margin-bottom: 1.5rem;
            color: #ffd700;
        }
        
        /* Job Application CTA */
        .application-cta {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 80px 0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .application-cta::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
        }
        
        .cta-content {
            position: relative;
            z-index: 2;
        }
        
        .cta-title {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
        }
        
        .cta-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 2rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Footer */
        .footer {
            background: var(--dark-color);
            color: white;
            padding: 60px 0 30px;
        }
        
        .footer-logo {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(to right, #ffd700, #ffab00);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 1rem;
            text-decoration: none;
            display: inline-block;
        }
        
        .footer-links a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: color 0.3s ease;
            display: block;
            margin-bottom: 0.5rem;
        }
        
        .footer-links a:hover {
            color: #ffd700;
        }
        
        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .social-link {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .social-link:hover {
            background: #ffd700;
            color: var(--dark-color);
            transform: translateY(-3px);
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-up {
            animation: fadeInUp 0.6s ease-out;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .hero-title {
                font-size: 3rem;
            }
            
            .section-title h2 {
                font-size: 2.5rem;
            }
            
            .portal-options {
                grid-template-columns: 1fr;
            }
            
            .hero-buttons {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .cta-title {
                font-size: 2.5rem;
            }
        }
        
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }
            
            .section-title h2 {
                font-size: 2rem;
            }
            
            .stat-number {
                font-size: 2.5rem;
            }
            
            .cta-title {
                font-size: 2rem;
            }
            
            .navbar-nav {
                text-align: center;
                padding-top: 1rem;
            }
            
            .nav-btn {
                width: 100%;
                margin: 0.5rem 0;
            }
        }
        
        @media (max-width: 576px) {
            .hero-title {
                font-size: 2rem;
            }
            
            .portal-card {
                padding: 2rem 1.5rem;
            }
            
            .cta-title {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-handshake me-2"></i>
                HR Portal
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <i class="fas fa-bars"></i>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link" href="#home">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#features">Features</a>
                    </li>
                    
                    <li class="nav-item">
                        <a class="nav-link" href="#portal">Portals</a>
                    </li>
                    <!-- <li class="nav-item">
                        <a class="nav-link" href="#apply">Apply Now</a>
                    </li> -->
                    <li class="nav-item">
                        <a class="nav-link" href="#contact">Contact</a>
                    </li>
                    <!-- <li class="nav-item">
                        <a href="apply.php" class="nav-link apply-btn nav-btn">
                            <i class="fas fa-briefcase"></i>
                            Apply for Job
                        </a>
                    </li> -->
                    <li class="nav-item ms-2">
                        <a href="#portal" class="nav-link login-btn nav-btn">
                            <i class="fas fa-user-tie"></i>
                            Login
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section" id="home">
        <div class="bg-animation">
            <div class="bg-circle"></div>
            <div class="bg-circle"></div>
            <div class="bg-circle"></div>
        </div>
        
        <div class="container">
            <div class="row align-items-center hero-content">
                <div class="col-lg-6 animate-up">
                    <h1 class="hero-title">
                        Transform Your <span>Workforce</span> Into A <span>Powerforce</span>
                    </h1>
                    <p class="hero-subtitle">
                        hr portal bridges the gap between talent and productivity, creating a seamless environment 
                        where employees thrive and organizations excel. Experience the future of human resource management.
                    </p>
                    <div class="hero-buttons">
                        <a href="#portal" class="btn btn-primary">
                            <i class="fas fa-rocket me-2"></i>Get Started
                        </a>
                       
                        <!-- <a href="apply.php" class="btn btn-success">
                            <i class="fas fa-briefcase me-2"></i>Apply for Job
                        </a> -->
                    </div>
                </div>
                <div class="col-lg-6 animate-up">
                    <img src="https://images.unsplash.com/photo-1552664730-d307ca884978?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" 
                         alt="HR Portal" 
                         class="img-fluid rounded-3 shadow-lg">
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section" id="features">
        <div class="container">
            <div class="section-title animate-up">
                <h2>Powerful Features</h2>
                <p class="text-muted">Everything you need for modern HR management</p>
            </div>
            
            <div class="row">
                <div class="col-lg-4 col-md-6 animate-up">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <h4>Employee Management</h4>
                        <p class="text-muted">
                            Comprehensive employee database with profiles, documents, and history management.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 animate-up">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <h4>Attendance Tracking</h4>
                        <p class="text-muted">
                            Real-time attendance monitoring with biometric integration and shift management.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 animate-up">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <h4>Payroll Processing</h4>
                        <p class="text-muted">
                            Automated payroll calculation with tax deductions and digital payslip distribution.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 animate-up">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h4>Recruitment Management</h4>
                        <p class="text-muted">
                            Streamlined applicant tracking, interview scheduling, and onboarding processes.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 animate-up">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h4>Analytics & Reporting</h4>
                        <p class="text-muted">
                            Advanced analytics dashboard with custom reports and KPI tracking.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 animate-up">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                        <h4>Mobile App</h4>
                        <p class="text-muted">
                            Native mobile applications for employees and HR staff with push notifications.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>


    <!-- Job Application CTA
    <section class="application-cta" id="apply">
        <div class="container">
            <div class="cta-content animate-up">
                <h2 class="cta-title">Ready to Join Our Team?</h2>
                <p class="cta-subtitle">
                    We're always looking for talented individuals to join our growing team. 
                    Apply now and take the first step towards an exciting career with us.
                </p>
                <!-- <a href="apply.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-paper-plane me-2"></i>
                    Apply for Job Now
                </a> -->
                <!-- <p class="mt-3" style="opacity: 0.8;">
                    <small>It only takes 5 minutes to submit your application</small>
                </p>
            </div>
        </div>
    </section> --> -->

    <!-- Portal Access Section -->
    <section class="portal-section" id="portal">
        <div class="container">
            <div class="section-title animate-up">
                <h2>Portal Access</h2>
                <p class="text-muted">Choose your portal to continue</p>
            </div>
            
            <div class="portal-card animate-up">
                <div class="portal-title">
                    <h3> Portal Access</h3>
                    <p class="text-muted mb-0">Select your role to access the appropriate portal</p>
                </div>
                
                <div class="portal-options">
                    <div class="portal-option hr">
                        <i class="fas fa-user-tie portal-icon"></i>
                        <h4>HR Professional Portal</h4>
                        <p class="mb-4">Access comprehensive HR management tools, analytics, and employee data.</p>
                        <div class="mt-4">
                            <a href="login_hr.php" class="btn btn-light btn-lg w-100">
                                <i class="fas fa-sign-in-alt me-2"></i>
                                Access HR Portal
                            </a>
                        </div>
                    </div>
                    
                    <div class="portal-option employee">
                        <i class="fas fa-user-check portal-icon"></i>
                        <h4>Employee Self-Service</h4>
                        <p class="mb-4">View your profile, attendance, payslips, apply for leaves, and more.</p>
                        <div class="mt-4">
                            <a href="login_emp.php" class="btn btn-light btn-lg w-100">
                                <i class="fas fa-sign-in-alt me-2"></i>
                                Access Employee Portal
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer" id="contact">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-5 mb-lg-0 animate-up">
                    <a href="#" class="footer-logo">HR Portal</a>
                    <p class="text-muted mb-4">
                        Where People Meet Performance. Transforming workforce management 
                        through innovation and technology since 2010.
                    </p>
                   
                </div>
                
                <div class="col-lg-2 col-md-4 mb-5 mb-md-0 animate-up">
                    <h5 class="mb-4">Quick Links</h5>
                    <div class="footer-links">
                        <a href="#home">Home</a>
                        <a href="#features">Features</a>
                        <a href="#portal">Portals</a>
                        <a href="#contact">Contact</a>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-4 mb-5 mb-md-0 animate-up">
                    <h5 class="mb-4">Portals</h5>
                    <div class="footer-links">
                        <a href="login_hr.php">HR Portal</a>
                        <a href="login_emp.php">Employee Portal</a>
                        
                    </div>
                </div>
                
                
                  
                </div>
            </div>
            
            <hr class="my-5" style="border-color: rgba(255,255,255,0.1);">
            
            <div class="row">
                <div class="col-md-6">
                    <p class="text-muted mb-0">
                        &copy; 2026 HR Portal. All rights reserved.
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="text-muted mb-0">
                        <span class="mx-2">•</span>
                        <a href="#" class="text-muted me-3">Privacy Policy</a>
                        <span class="mx-2">•</span>
                        <a href="#" class="text-muted">Terms of Service</a>
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
        
        // Animated counters
        function animateCounter(element, target) {
            let start = 0;
            const increment = target / (2000 / 16); // 60fps
            const timer = setInterval(() => {
                start += increment;
                if (start >= target) {
                    element.textContent = target + (element.getAttribute('data-count') === '98' ? '%' : '');
                    clearInterval(timer);
                } else {
                    element.textContent = Math.floor(start);
                }
            }, 16);
        }
        
        // Start counters when element is in viewport
        function startCountersWhenVisible() {
            const counters = document.querySelectorAll('.stat-number');
            
            counters.forEach(counter => {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const target = parseInt(counter.getAttribute('data-count'));
                            animateCounter(counter, target);
                            observer.unobserve(counter);
                        }
                    });
                }, { threshold: 0.5 });
                
                observer.observe(counter);
            });
        }
        
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                if (targetId === '#') return;
                
                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    window.scrollTo({
                        top: targetElement.offsetTop - 80,
                        behavior: 'smooth'
                    });
                }
            });
        });
        
        // Add animation class to elements when they come into view
        function animateOnScroll() {
            const elements = document.querySelectorAll('.animate-up');
            
            elements.forEach(element => {
                const elementTop = element.getBoundingClientRect().top;
                const elementVisible = 150;
                
                if (elementTop < window.innerHeight - elementVisible) {
                    element.classList.add('animated');
                }
            });
        }
        
        // Initialize everything when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Start counters
            startCountersWhenVisible();
            
            // Initial animation check
            animateOnScroll();
            
            // Add scroll event for animations
            window.addEventListener('scroll', animateOnScroll);
            
            // Add hover effect to portal options
            const portalOptions = document.querySelectorAll('.portal-option');
            portalOptions.forEach(option => {
                option.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-10px) scale(1.02)';
                });
                
                option.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
            
            // Typing effect for hero subtitle
            const heroSubtitle = document.querySelector('.hero-subtitle');
            if (heroSubtitle) {
                const originalText = heroSubtitle.textContent;
                heroSubtitle.textContent = '';
                
                let i = 0;
                function typeWriter() {
                    if (i < originalText.length) {
                        heroSubtitle.textContent += originalText.charAt(i);
                        i++;
                        setTimeout(typeWriter, 20);
                    }
                }
                
                // Start typing effect after a delay
                setTimeout(typeWriter, 1000);
            }
            
            // Add parallax effect to background circles
            document.addEventListener('mousemove', function(e) {
                const circles = document.querySelectorAll('.bg-circle');
                const mouseX = e.clientX / window.innerWidth;
                const mouseY = e.clientY / window.innerHeight;
                
                circles.forEach((circle, index) => {
                    const speed = 0.01 + (index * 0.005);
                    const x = (mouseX * 30 * speed) - 15;
                    const y = (mouseY * 30 * speed) - 15;
                    
                    circle.style.transform = `translate(${x}px, ${y}px)`;
                });
            });
            
        //     // Apply button hover effect
        //     const applyButtons = document.querySelectorAll('[href="apply.php"]');
        //     applyButtons.forEach(button => {
        //         button.addEventListener('mouseenter', function() {
        //             this.style.transform = 'translateY(-3px) scale(1.05)';
        //         });
                
        //         button.addEventListener('mouseleave', function() {
        //             this.style.transform = 'translateY(0) scale(1)';
        //         });
        //     });
        // });
        
        // // Apply page redirection confirmation
        // document.querySelectorAll('[href="apply.php"]').forEach(button => {
        //     button.addEventListener('click', function(e) {
        //         // You can add a confirmation dialog here if needed
        //         console.log('Redirecting to application page...');
        //         // No preventDefault() - allow normal link behavior
        //     });
        // });
    </script>
</body>
</html>