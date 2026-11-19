# docker build -t lihzorg/php-fpm-docker:$(date +%Y%m%d-%H%M) .
FROM ubuntu:26.04

ARG DEBIAN_FRONTEND=noninteractive

ENV TZ=Asia/Shanghai \
    PHP_VER=8.5 \
    NVM_DIR=/root/.nvm \
    NODE_MAJOR=24

RUN set -eux; \
    mkdir -p /data/srv /wwwroot; \
    cd /data; \
    ln -fs /usr/share/zoneinfo/Asia/Shanghai /etc/localtime; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
    lsb-release software-properties-common git wget curl ca-certificates tzdata \
    locales-all nano htop p7zip-full gzip tar sudo redis-tools ffmpeg; \
    curl -fsSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg; \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
    php${PHP_VER}-cli \
    php${PHP_VER}-fpm \
    php${PHP_VER}-ast \
    php${PHP_VER}-bcmath \
    php${PHP_VER}-bz2 \
    php${PHP_VER}-common \
    php${PHP_VER}-curl \
    php${PHP_VER}-dba \
    php${PHP_VER}-gd \
    php${PHP_VER}-http \
    php${PHP_VER}-igbinary \
    php${PHP_VER}-lz4 \
    php${PHP_VER}-mbstring \
    php${PHP_VER}-memcache \
    php${PHP_VER}-memcached \
    php${PHP_VER}-msgpack \
    php${PHP_VER}-mysql \
    php${PHP_VER}-odbc \
    php${PHP_VER}-pgsql \
    php${PHP_VER}-raphf \
    php${PHP_VER}-readline \
    php${PHP_VER}-redis \
    php${PHP_VER}-soap \
    php${PHP_VER}-sqlite3\
    php${PHP_VER}-uuid \
    php${PHP_VER}-xml \
    php${PHP_VER}-yaml \
    php${PHP_VER}-zip \
    php${PHP_VER}-zstd \
    php${PHP_VER}-gmp \
    php${PHP_VER}-imagick \
    php${PHP_VER}-intl \
    php${PHP_VER}-smbclient \
    php${PHP_VER}-mongodb \
    php${PHP_VER}-apcu \
    php${PHP_VER}-ldap \
    php${PHP_VER}-swoole; \
    sed -i "s#^listen = .*#listen = 0.0.0.0:9000#" /etc/php/${PHP_VER}/fpm/pool.d/www.conf; \
    sed -i 's/^;pm.max_requests = 500/pm.max_requests = 500/' /etc/php/${PHP_VER}/fpm/pool.d/www.conf; \
    sed -i 's/^;listen.backlog = 511/listen.backlog = 8192/' /etc/php/${PHP_VER}/fpm/pool.d/www.conf; \
    printf '%s\n' \
        'date.timezone = Asia/Shanghai' \
        'memory_limit = 512M' \
        'upload_max_filesize = 300M' \
        'post_max_size = 300M' \
        'max_execution_time = 60' \
        > /etc/php/${PHP_VER}/fpm/conf.d/99-custom.ini; \
    printf '%s\n' \
        'date.timezone = Asia/Shanghai' \
        'memory_limit = 512M' \
        > /etc/php/${PHP_VER}/cli/conf.d/99-custom.ini; \
    curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o /tmp/composer.phar; \
    curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar.sha256sum -o /tmp/composer.phar.sha256sum; \
    ( cd /tmp && sha256sum -c composer.phar.sha256sum ); \
    install -m 0755 /tmp/composer.phar /usr/local/bin/composer; \
    rm -f /tmp/composer.phar /tmp/composer.phar.sha256sum; \
    curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/refs/heads/master/install.sh | bash; \
    set +u; \
    . "${NVM_DIR}/nvm.sh"; \
    nvm install "${NODE_MAJOR}"; \
    npm i -g pnpm; \
    set -u; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

COPY srv/api /data/srv/api
COPY srv/ui /data/srv/ui

RUN set -eux; \
    cd /data/srv/api; \
    composer install --no-dev --prefer-dist --no-interaction --no-progress; \
    cd /data/srv/ui; \
    set +u; \
    . "${NVM_DIR}/nvm.sh"; \
    pnpm install --frozen-lockfile; \
    pnpm build; \
    cp -a dist/. /data/srv/api/public

WORKDIR /data/srv/api
ENTRYPOINT ["/usr/bin/php8.5", "/data/srv/api/main.php"]
