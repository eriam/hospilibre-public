#!/usr/bin/env python3
"""
audit_gate.py — Porte de non-régression licences (HospiLibre, Phase 1).

Relance audit_licences.py sur l'arbre courant et vérifie que les compteurs OXOL_SEUL
et PROPRIETARY n'AUGMENTENT pas par rapport à une baseline. Le nettoyage de Phase 2 doit
les faire DÉCROÎTRE vers 0 ; toute hausse = régression (réintroduction de code propriétaire).

Usage : python3 tools/audit_gate.py docs/provenance/audit_baseline.json

La baseline est un JSON : {"OXOL_SEUL": 1280, "PROPRIETARY": 11}
Sortie 1 si l'un des deux compteurs dépasse la baseline.
"""
import csv
import json
import os
import subprocess
import sys
import tempfile
from collections import Counter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def tracked_files() -> set:
    """Fichiers suivis par git (la source), pour exclure les artefacts d'install
    (vendor/, includes/* générés, libs JS téléchargées, config.php, files/…) qui
    pollueraient le comptage. L'audit est une propriété de la SOURCE, pas de l'instance."""
    try:
        out = subprocess.run(
            ["git", "-c", "safe.directory=*", "ls-files"], cwd=ROOT, check=True,
            capture_output=True, text=True,
        ).stdout
        return {line.strip() for line in out.splitlines() if line.strip()}
    except (subprocess.CalledProcessError, FileNotFoundError):
        return set()  # pas de git → on ne filtre pas


def current_counts() -> Counter:
    out = os.path.join(tempfile.gettempdir(), "audit_gate.csv")
    subprocess.run(
        [sys.executable, os.path.join(ROOT, "audit_licences.py"), ".", "-o", out],
        cwd=ROOT, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    tracked = tracked_files()
    counts = Counter()
    with open(out, newline="", encoding="utf-8") as f:
        for row in csv.DictReader(f):
            # Ne compter que les fichiers git-trackés (si git dispo)
            if tracked and row["fichier"] not in tracked:
                continue
            counts[row["licence"]] += 1
    return counts


def main() -> int:
    if len(sys.argv) < 2:
        sys.exit("usage: audit_gate.py <baseline.json>")
    with open(sys.argv[1], encoding="utf-8") as f:
        baseline = json.load(f)

    cur = current_counts()
    regressed = False
    for key in ("OXOL_SEUL", "PROPRIETARY"):
        base = int(baseline.get(key, 0))
        now = int(cur.get(key, 0))
        flag = "❌ HAUSSE" if now > base else "ok"
        if now > base:
            regressed = True
        print(f"  {key:<12} baseline={base:<6} courant={now:<6} {flag}")

    if regressed:
        print("Régression licences : du code OXOL/propriétaire a été réintroduit.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
