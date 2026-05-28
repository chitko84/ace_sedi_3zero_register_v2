    <!-- Bootstrap Bundle (with Popper) -->
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/theme.js"></script>

    <div class="modal fade" id="sharedDeleteConfirmModal" tabindex="-1" aria-labelledby="sharedDeleteConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="sharedDeleteConfirmModalLabel">Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2" id="sharedDeleteConfirmMessage">Are you sure you want to delete this item?</p>
                    <p class="fw-semibold mb-0" id="sharedDeleteConfirmItem"></p>
                    <p class="text-muted small mt-3 mb-0">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="sharedDeleteConfirmBtn">
                        <i class="fa-solid fa-trash me-1"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

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

    // Shared delete confirmation modal
    (function(){
        const modalEl = document.getElementById('sharedDeleteConfirmModal');
        const confirmBtn = document.getElementById('sharedDeleteConfirmBtn');
        const titleEl = document.getElementById('sharedDeleteConfirmModalLabel');
        const messageEl = document.getElementById('sharedDeleteConfirmMessage');
        const itemEl = document.getElementById('sharedDeleteConfirmItem');
        if (!modalEl || !confirmBtn || !titleEl || !messageEl || !itemEl) return;

        const modal = new bootstrap.Modal(modalEl);
        let pendingLink = null;
        let pendingForm = null;
        let pendingSubmitter = null;

        function applyContent(trigger) {
            titleEl.textContent = trigger.dataset.deleteTitle || 'Confirm Delete';
            messageEl.textContent = trigger.dataset.deleteMessage || 'Are you sure you want to delete this item?';
            itemEl.textContent = trigger.dataset.deleteItem || '';
            confirmBtn.innerHTML = trigger.dataset.deleteConfirmLabel || '<i class="fa-solid fa-trash me-1"></i> Delete';
        }

        document.addEventListener('click', function(event) {
            const link = event.target.closest('a.js-delete-confirm');
            if (!link) return;
            event.preventDefault();
            pendingLink = link.href;
            pendingForm = null;
            pendingSubmitter = null;
            applyContent(link);
            modal.show();
        });

        document.addEventListener('submit', function(event) {
            const form = event.target.closest('form.js-delete-confirm');
            if (!form || form.dataset.deleteConfirmed === 'true') return;
            event.preventDefault();
            pendingLink = null;
            pendingForm = form;
            pendingSubmitter = event.submitter || null;
            applyContent(form);
            modal.show();
        });

        confirmBtn.addEventListener('click', function() {
            if (pendingLink) {
                window.location.href = pendingLink;
                return;
            }
            if (pendingForm) {
                pendingForm.dataset.deleteConfirmed = 'true';
                if (pendingSubmitter && typeof pendingForm.requestSubmit === 'function') {
                    pendingForm.requestSubmit(pendingSubmitter);
                } else {
                    pendingForm.submit();
                }
            }
        });
    })();

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
