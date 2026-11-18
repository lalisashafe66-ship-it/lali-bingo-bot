FROM php:8.2-apache

RUN apt-get update && apt-get install -y libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite

# Copy your project files into Apache's default web directory
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html/

# Ensure correct permissions for SQLite and web files
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
