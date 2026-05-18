FROM php:8.1-apache

# Install MySQL client and PHP extensions
RUN apt-get update && apt-get install -y \
    default-mysql-client \
    libpng-dev \
    libjpeg-dev \
    libzip-dev \
    libxml2-dev \
    && docker-php-ext-install mysqli pdo pdo_mysql gd zip mbstring xml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers expires deflate

# PHP config
RUN echo "upload_max_filesize = 20M\npost_max_size = 20M\nmax_execution_time = 120\nmemory_limit = 256M\ndisplay_errors = Off\nlog_errors = On" \
    > /usr/local/etc/php/conf.d/99-custom.ini

# Copy app files
COPY . /var/www/html/

# Set permissions
RUN mkdir -p /var/www/html/uploads/faults \
             /var/www/html/uploads/repair_documents \
             /var/www/html/logs \
             /var/www/html/backups \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads /var/www/html/logs /var/www/html/backups

# Apache config - enable AllowOverride for .htaccess
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

# Copy and prepare entrypoint
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
