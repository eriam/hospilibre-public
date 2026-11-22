# javascript/dist/ est gitignore (généré) et le reste du Dockerfile n'a pas
# Node — sans cette étape, javascript/dist/ n'existe simplement pas dans
# l'image finale et tout le frontend Vue (Appbar, menus, navigation...) est
# cassé (constaté : absent sur staging). Stage séparé pour ne pas alourdir
# l'image finale avec Node/npm/node_modules.
FROM node:22 AS js-builder
WORKDIR /app
COPY package.json package-lock.json ./

# TROIS TENTATIVES, pour la meme raison que composer plus bas : c'etait le dernier gros appel
# reseau nu du build, et le plus expose — des centaines de paquets depuis le registre npm. Un
# hoquet du registre ferait exactement ce que le HTTP 504 de GitHub a fait au build #102 : echec
# dur, image non construite, staging et production sautes.
#
# La reprise est sure ici parce que « npm ci » SUPPRIME node_modules avant de commencer : une
# tentative interrompue ne laisse pas d'etat partiel que la suivante devrait demeler. C'est
# precisement ce qui distingue « npm ci » de « npm install » et ce qui rend le rejeu propre.
#
# Pas de « || true » : apres trois echecs on sort en 1 et le build echoue vraiment.
RUN sh -c 'for tentative in 1 2 3; do \
        npm ci --legacy-peer-deps && exit 0; \
        echo "[build] npm ci: tentative $tentative echouee, nouvelle tentative dans 15 s" >&2; \
        sleep 15; \
    done; \
    echo "[build] npm ci: trois tentatives echouees, abandon" >&2; \
    exit 1'
COPY . .
RUN npm run build

FROM php:8.2.35-apache-trixie

