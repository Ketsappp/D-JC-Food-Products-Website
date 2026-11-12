<?php
/**
 * Finds a private settings file (database login, PayMongo keys, email login).
 *
 * Looked up in this order:
 *   1. C:/xampp/private/<name>        (local XAMPP, outside the web folder)
 *   2. php/private/<name>             (hosting such as InfinityFree: PHP cannot read
 *                                      outside htdocs, so the files live here; the folder
 *                                      is blocked from the web by php/private/.htaccess)
 *   3. php/<name>                     (older location)
 *
 * @return string|null the full path, or null when the file does not exist
 */
if (!function_exists("private_config_file")) {
    function private_config_file(string $name): ?string
    {
        foreach (["C:/xampp/private/" . $name, __DIR__ . "/private/" . $name, __DIR__ . "/" . $name] as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}
