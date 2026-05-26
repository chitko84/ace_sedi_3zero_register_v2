<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in - if not, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

include '../includes/db.php';

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Information - 3ZERO Club</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="../uploads/aiu_logo.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a5276;
            --primary-dark: #154360;
            --secondary: #28b463;
            --accent: #f39c12;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --border-radius: 8px;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        .developer-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            overflow: hidden;
            transition: var(--transition);
        }

        .developer-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .developer-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .developer-body {
            padding: 2rem;
        }

        .developer-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            margin: -80px auto 20px;
            background: white;
            box-shadow: var(--shadow);
            object-fit: cover;
        }

        .tech-stack {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 20px 0;
        }

        .tech-badge {
            background: var(--light);
            border: 1px solid var(--gray-light);
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 0.8rem;
            color: var(--dark);
        }

        .contact-info {
            background: var(--light);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-top: 20px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            padding: 8px 0;
        }

        .contact-item i {
            width: 30px;
            color: var(--primary);
        }

        .project-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin: 30px 0;
        }

        .feature-list {
            list-style: none;
            padding: 0;
        }

        .feature-list li {
            padding: 8px 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .feature-list li:last-child {
            border-bottom: none;
        }

        .feature-list i {
            color: var(--secondary);
            margin-right: 10px;
        }

        .social-links {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }

        .social-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            text-decoration: none;
            transition: var(--transition);
        }

        .social-link:hover {
            transform: translateY(-2px);
            background: var(--primary-dark);
            color: white;
        }

        .social-link.whatsapp:hover {
            background: #25D366;
        }

        .social-link.email:hover {
            background: #EA4335;
        }

        .timeline {
            position: relative;
            padding: 20px 0;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--primary);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 30px;
            padding-left: 50px;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: 12px;
            top: 5px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--primary);
            border: 3px solid white;
            box-shadow: var(--shadow);
        }

        .achievement-badge {
            background: linear-gradient(135deg, var(--accent), #e67e22);
            color: white;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin: 10px 0;
            text-align: center;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            text-align: center;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gray);
        }

        [data-theme="dark"] body .developer-card {
            background: #1e293b !important;
            color: #f8fafc !important;
            border: 1px solid #475569 !important;
            box-shadow: 0 18px 48px rgba(0, 0, 0, .36) !important;
        }

        [data-theme="dark"] body .developer-card:hover {
            box-shadow: 0 22px 58px rgba(53, 208, 127, .14) !important;
        }

        [data-theme="dark"] body .developer-card h1,
        [data-theme="dark"] body .developer-card h2,
        [data-theme="dark"] body .developer-card h3,
        [data-theme="dark"] body .developer-card h4,
        [data-theme="dark"] body .developer-card h5,
        [data-theme="dark"] body .developer-card h6,
        [data-theme="dark"] body .developer-card p,
        [data-theme="dark"] body .developer-card li,
        [data-theme="dark"] body .developer-card span,
        [data-theme="dark"] body .developer-card strong {
            color: #f8fafc !important;
        }

        [data-theme="dark"] body .developer-card .text-muted,
        [data-theme="dark"] body .developer-card .small {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] body .developer-card i {
            color: #6cb6ff;
        }

        [data-theme="dark"] body .developer-header {
            background: linear-gradient(135deg, #0f172a, #1e3a5f) !important;
            border-bottom: 1px solid #475569;
        }

        [data-theme="dark"] body .project-info {
            background: linear-gradient(135deg, #1e3a5f 0%, #14532d 100%) !important;
            border: 1px solid rgba(148, 163, 184, .35);
            color: #f8fafc !important;
        }

        [data-theme="dark"] body .feature-list li {
            border-bottom-color: rgba(203, 213, 225, .22);
        }

        [data-theme="dark"] body .feature-list i {
            color: #35d07f !important;
        }

        [data-theme="dark"] body .tech-badge {
            background: #334155 !important;
            border-color: #475569 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] body .timeline::before {
            background: #6cb6ff;
        }

        [data-theme="dark"] body .timeline-item::before {
            background: #35d07f;
            border-color: #1e293b;
            box-shadow: 0 0 0 4px rgba(53, 208, 127, .14);
        }

        [data-theme="dark"] body .timeline-item p {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] body .achievement-badge {
            background: linear-gradient(135deg, #1a5276, #b45309) !important;
            border: 1px solid rgba(248, 250, 252, .14);
            color: #f8fafc !important;
        }

        [data-theme="dark"] body .contact-info {
            background: #0f172a !important;
            border: 1px solid #475569 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] body .contact-item {
            color: #f8fafc !important;
            border-bottom: 1px solid rgba(71, 85, 105, .55);
        }

        [data-theme="dark"] body .contact-item:last-of-type {
            border-bottom: 0;
        }

        [data-theme="dark"] body .contact-item i {
            color: #35d07f !important;
        }

        [data-theme="dark"] body .social-link {
            background: #334155 !important;
            color: #f8fafc !important;
            border: 1px solid #475569;
        }

        [data-theme="dark"] body .social-link:hover {
            background: #1a5276 !important;
            border-color: #6cb6ff;
            color: #ffffff !important;
        }

        [data-theme="dark"] body .social-link.whatsapp:hover {
            background: #25D366 !important;
            border-color: #25D366;
        }

        [data-theme="dark"] body .social-link.email:hover {
            background: #EA4335 !important;
            border-color: #EA4335;
        }

        [data-theme="dark"] body .developer-card .bg-light {
            background: #0f172a !important;
            border: 1px solid #475569;
            color: #f8fafc !important;
        }

        [data-theme="dark"] body .developer-card .card {
            background: #0f172a !important;
            border-color: #475569 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] body .developer-card .btn-outline-warning,
        [data-theme="dark"] body .developer-card .btn-outline-info {
            color: #f8fafc !important;
            border-color: #64748b !important;
        }

        [data-theme="dark"] body .developer-card .btn-outline-warning:hover,
        [data-theme="dark"] body .developer-card .btn-outline-info:hover {
            color: #0f172a !important;
        }
    </style>
</head>
<body>
    <?php include('header.php'); ?>
    
    <div class="main-content" id="mainContent">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h2 mb-1">Developer Information</h1>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Main Developer Card -->
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="developer-card">
                    <div class="developer-header" style="margin-bottom: 30px;">
                        <h2 class="h3 mb-2" style="margin-bottom: 100px;">3ZERO Club Registration System</h2>
                        <p></p>
                    </div>
                    
                    <div class="developer-body">
                        <!-- Developer Avatar with your image -->
                        <img src="../uploads/chitkoko_profile.jpg" alt="Chit Ko Ko" class="developer-avatar d-flex align-items-center justify-content-center" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        
                        <div class="text-center mb-4">
                            <h3 class="h4 mb-2">Developer - Chit Ko Ko</h3>
                            <p class="text-muted">School of Computing and Informatics</p>
                            <p class="text-muted">Bachelor of Computer Science (Hons)</p>
                        </div>

                        <!-- Project Information -->
                        <div class="project-info">
                            <h4 class="h5 mb-3"><i class="fas fa-rocket me-2"></i>Project Overview</h4>
                            <p class="mb-3">The 3ZERO Club Registration System is a comprehensive platform designed to streamline club management, member registration, and event coordination for educational institutions.</p>
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="feature-list">
                                        <li><i class="fas fa-check-circle"></i>User Authentication & Authorization</li>
                                        <li><i class="fas fa-check-circle"></i>Club Registration & Management</li>
                                        <li><i class="fas fa-check-circle"></i>Member Management System</li>
                                        <li><i class="fas fa-check-circle"></i>Event Creation & Tracking</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="feature-list">
                                        <li><i class="fas fa-check-circle"></i>Real-time Notifications</li>
                                        <li><i class="fas fa-check-circle"></i>Project Management Tools</li>
                                        <li><i class="fas fa-check-circle"></i>Achievement Tracking</li>
                                        <li><i class="fas fa-check-circle"></i>Admin Dashboard</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Technology Stack -->
                        <div class="mb-4">
                            <h4 class="h5 mb-3"><i class="fas fa-layer-group me-2"></i>Technology Stack</h4>
                            <div class="tech-stack">
                                <span class="tech-badge">PHP 8.x</span>
                                <span class="tech-badge">MySQL</span>
                                <span class="tech-badge">HTML5</span>
                                <span class="tech-badge">CSS3</span>
                                <span class="tech-badge">JavaScript</span>
                                <span class="tech-badge">Bootstrap 5</span>
                                <span class="tech-badge">Font Awesome</span>
                                <span class="tech-badge">AJAX</span>
                                <span class="tech-badge">Git</span>
                            </div>
                        </div>

                        <!-- Development Timeline -->
                        <div class="mb-4">
                            <h4 class="h5 mb-3"><i class="fas fa-history me-2"></i>Development Timeline</h4>
                            <div class="timeline">
                                <div class="timeline-item">
                                    <h6 class="mb-1">Phase 1: Foundation</h6>
                                    <p class="mb-0">Project planning, database design, and core architecture setup</p>
                                </div>
                                <div class="timeline-item">
                                    <h6 class="mb-1">Phase 2: Core Features</h6>
                                    <p class="mb-0">User authentication, club registration, and basic member management</p>
                                </div>
                                <div class="timeline-item">
                                    <h6 class="mb-1">Phase 3: Advanced Features</h6>
                                    <p class="mb-0">Event management, notifications, and project tracking systems</p>
                                </div>
                                <div class="timeline-item">
                                    <h6 class="mb-1">Phase 4: Polish & Launch</h6>
                                    <p class="mb-0">UI/UX improvements, performance optimization, and deployment</p>
                                </div>
                            </div>
                        </div>

                        <!-- Key Achievements -->
                        <div class="mb-4">
                            <h4 class="h5 mb-3"><i class="fas fa-trophy me-2"></i>Key Achievements</h4>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="achievement-badge">
                                        <i class="fas fa-bolt fa-2x mb-2"></i>
                                        <h6 class="mb-1">High Performance</h6>
                                        <p class="mb-0 small">Optimized for fast loading and smooth user experience</p>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="achievement-badge">
                                        <i class="fas fa-shield-alt fa-2x mb-2"></i>
                                        <h6 class="mb-1">Secure & Reliable</h6>
                                        <p class="mb-0 small">Built with security best practices and data protection</p>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="achievement-badge">
                                        <i class="fas fa-mobile-alt fa-2x mb-2"></i>
                                        <h6 class="mb-1">Responsive Design</h6>
                                        <p class="mb-0 small">Works perfectly on all devices and screen sizes</p>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="achievement-badge">
                                        <i class="fas fa-users fa-2x mb-2"></i>
                                        <h6 class="mb-1">User-Centric</h6>
                                        <p class="mb-0 small">Designed with user experience as the top priority</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="contact-info">
                            <h4 class="h5 mb-3"><i class="fas fa-envelope me-2"></i>Get In Touch</h4>
                            <div class="contact-item">
                                <i class="fas fa-envelope"></i>
                                <span>chitko.ko@student.aiu.edu.my</span>
                            </div>
                            <div class="contact-item">
                                <i class="fas fa-phone"></i>
                                <span>+601112476299 (WhatsApp, Telegram)</span>
                            </div>
                            <div class="contact-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span>AIU University Campus</span>
                            </div>
                            <div class="contact-item">
                                <i class="fas fa-clock"></i>
                                <span>Support Hours: Mon-Fri, 9AM-6PM</span>
                            </div>
                            
                            <div class="social-links">
                                <a href="https://wa.me/601112476299" class="social-link whatsapp" title="WhatsApp" target="_blank">
                                    <i class="fab fa-whatsapp"></i>
                                </a>
                                <a href="mailto:chitko.ko@student.aiu.edu.my" class="social-link email" title="Email">
                                    <i class="fas fa-envelope"></i>
                                </a>
                            </div>
                        </div>

                        <!-- System Information -->
                        <div class="mt-4 p-3 bg-light rounded">
                            <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>System Information</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Version:</strong> 2.1.0</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
                                    <p><strong>Database:</strong> MySQL</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Feedback Section -->
        <div class="row mt-4">
            <div class="col-lg-8 mx-auto">
                <div class="developer-card">
                    <div class="developer-body">
                        <h4 class="h5 mb-3"><i class="fas fa-comments me-2"></i>Feedback & Support</h4>
                        <p class="mb-3">We're constantly working to improve the 3ZERO Club Registration System. Your feedback is valuable to us!</p>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <i class="fas fa-bug fa-2x text-warning mb-3"></i>
                                        <h6>Report a Bug</h6>
                                        <p class="small text-muted">Found an issue? Let us know so we can fix it.</p>
                                        <a href="mailto:chitko.ko@student.aiu.edu.my" class="btn btn-outline-warning btn-sm">Report Bug</a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <i class="fas fa-lightbulb fa-2x text-info mb-3"></i>
                                        <h6>Feature Request</h6>
                                        <p class="small text-muted">Have an idea for improvement? Share it with us.</p>
                                        <a href="mailto:chitko.ko@student.aiu.edu.my" class="btn btn-outline-info btn-sm">Suggest Feature</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Simple animation for stats counter
        document.addEventListener('DOMContentLoaded', function() {
            const statNumbers = document.querySelectorAll('.stat-number');
            
            statNumbers.forEach(stat => {
                const target = parseInt(stat.textContent);
                let current = 0;
                const increment = target / 50;
                const timer = setInterval(() => {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        clearInterval(timer);
                    }
                    stat.textContent = Math.floor(current) + (stat.textContent.includes('%') ? '%' : '+');
                }, 30);
            });
        });
    </script>
    </script>
    <script>
// ✅ Stand-alone notification dropdown toggle script
document.addEventListener('DOMContentLoaded', function () {
    // Ensure header doesn't clip the dropdown
    const headerEl = document.querySelector('.main-header');
    if (headerEl) headerEl.style.overflow = 'visible';

    // Initialize all Bootstrap dropdowns on the page
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
        new bootstrap.Dropdown(el, { autoClose: 'outside', display: 'static' });
    });

    // Optional: direct manual toggle for the bell icon (if Bootstrap fails to auto-bind)
    const bellBtn = document.getElementById('notificationDropdown');
    if (bellBtn) {
        bellBtn.addEventListener('click', function (e) {
            e.preventDefault();
            const dd = bootstrap.Dropdown.getOrCreateInstance(bellBtn, { autoClose: 'outside', display: 'static' });
            dd.toggle();
        });
    }
});
</script>
    <?php include('footer.php'); ?>
</body>
</html>
