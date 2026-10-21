FROM php:8.3-cli-bookworm

ARG NODE_MAJOR=20

# System deps + PHP extensions Laravel needs (libaio1 is required by the Oracle client)
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip zip curl ca-certificates gnupg libaio1 \
    libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo mbstring zip xml bcmath \
    && rm -rf /var/lib/apt/lists/*

# Oracle Instant Client (basiclite + sdk + sqlplus) and the OCI8 PHP extension.
# The "latest" client connects to Oracle 19c servers. If the build complains about the client
# version, pick a 19.x/21.x client from Oracle's Instant Client download page instead.
RUN mkdir -p /opt/oracle && cd /opt/oracle \
    && for p in basiclite sdk sqlplus; do \
         curl -fsSLO "https://download.oracle.com/otn_software/linux/instantclient/instantclient-${p}-linuxx64.zip"; \
       done \
    && unzip -qo '*.zip' && rm -f ./*.zip \
    && mv instantclient_* instantclient \
    && echo /opt/oracle/instantclient > /etc/ld.so.conf.d/oracle.conf && ldconfig \
    && ln -s /opt/oracle/instantclient/sqlplus /usr/local/bin/sqlplus \
    && echo 'instantclient,/opt/oracle/instantclient' | pecl install oci8 \
    && docker-php-ext-enable oci8
ENV LD_LIBRARY_PATH=/opt/oracle/instantclient

# Node.js + npm
RUN curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Non-root dev user
RUN useradd -ms /bin/bash vscode
USER vscode

WORKDIR /workspace
