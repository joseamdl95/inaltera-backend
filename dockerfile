FROM php:8.2-apache

# instalar dependencias
RUN apt-get update && apt-get install -y ghostscript

# extensiones PHP necesarias
RUN docker-php-ext-install mysqli pdo pdo_mysql

# activar mod_rewrite
RUN a2enmod rewrite

# configurar apache
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# copiar proyecto
COPY . /var/www/html

WORKDIR /var/www/html

# permisos para storage
RUN chmod -R 777 /var/www/html/storage