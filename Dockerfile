FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_sqlite
COPY . /var/www/html/
EXPOSE 80
