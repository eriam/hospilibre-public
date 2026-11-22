# Attribution — Jeux de données personas FR (prénoms / noms)

Ces fichiers sont produits **hors base** (aucune écriture en base de données) à partir
de jeux de données open data publiés sous **Licence Ouverte / Etalab**, réutilisables
avec attribution. Ils alimentent `CPersonaSource` (module `populate`) pour générer des
personas fictifs réalistes (patients, personnel) dans l'environnement de simulation.

## `prenoms.csv`

- **Source** : INSEE, « Fichier des prénoms » (national), millésime 2021.
  - Page produit : https://www.insee.fr/fr/statistiques/2540004
  - Fichier téléchargé : https://www.insee.fr/fr/statistiques/fichier/2540004/nat2021_csv.zip
    (`nat2021.csv`, colonnes `sexe;preusuel;annais;nombre`, `sexe` : 1 = masculin, 2 = féminin)
- **Licence** : Licence Ouverte / Open Licence version 2.0 (Etalab) — réutilisation libre
  y compris à des fins commerciales, sous réserve de mention de la source et de la date
  de dernière mise à jour.
- **Date de récupération** : 2026-07-27.
- **Méthode d'agrégation** :
  1. Filtrage du bruit : suppression de la ligne agrégée `_PRENOMS_RARES` (prénoms trop
     rares pour être diffusés nommément par l'INSEE), suppression des prénoms de
     longueur < 2 et des entrées contenant des caractères non alphabétiques (hors tiret
     et apostrophe, utilisés dans les prénoms composés).
  2. Agrégation par prénom distinct (`preusuel`) sur toute la période disponible
     (1900–2021) : somme des occurrences (`nombre`) séparément pour chaque sexe.
  3. Détermination du **sexe dominant** par prénom (sexe ayant cumulé le plus
     d'occurrences).
  4. Seuil de fréquence : conservation des prénoms ayant cumulé **≥ 500 occurrences**
     au total sur la période, pour écarter le bruit statistique résiduel (fautes de
     saisie, prénoms exceptionnellement rares) tout en gardant une liste large et
     réaliste (~5 000 prénoms distincts).
  5. Mise en forme : casse Titre (première lettre majuscule, y compris pour chaque
     partie d'un prénom composé séparée par un tiret ou une apostrophe), tri
     alphabétique (insensible à la casse), dédoublonnage.
  6. **Fréquence conservée** (ajout du 2026-10-04) : le total d'occurrences du sexe dominant
     est écrit en troisième colonne. Il n'y figurait pas, et son absence imposait un tirage
     **uniforme** sur les 5 022 prénoms : « Randy » (1 840 naissances depuis 1900) sortait
     alors aussi souvent que « Marie » (2 232 235). Les cohortes se remplissaient de prénoms
     rares — souvent d'origine étrangère, que l'INSEE enregistre à juste titre puisque des
     Français les portent, mais qui ne composent pas une population vraisemblable. La source
     n'était pas en cause : la façon d'y puiser l'était.
     Le jeu de prénoms n'a PAS été re-dérivé à cette occasion : les 5 022 couples
     prénom/sexe livrés sont conservés à l'identique, seule la colonne de poids est ajoutée.
     Une re-dérivation par la méthode décrite ci-dessus en rend 4 953, tous inclus dans les
     5 022 ; les 69 restants ont un total inférieur à 500 et proviennent d'un seuillage
     appliqué avant dédoublonnage. Les conserver évite toute dérive de données.
- **Format de sortie** : une ligne par prénom, `Prénom;sexe;occurrences` avec `sexe` ∈
  {`f`, `m`} et `occurrences` un entier positif, encodage UTF-8, fin de ligne LF.
  Un fichier à deux colonnes reste lu : le poids vaut alors 1 pour tous, ce qui redonne le
  tirage uniforme.
- **Volume** : 5 022 prénoms distincts, 82 229 188 occurrences cumulées.

## `noms.csv`

- **Source** : data.gouv.fr, jeu de données « Liste de prénoms et patronymes »
  (extraction de la base SIRENE de l'INSEE, publiée pour faciliter l'anonymisation de
  données).
  - Page du jeu de données : https://www.data.gouv.fr/datasets/liste-de-prenoms-et-patronymes
  - Fichier utilisé (patronymes) : https://static.data.gouv.fr/resources/liste-de-prenoms-et-patronymes/20181014-162921/patronymes.csv
    (colonnes `patronyme,count`)
  - Note du producteur du jeu de données : « Aucune vérification du contenu n'est
    faite, et ces listes peuvent contenir des anomalies présentes dans la base
    d'origine » — d'où le nettoyage supplémentaire appliqué ci-dessous.
- **Licence** : Licence Ouverte / Open Licence version 2.0 (Etalab).
- **Date de récupération** : 2026-07-27.
- **Méthode d'agrégation** (top-N par fréquence, avec nettoyage) :
  1. Nettoyage : suppression des entrées de longueur < 2, des entrées contenant des
     chiffres, et des entrées contenant des caractères autres qu'alphabétiques (hors
     tiret, apostrophe et espace, utilisés pour les patronymes composés) — ceci écarte
     le bruit résiduel issu de l'extraction SIRENE (identifiants, sigles, etc.).
  2. Tri par fréquence décroissante (`count`).
  3. Conservation des **3 000** patronymes les plus fréquents (dans la fourchette
     cible 2 000–5 000).
  4. Mise en forme : casse Titre (idem prénoms, y compris sur les parties séparées par
     tiret/apostrophe/espace), dédoublonnage après mise en forme, tri alphabétique
     final (insensible à la casse).
  5. **Fréquence conservée** (ajout du 2026-10-04) : le nombre de porteurs est écrit en
     deuxième colonne. Il avait servi au classement puis avait été jeté, ce qui imposait un
     tirage **uniforme** : un patronyme de la médiane (648 porteurs) sortait aussi souvent
     que « Martin » (31 781), soit un facteur cinquante. Moins spectaculaire que sur les
     prénoms, où le rapport atteint mille deux cents, et faux de la même façon.
     Le jeu n'a PAS été re-dérivé : les 3 000 patronymes livrés sont conservés à
     l'identique, seule la colonne de poids est ajoutée — tous ont été retrouvés dans la
     source, par comparaison insensible à la casse et aux accents.
- **Format de sortie** : une ligne par patronyme, `Patronyme;porteurs` avec `porteurs` un
  entier positif, encodage UTF-8, fin de ligne LF. Un fichier à une seule colonne reste lu :
  le poids vaut alors 1 pour tous, ce qui redonne le tirage uniforme.
- **Volume** : 3 000 patronymes distincts, 3 347 948 porteurs cumulés.

## Exemples de contrôle (échantillons plausibles)

- `prenoms.csv` : `Marie;f`, `Jean;m`, `Camille;f`, `Mohamed;m`, `Sophie;f`,
  `Jean-Pierre;m`.
- `noms.csv` : `Martin`, `Bernard`, `Thomas`, `Durand`, `Dubois`, `Moreau`.

## Reproductibilité

Les fichiers source bruts (`nat2021.csv`, `patronymes.csv`) et les scripts
d'agrégation (Python 3, awk) ont été utilisés dans un répertoire de travail
temporaire (hors dépôt) puis supprimés après validation ; seuls les fichiers agrégés
et nettoyés (`prenoms.csv`, `noms.csv`) sont versionnés dans ce dépôt. Les URLs et la
méthode ci-dessus permettent de reproduire intégralement la génération.
