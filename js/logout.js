
document.addEventListener('DOMContentLoaded', function () {

    // ---------------- Logout confirmation modal ----------------
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutModal = document.getElementById('logoutModal');
    const cancelLogout = document.getElementById('cancelLogout');

    if (logoutBtn && logoutModal) {
        logoutBtn.addEventListener('click', function () {
            logoutModal.classList.remove('hidden');
        });

        if (cancelLogout) {
            cancelLogout.addEventListener('click', function (e) {
                e.preventDefault();
                logoutModal.classList.add('hidden');
            });
        }

        logoutModal.addEventListener('click', function (e) {
            if (e.target === logoutModal) {
                logoutModal.classList.add('hidden');
            }
        });
    }
});
