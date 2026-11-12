# Wehrkalender – ein Image für "app" (php-fpm) und "worker" (supercronic)

# ---------------------------------------------------------------- Abhängigkeiten
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
# Das composer-Image hat eine neuere PHP-Version als die Laufzeit (8.3).
# composer.json legt deshalb config.platform.php = 8.3.0 fest; hier werden nur
# Erweiterungen ignoriert, die im composer-Image fehlen (intl, sodium …),
# niemals die PHP-Version – sonst landen Pakete für PHP 8.4+ im Image.
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist --ignore-platform-req='ext-*'
COPY src/ src/
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---------------------------------------------------------------- Laufzeit
FROM php:8.3-fpm-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev curl ca-certificates \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql intl opcache \
 && apt-get purge -y --auto-remove libicu-dev && apt-get install -y --no-install-recommends libicu72 \
 && rm -rf /var/lib/apt/lists/*

# supercronic für die zeitgesteuerten Jobs im worker-Container
# (Version/Prüfsumme bei Updates anpassen: https://github.com/aptible/supercronic/releases)
ARG SUPERCRONIC_VERSION=v0.2.33
RUN curl -fsSL -o /usr/local/bin/supercronic \
      "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-$(dpkg --print-architecture)" \
 && chmod +x /usr/local/bin/supercronic

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-kalender.ini
COPY docker/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-kalender.conf
COPY docker/entrypoint.sh /usr/local/bin/kalender-entrypoint

WORKDIR /srv/app
COPY --from=vendor /app/vendor vendor/
COPY bin/ bin/
COPY migrations/ migrations/
COPY public/ public/
COPY src/ src/
COPY templates/ templates/
COPY docker/crontab docker/crontab
RUN mkdir -p storage/cache storage/locks storage/archive storage/sessions \
 && chown -R www-data:www-data storage \
 && chmod +x bin/console /usr/local/bin/kalender-entrypoint

USER www-data
ENTRYPOINT ["kalender-entrypoint"]
CMD ["php-fpm"]
