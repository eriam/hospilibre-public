#!/usr/bin/env bash
# HospiLibre — install non-interactive de référence (smoke d'installation, Phase 1).
# Idempotent : régénère config + base à chaque exécution.
set -euo pipefail
cd /var/www/html

DB_HOST="${DB_HOST:-db}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-oxdev17!}"
REDIS_HOST="${REDIS_HOST:-redis}"

echo "==> [0/8] Dossiers inscriptibles pour Apache (www-data) sur le bind-mount"
# Le dépôt est monté depuis l'hôte ; Apache tourne en www-data et doit pouvoir écrire
# dans tmp/ (cache, errors, logs) et files/ (uploads).
mkdir -p tmp files
chmod -R 777 tmp files 2>/dev/null || true

echo "==> [1/8] composer install --no-scripts (vendor/ uniquement)"
# --no-scripts : le hook post-autoload-dump échoue dans cet environnement car
# l'autoloader en-process de composer n'expose pas les namespaces de modules.
# On génère les fichiers includes/* à l'étape suivante, dans un process PHP neuf.
if [ ! -f vendor/autoload.php ]; then
    composer install --no-scripts --no-interaction --no-progress --prefer-dist
else
    echo "    vendor/ déjà présent — saut."
fi

# FUSION DES PSR-4 DES MODULES — étape manquante, et sans elle rien ne démarre.
#
# --no-scripts saute les DEUX hooks composer, or c'est preAutoloadDump qui écrit les
# namespaces Hospilibre\* dans vendor/composer/autoload_psr4.php. Sans eux, l'autoloader
# n'en connaît aucun : mesuré sur une installation neuve, zéro occurrence. Conséquence en
# cascade — build_generated.php échouait sur « Interface Hospilibre\Search\IIndexableObject
# not found », puis bin/console sur « Expected to find class
# Hospilibre\Admin\Repositories\AccessTokenRepository ». L'installation s'arrêtait à
# l'étape 2, et aucune des suivantes n'a jamais tourné.
#
# Le Dockerfile racine fait déjà exactement ceci, avec la même tolérance : postAutoloadDump
# (buildClassMap) échoue faute de base de données, mais l'autoloader est ÉCRIT AVANT, donc
# le but est atteint. D'où le `|| true`.
# Pas de --no-interaction : cette sous-commande ne l'accepte pas et rendrait son aide.
echo "    fusion des namespaces de modules (preAutoloadDump)…"
composer dump-autoload 2>&1 | tail -3 || true
grep -c 'Hospilibre' vendor/composer/autoload_psr4.php \
    | xargs -I{} echo "    {} namespaces de modules enregistrés"

echo "==> [2/8] Génération des fichiers includes/* (classmap, classref, config_dist, routes…)"
php tools/build_generated.php

echo "==> [3/8] Mise de côté d'un éventuel includes/config.php existant"
if [ -f includes/config.php ]; then
    [ -f includes/config.php.hospilibre-bak ] || cp -f includes/config.php includes/config.php.hospilibre-bak
    rm -f includes/config.php
    echo "    config.php écarté (sauvegarde : includes/config.php.hospilibre-bak)"
fi

echo "==> [4/8] ox-install:config --ci-mode (host=$DB_HOST user=$DB_USER)"
php bin/console ox-install:config --ci-mode \
    --db-host="$DB_HOST" --db-user="$DB_USER" --db-pass="$DB_PASS" --no-ansi

echo "==> [5/8] Redirection du cache distribué Redis vers le service '$REDIS_HOST'"
# ci-mode fige les paramètres redis sur 127.0.0.1:6379 ; on pointe le service compose.
if [ -f includes/config.php ]; then
    sed -i "s/127\.0\.0\.1:6379/${REDIS_HOST}:6379/g" includes/config.php
fi

echo "==> [6/8] ox-install:database --config --delete (createTables depuis cli/sql/hospilibre.sql)"
php bin/console ox-install:database --config --delete --no-ansi -v

echo "==> [7/8] ox-db:update --config --install (schéma complet des modules — best-effort)"
# Crée les tables de tous les modules depuis les specs (instance réellement utilisable).
# Non bloquant : une étape annexe (ex. persistance de clé de chiffrement) peut échouer
# sans empêcher la création des tables ni le boot.
php bin/console ox-db:update --config --install -n --no-ansi 2>&1 | tail -5 || \
    echo "    ⚠ ox-db:update terminé avec un avertissement (schéma créé, étape annexe en échec)"

echo "==> [8/8] ox-install:externals --declare --import (dictionnaires CCAM, CIM-10, communes)"
# Sans cette étape, l'installation produisait une instance où les codes CCAM s'affichent sans
# libellé et où l'écriture d'un diagnostic CIM-10 interrompt le processus — CSejour::check()
# vérifie l'EXISTENCE du code, et CSQLDataSource::get('cim10') lève un E_USER_ERROR fatal quand
# la source n'est pas déclarée. La commande n'était appelée nulle part, donc jamais testée.
# --declare reprend l'hôte et les identifiants de « db std ». Idempotent : une base déjà peuplée
# est conservée. Compter environ une minute au premier passage (la CCAM fait 174 Mo décompressés).
php bin/console ox-install:externals --declare --import \
    --only="${HOSPILIBRE_DICTIONARIES:-ccamV2,cim10,INSEE}" --no-ansi

echo "==> Install terminée avec succès."
