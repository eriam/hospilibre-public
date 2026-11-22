#!/usr/bin/env python3
"""
audit_oxol_deps.py — Analyse de dépendances pour le nettoyage OXOL (Phase 2 lot 4).

Ne modifie AUCUN fichier. Détermine, pour chaque fichier OXOL_SEUL, si du code
NON-OXOL (DUAL_GPL_OXOL, GPL_SEUL, INDETERMINE...) référence sa/ses classe(s),
fonction(s) ou template(s). Le but est de séparer :

  - "isolé"   : rien de ce qui RESTE dans le dépôt n'en dépend (ni code non-OXOL,
                ni un autre fichier OXOL_SEUL lui-même "dépendu" donc conservé)
                → retrait à faible risque, même méthode que les lots 1 et 3.
  - "dépendu" : référencé par au moins un fichier conservé (non-OXOL, ou OXOL_SEUL
                "dépendu" par transitivité) → retrait à risque, nécessite un
                arbitrage humain (réécriture, retrait de la dépendance côté GPL,
                ou conservation justifiée).

Calcul par point fixe : un premier passage marque "dépendu" tout fichier référencé
par du code non-OXOL ; les passages suivants propagent ce statut à tout fichier
"isolé" référencé par un fichier déjà "dépendu", jusqu'à stabilisation. Sans cette
transitivité, un fichier A "isolé" mais utilisé par un fichier B OXOL_SEUL "dépendu"
(donc conservé) serait supprimé à tort — B se retrouverait avec une référence
pendante vers A. Trouvé en pratique lors du lot 4c (CActionReport/CAPITiers.php).

Méthode volontairement simple et vérifiable (regex sur le nom court de classe/
fonction/template, pas d'AST) : mieux vaut sur-signaler des dépendances
(faux positifs -> vérification manuelle supplémentaire, sans risque) que d'en
manquer (faux négatif -> suppression d'un fichier encore utilisé).

Usage :
    python3 tools/audit_oxol_deps.py [rapport_licences.csv]

Produit un CSV (tools/oxol_deps_report.csv) : un fichier OXOL_SEUL par ligne,
avec son statut ("isole"/"depute"), le nombre de références externes trouvées,
et jusqu'à 5 exemples de fichiers non-OXOL qui le référencent.
"""

import csv
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFAULT_CSV = os.path.join(ROOT, "rapport_licences.csv")
SKIP_DIRS = {".git", ".svn", "node_modules", "vendor", "tmp", "cache", "log", "logs", ".idea", ".vscode"}
# .yml/.yaml indispensables : les routes Symfony (modules/*/config/routes/*.yml)
# référencent les contrôleurs par nom de classe complet (controller: Ox\Interop\...\
# CFoo::method) sans qu'aucun fichier PHP/TPL ne les cite explicitement. Absent au
# premier passage (lot 4b) : a fait planter le boot CLI (route vers une classe
# retirée) alors que "isolé" avait été rapporté à tort — corrigé avant tout commit
# grâce à tools/smoke.sh, mais la liste "isolé" issue d'un run sans .yml doit être
# considérée invalide et régénérée.
SEARCHABLE_EXTS = {".php", ".tpl", ".js", ".ts", ".vue", ".yml", ".yaml", ".xml"}


def tracked_files() -> set:
    try:
        out = subprocess.run(
            ["git", "-c", "safe.directory=*", "ls-files"], cwd=ROOT, check=True,
            capture_output=True, text=True,
        ).stdout
        return {line.strip() for line in out.splitlines() if line.strip()}
    except (subprocess.CalledProcessError, FileNotFoundError):
        return set()


def load_licenses(csv_path: str) -> dict:
    """Retourne {chemin_relatif: licence}."""
    licenses = {}
    with open(csv_path, newline="", encoding="utf-8") as f:
        for row in csv.DictReader(f):
            licenses[row["fichier"]] = row["licence"]
    return licenses


_COMMENT_RE = re.compile(r"/\*.*?\*/", re.S)
_LINE_COMMENT_RE = re.compile(r"(?://|#).*$", re.M)


