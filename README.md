# Kalender FF KRZ

Kalenderserver für die Mitglieder der FF KRZ: CalDAV und iCal-Abos, Terminübersicht als PDF, Verwaltung.

| Verzeichnis | Inhalt |
|---|---|
| `kalender/` | Anwendung (PHP 8.3, Slim 4, Twig, sabre/dav, MariaDB, Docker) – siehe `kalender/README.md` |
| `server/` | `setup-server-kalender-ff.sh`: Einrichtung und Updates des vServers |
| `doku/kalender/` | Projektbeschreibung, Implementierung, Betrieb (LaTeX) |
| `doku/anleitung/` | Bebilderte Anleitung für Mitglieder (LaTeX, Screenshots in `bilder/`) |
| `doku/latex/` | Vorlage `debsupport.sty` |

## Paket für den Server bauen

`vendor/` ist nicht im Repository; der Docker-Build installiert die Abhängigkeiten selbst.

```sh
zip -r kalender-ff-krz.zip kalender -x 'kalender/vendor/*' 'kalender/.env' 'kalender/storage/*'
cp server/setup-server-kalender-ff.sh .
./setup-server-kalender-ff.sh -H <server> -d <domain> -U
```

## Dokumentation übersetzen

```sh
cd doku/kalender && TEXINPUTS=../latex: pdflatex projektbeschreibung_kalender_ff_krz.tex   # 2–3 Läufe
```

## Lizenz

© 2026 Michael Bramer

- **Code** (`kalender/`, `server/`): GNU Affero General Public License v3.0 oder später
  (`AGPL-3.0-or-later`), siehe [`LICENSE`](LICENSE).
  Wer die Anwendung als Webdienst betreibt, muss den Nutzern den Quellcode anbieten (AGPL §13).
  Dafür in `/srv/kalender/.env` `SOURCE_URL` setzen; der Link erscheint dann im Seitenfuß.
- **Dokumentation** (`doku/`): Creative Commons Namensnennung – Weitergabe unter gleichen
  Bedingungen 4.0 International (`CC-BY-SA-4.0`), siehe [`doku/LICENSE`](doku/LICENSE).
  Ausgenommen ist die Vorlage `doku/latex/debsupport.sty` (siehe `doku/latex/HINWEIS.md`).
- Die verwendeten Bibliotheken (Slim, Twig, sabre/dav, Symfony, FPDF u. a.) stehen unter
  MIT- bzw. BSD-3-Clause-Lizenzen und sind mit der AGPL vereinbar.
