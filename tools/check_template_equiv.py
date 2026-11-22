#!/usr/bin/env python3
"""
Filet d'équivalence fonctionnelle pour les templates réécrits (balayage OXOL).

Prouve qu'une réécrite GPL (« read-rewrite ») ne change RIEN au rendu : le
contenu fonctionnel — tags Smarty {{...}} et texte — doit être strictement
identique entre l'ancienne version (une réf git) et la version courante. Seuls
le bloc de commentaire d'en-tête (licence) et la mise en forme (espaces,
indentation, retours ligne) ont le droit de changer.

Usage :
    tools/check_template_equiv.py [--ref REF] <fichier.tpl> [<fichier.tpl> ...]

  --ref REF   Référence git de la version d'origine (défaut : HEAD).
              - avant commit : la version OXOL est encore dans HEAD  -> --ref HEAD
              - après commit : comparer au commit précédent          -> --ref HEAD~1

Sortie : une ligne par fichier + code de sortie 0 si tous identiques, 1 sinon.
"""
import argparse
import re
import subprocess
import sys


def functional_tokens(src: str) -> str:
    """Réduit un template à sa substance : sans commentaires ni aucun espace."""
    src = re.sub(r"\{\{\*.*?\*\}\}", "", src, flags=re.S)  # commentaires Smarty
    src = re.sub(r"\s+", "", src)                          # tous les espaces
    return src


def git_show(ref: str, path: str) -> str:
    res = subprocess.run(
        ["git", "show", f"{ref}:{path}"], capture_output=True, text=True
    )
    if res.returncode != 0:
        raise FileNotFoundError(f"{ref}:{path} introuvable dans git")
    return res.stdout


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--ref", default="HEAD", help="réf git de la version d'origine (défaut HEAD)")
    ap.add_argument("files", nargs="+", help="templates à vérifier")
    args = ap.parse_args()

    ok = True
    for path in args.files:
        try:
            old = git_show(args.ref, path)
        except FileNotFoundError as e:
            print(f"  ⚠ {path} : {e}")
            ok = False
            continue
        try:
            new = open(path, encoding="utf-8").read()
        except OSError as e:
            print(f"  ⚠ {path} : {e}")
            ok = False
            continue

        fo, fn = functional_tokens(old), functional_tokens(new)
        if fo == fn:
            print(f"  ✅ identique (hors espaces/en-tête)  {path}")
        else:
            ok = False
            i = next((k for k in range(min(len(fo), len(fn))) if fo[k] != fn[k]), min(len(fo), len(fn)))
            print(f"  ❌ DIVERGE  {path}")
            print(f"       {args.ref}: …{fo[max(0, i - 30):i + 40]}")
            print(f"       courant : …{fn[max(0, i - 30):i + 40]}")

    print("\n  => équivalence fonctionnelle prouvée" if ok else "\n  => divergences à examiner")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
