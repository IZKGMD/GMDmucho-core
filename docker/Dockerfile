FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip openssl libzip-dev android-sdk-build-tools \
    && docker-php-ext-install pdo_mysql opcache zip \
    && printf '%s\n' 'display_errors=0' 'display_startup_errors=0' 'log_errors=1' 'expose_php=0' 'memory_limit=256M' 'max_execution_time=60' 'max_input_time=60' 'upload_max_filesize=64M' 'post_max_size=70M' 'max_file_uploads=20' > /usr/local/etc/php/conf.d/muchocore-production.ini \
    && printf '%s\n' 'clear_env = no' > /usr/local/etc/php-fpm.d/zz-muchocore-environment.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/mucho-core
COPY docker/app-entrypoint.sh /usr/local/bin/mucho-entrypoint
RUN chmod +x /usr/local/bin/mucho-entrypoint

ENTRYPOINT ["/usr/local/bin/mucho-entrypoint"]
CMD ["php-fpm", "-F"]
