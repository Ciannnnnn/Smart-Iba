FROM php:8.2-apache

# Required by includes/database.php for Railway MySQL connections.
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

WORKDIR /var/www/html
COPY . /var/www/html/

EXPOSE 80
