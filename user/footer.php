    <!-- REQUIRED: Bootstrap JS with Popper (bundle includes Popper) -->
    <link href="../assets/css/dark-mode.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
    // Shared delete confirmation modal
    (function () {
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

        document.addEventListener('click', function (event) {
            const link = event.target.closest('a.js-delete-confirm');
            if (!link) return;
            event.preventDefault();
            pendingLink = link.href;
            pendingForm = null;
            pendingSubmitter = null;
            applyContent(link);
            modal.show();
        });

        document.addEventListener('submit', function (event) {
            const form = event.target.closest('form.js-delete-confirm');
            if (!form || form.dataset.deleteConfirmed === 'true') return;
            event.preventDefault();
            pendingLink = null;
            pendingForm = form;
            pendingSubmitter = event.submitter || null;
            applyContent(form);
            modal.show();
        });

        confirmBtn.addEventListener('click', function () {
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
