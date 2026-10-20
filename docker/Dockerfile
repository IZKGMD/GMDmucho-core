FROM php:8.3-fpm-bookworm

# Redis is optional. The default MuchoCore cache driver uses MariaDB;
# never make a fresh/production app image depend on PECL availability.
# Set MUCHO_WITH_REDIS=1 only in the explicit Redis-cache Compose overlay.
ARG MUCHO_WITH_REDIS=0

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zip openssl openssh-client sshpass mariadb-client libzip-dev libpng-dev libjpeg62-turbo-dev android-sdk-build-tools \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd \
    && docker-php-ext-install pdo_mysql zip ftp \
    && docker-php-ext-enable opcache \
    && case "$MUCHO_WITH_REDIS" in \
        0) echo '[MuchoCore] Redis extension not requested; database cache remains available' ;; \
        1) pecl install redis-6.2.0 && docker-php-ext-enable redis ;; \
        *) echo '[MuchoCore] MUCHO_WITH_REDIS must be 0 or 1' >&2; exit 1 ;; \
       esac \
    && printf '%s\n' 'display_errors=0' 'display_startup_errors=0' 'log_errors=1' 'expose_php=0' 'memory_limit=256M' 'max_execution_time=60' 'max_input_time=60' 'upload_max_filesize=64M' 'post_max_size=70M' 'max_file_uploads=20' > /usr/local/etc/php/conf.d/muchocore-production.ini \
    && printf '%s\n' 'clear_env = no' > /usr/local/etc/php-fpm.d/zz-muchocore-environment.conf \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php-performance.ini /usr/local/etc/php/conf.d/zz-muchocore-performance.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/mucho-core
COPY docker/app-entrypoint.sh /usr/local/bin/mucho-entrypoint
RUN chmod +x /usr/local/bin/mucho-entrypoint

ENTRYPOINT ["/usr/local/bin/mucho-entrypoint"]
CMD ["php-fpm", "-F"]
