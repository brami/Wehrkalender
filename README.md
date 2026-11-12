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
