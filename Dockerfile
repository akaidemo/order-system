FROM php:8.2-cli-alpine

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS oniguruma-dev \
    && docker-php-ext-install mbstring \
    && apk del .build-deps

WORKDIR /app
COPY public/ /app/public/
COPY docker-entrypoint.sh /usr/local/bin/order-entrypoint
RUN chmod +x /usr/local/bin/order-entrypoint

ENV DATA_DIR=/data
EXPOSE 8080
ENTRYPOINT ["order-entrypoint"]
