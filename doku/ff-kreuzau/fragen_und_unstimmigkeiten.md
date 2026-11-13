# Kalender der FF KRZ (Software: Wehrkalender) – Fragen und Unstimmigkeiten

Stand: 13.11.2026 · gesammelt bei der Umsetzung der Anwendung (ohne Import aus MP Feuer)

Entschieden am 09.11.2026 (M. Bramer): A1, A3, A6, A7, A8, A9, A10, A11 – siehe dort, eingepflegt in die technische Doku und die Projektbeschreibung. Ebenfalls am 09.11.2026: Anleitung zu B2 in die technische Umsetzung aufgenommen; Android-Anleitung um Abo-Links im Google Kalender ergänzt (E1). Am 13.11.2026: eine Datei befüllt mehrere Kalender, Einstellungsdatei für das Setup-Skript (E8). Am 12.11.2026: Terminübersicht als PDF (E2), Kalenderansicht im Web vorgemerkt (F), Startseite und „Kalender abonnieren“ (E3), Abonnieren statt importieren (E4), Git-Repository, Lizenz und Impressum (E5), Umbenennung in „Wehrkalender“ (E6), Konzept weitere Quellen und Kalenderauswahl (E7). Offen: A2, A4, A5, A12.

Grundlage: `doku/technik/implementierung_wehrkalender.tex` (+ `_betrieb.tex`), `doku/ff-kreuzau/projektbeschreibung_kalender_ff_krz.tex`, `server/setup-server-wehrkalender.sh`. Ältere Einträge nennen noch die früheren Namen (`implementierung_kalender_ff_krz.tex`, `setup-server-kalender-ff.sh`, `/srv/kalender`), siehe E6.

Wo eine Entscheidung nötig war, ist eine Lösung umgesetzt und hier als **Umgesetzt** vermerkt. Jede davon lässt sich ändern.

---

## A. Fragen, die vor dem Start geklärt werden sollten

### A1. Kalender „FF KRZ“ für alle Mitglieder
Laut Projektbeschreibung sieht ein Mitglied der LG Drove auch den Kalender **FF KRZ**. Das Datenmodell ordnet Kalender aber nur über `memberships ⋈ calendar_sources` zu. FF KRZ erscheint also nur, wenn MP Feuer jedem Mitglied eine Mitgliedschaft „FF-KRZ“ exportiert.
**Umgesetzt:** zusätzliche Spalte `calendars.all_members`. Ein so markierter Kalender ist für alle aktiven Mitglieder freigeschaltet. Die Einstellung lässt sich im Admin-Bereich unter „Kalender & Zuordnungen“ ändern.
**Entschieden (09.11.2026):** Es bleibt vorerst bei der Markierung „für alle Mitglieder“.

### A2. Gleicher Schlüssel für Termin-Bereich und Mitgliedschaft?
`calendar_sources` legt zwei Dinge zugleich fest: In welchen Kalender die Termine eines Bereichs kommen und welche Mitglieder den Kalender sehen. Das funktioniert nur, wenn `termine.bereich` und `mitgliedschaften.schluessel` dieselben Schlüssel verwenden.
Beispiel: Betreuer der JF Gr1 sind in MP Feuer eventuell nicht als „JF-GR1“ geführt.
**Umgesetzt:** Mehrere Schlüssel können auf denselben Kalender zeigen (Testdaten: `JF-GR1` und `JF-GR1-BETREUER` → JF Gr1).
**Frage an MP Feuer:** Verwenden beide Exporte dieselben Schlüssel? *(Stand 09.11.2026: noch offen)*

### A3. Mehrere Mitglieder mit derselben E-Mail-Adresse
In der Kinder- und Jugendfeuerwehr ist oft bei Geschwistern die Adresse der Eltern hinterlegt. Die Anmeldung mit „E-Mail + Passwort“ ist dann nicht eindeutig.
**Umgesetzt:**
- Bei Anmeldung mit E-Mail wird das Passwort gegen alle Konten mit dieser Adresse geprüft. Da die Passwörter zufällig sind, passt es nur zu einem Konto.
- Bei „Anmeldelink anfordern“ geht je Konto eine eigene E-Mail raus.
- Testfall: Mia und Noah Schulz.

**Entschieden (09.11.2026):** So gewollt. Jedes Mitglied mit hinterlegter E-Mail-Adresse erhält einen Zugang, auch Kinder und Jugendliche; mehrere Zugänge mit derselben Adresse sind in Ordnung.

### A4. Exportiert MP Feuer auch vergangene Termine?
Die Regel „nicht mehr im Export → aus dem Kalender entfernen“ hat eine Folge: Exportiert MP Feuer nur künftige Termine, verschwinden vergangene Termine von allen Geräten.
**Frage an MP Feuer:** Welcher Zeitraum wird exportiert?
Der Abo-Feed zeigt ohnehin nur die letzten 12 Monate. Für CalDAV ist keine Grenze festgelegt, und auch für die Datenbank gibt es bisher keine Löschfrist für alte Termine.

### A5. Domain vor dem Start endgültig festlegen
Die Termin-UID enthält die Domain (`mp-<id>@<domain>`). Ändert sich die Domain später, ändern sich alle UIDs. Auf den Geräten entstehen dann Doppel, und alle Abo-Links und Geräte-Einrichtungen müssen erneuert werden. Derzeit ist der Platzhalter `kalender.example.de` gesetzt.
**Bitte die endgültige Domain vor dem ersten Echtbetrieb festlegen.**

### A6. Abo-Link in der E-Mail „neuer Kalender“
Laut Projektbeschreibung enthält diese E-Mail den Abo-Link. Der Link ist aber ein Geheimnis, gleichwertig zu einem Passwort für diesen einen Kalender. Laut Doku stehen Passwörter nie in E-Mails.
**Entschieden und umgesetzt (09.11.2026):** Abo-Links stehen nur im Web-Frontend. Die E-Mail nennt die neuen Kalender und enthält einen Anmeldelink, der 14 Tage gilt (neuer Token-Zweck `notice`, einmal verwendbar). Die Projektbeschreibung ist entsprechend angepasst.

