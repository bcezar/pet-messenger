FROM php:8.2-fpm

# Instala dependências do sistema
RUN apt-get update && apt-get install -y \
    build-essential \
    libpng-dev \
    libjpeg-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    curl \
    git \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring zip exif pcntl

# Instala o Composer
COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

# Define diretório de trabalho
WORKDIR /var/www

# Copia arquivos (opcional no início — será feito via volume)
COPY . .

# Permissões
RUN chown -R www-data:www-data /var/www

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

COPY ./docker/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

# Expondo porta
EXPOSE 9000
