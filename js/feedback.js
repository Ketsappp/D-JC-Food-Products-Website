(function () {
    "use strict";

    var form = document.getElementById("feedbackForm");
    if (!form) return;

    var token = form.dataset.token;
    var comment = document.getElementById("fbComment");
    var counter = document.getElementById("fbCount");
    var msg = document.getElementById("fbMsg");
    var del = document.getElementById("fbDelete");

    function updateCount() { counter.textContent = comment.value.length; }
    comment.addEventListener("input", updateCount);
    updateCount();

    function say(text, bad) {
        msg.hidden = false;
        msg.textContent = text;
        msg.className = "fb-msg " + (bad ? "bad" : "ok");
    }

    function post(url, body) {
        body.token = token;
        return fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function done(result) {
        if (!result.success) { say(result.message, true); return; }
        say(result.message, false);
        setTimeout(function () {
            window.location.hash = "feedback";
            window.location.reload();
        }, 700);
    }

    form.addEventListener("submit", function (e) {
        e.preventDefault();

        var checked = form.querySelector('input[name="rating"]:checked');
        if (!checked) { say("Please choose a star rating.", true); return; }
        if (comment.value.trim().length < 5) { say("Please write at least 5 characters.", true); return; }

        var btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;

        post("feedback_save.php", { rating: Number(checked.value), comment: comment.value })
            .then(done)
            .catch(function () { say("Something went wrong. Please try again.", true); })
            .then(function () { btn.disabled = false; });
    });

    if (del) {
        del.addEventListener("click", function () {
            if (!window.confirm("Delete your feedback?")) return;
            post("feedback_delete.php", {}).then(done).catch(function () { say("Something went wrong. Please try again.", true); });
        });
    }
})();
