FROM php:8.2-apache

# instalar dependencias
RUN apt-get update && apt-get install -y \
    ghostscript \
    git \
    unzip \
    curl

# extensiones PHP necesarias
RUN docker-php-ext-install mysqli pdo pdo_mysql

# activar mod_rewrite
RUN a2enmod rewrite

# instalar composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# configurar apache
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# copiar proyecto
COPY . /var/www/html

WORKDIR /var/www/html

# instalar dependencias PHP (aws sdk para R2)
RUN composer install

# permisos para storage
RUN mkdir -p /var/www/html/storage
RUN chmod -R 777 /var/www/html/storage