FROM php:8.2-apache AS frontend-assets

# Bundle the same pinned versions used by index.html, including CSS, images and licenses.
# Downloads happen at build time, never when the container starts.
ADD --checksum=sha256:84c65a256e50657896f54c33bd857b6849ebe94c817803be818bf32a3dde0b77 https://registry.npmjs.org/leaflet/-/leaflet-1.9.4.tgz /tmp/leaflet.tgz
ADD --checksum=sha256:fc6b0b1d00b6c708ae54e43ee4a11ac345e41660e19f0a570190bb35babb1a1c https://registry.npmjs.org/leaflet.markercluster/-/leaflet.markercluster-1.5.3.tgz /tmp/markercluster.tgz
RUN mkdir -p /vendor/leaflet-1.9.4 /vendor/leaflet.markercluster-1.5.3 \
    && tar -xzf /tmp/leaflet.tgz -C /vendor/leaflet-1.9.4 --strip-components=1 \
    && tar -xzf /tmp/markercluster.tgz -C /vendor/leaflet.markercluster-1.5.3 --strip-components=1

FROM php:8.2-apache

# Install system dependencies & PHP extensions required for PDO MySQL, MBString, and DOM/OpenGraph scraping
RUN apt-get update && apt-get install -y \
    libxml2-dev \
    libonig-dev \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring dom zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Set PHP timezone to Europe/Oslo (handles DST)
RUN echo "date.timezone = Europe/Oslo" > /usr/local/etc/php/conf.d/timezone.ini
RUN echo "upload_max_filesize = 128M\npost_max_size = 129M\nmemory_limit = 512M" > /usr/local/etc/php/conf.d/uploads.ini

# Enable Apache rewrite module
RUN a2enmod rewrite headers

# Copy project files into Apache web root
COPY . /var/www/html/
COPY --from=frontend-assets /vendor/ /var/www/html/vendor/
# Local api/db.php is excluded from the build; Docker uses environment settings.
COPY docker/db.php /var/www/html/api/db.php

# Keep an untouched template so changing modes on restart is reversible.
COPY index.html /usr/local/share/geonews/index.html
COPY docker/configure-frontend-assets.php /usr/local/lib/geonews/configure-frontend-assets.php
COPY docker/entrypoint.sh /usr/local/bin/geonews-entrypoint
RUN chmod +x /usr/local/bin/geonews-entrypoint
ENV FRONTEND_ASSETS=cdn
ENTRYPOINT ["geonews-entrypoint"]
CMD ["apache2-foreground"]

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
