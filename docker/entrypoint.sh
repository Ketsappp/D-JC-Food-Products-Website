#!/bin/sh
set -e

# 1. Listen on the port Render gives us
PORT="${PORT:-10000}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf
a2enconf servername >/dev/null

# 2. Turn environment variables into the private config files the app already understands
php /var/www/html/docker/write-config.php

exec apache2-foreground
