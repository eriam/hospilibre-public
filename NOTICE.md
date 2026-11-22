# NOTICE — Hospilibre

Hospilibre est un **fork** de **Mediboard** (« OX Mediboard »), application de
gestion d'établissement de santé développée par **SAS OpenXtrem**
(<https://www.openxtrem.com/>, <https://mediboard.org/>).

Ce document récapitule l'origine du code, son régime de licence, et les
composants tiers embarqués. Il complète les en-têtes de licence présents dans
chaque fichier source — **qui font foi** — et ne les remplace pas.

---

## 1. Origine et paternité

- **Œuvre d'origine** : Mediboard / OX Mediboard, © SAS OpenXtrem.
- **Ce dépôt** : Hospilibre, fork maintenu indépendamment à des fins pédagogiques,
  sans lien avec SAS OpenXtrem ni approbation de sa part.
- Les mentions de copyright et de licence d'origine (`@author SAS OpenXtrem`,
  `@license …`) présentes dans les en-têtes des fichiers hérités sont
  **conservées telles quelles**, conformément aux obligations de la GPL. Le
  travail de fork (renommage `Ox\Mediboard\*` → `Hospilibre\*`, retrait de
  modules, nettoyage) ne retire ni ne modifie ces mentions d'auteur.

## 2. Régime de licence

Le code hérité de Mediboard porte, fichier par fichier, l'un des régimes
suivants (visibles dans les en-têtes) :

| Régime | Description | Réutilisation |
|---|---|---|
| **GPL + OXOL** (double licence) | Majorité du code. OpenXtrem a explicitement proposé la GPL **au choix**. | Hospilibre retient la **GPL**. |
| **GPL seul** | Fixtures, tests, quelques classes. | GPL. |
| **OXOL seul** | Code du produit commercial OpenXtrem, jamais proposé sous GPL. | **Voir §4 (point ouvert).** |

Le texte de la GPL version 2 figure dans [`COPYING`](COPYING).

**Hospilibre est distribué sous licence GPL** (« GNU General Public License »,
telle que référencée dans les en-têtes via <https://www.gnu.org/licenses/gpl.html>).
Le champ `license` de `composer.json` déclare `GPL-2.0-or-later` comme
approximation lisible par les outils ; la version exacte de la GPL applicable
aux portions héritées n'est pas épinglée de façon univoque dans les en-têtes
amont (« GNU General Public License » + lien générique) et devrait être
confirmée auprès du projet d'origine si cela s'avère juridiquement nécessaire.

> Note : Hospilibre **ne s'appuie pas** sur l'« exception pédagogique »
> (art. L122-5 CPI) comme fondement juridique. Le logiciel relève d'un régime
> de droit d'auteur propre (art. L122-6 CPI) auquel cette exception ne
> s'applique pas directement. La posture retenue repose sur la GPL et la
> conservation des attributions d'origine.

## 3. Travail de nettoyage effectué

Pour amener le fork à un état purement GPL, le code exclusivement sous OXOL a été
éliminé **en totalité**, sans perte de fonctionnalité du socle clinique :

- Fichiers `OXOL seul` : **1280 → 0** (100 %).
- Deux régimes de traitement selon la nature du fichier :
  - **Retrait** des modules OXOL réellement isolés (non dépendus) — phases
    initiales, validées par le filet de non-régression `tools/smoke.sh`.
  - **Ré-expression GPL** des fichiers dépendus par du code conservé :
    - *Templates* (Smarty) : réécrits en équivalents GPL, **équivalence
      fonctionnelle prouvée** (contenu — tags + texte — identique à l'octet près
      hors mise en forme) via `tools/check_template_equiv.py`.
    - *Classes / contrôleurs* : réécrits avec une expression et un style
      distincts de l'original, **comportement préservé** (signatures,
      control-flow, requêtes SQL, effets de bord), sous garde de tests de
      caractérisation, puis **revue adverse d'équivalence** fichier par fichier.
- Vérification : filet `tools/smoke.sh`, tests unitaires PHPUnit, et suite E2E navigateur
  (`tools/e2e/`, Playwright) confirmant le rendu réel des pages.
- Outillage d'audit : `audit_licences.py`, `tools/audit_gate.py` (cliquet
  descendant), `tools/audit_oxol_deps.py`. Baseline :
  `docs/provenance/audit_baseline.json`.

## 4. État — point ouvert OXOL levé

L'ancien point ouvert (fichiers résiduels sous licence OXOL seule) est
**résolu** : il ne subsiste plus aucun fichier de code livré sous licence
propriétaire (`OXOL_SEUL = 0`, vérifiable par `python3 audit_licences.py .`).

Réserve honnête : la ré-expression des templates et classes dépendus s'est faite
**en lisant** le code d'origine (réécriture guardée par tests/équivalence), et non
en *clean-room* strict au sens juridique (reconstruction sans accès à la source).
Le comportement est garanti préservé. Il n'est donc pas exclu que certaines portions
ré-exprimées puissent être regardées comme dérivées du code d'origine distribué sous OXOL.
Ce dépôt est publié à des fins pédagogiques et non commerciales, et ne revendique sur ces
portions aucun droit au-delà de ce que permettent leurs licences d'origine. Tout titulaire
de droits qui estime qu'un fichier excède ce cadre est invité à le signaler par une
*issue* : le fichier sera examiné et, le cas échéant, retiré ou réécrit.

## 5. Composants tiers embarqués

### 5.1 `modules/dPfiles/script/shrinkpdf.sh`
- Licence : **BSD 3-clauses** (compatible GPL).
- Copyright : © 2014, **Alfred Klomp**.
- La mention de copyright et le texte de licence d'origine sont conservés en
  tête du fichier.

### 5.2 Bibliothèques front-end (`lib/`)
Le répertoire `lib/` (non versionné : rempli à l'installation par
`CLibrary::installAll()`) reçoit des bibliothèques JavaScript/CSS tierces sous
diverses licences permissives (MIT, Apache 2.0, BSD, LGPL…), chacune
accompagnée de son propre fichier de licence (`lib/*/LICENSE`, `MIT-LICENSE`,
`COPYING`, etc.). Se reporter à ces fichiers pour les termes exacts et les
copyrights respectifs.

## 6. « PROPRIETARY » — faux positifs

L'audit signale 11 fichiers contenant des motifs du type « all rights
reserved » / « confidentiel ». **Aucun n'est réellement propriétaire** : il
s'agit de chaînes d'interface, de noms de champs, ou du tiers BSD ci-dessus.
Détail dans [`docs/provenance/arbitrage_proprietary.md`](docs/provenance/arbitrage_proprietary.md).

---

*Ce fichier est maintenu à titre de bonne foi documentaire. En cas de doute sur
les droits attachés à un fichier précis, l'en-tête de ce fichier prévaut.*