# apt-get enveloppé d'un retry (5 essais, backoff 8s) : le builder de CI a des
# hoquets DNS transitoires ("Could not resolve deb.debian.org") qui faisaient
# échouer le build entier (cf. #70).
#
# Le pecl install de la chaîne a désormais SA PROPRE reprise. Cette défense n'existait
# longtemps qu'ici : on avait durci l'endroit où ça avait cassé, pas la classe — et c'est un appel
# réseau resté nu pendant tout ce temps, à deux lignes d'un appel protégé. Les autres étapes de la
# chaîne (locale-gen, docker-php-ext-*) compilent en local et n'ont rien à reprendre.
RUN for i in $(seq 1 5); do \
      apt-get update && apt-get install -y \
        libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
        libonig-dev libcurl4-openssl-dev libxml2-dev libxslt1-dev unzip git curl locales \
      && break; \
      echo "apt-get echec (tentative $i/5), nouvel essai dans 8s..."; sleep 8; \
      [ "$i" = 5 ] && { echo "apt-get: 5 echecs, abandon"; exit 1; }; \
    done \
  && sed -i '/fr_FR.UTF-8/s/^# //g' /etc/locale.gen \
  && locale-gen \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install pdo_mysql intl mbstring gd zip opcache curl bcmath soap sockets xsl \
  && sh -c 'for t in 1 2 3; do curl -fsSL -o /tmp/apcu.tgz https://github.com/krakjoe/apcu/archive/refs/tags/v5.1.28.tar.gz && exit 0; sleep 10; done; exit 1' \
  && mkdir -p /usr/src/apcu && tar xzf /tmp/apcu.tgz -C /usr/src/apcu --strip-components=1 \
  && (cd /usr/src/apcu && phpize && ./configure && make -j"$(nproc)" && make install) \
  && rm -rf /usr/src/apcu /tmp/apcu.tgz \
  && docker-php-ext-enable apcu \
  && a2enmod rewrite \
  && rm -rf /var/lib/apt/lists/*

# memory_limit : 128M par défaut dans l'image officielle, et c'est trop peu pour ce socle.
# Le seul bootstrap applicatif charge un classmap de 2,4 Mo, un classref de 355 Ko et 727
# entrées de configuration ; mesuré sur cette image, un semis de 1 212 objets mourait déjà sur
# « Allowed memory size of 134217728 bytes exhausted ». L'image de développement pose 1024M
# (dev/Docker/Dockerfile) : la production tournait donc avec huit fois moins, sans que rien ne le
# dise — les commandes d'administration mouraient en code 255 avec leur message sur stderr.
# 512M est un plancher raisonnable pour le service web ; les commandes qui ont besoin de plus
# élèvent la limite elles-mêmes (voir SeedHospi::ensureMemoryLimit).
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/99-memory.ini

# apc.enable_cli=0 par défaut : les commandes bin/console (ex. ox-modules:setup)
# plantent sur "APC must be enabled to use APCUIterator" dès qu'elles touchent
# le cache applicatif, alors que la même extension fonctionne sans problème
# côté Apache/mod_php.
RUN echo "apc.enable_cli=1" > /usr/local/etc/php/conf.d/99-apcu-cli.ini

# fr_FR.UTF-8 est requis par CAppUI::loadCoreLocales() (setlocale(LC_TIME, ...))
# et par extension par Smarty (modifier.date_format.php appelle strftime() natif).
# Sans locale générée, setlocale() échoue silencieusement et les dates
# s'affichent en anglais (ex. "Friday 24 July 2026" au lieu de
# "vendredi 24 juillet 2026") — l'image Debian de base n'a que C/C.utf8/POSIX.
ENV LANG=fr_FR.UTF-8
ENV LC_ALL=fr_FR.UTF-8

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .
COPY --from=js-builder /app/javascript/dist ./javascript/dist

# Étape 1 : installer les dépendances sans scripts (buildClassMap plante sans modules PSR-4)
#
# TROIS TENTATIVES, parce qu'une panne de quelques secondes chez un tiers ne doit pas coûter un
# déploiement. Le build #102 a échoué ainsi : api.github.com a rendu un HTTP 504 sur le zipball de
# symfony/serializer, à 96 % du téléchargement, et composer a abandonné en annonçant
# « Source fallback is disabled. Not trying alternative sources. » Un 504 est transitoire par
# nature ; l'échec, lui, était définitif — image non construite, staging et production sautés.
#
# --prefer-dist garde le comportement rapide, et « || true » n'est surtout PAS employé : après
# trois échecs on sort en 1, donc le build échoue vraiment. Une reprise qui masque l'échec final
# serait pire que pas de reprise du tout.
RUN COMPOSER_ALLOW_SUPERUSER=1 sh -c 'for tentative in 1 2 3; do \
        composer install --no-dev --no-scripts --no-interaction --ignore-platform-reqs --prefer-dist && exit 0; \
        echo "[build] composer install: tentative $tentative echouee, nouvelle tentative dans 15 s" >&2; \
        sleep 15; \
    done; \
    echo "[build] composer install: trois tentatives echouees, abandon" >&2; \
    exit 1'

# Étape 2 : régénérer l'autoloader en laissant preAutoloadDump ajouter les PSR-4 des modules.
# postAutoloadDump (buildClassMap) échoue mais l'autoloader est déjà écrit avec les bons namespaces.
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-interaction 2>&1 | head -5 || true

RUN chown -R www-data:www-data /var/www/html \
  && find /var/www/html -type d -exec chmod 755 {} \; \
  && find /var/www/html -type f -exec chmod 644 {} \; \
  && mkdir -p tmp/errors tmp/shared && chown -R www-data:www-data tmp && chmod -R 775 tmp \
  && sed -i "s|root_dir'\] = '.*'|root_dir'] = '/var/www/html'|" includes/config.php || true

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Profil de simulation par défaut de l'image (cours MIAGE : une image par hôpital).
# « off » pour l'image du pipeline : comportement inchangé. Surchargeable au lancement.
ARG HOSPILIBRE_SIM_PROFILE=off
ENV HOSPILIBRE_SIMULATION=${HOSPILIBRE_SIM_PROFILE}

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
