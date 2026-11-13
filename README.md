# Wehrkalender

Quellcode: https://github.com/brami/Wehrkalender

Bringt die Termine einer Feuerwehr aus MP Feuer nur lesend auf die Geräte der Mitglieder: CalDAV für Smartphone und Tablet, Abo-Links für Outlook und Google Kalender, Terminübersicht als PDF, Verwaltung mit Nutzungsübersicht. Erster Einsatz: Freiwillige Feuerwehr Kreuzau.

| Verzeichnis | Inhalt |
|---|---|
| `wehrkalender/` | Anwendung (PHP 8.3, Slim 4, Twig, sabre/dav, MariaDB, Docker) – siehe `wehrkalender/README.md` |
| `server/` | `setup-server-wehrkalender.sh`: Einrichtung des Servers, Installation und Updates der Anwendung |
| `doku/technik/` | Technische Umsetzung und Betrieb (LaTeX) |
| `doku/ff-kreuzau/` | Erster Einsatz: Projektbeschreibung, bebilderte Anleitung für Mitglieder, Fragen und Entscheidungen |
| `doku/latex/` | Vorlage `debsupport.sty` |

## Server einrichten und aktualisieren

Das Skript wird direkt aus dem Checkout aufgerufen und baut das Paket selbst aus Git
(`git archive`, committeter Stand von `HEAD`; nicht committete Änderungen werden nicht
ausgeliefert). `vendor/` ist nicht im Repository; der Docker-Build installiert die
Abhängigkeiten.

```sh
cd server
./setup-server-wehrkalender.sh -H <server> -d <domain> -E test   # Erstinstallation (legt wehrkalender.env an)
git pull && ./setup-server-wehrkalender.sh -U                    # Update auf den aktuellen Stand
./setup-server-wehrkalender.sh -U -G v1.0                        # bestimmten Tag/Commit ausliefern
./setup-server-wehrkalender.sh -U -Z ""                          # nur Einstellungen übernehmen
```

Außerhalb eines Checkouts (Skript einzeln kopiert) nimmt das Skript die neueste
`wehrkalender*.zip` neben sich bzw. `-Z <datei>`.

## Dokumentation übersetzen

```sh
cd doku/technik && TEXINPUTS=../latex: pdflatex implementierung_wehrkalender.tex   # 2–3 Läufe
```

## Lizenz

© 2026 Michael Bramer

- **Code** (`wehrkalender/`, `server/`): GNU Affero General Public License v3.0 oder später
  (`AGPL-3.0-or-later`), siehe [`LICENSE`](LICENSE).
  Wer die Anwendung als Webdienst betreibt, muss den Nutzern den Quellcode anbieten (AGPL §13).
  Dafür in `/srv/wehrkalender/.env` `SOURCE_URL` setzen; der Link erscheint dann im Seitenfuß.
- **Dokumentation** (`doku/`): Creative Commons Namensnennung – Weitergabe unter gleichen
  Bedingungen 4.0 International (`CC-BY-SA-4.0`), siehe [`doku/LICENSE`](doku/LICENSE).
  Ausgenommen ist die Vorlage `doku/latex/debsupport.sty` (siehe `doku/latex/HINWEIS.md`).
- Die verwendeten Bibliotheken (Slim, Twig, sabre/dav, Symfony, FPDF u. a.) stehen unter
  MIT- bzw. BSD-3-Clause-Lizenzen und sind mit der AGPL vereinbar.
