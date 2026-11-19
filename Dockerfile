FROM php:8.2-apache

# Install SQLite extensions
RUN apt-get update && apt-get install -y libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite

# Copy custom Apache configuration
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

# Copy all project files into Apache's web root
COPY . /var/www/html/

# Prepare persistent data directory and enable Apache rewrite module
RUN mkdir -p /var/data \
    && a2enmod rewrite \
    && chown -R www-data:www-data /var/www/html /var/data \
    && chmod -R 755 /var/www/html \
    && chmod 775 /var/data

EXPOSE 80
