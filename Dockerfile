FROM php:8.2-apache

# Установка системных зависимостей
# Добавлен libicu-dev для расширения intl
RUN apt-get update && apt-get install -y \
    libmariadb-dev \
    libpng-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql gd zip intl

# Копируем Composer из официального образа
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Включаем mod_rewrite
RUN a2enmod rewrite

# Настройка DocumentRoot
ENV APACHE_DOCUMENT_ROOT /var/www/html/www
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

# Копируем файлы и ставим зависимости
COPY . .
RUN composer install --no-interaction --optimize-autoloader

# Права доступа
RUN mkdir -p temp log && chown -R www-data:www-data temp log