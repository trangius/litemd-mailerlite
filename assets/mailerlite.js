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
            checkbox.addEventListener('change', function () {
                var userId = parseInt(checkbox.dataset.userId, 10);
                var subscribe = checkbox.checked;

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
                        // Revert on failure
                        checkbox.checked = !subscribe;
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
