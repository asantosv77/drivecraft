# syntax=docker/dockerfile:1
FROM denoland/deno:bin AS javascript-runtime
FROM php:8.3-apache-bookworm
ARG YTDLP_VERSION=2026.10.26
ENV TZ=America/Mexico_City TMPDIR=/drive/tmp HOME=/drive/home XDG_CACHE_HOME=/drive/cache
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    libzip-dev libonig-dev libmagickwand-dev imagemagick ffmpeg poppler-utils \
    p7zip-full unrar-free unzip tar gzip xz-utils bzip2 webp libjpeg-turbo-progs \
    pngquant optipng python3 python3-venv fonts-dejavu-core \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j2 pdo_mysql mbstring zip gd exif bcmath opcache \
    && pecl install imagick-3.8.0 && docker-php-ext-enable imagick \
    && python3 -m venv /opt/yt-dlp \
    && /opt/yt-dlp/bin/pip install --no-cache-dir "yt-dlp[default]==${YTDLP_VERSION}" \
    && ln -s /opt/yt-dlp/bin/yt-dlp /usr/local/bin/yt-dlp \
    && rm -rf /var/lib/apt/lists/*
COPY --from=javascript-runtime /deno /usr/local/bin/deno
COPY --chown=www-data:www-data *.php *.js *.css *.svg *.png dc_i18n_en_dcft.json /var/www/html/drive/
COPY --chown=www-data:www-data vendor/ /var/www/html/drive/vendor/
COPY --chown=www-data:www-data skills/ /var/www/html/drive/skills/
COPY deploy/ /opt/drivecraft/
COPY --chown=www-data:www-data deploy/config.local.php /var/www/html/drive/config.php
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-drivecraft-local.ini
RUN a2enmod headers rewrite \
    && printf 'Listen 8080\n' > /etc/apache2/ports.conf \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/drivecraft-servername.conf \
    && a2enconf drivecraft-servername \
    && install -d -o www-data -g www-data -m 0770 /drive /drive/files /drive/thumbs /drive/lists /drive/sessions /drive/tmp /drive/tmp/uploads /drive/home /drive/cache \
    && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
    && php /opt/drivecraft/prepare-image.php \
    && chmod 0755 /opt/drivecraft/entrypoint.sh
WORKDIR /var/www/html/drive
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=8s --start-period=90s --retries=3 CMD php /opt/drivecraft/health.php
ENTRYPOINT ["/opt/drivecraft/entrypoint.sh"]
