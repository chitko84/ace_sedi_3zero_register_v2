<?php include('header.php'); ?>

<!-- Hero Section -->
<section class="hero">
    <div class="slideshow">
        <div class="slide slide-1 active"></div>
        <div class="slide slide-2"></div>
        <div class="slide slide-3"></div>
        <div class="slide slide-4"></div>
    </div>
    
    <div class="hero-content">
        <h1 class="hero-title">AIU 3ZERO Club Bulletin</h1>
        <p class="hero-subtitle" style="color:white;">Stay updated with the latest events, activities, and achievements from our 3ZERO community</p>
    </div>
    
    <div class="slideshow-controls">
        <button class="slideshow-arrow prev-btn">
            <i class="fas fa-chevron-left"></i>
        </button>
        
        <div class="slideshow-dots">
            <div class="dot active" data-slide="0"></div>
            <div class="dot" data-slide="1"></div>
            <div class="dot" data-slide="2"></div>
            <div class="dot" data-slide="3"></div>
        </div>
        
        <button class="slideshow-arrow next-btn">
            <i class="fas fa-chevron-right"></i>
        </button>
    </div>
</section>

<!-- Bulletin Links Section -->
<section class="bulletin-section">
    <div class="bulletin-container">
        <div class="section-header">
            <h2 class="section-title">Explore Our Bulletin</h2>
            <p class="section-subtitle">Discover the latest happenings and accomplishments in our 3ZERO community</p>
        </div>
        
        <div class="bulletin-grid">
            <!-- Events Link -->
            <div class="bulletin-card">
                <div class="bulletin-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <h3 class="bulletin-title">Events</h3>
                <p class="bulletin-text">Stay informed about upcoming workshops, seminars, and community events organized by 3ZERO Clubs.</p>
                <a href="events.php" class="bulletin-btn">
                    <i class="fas fa-arrow-right"></i> View Events
                </a>
            </div>
            
            <!-- Activities / Participations Link -->
            <div class="bulletin-card">
                <div class="bulletin-icon">
                    <i class="fas fa-hands-helping"></i>
                </div>
                <h3 class="bulletin-title">Activities & Participations</h3>
                <p class="bulletin-text">Explore ongoing projects, community engagements, and participation opportunities across all clubs.</p>
                <a href="activities_public.php" class="bulletin-btn">
                    <i class="fas fa-arrow-right"></i> View Activities
                </a>
            </div>
            
            <!-- Achievements Link -->
            <div class="bulletin-card">
                <div class="bulletin-icon">
                    <i class="fas fa-trophy"></i>
                </div>
                <h3 class="bulletin-title">Achievements</h3>
                <p class="bulletin-text">Celebrate the successes, milestones, and impactful accomplishments of our 3ZERO Clubs and members.</p>
                <a href="achievements.php" class="bulletin-btn">
                    <i class="fas fa-arrow-right"></i> View Achievements
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Stats Section -->
<!-- <section class="stats-section">
    <div class="stats-container">
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number">50+</div>
                <div class="stat-label">Upcoming Events</div>
            </div>
            <div class="stat-item">
                <div class="stat-number">100+</div>
                <div class="stat-label">Active Projects</div>
            </div>
            <div class="stat-item">
                <div class="stat-number">200+</div>
                <div class="stat-label">Success Stories</div>
            </div>
            <div class="stat-item">
                <div class="stat-number">1000+</div>
                <div class="stat-label">Community Members</div>
            </div>
        </div>
    </div>
</section> -->

<!-- CTA Section -->
<section class="cta-section">
    <div class="cta-container">
        <h2 class="cta-title">Want to Feature Your Club?</h2>
        <p class="cta-text">Share your events, activities, and achievements with the entire 3ZERO community</p>
        <div class="cta-buttons">
            <a href="login.php" class="cta-btn cta-btn-primary">
                <i class="fas fa-sign-in-alt"></i> Login to Submit
            </a>
            <a href="register.php" class="cta-btn cta-btn-secondary">
                <i class="fas fa-user-plus"></i> Register Now
            </a>
        </div>
    </div>
</section>

<style>
/* Bulletin Section Styles */
.bulletin-section {
    padding: 80px 0;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.bulletin-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

.bulletin-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 30px;
    margin-top: 50px;
}

.bulletin-card {
    background: white;
    padding: 40px 30px;
    border-radius: 20px;
    text-align: center;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    border: 1px solid #e9ecef;
    position: relative;
    overflow: hidden;
}

.bulletin-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #1a5276, #28b463);
}

.bulletin-card:hover {
    transform: translateY(-10px);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
}

.bulletin-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 20px;
    background: linear-gradient(135deg, #1a5276, #28b463);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 2rem;
}

.bulletin-title {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 15px;
    color: #2c3e50;
    font-family: 'Poppins', sans-serif;
}

.bulletin-text {
    color: #6c757d;
    line-height: 1.6;
    margin-bottom: 25px;
    font-size: 1rem;
}

.bulletin-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 12px 30px;
    background: linear-gradient(135deg, #1a5276, #154360);
    color: white;
    text-decoration: none;
    border-radius: 50px;
    font-weight: 500;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
}

.bulletin-btn:hover {
    background: linear-gradient(135deg, #154360, #1a5276);
    transform: translateX(5px);
    color: white;
    text-decoration: none;
}

/* Responsive Design */
@media (max-width: 768px) {
    .bulletin-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .bulletin-card {
        padding: 30px 20px;
    }
    
    .bulletin-icon {
        width: 70px;
        height: 70px;
        font-size: 1.8rem;
    }
    
    .bulletin-title {
        font-size: 1.3rem;
    }
}
</style>

<?php include('footer.php'); ?>
