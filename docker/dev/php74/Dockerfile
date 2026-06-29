FROM php:7.4-apache

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    FIREFLY_PATH=/var/www/firefly-iii

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gettext-base \
        git \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libldap2-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libxml2-dev \
        libzip-dev \
        locales \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure ldap --with-libdir=lib/$(gcc -dumpmachine)/ \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        gd \
        intl \
        ldap \
        mbstring \
        opcache \
        pdo_mysql \
        pdo_pgsql \
        zip \
    && pecl install redis-5.3.7 \
    && docker-php-ext-enable redis \
    && a2enmod rewrite headers \
    && printf '%s\n' \
        'de_DE.UTF-8 UTF-8' \
        'en_US.UTF-8 UTF-8' \
        'es_ES.UTF-8 UTF-8' \
        'fr_FR.UTF-8 UTF-8' \
        'id_ID.UTF-8 UTF-8' \
        'it_IT.UTF-8 UTF-8' \
        'nl_NL.UTF-8 UTF-8' \
        'pl_PL.UTF-8 UTF-8' \
        'pt_BR.UTF-8 UTF-8' \
        'ru_RU.UTF-8 UTF-8' \
        'tr_TR.UTF-8 UTF-8' \
        'zh_TW.UTF-8 UTF-8' \
        'zh_CN.UTF-8 UTF-8' > /etc/locale.gen \
    && locale-gen \
    && curl -sS https://getcomposer.org/installer | php -- --2.2 --install-dir=/usr/local/bin --filename=composer \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY docker/dev/apache/firefly.conf /etc/apache2/sites-available/000-default.conf
COPY docker/dev/php74/php.ini /usr/local/etc/php/conf.d/firefly-dev.ini

WORKDIR ${FIREFLY_PATH}
