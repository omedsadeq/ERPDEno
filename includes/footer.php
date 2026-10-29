                </div><!-- /.page-content -->
            </div><!-- /.content-area -->
        </div><!-- /.app-container -->

        <!-- Status Bar -->
        <div class="statusbar">
            <div class="statusbar-section">
                <div class="statusbar-item">
                    <span class="statusbar-dot online"></span>
                    <span>متصل</span>
                </div>
                <div class="statusbar-item">
                    <i class="fas fa-user"></i>
                    <span><?= htmlspecialchars($currentUser['full_name']) ?></span>
                </div>
            </div>
            <div class="statusbar-section">
                <div class="statusbar-item">
                    <i class="far fa-clock"></i>
                    <span id="statusbarTime"><?= date('H:i:s') ?></span>
                </div>
                <div class="statusbar-item">
                    <span>v<?= APP_VERSION ?></span>
                </div>
            </div>
        </div>

    </div><!-- /.app-window -->

    <!-- JavaScript Libraries -->
    <script src="<?= APP_URL ?>/assets/vendor/jquery/jquery.min.js"></script>
    <script src="<?= APP_URL ?>/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>

    <!-- Notification System JS -->
    <script>window.APP_URL = '<?= APP_URL ?>';</script>
    <script src="<?= APP_URL ?>/assets/js/notifications.js"></script>

    <!-- Global Image Lightbox (auto-binds to x-ray images, .zoomable, [data-lightbox]) -->
    <script defer src="<?= APP_URL ?>/assets/js/lightbox.js"></script>

    <!-- Main JavaScript -->
    <script>
        // Base URL
        const APP_URL = '<?= APP_URL ?>';

        // Toggle Collapsible Nav Group (accordion-style)
        function toggleNavGroup(header) {
            const group = header.parentElement;
            const isOpening = !group.classList.contains('open');
            const items = group.querySelector('.nav-group-items');

            // Accordion: close other open groups (optional - keeps sidebar compact)
            if (isOpening) {
                document.querySelectorAll('.nav-group.open').forEach(function(otherGroup) {
                    if (otherGroup !== group) {
                        const otherItems = otherGroup.querySelector('.nav-group-items');
                        otherItems.style.maxHeight = otherItems.scrollHeight + 'px';
                        requestAnimationFrame(function() {
                            otherItems.style.maxHeight = '0px';
                        });
                        otherGroup.classList.remove('open');
                    }
                });
            }

            // Animate the clicked group
            if (isOpening) {
                group.classList.add('open');
                items.style.maxHeight = '0px';
                requestAnimationFrame(function() {
                    items.style.maxHeight = items.scrollHeight + 'px';
                    // After animation, remove inline style so CSS handles it
                    setTimeout(function() { items.style.maxHeight = ''; }, 350);
                });
            } else {
                items.style.maxHeight = items.scrollHeight + 'px';
                requestAnimationFrame(function() {
                    items.style.maxHeight = '0px';
                });
                group.classList.remove('open');
            }

            // Save state to localStorage
            const allGroups = document.querySelectorAll('.nav-group');
            const openGroups = {};
            allGroups.forEach(function(g, i) {
                openGroups[i] = g.classList.contains('open');
            });
            localStorage.setItem('navGroupsOpen', JSON.stringify(openGroups));
        }

        // Toggle Navigation Pane
        function toggleNavPane() {
            const navPane = document.getElementById('navPane');
            navPane.classList.toggle('compact');
            localStorage.setItem('navPaneCompact', navPane.classList.contains('compact'));
        }

        // Toggle Mobile Navigation
        function toggleMobileNav() {
            const navPane = document.getElementById('navPane');
            const overlay = document.getElementById('mobileNavOverlay');
            navPane.classList.toggle('open');
            overlay.classList.toggle('open');
        }

        // Toggle Notifications Dialog
        function toggleNotifications() {
            const dialog = document.getElementById('notificationDialog');
            dialog.classList.toggle('open');
        }

        function closeNotifications() {
            document.getElementById('notificationDialog').classList.remove('open');
        }

        // Toggle User Context Menu
        function toggleUserMenu() {
            const menu = document.getElementById('userContextMenu');
            if (menu.style.display === 'none' || !menu.style.display) {
                menu.style.display = 'block';
            } else {
                menu.style.display = 'none';
            }
        }

        // Initialize Navigation Pane state
        document.addEventListener('DOMContentLoaded', function() {
            const navPaneCompact = localStorage.getItem('navPaneCompact') === 'true';
            if (navPaneCompact && window.innerWidth > 767) {
                document.getElementById('navPane')?.classList.add('compact');
            }

            // Restore saved nav group states (only for groups not auto-opened by active page)
            const openGroups = JSON.parse(localStorage.getItem('navGroupsOpen') || '{}');
            document.querySelectorAll('.nav-group').forEach(function(group, index) {
                // If group has an active child, it's already open via PHP - skip localStorage
                if (group.querySelector('.nav-item.active')) return;
                if (openGroups[index] === true) {
                    group.classList.add('open');
                } else if (openGroups[index] === false) {
                    group.classList.remove('open');
                }
            });

            // Scroll active item into view smoothly
            const activeItem = document.querySelector('.nav-group-items .nav-item.active');
            if (activeItem) {
                setTimeout(function() {
                    activeItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }, 400);
            }

            // Close user menu on outside click
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.nav-user') && !e.target.closest('#userContextMenu')) {
                    document.getElementById('userContextMenu').style.display = 'none';
                }
            });
        });

        // Global Search
        document.getElementById('globalSearch')?.addEventListener('input', function() {
            const query = this.value.trim();
            if (query.length >= 2) {
                performGlobalSearch(query);
            }
        });

        async function performGlobalSearch(query) {
            try {
                const response = await fetch(`${APP_URL}/api/search.php?q=${encodeURIComponent(query)}`);
                const data = await response.json();
                console.log(data);
            } catch (error) {
                console.error('Search error:', error);
            }
        }

        // Confirm Delete
        function confirmDelete(message, callback) {
            Swal.fire({
                title: 'دڵنیایت؟',
                text: message || 'ئەم کردارە ناگەڕێتەوە',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#C42B1C',
                cancelButtonColor: '#5C5C5C',
                confirmButtonText: 'بەڵێ، بیسڕەوە',
                cancelButtonText: 'نەخێر'
            }).then((result) => {
                if (result.isConfirmed && typeof callback === 'function') {
                    callback();
                }
            });
        }

        // Show Alert
        function showAlert(type, message, title = '') {
            const icons = {
                success: 'success',
                error: 'error',
                warning: 'warning',
                info: 'info'
            };

            Swal.fire({
                title: title || (type === 'success' ? 'سەرکەوتوو' : type === 'error' ? 'هەڵە' : 'ئاگادارکردنەوە'),
                text: message,
                icon: icons[type] || 'info',
                confirmButtonText: 'باشە',
                confirmButtonColor: 'var(--win-accent, #0078D4)'
            });
        }

        // Show Toast
        function showToast(type, message) {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });

            Toast.fire({
                icon: type,
                title: message
            });
        }

        // Format Number
        function formatNumber(num) {
            return new Intl.NumberFormat('ar-IQ').format(num);
        }

        // Format Currency
        function formatCurrency(amount) {
            return formatNumber(amount);
        }

        // Format Date
        function formatDate(date) {
            return new Date(date).toLocaleDateString('ar-IQ');
        }

        // Loading State
        function showLoading() {
            document.body.insertAdjacentHTML('beforeend',
                '<div class="loading-overlay" id="loadingOverlay"><div class="spinner"></div></div>');
        }

        function hideLoading() {
            document.getElementById('loadingOverlay')?.remove();
        }

        // AJAX Form Submit
        async function submitForm(form, successCallback) {
            const formData = new FormData(form);
            showLoading();

            try {
                const response = await fetch(form.action, {
                    method: form.method || 'POST',
                    body: formData
                });

                const data = await response.json();
                hideLoading();

                if (data.success) {
                    showToast('success', data.message || 'سەرکەوتوو بوو');
                    if (typeof successCallback === 'function') {
                        successCallback(data);
                    }
                } else {
                    showAlert('error', data.message || 'هەڵەیەک ڕوویدا');
                }
            } catch (error) {
                hideLoading();
                showAlert('error', 'هەڵەی تەکنیکی ڕوویدا');
                console.error(error);
            }
        }

        // Tab functionality
        function initTabs() {
            document.querySelectorAll('.tab, .ribbon-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabGroup = this.closest('.tabs-container') || this.closest('.ribbon-tabs');
                    const targetId = this.dataset.tab;

                    // Update active tab
                    tabGroup.querySelectorAll('.tab, .ribbon-tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');

                    // Update content if specified
                    if (targetId) {
                        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                        document.getElementById(targetId)?.classList.add('active');
                    }
                });
            });
        }

        // Responsive table cards for mobile. Uses existing table headers as labels.
        function initResponsiveTables(root = document) {
            const tables = root.querySelectorAll('.page-content table:not(.no-responsive):not([data-responsive-ready])');

            tables.forEach(table => {
                if (table.parentElement && table.parentElement.closest('table')) return;
                if (table.dataset.noResponsive === 'true') return;

                const headerRow = table.tHead?.rows?.[0] || Array.from(table.rows).find(row => row.querySelectorAll('th').length > 0);
                if (!headerRow) return;

                const headerCells = Array.from(headerRow.children);
                const labels = headerCells.map(cell => cell.textContent.replace(/\s+/g, ' ').trim());
                if (labels.filter(Boolean).length < 2) return;

                table.classList.add('responsive-table');
                table.dataset.responsiveReady = 'true';

                if (!table.closest('.table-container, .responsive-table-wrapper, .report-table-container, .invoices-table-container, .ph-table-container, .expense-table-container') && table.parentElement && table.parentElement.tagName !== 'TD') {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'responsive-table-wrapper';
                    table.parentNode.insertBefore(wrapper, table);
                    wrapper.appendChild(table);
                }

                const rows = table.tBodies.length
                    ? Array.from(table.tBodies).flatMap(tbody => Array.from(tbody.rows))
                    : Array.from(table.rows).filter(row => row !== headerRow && !row.closest('thead'));

                rows.forEach(row => {
                    let labelIndex = 0;
                    Array.from(row.cells).forEach(cell => {
                        if (cell.tagName !== 'TD') return;
                        const label = cell.dataset.label || labels[labelIndex] || '';
                        if (label) {
                            cell.dataset.label = label;
                            cell.classList.remove('responsive-empty-label');
                        } else {
                            cell.classList.add('responsive-empty-label');
                        }
                        labelIndex += cell.colSpan || 1;
                    });
                });
            });
        }

        function initResponsiveShell() {
            document.querySelectorAll('#navPane .nav-item').forEach(link => {
                link.addEventListener('click', () => {
                    if (window.innerWidth > 767) return;
                    document.getElementById('navPane')?.classList.remove('open');
                    document.getElementById('mobileNavOverlay')?.classList.remove('open');
                });
            });

            let responsiveTableTimer = null;
            const pageContent = document.querySelector('.page-content');
            if (pageContent && 'MutationObserver' in window) {
                const observer = new MutationObserver(() => {
                    clearTimeout(responsiveTableTimer);
                    responsiveTableTimer = setTimeout(() => initResponsiveTables(pageContent), 80);
                });
                observer.observe(pageContent, { childList: true, subtree: true });
            }
        }

        window.RioResponsive = {
            initTables: initResponsiveTables
        };

        // Dialog Functions
        function openDialog(dialogId) {
            document.getElementById(dialogId)?.classList.add('open');
        }

        function closeDialog(dialogId) {
            document.getElementById(dialogId)?.classList.remove('open');
        }

        // Legacy Modal Functions (for compatibility)
        function openModal(modalId) {
            openDialog(modalId);
        }

        function closeModal(modalId) {
            closeDialog(modalId);
        }

        // Update Time Displays
        function updateTime() {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');

            // Update current time in ribbon
            const currentTime = document.getElementById('currentTime');
            if (currentTime) {
                currentTime.textContent = `${hours}:${minutes}`;
            }

            // Update statusbar time
            const statusbarTime = document.getElementById('statusbarTime');
            if (statusbarTime) {
                statusbarTime.textContent = `${hours}:${minutes}:${seconds}`;
            }
        }

        // PWA: clear service-worker caches on logout (defense-in-depth —
        // the SW never caches PHP/patient data, only static assets)
        document.addEventListener('click', function(e) {
            const logoutLink = e.target.closest('a[href*="logout.php"]');
            if (logoutLink && navigator.serviceWorker && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_CACHES' });
            }
        }, true);

        // Initialize on DOM ready
        document.addEventListener('DOMContentLoaded', function() {
            initTabs();
            initResponsiveTables();
            initResponsiveShell();

            // Update time every second
            updateTime();
            setInterval(updateTime, 1000);

            // Auto-hide alerts
            document.querySelectorAll('.alert[data-auto-hide]').forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });

            // Close dialogs on backdrop click
            document.querySelectorAll('.dialog-overlay').forEach(overlay => {
                overlay.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('open');
                    }
                });
            });
        });
    </script>

    <!-- «کەیس» from any page (includes/case-button.php): a doctor goes straight in -->
    <script>window.CASE_OPEN = { url: '<?= APP_URL ?>/cases/open.php', direct: <?= (isset($auth) && $auth->isDoctor() && !$auth->isAdmin()) ? 'true' : 'false' ?> };</script>
    <script src="<?= APP_URL ?>/assets/js/open-case.js?v=<?= @filemtime(__DIR__ . '/../assets/js/open-case.js') ?>"></script>

    <!-- Custom Page JavaScript -->
    <?php if (isset($customJS)): ?>
    <script><?= $customJS ?></script>
    <?php endif; ?>
</body>
</html>
