# Arbitrage des fichiers « PROPRIETARY » (Phase 2)

`audit_licences.py` classe en `PROPRIETARY` tout fichier dont l'en-tête (8 premiers Ko)
contient `all rights reserved`, `tous droits réservés`, `proprietary` ou `confidentiel`.
Ce motif attrape des **chaînes d'interface** et des **noms de champ**, pas seulement des
licences. Inspection fichier par fichier du déclencheur réel.

Verdict : **les 11 fichiers `PROPRIETARY` sont des faux positifs. PROPRIETARY réel = 0.**
Aucun retrait nécessaire.

| Fichier | Ligne déclenchante | Nature | Verdict |
|---|---|---|---|
| `locales/fr/common.php` | `$locales['All rights reserved'] = 'Tous droits réservés';` ; `'CMbFieldSpec.confidential' = 'Confidentiel'` | Traduction d'UI | **Faux positif — conserver** |
| `locales/fr-be/common.php` | idem (traduction) | Traduction d'UI | **Faux positif — conserver** |
| `locales/it/common.php` | idem (traduction) | Traduction d'UI | **Faux positif — conserver** |
| `locales/nl-be/common.php` | idem (traduction) | Traduction d'UI | **Faux positif — conserver** |
| `modules/dPfiles/locales/fr-be.php` | `'CFile-private' = 'Confidentiel'` | Traduction d'UI | **Faux positif — conserver** |
| `modules/dPfiles/locales/nl-be.php` | `'CFile-private' = 'Confidentiel'` | Traduction d'UI | **Faux positif — conserver** |
| `modules/admin/resources/default_rgpd_conf.xml` | « vos codes d'accès sont strictement confidentiels » | Texte de notice RGPD (contenu applicatif) | **Faux positif — conserver** |
| `modules/dPfiles/script/shrinkpdf.sh` | `# All rights reserved.` | Script tiers **sous licence BSD 3-clauses** (cf. ligne « Licensed under the 3-clause BSD license »). GPL-compatible. | **Faux positif — conserver ; à citer dans NOTICE.md (BSD-3, tiers)** |
| `modules/dPpatients/tests/Functional/data/patient_sejour.xml` | `<field name="presence_confidentielle">0</field>` | Nom de champ BDD dans une fixture de test | **Faux positif — conserver** |
| `modules/dPpmsi/tests/Functional/data/patient_sejour.xml` | `presence_confidentielle` | Nom de champ BDD (fixture) | **Faux positif — conserver** |
| `modules/dPplanningOp/config/routes/default.yml` | `sejour_presence_confidentielle: 0|1` | Nom de champ dans une définition de route | **Faux positif — conserver** |

## Conséquences
- Le compteur `PROPRIETARY = 11` de l'audit est entièrement constitué de faux positifs
  documentés ci-dessus → **objectif « PROPRIETARY = 0 après arbitrage » atteint**.
- `modules/dPfiles/script/shrinkpdf.sh` est un composant tiers réel sous **BSD 3-clauses** :
  à mentionner dans `NOTICE.md` (Phase 4) au titre des dépendances tierces embarquées.
- Aucune action de retrait. La baseline `audit_baseline.json` conserve `PROPRIETARY: 11`
  (faux positifs stables) ; la porte d'audit vérifie la non-augmentation.
