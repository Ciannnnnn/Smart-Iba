FROM php:8.2-apache

# Required by includes/database.php for Railway MySQL connections.
# PHP's Apache module requires exactly one MPM. Reset the enabled MPM links
# before enabling the only compatible one, prefork.
RUN docker-php-ext-install pdo_mysql \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork rewrite

WORKDIR /var/www/html
COPY . /var/www/html/

EXPOSE 80
