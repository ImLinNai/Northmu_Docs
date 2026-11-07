(function () {
    var drawer = document.getElementById('noticeFormDrawer');
    if (!drawer) {
        return;
    }

    if (drawer.getAttribute('data-notice-open') === '1' && window.bootstrap) {
        window.bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
    }

    drawer.addEventListener('hidden.bs.offcanvas', function () {
        var path = window.location.pathname.replace(/\/+$/, '');
        if (path === '/admin/notices/create' || /^\/admin\/notices\/edit\/\d+$/.test(path)) {
            window.history.replaceState({}, '', '/admin/notices');
        }
    });

    var popupSwitch = document.getElementById('noticeShowPopup');
    var cooldown = document.querySelector('[data-notice-cooldown]');
    if (popupSwitch && cooldown) {
        var syncCooldown = function () {
            cooldown.disabled = !popupSwitch.checked;
            cooldown.closest('.mb-3').classList.toggle('is-muted', !popupSwitch.checked);
        };
        popupSwitch.addEventListener('change', syncCooldown);
        syncCooldown();
    }
})();
