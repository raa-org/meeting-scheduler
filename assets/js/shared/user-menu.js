/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Account (user) menu — shared across the schedule, booking and AI assistant
 * pages. Classic (non-module) script: toggles the dropdown, closes it on an
 * outside click, and wires the Logout button. Reads the logout nonce from
 * window.apexianlab_oauth2_vars.
 */
(function () {
  document.addEventListener('click', (e) => {
    // Toggle the dropdown.
    const toggleBtn = e.target.closest('.user-menu__toggle');
    if (toggleBtn) {
      e.stopPropagation();
      e.preventDefault();
      const dropdown = toggleBtn.closest('.user-menu')?.querySelector('.user-menu__dropdown');
      if (dropdown) dropdown.classList.toggle('active');
      return;
    }

    // Close every open dropdown when the click lands outside any user menu.
    if (!e.target.closest('.user-menu')) {
      document.querySelectorAll('.user-menu__dropdown').forEach((d) => d.classList.remove('active'));
    }

    // Logout.
    const logoutBtn = e.target.closest('.user-menu__logout');
    if (logoutBtn) {
      e.preventDefault();
      const logoutUrl = logoutBtn.getAttribute('data-logout-url');
      if (!logoutUrl) return;

      const body = new URLSearchParams();
      body.set('action', 'oauth2_logout');
      body.set('nonce', window.apexianlab_oauth2_vars?.logout_nonce || '');

      fetch(logoutUrl, { method: 'POST', body, credentials: 'same-origin' })
        .then((r) => r.json())
        .then((resp) => {
          if (resp?.success && resp.data?.redirect) {
            window.location.href = resp.data.redirect;
          } else {
            window.location.reload();
          }
        })
        .catch(() => window.location.reload());
    }
  });
})();
