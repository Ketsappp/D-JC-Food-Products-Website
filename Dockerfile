FROM php:8.2-apache

# PHP extensions the app uses (mysqli for the DB, mbstring/curl are used too)
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates libcurl4-openssl-dev libonig-dev \
 && docker-php-ext-install mysqli mbstring curl \
 && a2enmod rewrite headers \
 && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html

RUN chmod +x /var/www/html/docker/entrypoint.sh \
 && mkdir -p /var/www/html/uploads/products /var/www/html/uploads/gcash /var/www/html/php/logs \
 && chown -R www-data:www-data /var/www/html/uploads /var/www/html/php/logs /var/www/html/php/private

# Render tells the container which port to listen on through $PORT
ENV PORT=10000
EXPOSE 10000

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
