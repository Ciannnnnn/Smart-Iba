FROM php:8.2-apache

# Required by includes/database.php for Railway MySQL connections.
# PHP's Apache module requires prefork; disable any conflicting MPM modules.
RUN docker-php-ext-install pdo_mysql \
    && (a2dismod mpm_event mpm_worker || true) \
    && a2enmod mpm_prefork rewrite

WORKDIR /var/www/html
COPY . /var/www/html/

EXPOSE 80
