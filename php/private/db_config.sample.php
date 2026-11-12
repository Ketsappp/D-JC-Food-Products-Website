<?php
/**
 * Local/hosting use: copy this file to  db_config.php  (same folder) and fill it in.
 * On Render this file is generated automatically from environment variables
 * (DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME) - see README.md.
 */
return [
    "host"     => "your-db-host",
    "username" => "your-db-user",
    "password" => "your-db-password",
    "database" => "your-db-name",
    "port"     => 3306,
    "ssl"      => false,   // set true for TiDB Cloud (port 4000)
];
