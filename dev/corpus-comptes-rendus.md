# Le corpus de comptes rendus : ce qu'il est, et comment on l'étend

## Ce que le semis fait, et ce qu'il ne fait pas

`ox-seed:hospi` écrit deux documents par intervention — un compte rendu opératoire signé du
chirurgien, un compte rendu d'anesthésie signé de l'anesthésiste — en **assemblant** :

- les **faits**, lus dans le jeu de données : date, acte et son code CCAM, durée prévue, classe ASA,
  cadre réglé ou non, incident peropératoire, date de visite pré-anesthésique ;
- la **prose**, tirée de `cli/resources/comptes-rendus/corpus.json`.

**Aucun modèle de langage n'intervient pendant le semis.** C'est structurel : à graine égale, le
semis doit produire le même jeu de données, et un appel à un service le rendrait irreproductible.
Le corpus est un fichier de **données**, versionné dans le dépôt, relu avant d'entrer.

## La règle qui gouverne tout : les faits ne sont jamais dans la prose

Un fragment du corpus **ne doit porter aucun fait**. Pas d'âge, pas de date, pas de durée, pas de
nombre de paquets hémorroïdaires, pas de taille de prothèse.

La raison n'est pas stylistique. Un fragment est réemployé des dizaines de fois : un chiffre écrit
dedans contredit le dossier dès le deuxième emploi. Et le lecteur qui le croirait apprendrait que le
dossier se contredit — ce qui est **plus nuisible qu'un compte rendu absent**, puisqu'il jette un
doute sur le reste.

`ReportCorpus` l'impose à chaque chargement, et refuse de démarrer sinon :

| Contrôle | Ce qu'il refuse |
|---|---|
| aucun chiffre | tout fragment contenant `\d` |
| substitutions fermées | toute `{substitution}` absente de `placeholders` |
| couverture des incidents | tout incident de `SurgicalIncidents` sans paragraphe |
| fragments génériques | l'absence de `generique` dans `ouvertures` ou `gestes` |

L'interdiction des chiffres est **grossière à dessein** : il n'existe pas de règle fine qui
distingue le chiffre inoffensif de celui qui contredit le dossier. Interdire la classe entière coûte
quelques tournures — « en deux temps » plutôt que « en 2 temps » — et rend la garantie vérifiable
d'un coup d'œil.

## La structure : composition, pas énumération

Un compte rendu opératoire est assemblé de quatre sections :

```
ouverture  +  geste  +  [paragraphe d'incident]  +  fermeture
```

Trois fragments de chaque donnent déjà 27 combinaisons par acte sans incident, et quatre fois plus
avec les quatre incidents du catalogue. **Vingt variantes par intervention ne demandent donc pas
vingt textes par acte** — ce qui compte pour un texte à vocation médicale, où le volume écrit doit
rester humainement relisible.

Le compte rendu d'anesthésie suit la même logique : `anesthesie_ouvertures` +
`anesthesie_conduites` + `anesthesie_reveils`.

### Les clés

- `gestes` est indexé par **code CCAM**, avec une clé `generique` obligatoire.
- `ouvertures` l'est aussi, mais seule `generique` est employée aujourd'hui.
- `installations`, `fermetures`, et les trois sections d'anesthésie sont des listes plates.
- `incidents` est indexé par le nom d'incident de `SurgicalIncidents` : `conversion`,
  `hemorragie`, `plaie_organe`, `anesthesie`.

### Les substitutions admises

Déclarées dans `placeholders`, et `CompteRenduBuilder::fill()` doit savoir remplir **chacune** —
une substitution non remplie apparaîtrait telle quelle dans le dossier du patient, ce qui est pire
qu'une phrase absente.

| Substitution | Remplie par |
|---|---|
| `{acte}` | `operations.libelle` |
| `{asa}` | `operations.ASA`, en chiffres romains (« ASA II ») |
| `{installation}` | une entrée de `installations`, **stable** pour une intervention donnée |
| `{duree}` | la durée prévue en minutes |
| `{chirurgien}`, `{anesthesiste}` | réservées, vides aujourd'hui |

`{installation}` est dérivée de l'identifiant de l'intervention et non tirée au sort : sans cela,
l'ouverture du compte rendu opératoire et celle du compte rendu d'anesthésie décriraient deux
installations différentes pour la même intervention.

## État de la couverture

Le corpus livré écrit les gestes des **huit actes de chirurgie viscérale** que le décor français
emploie réellement. `FrenchCoding` en déclare **77**, sur quatorze spécialités : les soixante-neuf
autres retombent sur le geste `generique`, qui est honnête mais peu spécifique.

