#!/bin/sh
# Startet php-fpm (app) bzw. supercronic (worker). Im app-Container werden vorher
# die Datenbank-Migrationen ausgeführt (RUN_MIGRATIONS=1).
set -e
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  i=0
  until out=$(php /srv/app/bin/console db:migrate 2>&1); do
    case "$out" in
      *"Composer detected issues"*|*"PHP Fatal"*|*"Parse error"*)
        echo "FEHLER: Die Anwendung startet nicht:" >&2; echo "$out" >&2; exit 1 ;;
    esac
    i=$((i+1))
    if [ "$i" -ge 30 ]; then
      echo "FEHLER: Migration nach 30 Versuchen fehlgeschlagen:" >&2; echo "$out" >&2; exit 1
    fi
    echo "Warte auf die Datenbank ($i/30) …" >&2
    sleep 2
  done
  echo "$out"
fi
exec "$@"
