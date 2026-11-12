<?php
/**
 * Login now lives in the pop-up on the landing page (landing_page.php).
 *
 * This file stays so that every old link keeps working: the admin / staff
 * "not signed in" redirects, the links in the confirmation and password-reset
 * emails, bookmarks, and so on. It just opens the pop-up on the Login tab and
 * passes along the little things the pop-up understands.
 */
$keep = ["auth" => "login"];

foreach (["next", "registered", "verified", "reset", "email"] as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key])) {
        $keep[$key] = $_GET[$key];
    }
}

header("Location: landing_page.php?" . http_build_query($keep));
exit;