### A7. Hinweis „neuer Kalender“ bei neuen Mitgliedern
Ein neues Mitglied bekommt die Begrüßung. Soll es zusätzlich für jeden Kalender den Hinweis „neuer Kalender“ bekommen?
**Umgesetzt (als Vorgabe für den Import):** nein. Die Begrüßung nennt die Kalender bereits (Parameter `notify` bei `MembershipService::recompute()`).
**Entschieden (09.11.2026):** So in Ordnung. Neue Mitglieder erhalten nur die Begrüßungs-E-Mail mit dem Anmeldelink für das Web-Frontend; dort stehen Kalender und Abo-Links.

### A8. Reservierte Benutzernamen und Löschung ausgeschiedener Mitglieder
Zwei Vorgaben der Doku widersprechen sich:
- „Frühere Benutzernamen bleiben reserviert und werden nie neu vergeben.“
- „Ausgeschiedene Mitglieder werden mit allen abhängigen Datensätzen gelöscht (`ON DELETE CASCADE`).“

Durch die Löschung verschwindet auch der Eintrag in `username_history`, und der alte Name wird wieder frei. Ebenso wird der aktuelle Benutzername eines Ausgeschiedenen frei.
**Umgesetzt:** Löschung wie in der Doku (Datenschutz hat Vorrang).
**Entschieden (09.11.2026):** So gewollt. Frühere Benutzernamen bleiben reserviert, solange das Mitglied geführt wird; scheidet es aus, werden sie mit allen Daten gelöscht und können wieder vergeben werden.
Außerdem: **Umgesetzt** ist, dass ein Mitglied **seine eigenen** früheren Namen wieder erhalten kann, z. B. nach einer Scheidung. Sie werden ja nicht an jemand anderen vergeben.

### A9. Anmeldebegrenzung je IP-Adresse
Die Grenze liegt bei 30 Fehlversuchen je IP und Stunde. Im Gerätehaus sind oft alle Mitglieder über **eine** IP-Adresse (WLAN/NAT) im Netz. Ein Einrichtungsabend mit vielen Tippfehlern oder einige Geräte mit altem Passwort (CalDAV wiederholt alle 15 Minuten) können dann die Anmeldung für alle sperren.
**Umgesetzt:** wie in der Doku, und die Begrenzung gilt für Web und CalDAV.
**Entschieden und umgesetzt (09.11.2026):** Die Grenzen bleiben. Gesperrte Benutzer und IP-Adressen werden auf der Status-Seite unter „Gesperrte Anmeldungen“ (und in der Detailansicht des Mitglieds) mit Anzahl der Versuche und Ende der Sperre aufgeführt und lassen sich einzeln oder alle auf einmal aufheben. IP-Adressen werden nur gekürzt angezeigt (IPv4 /24, IPv6 /48); gespeichert wird zusätzlich nur ein HMAC. Dafür neue Spalte `login_attempts.info` (Migration 002).

### A10. Empfänger der Warnungen
**Umgesetzt:** Warnungen gehen an `ADMIN_ALERT_EMAILS` aus der `.env` **und** an alle aktiven Administratoren.
**Entschieden (09.11.2026):** So gewünscht.

### A11. Aufbewahrungsfristen, die in der Doku fehlen
**Umgesetzt** in `cleanup:run`:
- versendete E-Mails nach 30 Tagen löschen (sie enthalten Anmelde- und Abo-Links),
- fehlgeschlagene E-Mails nach 90 Tagen,
- abgelaufene Einmal-Links nach 1 Tag,
- Anmeldeversuche nach 1 Tag.

`calendar_changes` wird bewusst **nicht** gekürzt. Sonst bekämen Geräte mit altem Sync-Token unvollständige Änderungslisten. Die Tabelle wächst nur langsam.
**Entschieden (09.11.2026):** So in Ordnung.

### A12. Wer bekommt Administratorrechte, und wer betreut den Betrieb dauerhaft?
Die Frage ist bereits in der Doku offen. Für den Test sind `michael.bramer` und `dieter.lang` Administratoren.

---

## B. Unstimmigkeiten in den Unterlagen

### B1. `setup-server.sh` überschreibt die Anwendung
Das Skript schreibt `docker-compose.yml`, `caddy/Caddyfile` und `www/index.html` bei **jedem** Lauf neu (nur Caddy-Testseite und MariaDB). Ein erneuter Lauf, etwa für `-B` (externes Backup-Ziel), entfernt also App und Worker aus Compose und Caddy.
**Erledigt (09.11.2026):** Das Skript heißt jetzt `setup-server-kalender-ff.sh` und installiert die Anwendung selbst (ZIP neben dem Skript bzw. `-Z`, Aktualisierung mit `-U`). Ist die Anwendung installiert, lässt die Grundeinrichtung `docker-compose.yml` und `Caddyfile` unverändert. Die Compose-Datei des Skripts reicht nur die nötigen Umgebungsvariablen an die Container weiter (kein `DB_ROOT_PASSWORD`, kein `RESTIC_PASSWORD`) und ist damit maßgeblich; `deploy/docker-compose.yml` im ZIP ist nur noch ein Beispiel.
Die technische Doku ist auf den neuen Namen umgestellt (09.11.2026). Zusätzlich kennt das Skript `-E prod|test` zum Umschalten der Umgebung (`APP_ENV`).

### B2. Namen der Konfigurationswerte
- Die Doku nennt `APP_DOMAIN`, `setup-server.sh` schreibt `DOMAIN`. **Umgesetzt:** Die Anwendung liest beides.
- In der `.env` von `setup-server.sh` fehlten SMTP-Zugang, `MAIL_FROM` und `ADMIN_ALERT_EMAILS`. `setup-server-kalender-ff.sh` legt diese Einträge an (leer bzw. mit Vorgabe), die Werte müssen aber von Hand eingetragen werden.

