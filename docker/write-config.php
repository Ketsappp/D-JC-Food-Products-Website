<?php
// Writes php/private/*.php from environment variables (so no secrets live in Git).
$dir = __DIR__ . "/../php/private";

function write_cfg(string $file, array $cfg): void
{
    file_put_contents($file, "<?php\nreturn " . var_export($cfg, true) . ";\n");
    chmod($file, 0640);
    chown($file, "www-data");
}

if (getenv("DB_HOST")) {
    write_cfg("$dir/db_config.php", [
        "host"     => getenv("DB_HOST"),
        "username" => getenv("DB_USER"),
        "password" => getenv("DB_PASSWORD"),
        "database" => getenv("DB_NAME") ?: "djc_db",
        "port"     => (int) (getenv("DB_PORT") ?: 3306),
        "ssl"      => filter_var(getenv("DB_SSL") ?: "true", FILTER_VALIDATE_BOOLEAN),
    ]);
}

if (getenv("MAIL_USERNAME")) {
    write_cfg("$dir/mail_config.php", [
        "host"       => getenv("MAIL_HOST") ?: "smtp.gmail.com",
        "port"       => (int) (getenv("MAIL_PORT") ?: 587),
        "encryption" => getenv("MAIL_ENCRYPTION") ?: "tls",
        "username"   => getenv("MAIL_USERNAME"),
        "password"   => getenv("MAIL_PASSWORD"),
        "from_email" => getenv("MAIL_FROM_EMAIL") ?: getenv("MAIL_USERNAME"),
        "from_name"  => getenv("MAIL_FROM_NAME") ?: "D&JC Food Products",
    ]);
}

if (getenv("PAYMONGO_SECRET_KEY")) {
    write_cfg("$dir/paymongo_config.php", [
        "api_url"        => "https://api.paymongo.com/v1",
        "secret_key"     => getenv("PAYMONGO_SECRET_KEY"),
        "webhook_secret" => getenv("PAYMONGO_WEBHOOK_SECRET") ?: "",
    ]);
}
