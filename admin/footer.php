    <!-- Bootstrap Bundle (with Popper) -->
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/theme.js"></script>

    <script>
    function markNotificationsAsRead() {
        // Remove the badge immediately for better UX
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            badge.style.display = 'none';
        }
        
        // Send AJAX request to mark notifications as read
        fetch('?mark_read=1', {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).catch(error => {
            console.log('Notifications marked as read');
        });
    }

    // Main app functionality
    (function(){
        const sidebarBtn = document.getElementById('btnSidebar');
        const sidebar    = document.getElementById('sidebar');
        const overlay    = document.getElementById('overlay');

        function toggleSidebar(){
            if(!sidebar) return;
            const showing = sidebar.classList.toggle('show');
            if (overlay) overlay.classList.toggle('show', showing);
            document.body.style.overflow = showing ? 'hidden' : '';
        }

        if (sidebarBtn) sidebarBtn.addEventListener('click', toggleSidebar);
        if (overlay) overlay.addEventListener('click', toggleSidebar);

        document.querySelectorAll('.menu a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 992 && sidebar?.classList.contains('show')) {
                    sidebar.classList.remove('show');
                    overlay?.classList.remove('show');
                    document.body.style.overflow = '';
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && sidebar?.classList.contains('show')) {
                sidebar.classList.remove('show');
                overlay?.classList.remove('show');
                document.body.style.overflow = '';
            }
        });

        // Close on resize back to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar?.classList.remove('show');
                overlay?.classList.remove('show');
                document.body.style.overflow = '';
            }
        });

        // Ensure header doesn't clip dropdowns
        const headerEl = document.querySelector('.admin-header');
        if (headerEl) headerEl.style.overflow = 'visible';

        // Initialize all dropdowns with autoClose 'outside'
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(el => {
            new bootstrap.Dropdown(el, { autoClose: 'outside', display: 'static' });
        });

        // Add click handler to notification bell for marking as read
        const bellBtn = document.getElementById('ddNotifs');
        if (bellBtn) {
            // Add click event for marking notifications as read
            bellBtn.addEventListener('click', markNotificationsAsRead);
            
            // Keyboard toggle for bell
            bellBtn.addEventListener('keydown', (e)=>{
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    const dd = bootstrap.Dropdown.getOrCreateInstance(bellBtn, { autoClose: 'outside', display: 'static' });
                    dd.toggle();
                    markNotificationsAsRead();
                }
            });
        }

        // Active link safety (in case PHP helper missed due to routes)
        const current = (location.pathname.split('/').pop() || 'dashboard.php').toLowerCase();
        document.querySelectorAll('.menu a').forEach(a=>{
            const href = (a.getAttribute('href')||'').split('?')[0].toLowerCase();
            if (href === current) a.classList.add('active');
        });
    })();
    </script>
</body>
</html>
