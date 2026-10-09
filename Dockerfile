FROM php:8.2-apache

# Install system dependencies & PHP extensions required for PDO MySQL, MBString, and DOM/OpenGraph scraping
RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libonig-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring dom \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Set PHP timezone to Europe/Oslo (handles DST)
RUN echo "date.timezone = Europe/Oslo" > /usr/local/etc/php/conf.d/timezone.ini
RUN echo "upload_max_filesize = 20M\npost_max_size = 21M" > /usr/local/etc/php/conf.d/uploads.ini

# Enable Apache rewrite module
RUN a2enmod rewrite headers

# Copy project files into Apache web root
COPY . /var/www/html/
# Local api/db.php is excluded from the build; Docker uses environment settings.
COPY docker/db.php /var/www/html/api/db.php

# Set working directory & file permissions
WORKDIR /var/www/html
RUN chown -R www-data:www-data /var/www/html

# Persistent storage with the same /pdf path on disk and over HTTP.
RUN mkdir -p /pdf && chown www-data:www-data /pdf \
    && ln -s /pdf /var/www/html/pdf \
    && chown -h www-data:www-data /var/www/html/pdf
COPY docker/pdf.conf /etc/apache2/conf-available/pdf.conf
RUN a2enconf pdf
VOLUME ["/pdf"]

EXPOSE 80
