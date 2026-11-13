#!/usr/bin/env bash
# =============================================================================
#  setup-server-wehrkalender.sh – Einrichtung eines Servers für den Wehrkalender
#
#  Läuft auf DEINEM Rechner und richtet den vServer per SSH ein.
#  Unterstützte Systeme auf dem Server: Debian 13 (trixie) und Ubuntu LTS.
#  Reproduzierbar: Jeder Schritt prüft, ob er schon erledigt ist, und kann
#  beliebig oft erneut ausgeführt werden.
#
#  Was das Skript macht
#    1. Verbindung prüfen (zuerst als root, nach der Härtung als juno)
#       und DNS-Einträge vorab prüfen (fehlende werden ausgegeben)
#    2. System aktualisieren, Zeitzone, Hostname, automatische Updates
#    3. Admin-Benutzer "juno" mit SSH-Schlüssel und sudo anlegen
#    4. Firewall (ufw: 22, 80, 443) und fail2ban
#    5. Docker Engine + Compose-Plugin
#    6. Verzeichnis /srv/wehrkalender mit Caddy-Testseite (HTTPS),
#       optional zusätzlich eine Test-Domain (-T)
#    6a. MariaDB mit Datenbanken wehrkalender und wehrkalender_test
#    6b. Tägliche Datensicherung, verschlüsselt mit restic: vorerst lokal in
#        /var/backups/wehrkalender-restic, später extern (-B)
#    7. Anmeldung als juno testen – erst DANACH SSH härten
#       (kein root-Login, keine Passwörter, nur juno)
#    8. Anwendung installieren bzw. aktualisieren: wehrkalender*.zip
#       (liegt neben diesem Skript oder per -Z) wird hochgeladen, nach
#       /srv/wehrkalender/app entpackt (Versionen unter /srv/wehrkalender/releases),
#       Docker-Image gebaut, app + worker gestartet, Datenbank migriert.
#       Vorher wird automatisch eine Datensicherung erstellt.
#       Umgebung umschalten mit -E prod|test (auch ohne neues ZIP).
#    9. Abschlussprüfung und Zusammenfassung
#
#  Protokolle
#    lokal:    ./logs/setup-<host>-<zeitstempel>.log
#    Server:   /var/log/wehrkalender-setup/<zeitstempel>.log
#
#  Einstellungen
#    stehen in einer Datei neben dem Skript (Standard: wehrkalender.env, sonst
#    -f <datei>). Optionen beim Aufruf überschreiben sie und werden dort
#    gespeichert; Werte für die Anwendung (APP_NAME, WEHR_NAME, MAIL_*,
#    BETREIBER_* …) übernimmt das Skript bei jedem Lauf nach /srv/wehrkalender/.env.
#    Fehlt die Datei, legt das Skript sie mit allen Schlüsseln (auskommentiert) an.
#
#  Aufruf
#    ./setup-server-wehrkalender.sh [-f <datei>] [Optionen]
#
#  Beispiele
#    Erstinstallation als Testsystem mit Testdaten (speichert -H -d -T -n -E):
#    ./setup-server-wehrkalender.sh -H 203.0.113.10 -d kalender.example.de \
#                      -T kalender-test.example.de -n wehrkalender -E test -X -a michael.bramer
#
#    Neue Version der Anwendung einspielen (alles andere aus der Datei):
#    ./setup-server-wehrkalender.sh -U
#
#    Geänderte Einstellungen übernehmen, ohne neues ZIP:
#    ./setup-server-wehrkalender.sh -U -Z ""
#
#    Auf Produktion umstellen (wird in der Datei gespeichert):
#    ./setup-server-wehrkalender.sh -U -Z "" -E prod
#
#    Nur prüfen (ändert nichts, speichert nichts):
#    ./setup-server-wehrkalender.sh -c
# =============================================================================
set -Eeuo pipefail

# ----------------------------- Voreinstellungen ------------------------------
ADMIN_USER="juno"
TIMEZONE="Europe/Berlin"
SSH_PORT="22"
NEW_HOSTNAME=""
SERVER=""
DOMAIN=""
TEST_DOMAIN=""
ACME_EMAIL=""
BACKUP_REPO=""
SKIP_HARDEN=0
ASSUME_YES=0
CHECK_ONLY=0
APP_ZIP=""            # leer = neueste wehrkalender*.zip neben dem Skript
APP_ZIP_GIVEN=0       # 1 = -Z angegeben (auch -Z "" = ausdrücklich kein ZIP)
UPDATE_ONLY=0
LOAD_TESTDATA=0
APP_ADMIN=""
APP_ENV_SET=""        # prod | test; leer = unverändert (neu: prod)

SETTINGS_FILE=""      # -f; Standard: wehrkalender.env neben dem Skript

usage() {
  cat <<EOF
Aufruf: $0 [-f <einstellungen.env>] [Optionen]

Einstellungsdatei (-f, Standard: wehrkalender.env neben dem Skript):
  Alle Einstellungen der Installation stehen in einer Datei auf DEINEM Rechner.
  Das Skript liest sie bei jedem Aufruf. Optionen beim Aufruf (-H -d -T -e -B
  -u -n -t -E -S) überschreiben sie und werden dort gespeichert; spätere
  Aufrufe brauchen sie nicht mehr, z. B. nur: $0 -U
  Fehlt die Datei, wird sie mit allen Schlüsseln (auskommentiert) angelegt.
  Werte für die Anwendung (APP_NAME, WEHR_NAME, MAIL_*, BETREIBER_*, SOURCE_URL,
  …) werden bei jedem Lauf in /srv/wehrkalender/.env auf dem Server übernommen.
  Mehrere Installationen: je eine Datei, z. B. -f test.env bzw. -f prod.env.

Pflicht (beim ersten Aufruf; danach aus der Einstellungsdatei):
  -H <server>     IP-Adresse oder Name des vServers                (SERVER)
  -d <domain>     Domain des Wehrkalenders, z. B. kalender.example.de (DOMAIN)

Anwendung:
  -Z <datei.zip>  ZIP der Anwendung. Standard: die neueste Datei
                  wehrkalender*.zip im Verzeichnis dieses Skripts.
                  -Z "" = kein ZIP (z. B. nur Einstellungen übernehmen).
                  Ohne ZIP wird nur der Server eingerichtet (Platzhalterseite).
  -U              Nur die Anwendung installieren/aktualisieren/umstellen:
                  überspringt Grundeinrichtung und SSH-Härtung (Server muss
                  schon eingerichtet sein)
  -E <prod|test>  Umgebung der Anwendung                           (APP_ENV)
                    test  Testsystem: Hinweisband "Testumgebung", Fehlerdetails
                          im Browser, Testdaten (-X) erlaubt
                    prod  Produktion: keine Fehlerdetails, -X gesperrt
                  Ohne Angabe gilt die Einstellungsdatei; steht dort nichts,
                  bleibt die Einstellung auf dem Server (neu: prod).
  -X              Testdaten laden – LÖSCHT ALLE DATEN der Datenbank wehrkalender!
                  Nur in der Umgebung test. Rückfrage, außer mit -y. (nur dieser Aufruf)
  -a <benutzer>   Diesem Benutzer der Anwendung Administratorrechte geben,
                  z. B. michael.bramer (muss existieren)        (nur dieser Aufruf)

Optional:
  -f <datei>      Einstellungsdatei (siehe oben)
  -T <domain>     Test-Domain, z. B. kalender-test.example.de      (TEST_DOMAIN)
                  (eigene Testseite, eigenes Zertifikat)
  -e <email>      Kontakt-E-Mail bei der Zertifikatsstelle         (ACME_EMAIL)
                  Nicht nötig: Let's Encrypt verschickt seit Juni 2025 keine
                  Ablaufwarnungen mehr. Ohne Angabe funktioniert alles.
  -B <ziel>       Backup-Ziel (restic-Repository)                  (BACKUP_REPO)
                  Standard: lokales Verzeichnis /var/backups/wehrkalender-restic.
                  Extern z. B. sftp://u12345@u12345.your-storagebox.de:23/wehrkalender
                  oder s3:https://<endpunkt>/<bucket> (Schlüssel dann in der
                  Einstellungsdatei: AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY).
  -u <benutzer>   Admin-Benutzer des Servers (Standard: $ADMIN_USER)    (SERVER_ADMIN)
  -n <hostname>   Interner Rechnername des Servers, z. B. "wehrkalender";
                  hat nichts mit der Domain zu tun                 (SERVER_HOSTNAME)
  -t <zeitzone>   Zeitzone (Standard: $TIMEZONE)                   (TIMEZONE)
  -c              Nur prüfen: SSH-Verbindung, DNS-Einträge, ZIP und
                  Einstellungsdatei testen; nichts ändern, nichts speichern
  -S              SSH-Härtung überspringen, root-Login bleibt      (SKIP_HARDEN=1)
  -y              Keine Rückfrage vor dem Start
  -h              Diese Hilfe
EOF
}

# ----------------------------- Einstellungsdatei -----------------------------
# Schlüssel des Skripts (Schlüssel → Variable) und Werte für die .env der Anwendung
declare -A CFG_VAR=(
  [SERVER]=SERVER [DOMAIN]=DOMAIN [TEST_DOMAIN]=TEST_DOMAIN [ACME_EMAIL]=ACME_EMAIL
  [BACKUP_REPO]=BACKUP_REPO [SERVER_ADMIN]=ADMIN_USER [SERVER_HOSTNAME]=NEW_HOSTNAME
  [TIMEZONE]=TIMEZONE [APP_ENV]=APP_ENV_SET [SKIP_HARDEN]=SKIP_HARDEN
)
CFG_APP_KEYS=(APP_NAME WEHR_NAME MAIL_DELIVERY MAILER_DSN MAIL_FROM MAIL_FROM_NAME ADMIN_ALERT_EMAILS
  FEED_BROWSER_NOTICE SOURCE_URL BETREIBER_NAME BETREIBER_ANSCHRIFT BETREIBER_VERTRETEN_DURCH
  BETREIBER_EMAIL BETREIBER_TELEFON BETREIBER_REGISTER DATENSCHUTZ_KONTAKT
  BACKUP_PING_URL BACKUP_KEEP_DAYS BACKUP_LOCAL_DAYS AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY)
# nur auf dem Server: werden dort erzeugt bzw. aus anderen Einstellungen gesetzt
CFG_SERVER_ONLY=(APP_KEY DB_ROOT_PASSWORD DB_PASSWORD DB_TEST_PASSWORD RESTIC_PASSWORD
  RESTIC_REPOSITORY APP_DOMAIN)
declare -A CFG_FROM_CLI=()   # beim Aufruf gesetzte Schlüssel → werden gespeichert
APP_SETTING_LINES=""         # KEY=WERT je Zeile, für /srv/wehrkalender/.env

