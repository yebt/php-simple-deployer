FROM php:7.4-apache

# System dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    zip \
    unzip \
    curl \
    libzip-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install \
    zip \
    mbstring

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Allow .htaccess overrides
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --no-interaction --optimize-autoloader 2>/dev/null || true

EXPOSE 80
