// Newsletter plugin: handles the subscription checkbox toggle in the
// auth dropdown. Posts to the newsletter-toggle API action via the
// admin API endpoint (for CSRF-free public use, we use a dedicated route).
(function () {
    "use strict";

    function init() {
        var checkboxes = document.querySelectorAll('.auth-newsletter-checkbox');
        if (checkboxes.length === 0) return;

        var baseUrl = (document.querySelector('base') || {}).href || '';
        var apiBase = baseUrl ? baseUrl.replace(/\/$/, '') + '/newsletter-toggle' : 'newsletter-toggle';

        checkboxes.forEach(function (checkbox) {
            // Find the error message element near this checkbox
            var errorEl = checkbox.closest('.auth-newsletter-label')
                ?.parentElement?.querySelector('.auth-newsletter-error');

            checkbox.addEventListener('change', function () {
                var userId = parseInt(checkbox.dataset.userId, 10);
                var subscribe = checkbox.checked;

                // Clear any previous error
                if (errorEl) {
                    errorEl.hidden = true;
                    errorEl.textContent = '';
                }

                fetch(apiBase, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        subscribe: subscribe,
                        user_id: userId
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        // Revert on failure and show the error message
                        checkbox.checked = !subscribe;
                        if (errorEl && data.error) {
                            errorEl.textContent = data.error;
                            errorEl.hidden = false;
                        }
                    }
                })
                .catch(function () {
                    // Revert on network error
                    checkbox.checked = !subscribe;
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
