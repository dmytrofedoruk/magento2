FROM php:8.1-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    zlib1g-dev \
    libxml2-dev \
    unzip \
    curl \
    git \
    libxslt-dev

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install extensions
RUN docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath soap sockets intl xsl
RUN docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/
RUN docker-php-ext-install gd

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Setup working directory
WORKDIR /var/www

COPY . ./magento2
WORKDIR /var/www/magento2

RUN composer install

RUN chown -R www-data:www-data /var/www

EXPOSE 80

CMD php -S 0.0.0.0:80 -t /var/www/magento2