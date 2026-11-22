#!/usr/bin/env python3
"""
audit_licences.py — Inventaire des licences d'un clone Hospilibre (ou autre arbre source).

Usage :
    python3 audit_licences.py /chemin/vers/hospilibre [-o rapport.csv]

Produit :
  - un CSV détaillé (un fichier source par ligne : chemin, module, licence détectée, indice)
  - une synthèse par module sur stdout, avec signalement des modules contenant
    du code OXOL ou propriétaire (à retirer avant toute redistribution)

Stdlib uniquement, aucun paquet à installer.
"""

import argparse
import csv
import os
import re
import sys
from collections import Counter, defaultdict

# Extensions considérées comme du code source / des assets porteurs d'en-têtes
SOURCE_EXTS = {
    ".php", ".js", ".ts", ".css", ".scss", ".less", ".html", ".tpl",
    ".xml", ".sql", ".sh", ".py", ".ini", ".yml", ".yaml", ".twig",
}

# Répertoires à ignorer
# `tools` = outillage de dev/audit du fork (audit_gate.py, smoke.sh, audit_oxol_deps.py…),
# pas du code produit livré. Ces scripts contiennent forcément la chaîne « OXOL »
# puisqu'ils l'auditent, ce qui les faisait compter à tort en OXOL_SEUL. On mesure
# la licence du code livré, pas de son propre instrument de mesure.
# `lib` = librairies JS tierces (CKEditor, Leaflet, PapaParse…) installées par
# CLibrary::installAll(), au même titre que `vendor` pour PHP : 563 fichiers, `lib/*` est
# dans .gitignore et aucun n'est suivi par git. Elles étaient pourtant comptées, ce qui
# faisait passer PROPRIETARY de 11 à 106 dès que l'audit tournait APRÈS l'installation —
# c'est-à-dire toujours, puisque tools/smoke.sh s'exécute après dev/Docker/install.sh. Le
# cliquet, lui, a été relevé sur un arbre sans `lib`, d'où une régression permanente et
# fantôme. On mesure la licence du code du dépôt, pas celle de ses dépendances installées.
SKIP_DIRS = {".git", ".svn", "node_modules", "vendor", "lib", "tmp", "cache", "log", "logs", ".idea", ".vscode", "tools"}

# Nombre d'octets lus en tête de fichier pour chercher l'en-tête de licence
HEADER_BYTES = 8192

# Motifs de détection, par ordre de priorité (le premier qui matche gagne)
PATTERNS = [
    ("OXOL",        re.compile(r"\bOXOL\b|OpenXtrem\s+Open\s+Licen[cs]e", re.I)),
    ("PROPRIETARY", re.compile(r"all\s+rights\s+reserved|tous\s+droits\s+r[ée]serv[ée]s|proprietary|confidentiel", re.I)),
    ("GPL",         re.compile(r"GNU\s+General\s+Public\s+Licen[cs]e|\bGPLv?[23]?\b|www\.gnu\.org/licenses", re.I)),
    ("LGPL",        re.compile(r"Lesser\s+General\s+Public|\bLGPL\b", re.I)),
    ("CECILL",      re.compile(r"\bCeCILL\b", re.I)),
    ("MIT",         re.compile(r"\bMIT\s+Licen[cs]e\b", re.I)),
    ("APACHE",      re.compile(r"Apache\s+Licen[cs]e", re.I)),
    ("BSD",         re.compile(r"\bBSD\s+Licen[cs]e|Redistribution\s+and\s+use\s+in\s+source", re.I)),
    ("CC",          re.compile(r"Creative\s+Commons|CC[- ]BY", re.I)),
]

# Pour le contexte : capture la 1re ligne contenant le mot 'licen[cs]e'
LICENSE_LINE = re.compile(r"^.*licen[cs]e.*$", re.I | re.M)


