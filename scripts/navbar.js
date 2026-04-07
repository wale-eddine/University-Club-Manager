(function () {
    // Menu toggle and navigation links
    const toggle = document.querySelector('.menu-toggle');
    const navLinks = document.querySelector('.nav-links');
    if (!toggle || !navLinks) return;

    // SECTION 1: NOTIFICATIONS & BELL ICON
    const dashboardNavLink = navLinks.querySelector('a[href$="dashboard.php"]');
    if (dashboardNavLink) {
        const dashboardHref = dashboardNavLink.getAttribute('href');
        const notifDataUrl = (dashboardHref || 'dashboard.php').replace(/dashboard\.php(?:\?.*)?$/, 'notif_data.php');
        let currentNotifications = [];

        // Close notifications modal
        const closeNotifModal = function () {
            const modal = document.getElementById('empty-notif-modal');
            if (modal) modal.classList.remove('is-open');
        };

        // Create or get notification modal
        const ensureNotifModal = function () {
            let modal = document.getElementById('empty-notif-modal');
            if (modal) return modal;

            // Build modal HTML structure
            modal = document.createElement('div');
            modal.id = 'empty-notif-modal';
            modal.className = 'empty-notif-modal';
            modal.innerHTML =
                '<div class="empty-notif-modal-backdrop"></div>' +
                '<div class="empty-notif-modal-content" role="dialog" aria-modal="true" aria-labelledby="empty-notif-title">' +
                    '<h3 id="empty-notif-title">Notifications</h3>' +
                    '<div id="empty-notif-body"></div>' +
                    '<div class="empty-notif-modal-actions">' +
                        '<button type="button" class="btn btn-primary" id="empty-notif-ok">OK</button>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(modal);

            const okBtn = modal.querySelector('#empty-notif-ok');
            const backdrop = modal.querySelector('.empty-notif-modal-backdrop');

            // OK button closes modal and clears notifications
            if (okBtn) {
                okBtn.addEventListener('click', function () {
                    closeNotifModal();
                    if (currentNotifications.length > 0) {
                        fetch(notifDataUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'action=clear'
                        }).finally(function () {
                            currentNotifications = [];
                            refreshBellBadges(0);
                        });
                    }
                });
            }

            if (backdrop) {
                backdrop.addEventListener('click', closeNotifModal);
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeNotifModal();
                }
            });

            return modal;
        };

        // Render modal content for current notifications list.
        const renderNotifBody = function (notifications) {
            const modal = ensureNotifModal();
            const body = modal.querySelector('#empty-notif-body');
            if (!body) {
                return;
            }

            if (!notifications || notifications.length === 0) {
                body.innerHTML = '<p>Aucune notification pour le moment.</p>';
                return;
            }

            let html = '<ul class="notif-modal-list">';
            notifications.forEach(function (notif) {
                const title = (notif && notif.title) ? String(notif.title) : 'Notification';
                const message = (notif && notif.message) ? String(notif.message) : '';
                html += '<li class="notif-modal-item">' +
                    '<strong class="notif-modal-title"></strong>' +
                    '<p class="notif-modal-message"></p>' +
                '</li>';
            });
            html += '</ul>';
            body.innerHTML = html;

            const titleEls = body.querySelectorAll('.notif-modal-title');
            const msgEls = body.querySelectorAll('.notif-modal-message');
            notifications.forEach(function (notif, idx) {
                if (titleEls[idx]) {
                    titleEls[idx].textContent = (notif && notif.title) ? String(notif.title) : 'Notification';
                }
                if (msgEls[idx]) {
                    msgEls[idx].textContent = (notif && notif.message) ? String(notif.message) : '';
                }
            });
        };

        // Sync desktop and mobile bell badge counters.
        const refreshBellBadges = function (count) {
            const safeCount = Math.max(0, parseInt(count, 10) || 0);
            document.querySelectorAll('.js-injected-bell').forEach(function (bellLink) {
                const existingCount = bellLink.querySelector('.decision-bell-count');

                if (safeCount > 0) {
                    bellLink.classList.add('has-notifs');
                    if (existingCount) {
                        existingCount.textContent = String(safeCount);
                    } else {
                        const countEl = document.createElement('span');
                        countEl.className = 'decision-bell-count';
                        countEl.textContent = String(safeCount);
                        bellLink.appendChild(countEl);
                    }
                } else {
                    bellLink.classList.remove('has-notifs');
                    if (existingCount) {
                        existingCount.remove();
                    }
                }
            });
        };

        // Load notification count and entries from backend.
        const loadNotifData = function () {
            return fetch(notifDataUrl, { credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        return { count: 0, notifications: [] };
                    }
                    return response.json();
                })
                .then(function (data) {
                    const notifications = Array.isArray(data && data.notifications) ? data.notifications : [];
                    return {
                        count: Math.max(0, parseInt((data && data.count) || notifications.length || 0, 10) || 0),
                        notifications: notifications
                    };
                })
                .catch(function () {
                    return { count: 0, notifications: [] };
                });
        };

        // Build a bell action link for desktop or mobile nav.
        const createBellLink = function (isMobile, notifCount) {
            const bellLink = document.createElement('a');
            bellLink.href = '#';
            bellLink.className = 'decision-bell-link js-injected-bell' + (isMobile ? ' mobile-bell-link' : '');
            bellLink.setAttribute('aria-label', 'Notifications de demandes');
            bellLink.title = 'Notifications de demandes';

            bellLink.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                loadNotifData().then(function (data) {
                    currentNotifications = data.notifications || [];
                    refreshBellBadges(data.count || 0);
                    renderNotifBody(currentNotifications);
                    ensureNotifModal().classList.add('is-open');
                });
            });

            if (notifCount > 0) {
                bellLink.classList.add('has-notifs');
            }

            const icon = document.createElement('span');
            icon.className = 'decision-bell-icon';
            icon.innerHTML = '&#128276;';
            bellLink.appendChild(icon);

            if (notifCount > 0) {
                const count = document.createElement('span');
                count.className = 'decision-bell-count';
                count.textContent = String(notifCount);
                bellLink.appendChild(count);
            }

            return bellLink;
        };

        // Inject bell links into nav once data is available.
        loadNotifData().then(function (data) {
            const notifCount = data.count || 0;
            currentNotifications = data.notifications || [];

            if (!navLinks.querySelector('.decision-bell-item')) {
                const bellItem = document.createElement('li');
                bellItem.className = 'decision-bell-item';
                bellItem.appendChild(createBellLink(false, notifCount));

                const profileOrAccountLi = navLinks.querySelector('.profile-menu, .account-menu');
                if (profileOrAccountLi && profileOrAccountLi.parentNode) {
                    profileOrAccountLi.parentNode.insertBefore(bellItem, profileOrAccountLi);
                } else {
                    const dashboardLi = dashboardNavLink.closest('li');
                    if (dashboardLi && dashboardLi.parentNode) {
                        dashboardLi.parentNode.insertBefore(bellItem, dashboardLi.nextSibling);
                    }
                }
            }

            if (!document.querySelector('.mobile-bell-link')) {
                const existingMobileActions = document.querySelector('.mobile-nav-actions');
                if (existingMobileActions) {
                    existingMobileActions.insertBefore(createBellLink(true, notifCount), existingMobileActions.firstChild);
                } else {
                    const actions = document.createElement('div');
                    actions.className = 'mobile-nav-actions';
                    actions.appendChild(createBellLink(true, notifCount));

                    const toggleParent = toggle.parentNode;
                    if (toggleParent) {
                        toggleParent.insertBefore(actions, toggle);
                        actions.appendChild(toggle);
                    }
                }
            }
        });
    }

    // Detect whether nav should use mobile behavior.
    const isMobileView = function () {
        return window.innerWidth <= 768;
    };

    // Collect profile/account dropdown menus for shared handling.
    const dropdownMenus = Array.from(navLinks.querySelectorAll('.profile-menu, .account-menu'));

    // Add explicit mobile toggle buttons after dropdown triggers.
    dropdownMenus.forEach(function (menu) {
        const trigger = menu.querySelector('.profile-trigger, .account-trigger');
        if (!trigger || menu.querySelector('.menu-dropdown-toggle')) {
            return;
        }

        const toggleButton = document.createElement('button');
        toggleButton.type = 'button';
        toggleButton.className = 'menu-dropdown-toggle';
        toggleButton.setAttribute('aria-label', 'Afficher le sous-menu');
        toggleButton.setAttribute('aria-expanded', 'false');
        toggleButton.innerHTML = '&#9662;';
        trigger.insertAdjacentElement('afterend', toggleButton);
    });

    // Close all dropdowns except an optional target menu.
    const closeDropdowns = function (exceptMenu) {
        dropdownMenus.forEach(function (menu) {
            const menuToggle = menu.querySelector('.menu-dropdown-toggle');
            if (exceptMenu && menu === exceptMenu) {
                if (menuToggle) {
                    menuToggle.setAttribute('aria-expanded', 'true');
                }
                return;
            }
            menu.classList.remove('is-open');
            if (menuToggle) {
                menuToggle.setAttribute('aria-expanded', 'false');
            }
        });
    };

    // Close mobile navigation and reset dropdown state.
    const closeMenu = function () {
        document.body.classList.remove('nav-open');
        toggle.setAttribute('aria-expanded', 'false');
        closeDropdowns();
    };

    // Open mobile navigation drawer.
    const openMenu = function () {
        document.body.classList.add('nav-open');
        toggle.setAttribute('aria-expanded', 'true');
    };

    // Toggle mobile menu open/close state.
    toggle.addEventListener('click', function () {
        if (document.body.classList.contains('nav-open')) {
            closeMenu();
            return;
        }

        openMenu();
    });

    // Close nav/dropdowns when clicking outside interactive areas.
    document.addEventListener('click', function (event) {
        const clickedToggle = event.target.closest('.menu-toggle');
        const clickedMenu = event.target.closest('.nav-links');

        if (!clickedToggle && !clickedMenu && document.body.classList.contains('nav-open')) {
            closeMenu();
        }

        if (isMobileView() && !event.target.closest('.profile-menu, .account-menu')) {
            closeDropdowns();
        }
    });

    // Handle dropdown expand/collapse from mobile toggle buttons.
    navLinks.querySelectorAll('.menu-dropdown-toggle').forEach(function (button) {
        button.addEventListener('click', function (event) {
            if (!isMobileView()) {
                return;
            }

            event.preventDefault();

            const menu = button.closest('.profile-menu, .account-menu');
            if (!menu) {
                return;
            }

            const willOpen = !menu.classList.contains('is-open');
            closeDropdowns(menu);
            if (willOpen) {
                menu.classList.add('is-open');
                button.setAttribute('aria-expanded', 'true');
            } else {
                menu.classList.remove('is-open');
                button.setAttribute('aria-expanded', 'false');
            }
        });
    });

    // Close menu after selecting any nav link.
    navLinks.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            closeMenu();
        });
    });

    // Reset mobile menu when switching to desktop width.
    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) {
            closeMenu();
        }
    });
})();
