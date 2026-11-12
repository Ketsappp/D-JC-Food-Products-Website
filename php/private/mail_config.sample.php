<?php
/**
 * Copy to  mail_config.php  and fill it in (Render: set the MAIL_* environment variables).
 * Gmail: turn on 2-Step Verification, then create an "App password" and paste it below.
 */
return [
    "host"       => "smtp.gmail.com",
    "port"       => 587,
    "encryption" => "tls",
    "username"   => "your-shop-email@gmail.com",
    "password"   => "xxxx xxxx xxxx xxxx",
    "from_email" => "your-shop-email@gmail.com",
    "from_name"  => "D&JC Food Products",
];
