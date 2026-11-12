<?php

require_once __DIR__ . "/config_path.php";

// Local XAMPP defaults. On a hosting account, copy php/private/db_config.sample.php to
// php/private/db_config.php and fill in the database details of the host.
$host = "localhost";
$username = "root";
$password = "";
$database = "djc_db";
$dbConfig = [];

$dbConfigFile = private_config_file("db_config.php");

if ($dbConfigFile !== null) {
    $dbConfig = require $dbConfigFile;

    if (is_array($dbConfig)) {
        $host = $dbConfig["host"] ?? $host;
        $username = $dbConfig["username"] ?? $username;
        $password = $dbConfig["password"] ?? $password;
        $database = $dbConfig["database"] ?? $database;
    }
}

$port = (int) ($dbConfig["port"] ?? 3306);
$useSsl = !empty($dbConfig["ssl"]);

$conn = mysqli_init();

if ($useSsl) {
    // TiDB Cloud only accepts TLS connections. The system CA bundle is enough.
    $conn->ssl_set(null, null, "/etc/ssl/certs/ca-certificates.crt", null, null);
    $conn->real_connect($host, $username, $password, $database, $port, null, MYSQLI_CLIENT_SSL);
} else {
    $conn->real_connect($host, $username, $password, $database, $port);
}

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

?>