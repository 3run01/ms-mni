FROM dunglas/frankenphp:php8.3-bookworm

WORKDIR /var/www/app

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    git \
    unzip \
    librabbitmq-dev \
    libpq-dev \
    supervisor \
    libpng-dev \
    libxml2-dev \
    zlib1g-dev \
    libzip-dev

RUN install-php-extensions \
    gd \
    pcntl \
    opcache \
    pdo \
    pdo_mysql \
    redis

RUN docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql pgsql session xml zip iconv simplexml pcntl gd fileinfo soap

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Instala Node.js 22 e npm globalmente
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs

# Configura npm e instala PM2 com retry
RUN npm config set fetch-timeout 60000 \
    && npm config set fetch-retries 5 \
    && npm config set fetch-retry-mintimeout 20000 \
    && npm config set fetch-retry-maxtimeout 120000 \
    && npm install -g pm2 || (sleep 10 && npm install -g pm2) || (sleep 20 && npm install -g pm2)

# Habilita o provider legacy do OpenSSL 3.x
RUN sed -i -e '$a\' /etc/ssl/openssl.cnf
RUN printf "\n[openssl_init]\nproviders = provider_sect\n\n[provider_sect]\ndefault = default_sect\nlegacy = legacy_sect\n\n[default_sect]\nactivate = 1\n\n[legacy_sect]\nactivate = 1\n" >> /etc/ssl/openssl.cnf

# Cria diretórios necessários para o supervisord
RUN mkdir -p /var/log/supervisor /etc/supervisord.d

# Define o comando para iniciar o supervisord
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
