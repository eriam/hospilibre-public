# HospiLibre — image de référence pour le filet de tests (Phase 1)
# PHP 8.1 = borne haute de la plage déclarée par composer.json (^7.4 || 8.1.*).
# Le PHP local de la machine de dev est 8.2 et ne satisfait PAS cette contrainte :
# tout le volet dynamique (composer install, boot, install BDD, PHPUnit) passe par cette image.
FROM php:8.1-apache

# Dépendances système nécessaires à la compilation des extensions PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libxml2-dev \
        libxslt1-dev \
        libonig-dev \
        git \
        unzip \
        default-mysql-client \
        python3 \
    && rm -rf /var/lib/apt/lists/*

# Extensions PHP exigées par composer.json (ext-*) + runtime (pdo_mysql, apcu, redis).
# TROIS EXTENSIONS QUE composer.json NE DÉCLARE PAS, alors que le code les utilise :
#   soap    — webservices, hl7, eai, ihe, dPfiles (SoapClient, SoapFault, SoapHeader, SoapVar)
#   sockets — core/classes/Socket/SocketServer.php (la classe Socket, depuis PHP 8)
#   xsl     — modules/cda/classes/CCdaTools.php (XSLTProcessor)
# Sans elles, tools/check_refs.php signale quatorze références pendantes et le filet passe
# au rouge — le cliquet ayant été relevé dans un environnement qui les avait.
# Déjà fournies/activées par l'image officielle : ctype, curl, dom, fileinfo, iconv,
# json, libxml, openssl, pdo, zlib. À compiler : gd, intl, mbstring, pdo_mysql, zip, bcmath.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        mbstring \
        pdo_mysql \
        mysqli \
        zip \
        bcmath \
        soap \
        sockets \
        xsl \
    && pecl install apcu redis \
    && docker-php-ext-enable apcu redis opcache

# Composer (depuis l'image officielle)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Apache : activer rewrite/headers et autoriser les .htaccess de Hospilibre
RUN a2enmod rewrite headers \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

# Réglages PHP adaptés à Hospilibre (SIH lourd)
RUN { \
        echo 'memory_limit=1024M'; \
        echo 'max_execution_time=300'; \
        echo 'post_max_size=64M'; \
        echo 'upload_max_filesize=64M'; \
        echo 'date.timezone=Europe/Paris'; \
        echo 'apc.enable_cli=1'; \
        echo 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_STRICT'; \
        echo 'display_errors=Off'; \
        echo 'log_errors=On'; \
    } > /usr/local/etc/php/conf.d/zz-hospilibre.ini

WORKDIR /var/www/html