**Erledigt (09.11.2026):** Anleitung „E-Mail-Versand einrichten“ in der technischen Umsetzung (Abschnitt Betrieb): SMTP-Zugang, SPF/DKIM/DMARC, Einträge in `/srv/kalender/.env` mit Beispiel und Hinweisen zur Kodierung von Sonderzeichen (auch `$` → `$$` wegen Docker Compose), Übernehmen mit `docker compose up -d`, Test, typische Fehlermeldungen, Umschalten auf `MAIL_DELIVERY=smtp`. Dafür neues Kommando `mail:test <adresse>`: Es zeigt die Einstellungen (Passwort ausgeblendet) und schickt eine Test-E-Mail direkt über `MAILER_DSN`, auch wenn `MAIL_DELIVERY` noch auf `database` steht.

### B3. Netzwerke in Compose
Im Compose von `setup-server.sh` hängt `db` nur am internen Netz `backend`. Das passt. Der Worker braucht aber zusätzlich Internetzugang (SMTP-Relay, später HTTPS-API von MP Feuer).
**Umgesetzt** in `deploy/docker-compose.yml`: eigenes Netz `egress` für den Worker.

### B4. Datenmodell: Ergänzungen gegenüber der Doku
| Tabelle | Ergänzung | Grund |
|---|---|---|
| `events` | `sequence_no`, `created_at` | `SEQUENCE` soll bei jeder Änderung steigen und muss daher gespeichert werden. `CREATED` im iCalendar. (`sequence` ist in MariaDB ein Schlüsselwort.) |
| `calendars` | `all_members`, `created_at`, `updated_at` | siehe A1 |
| `mail_queue` | `next_attempt_at`, `created_at`, Status `suppressed` | Wiederholung mit wachsendem Abstand; Testbetrieb ohne Versand |
| `login_attempts` | `throttle_key` statt `key`, `info` | `KEY` ist in MariaDB ein reserviertes Wort; `info` für die Sperrliste (siehe A9) |
| `login_tokens` | `created_at`, Zweck `notice` | Nachvollziehbarkeit; Anmeldelink in der E-Mail „neuer Kalender“ (14 Tage, siehe A6) |
| `import_messages` | `created_at` | Nachvollziehbarkeit |
| `users` | `created_at`, `updated_at` | Nachvollziehbarkeit |
| neu: `app_state` | Schlüssel/Wert | siehe B5 |
| neu: `schema_migrations` | – | für `db:migrate` |

### B5. Warnung „seit 24 Stunden kein neuer Export“ würde falsch auslösen
Ein Import-Lauf wird nur protokolliert, wenn sich die Daten geändert haben (Prüfsumme). Ändert sich in MP Feuer einen Tag lang nichts, etwa am Wochenende, gäbe es keinen Lauf, und `monitor:check` bzw. `/health` würden fälschlich warnen.
**Umgesetzt:** Der Import soll in `app_state` den Wert `import.last_export_seen_at` setzen, sobald ein Export eingeht, auch einer mit unveränderten Daten. Überwachung und `/health` werten diesen Zeitpunkt aus.

### B6. „Begrüßungs-E-Mail erneut senden“
Laut Projektbeschreibung geht das „in der Nutzerübersicht mit einem Klick“, laut technischer Doku in der Detailansicht.
**Umgesetzt:** in der Detailansicht. Die Übersicht verlinkt dorthin, ein Klick mehr. Die Projektbeschreibung ist angepasst (09.11.2026).

### B7. Schutz der Kalender: CalDAV-Rechte
Auf der Ebene einzelner Kalender meldet der Server nur Leserechte. Das ist korrekt, iOS und DAVx⁵ zeigen die Kalender als schreibgeschützt.
Für die **Kalender-Sammlung** des Benutzers meldet sabre/dav standardmäßig Schreibrechte. Ein „Kalender anlegen“ wird trotzdem mit 403 abgelehnt.
Das muss beim Gerätetest auf iOS beobachtet werden: Bietet iOS „Kalender hinzufügen“ für diesen Account an, wäre eine eigene ACL für die Sammlung nötig.

---

## C. Eigene Entscheidungen bzw. Abweichungen von der Doku

1. **Einmal-Links verbrauchen sich nicht beim Öffnen.** Link-Prüfer in Mailprogrammen (z. B. Outlook „Sichere Links“, Virenscanner) rufen Links vorab auf und würden sie sonst entwerten. Ein GET zeigt daher nur die Seite „Jetzt anmelden“, erst der Klick (POST mit CSRF-Schutz) verbraucht den Link.
2. **Testbetrieb ohne Versand:** Mit `MAIL_DELIVERY=database` setzt `mail:send` die E-Mails auf den Status `suppressed`. Unter Verwaltung → E-Mails sind sie als Text- und HTML-Vorschau sichtbar, wie vereinbart.
3. **Zuordnungen im Admin-Bereich wirken sofort:** Ändert man eine Zuordnung Bereich → Kalender oder die Einstellungen eines Kalenders, werden die Kalenderzuordnungen aller Mitglieder sofort neu berechnet und Hinweis-E-Mails eingereiht. Laut Doku geschähe das erst beim nächsten Import.
4. **Umbenennen eines Kalenders** erzeugt alle Termine des Kalenders neu, weil `CATEGORIES` den Kalendernamen enthält. Die Geräte laden diese Termine dann einmal neu.
5. **Kalender deaktivieren** (`active = 0`): Der Kalender wird nicht mehr ausgeliefert, auch nicht über Abo-Links (404). Die Termine bleiben in der Datenbank.
6. **Datenschutz bei CalDAV:** Der Server liefert immer nur den Principal des angemeldeten Benutzers. Andere Benutzer lassen sich nicht auflisten oder suchen, und fremde Pfade ergeben 404 statt 403. So lässt sich nicht einmal prüfen, ob ein Benutzername existiert.
7. **Passwortprüfung** ignoriert Leerzeichen am Rand und Großschreibung (Eingabe auf dem Smartphone). Das Alphabet besteht nur aus Kleinbuchstaben und Ziffern.
8. **Content-Security-Policy:** zusätzlich `style-src-attr 'unsafe-inline'`, nötig für die Kalenderfarben. Die Farbwerte werden auf `#RRGGBB` geprüft.
9. **Gesperrte Mitglieder** sehen den Sperrhinweis nur bei **richtigem** Passwort. Sonst ließe sich ohne Passwort herausfinden, welche Konten gesperrt sind.
10. **`import:run`** existiert als Platzhalter ohne Wirkung, damit die Zeitpläne schon eingerichtet werden können. Die Bausteine für den Import sind im Kopf von `src/Console/ImportRunCommand.php` aufgeführt.
11. **E-Mail-Prüfung für den Import** (`Kalender\Import\EmailValidator`): Syntax per `filter_var`, Domain mit MX-, A- oder AAAA-Eintrag, Ergebnis je Domain und Lauf zwischengespeichert. Ist eine Domain nicht auflösbar, wird einmal eine Kontrolldomain abgefragt; scheitert auch diese, wird `DnsUnavailable` geworfen und der Import muss abbrechen, statt Adressen fälschlich zu sperren.

