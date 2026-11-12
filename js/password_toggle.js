// Show / Hide for password fields: <button class="toggle-pw" data-target="inputId">Show</button>
(function () {
    "use strict";
    document.querySelectorAll(".toggle-pw").forEach(function (btn) {
        var input = document.getElementById(btn.dataset.target);
        if (!input) return;
        btn.setAttribute("aria-label", "Show password");
        btn.addEventListener("click", function () {
            var show = input.type === "password";
            input.type = show ? "text" : "password";
            btn.textContent = show ? "Hide" : "Show";
            btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
            input.focus();
        });
    });
})();
