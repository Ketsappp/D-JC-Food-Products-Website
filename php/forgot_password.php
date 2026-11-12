<?php
/**
 * Forgot Password now lives in the pop-up on the landing page (landing_page.php).
 * This file only keeps old links working (the "Request a new link" button on
 * the reset page, bookmarks) by opening the pop-up on the Forgot Password tab.
 */
header("Location: landing_page.php?auth=forgot");
exit;