---

## D. Nicht geprüft bzw. später zu erledigen

- **MariaDB:** Lokal mit MariaDB 10.11 (Migrationen, Seiten) geprüft; der Testserver läuft mit MariaDB 11.4 (seit 09.11.2026). Die Tests können per `TEST_DB_DSN` gegen `kalender_test` laufen (siehe README).
- **Docker-Image, Caddyfile:** laufen auf dem Testserver (09.11.2026). Beim ersten Bau rutschten Bibliotheken für PHP 8.4 ins Image (Composer im Image `composer:2` mit neuerem PHP); behoben durch `config.platform.php = 8.3.0` in `composer.json` und einen Gesundheitscheck der Container.
- **Bibliotheken:** Packagist war in der Arbeitsumgebung gesperrt. `vendor/` wurde aus den GitHub-Quellen gebaut, getestet mit Slim 4.15, Twig 3.30, sabre/dav 4.7.1, sabre/vobject 4.5, Symfony 7.4 und PHPUnit 11.5. Eine `composer.lock` liegt deshalb nicht bei. Das Dockerfile installiert die Pakete auf dem Server von Packagist.
- **Gerätetests** (iOS, DAVx⁵, Outlook klassisch/neu/Web, Google) – Arbeitspaket 11.
- **Anleitungen mit Screenshots** – derzeit nur Text, wie vereinbart.
- **iOS-Profil unsigniert:** iOS zeigt „Nicht verifiziert“. Signieren wäre z. B. mit dem Zertifikat der Domain möglich.
- **DNS:** SRV-Einträge `_caldavs._tcp` sowie SPF/DKIM/DMARC für die Absenderdomain. `setup-server-kalender-ff.sh` prüft diese Einträge bisher nicht.
- **Import aus MP Feuer** (Quellen, Mapper, `import:run`, Plausibilitätsprüfung) – laut Auftrag nicht enthalten. Die E-Mail-/DNS-Prüfung ist als Baustein vorhanden (C11).

---

## E. Ergänzungen

### E1. Android ohne Zusatz-App: Abo-Links im Google Kalender
**Umgesetzt (09.11.2026):** Die Android-Anleitung beschreibt neben DAVx⁵ als Alternative das Abonnieren der Abo-Links im Google Kalender (calendar.google.com → „Weitere Kalender“ → „Per URL“, danach in der App „Synchronisieren“ einschalten). Startseite und Seite „Smartphone & Tablet“ verweisen darauf. Hinweise in der Anleitung: Google aktualisiert abonnierte Kalender nur alle paar Stunden (nicht einstellbar), jeder Kalender wird einzeln abonniert, nach „Neue Abo-Links“ müssen die Abos ersetzt werden, und der Link wird bei Google gespeichert. Projektbeschreibung und technische Doku sind ergänzt, der Gerätetest um „Android ohne Zusatz-App“ erweitert.

### E2. Terminübersicht als PDF (Jahreskalender)
**Umgesetzt (12.11.2026)**, abgestimmt mit M. Bramer:
- Nur für Mitglieder und nur für ihre eigenen Kalender. Administratoren haben keine Terminansicht.
- Menüpunkt „Terminübersicht“: Startmonat (Vorgabe Januar des laufenden Jahres), Zeitraum 1–12 Monate (Vorgabe 12), eine oder zwei Seiten, Auswahl der Kalender.
- A4 quer, je Monat eine Spalte. Termine als Oval in der Kalenderfarbe mit Startzeit; ganztägig leeres Oval; abgesagt leerer Kreis mit farbigem Rand. Passen nicht alle Ovale, nur farbige Punkte (ggf. „+n“). Keine Titel, Orte oder Beschreibungen; keine Kürzel.
- Wochenenden und Feiertage NRW grau, Schulferien NRW als Streifen. Feiertage werden berechnet; die Ferien stehen als Tabelle aus der Ferienordnung NRW in der Anwendung (bis Sommer 2030, danach ergänzen). Bewegliche Ferientage einzelner Schulen sind nicht enthalten.
- Neue Bibliothek: `setasign/fpdf` (klein, ohne weitere Abhängigkeiten).

