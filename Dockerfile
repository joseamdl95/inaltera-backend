FROM php:8.2-apache

# instalar dependencias
RUN apt-get update && apt-get install -y \
    ghostscript \
    git \
    unzip \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev

# configurar GD
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

# extensiones PHP necesarias
RUN docker-php-ext-install mysqli pdo pdo_mysql gd

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

# permisos para phpqrcode (evita error errors.txt)
RUN chmod -R 777 /var/www/html/src/Utils/lib/phpqrcode