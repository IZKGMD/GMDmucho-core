FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip openssl libzip-dev \
    && docker-php-ext-install pdo_mysql opcache zip \
    && printf '%s\n' 'upload_max_filesize=64M' 'post_max_size=70M' 'max_file_uploads=20' > /usr/local/etc/php/conf.d/muchocore-uploads.ini \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/mucho-core
COPY docker/app-entrypoint.sh /usr/local/bin/mucho-entrypoint
RUN chmod +x /usr/local/bin/mucho-entrypoint

ENTRYPOINT ["/usr/local/bin/mucho-entrypoint"]
CMD ["php-fpm", "-F"]
