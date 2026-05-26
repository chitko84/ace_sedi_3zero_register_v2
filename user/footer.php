    <!-- REQUIRED: Bootstrap JS with Popper (bundle includes Popper) -->
    <link href="../assets/css/dark-mode.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/theme.js"></script>

    <script>
    // ===== App UI script (mobile menu + dropdowns) =====
    (function () {
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar       = document.getElementById('sidebar');
        const overlay       = document.getElementById('overlay');

        function toggleMenu() {
            const isActive = sidebar.classList.toggle('active');
            overlay.classList.toggle('active');

            // Prevent body scroll when menu is open
            document.body.style.overflow = isActive ? 'hidden' : '';
        }

        // Mobile menu open/close
        if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', toggleMenu);
        if (overlay) overlay.addEventListener('click', toggleMenu);

        // Close menu when clicking a link on mobile
        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 992 && sidebar.classList.contains('active')) {
                    toggleMenu();
                }
            });
        });

        // Handle window resize
        window.addEventListener('resize', function () {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Active page highlighting
        const currentPage = window.location.pathname.split('/').pop() || 'dashboard.php';
        document.querySelectorAll('.sidebar-menu a').forEach(item => {
            const href = item.getAttribute('href');
            if (href === currentPage) item.classList.add('active');
        });

        // --- Bootstrap 5 dropdowns (NO stopPropagation) ---
        document.addEventListener('DOMContentLoaded', function () {
            // Ensure header doesn't clip dropdown (safety)
            const headerEl = document.querySelector('.main-header');
            if (headerEl) headerEl.style.overflow = 'visible';

            // Initialize all dropdown toggles with autoClose 'outside'
            document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
                // If you didn't add data-bs-auto-close="outside" in HTML, this enforces it via JS
                new bootstrap.Dropdown(el, { autoClose: 'outside', display: 'static' });
            });

            // Defensive: also toggle programmatically on bell click (in case of custom markup)
            const bellBtn = document.getElementById('notificationDropdown');
            if (bellBtn) {
                bellBtn.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        const dd = bootstrap.Dropdown.getOrCreateInstance(bellBtn, { autoClose: 'outside', display: 'static' });
                        dd.toggle();
                    }
                });
            }
        });
    })();
    </script>
</body>
</html>
