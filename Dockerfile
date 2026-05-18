# ─────────────────────────────────────────────────────────────
#  BUSIQUIP Fault Management System — Production Docker Image
#  PHP 8.2 + Apache
# ─────────────────────────────────────────────────────────────
FROM php:8.2-apache

# ── System dependencies ──────────────────────────────────────
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libzip-dev \
    unzip \
    default-mysql-client \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd mysqli pdo pdo_mysql zip \
    && a2enmod rewrite \
   && a2dismod mpm_event || true \
   && a2enmod mpm_prefork \
    && a2dismod mpm_event || true \
    && a2enmod mpm_prefork \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ── Apache config — serve from / (no subfolder) ──────────────
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    <Directory /var/www/html>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# ── PHP settings ──────────────────────────────────────────────
RUN echo "upload_max_filesize = 20M\n\
post_max_size = 20M\n\
max_execution_time = 120\n\
memory_limit = 256M\n\
display_errors = Off\n\
log_errors = On" > /usr/local/etc/php/conf.d/custom.ini

# ── Copy application source ───────────────────────────────────
COPY . /var/www/html/

# ── Writable upload/log directories ──────────────────────────
RUN mkdir -p /var/www/html/uploads/faults \
             /var/www/html/uploads/repair_documents \
             /var/www/html/logs \
             /var/www/html/backups \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads \
                    /var/www/html/logs \
                    /var/www/html/backups

# ── Entrypoint: auto-import DB on first boot ─────────────────
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
