FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json ./

RUN npm install

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./

RUN npm run build


FROM richarvey/nginx-php-fpm:3.1.6

WORKDIR /var/www/html

COPY . .

COPY --from=frontend /app/public/build ./public/build

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV SKIP_COMPOSER=1
ENV WEBROOT=/var/www/html/public
ENV PHP_ERRORS_STDERR=1
ENV RUN_SCRIPTS=1
ENV REAL_IP_HEADER=1

ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

RUN chmod -R 775 storage bootstrap/cache

CMD ["/start.sh"]
