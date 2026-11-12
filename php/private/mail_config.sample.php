<?php
/**
 * Copy to  mail_config.php  and fill it in (Render: set the MAIL_* environment variables).
 * Gmail: turn on 2-Step Verification, then create an "App password" and paste it below.
 */
// Option A - Brevo HTTPS API (works on Render free). Use only these 3 lines:
//   "brevo_api_key" => "xkeysib-xxxxxxxx", "from_email" => "verified-sender@yourdomain.com", "from_name" => "D&JC Food Products"
// Option B - SMTP (local XAMPP, or hosts that allow SMTP):
return [
    "host"       => "smtp.gmail.com",
    "port"       => 587,
    "encryption" => "tls",
    "username"   => "your-shop-email@gmail.com",
    "password"   => "xxxx xxxx xxxx xxxx",
    "from_email" => "your-shop-email@gmail.com",
    "from_name"  => "D&JC Food Products",
];
