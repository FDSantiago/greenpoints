FROM php:8.3-cli

ARG NODE_MAJOR=20

# System deps + PHP extensions Laravel/MySQL need
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    zip \
    curl \
    ca-certificates \
    gnupg \
    default-mysql-client \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install pdo pdo_mysql mysqli mbstring zip xml bcmath \
    && rm -rf /var/lib/apt/lists/*

# Node.js + npm
RUN curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Non-root dev user (matches the default "vscode" convention)
RUN useradd -ms /bin/bash vscode
USER vscode

WORKDIR /workspace