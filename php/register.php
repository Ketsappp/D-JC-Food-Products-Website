<?php
/**
 * Registration now lives in the pop-up on the landing page (landing_page.php).
 * This file only keeps old links working by opening the pop-up on the
 * "Create an Account" tab.
 */
header("Location: landing_page.php?auth=register");
exit;
