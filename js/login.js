document.addEventListener('DOMContentLoaded', function () {

    const form = document.getElementById('loginForm');

    form.addEventListener('submit', function () {

        const username =
            document.getElementById('username').value.trim();

        const password =
            document.getElementById('password').value.trim();

        if (!username || !password) {
            return;
        }

        // PHP will handle the login and redirect.
    });

});