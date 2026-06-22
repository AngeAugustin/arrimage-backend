FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j1 pdo_pgsql zip gd mbstring \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-scripts

COPY . .

RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --classmap-authoritative \
  && cp .env.example .env \
  && mkdir -p var/cache var/log \
  && chmod -R 777 var \
  && chmod +x docker/entrypoint.sh

ENV APP_ENV=prod
ENV APP_DEBUG=0

EXPOSE 8000
ENTRYPOINT ["docker/entrypoint.sh"]