in_list() { local x="$1" y; shift; for y in "$@"; do [[ "$x" == "$y" ]] && return 0; done; return 1; }
# Wert ohne Anführungszeichen: Kommentar „ # …“ und Leerzeichen am Ende entfernen
cfg_clean() {
  local v="$1"
  if [[ ! "$v" =~ ^[\"\'] ]]; then
    v="$(sed -E 's/[[:space:]]+#.*$//; s/[[:space:]]+$//' <<<"$v")"
  fi
  printf '%s' "$v"
}
cfg_unquote() {
  local v="$1"
  if [[ "$v" =~ ^\"(.*)\"$ || "$v" =~ ^\'(.*)\'$ ]]; then v="${BASH_REMATCH[1]}"; fi
  printf '%s' "$v"
}
load_settings() {
  local f="$1" line key raw n=0 errors=()
  [[ -f "$f" ]] || return 0
  while IFS= read -r line || [[ -n "$line" ]]; do
    n=$((n + 1))
    line="${line%$'\r'}"
    [[ "$line" =~ ^[[:space:]]*(#|$) ]] && continue
    if [[ ! "$line" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]]; then
      errors+=("Zeile $n: erwartet SCHLÜSSEL=Wert"); continue
    fi
    key="${BASH_REMATCH[1]}"; raw="$(cfg_clean "${BASH_REMATCH[2]}")"
    if [[ -n "${CFG_VAR[$key]:-}" ]]; then
      printf -v "${CFG_VAR[$key]}" '%s' "$(cfg_unquote "$raw")"
    elif in_list "$key" "${CFG_APP_KEYS[@]}"; then
      APP_SETTING_LINES+="$key=$raw"$'\n'
    elif in_list "$key" "${CFG_SERVER_ONLY[@]}"; then
      errors+=("Zeile $n: $key wird auf dem Server erzeugt bzw. gesetzt und gehört nicht hierher")
    else
      errors+=("Zeile $n: unbekannter Schlüssel $key (Tippfehler?)")
    fi
  done < "$f"
  if (( ${#errors[@]} > 0 )); then
    echo "FEHLER in der Einstellungsdatei $f:" >&2
    printf '  %s\n' "${errors[@]}" >&2
    exit 2
  fi
}
# Wert für die Datei: einfache Werte ohne, sonst in einfachen Anführungszeichen
cfg_quote() {
  if [[ "$1" =~ ^[A-Za-z0-9._:/@+,=-]*$ ]]; then printf '%s' "$1"
  elif [[ "$1" != *"'"* ]]; then printf "'%s'" "$1"
  else printf '"%s"' "$1"; fi
}
# Schlüssel setzen: vorhandene Zeile ersetzen, sonst die auskommentierte Vorlage, sonst anhängen
cfg_write() {
  local f="$SETTINGS_FILE"
  ( umask 077
    K="$1" V="$(cfg_quote "$2")" awk '
      BEGIN { k = ENVIRON["K"]; l = k "=" ENVIRON["V"] }
      FNR == NR { if (index($0, k "=") == 1) ex = 1; next }
      ex && index($0, k "=") == 1 { print l; next }
      !ex && !done && $0 ~ ("^#[ \t]*" k "=") { print l; done = 1; next }
      { print }
      END { if (!ex && !done) print l }' "$f" "$f" > "$f.tmp" )
  cat "$f.tmp" > "$f"; rm -f "$f.tmp"
}
cfg_template() {
  ( umask 077; cat > "$1" <<'TEMPLATE'
# =============================================================================
#  Einstellungen für setup-server-wehrkalender.sh (eine Datei je Installation)
#
#  Liegt auf DEINEM Rechner, nicht auf dem Server. Enthält ggf. Zugangsdaten
#  (MAILER_DSN, AWS_*): nicht weitergeben, nicht ins Git-Repository.
#  Das Skript liest die Datei bei jedem Aufruf. Optionen beim Aufruf
#  (-H -d -T -e -B -u -n -t -E -S) überschreiben sie und werden hier gespeichert.
#
#  Form: SCHLÜSSEL=Wert. Leerzeichen im Wert: in Anführungszeichen setzen.
#  Werte für die Anwendung gelten wie in /srv/wehrkalender/.env (Docker Compose):
#  ein "$" im Wert als "$$" schreiben oder den Wert in 'einfache' Anführungszeichen.
#  Auskommentierte Schlüssel (#) bleiben auf dem Server unverändert.
# =============================================================================

# ------------------------------------------------------------- Server (Skript)
# SERVER=203.0.113.10                 # -H  IP-Adresse oder Name des vServers
# DOMAIN=kalender.example.de          # -d  Domain des Wehrkalenders
# TEST_DOMAIN=                        # -T  Test-Domain (eigene Testseite)
# ACME_EMAIL=                         # -e  Kontakt bei Let's Encrypt (nicht nötig)
# BACKUP_REPO=                        # -B  externes Backup-Ziel (restic), leer = lokal
# SERVER_ADMIN=juno                   # -u  Admin-Benutzer des Servers
# SERVER_HOSTNAME=                    # -n  interner Rechnername
# TIMEZONE=Europe/Berlin              # -t  Zeitzone
# APP_ENV=test                        # -E  prod | test
# SKIP_HARDEN=0                       # -S  1 = SSH nicht härten (root-Login bleibt)

# ---------------------------------------------------------- Anwendung (.env)
# APP_NAME="Kalender FF Musterstadt"  # Name in Oberfläche, E-Mails, auf den Geräten
# WEHR_NAME="FF Musterstadt"          # Name der Wehr in Texten („Kalender der …“)
# SOURCE_URL=https://github.com/brami/Wehrkalender   # Quellcode-Link (AGPL-3.0); bei eigenen Änderungen: eigenes Repository
# FEED_BROWSER_NOTICE=0               # 1 = Hinweisseite für Abo-Links im Browser (nach Gerätetest)

# E-Mail-Versand (Betriebsdoku: „E-Mail-Versand einrichten“)
# MAIL_DELIVERY=database              # database = Testbetrieb (nichts wird verschickt) | smtp
# MAILER_DSN='smtp://benutzer%40example.de:GEHEIM@smtp.example.de:587'
# MAIL_FROM=kalender@example.de
# MAIL_FROM_NAME="Kalender FF Musterstadt"
# ADMIN_ALERT_EMAILS=wehrleitung@example.de,it@example.de

# Impressum und Datenschutz: Betreiber des Dienstes (Anschrift: Zeilen mit ; trennen)
# BETREIBER_NAME="Förderverein der FF Musterstadt e. V."
# BETREIBER_ANSCHRIFT="Hauptstraße 1; 12345 Musterstadt"
# BETREIBER_VERTRETEN_DURCH="den Vorstand, dieser vertreten durch …"
# BETREIBER_EMAIL=info@example.de
# BETREIBER_TELEFON=
# BETREIBER_REGISTER="Amtsgericht Musterstadt, VR 1234"
# DATENSCHUTZ_KONTAKT=

# Datensicherung
# BACKUP_PING_URL=                    # Überwachungsdienst, z. B. https://hc-ping.com/<uuid>
# BACKUP_KEEP_DAYS=30                 # tägliche Stände im restic-Repository
# BACKUP_LOCAL_DAYS=7                 # einfache Dumps auf dem Server
# AWS_ACCESS_KEY_ID=                  # nur für S3-Backup-Ziele
# AWS_SECRET_ACCESS_KEY=
TEMPLATE
  )
}

# Zwei Durchläufe: zuerst nur -f (Einstellungsdatei), dann alle Optionen,
# damit Optionen beim Aufruf die Datei überschreiben.
OPTSTRING=":f:H:d:T:e:B:u:n:t:Z:a:E:cSUXyh"
while getopts "$OPTSTRING" opt; do
  if [[ "$opt" == f ]]; then SETTINGS_FILE="$OPTARG"; fi
done
OPTIND=1
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SETTINGS_FILE="${SETTINGS_FILE:-$SCRIPT_DIR/wehrkalender.env}"
load_settings "$SETTINGS_FILE"

while getopts "$OPTSTRING" opt; do
  case "$opt" in
    f) ;;
    H) SERVER="$OPTARG"; CFG_FROM_CLI[SERVER]=1 ;;
    d) DOMAIN="$OPTARG"; CFG_FROM_CLI[DOMAIN]=1 ;;
    T) TEST_DOMAIN="$OPTARG"; CFG_FROM_CLI[TEST_DOMAIN]=1 ;;
    e) ACME_EMAIL="$OPTARG"; CFG_FROM_CLI[ACME_EMAIL]=1 ;;
    B) BACKUP_REPO="$OPTARG"; CFG_FROM_CLI[BACKUP_REPO]=1 ;;
    u) ADMIN_USER="$OPTARG"; CFG_FROM_CLI[SERVER_ADMIN]=1 ;;
    n) NEW_HOSTNAME="$OPTARG"; CFG_FROM_CLI[SERVER_HOSTNAME]=1 ;;
    t) TIMEZONE="$OPTARG"; CFG_FROM_CLI[TIMEZONE]=1 ;;
    Z) APP_ZIP="$OPTARG"; APP_ZIP_GIVEN=1 ;;
    a) APP_ADMIN="$OPTARG" ;;
    E) APP_ENV_SET="$OPTARG"; CFG_FROM_CLI[APP_ENV]=1 ;;
    c) CHECK_ONLY=1 ;;
    S) SKIP_HARDEN=1; CFG_FROM_CLI[SKIP_HARDEN]=1 ;;
    U) UPDATE_ONLY=1 ;;
    X) LOAD_TESTDATA=1 ;;
    y) ASSUME_YES=1 ;;
    h) usage; exit 0 ;;
    :) echo "Option -$OPTARG braucht einen Wert." >&2; usage; exit 2 ;;
    *) echo "Unbekannte Option: -$OPTARG" >&2; usage; exit 2 ;;
  esac
