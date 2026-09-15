FROM php:8.3-cli-alpine

RUN apk add --no-cache curl-dev sqlite-dev \
    && docker-php-ext-install curl pdo_sqlite

WORKDIR /app
COPY . /app
RUN mkdir -p /app/var/log && chown -R www-data:www-data /app/var

USER www-data
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "public/index.php"]
