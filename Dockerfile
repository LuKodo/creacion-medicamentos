FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring zip xml \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --chown=www-data:www-data . /var/www/html/
# Keep the baked-in initial data separate from the mounted FSS path.
COPY --chown=www-data:www-data data/ /opt/app-seed/data/
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint

RUN printf '%s\n' \
        '<Directory /var/www/html>' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        '<Directory /var/www/html/data>' \
        '    AllowOverride None' \
        '    Require all denied' \
        '</Directory>' \
        '<Directory /var/www/html/salidas>' \
        '    AllowOverride None' \
        '    Require all denied' \
        '</Directory>' \
        >> /etc/apache2/apache2.conf \
    && chmod 0755 /usr/local/bin/app-entrypoint \
    && mkdir -p /var/www/html/data /var/www/html/salidas \
    && chown -R www-data:www-data /var/www/html/data /var/www/html/salidas

# Base uploads accept up to 200 MB (see README, "php.ini"); the PHP default is 2 MB.
RUN printf '%s\n' \
        'upload_max_filesize = 200M' \
        'post_max_size = 210M' \
        > /usr/local/etc/php/conf.d/zz-uploads.ini

WORKDIR /var/www/html
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["apache2-foreground"]
