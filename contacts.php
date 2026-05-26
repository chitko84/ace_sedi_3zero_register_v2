<?php
// contacts.php
// Contact page for users to get help (no form version)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Header
include('header.php');
?>
<style>
/* ---------- Page wrapper ---------- */
:root{
  /* fallback if your header.php already defines this */
  --header-h: 70px;
}

.contact-hero {
    /* push below fixed navbar */
    padding: calc(60px + var(--header-h)) 16px 40px;
    background: linear-gradient(135deg, #1a5276, #154360);
    color: #fff;
    text-align: center;
    scroll-margin-top: calc(var(--header-h) + 20px);
}
.contact-hero h1 {
    font-family: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    font-size: clamp(28px, 4vw, 42px);
    margin: 0 0 12px;
}
.contact-hero p {
    opacity:.95;
    margin: 0;
    font-size: 18px;
    max-width: 700px;
    margin-left: auto;
    margin-right: auto;
}

/* Contact Layout — single column since form removed */
.contact-layout {
    max-width: 900px;
    margin: 40px auto 60px;
    padding: 0 16px;
}

/* Contact Info */
.contact-info {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border-radius: 16px;
    padding: 40px;
    border: 1px solid #e9ecef;
}
.contact-info h3 {
    color: #1a5276;
    margin-bottom: 10px;
    font-size: 24px;
    font-weight: 700;
}
.contact-subnote{
    margin: 10px 0 30px;
    color:#2c3e50;
    font-size: 14px;
    opacity:.9;
}

.contact-item {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 25px;
    padding: 20px;
    background: #fff;
    border-radius: 12px;
    border-left: 4px solid #1a5276;
    transition: transform 0.3s ease, box-shadow .3s ease;
}
.contact-item:hover {
    transform: translateX(5px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.08);
}
.contact-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #1a5276, #154360);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.contact-icon i {
    color: white;
    font-size: 20px;
}
.contact-details h4 {
    margin: 0 0 5px 0;
    color: #2c3e50;
    font-size: 16px;
    font-weight: 600;
}
.contact-details p {
    margin: 0;
    color: #6c757d;
    font-size: 14px;
}
.contact-link {
    color: #1a5276;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.3s ease;
    word-break: break-word;
}
.contact-link:hover {
    color: #154360;
    text-decoration: underline;
}

/* Social Links */
.social-links {
    display: flex;
    gap: 15px;
    margin-top: 20px;
}
.social-link {
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #1a5276, #154360);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    text-decoration: none;
    transition: all 0.3s ease;
}
.social-link:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(26,82,118,0.3);
    color: white;
}

/* Response Times */
.response-times {
    margin-top: 30px;
    padding: 20px;
    background: #e8f4f8;
    border-radius: 10px;
    border-left: 4px solid #28b463;
}
.response-times h4 {
    color: #1a5276;
    margin-bottom: 10px;
    font-size: 16px;
}
.response-times p {
    margin: 5px 0;
    color: #5b6b79;
    font-size: 14px;
}

/* FAQ Section */
.faq-section {
    max-width: 1200px;
    margin: 60px auto;
    padding: 0 16px;
}
.faq-section h2 {
    text-align: center;
    color: #1a5276;
    margin-bottom: 40px;
    font-size: 32px;
}
.faq-grid {
    display: grid;
    gap: 20px;
}
.faq-item {
    background: #fff;
    border-radius: 12px;
    padding: 25px;
    border: 1px solid #e9ecef;
    box-shadow: 0 5px 15px rgba(0,0,0,0.05);
}
.faq-item h4 {
    color: #2c3e50;
    margin-bottom: 10px;
    font-size: 18px;
}
.faq-item p {
    color: #6c757d;
    margin: 0;
    line-height: 1.6;
}

@media (max-width: 768px) {
    .contact-info { padding: 25px; }
    .contact-hero { padding: calc(40px + var(--header-h)) 16px 30px; }
}
</style>

<section class="contact-hero">
    <h1>Get In Touch</h1>
    <p>
        Have questions or need assistance? We're here to help you with any inquiries about 3ZERO Club activities and events.
        <br><br>
        <strong>Tip:</strong> Click any of the boxes below to contact us directly.
    </p>
</section>

<section class="contact-layout">
    <!-- Contact Information (only) -->
    <div class="contact-info">
        <h3>Contact Information</h3>
        <div class="contact-subnote">Choose WhatsApp, Email, or check our typical response times.</div>

        <a class="contact-item" href="https://wa.me/601112476299" target="_blank" rel="noopener">
            <div class="contact-icon">
                <i class="fas fa-phone-alt"></i>
            </div>
            <div class="contact-details">
                <h4>Phone & Messaging</h4>
                <p>
                    <span class="contact-link"><i class="fab fa-whatsapp me-1"></i>+60 11-1247 6299</span><br>
                    <small>Available on WhatsApp & Telegram</small>
                </p>
            </div>
        </a>

        <div class="contact-item">
            <div class="contact-icon">
                <i class="fas fa-envelope"></i>
            </div>
            <div class="contact-details">
                <h4>Email Address</h4>
                <p>
                    <a href="mailto:chitko.ko@student.aiu.edu.my" class="contact-link">
                        chitko.ko@student.aiu.edu.my
                    </a>
                    <br>
                    <a href="mailto:chitkoko.ali@gmail.com" class="contact-link">
                        chitkoko.ali@gmail.com
                    </a>
                </p>
            </div>
        </div>

        <div class="social-links">
            <a href="https://wa.me/601112476299" class="social-link" target="_blank" title="WhatsApp" rel="noopener">
                <i class="fab fa-whatsapp"></i>
            </a>
            <a href="https://t.me/+601112476299" class="social-link" target="_blank" title="Telegram" rel="noopener">
                <i class="fab fa-telegram"></i>
            </a>
            <a href="mailto:chitko.ko@student.aiu.edu.my" class="social-link" title="Email">
                <i class="fas fa-envelope"></i>
            </a>
        </div>

        <div class="response-times">
            <h4><i class="fas fa-info-circle me-2"></i>Best Times to Reach Us</h4>
            <p><strong>Weekdays:</strong> 9:00 AM - 6:00 PM</p>
            <p><strong>Weekends:</strong> 10:00 AM - 4:00 PM</p>
            <p><strong>Urgent Support:</strong> Available via WhatsApp</p>
        </div>
    </div>
</section>

<!-- FAQ Section (kept) -->
<section class="faq-section">
    <h2>Frequently Asked Questions</h2>
    <div class="faq-grid">
        <div class="faq-item">
            <h4>How do I register a new club?</h4>
            <p>You can register a new club through the Club Registration section in your dashboard. Make sure you have all the required information about your club members ready. Also make sure that all your club members are registered into the system as a user first before registring your club.</p>
        </div>

        <div class="faq-item">
            <h4>Can I edit my event after publishing?</h4>
            <p>Yes, you can edit your events anytime through the Events section. However, please note that major changes to published events should be communicated to registered participants.</p>
        </div>

        <div class="faq-item">
            <h4>How do I upload photos for activities?</h4>
            <p>When creating or editing an activity, you'll find an option to upload photos. You can upload up to 3 images with a total size limit of 5MB. Photos are mandatory for better engagement.</p>
        </div>

        <div class="faq-item">
            <h4>What's the difference between events and activities?</h4>
            <p>Events are time-bound occasions with specific dates, while activities represent ongoing projects or participations that may span longer periods and have different statuses.</p>
        </div>
    </div>
</section>

<?php include('footer.php'); ?>
