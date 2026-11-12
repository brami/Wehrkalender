# Wehrkalender – Anwendung

Stellt die Termine aus MP Feuer nur lesend bereit:
- für iPhone, iPad und Android per CalDAV unter `/dav/`,
- für Outlook und Google als Abo-Link `/abo/<token>.ics`,
- dazu ein Web-Frontend für Mitglieder und Administratoren.

Technische Beschreibung: `doku/technik/implementierung_wehrkalender.tex`.
**Noch nicht enthalten:** der Import aus MP Feuer (siehe `doku/ff-kreuzau/fragen_und_unstimmigkeiten.md`).

Technik: PHP 8.3, Slim 4, Twig, sabre/dav 4.7, sabre/vobject 4.5, Symfony Mailer/Console, MariaDB 11.4 (lokal auch SQLite).

## Schnellstart lokal (ohne Docker, mit SQLite)

```bash
composer install                  # entfällt, wenn vendor/ mitgeliefert ist
cp .env.example .env
# in .env setzen:
#   APP_ENV=dev  APP_KEY=$(head -c 32 /dev/urandom | base64)
#   APP_URL=http://localhost:8080  APP_DOMAIN=localhost  SESSION_SECURE=0
#   DB_DRIVER=sqlite  DB_PATH=storage/wehrkalender.sqlite  MAIL_DELIVERY=database
php bin/console testdata:load            # Datenbank anlegen und mit Testdaten füllen
php -S localhost:8080 -t public public/index.php
```

Danach im Browser `http://localhost:8080` öffnen. Die Zugangsdaten stehen in `storage/testdaten-zugaenge.txt`.

CalDAV lässt sich mit curl prüfen:

```bash
curl -u juergen.mueller:<passwort> -X PROPFIND -H "Depth: 1" http://localhost:8080/dav/calendars/juergen.mueller/
```

## Testdaten

`php bin/console testdata:load` **löscht alle Tabellen** und legt Folgendes an:

- **Kalender** einer Beispielwehr (angelehnt an die FF Kreuzau, das erste Einsatzgebiet): FF KRZ (für alle), LG Drove, LG Kreuzau, LG Stockheim, GJF, JF Gr1, KF, TM1 2027, Atemschutz und einen deaktivierten Lehrgang TM1 2025.
- **Bereich → Kalender:** auch mehrere Bereiche je Kalender sowie Bereiche ohne Kalender (Förderverein, Ehrenabteilung, Musikzug).
- **30 Mitglieder**, je ein Testfall:
  - Administratoren
  - Umlaute, ß, Doppelnamen, Sonderzeichen
  - Namensgleichheit (`max.mustermann2`)
  - Heirat mit veraltetem Benutzername
  - reservierter alter Name (`dieter.langer2`)
  - gesperrt wegen ungültiger Adresse
  - nie angemeldet, Begrüßung abgelaufen
  - kein Abruf seit 40 Tagen
  - Geschwister mit gleicher E-Mail
  - Kalender entzogen, Abo-Links neu erzeugt, Passwort neu erzeugt
  - E-Mail-Adresse geändert, neuer Kalender seit gestern
- **Rund 350 Termine:**
  - Serien, ganztägige und mehrtägige Termine
  - abgesagte, geänderte, gelöschte und in einen anderen Kalender verschobene Termine
  - Nachtübungen über die Zeitumstellung
  - ein Termin älter als 12 Monate (nicht im Feed)
- **Importprotokolle:** erfolgreich, fehlgeschlagen (Schema), abgebrochen (Plausibilität). Dazu Meldungen wie gesperrt, nicht übernommen, ausgeschieden, Termin ohne Kalender.
- **E-Mails:** versendet, offen, endgültig und vorübergehend fehlgeschlagen.

Optionen:

| Option | Wirkung |
|---|---|
| `--import=failed` | letzter Import fehlgeschlagen |
| `--import=stale` | letzter Export vor 30 Stunden: `monitor:check` warnt, `/health` antwortet 503 |
| `--passwort=…` | gleiches Passwort für alle Testbenutzer |

Bei `APP_ENV=prod` startet das Programm nur mit `--yes`.

## Konsolenbefehle

