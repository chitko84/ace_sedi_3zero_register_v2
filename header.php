
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>3ZERO Club Registration System</title>
    <meta name="description" content="Join the movement for a better world. Register your 3ZERO Club today and be part of creating zero net carbon emissions, zero wealth concentration, and zero unemployment.">
    <link rel="icon" href="favicon.ico" type="image/x-icon" sizes="16x16"/>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="uploads/aiu_logo.png" type="image/x-icon">
    <link rel="stylesheet" href="styles.css">
    <script>
        (function () {
            const saved = localStorage.getItem('zeroClubTheme');
            document.documentElement.setAttribute('data-theme', saved === 'dark' || saved === 'light' ? saved : 'light');
        })();
    </script>
    <link rel="stylesheet" href="assets/css/dark-mode.css">
    <script src="assets/js/theme.js" defer></script>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <header class="header" id="header">
            <div class="header-content">
                <a href="https://aiu.edu.my/">
                    <img src="uploads/aiu_logo.png" alt="3Zero Club Logo" class="logo">
                </a>
                <button class="menu-toggle" aria-label="Toggle Menu" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <nav class="nav-desktop">
                    <a href="index.php" class="nav-link"><i class="fas fa-home"></i> Home</a>
                    <a href="https://ace-sedi.aiu.edu.my/about.html" class="nav-link"><i class="fas fa-info-circle"></i> About</a>
                    <a href="bulletin.php" class="nav-link">
                        <i class="fas fa-thumbtack"></i> Bulletin
                    </a>
                    <a href="https://ace-sedi.aiu.edu.my/greenCredits/" class="nav-link"><i class="fas fa-globe"></i> GreenCredit</a>
                    <a href="https://ace-sedi.aiu.edu.my/newsAndEvent.html" class="nav-link"><i class="fas fa-calendar-alt"></i> Events</a>
                    <a href="login.php" class="nav-link"><i class="fas fa-sign-in-alt"></i> Login</a>
                    <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode">
                        <i class="fa-solid fa-moon"></i>
                    </button>
                    <button class="register-btn" onclick="window.location.href='register.php'">
                        <i class="fas fa-user-plus"></i> Register
                    </button>
                </nav>
            </div>
            <!-- Mobile Menu -->
            <div class="mobile-menu" id="mobileMenu">
                <nav class="mobile-nav">
                    <a href="index.php" class="nav-link"><i class="fas fa-home"></i> Home</a>
                    <a href="https://ace-sedi.aiu.edu.my/about.html" class="nav-link"><i class="fas fa-info-circle"></i> About</a>
                    <a href="bulletin.php" class="nav-link">
                        <i class="fas fa-thumbtack"></i> Bulletin
                    </a>
                    <a href="https://ace-sedi.aiu.edu.my/greenCredits/" class="nav-link"><i class="fas fa-globe"></i> GreenCredit</a>
                    <a href="https://ace-sedi.aiu.edu.my/newsAndEvent.html" class="nav-link"><i class="fas fa-calendar-alt"></i> Events</a>
                    <a href="login.php" class="nav-link"><i class="fas fa-sign-in-alt"></i> Login</a>
                    <button type="button" class="theme-toggle" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode">
                        <i class="fa-solid fa-moon"></i>
                    </button>
                    <button class="register-btn" onclick="window.location.href='register.php'">
                        <i class="fas fa-user-plus"></i> Register
                    </button>
                </nav>
            </div>
        </header>