done
shift $((OPTIND - 1))
if (( $# > 0 )); then echo "Unerwartete Angabe: $1 (Optionen beginnen mit -)" >&2; exit 2; fi

if [[ -z "$SERVER" || -z "$DOMAIN" ]]; then
  echo "Server (-H) und Domain (-d) fehlen – beim ersten Aufruf angeben, danach stehen sie in $SETTINGS_FILE." >&2
  echo >&2; usage >&2; exit 2
fi
if [[ ! "$ADMIN_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]; then
  echo "Ungültiger Benutzername: $ADMIN_USER" >&2; exit 2
fi
if [[ ! "$SKIP_HARDEN" =~ ^[01]$ ]]; then
  echo "Ungültiger Wert SKIP_HARDEN=$SKIP_HARDEN (erlaubt: 0 oder 1)" >&2; exit 2
fi
if [[ -n "$APP_ADMIN" && ! "$APP_ADMIN" =~ ^[a-z0-9][a-z0-9.-]*$ ]]; then
  echo "Ungültiger Benutzername der Anwendung: $APP_ADMIN (Form vorname.nachname)" >&2; exit 2
fi
if [[ -n "$APP_ENV_SET" && ! "$APP_ENV_SET" =~ ^(prod|test)$ ]]; then
  echo "Ungültige Umgebung: $APP_ENV_SET (erlaubt: prod oder test)" >&2; exit 2
fi
if [[ "$APP_ENV_SET" == prod && $LOAD_TESTDATA -eq 1 ]]; then
  echo "-X (Testdaten) ist in der Umgebung prod nicht erlaubt." >&2; exit 2
fi

# Einstellungen speichern (nicht bei -c): neue Datei aus der Vorlage anlegen,
# dann die beim Aufruf angegebenen Werte eintragen
SETTINGS_NOTE="unverändert"
if [[ $CHECK_ONLY -eq 1 ]]; then
  if (( ${#CFG_FROM_CLI[@]} > 0 )); then SETTINGS_NOTE="nicht gespeichert (-c): ${!CFG_FROM_CLI[*]}"; fi
else
  if [[ ! -f "$SETTINGS_FILE" ]]; then
    cfg_template "$SETTINGS_FILE"
    CFG_FROM_CLI[SERVER]=1; CFG_FROM_CLI[DOMAIN]=1
    SETTINGS_NOTE="neu angelegt"
  fi
  for key in "${!CFG_FROM_CLI[@]}"; do
    var="${CFG_VAR[$key]}"
    cfg_write "$key" "${!var}"
  done
  chmod 600 "$SETTINGS_FILE" 2>/dev/null || true
  if (( ${#CFG_FROM_CLI[@]} > 0 )); then
    saved="gespeichert: $(printf '%s\n' "${!CFG_FROM_CLI[@]}" | sort | paste -sd ' ')"
    if [[ "$SETTINGS_NOTE" == unverändert ]]; then SETTINGS_NOTE="$saved"; else SETTINGS_NOTE+="; $saved"; fi
  fi
fi
APP_SETTINGS_B64="$(printf '%s' "$APP_SETTING_LINES" | base64 | tr -d '\n')"
APP_SETTING_KEYS="$(printf '%s' "$APP_SETTING_LINES" | cut -d= -f1 | paste -sd ' ')"

# ----------------------------- Lokale Voraussetzungen ------------------------
# Läuft vor allem anderen; bricht ab, wenn Pflichtprogramme fehlen.
check_local_tools() {
  local missing=() optional=() t
  # Bash >= 4.4 (leere Arrays mit "set -u"; macOS liefert nur Bash 3.2 mit)
  if (( BASH_VERSINFO[0] < 4 || (BASH_VERSINFO[0] == 4 && BASH_VERSINFO[1] < 4) )); then
    echo "FEHLER: Bash $BASH_VERSION ist zu alt, benötigt wird Bash 4.4 oder neuer." >&2
    echo "        macOS: brew install bash, dann: /opt/homebrew/bin/bash $0 ..." >&2
    exit 2
  fi
  for t in ssh curl tee awk sed grep tr wc head cut paste date ls; do
    command -v "$t" >/dev/null 2>&1 || missing+=("$t")
  done
  command -v dig >/dev/null 2>&1 || optional+=("dig")
  if (( ${#missing[@]} > 0 )); then
    echo "FEHLER: Folgende Programme fehlen auf diesem Rechner: ${missing[*]}" >&2
    echo "        Debian/Ubuntu: sudo apt install openssh-client curl coreutils gawk sed grep" >&2
    exit 2
  fi
  if (( ${#optional[@]} > 0 )); then
    echo "HINWEIS: 'dig' fehlt – die DNS-Vorabprüfung wird übersprungen." >&2
    echo "         Debian/Ubuntu: sudo apt install dnsutils · Fedora: sudo dnf install bind-utils" >&2
  fi
  # Ein SSH-Schlüssel muss vorhanden sein (Agent oder Datei), sonst klappt BatchMode nicht
  if ! ssh-add -l >/dev/null 2>&1 && ! ls ~/.ssh/id_* >/dev/null 2>&1; then
    echo "WARNUNG: Kein SSH-Schlüssel gefunden (weder im ssh-agent noch unter ~/.ssh/id_*)." >&2
  fi
}
check_local_tools

# ----------------------------- Lokales Protokoll -----------------------------
STAMP="$(date +%Y%m%d-%H%M%S)"
LOG_DIR="$SCRIPT_DIR/logs"
mkdir -p "$LOG_DIR"
LOCAL_LOG="$LOG_DIR/setup-${SERVER}-${STAMP}.log"
exec > >(tee -a "$LOCAL_LOG") 2>&1

log()  { printf '%s [lokal] %s\n' "$(date '+%F %T')" "$*"; }
fail() { log "FEHLER: $*"; exit 1; }
trap 'log "Abbruch in Zeile $LINENO (Exit-Code $?). Protokoll: $LOCAL_LOG"' ERR

SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=30
          -o StrictHostKeyChecking=accept-new -p "$SSH_PORT")

log "Start ($([[ $CHECK_ONLY -eq 1 ]] && echo "nur Prüfung" || { [[ $UPDATE_ONLY -eq 1 ]] && echo "nur Anwendung" || echo "Einrichtung"; }))"
log "Server: $SERVER | Domain: $DOMAIN | Test-Domain: ${TEST_DOMAIN:-keine} | Admin: $ADMIN_USER | Zeitzone: $TIMEZONE | Hostname: ${NEW_HOSTNAME:-unverändert}"
log "Einstellungsdatei: $SETTINGS_FILE ($SETTINGS_NOTE)"
log "Werte für die Anwendung aus der Einstellungsdatei: ${APP_SETTING_KEYS:-keine}"
log "Lokales Protokoll: $LOCAL_LOG"

# ----------------------------- Anwendung (ZIP) -------------------------------
# Neueste wehrkalender*.zip neben dem Skript, falls nicht per -Z angegeben.
sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1
  elif command -v shasum >/dev/null 2>&1; then shasum -a 256 "$1" | cut -d' ' -f1
  else echo ""; fi
}
if [[ -z "$APP_ZIP" && $APP_ZIP_GIVEN -eq 0 ]]; then
  APP_ZIP="$(ls -t "$SCRIPT_DIR"/wehrkalender*.zip 2>/dev/null | head -n1 || true)"
fi
APP_SHA=""
# Einstellungen der Anwendung ohne neues ZIP:
#   APP_ACTIONS  beim Aufruf verlangt (-E, -X, -a) – setzt eine installierte Anwendung voraus
#   APP_SETTINGS zusätzlich Werte aus der Einstellungsdatei – werden übernommen, wenn installiert
APP_ACTIONS=0
[[ -n "${CFG_FROM_CLI[APP_ENV]:-}" || $LOAD_TESTDATA -eq 1 || -n "$APP_ADMIN" ]] && APP_ACTIONS=1
APP_SETTINGS=$APP_ACTIONS
[[ -n "$APP_ENV_SET" || -n "$APP_SETTING_LINES" ]] && APP_SETTINGS=1
if [[ -n "$APP_ZIP" ]]; then
  [[ -f "$APP_ZIP" ]] || fail "ZIP nicht gefunden: $APP_ZIP"
  # Inhalt prüfen: erwartet wird das Verzeichnis wehrkalender/ mit Dockerfile und public/index.php
  if command -v unzip >/dev/null 2>&1; then
    unzip -tq "$APP_ZIP" >/dev/null || fail "ZIP ist beschädigt: $APP_ZIP"
    ZIP_LIST="$(unzip -Z1 "$APP_ZIP")"
  elif command -v python3 >/dev/null 2>&1; then
    ZIP_LIST="$(python3 -c 'import sys,zipfile; z=zipfile.ZipFile(sys.argv[1]); assert z.testzip() is None; print("\n".join(z.namelist()))' "$APP_ZIP")" \
      || fail "ZIP ist beschädigt: $APP_ZIP"
  else
    ZIP_LIST="wehrkalender/Dockerfile"$'\n'"wehrkalender/public/index.php"
    log "HINWEIS: weder unzip noch python3 vorhanden – Inhalt des ZIP wird erst auf dem Server geprüft."
  fi
  for f in wehrkalender/Dockerfile wehrkalender/public/index.php wehrkalender/bin/console wehrkalender/deploy/Caddyfile; do
    grep -qx "$f" <<<"$ZIP_LIST" || fail "$APP_ZIP enthält $f nicht – ist das das richtige ZIP?"
  done
  APP_SHA="$(sha256_of "$APP_ZIP")"
  log "Anwendung: $APP_ZIP ($(du -h "$APP_ZIP" | cut -f1), SHA-256 ${APP_SHA:0:16}…)"
elif [[ $APP_SETTINGS -eq 1 ]]; then
  log "Kein neues ZIP – nur Einstellungen der installierten Anwendung:${APP_ENV_SET:+ Umgebung $APP_ENV_SET}$([[ -n "$APP_SETTING_LINES" ]] && echo ' Werte aus der Einstellungsdatei')$([[ $LOAD_TESTDATA -eq 1 ]] && echo ' Testdaten')${APP_ADMIN:+ Administrator $APP_ADMIN}"
else
  [[ $UPDATE_ONLY -eq 1 ]] && fail "-U angegeben, aber kein ZIP gefunden und nichts umzustellen (wehrkalender*.zip neben das Skript legen, -Z, -E/-X/-a oder Werte in der Einstellungsdatei)."
  log "Kein ZIP der Anwendung gefunden – es wird nur der Server eingerichtet (Platzhalterseite)."
fi

# ----------------------------- Verbindung wählen -----------------------------
TARGET=""; SUDO=""
if ssh "${SSH_OPTS[@]}" "root@$SERVER" true 2>/dev/null; then
  TARGET="root@$SERVER"; SUDO=""
  log "Verbindung als root steht."
elif ssh "${SSH_OPTS[@]}" "$ADMIN_USER@$SERVER" 'sudo -n true' 2>/dev/null; then
  TARGET="$ADMIN_USER@$SERVER"; SUDO="sudo -n"
  log "root-Login nicht (mehr) möglich – verwende $ADMIN_USER mit sudo (erneuter Lauf)."
else
  fail "Keine SSH-Verbindung als root oder $ADMIN_USER mit sudo möglich."
fi

# ----------------------------- Server-Voraussetzungen ------------------------
# Alles Weitere installiert das Skript selbst; nötig sind nur Debian 13 oder
# Ubuntu LTS mit apt, systemd und Internetzugang zu den Paketquellen.
REMOTE_CHECK=$(ssh "${SSH_OPTS[@]}" "$TARGET" '
  . /etc/os-release 2>/dev/null; echo "os=${ID:-?} ${VERSION_ID:-?}"
  for t in apt-get systemctl bash; do command -v $t >/dev/null || echo "fehlt=$t"; done
  [ -d /run/systemd/system ] || echo "fehlt=systemd (läuft nicht)"
  case "${ID:-}" in debian) mirror=deb.debian.org ;; *) mirror=archive.ubuntu.com ;; esac
  timeout 10 bash -c "exec 3<>/dev/tcp/$mirror/80" 2>/dev/null || echo "fehlt=Internetzugang ($mirror:80)"
  command -v docker >/dev/null && docker compose version >/dev/null 2>&1 && echo "docker=ja"
  [ -f /srv/wehrkalender/.env ] && echo "eingerichtet=ja"
  [ -f /srv/wehrkalender/app/Dockerfile ] && echo "anwendung=ja"
' 2>/dev/null || true)
log "Server: $(grep '^os=' <<<"$REMOTE_CHECK" | cut -d= -f2)"
case "$(grep '^os=' <<<"$REMOTE_CHECK" | cut -d= -f2)" in
  "debian 13"|ubuntu*) ;;
  debian*) log "WARNUNG: Debian-Version nicht getestet – das Skript ist für Debian 13 geschrieben." ;;
  *) log "WARNUNG: Weder Debian noch Ubuntu erkannt – das Skript ist für Debian 13 / Ubuntu LTS geschrieben." ;;
esac
if grep -q '^fehlt=' <<<"$REMOTE_CHECK"; then
  fail "Auf dem Server fehlt: $(grep '^fehlt=' <<<"$REMOTE_CHECK" | cut -d= -f2 | paste -sd ', ')"
fi
if [[ $UPDATE_ONLY -eq 1 ]] && ! { grep -q '^docker=ja' <<<"$REMOTE_CHECK" && grep -q '^eingerichtet=ja' <<<"$REMOTE_CHECK"; }; then
  fail "-U: Der Server ist noch nicht eingerichtet (Docker oder /srv/wehrkalender/.env fehlt). Bitte zuerst ohne -U ausführen."
fi
APP_INSTALLED=0
grep -q '^anwendung=ja' <<<"$REMOTE_CHECK" && APP_INSTALLED=1
if [[ -z "$APP_ZIP" && $APP_ACTIONS -eq 1 && $APP_INSTALLED -eq 0 ]]; then
  fail "-E/-X/-a ohne ZIP: Auf dem Server ist noch keine Anwendung installiert. Bitte mit ZIP aufrufen."
fi
log "Server-Voraussetzungen erfüllt."

# ----------------------------- DNS-Vorabprüfung ------------------------------
# Prüft vor jeder Änderung am Server, ob die DNS-Einträge stimmen, und gibt
# fehlende oder falsche Einträge in kopierbarer Form (Zonendatei-Format) aus.
DNS_RESOLVER="${DNS_RESOLVER:-1.1.1.1}"   # öffentlicher Resolver, umgeht lokale Caches
DNS_TTL=3600
DNS_PROBLEMS=0
DNS_FIXES=()

q() {  # q <typ> <name>  -> Antworten zeilenweise (ohne Punkt am Ende)
  dig +short +time=3 +tries=2 "@$DNS_RESOLVER" "$1" "$2" 2>/dev/null | sed 's/\.$//'
}

dns_preflight() {
  log "DNS-Vorabprüfung (Resolver: $DNS_RESOLVER)"
  if ! command -v dig >/dev/null; then
    log "WARNUNG: 'dig' ist auf diesem Rechner nicht installiert – DNS-Prüfung übersprungen."
    log "         (Debian/Ubuntu: apt install dnsutils · macOS: bereits vorhanden · Windows: WSL nutzen)"
    return 0
  fi

  # IP-Adressen des Servers ermitteln (IPv4 aus -H bzw. DNS, IPv6 direkt vom Server)
  local ip4 ip6
  if [[ "$SERVER" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    ip4="$SERVER"
  else
    ip4=$(q A "$SERVER" | grep -E '^[0-9.]+$' | head -n1 || true)
  fi
  ip6=$(ssh "${SSH_OPTS[@]}" "$TARGET" \
        "ip -6 -o addr show scope global 2>/dev/null | awk '{print \$4}' | cut -d/ -f1 | grep -v '^f[cd]' | head -n1" 2>/dev/null || true)
  log "Server: IPv4 ${ip4:-unbekannt} | IPv6 ${ip6:-keine}"
  [[ -n "$ip4" ]] || { log "WARNUNG: IPv4 des Servers unbekannt – A-Einträge können nicht verglichen werden."; }

  local d a_list aaaa_list
  for d in "$DOMAIN" $TEST_DOMAIN; do
    a_list=$(q A "$d" | grep -E '^[0-9.]+$' || true)
    aaaa_list=$(q AAAA "$d" | grep ':' || true)

    # --- A-Eintrag (IPv4)
    if [[ -z "$a_list" ]]; then
      log "  FEHLT   $d: kein A-Eintrag"
      DNS_PROBLEMS=$((DNS_PROBLEMS+1))
      [[ -n "$ip4" ]] && DNS_FIXES+=("$d.  $DNS_TTL  IN  A     $ip4      ; neu anlegen")
    elif [[ -n "$ip4" ]] && ! grep -qx "$ip4" <<<"$a_list"; then
      log "  FALSCH  $d: A zeigt auf $(tr '\n' ' ' <<<"$a_list")statt auf $ip4"
      DNS_PROBLEMS=$((DNS_PROBLEMS+1))
      DNS_FIXES+=("$d.  $DNS_TTL  IN  A     $ip4      ; bestehenden A-Eintrag ersetzen")
    elif [[ $(wc -l <<<"$a_list") -gt 1 ]]; then
      log "  WARNUNG $d: mehrere A-Einträge ($(tr '\n' ' ' <<<"$a_list")) – nur $ip4 sollte übrig bleiben"
      DNS_PROBLEMS=$((DNS_PROBLEMS+1))
      DNS_FIXES+=("; $d: alle A-Einträge außer $ip4 löschen")
    else
      log "  OK      $d: A -> $ip4"
    fi

    # --- AAAA-Eintrag (IPv6): ein falscher AAAA verhindert das Zertifikat,
    #     weil die Zertifikatsstelle IPv6 bevorzugt
    if [[ -n "$aaaa_list" ]]; then
      if [[ -n "$ip6" ]] && grep -qix "$ip6" <<<"$aaaa_list"; then
        log "  OK      $d: AAAA -> $ip6"
      else
        log "  FALSCH  $d: AAAA zeigt auf $(tr '\n' ' ' <<<"$aaaa_list")– der Server hat ${ip6:-keine IPv6-Adresse}"
        DNS_PROBLEMS=$((DNS_PROBLEMS+1))
        if [[ -n "$ip6" ]]; then
          DNS_FIXES+=("$d.  $DNS_TTL  IN  AAAA  $ip6      ; bestehenden AAAA-Eintrag ersetzen")
        else
          DNS_FIXES+=("; $d: AAAA-Eintrag löschen (Server hat keine IPv6-Adresse)")
        fi
      fi
    elif [[ -n "$ip6" ]]; then
      log "  HINWEIS $d: kein AAAA-Eintrag (optional, Server hätte IPv6 $ip6)"
      DNS_FIXES+=("$d.  $DNS_TTL  IN  AAAA  $ip6      ; optional für IPv6")
    fi

    # --- CAA: Wenn vorhanden, muss Let's Encrypt erlaubt sein
    local name="$d" caa=""
    while [[ "$name" == *.* ]]; do
      caa=$(q CAA "$name" || true)
      [[ -n "$caa" ]] && break
      name="${name#*.}"
    done
    if [[ -n "$caa" ]]; then
      if grep -qiE 'issue "?letsencrypt\.org' <<<"$caa"; then
        log "  OK      $d: CAA (bei $name) erlaubt Let's Encrypt"
      else
        log "  FALSCH  $d: CAA bei $name erlaubt Let's Encrypt nicht: $(tr '\n' ' ' <<<"$caa")"
        DNS_PROBLEMS=$((DNS_PROBLEMS+1))
        DNS_FIXES+=("$name.  $DNS_TTL  IN  CAA   0 issue \"letsencrypt.org\"   ; ergänzen")
      fi
    else
      log "  OK      $d: kein CAA-Eintrag (jede Zertifikatsstelle erlaubt)"
    fi
  done

  if [[ ${#DNS_FIXES[@]} -gt 0 ]]; then
    log "Vorgeschlagene DNS-Einträge (beim DNS-Anbieter eintragen):"
    printf '    %s\n' "${DNS_FIXES[@]}" | awk '!seen[$0]++'
  fi
  if [[ "$DNS_PROBLEMS" -eq 0 ]]; then
    log "DNS-Prüfung: alles in Ordnung."
  else
    log "DNS-Prüfung: $DNS_PROBLEMS Problem(e). Ohne Korrektur erhält Caddy für die betroffene(n) Domain(s) kein Zertifikat."
    log "Hinweis: Änderungen im DNS können je nach TTL einige Minuten bis Stunden dauern."
  fi
}

dns_preflight

if [[ "$CHECK_ONLY" -eq 1 ]]; then
  log "Nur Prüfung (-c): Am Server wurde nichts geändert."
  exit $(( DNS_PROBLEMS > 0 ? 1 : 0 ))
fi

if [[ "$ASSUME_YES" -ne 1 ]]; then
  if [[ "$DNS_PROBLEMS" -gt 0 ]]; then
    echo "Die Einrichtung funktioniert auch ohne korrektes DNS; nur die Zertifikate folgen erst danach."
  fi
  read -r -p "$([[ $UPDATE_ONLY -eq 1 ]] && echo "Anwendung" || echo "Einrichtung") auf $SERVER starten? [j/N] " answer
  [[ "$answer" =~ ^[jJyY]$ ]] || { log "Vom Benutzer abgebrochen."; exit 0; }
  if [[ $LOAD_TESTDATA -eq 1 ]]; then
    echo "ACHTUNG: -X lädt Testdaten und LÖSCHT dabei ALLE Daten der Datenbank 'wehrkalender'"
    echo "         (Benutzer, Passwörter, Abo-Links). Vorher wird eine Sicherung erstellt."
    read -r -p "Zum Fortfahren 'testdaten' eintippen: " answer
    [[ "$answer" == testdaten ]] || { log "Testdaten abgelehnt – Abbruch."; exit 0; }
  fi
  if [[ -n "${CFG_FROM_CLI[APP_ENV]:-}" && "$APP_ENV_SET" == prod ]]; then
    echo "HINWEIS: -E prod schaltet auf Produktion um. Prüfe vorher in der Einstellungsdatei"
    echo "         MAIL_DELIVERY=smtp und MAILER_DSN, sonst werden keine E-Mails verschickt."
  fi
fi

# Hilfsfunktion: Remote-Skript mit Umgebungsvariablen ausführen
RELEASE="$STAMP"
REMOTE_ZIP=""
APP_ZIP_NAME="${APP_ZIP##*/}"
run_remote() {  # $1 = Phase, Skript kommt über stdin
  local phase="$1"
  local envs
  envs=$(printf 'PHASE=%q ADMIN_USER=%q TIMEZONE=%q NEW_HOSTNAME=%q DOMAIN=%q TEST_DOMAIN=%q ACME_EMAIL=%q BACKUP_REPO=%q STAMP=%q SSH_PORT=%q REMOTE_ZIP=%q APP_SHA=%q APP_ZIP_NAME=%q RELEASE=%q LOAD_TESTDATA=%q APP_ADMIN=%q APP_ENV_SET=%q APP_SETTINGS_B64=%q' \
    "$phase" "$ADMIN_USER" "$TIMEZONE" "$NEW_HOSTNAME" "$DOMAIN" "$TEST_DOMAIN" "$ACME_EMAIL" "$BACKUP_REPO" "$STAMP" "$SSH_PORT" \
    "$REMOTE_ZIP" "$APP_SHA" "$APP_ZIP_NAME" "$RELEASE" "$LOAD_TESTDATA" "$APP_ADMIN" "$APP_ENV_SET" "$APP_SETTINGS_B64")
  # shellcheck disable=SC2029  # Variablen sollen lokal expandiert werden
  ssh "${SSH_OPTS[@]}" "$TARGET" "$SUDO env $envs bash -s"
}

# ============================== Remote-Skript ================================
REMOTE_SCRIPT=$(cat <<'REMOTE'
set -Eeuo pipefail
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a

LOG_DIR=/var/log/wehrkalender-setup
mkdir -p "$LOG_DIR"; chmod 750 "$LOG_DIR"
REMOTE_LOG="$LOG_DIR/$STAMP.log"
exec > >(tee -a "$REMOTE_LOG") 2>&1

log()  { printf '%s [%s] %s\n' "$(date '+%F %T')" "$(hostname)" "$*"; }
step() { printf '\n%s [%s] ===== %s =====\n' "$(date '+%F %T')" "$(hostname)" "$*"; }
trap 'log "FEHLER in Zeile $LINENO (Exit-Code $?)"' ERR

apt_install() { apt-get install -y -q --no-install-recommends "$@"; }

# Gemeinsame Hilfen für .env (alle Phasen)
APP=/srv/wehrkalender
rnd() { head -c "$1" /dev/urandom | base64 | tr -d '/+=\n'; }
ensure_env() {  # ensure_env KEY WERT  -> nur setzen, wenn KEY noch nicht existiert
  grep -q "^$1=" "$APP/.env" || { echo "$1=$2" >> "$APP/.env"; log ".env: $1 neu angelegt."; }
}
set_env() {  # set_env KEY WERT -> setzen bzw. ersetzen (Wert unverändert, auch mit | & $ /)
  if grep -q "^$1=" "$APP/.env"; then
    ( umask 077
      K="$1" V="$2" awk 'index($0, ENVIRON["K"] "=") == 1 { print ENVIRON["K"] "=" ENVIRON["V"]; next } { print }' \
        "$APP/.env" > "$APP/.env.tmp" )
    cat "$APP/.env.tmp" > "$APP/.env"; rm -f "$APP/.env.tmp"
  else
    printf '%s=%s\n' "$1" "$2" >> "$APP/.env"
  fi
}
# Werte aus der Einstellungsdatei des Setup-Skripts übernehmen (APP_SETTINGS_B64:
# Zeilen KEY=WERT, base64-kodiert). Diese Werte gewinnen gegenüber der .env;
# Schlüssel, die dort fehlen, bleiben unverändert.
apply_app_settings() {
  [[ -n "${APP_SETTINGS_B64:-}" ]] || return 0
  local line k v old changed=()
  while IFS= read -r line; do
    [[ -n "$line" ]] || continue
    k="${line%%=*}"; v="${line#*=}"
    old="$(grep -m1 "^$k=" "$APP/.env" | cut -d= -f2- || true)"
    if ! grep -q "^$k=" "$APP/.env" || [[ "$old" != "$v" ]]; then
      set_env "$k" "$v"; changed+=("$k")
    fi
  done < <(printf '%s' "$APP_SETTINGS_B64" | base64 -d)
  if (( ${#changed[@]} > 0 )); then
    log "Einstellungsdatei: in $APP/.env übernommen: ${changed[*]}"
  else
    log "Einstellungsdatei: $APP/.env ist aktuell."
  fi
}
envval() {  # Wert aus .env; umschließende Anführungszeichen werden entfernt
  local v; v="$(grep -m1 "^$1=" "$APP/.env" | cut -d= -f2-)" || return 1
  if [[ "$v" =~ ^\"(.*)\"$ || "$v" =~ ^\'(.*)\'$ ]]; then v="${BASH_REMATCH[1]}"; fi
  printf '%s\n' "$v"
}
# Ist die Anwendung installiert? Dann gehören docker-compose.yml und Caddyfile
# der Anwendungsphase und werden von der Grundeinrichtung nicht überschrieben.
app_installed() { [[ -f "$APP/app/public/index.php" && -f "$APP/app/Dockerfile" ]]; }

# Umgebung der Anwendung: -E (APP_ENV_SET) > Eintrag in .env > prod
effective_env() {
  local e="${APP_ENV_SET:-}"
  [[ -n "$e" ]] || e="$(envval APP_ENV 2>/dev/null || true)"
  echo "${e:-prod}"
}
# Testdaten nur außerhalb der Produktion
guard_testdata() {
  if [[ "$LOAD_TESTDATA" == 1 && "$(effective_env)" == prod ]]; then
    log "FEHLER: -X (Testdaten) ist in der Umgebung prod gesperrt. Zuerst mit -E test umstellen."
    exit 1
  fi
}
# -E übernehmen und Hinweise ausgeben
apply_env() {
  if [[ -n "${APP_ENV_SET:-}" ]]; then
    local old; old="$(envval APP_ENV || true)"
    if [[ "$old" != "$APP_ENV_SET" ]]; then
      set_env APP_ENV "$APP_ENV_SET"
      log "Umgebung: ${old:-prod} -> $APP_ENV_SET"
    fi
  fi
  log "Umgebung der Anwendung: $(envval APP_ENV)$([[ "$(envval APP_ENV)" == test ]] && echo ' (Testsystem: Hinweisband, Fehlerdetails im Browser)')"
  if [[ -z "$(envval WEHR_NAME)" ]]; then
    log "HINWEIS: WEHR_NAME ist leer – Texte und E-Mails sprechen allgemein von „Feuerwehr“. In der Einstellungsdatei des Setup-Skripts eintragen, z. B. WEHR_NAME=\"FF Musterstadt\"."
  fi
  if [[ -z "$(envval BETREIBER_NAME)" || -z "$(envval BETREIBER_ANSCHRIFT)" || -z "$(envval BETREIBER_EMAIL)" ]]; then
    log "WARNUNG: Impressum unvollständig – in der Einstellungsdatei des Setup-Skripts BETREIBER_NAME, BETREIBER_ANSCHRIFT und BETREIBER_EMAIL eintragen."
  fi
  if [[ "$(envval APP_ENV)" == prod && "$(envval MAIL_DELIVERY)" != smtp ]]; then
    log "WARNUNG: Produktion, aber MAIL_DELIVERY=$(envval MAIL_DELIVERY) – es werden keine E-Mails verschickt."
    log "         In der Einstellungsdatei MAILER_DSN eintragen, MAIL_DELIVERY=smtp setzen und erneut mit -U aufrufen."
  fi
}
# Testdaten und Administrator (nach dem Start der Container)
post_start_tasks() {
  cd "$APP"
  if [[ "$LOAD_TESTDATA" == 1 ]]; then
    step "Anwendung: Testdaten laden (Datenbank wehrkalender wird geleert)"
    if systemctl cat wehrkalender-backup.service >/dev/null 2>&1; then
      systemctl start wehrkalender-backup.service || log "WARNUNG: Sicherung vor den Testdaten fehlgeschlagen."
    fi
    # Ausgabe ohne Zugangsdaten (die stehen nur in der Datei im Container)
    docker compose exec -T app php bin/console testdata:load --yes </dev/null \
      | grep -E '^(Testdaten in|Umfang|Gespeichert)' | sed 's/^/    /' || true
    docker compose exec -T app test -s storage/testdaten-zugaenge.txt </dev/null \
      || { log "FEHLER: Testdaten konnten nicht geladen werden."; exit 1; }
    log "Zugangsdaten der Testbenutzer anzeigen (enthält Passwörter):"
    log "    cd $APP && docker compose exec app cat storage/testdaten-zugaenge.txt"
  fi
  if [[ -n "$APP_ADMIN" ]]; then
    step "Anwendung: Administrator $APP_ADMIN"
    if docker compose exec -T app php bin/console user:admin "$APP_ADMIN" </dev/null; then
      :
    else
      log "WARNUNG: $APP_ADMIN existiert (noch) nicht in der Datenbank. Später erneut: docker compose exec app php bin/console user:admin $APP_ADMIN"
    fi
  fi
}
# Erreichbarkeit über Caddy prüfen
check_health() {
  step "Anwendung: Erreichbarkeit"
  cd "$APP"
  local code data
  # /health/live: Anwendung und Datenbank laufen (die Adresse für einen Uptime-Dienst)
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/health/live" || true)
  case "$code" in
    200) log "https://$DOMAIN/health/live: Anwendung und Datenbank laufen." ;;
    502|503|504)
         docker compose ps
         docker compose logs --tail 40 app || true
         log "FEHLER: Anwendung oder Datenbank antwortet nicht (HTTP $code). Protokoll des app-Containers siehe oben."
         exit 1 ;;
    *)   log "WARNUNG: https://$DOMAIN/health/live nicht erreichbar (HTTP ${code:-–}). Meist fehlt noch das Zertifikat (DNS prüfen)."
         log "         Test im Container: docker compose exec app php bin/console list"
         return 0 ;;
  esac
  # /health: Zustand der Daten (Import, externe Quellen); Einzelheiten auf der Status-Seite
  data=$(curl -s --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/health" || true)
  if grep -q '"status":"ok"' <<<"$data"; then
    log "https://$DOMAIN/health: Daten in Ordnung."
  else
    log "HINWEIS: https://$DOMAIN/health meldet $(grep -o '"problems":[0-9]*' <<<"$data" | cut -d: -f2) Problem(e) mit den Daten – Einzelheiten auf https://$DOMAIN/admin."
    log "         Ohne Import aus MP Feuer ist das zu erwarten („noch kein Export“)."
  fi
}
# Container starten bzw. mit geänderter Konfiguration neu erzeugen
start_containers() {
  cd "$APP"
  if ! docker compose up -d --remove-orphans --wait --wait-timeout 300; then
    docker compose ps
    docker compose logs --tail 60 app worker || true
    log "FEHLER: Container sind nicht sauber gestartet (Protokolle siehe oben)."
    exit 1
  fi
  docker compose exec -T caddy caddy reload --config /etc/caddy/Caddyfile </dev/null >/dev/null 2>&1 \
    || docker compose restart caddy
  docker compose ps --format 'table {{.Name}}\t{{.Status}}' | sed 's/^/    /'
  log "Datenbank: $(docker compose exec -T app php bin/console db:migrate </dev/null)"
}

# --------------------------------------------------------------- Phase base
phase_base() {
  step "Systeminformationen"
  . /etc/os-release
  log "System: $PRETTY_NAME (Codename: ${VERSION_CODENAME:-?}) | Kernel: $(uname -r)"
  case "${ID:-}" in
    debian|ubuntu) OS_ID="$ID" ;;
    *) log "WARNUNG: Weder Debian noch Ubuntu – verwende Debian-Einstellungen."; OS_ID=debian ;;
  esac

  step "System aktualisieren"
  apt-get update -q
  apt-get -y -q -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold full-upgrade
  # sudo und dbus fehlen bei Debian-Minimalinstallationen;
  # python3-systemd braucht fail2ban, um das Systemjournal zu lesen
  apt_install ca-certificates curl gnupg sudo dbus ufw fail2ban python3-systemd \
              unattended-upgrades apt-listchanges git jq bind9-dnsutils rsync restic unzip
  log "Pakete aktuell."

  step "Zeitzone und Hostname"
  timedatectl set-timezone "$TIMEZONE"
  log "Zeitzone: $(timedatectl show -p Timezone --value)"
  if [[ -n "$NEW_HOSTNAME" && "$(hostnamectl --static)" != "$NEW_HOSTNAME" ]]; then
    hostnamectl set-hostname "$NEW_HOSTNAME"
    grep -q "127.0.1.1 $NEW_HOSTNAME" /etc/hosts || echo "127.0.1.1 $NEW_HOSTNAME" >> /etc/hosts
    log "Hostname gesetzt: $NEW_HOSTNAME"
  else
    log "Hostname unverändert: $(hostnamectl --static)"
  fi

  step "Automatische Sicherheitsupdates"
  cat > /etc/apt/apt.conf.d/20auto-upgrades <<EOF
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF
  systemctl enable --now unattended-upgrades >/dev/null
  log "unattended-upgrades aktiv (Neustarts erfolgen NICHT automatisch)."

  step "Admin-Benutzer $ADMIN_USER"
  if id "$ADMIN_USER" >/dev/null 2>&1; then
    log "Benutzer existiert bereits."
  else
    useradd -m -s /bin/bash "$ADMIN_USER"   # ohne Passwort (gesperrt)
    log "Benutzer angelegt (ohne Passwort, Anmeldung nur per Schlüssel)."
  fi
  usermod -aG sudo "$ADMIN_USER"
  install -d -m 700 -o "$ADMIN_USER" -g "$ADMIN_USER" "/home/$ADMIN_USER/.ssh"
  AK="/home/$ADMIN_USER/.ssh/authorized_keys"
  touch "$AK"
  if [[ -s /root/.ssh/authorized_keys ]]; then
    # Schlüssel von root übernehmen, ohne Doppelte
    sort -u "$AK" /root/.ssh/authorized_keys | grep -v '^\s*$' > "$AK.tmp"
    mv "$AK.tmp" "$AK"
    log "SSH-Schlüssel von root übernommen ($(wc -l < "$AK") Schlüssel)."
  fi
  chown "$ADMIN_USER:$ADMIN_USER" "$AK"; chmod 600 "$AK"
  [[ -s "$AK" ]] || { log "FEHLER: $ADMIN_USER hat keinen SSH-Schlüssel."; exit 1; }
  echo "$ADMIN_USER ALL=(ALL) NOPASSWD:ALL" > "/etc/sudoers.d/90-$ADMIN_USER"
  chmod 440 "/etc/sudoers.d/90-$ADMIN_USER"
  visudo -cf "/etc/sudoers.d/90-$ADMIN_USER" >/dev/null
  log "sudo ohne Passwort für $ADMIN_USER eingerichtet (Benutzer hat kein Passwort)."

  step "Firewall (ufw)"
  ufw default deny incoming >/dev/null
  ufw default allow outgoing >/dev/null
  ufw allow "$SSH_PORT/tcp" comment 'SSH' >/dev/null
  ufw allow 80/tcp  comment 'HTTP (Zertifikat, Weiterleitung)' >/dev/null
  ufw allow 443/tcp comment 'HTTPS' >/dev/null
  ufw allow 443/udp comment 'HTTP/3' >/dev/null
  ufw --force enable >/dev/null
  ufw status verbose | sed 's/^/    /'

  step "fail2ban"
  cat > /etc/fail2ban/jail.d/wehrkalender.local <<EOF
[sshd]
enabled  = true
port     = $SSH_PORT
backend  = systemd
maxretry = 5
findtime = 10m
bantime  = 1h
EOF
  systemctl enable fail2ban >/dev/null
  systemctl restart fail2ban
  log "fail2ban aktiv für SSH."

  step "Docker"
  if command -v docker >/dev/null && docker compose version >/dev/null 2>&1; then
    log "Docker bereits installiert: $(docker --version)"
  else
    install -m 0755 -d /etc/apt/keyrings
    # Offizielles Docker-Repository – getrennt für Debian und Ubuntu
    curl -fsSL "https://download.docker.com/linux/$OS_ID/gpg" -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/$OS_ID ${VERSION_CODENAME} stable" \
      > /etc/apt/sources.list.d/docker.list
    if apt-get update -q && apt_install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin; then
      log "Docker aus dem offiziellen Docker-Repository installiert ($OS_ID/${VERSION_CODENAME})."
    else
      log "WARNUNG: Docker-Repository für '$OS_ID ${VERSION_CODENAME}' nicht verfügbar – verwende Pakete der Distribution."
      rm -f /etc/apt/sources.list.d/docker.list
      apt-get update -q
      # Paketname des Compose-Plugins: Ubuntu docker-compose-v2, Debian docker-compose
      apt_install docker.io docker-compose-v2 || apt_install docker.io docker-compose
    fi
  fi
  # Log-Größe der Container begrenzen
  if [[ ! -f /etc/docker/daemon.json ]]; then
    install -d /etc/docker
    cat > /etc/docker/daemon.json <<EOF
{ "log-driver": "json-file", "log-opts": { "max-size": "10m", "max-file": "5" } }
EOF
    systemctl restart docker
  fi
  systemctl enable --now docker >/dev/null
  usermod -aG docker "$ADMIN_USER"
  log "$(docker --version) | $(docker compose version)"
  log "Hinweis: Docker umgeht ufw für veröffentlichte Ports. Nach außen werden nur 80/443 von Caddy veröffentlicht."

  step "Anwendungsverzeichnis /srv/wehrkalender"
  install -d -o "$ADMIN_USER" -g "$ADMIN_USER" -m 750 "$APP" "$APP/caddy" "$APP/www" "$APP/www-test" "$APP/mariadb"
  if [[ ! -f "$APP/.env" ]]; then
    ( umask 077; echo "# Wehrkalender – Konfiguration (NICHT ins Git-Repository!)" > "$APP/.env" )
    log ".env angelegt."
  fi
  chown "$ADMIN_USER:$ADMIN_USER" "$APP/.env"; chmod 600 "$APP/.env"

  # Geheimnisse nur erzeugen, wenn sie noch fehlen – bestehende bleiben unverändert
  ensure_env APP_KEY          "$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
  ensure_env DB_ROOT_PASSWORD "$(rnd 24)"
  ensure_env DB_PASSWORD      "$(rnd 24)"
  ensure_env DB_TEST_PASSWORD "$(rnd 24)"
  ensure_env RESTIC_PASSWORD  "$(rnd 32)"
  ensure_env RESTIC_REPOSITORY "/var/backups/wehrkalender-restic"
  ensure_env BACKUP_KEEP_DAYS  "30"
  ensure_env BACKUP_LOCAL_DAYS "7"
  ensure_env BACKUP_PING_URL   ""
  ensure_env AWS_ACCESS_KEY_ID     ""   # nur für S3-Ziele (Backblaze B2, Cloudflare R2 ...)
  ensure_env AWS_SECRET_ACCESS_KEY ""

  # Domains und Kontakt-E-Mail werden bei jedem Lauf auf die übergebenen Werte gesetzt,
  # das Backup-Ziel nur, wenn -B angegeben wurde
  set_env DOMAIN "$DOMAIN"
  set_env TEST_DOMAIN "$TEST_DOMAIN"
  set_env ACME_EMAIL "$ACME_EMAIL"
  apply_app_settings
  if [[ -n "$BACKUP_REPO" ]]; then
    set_env RESTIC_REPOSITORY "$BACKUP_REPO"
  elif [[ -z "$(envval RESTIC_REPOSITORY)" ]]; then
    set_env RESTIC_REPOSITORY "/var/backups/wehrkalender-restic"
  fi
  log "Backup-Ziel: $(envval RESTIC_REPOSITORY)"

  if app_installed; then
    log "Anwendung ist installiert – Caddyfile und docker-compose.yml bleiben unverändert (werden in der Anwendungsphase geschrieben)."
  else
  {
    # Globale Optionen nur, wenn eine Kontakt-E-Mail angegeben wurde
    if [[ -n "$ACME_EMAIL" ]]; then
      printf '{\n\temail {$ACME_EMAIL}\n}\n\n'
    fi
    cat <<'CADDY'
(sicherheit) {
	encode zstd gzip
	header {
		Strict-Transport-Security "max-age=31536000"
		X-Content-Type-Options "nosniff"
		Referrer-Policy "no-referrer"
		-Server
	}
}

{$DOMAIN} {
	import sicherheit
	# Platzhalter bis zur Installation der Anwendung
	root * /srv/www
	file_server
}
CADDY
    if [[ -n "$TEST_DOMAIN" ]]; then
      cat <<'CADDY'

{$TEST_DOMAIN} {
	import sicherheit
	# Testumgebung: nicht von Suchmaschinen erfassen lassen
	header X-Robots-Tag "noindex, nofollow"
	root * /srv/www-test
	file_server
}
CADDY
    fi
  } > "$APP/caddy/Caddyfile"
  log "Caddyfile geschrieben: $DOMAIN${TEST_DOMAIN:+ und $TEST_DOMAIN}"
  fi

  cat > "$APP/www-test/index.html" <<'EOF'
<!doctype html><html lang="de"><meta charset="utf-8"><title>TEST – Wehrkalender</title>
<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">
<p style="background:#FFF7ED;border:1px solid #FDBA74;padding:.5rem 1rem">Testumgebung</p>
<h1>Wehrkalender – Test</h1><p>Die Testumgebung ist eingerichtet.</p></body></html>
EOF

  cat > "$APP/www/index.html" <<'EOF'
<!doctype html><html lang="de"><meta charset="utf-8"><title>Wehrkalender</title>
<body style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">
<h1>Wehrkalender</h1><p>Der Server ist eingerichtet. Die Anwendung folgt.</p></body></html>
EOF

  cat > "$APP/mariadb/wehrkalender.cnf" <<'EOF'
# MariaDB-Einstellungen für den Wehrkalender (angelegt von setup-server-wehrkalender.sh)
[mariadb]
character-set-server    = utf8mb4
collation-server        = utf8mb4_unicode_ci
# Zeitstempel in UTC speichern; die Anwendung rechnet in Europe/Berlin um
default-time-zone       = '+00:00'
skip-name-resolve
max_connections         = 100
# Für ~200 Benutzer reichlich; bei 4 GB RAM unkritisch
innodb_buffer_pool_size = 256M
EOF

  if ! app_installed; then
  cat > "$APP/docker-compose.yml" <<'EOF'
# Wehrkalender – Docker Compose (angelegt von setup-server-wehrkalender.sh)
# Stand: Caddy mit Testseite und MariaDB. App und Worker kommen mit der
# Installation der Anwendung (ZIP) hinzu.
# Werte wie ${DOMAIN} kommen aus der Datei .env in diesem Verzeichnis.
services:
  caddy:
    image: caddy:2
    restart: unless-stopped
    environment:            # nur die Werte, die Caddy braucht – keine Passwörter
      DOMAIN: ${DOMAIN}
      TEST_DOMAIN: ${TEST_DOMAIN:-}
      ACME_EMAIL: ${ACME_EMAIL:-}
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"
    volumes:
      - ./caddy/Caddyfile:/etc/caddy/Caddyfile:ro
      - ./www:/srv/www:ro
      - ./www-test:/srv/www-test:ro
      - caddy_data:/data
      - caddy_config:/config
    networks: [frontend]

  db:
    image: mariadb:11.4     # LTS-Version
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MARIADB_DATABASE: wehrkalender
      MARIADB_USER: wehrkalender
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_AUTO_UPGRADE: "1"   # nach Image-Update Systemtabellen automatisch anpassen
    volumes:
      - db-data:/var/lib/mysql
      - ./mariadb/wehrkalender.cnf:/etc/mysql/conf.d/wehrkalender.cnf:ro
    networks: [backend]     # kein Port nach außen, nur internes Netz
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 30s

networks:
  frontend:
  backend:
    internal: true          # kein Zugang ins Internet und von außen

volumes:
  caddy_data:
  caddy_config:
  db-data:
EOF
  fi
  chown -R "$ADMIN_USER:$ADMIN_USER" "$APP"
  chmod 600 "$APP/.env"

  step "DNS-Prüfung"
  MY_IP4=$(curl -4 -fsS --max-time 10 https://ifconfig.co 2>/dev/null || true)
  log "Öffentliche IPv4 des Servers: ${MY_IP4:-unbekannt}"
  for d in "$DOMAIN" $TEST_DOMAIN; do
    DNS_IP4=$(dig +short A "$d" | tail -n1 || true)
    if [[ -n "$MY_IP4" && "$MY_IP4" == "$DNS_IP4" ]]; then
      log "$d -> $DNS_IP4: zeigt auf diesen Server, Zertifikat kann geholt werden."
    else
      log "WARNUNG: $d -> ${DNS_IP4:-kein A-Eintrag}: zeigt (noch) nicht auf diesen Server. Caddy versucht es später erneut."
    fi
  done

  step "Container starten (Caddy, MariaDB)"
  cd "$APP"
  # Nur Caddy und MariaDB; app und worker gehören der Anwendungsphase
  docker compose pull -q caddy db
  if ! docker compose up -d --no-deps --wait --wait-timeout 180 caddy db; then
    docker compose ps
    docker compose logs --tail 40 db || true
    log "FEHLER: Container sind nicht gesund gestartet."
    exit 1
  fi
  # Bei erneutem Lauf geänderte Caddy-Konfiguration übernehmen
  docker compose exec -T caddy caddy reload --config /etc/caddy/Caddyfile </dev/null >/dev/null 2>&1 \
    || docker compose restart caddy
  docker compose ps

  step "Datenbanken und Benutzer"
  # Idempotent: legt fehlendes an und setzt die Passwörter auf die Werte aus .env
  docker compose exec -T -e MYSQL_PWD="$(envval DB_ROOT_PASSWORD)" db mariadb -uroot <<SQL
CREATE DATABASE IF NOT EXISTS wehrkalender      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wehrkalender_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'wehrkalender'@'%'      IDENTIFIED BY '$(envval DB_PASSWORD)';
ALTER  USER               'wehrkalender'@'%'      IDENTIFIED BY '$(envval DB_PASSWORD)';
CREATE USER IF NOT EXISTS 'wehrkalender_test'@'%' IDENTIFIED BY '$(envval DB_TEST_PASSWORD)';
ALTER  USER               'wehrkalender_test'@'%' IDENTIFIED BY '$(envval DB_TEST_PASSWORD)';
GRANT ALL PRIVILEGES ON wehrkalender.*      TO 'wehrkalender'@'%';
GRANT ALL PRIVILEGES ON wehrkalender_test.* TO 'wehrkalender_test'@'%';
FLUSH PRIVILEGES;
SQL
  log "Datenbanken: $(docker compose exec -T -e MYSQL_PWD="$(envval DB_ROOT_PASSWORD)" db \
        mariadb -uroot -N -e "SELECT GROUP_CONCAT(schema_name) FROM information_schema.schemata WHERE schema_name LIKE 'wehrkalender%'" </dev/null)"
  log "Benutzer wehrkalender -> Datenbank wehrkalender, wehrkalender_test -> wehrkalender_test (nur im internen Docker-Netz erreichbar)."
  log "Die Tabellen legt die Anwendung per Migration an (bin/console db:migrate, automatisch beim Start)."

  step "Datensicherung"
  install -d -m 700 /var/backups/wehrkalender /var/backups/wehrkalender-restic
  # Schlüssel für ein externes Backup-Ziel per SFTP (z. B. Storage-Box)
  install -d -m 700 /root/.ssh
  if [[ ! -f /root/.ssh/wehrkalender-backup ]]; then
    ssh-keygen -q -t ed25519 -N "" -C "wehrkalender-backup@$(hostnamectl --static)" -f /root/.ssh/wehrkalender-backup
    log "SSH-Schlüssel für das Backup-Ziel erzeugt."
  fi

  cat > /usr/local/sbin/wehrkalender-backup <<'BACKUP'
#!/usr/bin/env bash
# =============================================================================
#  wehrkalender-backup – Datensicherung des Wehrkalenders (angelegt von setup-server-wehrkalender.sh)
#  Läuft täglich über wehrkalender-backup.timer; manuell: sudo wehrkalender-backup
#
#  1. Datenbank "wehrkalender" als komprimierter Dump nach /var/backups/wehrkalender
#  2. Konfiguration (Caddyfile, docker-compose.yml, MariaDB) als tar.gz – ohne .env
#  3. Verschlüsselt mit restic in RESTIC_REPOSITORY (Standard: lokales
#     Verzeichnis /var/backups/wehrkalender-restic, später extern), dort
#     BACKUP_KEEP_DAYS tägliche Stände. Einfache Dumps: BACKUP_LOCAL_DAYS Tage
#  Protokoll: journalctl -u wehrkalender-backup
# =============================================================================
set -Eeuo pipefail
APP=/srv/wehrkalender
DIR=/var/backups/wehrkalender
KEY=/root/.ssh/wehrkalender-backup
envval() {  # Wert aus .env; umschließende Anführungszeichen werden entfernt
  local v; v="$(grep -m1 "^$1=" "$APP/.env" | cut -d= -f2-)" || return 1
  if [[ "$v" =~ ^\"(.*)\"$ || "$v" =~ ^\'(.*)\'$ ]]; then v="${BASH_REMATCH[1]}"; fi
  printf '%s\n' "$v"
}
log()  { printf '%s %s\n' "$(date '+%F %T')" "$*"; }
ping_url() { local u; u="$(envval BACKUP_PING_URL)"; [[ -n "$u" ]] && curl -fsS -m 10 --retry 3 -o /dev/null "$u$1" || true; }
trap 'log "FEHLER in Zeile $LINENO – Sicherung unvollständig"; ping_url /fail' ERR

[[ $EUID -eq 0 ]] || { echo "Bitte mit sudo ausführen." >&2; exit 1; }
exec 9>/run/wehrkalender-backup.lock
flock -n 9 || { log "Es läuft bereits eine Sicherung."; exit 0; }
cd "$APP"
ping_url /start
STAMP="$(date +%Y%m%d-%H%M%S)"

# --- 1. Datenbank-Dump (konsistent dank --single-transaction, ohne Sperre)
f="$DIR/$STAMP-wehrkalender.sql.gz"
docker compose exec -T -e MYSQL_PWD="$(envval DB_ROOT_PASSWORD)" db \
  mariadb-dump -uroot --single-transaction --quick --routines --triggers --events --hex-blob wehrkalender \
  | gzip -9 > "$f.part"
gzip -t "$f.part"
mv "$f.part" "$f"; chmod 600 "$f"
log "Dump: $f ($(du -h "$f" | cut -f1))"

# --- 2. Konfiguration (ohne .env: Schlüssel werden getrennt verwahrt)
c="$DIR/$STAMP-konfig.tar.gz"
tar -czf "$c" -C "$APP" caddy/Caddyfile docker-compose.yml mariadb
chmod 600 "$c"
log "Konfiguration: $c"

# --- Lokale Aufbewahrung
find "$DIR" -maxdepth 1 -type f \( -name '*.sql.gz' -o -name '*-konfig.tar.gz' \) \
     -mtime +"$(envval BACKUP_LOCAL_DAYS)" -print -delete | sed 's/^/gelöscht: /'
find "$DIR" -maxdepth 1 -name '*.part' -delete

# --- 3. Versionierte Sicherung (verschlüsselt mit restic)
export RESTIC_REPOSITORY RESTIC_PASSWORD
RESTIC_REPOSITORY="$(envval RESTIC_REPOSITORY)"
RESTIC_PASSWORD="$(envval RESTIC_PASSWORD)"
# Zugangsschlüssel für S3-Ziele (nur wenn eingetragen)
if [[ -n "$(envval AWS_ACCESS_KEY_ID)" ]]; then
  export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
  AWS_ACCESS_KEY_ID="$(envval AWS_ACCESS_KEY_ID)"; AWS_SECRET_ACCESS_KEY="$(envval AWS_SECRET_ACCESS_KEY)"
fi
if [[ -z "$RESTIC_REPOSITORY" ]]; then
  log "WARNUNG: Kein Backup-Ziel eingetragen (RESTIC_REPOSITORY in $APP/.env) – nur einfache Dumps."
else
  if [[ "$RESTIC_REPOSITORY" == /* ]]; then install -d -m 700 "$RESTIC_REPOSITORY"; fi
  R=(restic)
  [[ "$RESTIC_REPOSITORY" == sftp:* ]] && R+=(-o "sftp.args=-i $KEY -o BatchMode=yes -o StrictHostKeyChecking=accept-new")
  rc=0; "${R[@]}" cat config >/dev/null 2>&1 || rc=$?
  if [[ $rc -eq 10 ]]; then
    log "Repository existiert noch nicht – wird angelegt."
    "${R[@]}" init
  elif [[ $rc -ne 0 ]]; then
    log "FEHLER: Backup-Ziel nicht erreichbar (restic Exit-Code $rc)."; false
  fi
  "${R[@]}" backup --quiet --host wehrkalender --tag wehrkalender "$f" "$c"
  "${R[@]}" forget --quiet --host wehrkalender --keep-daily "$(envval BACKUP_KEEP_DAYS)" --prune
  # Sonntags 5 % der Daten tatsächlich lesen und prüfen
  if [[ "$(date +%u)" == 7 ]]; then "${R[@]}" check --read-data-subset=5%; fi
  log "Gesichert in $RESTIC_REPOSITORY ($("${R[@]}" snapshots --host wehrkalender --json | jq length) Stände)"
  if [[ "$RESTIC_REPOSITORY" == /* ]]; then
    log "HINWEIS: Ziel liegt auf diesem Server – schützt nicht bei Ausfall des Servers."
  fi
fi

ping_url ""
log "Sicherung erfolgreich."
BACKUP

  cat > /usr/local/sbin/wehrkalender-restore <<'RESTORE'
#!/usr/bin/env bash
# =============================================================================
#  wehrkalender-restore – Datenbank aus einer Sicherung wiederherstellen
#  (angelegt von setup-server-wehrkalender.sh)
#
#  sudo wehrkalender-restore -l                     verfügbare Sicherungen anzeigen
#  sudo wehrkalender-restore <datei.sql.gz>         lokalen Dump einspielen
#  sudo wehrkalender-restore -r latest              neuesten restic-Stand einspielen
#  sudo wehrkalender-restore -r <snapshot-id>       bestimmten restic-Stand einspielen
#
#  Ziel ist standardmäßig die TEST-Datenbank wehrkalender_test (gefahrloser
#  Wiederherstellungstest). Produktion nur mit  -t wehrkalender  und Rückfrage.
# =============================================================================
set -Eeuo pipefail
APP=/srv/wehrkalender; DIR=/var/backups/wehrkalender; KEY=/root/.ssh/wehrkalender-backup
TARGET=wehrkalender_test; SNAP=""; LIST=0; YES=0
envval() {  # Wert aus .env; umschließende Anführungszeichen werden entfernt
  local v; v="$(grep -m1 "^$1=" "$APP/.env" | cut -d= -f2-)" || return 1
  if [[ "$v" =~ ^\"(.*)\"$ || "$v" =~ ^\'(.*)\'$ ]]; then v="${BASH_REMATCH[1]}"; fi
  printf '%s\n' "$v"
}
log() { printf '%s %s\n' "$(date '+%F %T')" "$*"; }
[[ $EUID -eq 0 ]] || { echo "Bitte mit sudo ausführen." >&2; exit 1; }

while getopts ":t:r:ly" o; do
  case "$o" in
    t) TARGET="$OPTARG" ;; r) SNAP="$OPTARG" ;; l) LIST=1 ;; y) YES=1 ;;
    *) sed -n '3,14p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
  esac
done
shift $((OPTIND-1))
[[ "$TARGET" =~ ^wehrkalender(_test)?$ ]] || { echo "Ziel muss wehrkalender oder wehrkalender_test sein." >&2; exit 2; }

export RESTIC_REPOSITORY RESTIC_PASSWORD
RESTIC_REPOSITORY="$(envval RESTIC_REPOSITORY)"; RESTIC_PASSWORD="$(envval RESTIC_PASSWORD)"
if [[ -n "$(envval AWS_ACCESS_KEY_ID)" ]]; then
  export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
  AWS_ACCESS_KEY_ID="$(envval AWS_ACCESS_KEY_ID)"; AWS_SECRET_ACCESS_KEY="$(envval AWS_SECRET_ACCESS_KEY)"
fi
R=(restic); [[ "$RESTIC_REPOSITORY" == sftp:* ]] && R+=(-o "sftp.args=-i $KEY -o BatchMode=yes")

if [[ $LIST -eq 1 ]]; then
  echo "Lokal ($DIR):"; ls -lh "$DIR"/*.sql.gz 2>/dev/null | awk '{print "  "$5"  "$9}' || echo "  keine"
  if [[ -n "$RESTIC_REPOSITORY" ]]; then echo "restic ($RESTIC_REPOSITORY):"; "${R[@]}" snapshots --host wehrkalender; fi
  exit 0
fi

TMP=""; trap '[[ -n "$TMP" ]] && rm -rf "$TMP"' EXIT
if [[ -n "$SNAP" ]]; then
  [[ -n "$RESTIC_REPOSITORY" ]] || { echo "Kein Backup-Ziel eingetragen." >&2; exit 1; }
  TMP="$(mktemp -d)"
  "${R[@]}" restore "$SNAP" --host wehrkalender --target "$TMP" --include '*.sql.gz'
  FILE="$(find "$TMP" -name '*-wehrkalender.sql.gz' | sort | tail -n1)"
else
  FILE="${1:-}"
fi
[[ -f "$FILE" ]] || { echo "Sicherungsdatei nicht gefunden: ${FILE:-(keine angegeben)}" >&2; exit 1; }
gzip -t "$FILE"

if [[ "$TARGET" == wehrkalender && $YES -ne 1 ]]; then
  echo "ACHTUNG: Die PRODUKTIVE Datenbank 'wehrkalender' wird vollständig ersetzt durch:"
  echo "         $FILE"
  echo "         Vorher wird automatisch eine aktuelle Sicherung erstellt."
  read -r -p "Zum Fortfahren 'wehrkalender' eintippen: " a
  [[ "$a" == wehrkalender ]] || { echo "Abgebrochen."; exit 1; }
fi
[[ "$TARGET" == wehrkalender ]] && { log "Sicherung des aktuellen Stands …"; /usr/local/sbin/wehrkalender-backup; }

cd "$APP"
export MYSQL_PWD; MYSQL_PWD="$(envval DB_ROOT_PASSWORD)"
db() { docker compose exec -T -e MYSQL_PWD db mariadb -uroot "$@"; }
log "Spiele $FILE in Datenbank $TARGET ein …"
db -e "DROP DATABASE IF EXISTS \`$TARGET\`; CREATE DATABASE \`$TARGET\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" </dev/null
gunzip -c "$FILE" | db "$TARGET"
log "Fertig: $(db -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$TARGET'" </dev/null) Tabellen in $TARGET."
RESTORE
  chmod 750 /usr/local/sbin/wehrkalender-backup /usr/local/sbin/wehrkalender-restore

  cat > /etc/systemd/system/wehrkalender-backup.service <<'EOF'
[Unit]
Description=Kalender: Datensicherung
Requires=docker.service
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/wehrkalender-backup
Nice=10
IOSchedulingClass=idle
EOF
  cat > /etc/systemd/system/wehrkalender-backup.timer <<'EOF'
[Unit]
Description=Kalender: tägliche Datensicherung

[Timer]
OnCalendar=*-*-* 03:15
RandomizedDelaySec=15min
Persistent=true

[Install]
WantedBy=timers.target
EOF
  systemctl daemon-reload
  systemctl enable --now wehrkalender-backup.timer >/dev/null
  log "Timer aktiv, nächste Sicherung: $(systemctl show wehrkalender-backup.timer -p NextElapseUSecRealtime --value)"

  log "Erste Sicherung zur Kontrolle …"
  if systemctl start wehrkalender-backup.service; then
    journalctl -u wehrkalender-backup -n 8 --no-pager -o cat | sed 's/^/    /'
  else
    journalctl -u wehrkalender-backup -n 20 --no-pager -o cat | sed 's/^/    /'
    log "WARNUNG: Erste Sicherung fehlgeschlagen (siehe oben). Einrichtung läuft weiter."
  fi
}

# ------------------------------------------------------------- Phase harden
phase_harden() {
  step "SSH härten"
  CONF=/etc/ssh/sshd_config.d/10-wehrkalender-hardening.conf
  cat > "$CONF" <<EOF
# Wehrkalender – SSH-Härtung (angelegt von setup-server-wehrkalender.sh)
Port $SSH_PORT
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AllowUsers $ADMIN_USER
X11Forwarding no
MaxAuthTries 4
LoginGraceTime 30
EOF
  chmod 644 "$CONF"
  if sshd -t; then
    systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || systemctl restart ssh
    log "SSH-Härtung aktiv. Wirksame Werte:"
    sshd -T 2>/dev/null | grep -Ei '^(permitrootlogin|passwordauthentication|kbdinteractiveauthentication|allowusers|port) ' | sed 's/^/    /'
  else
    rm -f "$CONF"
    log "FEHLER: sshd-Konfiguration ungültig – Änderung zurückgenommen."
    exit 1
  fi
}

# --------------------------------------------------------------- Phase app
# Anwendung aus dem hochgeladenen ZIP installieren bzw. aktualisieren.
#   /srv/wehrkalender/releases/<zeitstempel>/   entpackte Versionen (die letzten 3)
#   /srv/wehrkalender/app/                      aktive Version (Build-Kontext)
#   /srv/wehrkalender/docker-compose.yml        Caddy, app, worker, db
#   /srv/wehrkalender/caddy/Caddyfile           aus app/deploy/Caddyfile
phase_app() {
  local compose_bak caddy_bak
  step "Anwendung: Paket prüfen"
  [[ -f "$APP/.env" ]] || { log "FEHLER: $APP/.env fehlt – Server zuerst ohne -U einrichten."; exit 1; }
  guard_testdata
  [[ -f "$REMOTE_ZIP" ]] || { log "FEHLER: Hochgeladenes ZIP fehlt: $REMOTE_ZIP"; exit 1; }
  if [[ -n "$APP_SHA" ]]; then
    [[ "$(sha256sum "$REMOTE_ZIP" | cut -d' ' -f1)" == "$APP_SHA" ]] \
      || { log "FEHLER: Prüfsumme des hochgeladenen ZIP stimmt nicht – Übertragung fehlerhaft."; exit 1; }
    log "Prüfsumme in Ordnung."
  fi
  command -v unzip >/dev/null || { apt-get update -q && apt_install unzip; }

  step "Anwendung: entpacken (Version $RELEASE)"
  local rel="$APP/releases/$RELEASE"
  install -d -o "$ADMIN_USER" -g "$ADMIN_USER" -m 750 "$APP/releases" "$APP/app"
  rm -rf "$rel"; mkdir -p "$rel"
  unzip -q "$REMOTE_ZIP" -d "$rel"
  for f in Dockerfile public/index.php bin/console deploy/Caddyfile docker/crontab; do
    [[ -e "$rel/wehrkalender/$f" ]] || { log "FEHLER: $f fehlt im ZIP."; rm -rf "$rel"; exit 1; }
  done
  rm -f "$REMOTE_ZIP"
  # Laufzeitdaten (Sitzungen, Cache) liegen im Docker-Volume, nicht im Code-Verzeichnis
  rsync -a --delete --exclude '/storage/' --exclude '/.env' "$rel/wehrkalender/" "$APP/app/"
  printf 'Version:  %s\nPaket:    %s\nSHA-256:  %s\nInstalliert: %s\n' \
    "$RELEASE" "$APP_ZIP_NAME" "${APP_SHA:-unbekannt}" "$(date '+%F %T')" > "$APP/app/.release"
  chown -R "$ADMIN_USER:$ADMIN_USER" "$APP/releases" "$APP/app"
  # die letzten drei Versionen behalten (Rückkehr: ältere Version per -Z erneut einspielen)
  ls -1dt "$APP"/releases/*/ 2>/dev/null | tail -n +4 | xargs -r rm -rf
  log "Anwendung nach $APP/app kopiert; Versionen: $(ls -1 "$APP/releases" | paste -sd ' ')"

  step "Anwendung: Konfiguration"
  # Werte für die Anwendung ergänzen (bestehende bleiben unverändert)
  ensure_env APP_ENV            "prod"
  ensure_env APP_NAME           "Wehrkalender"   # Name in Oberfläche, E-Mails, auf den Geräten
  ensure_env WEHR_NAME          ""               # z. B. "FF Musterstadt" für „Kalender der …“, „Ihre …“
  ensure_env MAIL_DELIVERY      "database"   # Testbetrieb: E-Mails nur speichern; für echten Versand: smtp
  ensure_env MAILER_DSN         ""           # z. B. smtp://benutzer:passwort@relay.example.de:587
  ensure_env MAIL_FROM          "kalender@$DOMAIN"
  ensure_env MAIL_FROM_NAME     "Wehrkalender"
  ensure_env ADMIN_ALERT_EMAILS ""
  ensure_env FEED_BROWSER_NOTICE "0"         # 1 = Abo-Link im Browser zeigt Hinweisseite statt .ics (nach Gerätetest)
  ensure_env SOURCE_URL          "https://github.com/brami/Wehrkalender"   # Link zum Quellcode im Seitenfuß (AGPL-3.0); bei eigenen Änderungen auf das eigene Repository setzen
  # Impressum und Datenschutz: Betreiber des Dienstes (Anschrift: Zeilen mit ; trennen)
  ensure_env BETREIBER_NAME ""
  ensure_env BETREIBER_ANSCHRIFT ""
  ensure_env BETREIBER_VERTRETEN_DURCH ""
  ensure_env BETREIBER_EMAIL ""
  ensure_env BETREIBER_TELEFON ""
  ensure_env BETREIBER_REGISTER ""
  ensure_env DATENSCHUTZ_KONTAKT ""
  set_env DOMAIN "$DOMAIN"
  apply_app_settings
  apply_env
  log "E-Mail-Versand: $(envval MAIL_DELIVERY)$([[ "$(envval MAIL_DELIVERY)" == database ]] && echo ' (Testbetrieb, nichts wird verschickt)')"

  compose_bak="$APP/docker-compose.yml.bak-$STAMP"
  caddy_bak="$APP/caddy/Caddyfile.bak-$STAMP"
  [[ -f "$APP/docker-compose.yml" ]] && cp -p "$APP/docker-compose.yml" "$compose_bak"
  [[ -f "$APP/caddy/Caddyfile" ]] && cp -p "$APP/caddy/Caddyfile" "$caddy_bak"
  restore_config() {
    [[ -f "$compose_bak" ]] && cp -p "$compose_bak" "$APP/docker-compose.yml"
    [[ -f "$caddy_bak" ]] && cp -p "$caddy_bak" "$APP/caddy/Caddyfile"
    log "Vorherige docker-compose.yml und Caddyfile wiederhergestellt."
  }

  # Compose mit ausdrücklicher Liste der Umgebungsvariablen: Die Container
  # erhalten nur, was die Anwendung braucht – nicht DB_ROOT_PASSWORD,
  # RESTIC_PASSWORD oder Zugangsschlüssel für Backup-Ziele.
  cat > "$APP/docker-compose.yml" <<'EOF'
# Wehrkalender – Docker Compose (angelegt von setup-server-wehrkalender.sh)
# Anwendung: ./app (aus wehrkalender*.zip), Werte aus ./.env
x-app: &app
  build: ./app
  image: wehrkalender-app:latest
  restart: unless-stopped
  environment: &app-env
    APP_ENV: ${APP_ENV:-prod}
    APP_KEY: ${APP_KEY}
    APP_DOMAIN: ${DOMAIN}
    APP_NAME: ${APP_NAME:-Wehrkalender}
    WEHR_NAME: ${WEHR_NAME:-}
    SESSION_SECURE: "1"
    TRUSTED_PROXIES: "172.16.0.0/12,10.0.0.0/8,192.168.0.0/16"
    DB_DRIVER: mysql
    DB_HOST: db
    DB_PORT: "3306"
    DB_NAME: wehrkalender
    DB_USER: wehrkalender
    DB_PASSWORD: ${DB_PASSWORD}
    MAIL_DELIVERY: ${MAIL_DELIVERY:-database}
    MAILER_DSN: ${MAILER_DSN:-}
    MAIL_FROM: ${MAIL_FROM:-}
    MAIL_FROM_NAME: ${MAIL_FROM_NAME:-Wehrkalender}
    ADMIN_ALERT_EMAILS: ${ADMIN_ALERT_EMAILS:-}
    FEED_BROWSER_NOTICE: ${FEED_BROWSER_NOTICE:-0}
    SOURCE_URL: ${SOURCE_URL:-}
    BETREIBER_NAME: ${BETREIBER_NAME:-}
    BETREIBER_ANSCHRIFT: ${BETREIBER_ANSCHRIFT:-}
    BETREIBER_VERTRETEN_DURCH: ${BETREIBER_VERTRETEN_DURCH:-}
    BETREIBER_EMAIL: ${BETREIBER_EMAIL:-}
    BETREIBER_TELEFON: ${BETREIBER_TELEFON:-}
    BETREIBER_REGISTER: ${BETREIBER_REGISTER:-}
    DATENSCHUTZ_KONTAKT: ${DATENSCHUTZ_KONTAKT:-}
  volumes:
    - app-storage:/srv/app/storage
  healthcheck:              # erkennt z. B. fehlerhafte Abhängigkeiten im Image
    test: ["CMD", "php", "-r", "require '/srv/app/vendor/autoload.php';"]
    interval: 60s
    timeout: 10s
    retries: 3
    start_period: 90s
    start_interval: 5s
  depends_on:
    db:
      condition: service_healthy

services:
  caddy:
    image: caddy:2
    restart: unless-stopped
    environment:            # nur die Werte, die Caddy braucht – keine Passwörter
      DOMAIN: ${DOMAIN}
      TEST_DOMAIN: ${TEST_DOMAIN:-}
      ACME_EMAIL: ${ACME_EMAIL:-}
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"
    volumes:
      - ./caddy/Caddyfile:/etc/caddy/Caddyfile:ro
      - ./app/public:/srv/app/public:ro     # statische Dateien (CSS, JS)
      - ./www-test:/srv/www-test:ro
      - caddy_data:/data
      - caddy_config:/config
      - caddy_logs:/var/log/caddy
    networks: [frontend]

  app:                      # PHP-FPM: CalDAV, iCal-Feed, Web-Frontend; migriert beim Start
    <<: *app
    environment:
      <<: *app-env
      RUN_MIGRATIONS: "1"
    networks: [frontend, backend]

  worker:                   # gleiches Image, supercronic: import:run, mail:send, monitor:check, cleanup:run
    <<: *app
    command: ["supercronic", "-quiet", "/srv/app/docker/crontab"]
    networks: [backend, egress]   # egress: SMTP-Relay, später HTTPS-API von MP Feuer

  db:
    image: mariadb:11.4     # LTS-Version
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MARIADB_DATABASE: wehrkalender
      MARIADB_USER: wehrkalender
      MARIADB_PASSWORD: ${DB_PASSWORD}
      MARIADB_AUTO_UPGRADE: "1"
    volumes:
      - db-data:/var/lib/mysql
      - ./mariadb/wehrkalender.cnf:/etc/mysql/conf.d/wehrkalender.cnf:ro
    networks: [backend]     # kein Port nach außen, nur internes Netz
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 30s

networks:
  frontend:
  egress:
  backend:
    internal: true          # kein Zugang ins Internet und von außen

volumes:
  caddy_data:
  caddy_config:
  caddy_logs:
  db-data:
  app-storage:
EOF

  # Caddyfile aus dem Paket; Kontakt-E-Mail und Test-Domain je nach Aufruf
  {
    sed -e "s|^\([[:space:]]*\)# email {\$ACME_EMAIL}|$([[ -n "$ACME_EMAIL" ]] && echo '\1email {$ACME_EMAIL}' || echo '\1# email {$ACME_EMAIL}')|" \
        "$APP/app/deploy/Caddyfile"
    if [[ -n "$TEST_DOMAIN" ]]; then
      cat <<'CADDY'

# Testumgebung (setup-server-wehrkalender.sh -T)
{$TEST_DOMAIN} {
	import sicherheit
	header X-Robots-Tag "noindex, nofollow"
	root * /srv/www-test
	file_server
}
CADDY
    fi
  } > "$APP/caddy/Caddyfile"
  chown "$ADMIN_USER:$ADMIN_USER" "$APP/docker-compose.yml" "$APP/caddy/Caddyfile"

  cd "$APP"
  if ! docker compose config -q; then
    log "FEHLER: docker-compose.yml ist ungültig."; restore_config; exit 1
  fi
  caddy_validate() {
    docker run --rm -e DOMAIN="$DOMAIN" -e TEST_DOMAIN="$TEST_DOMAIN" -e ACME_EMAIL="$ACME_EMAIL" \
      --tmpfs /var/log/caddy -v "$APP/caddy/Caddyfile:/etc/caddy/Caddyfile:ro" caddy:2 \
      caddy validate --adapter caddyfile --config /etc/caddy/Caddyfile
  }
  docker pull -q caddy:2 >/dev/null
  if ! caddy_validate >/dev/null 2>&1; then
    caddy_validate 2>&1 | tail -5 || true
    log "FEHLER: Caddyfile ist ungültig."; restore_config; exit 1
  fi
  log "docker-compose.yml und Caddyfile geschrieben und geprüft (Sicherungskopien: *.bak-$STAMP)."
  # alte Sicherungskopien aufräumen (die letzten 5 behalten)
  ls -1t "$APP"/docker-compose.yml.bak-* 2>/dev/null | tail -n +6 | xargs -r rm -f
  ls -1t "$APP"/caddy/Caddyfile.bak-* 2>/dev/null | tail -n +6 | xargs -r rm -f

  step "Anwendung: Datensicherung vor dem Update"
  if systemctl cat wehrkalender-backup.service >/dev/null 2>&1 && docker compose ps --status running db 2>/dev/null | grep -q db; then
    if systemctl start wehrkalender-backup.service; then
      log "Sicherung erstellt: $(ls -t /var/backups/wehrkalender/*-wehrkalender.sql.gz 2>/dev/null | head -n1)"
    else
      journalctl -u wehrkalender-backup -n 10 --no-pager -o cat | sed 's/^/    /'
      log "WARNUNG: Sicherung fehlgeschlagen – Update läuft trotzdem weiter."
    fi
  else
    log "Keine laufende Datenbank oder kein Backup-Dienst – Sicherung übersprungen."
  fi

  step "Anwendung: Image bauen (kann einige Minuten dauern)"
  docker compose pull -q caddy db
  if ! docker compose build --pull app; then
    log "FEHLER: Image konnte nicht gebaut werden."; restore_config; exit 1
  fi

  step "Anwendung: Container starten"
  start_containers
  post_start_tasks
  check_health
}

# ---------------------------------------------------------- Phase settings
# Einstellungen der installierten Anwendung ohne neues ZIP:
# Umgebung (-E), Testdaten (-X), Administrator (-a)
phase_settings() {
  step "Anwendung: Einstellungen"
  app_installed || { log "FEHLER: Keine Anwendung installiert – bitte mit ZIP aufrufen."; exit 1; }
  guard_testdata
  apply_app_settings
  apply_env
  step "Anwendung: Container mit neuer Konfiguration starten"
  start_containers
  post_start_tasks
  check_health
}

# ------------------------------------------------------------ Phase summary
phase_summary() {
  step "Zusammenfassung"
  . /etc/os-release
  log "System:       $PRETTY_NAME, Kernel $(uname -r)"
  log "Hostname:     $(hostnamectl --static)"
  log "Zeitzone:     $(timedatectl show -p Timezone --value)"
  log "Admin:        $ADMIN_USER (Gruppen: $(id -nG "$ADMIN_USER"))"
  log "Firewall:     $(ufw status | head -n1)"
  log "fail2ban:     $(systemctl is-active fail2ban)"
  log "Docker:       $(docker --version)"
  if app_installed && [[ -f "$APP/app/.release" ]]; then
    log "Anwendung:    $(grep '^Version' "$APP/app/.release" | cut -d: -f2- | xargs) aus $(grep '^Paket' "$APP/app/.release" | cut -d: -f2- | xargs)"
    log "              Umgebung: $(effective_env) · E-Mail-Versand: $(envval MAIL_DELIVERY)"
    log "              Anwendungs-Admins: docker compose exec app php bin/console user:admin <benutzer>"
  else
    log "Anwendung:    noch nicht installiert (Platzhalterseite)"
  fi
  log "Container:"
  (cd /srv/wehrkalender && docker compose ps --format 'table {{.Name}}\t{{.Status}}\t{{.Ports}}') | sed 's/^/    /'
  local repo; repo="$(grep -m1 '^RESTIC_REPOSITORY=' /srv/wehrkalender/.env | cut -d= -f2-)"
  log "Backup:       täglich ~03:15, Dumps in /var/backups/wehrkalender (7 Tage),"
  log "              versioniert (30 Tage) in: ${repo:-NICHT eingerichtet}"
  [[ "$repo" == /* ]] && log "              HINWEIS: liegt auf diesem Server – für Schutz bei Totalausfall später extern sichern (-B)"
  log "              letzte Sicherung: $(ls -t /var/backups/wehrkalender/*-wehrkalender.sql.gz 2>/dev/null | head -n1 || echo keine)"
  log "              Wiederherstellungstest: sudo wehrkalender-restore -r latest   (spielt in wehrkalender_test ein)"
  log "Öffentlicher Schlüssel für das Backup-Ziel (bei SFTP dort hinterlegen):"
  sed 's/^/    /' /root/.ssh/wehrkalender-backup.pub
  log "WICHTIG: APP_KEY und RESTIC_PASSWORD getrennt vom Server verwahren (Passwort-Manager)."
  log "         Ohne RESTIC_PASSWORD sind die restic-Sicherungen nicht lesbar. Anzeigen mit:"
  log "         sudo grep -E '^(APP_KEY|RESTIC_PASSWORD)=' /srv/wehrkalender/.env"
  # Ubuntu legt /var/run/reboot-required an, Debian nicht: dort laufenden mit
  # neuestem installierten Kernel vergleichen
  local newest; newest="$(ls /boot/vmlinuz-* 2>/dev/null | sed 's|/boot/vmlinuz-||' | sort -V | tail -n1)"
  if [[ -f /var/run/reboot-required || ( -n "$newest" && "$newest" != "$(uname -r)" ) ]]; then
    log "HINWEIS: Ein Neustart ist erforderlich (Kernel-Update). Bitte zu passender Zeit: sudo reboot"
  fi
  log "Server-Protokoll: $REMOTE_LOG"
}

case "$PHASE" in
  base)     phase_base ;;
  harden)   phase_harden ;;
  app)      phase_app ;;
  settings) phase_settings ;;
  summary)  phase_summary ;;
  *) echo "Unbekannte Phase: $PHASE"; exit 2 ;;
esac
REMOTE
)

# ============================== Ablauf (lokal) ===============================
if [[ $UPDATE_ONLY -eq 1 ]]; then
  log "Phasen 1–3 übersprungen (-U: nur Anwendung)."
else
  log "Phase 1: Grundeinrichtung"
  run_remote base <<<"$REMOTE_SCRIPT"

  log "Phase 2: Anmeldung als $ADMIN_USER testen"
  if ssh "${SSH_OPTS[@]}" "$ADMIN_USER@$SERVER" 'sudo -n true && docker ps >/dev/null'; then
    log "Anmeldung als $ADMIN_USER mit sudo und Docker funktioniert."
  else
    fail "Anmeldung als $ADMIN_USER fehlgeschlagen – SSH wird NICHT gehärtet, root-Login bleibt möglich."
  fi

  # Ab hier immer als Admin-Benutzer arbeiten
  TARGET="$ADMIN_USER@$SERVER"; SUDO="sudo -n"

  if [[ "$SKIP_HARDEN" -eq 1 ]]; then
    log "Phase 3: SSH-Härtung übersprungen (-S)."
  else
    log "Phase 3: SSH härten"
    run_remote harden <<<"$REMOTE_SCRIPT"
    log "Kontrolle: Anmeldung als $ADMIN_USER nach der Härtung"
    ssh "${SSH_OPTS[@]}" "$ADMIN_USER@$SERVER" true || fail "Anmeldung nach Härtung fehlgeschlagen! Bestehende SSH-Sitzung offen halten und Konfiguration prüfen."
    if ssh "${SSH_OPTS[@]}" "root@$SERVER" true 2>/dev/null; then
      log "WARNUNG: root-Login ist noch möglich."
    else
      log "root-Login ist gesperrt (erwartet)."
    fi
  fi
fi

if [[ -n "$APP_ZIP" ]]; then
  log "Phase 4: Anwendung installieren ($(basename "$APP_ZIP"))"
  REMOTE_ZIP="/tmp/wehrkalender-app-$STAMP.zip"
  log "Lade ZIP hoch nach $SERVER:$REMOTE_ZIP …"
  # shellcheck disable=SC2029  # Pfad soll lokal expandiert werden
  ssh "${SSH_OPTS[@]}" "$TARGET" "umask 077; cat > '$REMOTE_ZIP'" < "$APP_ZIP" \
    || fail "Hochladen des ZIP fehlgeschlagen."
  run_remote app <<<"$REMOTE_SCRIPT"
elif [[ $APP_SETTINGS -eq 1 && $APP_INSTALLED -eq 1 ]]; then
  log "Phase 4: Einstellungen der Anwendung (ohne neues ZIP)"
  run_remote settings <<<"$REMOTE_SCRIPT"
else
  log "Phase 4: Anwendung übersprungen (kein ZIP)."
fi

log "Phase 5: Zusammenfassung"
run_remote summary <<<"$REMOTE_SCRIPT"

for d in "$DOMAIN" $TEST_DOMAIN; do
  if curl -fsS --max-time 15 -o /dev/null "https://$d"; then
    log "HTTPS-Test https://$d: erreichbar."
  else
    log "HTTPS-Test https://$d: noch nicht erreichbar (DNS/Zertifikat prüfen; Caddy versucht es automatisch erneut)."
  fi
done

if [[ -n "$APP_ZIP" || ( $APP_SETTINGS -eq 1 && $APP_INSTALLED -eq 1 ) ]]; then
  log "Anwendung: https://$DOMAIN   (Verwaltung: https://$DOMAIN/admin)"
  log "Nächste Version einspielen: ZIP neben das Skript legen und mit -U erneut aufrufen."
fi
log "Fertig. Anmeldung künftig: ssh $ADMIN_USER@$SERVER"
log "Lokales Protokoll: $LOCAL_LOG"