### E3. Startseite ohne Abo-Links, neuer Menüpunkt „Kalender abonnieren“
**Umgesetzt (12.11.2026)**, abgestimmt mit M. Bramer:
- Startseite „Meine Kalender“: nur noch Liste der Kalender mit Farbe, Beschreibung und Anzahl der Termine im laufenden Kalenderjahr (ohne abgesagte, ganzes Jahr). Hinweis mit Knöpfen „Kalender abonnieren“ und „Terminübersicht als PDF“.
- Neuer Menüpunkt **Kalender abonnieren** (`/abonnieren`) mit zwei Unterseiten: **Smartphone & Tablet** (`/abonnieren/geraete`, CalDAV, inkl. „Neues Passwort“) und **Outlook & Google Kalender** (`/abonnieren/outlook`, Abo-Links, inkl. „Neue Abo-Links“). Der bisherige Menüpunkt „Smartphone & Tablet“ entfällt; `/geraete` leitet dauerhaft weiter.
- E-Mail „neuer Kalender“ und Anleitungen verweisen auf die neuen Seiten. Kosten von DAVx⁵ in der Android-Anleitung: Google Play einmalig rund 5 € (ein Kauf für alle Geräte desselben Google-Kontos), F-Droid kostenlos.
- Anleitung für Mitglieder (`anleitung_mitglieder_kalender_ff_krz.tex`): neue Screenshots, eigenes Kapitel „Android ohne Zusatz-App: über den Google Kalender“.

**Nachtrag (12.11.2026):** Menüpunkt „Mitgliedschaften“ entfällt. Die Startseite zeigt bei jedem Kalender die Herkunft („aus: LG Drove“ bzw. „für alle Mitglieder“) und unter der Liste die Mitgliedschaften ohne eigenen Kalender. `/mitgliedschaften` leitet auf die Startseite weiter. Menü jetzt: Meine Kalender · Kalender abonnieren · Terminübersicht · Anleitungen.

**Behoben (12.11.2026):** In der Umgebung `prod` lagen die kompilierten Twig-Vorlagen im dauerhaften Volume `app-storage`. Nach einem Update mit `-U` hätte der Server weiter die **alten** Seiten gezeigt (betraf auch das Update mit der Terminübersicht). Jetzt leert der Container `app` den Vorlagen-Cache bei jedem Start, zusätzlich ist `auto_reload` aktiv.

**Behoben (12.11.2026):** Darstellungsfehler: „Kopieren“-Knöpfe brachen am PC um; nach „Passwort anzeigen“ war die Knopfbeschriftung unsichtbar.

### E4. Abonnieren statt importieren
Ein `.ics`-Link kann abonniert oder importiert werden. Ein Import kopiert die Termine einmalig – Änderungen und Absagen kommen nicht mehr an, ein zweiter Import erzeugt Doppel. Betrifft nur Abo-Links (Outlook, Google Kalender, Android ohne DAVx⁵), nicht CalDAV.
**Umgesetzt (12.11.2026)**, abgestimmt mit M. Bramer:
- **Stufe 1 – Hinweise:** Kasten „Abonnieren – nicht importieren“ auf der Seite „Outlook & Google Kalender“, in den Online-Anleitungen (Outlook, Android) und in der Anleitung für Mitglieder (Kapitel 6 und 7, neue häufige Frage „aus Versehen importiert“). „direkt abonnieren“ (webcal) wird unter Android ausgeblendet.
- **Stufe 2 – Hinweisseite im Browser:** Wird ein Abo-Link im Browser geöffnet (`Accept` enthält `text/html`, aber nicht `text/calendar`), zeigt der Server eine Hinweisseite statt die Datei auszuliefern. Kalenderprogramme erhalten weiterhin die Termine. Eingebaut und getestet, aber **ausgeschaltet** (`FEED_BROWSER_NOTICE=0`); das Setup-Skript legt den Eintrag an und reicht ihn an den Container weiter.

### E5. Git-Repository, Lizenz, Impressum (12.11.2026)

- Quellcode und Dokumentation liegen in einem Git-Repository. Die Stände vom 09.11. sind aus gesicherten Paketen rekonstruiert, die Arbeit vom 09.11. bis 12.11. ist in einem Commit zusammengefasst; ab jetzt wird jeder Schritt einzeln committet. `vendor/` gehört nicht dazu.
- Lizenz: Code AGPL-3.0-or-later, Dokumentation CC BY-SA 4.0, Urheber Michael Bramer. Ausgenommen: `debsupport.sty` (Corporate Design). Alle Bibliotheken stehen unter MIT bzw. BSD-3-Clause.
- Link „Quellcode (AGPL-3.0)“ im Seitenfuß über `SOURCE_URL` (AGPL §13).
- Neue Seite **Impressum** (`/impressum`, ohne Anmeldung): Betreiber nach § 5 DDG aus `BETREIBER_*` in `.env`, darunter „Über diese Software“ mit Urheber, Lizenzen, Quellcode-Link und allen Bibliotheken (aus `vendor/composer/installed.json`). Der Datenschutzhinweis nennt jetzt den Verantwortlichen (dieselben Angaben, dazu `DATENSCHUTZ_KONTAKT`). Fehlen Pflichtangaben, warnen Verwaltung, Impressum (für Admins) und Setup-Skript.

### E6. Umbenennung in „Wehrkalender“ und Aufräumen (12.11.2026)

Die Software heißt jetzt **Wehrkalender** und ist für jede Feuerwehr nutzbar. Die Installation der FF Kreuzau behält ihren Namen über `APP_NAME` (z. B. „Kalender FF KRZ“) und `WEHR_NAME` (z. B. „FF KRZ“). Abgestimmt mit M. Bramer: keine Rücksicht auf bestehende Installationen, der Server wird neu aufgesetzt.

