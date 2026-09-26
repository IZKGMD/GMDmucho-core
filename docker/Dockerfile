FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip openssl libzip-dev android-sdk-build-tools ffmpeg python3 curl ca-certificates \
    && curl -L --fail --silent --show-error https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp \
    && chmod 0755 /usr/local/bin/yt-dlp \
    && ARCH="$(dpkg --print-architecture)" \
    && case "$ARCH" in \
         amd64) DENO_ASSET="deno-x86_64-unknown-linux-gnu.zip" ;; \
         arm64) DENO_ASSET="deno-aarch64-unknown-linux-gnu.zip" ;; \
         *) echo "Unsupported architecture for Deno: $ARCH" >&2; exit 1 ;; \
       esac \
    && curl -L --fail --silent --show-error "https://github.com/denoland/deno/releases/latest/download/$DENO_ASSET" -o /tmp/deno.zip \
    && unzip -q /tmp/deno.zip -d /usr/local/bin \
    && chmod 0755 /usr/local/bin/deno \
    && rm -f /tmp/deno.zip \
    && docker-php-ext-install pdo_mysql zip \
    && docker-php-ext-enable opcache \
    && printf '%s\n' 'display_errors=0' 'display_startup_errors=0' 'log_errors=1' 'expose_php=0' 'memory_limit=256M' 'max_execution_time=60' 'max_input_time=60' 'upload_max_filesize=64M' 'post_max_size=70M' 'max_file_uploads=20' > /usr/local/etc/php/conf.d/muchocore-production.ini \
    && printf '%s\n' 'clear_env = no' > /usr/local/etc/php-fpm.d/zz-muchocore-environment.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/mucho-core
COPY docker/app-entrypoint.sh /usr/local/bin/mucho-entrypoint
RUN chmod +x /usr/local/bin/mucho-entrypoint

ENTRYPOINT ["/usr/local/bin/mucho-entrypoint"]
CMD ["php-fpm", "-F"]
