# ============================================
# Stage 1: Build Vite frontend assets
# ============================================
FROM node:22-alpine AS frontend

WORKDIR /app

# Copy package files first for better Docker caching
COPY package.json ./

# Install frontend dependencies
RUN npm install

# Copy frontend source files
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./

# Build Vite assets
RUN npm run build


# ============================================
# Stage 2: Laravel application
# ============================================
FROM richarvey/nginx-php-fpm:3.1.6

WORKDIR /var/www/html

# Copy Laravel application
COPY . .

# Copy compiled Vite assets from frontend stage
COPY --from=frontend /app/public/build ./public/build


# ============================================
# Environment configuration
# ============================================
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV SKIP_COMPOSER=1
ENV WEBROOT=/var/www/html/public
ENV PHP_ERRORS_STDERR=1
ENV RUN_SCRIPTS=1
ENV REAL_IP_HEADER=1

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr


# ============================================
# PHP memory configuration
# Render Free has 512 MB RAM.
# Give PHP a 256 MB memory limit.
# ============================================
RUN echo "memory_limit=256M" > /usr/local/etc/php/conf.d/99-memory-limit.ini


# ============================================
# Install PHP dependencies
# ============================================
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader


# ============================================
# Laravel writable directories
# PHP-FPM needs permission to write here.
# ============================================
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache


# ============================================
# Start Laravel + Nginx + PHP-FPM
# ============================================
CMD ["/start.sh"]