**Ce manque est rapporté par le semis**, ligne « comptes rendus opératoires », qui distingue les
actes ayant un geste propre des actes génériques. C'est délibéré : un corpus entièrement générique
ressemblerait autrement à un corpus complet.

## Étendre le corpus par un modèle de langage

C'est un travail **hors ligne**, dont le produit est une modification de `corpus.json` — aucun code
à toucher.

### Ce qui a été décidé

- Modèle de base suffisant : un `gemma`-9B sur une 4090, ou équivalent loué à l'heure.
- Objectif : une vingtaine de variantes exploitables par intervention, obtenues par **composition**
  — donc environ trois gestes par code CCAM, pas vingt.
- Priorité aux 69 actes sans geste propre, par spécialité, en commençant par celles que les décors
  emploient le plus.

### Le gabarit de requête

Pour chaque code CCAM, fournir au modèle : le **code**, son **libellé officiel CCAM** (table
`p_acte`, colonne `LIBELLELONG`) et le libellé du décor. Demander **trois** descriptions du temps
opératoire, et énoncer les contraintes **dans la requête**, pas seulement en relecture :

> Rédige trois descriptions distinctes du temps opératoire de l'acte CCAM `<code>`
> (« `<libellé officiel>` »), telles qu'elles figureraient dans un compte rendu opératoire français.
>
> Contraintes impératives :
> - AUCUN chiffre, en toutes lettres comme en chiffres arabes : ni âge, ni date, ni durée, ni
>   dimension, ni dosage.
> - Aucun nom de patient, de praticien, d'établissement.
> - Aucune mention d'incident ou de complication : les incidents sont ajoutés séparément, et un
>   récit d'incident non étayé par les données contredirait le dossier.
> - Aucune conclusion ni suite opératoire : la fermeture est une section distincte.
> - Le temps opératoire seulement : abord, exploration, geste, contrôle.
> - Entre trois et six phrases, au présent de narration, à la voix impersonnelle.
> - Vocabulaire chirurgical français courant ; pas d'abréviation non explicitée.

### Une règle que le validateur n'impose PAS : la fermeture n'est pas dans le geste

Un fragment de `gestes` doit s'arrêter au contrôle de l'hémostase. La fermeture, le pansement et le
compte des textiles appartiennent à `fermetures`.

C'est une faute facile — **dix-sept des vingt-sept gestes écrits à la main la commettaient**, et le
résultat se lisait tout seul : « …Drainage, fermeture plan par plan. » suivi de « Fermeture plan par
plan. Pansement. ». Elle est corrigée dans le corpus livré.

Elle n'est **pas** vérifiée mécaniquement, et c'est délibéré : « fermeture du péritoine » ou
« fermeture du plan profond » sont des temps opératoires légitimes au milieu d'un geste. Un contrôle
sur le mot refuserait des fragments justes, et un contrôle sur la dernière phrase serait contournable
d'une virgule. La règle est donc dans la requête au modèle et dans cette page, pas dans le code.

### La relecture est obligatoire

Le validateur attrape les chiffres et les substitutions inconnues. Il **n'attrape pas** :

- une **erreur anatomique** (« repérage de l'uretère » sur une chirurgie de l'épaule) ;
- une **incohérence de voie d'abord** (un geste décrit par laparotomie sous un code cœlioscopique) ;
- une mention d'incident formulée sans le mot « complication » ;
- un geste qui n'est pas celui du code.

Ces quatre défauts demandent un œil clinique, et c'est la raison pour laquelle l'extension du corpus
est une tâche de **relecture assistée**, pas de génération automatique. Un fragment faux est plus
nuisible qu'un fragment générique : il apprend quelque chose de faux à qui le lit.

### Incrémenter `version`

Changer le contenu change les données produites à graine égale. `version` rend ce changement
lisible dans un journal de semis, au lieu d'une divergence inexplicable entre deux jeux. La ligne
« comptes rendus » du rapport affiche la version.

## Ce qui reste ouvert

- Les **69 actes** sans geste propre.
- La **variété des actes** elle-même : le décor `small` n'emploie que huit codes CCAM pour 694
  actes. Enrichir le corpus a peu d'intérêt avant d'élargir le catalogue d'actes, car c'est lui qui
  borne la variété perçue — et la CCAM compte 4 090 actes thérapeutiques.
- Les **comptes rendus de consultation** (visite ambulatoire, consultation pré-anesthésique) :
  `CConsultAnesth` porte déjà ses champs structurés, mais aucun document n'y est attaché.
