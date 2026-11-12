document.addEventListener('DOMContentLoaded', function () {
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutModal = document.getElementById('logoutModal');
    const cancelLogout = document.getElementById('cancelLogout');

    if (!logoutBtn || !logoutModal) return;

    logoutBtn.addEventListener('click', function () {
        logoutModal.classList.remove('hidden');
    });

    cancelLogout.addEventListener('click', function (e) {
        e.preventDefault();
        logoutModal.classList.add('hidden');
    });

    // Clicking outside the modal box also cancels.
    logoutModal.addEventListener('click', function (e) {
        if (e.target === logoutModal) {
            logoutModal.classList.add('hidden');
        }
    });
});
