        <!-- Footer -->
        <footer class="footer">
            <div class="footer-content">
                <div class="footer-section">
                    <a href="/">
                        <img src="uploads/3 zero club logo.png" alt="3ZERO Club Logo" class="footer-logo">
                    </a>
                    <p class="footer-text">
                        The 3ZERO Club is an initiative towards achieving the Nobel Peace Laureate Professor Muhammad Yunus's vision 
                        of creating a world of three zeros — zero net carbon emission, zero wealth concentration for ending poverty, 
                        and zero unemployment by unleashing entrepreneurship in all.
                    </p>
                </div>
                <div class="footer-section">
                    <h4 class="footer-section-title">Our Partners</h4>
                    <p class="footer-text">Powered by</p>
                    <div class="partner-logos">
    <img src="uploads/ace sedi logo.png" alt="ACE-SEDI" class="partner-logo" style="width: 150px; height: auto;">
    <img src="uploads/yunus_social_business_center.png" alt="Social Business" class="partner-logo" style="width: 150px; height: auto;">
</div>
                    <p class="footer-text">Supported by</p>
                    <div class="supporter-logos">
                        <img src="https://ace-sedi.aiu.edu.my/assets/images/ysbc-2.png" alt="Global Supporter" class="supporter-logo" style="width: 150px; height: auto;">
                    </div>
                </div>
                <div class="footer-section">
                    <h4 class="footer-section-title">Connect With Us</h4>
                    <div class="social-icons">
                        <a href="https://www.instagram.com/aiu.socialbusiness/" class="social-icon" target="_blank" aria-label="Instagram">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <a href="https://www.linkedin.com/in/aiu-3zero-and-social-business-9906b1341/" class="social-icon" target="_blank" aria-label="LinkedIn">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                        <a href="https://www.youtube.com/@aiu_acesedi" class="social-icon" target="_blank" aria-label="YouTube">
                            <i class="fab fa-youtube"></i>
                        </a>
                    </div>
                    <div class="contact-info">
                        <a href="https://maps.app.goo.gl/GP1maMkwWvkrNnu66"
                        class="contact-item" target="_blank" rel="noopener noreferrer">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>Albukhary International University, Alor Setar, Kedah</span>
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="footer-links">
                <a href="https://ace-sedi.aiu.edu.my/sb2.html" class="footer-link"><i class="fas fa-book"></i>  3ZERO Clubs in AIU</a>
                <a href="https://wa.me/601112476299" class="footer-link" target="_blank">
                    <i class="fab fa-whatsapp"></i> Contact Us
                </a>
                <a href="developer_info.php" class="footer-link" aria-label="Developer Info" title="Developer Info">
                    <i class="fas fa-user-cog"></i> Developer Info
                </a>
            </div>

            <div class="copyright">
                © 2025. AIU 3ZERO Club Registration System. All Rights Reserved.
            </div>
        </footer>
        
        <!-- Back to top button -->
        <button class="back-to-top" id="backToTop" aria-label="Back to top">
            <i class="fas fa-arrow-up"></i>
        </button>
    </div>
    
