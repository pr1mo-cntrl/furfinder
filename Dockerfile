FROM php:8.2-apache

# Install database drivers for PostgreSQL (Supabase) and MySQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql mysqli pdo_mysql

# Copy your FurFinder files to the server
COPY . /var/www/html/

# Enable Apache rewrite module
RUN a2enmod rewrite