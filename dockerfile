# Use official PHP with Apache web server
FROM php:8.2-apache

# Install PostgreSQL extensions for PDO
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Enable Apache mod_rewrite for custom routing
RUN a2enmod rewrite

# Copy your source code into Apache's web root
COPY . /var/www/html/

# Expose port 80 to web traffic
EXPOSE 80