<link rel="stylesheet" href="assets/css/dark-mode.css">
<script src="assets/js/theme.js"></script>
<script>
    // Mobile menu toggle
    const menuToggle = document.getElementById('menuToggle');
    const mobileMenu = document.getElementById('mobileMenu');
    
    if (menuToggle && mobileMenu) {
        menuToggle.addEventListener('click', () => {
            mobileMenu.classList.toggle('show');
            menuToggle.innerHTML = mobileMenu.classList.contains('show') ? 
                '<i class="fas fa-times"></i>' : '<i class="fas fa-bars"></i>';
        });
    }
    
    // Close mobile menu when clicking a link
    document.querySelectorAll('.mobile-nav .nav-link, .mobile-nav .register-btn').forEach(link => {
        link.addEventListener('click', () => {
            if (mobileMenu) mobileMenu.classList.remove('show');
            if (menuToggle) menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
        });
    });
    
    // Back to top button
    const backToTopBtn = document.getElementById('backToTop');
    const header = document.getElementById('header');
    
    if (backToTopBtn && header) {
        window.addEventListener('scroll', () => {
            if (window.pageYOffset > 300) {
                backToTopBtn.style.display = 'flex';
                backToTopBtn.style.alignItems = 'center';
                backToTopBtn.style.justifyContent = 'center';
                header.classList.add('scrolled');
            } else {
                backToTopBtn.style.display = 'none';
                header.classList.remove('scrolled');
            }
        });
        
        backToTopBtn.addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }
    
    // Add hover effect to all elements with class 'nav-link'
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('mouseenter', () => {
            if (link.querySelector('i')) {
                link.querySelector('i').style.transform = 'scale(1.2)';
            }
        });
        link.addEventListener('mouseleave', () => {
            if (link.querySelector('i')) {
                link.querySelector('i').style.transform = 'scale(1)';
            }
        });
    });
    
    // FAQ accordion functionality
        document.addEventListener('DOMContentLoaded', function() {
            const faqQuestions = document.querySelectorAll('.faq-question');
            
            faqQuestions.forEach(question => {
                question.addEventListener('click', () => {
                    const answer = question.nextElementSibling;
                    const isActive = answer.classList.contains('active');
                    
                    // Close all other FAQ items
                    document.querySelectorAll('.faq-answer').forEach(item => {
                        item.classList.remove('active');
                    });
                    
                    document.querySelectorAll('.faq-question').forEach(item => {
                        item.classList.remove('active');
                    });
                    
                    // Toggle current answer
                    if (!isActive) {
                        question.classList.add('active');
                        answer.classList.add('active');
                    }
                });
            });
        });
    
    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            
            const targetId = this.getAttribute('href');
            if (targetId === '#') return;
            
            const targetElement = document.querySelector(targetId);
            if (targetElement) {
                window.scrollTo({
                    top: targetElement.offsetTop - 100,
                    behavior: 'smooth'
                });
            }
        });
    });
    
    // Register button hover effect for all instances
    document.querySelectorAll('.register-btn').forEach(btn => {
        btn.addEventListener('mouseenter', () => {
            if (btn.querySelector('i')) {
                btn.querySelector('i').style.transform = 'translateX(3px)';
            }
        });
        btn.addEventListener('mouseleave', () => {
            if (btn.querySelector('i')) {
                btn.querySelector('i').style.transform = 'translateX(0)';
            }
        });
    });

    // Slideshow functionality
    document.addEventListener('DOMContentLoaded', function() {
        const slides = document.querySelectorAll('.slide');
        const dots = document.querySelectorAll('.dot');
        const prevBtn = document.querySelector('.prev-btn');
        const nextBtn = document.querySelector('.next-btn');
        
        if (slides.length > 0 && dots.length > 0 && prevBtn && nextBtn) {
            let currentSlide = 0;
            let slideInterval;
            
            // Function to show a specific slide
            function showSlide(index) {
                // Hide all slides
                slides.forEach(slide => slide.classList.remove('active'));
                dots.forEach(dot => dot.classList.remove('active'));
                
                // Show the selected slide
                slides[index].classList.add('active');
                dots[index].classList.add('active');
                currentSlide = index;
            }
            
            // Function to go to next slide
            function nextSlide() {
                let nextIndex = (currentSlide + 1) % slides.length;
                showSlide(nextIndex);
            }
            
            // Function to go to previous slide
            function prevSlide() {
                let prevIndex = (currentSlide - 1 + slides.length) % slides.length;
                showSlide(prevIndex);
            }
            
            // Start automatic slideshow
            function startSlideshow() {
                slideInterval = setInterval(nextSlide, 5000); // Change slide every 5 seconds
            }
            
            // Stop automatic slideshow
            function stopSlideshow() {
                clearInterval(slideInterval);
            }
            
            // Event listeners for controls
            nextBtn.addEventListener('click', function() {
                stopSlideshow();
                nextSlide();
                startSlideshow();
            });
            
            prevBtn.addEventListener('click', function() {
                stopSlideshow();
                prevSlide();
                startSlideshow();
            });
            
            // Event listeners for dots
            dots.forEach(dot => {
                dot.addEventListener('click', function() {
                    stopSlideshow();
                    let slideIndex = parseInt(this.getAttribute('data-slide'));
                    showSlide(slideIndex);
                    startSlideshow();
                });
            });
            
            // Pause slideshow when user hovers over it
            const heroSection = document.querySelector('.hero');
            if (heroSection) {
                heroSection.addEventListener('mouseenter', stopSlideshow);
                heroSection.addEventListener('mouseleave', startSlideshow);
            }
            
            // Start the slideshow
            startSlideshow();
        }
    });
</script>
</body>
</html>
