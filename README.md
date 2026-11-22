# Hospilibre

> ⚠️ **Logiciel pédagogique — à ne pas utiliser pour soigner.**
>
> - Hospilibre est un support d'enseignement (systèmes d'information hospitaliers). Il n'est
>   **ni certifié, ni marqué CE, ni homologué** comme dispositif médical ou logiciel de santé,
>   et n'a fait l'objet d'aucune certification d'hébergement de données de santé (HDS).
> - **N'y saisissez jamais de données de santé réelles.** Toutes les données des hôpitaux
>   simulés sont fictives.
> - **Ne l'utilisez pas en établissement de santé**, ni pour une prise en charge réelle.
> - Hospilibre est un **fork de Mediboard** (OX Mediboard, © SAS OpenXtrem). Il est maintenu
>   indépendamment, **sans lien avec OpenXtrem ni approbation de sa part**.
>
> *Educational software, not certified, never to be used with real health data or in a
> healthcare facility. Independent fork of Mediboard (© SAS OpenXtrem), not affiliated with
> or endorsed by OpenXtrem.*
>
> Licence : GPL (voir [`COPYING`](COPYING) et [`NOTICE.md`](NOTICE.md)).

## About

Hospilibre is an open source health facility management application, forked from [Mediboard](https://mediboard.org/) (OX Mediboard).

## Requirements

- Apache
- Php
- Mysql
- Pecl
- Composer

## Installation
```
cp .env.example .env            # puis renseigner les mots de passe
composer install --no-dev --optimize-autoloader
npm install
npm run build
composer ox-install-config
composer ox-install-database
bin/console ox-modules:setup --profile=complet
bin/console ox-install:externals --declare --import
```

Les deux dernières étapes manquaient à cette liste. `ox-modules:setup` installe et active le
socle de modules selon un profil d'établissement (`aigu`, `subaigu`, `ssr`, `complet`).
`ox-install:externals` installe les dictionnaires — CCAM, CIM-10, NGAP, communes INSEE… —
dont les archives sont versionnées dans le dépôt : rien à télécharger.

Sans dictionnaire CIM-10, l'écriture d'un diagnostic sur un séjour **interrompt le processus** :
`CSejour::check()` en vérifie l'existence, et une datasource non déclarée déclenche une erreur
fatale que `try/catch` n'intercepte pas. `--declare` ajoute les déclarations manquantes en
reprenant la connexion de `db std`. Pour n'installer qu'une partie :
`--only=ccamV2,cim10`. Pour savoir où l'on en est : `composer ox-diagnose`.

Les images Docker font ces deux étapes seules, au démarrage du conteneur.

### Front End

#### Project setup
```
npm install
```

#### Compiles for development
```
npm run build:dev
```

#### Compiles and minifies for production
```
npm run build
```

#### Run your unit tests
```
npm run test:unit
```

#### Run your unit tests with coverage
```
npm run test:coverage
```

## Links
- Instance state: `/installation`
- OpenApi documentation: `/openapi`
- Devtools: `/devtools`

## ADR (Architecture Decision Record)

ADRs are available in [dev/ADR](./dev/ADR) and must be respected while developping.

## Version
0.5.0

## Licenses
This fork is primarily licensed under the [GNU General Public License](https://www.gnu.org/licenses/gpl.html).
Some files inherited from the original Mediboard codebase remain under the
[OpenXtrem Open License](https://www.openxtrem.com/licenses/oxol.html) (dual GPL/OXOL or OXOL-only);
original copyright notices are preserved in file headers.

See [`NOTICE.md`](./NOTICE.md) for the full attribution, licensing posture, the
remaining OXOL-only files (known open point), and third-party components.