- **Namen:** Repository und Anwendung `wehrkalender/`, PHP-Namespace `Wehrkalender\`, Paket `wehrkalender/wehrkalender`, ZIP `wehrkalender*.zip`, Skript `setup-server-wehrkalender.sh`. Auf dem Server: `/srv/wehrkalender`, Datenbanken `wehrkalender` und `wehrkalender_test`, Sicherung `wehrkalender-backup`/`wehrkalender-restore` unter `/var/backups/wehrkalender`, systemd-Units `wehrkalender-*`.
- **Neu `WEHR_NAME`:** Name der Wehr in Seiten, E-Mails („Ihre …“, „Kalender der …“) und im iOS-Profil. Vorgabe „Feuerwehr“; das Setup-Skript weist auf einen leeren Wert hin. `APP_NAME` hat die Vorgabe „Wehrkalender“.
- **Behoben:** Die Anwendung übernahm aus den Umgebungsvariablen der Container nur bestimmte Vorsilben (`APP_`, `DB_`, `MAIL_` …). `FEED_BROWSER_NOTICE`, `SOURCE_URL`, `BETREIBER_*` und `DATENSCHUTZ_KONTAKT` wären auf dem Server **nicht angekommen**. Jetzt übernommen, mit Test.
- **Entfernt:** Weiterleitungen `/geraete` und `/mitgliedschaften`; Rückgriff von `APP_DOMAIN` auf `DOMAIN` in der Anwendung (Compose setzt `APP_DOMAIN`); Suche nach `/srv/kalender/.env`; Beispiel `deploy/docker-compose.yml` (maßgeblich ist die Compose-Datei des Setup-Skripts); Migration 002 (in 001 aufgenommen).
- **Umbenannt:** CalDAV-Backends ohne „Ff“-Präfix, Sitzungs-Cookie `wehrkalender_session`, iOS-Profil unter `/abonnieren/geraete/wehrkalender.mobileconfig`, `PRODID` „-//Wehrkalender//Wehrkalender//DE“.
- **Dokumentation:** technische Doku als `doku/technik/implementierung_wehrkalender.tex` (allgemein); Projektbeschreibung, Anleitung für Mitglieder und diese Datei unter `doku/ff-kreuzau/`. Die Testdaten bleiben als Beispielwehr an der FF Kreuzau angelehnt.

### E7. Weitere Quellen und Kalenderauswahl – Konzept (12.11.2026)

**Abgestimmt mit M. Bramer.** Beschrieben in der technischen Doku, Kapitel „Weitere Quellen und Kalenderauswahl“; kurz auch in der Projektbeschreibung unter „Mögliche Erweiterungen“.

**Umgesetzt (12.11.2026), ohne den iCal-Importer:**
- Datenmodell: `sources` (Quelle `mp` legt die Migration an), `calendar_feeds` (Befüllung aus externen Quellen mit Filter), `calendar_sources` mit `grants_access`/`fills`, `events.source_id`/`external_id`/`feed_id`, `calendars.default_enabled`, `user_calendar_choice`, `import_runs.source_id`. UID `<quelle>-<id>@<domain>`.
- Kalenderauswahl vollständig: Schalter auf der Startseite („Meine Kalender“ / „Weitere Kalender“), CalDAV zeigt nur eingeschaltete Kalender, Abo-Links nur für eingeschaltete, ausgeschaltetes Abo liefert eine leere Kalenderdatei, PDF-Vorauswahl, E-Mail „neuer Kalender“ nur bei Vorgabe „an“, Wahl verfällt mit der Freischaltung.
- Verwaltung: Vorgabe an/aus je Kalender, Anzahl eingeschaltet, Zuordnungen mit „freischalten“/„befüllen“, neue Seite „Quellen“ (iCal-Quellen anlegen, ändern, löschen; Befüllungen mit Filter; Zustand), Warnung auf der Statusseite bei fehlerhaften Quellen, ausgeschaltete Kalender in Nutzerübersicht und Detailansicht.
- Filter (`FeedFilter`) mit Prüfung, Beschreibung und Auswertung (für den Importer); Adressprüfung (nur HTTPS, keine internen Adressen).
- Testdaten: Kreis-Feed befüllt TM1 2027 (Filter „TM1“, 2027), Abfallkalender Drove/Kreuzau/Stockheim (für alle, Vorgabe aus), Kreisfeuerwehrverband (Quelle meldet Fehler), gemischter Kalender LG Drove + Dorffest des Ortsvereins, einzelne Mitglieder mit eigener Wahl. 11 neue Tests (66 insgesamt).
- Texte: „Termine werden in MP Feuer bzw. bei der jeweiligen Quelle gepflegt.“

**Ergänzt (12.11.2026), abgestimmt mit M. Bramer:**
- **iCal-Dateien hochladen** als dritte Quellenart: Upload auf der Seite „Quellen“ mit Vorschau (neu/geändert/entfernt je Befüllung) und „Übernehmen“; mehrere Dateien je Quelle (neue Fassung ersetzt nur ihre Termine, Datei des Folgejahres kommt dazu); Speicherung in der Datenbank (in der Sicherung enthalten); nur Admins, max. 2 MB; Warnung, wenn die Dateien bald enden. Option „Kennung aus Datum und Titel bilden“ für Dateien mit wechselnden UIDs. Verarbeitung gemeinsam mit dem iCal-Importer; Umsetzung beginnt mit dem Upload.
- **Stand:** im Termin das Datum der letzten *inhaltlichen* Änderung („Quelle: … · Stand TT.MM.JJJJ“, je Befüllung schaltbar) – bewusst nicht der Sync-Zeitpunkt, sonst wäre jeder Termin bei jedem Abruf geändert. Beim Kalender der Stand der Quelle (letzter Abruf bzw. Upload) in der Kalenderbeschreibung und auf der Startseite, hervorgehoben wenn veraltet. Für MP Feuer nicht (Seitenfuß zeigt den Import).
- Aufwand gesamt jetzt 6,5–8 PT, davon rund 3 PT umgesetzt.

**Umgesetzt (13.11.2026):** gemeinsame Verarbeitung `IcalProcessor` (Lesen, Serien mit Ausnahmen auflösen, Filter je Befüllung, Abgleich neu/geändert/entfernt je Befüllung und Datei, Plausibilität, „Quelle/Stand“ im Termin – Stand bleibt stabil, solange sich der Termin nicht ändert). Datei-Upload mit Vorschau, „Übernehmen“/„Verwerfen“, neue Fassung oder weitere Datei, Entfernen. Feed-Abruf `sources:fetch` alle 15 min (Intervall je Quelle, ETag/Last-Modified, nur HTTPS, interne Netze gesperrt, max. 5 MB), „Jetzt abrufen“, „trotzdem übernehmen“. Stand der Quelle in Kalenderbeschreibung (CalDAV, `X-WR-CALDESC`) und auf der Startseite, orange wenn veraltet. Überwachung: Status-Seite, `/health`, `monitor:check`-E-Mails (Abruffehler, länger kein Abruf, Dateien laufen in 30 Tagen aus). Option „Kennung aus Datum und Titel“. 5 neue Tests (71 insgesamt).

**Test mit dem echten Lehrgangsplan Kreis Düren 2027** (von M. Bramer, `tests/fixtures/kreis-lehrgaenge-2027.ics`): 105 Termine, 35 Lehrgänge, 17 Arten; stabile UIDs, keine Serien. Erkenntnisse: Lehrgang nur im Titel → Filter „Titel beginnt mit ‚Maschinist 2/2027‘“; „enthält ‚Messtrupp‘“ trifft auch „Messtrupp Fortbildung“; Truppführer-Bausteine über mehrere Befüllungen („oder“); die Datei nennt selbst einen Stand → eigener Stand aus. „TM1“ gibt es im Kreisplan nicht (Truppmann auf Gemeindeebene) – Testdaten und Doku nutzen jetzt „Maschinist 2/2027“. Testdaten laufen über die echte Verarbeitung (Kreisdatei als Upload, Abfall als Serien, Verband mit Abruffehler, Konzert des Ortsvereins vom Filter ausgeschlossen).

**Noch offen:** Vorschlag von Lehrgangskalendern aus neuen Lehrgangsschlüsseln aus MP Feuer (z. B. `LEHRGANG-MASCHINIST-2-2027` → Kalender und Befüllung „Titel beginnt mit ‚Maschinist 2/2027‘“) – hängt vom Export ab, Frage an MP Feuer.

- **Drei getrennte Fragen:** *Freischaltung* (wer darf sehen – nur MP Feuer bzw. „für alle“), *Befüllung* (woher kommen die Termine – MP Feuer oder iCal-Feed mit Filter), *Auswahl* (will das Mitglied den Kalender – eigene Wahl, sonst Vorgabe des Admins).
- **Benutzer und Mitgliedschaften** kommen weiterhin nur aus MP Feuer. Weitere Quellen liefern nur Termine (später evtl. Kontakte). Termine verschiedener Quellen überschneiden sich nicht.
- **Mehrere Befüllungen je Kalender** sind im Datenmodell erlaubt (z. B. LG Drove aus MP Feuer plus Dorffest aus einem iCal); empfohlen sind aber eigene Kalender je Quelle (Ausfälle fallen auf, klare Zuständigkeit, getrennt abschaltbar, PDF-Farben). Löschen, Plausibilität und Überwachung gelten je Befüllung.
- **Filter** für Feeds mit vielen Terminen (Kreis liefert einen Feed mit allen Lehrgängen): Titel, Ort, Kategorie, Beschreibung (enthält / beginnt mit / regulärer Ausdruck) plus Zeitraum; Vorschau in der Verwaltung. Trifft ein Filter plötzlich nichts mehr, wird nicht gelöscht, sondern gewarnt. Der Kreis-Feed liegt noch nicht vor.
- **Abfallkalender:** je Ort ein Kalender „für alle Mitglieder“ mit Vorgabe „aus“; jedes Mitglied schaltet seinen Ort selbst ein. MP Feuer muss keine Wohnorte liefern.
- **Kalenderauswahl:** Jeder Kalender ist vom Mitglied abschaltbar (keine Pflichtkalender). Abgeschaltet: nicht auf CalDAV-Geräten, kein Abo-Link auf der Seite, bestehendes Abo erhält eine gültige, *leere* Kalenderdatei (kein 404; derselbe Link liefert nach dem Einschalten wieder Termine), nicht in der PDF-Vorauswahl. Der Admin legt je Kalender die Vorgabe fest; wer nie selbst geschaltet hat, folgt Änderungen der Vorgabe. E-Mail „neuer Kalender“ nur bei Vorgabe „an“.
- **UID-Schema** `<quelle>-<id>@<domain>`, für MP Feuer `mp` (unverändert). Für iCal-Quellen ein Hash der fremden UID. **Vor dem ersten Echtbetrieb festlegen**, ebenso die Trennung von Freischaltung und Befüllung.
- **Aufwand:** etwa 5–6 PT zusätzlich zum MP-Feuer-Import. Reihenfolge: Datenmodell und UID-Schema zusammen mit dem MP-Feuer-Import, dann Kalenderauswahl, dann iCal-Quellen.

### E8. Eine Datei, mehrere Kalender; Einstellungsdatei für das Setup-Skript (13.11.2026)

Abgestimmt mit M. Bramer.

- **Eine Quelle befüllt mehrere Kalender** – war schon so gebaut (je Kalender eine Befüllung mit Filter). Testdaten zeigen es jetzt mit der Kreisdatei: Maschinist 2/2027, Atemschutz 2/2027 und Truppführer 1/2027 (zwei Befüllungen Theorie + Praxis in denselben Kalender = „oder“). Hinweis „mehrere Quellen“ erscheint nur noch, wenn wirklich verschiedene Quellen einen Kalender befüllen.
- **Erneut übernehmen:** Knopf an jeder hochgeladenen Datei; verarbeitet sie mit den aktuellen Befüllungen neu, mit Vorschau, ohne neuen Upload. Name und Hochladedatum bleiben.
- **Vorschau zeigt „Nicht übernommen“:** alle Termine der Datei, die keine Befüllung trifft (z. B. 88 von 105 beim Kreisplan), damit fehlende Filter auffallen. Gruppiert nach Titel ohne Zählung („– Tag 1/4“, „Teil 2“, „(3/5)“ entfallen), alphabetisch mit natürlicher Zahlenfolge, je Zeile Anzahl und Zeitraum, aufklappbar mit Datum und Ort (Kreisplan: 31 Zeilen statt 88).
- Texte: „Neue Befüllung (Feed oder hochgeladene Datei → Kalender)“, Beispiel im Filterfeld „Maschinist 2/2027“.
- **Test mit dem Kreisplan 2026** (von M. Bramer, `tests/fixtures/kreis-lehrgaenge-2026.ics`, 93 Termine, 31 Lehrgänge) als weitere Datei neben 2027: nichts aus 2027 wird entfernt, Befüllungen über beide Jahre funktionieren, „Erneut übernehmen“/„Datei entfernen“ wirken nur auf ihre Datei, keine Warnung „Dateien enden bald“ (zählt die jüngste Datei). **Behoben:** Die Vorschau einer weiteren Datei zeigte „0 Treffer (zuletzt 4)“ und setzte danach „zuletzt 0 Treffer“ bei der Befüllung, obwohl der Kalender Termine aus der anderen Datei hat; jetzt vergleicht die Vorschau nur mit der ersetzten Datei („bisher 12 aus dieser Datei“), und die Trefferzahl zählt alle Dateien. Hinweis: Der Kreis benennt Lehrgänge je Jahr unterschiedlich (2026 „Technische Hilfeleistung Wald“, „ABC 1 Modul 3“; 2027 „TH Wald“, „ABC Modul 3“) – jahresübergreifende Filter knapp halten.
- **Test mit dem Abfallkalender Kreuzau 2026** (von M. Bramer, `tests/fixtures/abfall-kreuzau-2026.ics`): 122 Termine, 92 ganztägige Abfuhren (Rest, Bio, Gelb, Papier, Weihnachtsbaum) und 30 mit Uhrzeit (Schadstoffannahme Horm, Schadstoffmobil). Auf zwei Kalender verteilt (Abfuhren / Schadstoffe) ohne Rest; ganztägige Termine bleiben ganztägig und „frei“ (`TRANSP:TRANSPARENT`, blockieren in Outlook nicht den Tag), Abfuhr am 31.12. endet korrekt am 01.01.; mehrzeilige Beschreibung und Ort mit Kommas bleiben erhalten. **Behoben:** Die Vorschau zeigte Datumsangaben um einen Tag zu früh, wenn ein Termin um Mitternacht beginnt (ganztägige Termine: „01.01.–30.12.2026“ statt „02.01.–31.12.2026“), weil die Anzeige in UTC umrechnete; jetzt immer deutsche Zeit, auch für Feeds mit Zeitangaben in UTC. Hinweis: „alles außer Schadstoffen“ geht derzeit nur per regulärem Ausdruck `^(?!Schadstoff)` – eine Bedingung „enthält nicht“ wäre einfacher (vorgeschlagen).
- **Einstellungsdatei für `setup-server-wehrkalender.sh`** (Standard `wehrkalender.env` neben dem Skript, sonst `-f`): Das Skript liest sie bei jedem Aufruf; Optionen beim Aufruf (`-H -d -T -e -B -u -n -t -E -S`) überschreiben sie und werden gespeichert. Danach genügt z. B. `./setup-server-wehrkalender.sh -U`. Einmalige Aktionen (`-Z -U -X -a -c -y`) werden nicht gespeichert. Werte der Anwendung (`APP_NAME`, `WEHR_NAME`, `MAIL_*`, `MAILER_DSN`, `BETREIBER_*`, `SOURCE_URL`, `FEED_BROWSER_NOTICE`, `BACKUP_*`, `AWS_*`) überträgt das Skript bei jedem Lauf nach `/srv/wehrkalender/.env` – sie müssen nicht mehr auf dem Server von Hand eingetragen werden. Nur die vom Server erzeugten Geheimnisse (`APP_KEY`, `DB_*`, `RESTIC_PASSWORD`) bleiben ausschließlich dort. Fehlt die Datei, wird sie mit allen Schlüsseln (auskommentiert) angelegt; unbekannte Schlüssel brechen ab (Tippfehler). Die Datei gehört nicht ins Repository (`.gitignore`). Zusätzlich verträgt `.env` jetzt Werte mit `|`, `&`, `$` und Anführungszeichen.

---

## F. To-do / später

- **`FEED_BROWSER_NOTICE=1` einschalten**, sobald der Gerätetest bestätigt, dass Outlook (klassisch, neu, Web, Mac) und der Google Kalender ihre Abos weiter mit Terminen füllen (siehe E4).
- **Betreiber für Impressum und Datenschutz klären** (Förderverein, FF oder Stadt?) und `BETREIBER_*` sowie `DATENSCHUTZ_KONTAKT` in der Einstellungsdatei des Setup-Skripts eintragen; Datenschutzhinweis mit der bzw. dem Datenschutzbeauftragten abstimmen (Seite ist noch als Entwurf markiert).
- **`SOURCE_URL`** in der Einstellungsdatei des Setup-Skripts auf die Adresse des Git-Repositorys setzen, sobald es angelegt ist.
- **`composer.lock`** ins Repository aufnehmen, damit jeder Docker-Build dieselben Bibliotheksversionen verwendet.
- **Echte Feed-Adressen** von Kreis (falls es einen Feed gibt) und Gemeinde (Abfall) beschaffen; Lehrgangskalender-Vorschlag mit MP Feuer klären (E7);
- **Anleitung für Mitglieder** um „Kalender ein- und ausschalten“ ergänzen (neue Screenshots der Startseite).
- **Kalenderansicht im Web-Frontend** für Mitglieder: Monats- und Listenansicht mit Titel, Zeit und Ort, nur eigene Kalender, Filter je Kalender. Geschätzt etwa 1 Personentag. Vorgemerkt am 12.11.2026, zunächst nur das PDF (E2).
- **Schulferien NRW ab 2030/31** in `src/Calendar/NrwSchoolHolidays.php` ergänzen, sobald veröffentlicht.
