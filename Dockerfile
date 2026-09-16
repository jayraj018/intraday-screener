# =========================
# Stage 1: Build Vite assets
# =========================
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json ./

RUN npm install

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./

RUN npm run build


# =========================
# Stage 2: Laravel
# =========================
FROM richarvey/nginx-php-fpm:3.1.6

WORKDIR /var/www/html

COPY . .

# Copy compiled Vite assets
COPY --from=frontend /app/public/build ./public/build

# Allow Composer to run as root
ENV COMPOSER_ALLOW_SUPERUSER=1

# Laravel / Nginx configuration
ENV SKIP_COMPOSER=1
ENV WEBROOT=/var/www/html/public
ENV PHP_ERRORS_STDERR=1
ENV RUN_SCRIPTS=1
ENV REAL_IP_HEADER=1

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr

# Install PHP dependencies
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# Laravel storage permissions
RUN chmod -R 775 storage bootstrap/cache

CMD ["/start.sh"]