| Befehl | Zweck |
|---|---|
| `db:migrate` | Datenbank-Migrationen |
| `user:admin <benutzer> [--revoke]` | Administratorrechte vergeben bzw. entziehen |
| `mail:send` | Mail-Warteschlange abarbeiten (60 je Lauf, 5 Versuche) |
| `mail:test <adresse>` | Test-E-Mail direkt über `MAILER_DSN` senden (auch bei `MAIL_DELIVERY=database`); Anleitung „E-Mail-Versand einrichten“ in der technischen Doku |
| `monitor:check [--force]` | Warnung bei ausbleibendem oder fehlerhaftem Import |
| `cleanup:run` | Einmal-Links, alte Protokolle, E-Mails und Archivdateien löschen |
| `import:run` | **Platzhalter**, Import noch nicht umgesetzt |
| `testdata:load` | Testdaten (siehe oben) |

## Tests

```bash
vendor/bin/phpunit        # SQLite im Speicher
```

Abgedeckt sind:
- Benutzernamen (Umlaute, Ziffern, reservierte Namen, Umstellung),
- Passwörter und Einmal-Links,
- iCalendar (Sommer-/Winterzeit, ganztägig, abgesagt, SEQUENCE, Escaping),
- CalDAV- und Feed-Zugriffsschutz (fremde Kalender, entzogene Zuordnung, gesperrt, widerrufene Tokens),
- Schreibversuche (403), WebDAV-Sync, Zeitbereichsabfragen,
- Terminübersicht als PDF, Seiten für Mitglieder, Impressum, Konfiguration,
- das Testdaten-Programm.

Gegen MariaDB (Testdatenbank, wird geleert!):

```bash
TEST_DB_DSN="mysql:host=127.0.0.1;dbname=wehrkalender_test;charset=utf8mb4" \
TEST_DB_USER=wehrkalender_test TEST_DB_PASSWORD=… vendor/bin/phpunit
```

## Betrieb

Der Server wird mit `server/setup-server-wehrkalender.sh` eingerichtet. Das Skript
installiert und aktualisiert auch die Anwendung (ZIP `wehrkalender*.zip` neben
dem Skript, Update mit `-U`) und schreibt `docker-compose.yml` und `Caddyfile`.
Alle Einstellungen stehen in `/srv/wehrkalender/.env`; die Container erhalten sie
als Umgebungsvariablen. Erläuterungen in `.env.example` und in der technischen
Doku (Kapitel Betrieb).

| APP_ENV | Wirkung |
|---|---|
| `test` | Hinweisband „Testumgebung“, Fehlerdetails im Browser, Testdaten erlaubt |
| `prod` | keine Fehlerdetails, Testdaten nur mit `--yes` (Skript sperrt `-X`) |
| `dev`  | lokale Entwicklung, zusätzlich sabre/dav-Browser unter `/dav/` |

Ohne Docker: siehe `deploy/systemd/README.txt` und den Abschnitt „Installation ohne Docker“ der technischen Doku.

## Aufbau

```
bin/console              Konsolenbefehle (Symfony Console)
public/index.php         Einstieg: /dav/ → sabre/dav, sonst Slim
src/App.php              Dienst-Container
src/Database/            PDO-Schicht (MariaDB/SQLite), Migrator
src/Domain/              Benutzernamen, Konten, Mitgliedschaften, Status/Überwachung
src/Calendar/            iCalendar-Erzeugung, Termine + Sync-Protokoll,
                         Terminübersicht (Daten), Feiertage/Schulferien NRW
src/Pdf/                 Terminübersicht als PDF (FPDF)
src/Import/              Bausteine für den Import (E-Mail-Prüfung)
src/Dav/                 CalDAV: Auth-, Principal-, Kalender-Backend, Server
src/Web/                 Controller, Sitzung, Routen
src/Mail/                Warteschlange, Versand
src/TestData/            Testdaten-Programm
templates/               Twig-Vorlagen (Seiten, E-Mails, Anleitungen)
migrations/              Datenbankschema
deploy/                  Caddyfile, systemd-Timer (Installation ohne Docker)
docker/                  Dockerfile-Zubehör (php.ini, FPM, crontab, Entrypoint)
tests/                   PHPUnit
```

## Für den späteren Import

Die Bausteine sind fertig:

| Baustein | Aufgabe |
|---|---|
| `AccountService::createUser()` | Benutzername, Passwort |
| `AccountService::needsWelcome()` / `queueWelcome()` | Begrüßung |
| `MembershipService::replaceMemberships()` / `recompute()` | Kalender, Abo-Links, Hinweis-E-Mail |
| `EventStore::save()` / `delete()` | iCalendar, ETag, Sync-Token, `calendar_changes` |
| `StatusService::setState('import.last_export_seen_at', …)` | Überwachung |
| `StatusService::alertAdmins()` | Warnungen |
| `Db::lock()` / `Db::transaction()` | Sperre und Transaktion |
