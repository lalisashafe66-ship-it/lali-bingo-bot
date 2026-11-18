FROM php:8.2-apache

# Install SQLite extensions
RUN apt-get update && apt-get install -y libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite

# Copy all project files into Apache's web root
COPY . /var/www/html/

# Explicitly set Apache document root to /var/www/html
RUN sed -i 's!/var/www/html!/var/www/html!g' /etc/apache2/sites-available/*.conf
RUN sed -i 's!/var/www/!/var/www/html/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Enable Apache modules
RUN a2enmod rewrite

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
