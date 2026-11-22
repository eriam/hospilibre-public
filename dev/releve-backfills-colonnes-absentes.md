# Relevé : backfills de révision filtrant sur une colonne absente du schéma final

Mesuré le 2026-10-03, par balayage de `modules/*/classes/CSetup*.php` croisé avec le schéma d'une
base **entièrement convergée** (697 tables, 8 827 colonnes). Une colonne absente de cette base
n'existe nulle part à l'arrivée.

## Le motif

```sql
UPDATE `une_table` SET `nouvelle` = '…' WHERE `ancienne` = '1'   -- `ancienne` a disparu
```

Une révision postérieure — parfois la **même** — supprime la colonne sur laquelle le backfill
filtre. La requête rend alors l'erreur 1054 (*Unknown column*), qui n'est pas dans
`ALREADY_IN_TARGET_STATE`, et `CSetup::upgrade()` interrompait le module sur place en enregistrant
sa version partielle.

Deux occurrences ont effectivement bloqué la production :

| Module | Révision | Requête |
|---|---|---|
| `system` | 1.2.10 | `UPDATE cronjob_log SET severity='1' WHERE log IS NOT NULL` |
| `dicom` | 1.09 | `UPDATE dicom_sender SET response='postprocessor' WHERE create_ack_file='1'` |

Coût réel : `admin`, `dPdeveloppement` et `dPetablissement` exigent tous `system >= 1.2.98` et ont
été immobilisés avec lui. **Un seul backfill, quatre modules arrêtés, aucun signal visible.**

## Ce qui a été corrigé

1. Les deux occurrences actives, chacune par une méthode conditionnelle rejouable.
2. **La réaction**, dans `CSetup::upgrade()` : une 1054 sur un `UPDATE`/`DELETE` est toujours
   rapportée comme erreur — et fait désormais échouer `ox-modules:setup` — mais n'interrompt plus
   la progression du module. Signaler n'est pas geler. Strictement limité aux backfills : une 1054
   sur un DDL ou un `SELECT` signale un schéma réellement incohérent et reste fatale.

## Les 40 occurrences restantes

⚠ **Ce sont des risques conditionnels, pas 40 bugs actifs.** Une occurrence ne se déclenche que si
une base se trouve *à* la révision concernée et la rejoue. Un module qui l'a franchie depuis
longtemps ne la rejouera jamais. Elles sont listées pour être priorisées, pas corrigées en bloc —
et depuis le correctif n° 2 ci-dessus, aucune ne peut plus geler un module.

| Module | Table | Colonne absente |
|---|---|---|
| dPcabinet | consultation_anesth | rhesus |
| dPcabinet | consultation | mode_reglement, a_regler, patient_regle |
| dPccam | codage_ccam | codage_ccam_tmp |
| dPcompteRendu | compte_rendu | chir_id |
| dPfacturation | facture_cabinet | bill_printed, justif_printed, facture |
| dPfacturation | facture_etablissement | bill_printed, justif_printed, facture |
| dPfacturation | facture_relance | facture |
| dPfiles | files_hospilibre | file_consultation, file_operation, file_owner |
| dPfiles | files_category | validation_auto |
| dPhospi | affectation | operation_id |
| dPhospi | prestation_journaliere | type_pec |
| dPhospi | prestation_ponctuelle | type_pec |
| dPhospi | observation_medicale | dietetique |
| dPpatients | medecin | specialite |
| dPpatients | patients | medecin1, medecin2, medecin3 |
| dPplanningOp | operations | admis, date_anesth, pat_id, ATNC |
| dPplanningOp | sejour | tmp_operation_id, saisi_SHS |
| dPpmsi | operations | ghm |
| ftp | sender_ftp | create_ack_file, delete_file |
| ftp | sender_sftp | create_ack_file, delete_file |
| hprimxml | echange_hprim | compressed |
| mediusers | users_hospilibre | contrat_acces_soins |
| webservices | source_soap | return_raw |
| webservices | sender_soap | create_ack_file |

31 autres requêtes du même motif se trouvent déjà dans des méthodes plutôt qu'en `addQuery` direct,
dont celles protégées lors des correctifs de `system`.

## Reproduire le relevé

Le balayage n'est pas versionné : il dépend d'une base convergée, qu'un dépôt ne porte pas. Sa
logique tient en trois points — extraire les littéraux `UPDATE`/`DELETE` par `token_get_all`,
résoudre les identifiants qualifiés `` `table`.`colonne` `` et écarter les noms de tables cités dans
les jointures, puis croiser avec `information_schema.COLUMNS`. Sans le deuxième point, le bruit
dépasse le signal : les `WHERE` de mises à jour multi-tables citent des tables, pas des colonnes.
