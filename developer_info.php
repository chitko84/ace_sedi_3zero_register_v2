<?php include('header.php'); ?>

<!-- Compact Hero -->
<section class="hero hero--compact" style="min-height: 260px;">
  <div class="slideshow"><div class="slide slide-1 active"></div></div>
  <div class="hero-content">
    <h1 class="hero-title"><i class="fas fa-laptop-code"></i> Developer Info</h1>
    <p class="hero-subtitle" style="color:white;">System builder, contact & support details</p>
  </div>
</section>

<main>
  <!-- Developer Card -->
  <section class="features-section" style="padding-top: 32px; padding-bottom: 48px;">
    <div class="features-container">
      <div class="dev-card-wrap">
        <article class="dev-card" role="article" aria-label="Developer profile card">
          <!-- Top: Photo -->
          <div class="dev-photo">
            <!-- Replace with your actual path -->
            <img src="uploads/ckk.jpg" alt="Developer photo" loading="lazy">
          </div>

          <!-- Details -->
          <div class="dev-body">
            <h2 class="dev-name">Chit Ko Ko</h2>
            <p class="dev-role">Full-Stack Developer • 3ZERO Club Registration System</p>

            <ul class="dev-meta">
              <li><i class="fas fa-building"></i> ACE-SEDI / AIU</li>
              <li><i class="fas fa-map-marker-alt"></i> Alor Setar, Kedah, Malaysia</li>
              <li><i class="fas fa-envelope"></i> <a href="mailto:chitko.ko@student.aiu.edu.my">chitko.ko@student.aiu.edu.my</a></li>
              <li><i class="fas fa-envelope"></i> <a href="mailto:chitkoko20030408@gmail.com">chitkoko20030408@gmail.com</a></li>

            </ul>

            <!-- Support -->
            <div class="dev-support" role="region" aria-label="Support contact">
              <h3 class="support-title"><i class="fas fa-headset"></i> Support</h3>
              <p class="support-hours">
                <i class="far fa-clock"></i>
                <strong>Mon–Fri</strong>, <strong>9:00 AM – 5:30 PM</strong> (MYT)
              </p>
              <div class="support-actions">
                <a href="https://wa.me/601112476299" target="_blank" rel="noopener" class="cta-btn cta-btn-primary" aria-label="Contact on WhatsApp">
                  <i class="fab fa-whatsapp"></i> WhatsApp Support
                </a>
                <a href="mailto:chitko.ko@student.aiu.edu.my" class="cta-btn cta-btn-secondary" aria-label="Email the developer">
                  <i class="fas fa-envelope"></i> Email
                </a>
              </div>
              <p class="support-note">
                For urgent production issues, include <code>[URGENT]</code> in your message subject.
              </p>
            </div>
          </div>
        </article>
      </div>
    </div>
  </section>
</main>

<?php include('footer.php'); ?>

<!-- Scoped styles for this page only -->
<style>
  .hero.hero--compact .slideshow { opacity: .35; }
  .dev-card-wrap { display: grid; place-items: center; }
  .dev-card {
    width: 100%;
    max-width: 880px;
    background: var(--card-bg, #fff);
    border-radius: 20px;
    box-shadow: 0 8px 30px rgba(0,0,0,.08);
    overflow: hidden;
    display: grid;
    grid-template-columns: 360px 1fr;
  }
  .dev-photo {
    background: linear-gradient(180deg, rgba(0,0,0,.05), rgba(0,0,0,.02));
    display: grid;
    place-items: center;
    padding: 24px;
  }
  .dev-photo img {
    width: 100%;
    max-width: 300px;
    aspect-ratio: 1/1;
    object-fit: cover;
    border-radius: 16px;
    box-shadow: 0 6px 18px rgba(0,0,0,.12);
  }
  .dev-body {
    padding: 28px;
  }
  .dev-name {
    margin: 0 0 6px;
    font-size: clamp(1.4rem, 2.2vw, 1.8rem);
  }
  .dev-role {
    margin: 0 0 16px;
    opacity: .9;
  }
  .dev-meta {
    list-style: none;
    padding: 0;
    margin: 0 0 18px;
    display: grid;
    gap: 8px;
  }
  .dev-meta li i { width: 18px; }
  .dev-meta a { text-decoration: none; }
  .dev-support {
    margin-top: 8px;
    padding: 16px;
    border: 1px solid rgba(0,0,0,.08);
    border-radius: 14px;
    background: rgba(0,0,0,.02);
  }
  .support-title {
    display: flex; align-items: center; gap: 8px; margin: 0 0 8px;
    font-size: 1.1rem;
  }
  .support-hours { margin: 0 0 14px; }
  .support-actions { display: flex; gap: 12px; flex-wrap: wrap; }
  .cta-btn { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
  .support-note { margin: 10px 0 0; opacity: .85; font-size: .95rem; }

  /* Dark mode friendliness (if your theme uses variables, these will blend in) */
  @media (prefers-color-scheme: dark) {
    .dev-card { background: rgba(255,255,255,.06); }
    .dev-support { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.12); }
  }

  /* Responsive */
  @media (max-width: 900px) {
    .dev-card { grid-template-columns: 1fr; }
    .dev-photo { padding: 20px 20px 0; }
    .dev-photo img { max-width: 220px; }
  }
</style>