def extract_symbol(path: str) -> str:
    """Nom court de la classe/interface/trait principale d'un fichier PHP,
    ou le nom de fichier sans extension pour un template/script.

    Retire d'abord les commentaires : un docblock du type "Parent class for
    all the generators" avant la vraie déclaration matchait "class for" et
    extrayait le symbole bidon "for" (mot-clé PHP, jamais un nom de classe) —
    faussant tout le calcul de dépendance pour ce fichier. Préfère ensuite,
    parmi les déclarations trouvées, celle dont le nom correspond au nom de
    fichier (convention du projet : CObjectGenerator vit dans
    CObjectGenerator.php) plutôt que la première rencontrée."""
    base = os.path.splitext(os.path.basename(path))[0]
    if not path.endswith(".php"):
        return base

    try:
        with open(os.path.join(ROOT, path), encoding="utf-8", errors="replace") as f:
            content = f.read()
    except OSError:
        return base

    stripped = _LINE_COMMENT_RE.sub("", _COMMENT_RE.sub("", content))
    matches = re.findall(r"\b(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)", stripped)
    if not matches:
        return base
    if base in matches:
        return base
    return matches[0]


# Artefacts générés (gitignorés, donc absents de `git ls-files`/tracked) qui
# enregistrent des classes par nom complet SANS aucune citation dans un fichier
# source suivi par git : includes/legacy_actions.php (CClassMap::buildLegacyActions(),
# table nom d'action AJAX -> FQCN du contrôleur Legacy). Absent au premier passage :
# CDAControllerLegacy (cda) et des contrôleurs Legacy de dPpatients/eai/maternite
# retirés à tort restaient invisibles à l'analyse alors qu'ils gèrent de vraies
# actions utilisateur (ex. editGroupeRhesus, ajax_close_pregnancies,
# ajax_create_cda_vsm). Toujours régénérer ces fichiers avant de lancer cet outil
# (cf. docker-entrypoint.sh pour les commandes de génération).
ALWAYS_INCLUDE_GENERATED = ["includes/legacy_actions.php", "includes/all_routes.yml", "includes/all_services.yml"]


def build_search_corpus(licenses: dict, tracked: set) -> dict:
    """Concatène le contenu de tous les fichiers NON-OXOL_SEUL, par extension,
    pour une recherche par regex. Retourne {ext: texte_concatene}."""
    corpus = {ext: [] for ext in SEARCHABLE_EXTS}
    for relpath, lic in licenses.items():
        if lic == "OXOL_SEUL":
            continue
        if tracked and relpath not in tracked:
            continue
        ext = os.path.splitext(relpath)[1]
        if ext not in SEARCHABLE_EXTS:
            continue
        full = os.path.join(ROOT, relpath)
        try:
            with open(full, encoding="utf-8", errors="replace") as f:
                corpus[ext].append((relpath, f.read()))
        except OSError:
            continue

    for relpath in ALWAYS_INCLUDE_GENERATED:
        ext = os.path.splitext(relpath)[1]
        full = os.path.join(ROOT, relpath)
        if os.path.isfile(full):
            try:
                with open(full, encoding="utf-8", errors="replace") as f:
                    corpus.setdefault(ext, []).append((relpath, f.read()))
            except OSError:
                pass

    return corpus


def code_context_match(symbol: str, text: str) -> bool:
    """Confirme un usage de CODE réel du symbole (par opposition à un mot anglais
    ordinaire qui matche par coïncidence — ex. les classes FHIR nommées d'après
    des ressources génériques comme Patient/Device/Observation/Schedule/Slot
    matchent des descriptions HL7 en anglais courant sans rapport). Un hit brut
    (mot entier) reste rapporté dans tous les cas ; cette fonction sert
    uniquement à ANNOTER la confiance du hit, jamais à le supprimer — mieux vaut
    sur-signaler une dépendance que d'en manquer une (cf. docstring du module)."""
    s = re.escape(symbol)
    patterns = [
        rf"\\{s}\b",                        # namespace complet Ox\...\Symbol (yml/xml generes)
        rf"\b{s}::",                        # appel statique / ::class
        rf"\bnew\s+{s}\b",                  # instanciation
        rf"\b(?:use|extends|implements)\s+[\w\\]*{s}\b",  # import/heritage PHP
        rf"template\s*=\s*[\w/]*{s}\b",     # {{mb_include ... template=sous/dossier/X}}
        rf"""['"][\w/]*{s}(?:\.tpl)?['"]""",  # {{include file="sous/dossier/X.tpl"}} / tpl_exist:'X'
        rf"file\s*=\s*[\w/\"'.$]*{s}\b",    # {{include file=$var|cat:"X.tpl"}} variantes
    ]
    return any(re.search(p, text) for p in patterns)


