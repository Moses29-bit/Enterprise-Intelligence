# VANTIQ PHP REST API - Render Docker deployment
# Build context must be the repository root (the folder containing api/, backend/, and composer.json).
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libonig-dev \
    libpng-dev \
    libzip-dev \
    libxml2-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_mysql mysqli mbstring zip gd exif \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Use the repository root as Apache's document root because the existing
# VANTIQ routing convention sends /api/* to api/index.php.
ENV APACHE_DOCUMENT_ROOT=/var/www/html

COPY deploy/render/apache-vantiq.conf /etc/apache2/conf-available/vantiq.conf
RUN a2enconf vantiq

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader

COPY . /var/www/html/

# Ensure Apache can read application files. Do not make secrets world-writable.
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80
CMD ["apache2-foreground"]
