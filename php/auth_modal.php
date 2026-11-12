<?php
/**
 * The Login / Create an Account pop-up for the landing page.
 *
 * Needs $auth from auth_process() (auth_process.php). Draws nothing when a
 * customer is already signed in.
 *
 * It is plain server-rendered HTML, so it also works without JavaScript: when
 * a form has an error, or a link like landing_page.php?auth=login is opened,
 * the pop-up is already open on the right tab. js/auth_modal.js adds the
 * smooth open / close, tab switching and the blurred background.
 */
if (empty($auth["available"])) {
    return;
}

$amOpen = $auth["open"];
$amErrors = $auth["reg_errors"];
$amValues = $auth["reg_values"];

/** Error text under a Register field. */
function am_field_error(array $errors, string $key): void
{
    // Always printed (empty = hidden) so js/auth_modal.js can show live warnings in it.
    $text = $errors[$key] ?? "";
    echo '<p class="am-field-error" id="amErr_' . e($key) . '" role="alert"' . ($text === "" ? " hidden" : "") . '>' . e($text) . '</p>';
}

/** " is-invalid" when a Register field has an error. */
function am_invalid(array $errors, string $key): string
{
    return isset($errors[$key]) ? " is-invalid" : "";
}
?>
<div class="am-overlay<?= $amOpen !== "" ? " is-open" : "" ?>" id="authModal" data-initial="<?= e($amOpen) ?>">
    <div class="am-dialog" role="dialog" aria-modal="true" aria-labelledby="amLoginTitle" tabindex="-1">

        <a href="landing_page.php" class="am-close" id="amClose" aria-label="Close">&times;</a>

        <div class="am-brand">
            <span class="am-brand-logo">D&amp;JC</span>
            <span class="am-brand-text">Food Products</span>
        </div>

        <!-- ================= LOGIN ================= -->
        <section class="am-panel" id="amLogin" data-panel="login"<?= ($amOpen === "register" || $amOpen === "forgot") ? " hidden" : "" ?>>
            <h2 class="am-title" id="amLoginTitle">Welcome back</h2>
            <p class="am-sub">Log in to order from D&amp;JC Food Products.</p>

            <?php if ($auth["login_error"] !== ""): ?>
                <p class="am-alert am-alert-error" role="alert"><?= e($auth["login_error"]) ?></p>
            <?php endif; ?>

            <?php if ($auth["login_notice"] !== ""): ?>
                <p class="am-alert am-alert-ok" role="status"><?= e($auth["login_notice"]) ?></p>
            <?php endif; ?>

            <?php if ($auth["resend"]): ?>
                <p class="am-resend">
                    <a class="am-link strong" href="resend_verification.php?email=<?= urlencode($auth["resend_email"]) ?>">Resend confirmation email</a>
                </p>
            <?php endif; ?>

            <form method="POST" action="landing_page.php" id="amLoginForm" class="am-form">
                <input type="hidden" name="auth_action" value="login">
                <?php if ($auth["next"] !== ""): ?>
                    <input type="hidden" name="next" value="<?= e($auth["next"]) ?>">
                <?php endif; ?>

                <div class="am-field">
                    <label class="am-label" for="amLoginUsername">Username</label>
                    <div class="am-input">
                        <input type="text" id="amLoginUsername" name="username" placeholder="Username"
                               value="<?= e($auth["login_username"]) ?>" autocomplete="username" required>
                    </div>
                </div>

                <div class="am-field">
                    <label class="am-label" for="amLoginPassword">Password</label>
                    <div class="am-input">
                        <input type="password" id="amLoginPassword" name="password" placeholder="Password"
                               autocomplete="current-password" required>
                        <button type="button" class="am-toggle-pw" data-target="amLoginPassword" aria-label="Show password">Show</button>
                    </div>
                </div>

                <div class="am-row-end">
                    <a href="landing_page.php?auth=forgot" class="am-link" data-auth-switch="forgot">Forgot Password?</a>
                </div>

                <button type="submit" class="am-submit">LOGIN</button>
            </form>

            <p class="am-switch">
                Don&rsquo;t have an account?
                <a href="landing_page.php?auth=register" class="am-link strong" data-auth-switch="register">Create an Account</a>
            </p>
        </section>

        <!-- ================= REGISTER ================= -->
        <section class="am-panel" id="amRegister" data-panel="register"<?= $amOpen === "register" ? "" : " hidden" ?>>
            <h2 class="am-title" id="amRegisterTitle">Create an Account</h2>
            <p class="am-sub">Sign up to order from D&amp;JC Food Products.</p>

            <?php if ($auth["reg_form_error"] !== ""): ?>
                <p class="am-alert am-alert-error" role="alert"><?= e($auth["reg_form_error"]) ?></p>
            <?php endif; ?>

            <form method="POST" action="landing_page.php" id="amRegisterForm" class="am-form" novalidate>
                <input type="hidden" name="auth_action" value="register">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="am-field">
                    <label class="am-label" for="amRegName">Name</label>
                    <div class="am-input<?= am_invalid($amErrors, "full_name") ?>">
                        <input type="text" id="amRegName" name="full_name" data-live="full_name" placeholder="Juan Dela Cruz"
                               value="<?= e($amValues["full_name"]) ?>" maxlength="100" autocomplete="name" required>
                    </div>
                    <?php am_field_error($amErrors, "full_name"); ?>
                </div>

                <div class="am-field">
                    <label class="am-label" for="amRegUsername">Username</label>
                    <div class="am-input<?= am_invalid($amErrors, "username") ?>">
                        <input type="text" id="amRegUsername" name="username" data-live="username" placeholder="Username"
                               value="<?= e($amValues["username"]) ?>" maxlength="30" autocomplete="username" required>
                    </div>
                    <?php if (!isset($amErrors["username"])): ?>
                        <p class="am-hint">4-30 characters: letters, numbers, _ or .</p>
                    <?php endif; ?>
                    <?php am_field_error($amErrors, "username"); ?>
                </div>

                <div class="am-field">
                    <label class="am-label" for="amRegPassword">Password</label>
                    <div class="am-input<?= am_invalid($amErrors, "password") ?>">
                        <input type="password" id="amRegPassword" name="password" data-live="password" placeholder="Password"
                               autocomplete="new-password" required>
                        <button type="button" class="am-toggle-pw" data-target="amRegPassword" aria-label="Show password">Show</button>
                    </div>
                    <ul class="am-pw-rules" id="amPwRules" aria-label="Password requirements">
                        <li data-rule="length">At least 8 characters</li>
                        <li data-rule="upper">An uppercase letter (A-Z)</li>
                        <li data-rule="lower">A lowercase letter (a-z)</li>
                        <li data-rule="number">A number (0-9)</li>
                        <li data-rule="special">A special character (! @ # $ % ...)</li>
                    </ul>
                    <?php am_field_error($amErrors, "password"); ?>
                </div>

                <div class="am-field">
                    <label class="am-label" for="amRegConfirm">Confirm Password</label>
                    <div class="am-input<?= am_invalid($amErrors, "confirm_password") ?>">
                        <input type="password" id="amRegConfirm" name="confirm_password" data-live="confirm_password" placeholder="Confirm Password"
                               autocomplete="new-password" required>
                        <button type="button" class="am-toggle-pw" data-target="amRegConfirm" aria-label="Show password">Show</button>
                    </div>
                    <?php am_field_error($amErrors, "confirm_password"); ?>
                </div>

                <div class="am-field">
                    <label class="am-label" for="amRegEmail">Email</label>
                    <div class="am-input<?= am_invalid($amErrors, "email") ?>">
                        <input type="email" id="amRegEmail" name="email" data-live="email" placeholder="name@example.com"
                               value="<?= e($amValues["email"]) ?>" maxlength="100" autocomplete="email" required>
                    </div>
                    <?php am_field_error($amErrors, "email"); ?>
                </div>

                <div class="am-field">
                    <label class="am-label" for="amRegMobile">Mobile Number</label>
                    <div class="am-input<?= am_invalid($amErrors, "mobile") ?>">
                        <input type="tel" id="amRegMobile" name="mobile" data-live="mobile" placeholder="09123456789"
                               value="<?= e($amValues["mobile"]) ?>" maxlength="16" autocomplete="tel" inputmode="tel" required>
                    </div>
                    <?php am_field_error($amErrors, "mobile"); ?>
                </div>

                <label class="am-terms">
                    <input type="checkbox" name="terms" id="amRegTerms" value="1"<?= $auth["reg_terms"] ? " checked" : "" ?>>
                    <span>I agree to the <a href="#" id="amOpenTerms">Terms of Service</a>.</span>
                </label>
                <?php am_field_error($amErrors, "terms"); ?>

                <p class="am-alert am-alert-error" id="amRegisterClientError" role="alert" hidden></p>

                <button type="submit" class="am-submit">CREATE ACCOUNT</button>
            </form>

            <p class="am-switch">
                Already have an account?
                <a href="landing_page.php?auth=login" class="am-link strong" data-auth-switch="login">Log in</a>
            </p>
        </section>

        <!-- ================= FORGOT PASSWORD ================= -->
        <section class="am-panel" id="amForgot" data-panel="forgot"<?= $amOpen === "forgot" ? "" : " hidden" ?>>
            <h2 class="am-title" id="amForgotTitle">Forgot Password?</h2>

            <?php if ($auth["forgot_sent"]): ?>
                <p class="am-alert am-alert-ok" role="status">
                    If an account exists for that email, we&rsquo;ve sent a password reset link.
                    It expires in <?= (int) RESET_TOKEN_MINUTES ?> minutes.
                </p>
            <?php else: ?>
                <p class="am-sub">Enter the email address on your account and we&rsquo;ll send you a link to reset your password.</p>

                <?php if ($auth["forgot_error"] !== ""): ?>
                    <p class="am-alert am-alert-error" role="alert"><?= e($auth["forgot_error"]) ?></p>
                <?php endif; ?>

                <form method="POST" action="landing_page.php" id="amForgotForm" class="am-form">
                    <input type="hidden" name="auth_action" value="forgot">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <div class="am-field">
                        <label class="am-label" for="amForgotEmail">Email</label>
                        <div class="am-input">
                            <input type="email" id="amForgotEmail" name="email" placeholder="name@example.com"
                                   value="<?= e($auth["forgot_email"]) ?>" maxlength="100" autocomplete="email" required>
                        </div>
                    </div>

                    <button type="submit" class="am-submit">SEND RESET LINK</button>
                </form>
            <?php endif; ?>

            <p class="am-switch">
                <a href="landing_page.php?auth=login" class="am-link strong" data-auth-switch="login">Back to Login</a>
            </p>
        </section>
    </div>
</div>

<!-- Terms of Service (opens on top of the pop-up) -->
<div class="am-terms-modal" id="amTermsModal" role="dialog" aria-modal="true" aria-labelledby="amTermsTitle" hidden>
    <div class="am-terms-box">
        <h2 id="amTermsTitle">Terms of Service</h2>
        <div class="am-terms-content">
            <p>By creating an account with D&amp;JC Food Products you agree to the following terms.</p>
            <h3>1. Your account</h3>
            <p>You are responsible for the accuracy of the information you provide and for keeping your username and password confidential. Activity under your account is treated as yours.</p>
            <h3>2. Orders and pricing</h3>
            <p>Orders for milktea, milkshakes, fruit soda and pizza are subject to product availability. Prices shown at checkout are final for that order. We may cancel an order if an item is out of stock.</p>
            <h3>3. Payments</h3>
            <p>Payments are accepted through GCash Scan-to-Pay only. Your order is confirmed only after the payment is verified, which helps us prevent fake orders. We will notify you once your order is ready for pick-up. Please keep your payment confirmation.</p>
            <h3>4. Personal information</h3>
            <p>We collect your name, email and mobile number to process your orders and contact you about them. Your data is used only for the operation of D&amp;JC Food Products and is not sold to third parties, in line with the Data Privacy Act of 2012 (RA 10173).</p>
            <h3>5. Acceptable use</h3>
            <p>You agree not to misuse the system, attempt unauthorized access, or place fraudulent orders. We may suspend accounts that violate these terms.</p>
            <h3>6. Changes</h3>
            <p>We may update these terms from time to time. Continued use of the system means you accept the updated terms.</p>
        </div>
        <button type="button" class="am-submit" id="amCloseTerms">I UNDERSTAND</button>
    </div>
</div>

<script src="../js/auth_modal.js?v=3"></script>