def find_external_refs(symbol: str, corpus: dict, self_path: str, max_examples: int = 5):
    """Cherche `symbol` comme mot entier dans le corpus non-OXOL. Retourne la
    liste des fichiers correspondants (hors self_path). Un hit sans contexte de
    code confirmé (cf. code_context_match) est préfixé "?" — signal à vérifier
    manuellement avant de faire confiance au statut "depute" qui en découle."""
    if not symbol or len(symbol) < 3:
        return []
    pattern = re.compile(r"\b" + re.escape(symbol) + r"\b")
    hits = []
    for ext, files in corpus.items():
        for relpath, text in files:
            if relpath == self_path:
                continue
            if pattern.search(text):
                label = relpath if code_context_match(symbol, text) else f"?{relpath}"
                hits.append(label)
                if len(hits) >= max_examples:
                    return hits
    return hits


def main():
    csv_path = sys.argv[1] if len(sys.argv) > 1 else DEFAULT_CSV
    if not os.path.isfile(csv_path):
        sys.exit(f"CSV introuvable : {csv_path} (lancer audit_licences.py d'abord)")

    tracked = tracked_files()
    licenses = load_licenses(csv_path)

    oxol_files = sorted(
        relpath for relpath, lic in licenses.items()
        if lic == "OXOL_SEUL" and (not tracked or relpath in tracked)
    )
    print(f"{len(oxol_files)} fichiers OXOL_SEUL a analyser...", file=sys.stderr)

    print("Construction du corpus de recherche (fichiers non-OXOL)...", file=sys.stderr)
    corpus = build_search_corpus(licenses, tracked)
    total_corpus = sum(len(v) for v in corpus.values())
    print(f"Corpus : {total_corpus} fichiers non-OXOL indexes.", file=sys.stderr)

    symbols = {relpath: extract_symbol(relpath) for relpath in oxol_files}

    # Point fixe : un fichier OXOL_SEUL n'est "isole" (retrait sans casse) que si
    # RIEN de ce qui RESTE dans le dépôt n'en dépend — ni le code non-OXOL, ni un
    # AUTRE fichier OXOL_SEUL lui-même "depute" (donc conservé). Un premier passage
    # qui ignore cette transitivité peut classer "isole" un fichier encore utilisé
    # par un fichier OXOL voisin conservé (ex. CActionReport, retiré à tort dans un
    # brouillon de ce lot, référencé par CAPITiers.php qui reste car cité hors OXOL).
    # On boucle : tout fichier "isole" référencé par un fichier déjà "depute" bascule
    # "depute" à son tour, jusqu'à stabilisation.
    depute_refs = {relpath: [] for relpath in oxol_files}
    for relpath in oxol_files:
        depute_refs[relpath] = find_external_refs(symbols[relpath], corpus, relpath)
    statut = {relpath: ("depute" if depute_refs[relpath] else "isole") for relpath in oxol_files}

    def file_text(relpath):
        try:
            with open(os.path.join(ROOT, relpath), encoding="utf-8", errors="replace") as f:
                return f.read()
        except OSError:
            return ""

    round_num = 0
    while True:
        round_num += 1
        depute_now = [relpath for relpath in oxol_files if statut[relpath] == "depute"]
        depute_text = [(relpath, file_text(relpath)) for relpath in depute_now]
        changed = False
        for relpath in oxol_files:
            if statut[relpath] == "depute":
                continue
            symbol = symbols[relpath]
            if not symbol or len(symbol) < 3:
                continue
            pattern = re.compile(r"\b" + re.escape(symbol) + r"\b")
            hits = [
                dep if code_context_match(symbol, text) else f"?{dep}"
                for dep, text in depute_text
                if dep != relpath and pattern.search(text)
            ]
            if hits:
                statut[relpath] = "depute"
                depute_refs[relpath] = hits
                changed = True
        print(f"  point fixe, passage {round_num} : {'des fichiers ont bascule' if changed else 'stable'}", file=sys.stderr)
        if not changed:
            break

    out_path = os.path.join(ROOT, "tools", "oxol_deps_report.csv")
    isole_count = 0
    depute_count = 0

    with open(out_path, "w", newline="", encoding="utf-8") as out:
        writer = csv.writer(out)
        writer.writerow(["fichier", "symbole", "statut", "nb_refs_externes", "exemples"])

        for relpath in oxol_files:
            refs = depute_refs[relpath]
            if statut[relpath] == "isole":
                isole_count += 1
            else:
                depute_count += 1
            writer.writerow([relpath, symbols[relpath], statut[relpath], len(refs), " | ".join(refs[:5])])

    print(f"\nTerminé. Rapport : {out_path}")
    print(f"  isole  : {isole_count} (candidats retrait à faible risque, y compris entre eux)")
    print(f"  depute : {depute_count} (référencés par du code conservé — non-OXOL ou OXOL 'depute')")


if __name__ == "__main__":
    main()
