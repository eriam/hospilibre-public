# Hospilibre — hôpitaux simulés pour le cours MIAGE

Chaque image contient **un hôpital fictif** qui se construit au premier lancement (90 jours
d'activité passée), puis **continue de vivre en temps réel** : admissions, transferts, sorties,
consultations, bloc opératoire, constantes. Toutes les identités sont fictives.

| Hôpital | Image Docker Hub | Caractère |
|---|---|---|
| CHU de Villefictive | `eriamschaffter/hospilibre-sim:chu` | ~290 lits, 10 services, 6 salles de bloc |
| Clinique du Parc Fictif | `eriamschaffter/hospilibre-sim:clinique` | ~70 lits, chirurgie, ambulatoire dominant |
| Centre SSR des Tilleuls Fictifs | `eriamschaffter/hospilibre-sim:ssr` | 60 lits, longs séjours, aucun bloc |

## Prérequis

Docker avec `docker compose`, **8 Go de RAM**, ~10 Go d'espace disque, et le fichier
`docker-compose.cours.yml` de ce dépôt.

## Lancer

    docker pull eriamschaffter/hospilibre-sim:chu
    HOSPITAL=chu docker compose -f docker-compose.cours.yml up -d

Hors ligne, depuis une archive : `docker load -i hospilibre-sim-chu.tar.gz`, puis lancer avec
`IMAGE=hospilibre-sim` devant la commande (l'archive charge l'image sous ce nom local).

Variante **avec crise** : la crise démarre au premier lancement et se déroule ensuite sur
plusieurs semaines, en temps réel.

    HOSPITAL=chu CRISIS=epidemie docker compose -f docker-compose.cours.yml up -d

| Crise | Prévue pour | Déroulé |
|---|---|---|
| `epidemie` | CHU | ~4 semaines : montée, saturation vers J15–J24, décrue ; lits de renfort en médecine interne |
| `plan_blanc` | clinique | afflux d'urgences chirurgicales pendant 48 h, déprogrammations, lits de renfort |
| `crise_interne` | SSR | ~6 semaines : fermeture de l'aile SSR locomoteur, pénurie de personnel, refus d'admission |

L'application est sur http://localhost:8080 (autre port : `PORT=8081`). Pour faire tourner
plusieurs hôpitaux sur la même machine, donner à chacun un `PORT` différent.

## Patience au premier lancement

L'application répond vite, mais **reste vide** tant que l'hôpital se construit. Suivre l'avancement :

    docker compose -f docker-compose.cours.yml logs -f app | grep '\[sim\]'
    docker compose -f docker-compose.cours.yml exec -u www-data app php bin/console simulate:status 2>&1 | grep -v Deprecated

Attendre `Phase : live`. Durées mesurées sur une machine de 4 Go de RAM (conteneur applicatif limité
à 3 Go), installation comprise : CHU **50 min**, clinique **20 min**, SSR **10 min**.

## Arrêter, reprendre

- `docker compose -f docker-compose.cours.yml stop` puis `start` : au redémarrage, l'hôpital
  **rattrape** le temps écoulé, 14 jours au plus. Au-delà, la période est sautée et signalée
  dans le journal (`files/seed/sim-boot.log`).
- Un arrêt pendant la construction initiale n'est pas grave : elle reprend au lancement suivant.
- `down` garde les données ; `down -v` **efface** l'hôpital, qui sera reconstruit au lancement suivant.

## Pour les missions « données »

La base est une MariaDB locale au conteneur `db` (utilisateur `root`, mot de passe
`hospilibre-root`, base `hospilibre`) :

    docker compose -f docker-compose.cours.yml exec db mariadb -uroot -phospilibre-root hospilibre

Les traces d'une crise sont dans les champs métier ordinaires ; aucune table ne la nomme.

| Phénomène | Où le lire |
|---|---|
| afflux d'urgences | `sejour.provenance = '5'` (passage aux urgences), `sejour.mode_entree = '8'`. **Pas** `sejour.type` : un séjour hospitalisé après les urgences est de type `comp` |
| admission refusée faute de lit | `sejour.annule = '1'` et `sejour.motif_annulation = 'no_lit'` |
| déprogrammation | `operations.annulee = '1'`, `consultation.annule = '1'` |
| lits ouverts ou fermés | `lit.annule` (`'1'` = fermé) ; les lits de renfort sont dans une chambre nommée « Renfort » |
| séjours en cours | `sejour.annule = '0'` et `sejour.sortie_reelle IS NULL` |

Les colonnes booléennes sont des énumérations `'0'`/`'1'` : toujours écrire `annule = '1'`, avec
les apostrophes. Sans elles, MariaDB compare à la *position* dans l'énumération, et le résultat
est faux sans le moindre message d'erreur.

## Pour l'enseignant

    docker compose -f docker-compose.cours.yml exec -u www-data app php bin/console simulate:crisis start plan_blanc 2>&1 | grep -v Deprecated
    docker compose -f docker-compose.cours.yml exec -u www-data app php bin/console simulate:crisis status 2>&1 | grep -v Deprecated
    docker compose -f docker-compose.cours.yml exec -u www-data app php bin/console simulate:crisis stop 2>&1 | grep -v Deprecated

Une seule crise à la fois : `start` refuse tant qu'une crise est active (`stop` d'abord).
**Toujours `-u www-data`** : une commande lancée en root casse l'application (erreurs 500).

Les données sont générées aléatoirement : deux groupes qui lancent le même hôpital obtiennent des
chiffres différents. Le corrigé d'une mission « données » porte donc sur la méthode et sur les
ordres de grandeur, pas sur des valeurs exactes.
