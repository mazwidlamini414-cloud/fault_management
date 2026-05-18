FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV APACHE_RUN_USER=www-data
ENV APACHE_RUN_GROUP=www-data
ENV APACHE_LOG_DIR=/var/log/apache2
ENV APACHE_RUN_DIR=/var/run/apache2
ENV APACHE_LOCK_DIR=/var/lock/apache2
ENV APACHE_PID_FILE=/var/run/apache2/apache2.pid

RUN apt-get update && apt-get install -y \
    apache2 \
    php8.1 \
    php8.1-mysql \
    php8.1-gd \
    php8.1-zip \
    php8.1-mbstring \
    php8.1-xml \
    libapache2-mod-php8.1 \
    default-mysql-client \
    unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite php8.1 headers expires deflate

RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    <Directory /var/www/html>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Suppress Apache ServerName warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

RUN echo "upload_max_filesize = 20M\npost_max_size = 20M\nmax_execution_time = 120\nmemory_limit = 256M\ndisplay_errors = Off\nlog_errors = On" \
    > /etc/php/8.1/apache2/conf.d/99-custom.ini

COPY . /var/www/html/

# Strip ALL Windows CRLF line endings from every text file in the image
RUN find /var/www/html -type f \( \
        -name "*.php" -o -name "*.sh" -o -name "*.sql" \
        -o -name "*.html" -o -name "*.css" -o -name "*.js" \
        -o -name "*.json" -o -name "*.md" -o -name "*.txt" \
        -o -name "*.htaccess" -o -name "*.yml" -o -name "*.yaml" \
    \) -exec sed -i 's/\r//' {} \;

RUN mkdir -p /var/www/html/uploads/faults \
             /var/www/html/uploads/repair_documents \
             /var/www/html/logs \
             /var/www/html/backups \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads /var/www/html/logs /var/www/html/backups

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2ctl", "-D", "FOREGROUND"]
