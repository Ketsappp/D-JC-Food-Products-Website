/**
 * Login / Create an Account pop-up on the landing page.
 *
 * The pop-up's HTML comes from php/auth_modal.php. This script makes it behave:
 *
 *  - any link with data-auth-open="login" | "register" opens it, centered,
 *    over a blurred page (the links still work as normal links without JS);
 *  - "Create an Account" / "Log in" / "Forgot Password?" inside it switch tabs
 *    without reloading;
 *  - closes with the X, a click outside the card, or the Escape key;
 *  - keeps keyboard focus inside the card while it is open, and gives focus
 *    back to the button that opened it;
 *  - Show / Hide for passwords, and a quick check on the sign-up form
 *    (the server checks everything again).
 */
(function () {
    'use strict';

    var overlay = document.getElementById('authModal');

    if (!overlay) return;

    var dialog = overlay.querySelector('.am-dialog');
    var closeLink = document.getElementById('amClose');
    var panels = {
        login: document.getElementById('amLogin'),
        register: document.getElementById('amRegister'),
        forgot: document.getElementById('amForgot')
    };

    var termsModal = document.getElementById('amTermsModal');
    var openTermsLink = document.getElementById('amOpenTerms');
    var closeTermsBtn = document.getElementById('amCloseTerms');

    var lastTrigger = null;
    var lastTermsTrigger = null;

    // Query-string keys that only exist to open the pop-up; removed once it closes.
    var URL_KEYS = ['auth', 'registered', 'verified', 'reset', 'email', 'next'];

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
        'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function isOpen() {
        return overlay.classList.contains('is-open');
    }

    function visible(element) {
        return !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    }

    function focusables(root) {
        return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), visible);
    }

    function cleanUrl() {
        if (!window.history || !history.replaceState) return;

        try {
            var url = new URL(window.location.href);
            var changed = false;

            URL_KEYS.forEach(function (key) {
                if (url.searchParams.has(key)) {
                    url.searchParams.delete(key);
                    changed = true;
                }
            });

            if (changed) {
                history.replaceState(null, '', url.pathname + url.search + url.hash);
            }
        } catch (error) {
            // Old browser: leave the address as it is.
        }
    }

    /** Show one tab (login or register) and put the cursor in its first empty field. */
    function showPanel(name, moveFocus) {
        if (!panels[name]) name = 'login';

        Object.keys(panels).forEach(function (key) {
            panels[key].hidden = key !== name;
        });

        var heading = panels[name].querySelector('.am-title');

        if (heading) dialog.setAttribute('aria-labelledby', heading.id);
        dialog.scrollTop = 0;

        if (moveFocus === false) return;

        var invalid = panels[name].querySelector('.is-invalid input');
        var fields = Array.prototype.slice.call(panels[name].querySelectorAll('input:not([type="hidden"]):not([type="checkbox"])'));
        var empty = fields.filter(function (input) { return input.value === ''; })[0];
        var target = invalid || empty || fields[0];

        if (target) target.focus();
    }

    function open(name, trigger) {
        lastTrigger = trigger || document.activeElement;

        overlay.classList.add('is-open');
        document.documentElement.classList.add('am-locked');

        showPanel(name || 'login');
    }

    function close() {
        if (termsModal && !termsModal.hidden) closeTerms();

        overlay.classList.remove('is-open');
        document.documentElement.classList.remove('am-locked');
        cleanUrl();

        if (lastTrigger && lastTrigger.focus && document.contains(lastTrigger)) {
            lastTrigger.focus();
        }
    }

    function openTerms(trigger) {
        if (!termsModal) return;

        lastTermsTrigger = trigger || document.activeElement;
        termsModal.hidden = false;

        if (closeTermsBtn) closeTermsBtn.focus();
    }

    function closeTerms() {
        if (!termsModal) return;

        termsModal.hidden = true;

        if (lastTermsTrigger && lastTermsTrigger.focus) lastTermsTrigger.focus();
    }

    // ---------- Links that open the pop-up (header, feedback, "Create an account") ----------
    document.addEventListener('click', function (event) {
        var opener = event.target.closest ? event.target.closest('[data-auth-open]') : null;

        if (opener) {
            event.preventDefault();
            open(opener.getAttribute('data-auth-open'), opener);
            return;
        }

        var switcher = event.target.closest ? event.target.closest('[data-auth-switch]') : null;

        if (switcher && overlay.contains(switcher)) {
            event.preventDefault();
            showPanel(switcher.getAttribute('data-auth-switch'));
        }
    });

    // ---------- Closing ----------
    if (closeLink) {
        closeLink.addEventListener('click', function (event) {
            event.preventDefault();
            close();
        });
    }

    // A click on the dimmed / blurred area (not on the card) closes it.
    overlay.addEventListener('mousedown', function (event) {
        if (event.target === overlay) close();
    });

    document.addEventListener('keydown', function (event) {
        if (!isOpen()) return;

        if (event.key === 'Escape') {
            event.preventDefault();

            if (termsModal && !termsModal.hidden) {
                closeTerms();
            } else {
                close();
            }

            return;
        }

        // Keep Tab inside the card (or inside the Terms box when it is on top).
        if (event.key === 'Tab') {
            var scope = termsModal && !termsModal.hidden ? termsModal : dialog;
            var items = focusables(scope);

            if (!items.length) return;

            var first = items[0];
            var last = items[items.length - 1];

            if (event.shiftKey && (document.activeElement === first || !scope.contains(document.activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !scope.contains(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    // ---------- Terms of Service ----------
    if (openTermsLink) {
        openTermsLink.addEventListener('click', function (event) {
            event.preventDefault();
            openTerms(openTermsLink);
        });
    }

    if (closeTermsBtn) closeTermsBtn.addEventListener('click', closeTerms);

    if (termsModal) {
        termsModal.addEventListener('mousedown', function (event) {
            if (event.target === termsModal) closeTerms();
        });
    }

    // ---------- Show / Hide password ----------
    overlay.querySelectorAll('.am-toggle-pw').forEach(function (button) {
        var input = document.getElementById(button.getAttribute('data-target'));

        if (!input) return;

        button.addEventListener('click', function () {
            var show = input.type === 'password';

            input.type = show ? 'text' : 'password';
            button.textContent = show ? 'Hide' : 'Show';
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus();
        });
    });

    // ---------- Live checks on the Create an Account form ----------
    // Warnings appear while typing / when leaving a field. The server re-checks everything.
    var registerForm = document.getElementById('amRegisterForm');
    var registerError = document.getElementById('amRegisterClientError');

    if (registerForm && registerError) {
        initRegisterChecks(registerForm, registerError);
    }

    function initRegisterChecks(form, summary) {
        var NAME_OK = /^[\p{L}][\p{L}\s.'\-]*$/u;
        var USER_OK = /^[A-Za-z0-9_.]{4,30}$/;
        var EMAIL_OK = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
        var MOBILE_OK = /^(?:\+?63|0)?9\d{9}$/;
        var COMMON = ['password', 'password1', 'password123', 'passw0rd', 'p@ssw0rd', 'p@ssword1',
            'qwerty123', 'qwertyuiop', 'letmein123', 'welcome123', 'admin123', 'iloveyou1',
            'abc12345', '12345678', '123456789', '1q2w3e4r'];

        var el = {
            name: document.getElementById('amRegName'),
            username: document.getElementById('amRegUsername'),
            password: document.getElementById('amRegPassword'),
            confirm: document.getElementById('amRegConfirm'),
            email: document.getElementById('amRegEmail'),
            mobile: document.getElementById('amRegMobile'),
            terms: document.getElementById('amRegTerms')
        };
        var rules = document.getElementById('amPwRules');
        var touched = {};
        var taken = { username: {}, email: {}, mobile: {} };   // value -> true (taken) / false (free)
        var timers = {};
        var requestNo = {};

        function box(input) { return input.closest('.am-input'); }
        function errorEl(key) { return document.getElementById('amErr_' + key); }

        function show(key, input, message, state) {
            var holder = errorEl(key);
            var wrap = box(input);

            if (holder) {
                holder.textContent = message || '';
                holder.hidden = !message;
            }

            if (wrap) {
                wrap.classList.toggle('is-invalid', !!message);
                wrap.classList.toggle('is-valid', !message && state === 'ok');
                wrap.classList.toggle('is-checking', !message && state === 'checking');
            }

            input.setAttribute('aria-invalid', message ? 'true' : 'false');
        }

        // ---- the rules (each returns an error message, or '' when fine) ----
        function nameProblem(v) {
            v = v.trim();
            if (v === '') return 'Please enter your name.';
            if (/\d/.test(v)) return 'Name can’t contain numbers.';
            if (!NAME_OK.test(v)) return 'Name may only contain letters, spaces, periods, apostrophes and hyphens.';
            if (v.length > 100) return 'Name must be 100 characters or fewer.';
            return '';
        }

        function usernameProblem(v) {
            v = v.trim();
            if (v === '') return 'Please choose a username.';
            if (/\s/.test(v)) return 'Username can’t contain spaces.';
            if (!/^[A-Za-z0-9_.]+$/.test(v)) return 'Only letters, numbers, underscore or period are allowed.';
            if (v.length < 4) return 'Username must be at least 4 characters.';
            if (v.length > 30) return 'Username must be 30 characters or fewer.';
            if (taken.username[v.toLowerCase()] === true) return 'That username is already taken.';
            return '';
        }

        function emailProblem(v) {
            v = v.trim();
            if (v === '') return 'Please enter your email address.';
            if (!EMAIL_OK.test(v) || v.length > 100) return 'Please enter a valid email address.';
            if (taken.email[v.toLowerCase()] === true) return 'That email is already registered.';
            return '';
        }

        function mobileKey(v) { return v.replace(/[\s\-()]/g, ''); }

        function mobileProblem(v) {
            var digits = mobileKey(v);
            if (digits === '') return 'Please enter your mobile number.';
            if (!MOBILE_OK.test(digits)) return 'Enter a valid PH mobile number, e.g. 09123456789.';
            if (taken.mobile[digits] === true) return 'That mobile number is already registered.';
            return '';
        }

        function passwordChecks(v) {
            return {
                length: v.length >= 8,
                upper: /[A-Z]/.test(v),
                lower: /[a-z]/.test(v),
                number: /\d/.test(v),
                special: /[^A-Za-z0-9]/.test(v)
            };
        }

        function passwordProblem(v) {
            var checks = passwordChecks(v);
            var missing = Object.keys(checks).filter(function (k) { return !checks[k]; });

            if (v === '') return 'Please create a password.';
            if (v.length > 72) return 'Password must not be longer than 72 characters.';
            if (missing.length) return 'Password is not strong enough yet.';
            if (COMMON.indexOf(v.toLowerCase().replace(/[!@#$%^&*._-]+$/, '')) !== -1) return 'That password is too common. Please choose a stronger one.';

            var user = el.username.value.trim().toLowerCase();
            if (user !== '' && v.toLowerCase().indexOf(user) !== -1) return 'Password must not contain your username.';

            return '';
        }

        function confirmProblem() {
            if (el.confirm.value === '') return 'Please confirm your password.';
            if (el.confirm.value !== el.password.value) return 'Passwords do not match.';
            return '';
        }

        // ---- showing results ----
        function paintRules(showUnmet) {
            if (!rules) return;
            var checks = passwordChecks(el.password.value);

            Array.prototype.forEach.call(rules.querySelectorAll('li'), function (li) {
                var met = checks[li.getAttribute('data-rule')];
                li.classList.toggle('is-met', met);
                li.classList.toggle('is-unmet', !met && showUnmet);
            });
        }

        function check(key, quiet) {
            var message = '';
            var input;

            switch (key) {
                case 'full_name': input = el.name; message = nameProblem(input.value); break;
                case 'username': input = el.username; message = usernameProblem(input.value); break;
                case 'email': input = el.email; message = emailProblem(input.value); break;
                case 'mobile': input = el.mobile; message = mobileProblem(input.value); break;
                case 'password':
                    input = el.password;
                    message = passwordProblem(input.value);
                    paintRules(touched.password && input.value !== '');
                    break;
                case 'confirm_password': input = el.confirm; message = confirmProblem(); break;
            }

            // While the field is still empty and untouched, say nothing.
            if (quiet && input.value === '') message = '';

            show(key, input, message, input.value !== '' ? 'ok' : '');

            // The password rules list says it all, so no extra red line under it.
            if (key === 'password' && message === 'Password is not strong enough yet.') {
                show(key, input, '', '');
                box(input).classList.add('is-invalid');
            }

            return message;
        }

        // ---- "already taken?" lookups ----
        function lookup(field, input, value, key) {
            var cacheKey = field === 'mobile' ? mobileKey(value) : value.toLowerCase();

            if (taken[field][cacheKey] !== undefined) {
                check(key);
                return;
            }

            var no = (requestNo[field] = (requestNo[field] || 0) + 1);
            box(input).classList.add('is-checking');

            fetch('check_availability.php?field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (no !== requestNo[field] || !data || !data.ok) return;

                    taken[field][cacheKey] = data.available === false;
                    check(key);
                })
                .catch(function () {
                    // Offline or server busy: the sign-up still checks again.
                    box(input).classList.remove('is-checking');
                });
        }

        function scheduleLookup(field, input, key, formatOk, delay) {
            clearTimeout(timers[field]);

            var value = input.value.trim();

            if (!value || !formatOk(value)) return;

            timers[field] = setTimeout(function () {
                if (input.value.trim() === value) lookup(field, input, value, key);
            }, delay);
        }

        // ---- events ----
        el.name.addEventListener('input', function () {
            // Digits and symbols are flagged straight away; "empty" waits for leaving the field.
            check('full_name', !touched.full_name && !/[\d]|[^\p{L}\s.'\-]/u.test(el.name.value));
        });
        el.name.addEventListener('blur', function () { touched.full_name = true; check('full_name'); });

        el.username.addEventListener('input', function () {
            touched.username = true;
            check('username', false);
            scheduleLookup('username', el.username, 'username', function (v) { return USER_OK.test(v); }, 450);
            if (el.password.value !== '') check('password');   // "must not contain your username"
        });
        el.username.addEventListener('blur', function () { touched.username = true; check('username'); });

        el.email.addEventListener('input', function () {
            if (touched.email) check('email');
        });
        el.email.addEventListener('blur', function () {
            touched.email = true;
            check('email');
            scheduleLookup('email', el.email, 'email', function (v) { return EMAIL_OK.test(v); }, 0);
        });

        el.mobile.addEventListener('input', function () {
            if (touched.mobile) check('mobile');
            scheduleLookup('mobile', el.mobile, 'mobile', function (v) { return MOBILE_OK.test(mobileKey(v)); }, 450);
        });
        el.mobile.addEventListener('blur', function () { touched.mobile = true; check('mobile'); });

        el.password.addEventListener('input', function () {
            touched.password = true;
            check('password');
            if (el.confirm.value !== '') check('confirm_password');
        });
        el.password.addEventListener('focus', function () { paintRules(false); });
        el.password.addEventListener('blur', function () { touched.password = true; check('password'); });

        el.confirm.addEventListener('input', function () {
            touched.confirm_password = true;
            check('confirm_password');
        });
        el.confirm.addEventListener('blur', function () { touched.confirm_password = true; check('confirm_password'); });

        el.terms.addEventListener('change', function () {
            var holder = errorEl('terms');
            if (holder) { holder.hidden = el.terms.checked; }
        });

        form.addEventListener('input', function () { summary.hidden = true; });

        // Already-filled values (after a server error) get checked once, quietly.
        ['full_name', 'username', 'email', 'mobile'].forEach(function (key) {
            var input = { full_name: el.name, username: el.username, email: el.email, mobile: el.mobile }[key];
            if (input.value !== '' && !errorEl(key).textContent) show(key, input, '', 'ok');
        });

        form.addEventListener('submit', function (event) {
            var order = ['full_name', 'username', 'password', 'confirm_password', 'email', 'mobile'];
            var firstBad = null;

            order.forEach(function (key) {
                touched[key] = true;
                var bad = check(key);

                if (bad && !firstBad) {
                    firstBad = { full_name: el.name, username: el.username, password: el.password,
                        confirm_password: el.confirm, email: el.email, mobile: el.mobile }[key];
                }
            });

            if (!el.terms.checked) {
                var holder = errorEl('terms');
                if (holder) { holder.textContent = 'You must agree to the Terms of Service.'; holder.hidden = false; }
                if (!firstBad) firstBad = el.terms;
            }

            if (firstBad) {
                event.preventDefault();
                summary.textContent = 'Please fix the highlighted fields.';
                summary.hidden = false;
                firstBad.focus();
            }
        });
    }

    // Stop double-clicks from sending a form twice.
    overlay.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) return;

            var button = form.querySelector('.am-submit');

            if (button) {
                // Disable after the browser has collected the form data.
                setTimeout(function () { button.disabled = true; }, 0);
            }
        });
    });

    // Coming back with the Back button: the buttons must work again.
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;

        overlay.querySelectorAll('.am-submit').forEach(function (button) {
            button.disabled = false;
        });
    });

    // ---------- Opened by the server (an error to show, or ?auth=login) ----------
    if (isOpen()) {
        document.documentElement.classList.add('am-locked');
        lastTrigger = null;
        showPanel(overlay.getAttribute('data-initial') || 'login');
    }
})();
