#!/usr/bin/env bash
# Cycle de vie de l'hôpital simulé dans le conteneur. Lancé en ARRIÈRE-PLAN par l'entrypoint,
# SOUS www-data (jamais root : un bin/console root crée tmp/locks en root:root -> 500 côté Apache).
#
#   1er démarrage (J0) : semis du profil + N jours d'historique, puis crise éventuelle, puis tick.
#   Redémarrages       : rattrapage du temps écoulé (plafonné), puis tick.
#   Semis interrompu   : purge puis nouveau semis (jamais deux hôpitaux).
#
# PAR TRANCHES, ET CE N'EST PAS UN DÉTAIL : simulate:advance grossit d'environ 100 Mo par jour
# simulé dans un même processus (mesuré : CHU tué par l'OOM au 12e jour ; clinique au-delà de
# 512 Mo avant 14 jours). Chaque tranche de 3 jours est donc un processus neuf (pic mesuré
# 423–517 Mo, d'où -d memory_limit=1G), relancé jusqu'à ce que l'horloge rejoigne maintenant.
#
# Chaque étape est tolérante : un échec est journalisé, le conteneur et Apache restent debout.
set -u

ROOT="${ROOT:-/var/www/html}"
PROFILE="${HOSPILIBRE_SIMULATION:-off}"
HISTORY="${HOSPILIBRE_SIM_HISTORY_DAYS:-90}"
MAXC="${HOSPILIBRE_SIM_MAX_CATCHUP_DAYS:-14}"
TICK="${HOSPILIBRE_SIM_TICK:-900}"
DELAY="${HOSPILIBRE_SIM_DELAY:-0}"
CHUNK=3

LOG_DIR="$ROOT/files/seed"
LOG="$LOG_DIR/sim-boot.log"
# Marqueur « J0 terminé » (historique complet + crise éventuelle posée). Dans files/, un volume :
# il vit aussi longtemps que la base. Tant qu'il manque, un redémarrage REPREND le J0 (historique
# sans plafond, puis crise) au lieu d'un rattrapage plafonné qui sauterait l'historique restant.
J0_DONE="$LOG_DIR/sim-j0.done"
mkdir -p "$LOG_DIR"

# Console SANS mode debug et SANS dépréciations, pour ces processus seulement (le site web n'est
# pas concerné). Mesuré en PHP 8.2 : en APP_DEBUG=1, la console accumule les milliers de
# dépréciations par jour simulé (propriétés dynamiques…) et une tranche d'UN jour dépasse 800 Mo
# (tuée par l'OOM) ; avec APP_DEBUG=0 seul, 116 000 lignes « Deprecated » par jour inondent le
# journal. Les deux réglages ensemble : tranche de 3 jours en 12 s, pic 120 Mo, journal propre.
export APP_DEBUG=0
PHP_CLI=(php -d memory_limit=1G -d "error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED")

say() { echo "[sim] $(date '+%F %T') $1" | tee -a "$LOG"; }
console() { "${PHP_CLI[@]}" "$ROOT/bin/console" "$@" >> "$LOG" 2>&1; }
phase() { "${PHP_CLI[@]}" "$ROOT/bin/console" simulate:status --phase 2>>"$LOG" | tail -n 1; }

# Avance par tranches jusqu'à « maintenant ». $1 = plafond de rattrapage (0 = illimité, semis J0).
# Le plafond ne joue qu'à la première tranche (il saute le trou) ; garde-fou d'itérations contre
# une boucle infinie si l'horloge cessait d'avancer.
catch_up() {
    local cap="$1" n=0 max_iter
    max_iter=$(( (HISTORY > MAXC ? HISTORY : MAXC) / CHUNK + 5 ))
    while [ "$(phase)" = "catching-up" ] && [ "$n" -lt "$max_iter" ]; do
        n=$((n + 1))
        if ! console simulate:advance --until=now --max-days="$CHUNK" --max-catchup-days="$cap"; then
            say "tranche $n en échec (voir journal) : le prochain tick reprendra"
            return 1
        fi
        say "tranche $n : $("${PHP_CLI[@]}" "$ROOT/bin/console" simulate:status 2>/dev/null | tail -n 1)"
    done
}

[ "$PROFILE" = "off" ] && exit 0

echo "====================================================================" >> "$LOG"
say "démarrage du conteneur — profil $PROFILE, crise « ${HOSPILIBRE_CRISIS:-aucune} »"

if [ "$DELAY" -gt 0 ] 2>/dev/null; then
    say "attente de ${DELAY}s avant de simuler"
    sleep "$DELAY"
fi

# Phase lue en base. Une sortie inattendue (base injoignable, identifiants refusés…) n'est PAS une
# phase : on réessaie quelques fois puis on abandonne — jamais d'historique sur un état inconnu
# (constaté : une erreur de connexion prise pour une phase lançait la génération sans semis).
current=""
for attempt in 1 2 3 4 5 6; do
    current=$(phase)
    case "$current" in none|bootstrapping|catching-up|live) break ;; esac
    say "état de simulation illisible (« ${current:0:120} »), nouvel essai dans 20 s ($attempt/6)"
    sleep 20
done
case "$current" in
    none|bootstrapping|catching-up|live) ;;
    *) say "base injoignable : simulation NON démarrée (voir files/seed/sim-boot.log)"; exit 1 ;;
esac

if [ "$current" = "bootstrapping" ]; then
    say "semis interrompu détecté : purge de l'hôpital partiel avant de ressemer"
    console simulate:reset || say "purge en échec (voir journal)"
    current="none"
fi

if [ "$current" = "none" ]; then
    rm -f "$J0_DONE"
    say "J0 : semis du profil $PROFILE avec ${HISTORY} jours d'historique (patience : jusqu'à une heure)"
    if ! console simulate:bootstrap --profile="$PROFILE" --origin="-${HISTORY} days"; then
        say "ÉCHEC du semis — simulation arrêtée (voir files/seed/sim-boot.log)"
        exit 1
    fi
fi

if [ ! -f "$J0_DONE" ]; then
    say "génération de l'historique par tranches de ${CHUNK} jours…"
    catch_up 0 || { say "historique incomplet : il reprendra au prochain démarrage"; }
    if [ "$(phase)" != "live" ]; then
        say "historique incomplet — reprise au prochain démarrage ; ticks suspendus d'ici là"
        exit 1
    fi
    if [ -n "${HOSPILIBRE_CRISIS:-}" ]; then
        if console simulate:crisis start "$HOSPILIBRE_CRISIS"; then
            say "crise « $HOSPILIBRE_CRISIS » démarrée à J0"
        else
            say "crise « $HOSPILIBRE_CRISIS » refusée (nom inconnu ?) : simulation NOMINALE"
        fi
    fi
    date '+%F %T' > "$J0_DONE"
else
    say "redémarrage : rattrapage du temps écoulé (plafond ${MAXC} jours)"
    catch_up "$MAXC"
fi

say "$("${PHP_CLI[@]}" "$ROOT/bin/console" simulate:status 2>/dev/null | tail -n 1)"

while sleep "$TICK"; do
    console simulate:advance --until=now --max-days="$CHUNK" --max-catchup-days="$MAXC" \
        || say "tick en échec (voir journal)"
done