def detect_license(path: str):
    """Retourne (licence, extrait) pour un fichier donné."""
    try:
        with open(path, "rb") as f:
            raw = f.read(HEADER_BYTES)
        text = raw.decode("utf-8", errors="replace")
    except OSError as e:
        return "ERREUR_LECTURE", str(e)

    gpl = bool(re.search(r"GNU\s+General\s+Public|gnu\.org/licenses|\bGPLv?[23]?\b", text, re.I))
    oxol = bool(re.search(r"\bOXOL\b|OpenXtrem\s+Open\s+Licen[cs]e", text, re.I))
    if gpl and oxol:
        m = LICENSE_LINE.search(text)
        return "DUAL_GPL_OXOL", (m.group(0).strip()[:120] if m else "")
    if oxol:
        return "OXOL_SEUL", ""
    if gpl:
        return "GPL_SEUL", ""
    for name, pattern in PATTERNS:
        if pattern.search(text):
            m = LICENSE_LINE.search(text)
            excerpt = m.group(0).strip()[:120] if m else ""
            return name, excerpt
    return "INDETERMINE", ""

def module_of(relpath: str) -> str:
    """Rattache un fichier à un 'module' : modules/<nom>, sinon le 1er répertoire, sinon '.'"""
    parts = relpath.split(os.sep)
    if len(parts) >= 2 and parts[0] == "modules":
        return f"modules/{parts[1]}"
    return parts[0] if len(parts) > 1 else "."


def main():
    ap = argparse.ArgumentParser(description="Inventaire des licences d'une arborescence source.")
    ap.add_argument("root", help="Racine du dépôt à analyser")
    ap.add_argument("-o", "--output", default="rapport_licences.csv", help="CSV de sortie (défaut: rapport_licences.csv)")
    args = ap.parse_args()

    root = os.path.abspath(args.root)
    if not os.path.isdir(root):
        sys.exit(f"Répertoire introuvable : {root}")

    rows = []
    per_module = defaultdict(Counter)
    license_files = []

    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
        for fn in filenames:
            full = os.path.join(dirpath, fn)
            rel = os.path.relpath(full, root)

            # Fichiers LICENSE/COPYING : toujours inventoriés
            base = fn.lower()
            if base.startswith(("license", "licence", "copying")):
                lic, excerpt = detect_license(full)
                license_files.append((rel, lic))
                rows.append((rel, module_of(rel), lic, excerpt, "fichier de licence"))
                continue

            ext = os.path.splitext(fn)[1].lower()
            if ext not in SOURCE_EXTS:
                continue

            lic, excerpt = detect_license(full)
            mod = module_of(rel)
            per_module[mod][lic] += 1
            rows.append((rel, mod, lic, excerpt, ""))

    # CSV détaillé
    with open(args.output, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["fichier", "module", "licence", "extrait_entete", "note"])
        w.writerows(sorted(rows))

    # Synthèse
    total = Counter()
    for c in per_module.values():
        total.update(c)

    print(f"\n=== Synthèse globale ({sum(total.values())} fichiers source analysés) ===")
    for lic, n in total.most_common():
        print(f"  {lic:<12} {n:>6}")

    if license_files:
        print("\n=== Fichiers LICENSE/COPYING trouvés ===")
        for rel, lic in sorted(license_files):
            print(f"  [{lic}] {rel}")

    # Modules à problème : au moins un fichier OXOL ou PROPRIETARY
    flagged = {m: c for m, c in per_module.items() if c.get("OXOL") or c.get("PROPRIETARY")}
    print("\n=== Modules contenant du code OXOL / propriétaire (à retirer du fork) ===")
    if flagged:
        for mod in sorted(flagged):
            c = flagged[mod]
            detail = ", ".join(f"{k}:{v}" for k, v in c.most_common())
            print(f"  ⚠ {mod}  ({detail})")
    else:
        print("  (aucun)")

    # Modules majoritairement indéterminés : à vérifier à la main
    suspicious = sorted(
        (m for m, c in per_module.items()
         if c.get("INDETERMINE", 0) > 0.8 * sum(c.values()) and sum(c.values()) >= 5),
    )
    print("\n=== Modules sans en-tête de licence sur >80% des fichiers (vérif. manuelle) ===")
    if suspicious:
        for mod in suspicious:
            c = per_module[mod]
            print(f"  ? {mod}  ({sum(c.values())} fichiers, {c.get('INDETERMINE',0)} indéterminés)")
    else:
        print("  (aucun)")

    print(f"\nRapport détaillé : {args.output}")


if __name__ == "__main__":
    main()
