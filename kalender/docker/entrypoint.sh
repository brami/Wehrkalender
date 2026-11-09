#!/bin/sh
# Startet php-fpm (app) bzw. supercronic (worker). Im app-Container werden vorher
# die Datenbank-Migrationen ausgeführt (RUN_MIGRATIONS=1).
set -e
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  i=0
  until php /srv/app/bin/console db:migrate; do
    i=$((i+1)); [ "$i" -ge 30 ] && { echo "Datenbank nicht erreichbar" >&2; exit 1; }
    sleep 2
  done
fi
exec "$@"
