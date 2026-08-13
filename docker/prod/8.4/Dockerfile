# =============================================================================
# Laravel Sail - Alpine (Desenvolvimento)
# =============================================================================
FROM oven/bun:1-alpine AS bun

# =============================================================================
FROM dunglas/frankenphp:1-php8.4-alpine

LABEL maintainer="Pedro Viana"

ARG WWWGROUP=1000
ARG POSTGRES_VERSION=17

WORKDIR /var/www/html

ENV TZ=America/Sao_Paulo
ENV SUPERVISOR_PHP_COMMAND="/usr/local/bin/php -d variables_order=EGPCS /var/www/html/artisan octane:start --server=frankenphp --host=0.0.0.0 --port=80 --admin-port=2019"
ENV SUPERVISOR_PHP_USER="sail"
ENV PANTHER_CHROME_DRIVER_BINARY="/usr/lib/chromium/chromedriver"
ENV CHROME_BIN="/usr/bin/chromium-browser"
ENV PYTHONWARNINGS="ignore::UserWarning:supervisor.options"

# Instalar helper para extensões PHP
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Timezone
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Dependências do sistema
RUN apk update && apk upgrade && \
    apk add --no-cache \
    bash \
    su-exec \
    curl \
    wget \
    ca-certificates \
    zip \
    unzip \
    git \
    supervisor \
    sqlite \
    libcap \
    shadow \
    tzdata \
    procps \
    ncdu \
    screen \
    nano \
    python3 \
    py3-pip \
    py3-setuptools \
    libsodium-dev \
    brotli \
    librsvg \
    ffmpeg \
    chromium \
    chromium-chromedriver \
    # Playwright dependencies for Chromium on Alpine
    nss \
    nspr \
    libstdc++ \
    libx11 \
    libxcb \
    libxext \
    libxdamage \
    libxfixes \
    libxkbcommon \
    libxrender \
    libxrandr \
    libxtst \
    libxscrnsaver \
    libxau \
    libxdmcp \
    libgcc \
    glib \
    libgpg-error \
    libgcrypt \
    libcrypto3 \
    libssl3 \
    at-spi2-core \
    alsa-lib \
    libxcomposite \
    libxcursor \
    libxi \
    # Build tools for native dependencies
    build-base \
    gcc \
    g++ \
    make \
    libc-dev

# Extensões PHP (mesmo conjunto da produção + xdebug/pcov para dev)
RUN install-php-extensions \
    bcmath \
    bz2 \
    exif \
    gd \
    igbinary \
    intl \
    ldap \
    mbstring \
    memcached \
    opcache \
    pcntl \
    pcov \
    pdo_pgsql \
    pgsql \
    redis \
    sockets \
    soap \
    uv \
    vips \
    xdebug \
    zip

# excimer é opcional (profiling do Sentry) e o PECL já publicou versão sem REST
# xml, quebrando o build inteiro. Tenta a última, cai para a 1.2.5 e segue sem
# profiling se as duas falharem.
RUN (install-php-extensions excimer \
        || install-php-extensions excimer-1.2.5 \
        || echo 'AVISO: excimer indisponivel no PECL; imagem sem profiling do Sentry') && \
    docker-php-source delete && \
    rm -rf /var/cache/apk/* /tmp/* /var/tmp/*

# Xdebug config (desabilitado por padrão)
RUN echo -e "xdebug.mode=off\n\
    xdebug.client_host=host.docker.internal\n\
    xdebug.client_port=9003\n\
    xdebug.start_with_request=yes" > /usr/local/etc/php/conf.d/99-xdebug.ini

# PostgreSQL Client
RUN apk add --no-cache postgresql${POSTGRES_VERSION}-client || apk add --no-cache postgresql16-client

# Remover certificado cliente PostgreSQL herdado do root (processo roda como sail)
RUN rm -rf /root/.postgresql

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Bun (do stage 1)
COPY --from=bun /usr/local/bin/bun /usr/local/bin/bun
RUN ln -sf /usr/local/bin/bun /usr/local/bin/bunx

# Node.js + pnpm
RUN apk add --no-cache nodejs npm && \
    npm install -g pnpm

# Install Playwright browsers globally for all users
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
RUN mkdir -p /ms-playwright && chmod 777 /ms-playwright && \
    npx playwright install chromium && \
    chmod -R 755 /ms-playwright

# Capabilities para bind em portas baixas
RUN setcap "cap_net_bind_service=+ep" /usr/local/bin/php

# Symlink para compatibilidade (alguns scripts procuram em /usr/bin/php)
RUN ln -sf /usr/local/bin/php /usr/bin/php

# Criar grupo sail (ignora erro se GID já existir e usa grupo existente)
RUN (addgroup -g $WWWGROUP sail 2>/dev/null || addgroup sail) \
    && adduser -D -s /bin/bash -G sail -u 1337 sail \
    && addgroup sail root \
    && git config --global --add safe.directory /var/www/html \
    && su sail -c "git config --global --add safe.directory /var/www/html"

RUN chown -R sail:sail /var/www/html






RUN apk add --no-cache \
    firefox \
    geckodriver

ENV GECKODRIVER_PATH="/usr/bin/geckodriver"
ENV XDG_CONFIG_HOME="/config"
ENV XDG_DATA_HOME="/data"

# Playwright configuration
ENV PWDEBUG=0

# Diretório de logs do supervisor
RUN mkdir -p /var/log/supervisor

# Copiar arquivos de dependências primeiro para maximizar cache do Docker
COPY --chown=sail:sail composer.json composer.lock ./
COPY --chown=sail:sail package.json package-lock.json* ./

# Instalar dependências PHP (sem dev dependencies para produção)
RUN php -d memory_limit=-1 /usr/bin/composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

# Copiar código da aplicação
COPY --chown=sail:sail . .

# Remover cache de pacotes descobertos que pode conter referências a pacotes dev
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php 2>/dev/null || true

# Gerar autoload otimizado após copiar código
RUN php -d memory_limit=-1 /usr/bin/composer dump-autoload --optimize --no-dev --no-scripts

# Instalar dependências Node e build do frontend
RUN npm ci && \
    npm run build && \
    rm -rf node_modules

# Copiar configurações PHP customizadas
COPY docker/prod/8.4/php.ini /usr/local/etc/php/conf.d/custom.ini

# Copiar scripts de inicialização
COPY docker/prod/8.4/start-container /usr/local/bin/start-container
COPY docker/prod/8.4/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
RUN chmod +x /usr/local/bin/start-container

# Garantir permissões corretas
RUN chown -R sail:sail /var/www/html && \
    chmod -R 755 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80/tcp

ENTRYPOINT ["start-container"]
