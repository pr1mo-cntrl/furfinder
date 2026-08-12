FROM php:8.2-apache

# Install database drivers for PostgreSQL (Supabase) and MySQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql mysqli pdo_mysql

# Raise upload limits - defaults (2M/8M) are too small for phone camera
# photos and were causing lost-pet/pet-photo submissions to silently fail
RUN { \
        echo 'upload_max_filesize = 12M'; \
        echo 'post_max_size = 15M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# Copy your FurFinder files to the server
COPY . /var/www/html/

# Enable Apache rewrite module
RUN a2enmod rewrite

RUN mkdir -p uploads && chmod 777 uploads