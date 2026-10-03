FROM php:8.2-apache

# Install system dependencies & PHP extensions required for PDO MySQL, MBString, and DOM/OpenGraph scraping
RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libonig-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring dom \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy project files into Apache web root
COPY . /var/www/html/

# Set working directory & file permissions
WORKDIR /var/www/html
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
