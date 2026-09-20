# Copyright (C) 2026 IZK
FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip openssl \
    && docker-php-ext-install pdo_mysql opcache \
    && rm -rf /var/lib/apt/lists/*

RUN printf '%s\\n' \
    'upload_max_filesize=256M' \
    'post_max_size=320M' \
    'memory_limit=512M' \
    'max_execution_time=180' \
    'max_input_time=180' \
    > /usr/local/etc/php/conf.d/muchocore.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/mucho-core
COPY docker/app-entrypoint.sh /usr/local/bin/mucho-entrypoint
RUN chmod +x /usr/local/bin/mucho-entrypoint

ENTRYPOINT ["/usr/local/bin/mucho-entrypoint"]
CMD ["php-fpm", "-F"]
