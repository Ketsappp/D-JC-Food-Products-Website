<?php
/**
 * Copy to  paymongo_config.php  to turn PayMongo checkout ON (Render: set PAYMONGO_SECRET_KEY
 * and PAYMONGO_WEBHOOK_SECRET). Without it the manual GCash QR + receipt flow is used.
 */
return [
    "api_url"        => "https://api.paymongo.com/v1",
    "secret_key"     => "sk_test_xxxxxxxxxxxxxxxx",
    "webhook_secret" => "whsk_xxxxxxxxxxxxxxxx",
];
