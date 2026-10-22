FROM php:8.3-cli

# Match your host user so files created in the container aren't root-owned
ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
        libonig-dev libxml2-dev default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring bcmath zip gd exif pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd -g ${GID} app && useradd -u ${UID} -g ${GID} -m -s /bin/bash app

WORKDIR /var/www/html

# Cache dependency install layer (only matters if you ever run without the bind mount)
COPY --chown=app:app composer.json composer.lock* ./
USER app
RUN composer install --no-interaction --no-scripts --prefer-dist || true

COPY --chown=app:app . .

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]