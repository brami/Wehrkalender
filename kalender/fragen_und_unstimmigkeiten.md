# Kalenderserver FF KRZ – Fragen und Unstimmigkeiten

Stand: 08.11.2026 · gesammelt bei der Umsetzung der Anwendung (ohne Import aus MP Feuer)

Grundlage: `implementierung_kalender_ff_krz.tex` (+ `_betrieb.tex`), `projektbeschreibung_kalender_ff_krz.tex`, `setup-server.sh`.

Wo eine Entscheidung nötig war, ist eine Lösung umgesetzt und hier als **Umgesetzt** vermerkt. Jede davon lässt sich ändern.

---

## A. Fragen, die vor dem Start geklärt werden sollten

### A1. Kalender „FF KRZ“ für alle Mitglieder
Laut Projektbeschreibung sieht ein Mitglied der LG Drove auch den Kalender **FF KRZ**. Das Datenmodell ordnet Kalender aber nur über `memberships ⋈ calendar_sources` zu. FF KRZ erscheint also nur, wenn MP Feuer jedem Mitglied eine Mitgliedschaft „FF-KRZ“ exportiert.
**Umgesetzt:** zusätzliche Spalte `calendars.all_members`. Ein so markierter Kalender ist für alle aktiven Mitglieder freigeschaltet. Die Einstellung lässt sich im Admin-Bereich unter „Kalender & Zuordnungen“ ändern.
**Frage:** Liefert MP Feuer eine solche Mitgliedschaft für alle, oder bleibt es bei der Markierung?

### A2. Gleicher Schlüssel für Termin-Bereich und Mitgliedschaft?
`calendar_sources` legt zwei Dinge zugleich fest: In welchen Kalender die Termine eines Bereichs kommen und welche Mitglieder den Kalender sehen. Das funktioniert nur, wenn `termine.bereich` und `mitgliedschaften.schluessel` dieselben Schlüssel verwenden.
Beispiel: Betreuer der JF Gr1 sind in MP Feuer eventuell nicht als „JF-GR1“ geführt.
**Umgesetzt:** Mehrere Schlüssel können auf denselben Kalender zeigen (Testdaten: `JF-GR1` und `JF-GR1-BETREUER` → JF Gr1).
**Frage an MP Feuer:** Verwenden beide Exporte dieselben Schlüssel?

### A3. Mehrere Mitglieder mit derselben E-Mail-Adresse
In der Kinder- und Jugendfeuerwehr ist oft bei Geschwistern die Adresse der Eltern hinterlegt. Die Anmeldung mit „E-Mail + Passwort“ ist dann nicht eindeutig.
**Umgesetzt:**
- Bei Anmeldung mit E-Mail wird das Passwort gegen alle Konten mit dieser Adresse geprüft. Da die Passwörter zufällig sind, passt es nur zu einem Konto.
- Bei „Anmeldelink anfordern“ geht je Konto eine eigene E-Mail raus.
- Testfall: Mia und Noah Schulz.

**Frage:** Ist das so gewollt? Und grundsätzlich: Sollen Kinder und Jugendliche überhaupt eigene Zugänge erhalten (Einwilligung der Eltern, Datenschutzbeauftragte)?

### A4. Exportiert MP Feuer auch vergangene Termine?
Die Regel „nicht mehr im Export → aus dem Kalender entfernen“ hat eine Folge: Exportiert MP Feuer nur künftige Termine, verschwinden vergangene Termine von allen Geräten.
**Frage an MP Feuer:** Welcher Zeitraum wird exportiert?
Der Abo-Feed zeigt ohnehin nur die letzten 12 Monate. Für CalDAV ist keine Grenze festgelegt, und auch für die Datenbank gibt es bisher keine Löschfrist für alte Termine.

### A5. Domain vor dem Start endgültig festlegen
Die Termin-UID enthält die Domain (`mp-<id>@<domain>`). Ändert sich die Domain später, ändern sich alle UIDs. Auf den Geräten entstehen dann Doppel, und alle Abo-Links und Geräte-Einrichtungen müssen erneuert werden. Derzeit ist der Platzhalter `kalender.example.de` gesetzt.
**Bitte die endgültige Domain vor dem ersten Echtbetrieb festlegen.**

### A6. Abo-Link in der E-Mail „neuer Kalender“
Laut Projektbeschreibung enthält diese E-Mail den Abo-Link. Der Link ist aber ein Geheimnis, gleichwertig zu einem Passwort für diesen einen Kalender. Laut Doku stehen Passwörter nie in E-Mails.
**Umgesetzt:** Der Link steht in der E-Mail, wie in der Projektbeschreibung vorgesehen.
**Frage:** Soll das so bleiben, oder soll die E-Mail nur auf das Web-Frontend verweisen?

### A7. Hinweis „neuer Kalender“ bei neuen Mitgliedern
Ein neues Mitglied bekommt die Begrüßung. Soll es zusätzlich für jeden Kalender den Hinweis „neuer Kalender“ bekommen?
**Umgesetzt (als Vorgabe für den Import):** nein. Die Begrüßung nennt die Kalender bereits (Parameter `notify` bei `MembershipService::recompute()`).

### A8. Reservierte Benutzernamen und Löschung ausgeschiedener Mitglieder
Zwei Vorgaben der Doku widersprechen sich:
- „Frühere Benutzernamen bleiben reserviert und werden nie neu vergeben.“
- „Ausgeschiedene Mitglieder werden mit allen abhängigen Datensätzen gelöscht (`ON DELETE CASCADE`).“

Durch die Löschung verschwindet auch der Eintrag in `username_history`, und der alte Name wird wieder frei. Ebenso wird der aktuelle Benutzername eines Ausgeschiedenen frei.
**Umgesetzt:** Löschung wie in der Doku (Datenschutz hat Vorrang).
**Frage:** Ist das so gewollt? Eine Alternative wäre eine Liste gesperrter Namen ohne Personenbezug.
Außerdem: **Umgesetzt** ist, dass ein Mitglied **seine eigenen** früheren Namen wieder erhalten kann, z. B. nach einer Scheidung. Sie werden ja nicht an jemand anderen vergeben.

### A9. Anmeldebegrenzung je IP-Adresse
Die Grenze liegt bei 30 Fehlversuchen je IP und Stunde. Im Gerätehaus sind oft alle Mitglieder über **eine** IP-Adresse (WLAN/NAT) im Netz. Ein Einrichtungsabend mit vielen Tippfehlern oder einige Geräte mit altem Passwort (CalDAV wiederholt alle 15 Minuten) können dann die Anmeldung für alle sperren.
**Umgesetzt:** wie in der Doku, und die Begrenzung gilt für Web und CalDAV.
**Frage:** Soll die IP-Grenze höher werden, oder soll CalDAV nur je Benutzer begrenzt werden?

### A10. Empfänger der Warnungen
**Umgesetzt:** Warnungen gehen an `ADMIN_ALERT_EMAILS` aus der `.env` **und** an alle aktiven Administratoren.
**Frage:** Ist das gewünscht?

### A11. Aufbewahrungsfristen, die in der Doku fehlen
**Umgesetzt** in `cleanup:run`:
- versendete E-Mails nach 30 Tagen löschen (sie enthalten Anmelde- und Abo-Links),
- fehlgeschlagene E-Mails nach 90 Tagen,
- abgelaufene Einmal-Links nach 1 Tag,
- Anmeldeversuche nach 1 Tag.

`calendar_changes` wird bewusst **nicht** gekürzt. Sonst bekämen Geräte mit altem Sync-Token unvollständige Änderungslisten. Die Tabelle wächst nur langsam.
**Bitte bestätigen** bzw. mit der/dem Datenschutzbeauftragten abstimmen.

### A12. Wer bekommt Administratorrechte, und wer betreut den Betrieb dauerhaft?
Die Frage ist bereits in der Doku offen. Für den Test sind `michael.bramer` und `dieter.lang` Administratoren.

---

## B. Unstimmigkeiten in den Unterlagen

### B1. `setup-server.sh` überschreibt die Anwendung
Das Skript schreibt `docker-compose.yml`, `caddy/Caddyfile` und `www/index.html` bei **jedem** Lauf neu (nur Caddy-Testseite und MariaDB). Ein erneuter Lauf, etwa für `-B` (externes Backup-Ziel), entfernt also App und Worker aus Compose und Caddy.
**Erledigt (09.11.2026):** Das Skript heißt jetzt `setup-server-kalender-ff.sh` und installiert die Anwendung selbst (ZIP neben dem Skript bzw. `-Z`, Aktualisierung mit `-U`). Ist die Anwendung installiert, lässt die Grundeinrichtung `docker-compose.yml` und `Caddyfile` unverändert. Die Compose-Datei des Skripts reicht nur die nötigen Umgebungsvariablen an die Container weiter (kein `DB_ROOT_PASSWORD`, kein `RESTIC_PASSWORD`) und ist damit maßgeblich; `deploy/docker-compose.yml` im ZIP ist nur noch ein Beispiel.
Die technische Doku nennt an mehreren Stellen noch `setup-server.sh`.

### B2. Namen der Konfigurationswerte
- Die Doku nennt `APP_DOMAIN`, `setup-server.sh` schreibt `DOMAIN`. **Umgesetzt:** Die Anwendung liest beides.
- In der `.env` von `setup-server.sh` fehlen SMTP-Zugang, `MAIL_FROM` und `ADMIN_ALERT_EMAILS`. Diese müssen von Hand ergänzt werden (siehe `.env.example`).

### B3. Netzwerke in Compose
Im Compose von `setup-server.sh` hängt `db` nur am internen Netz `backend`. Das passt. Der Worker braucht aber zusätzlich Internetzugang (SMTP-Relay, später HTTPS-API von MP Feuer).
**Umgesetzt** in `deploy/docker-compose.yml`: eigenes Netz `egress` für den Worker.

### B4. Datenmodell: Ergänzungen gegenüber der Doku
| Tabelle | Ergänzung | Grund |
|---|---|---|
| `events` | `sequence_no`, `created_at` | `SEQUENCE` soll bei jeder Änderung steigen und muss daher gespeichert werden. `CREATED` im iCalendar. (`sequence` ist in MariaDB ein Schlüsselwort.) |
| `calendars` | `all_members`, `created_at`, `updated_at` | siehe A1 |
| `mail_queue` | `next_attempt_at`, `created_at`, Status `suppressed` | Wiederholung mit wachsendem Abstand; Testbetrieb ohne Versand |
| `login_attempts` | `throttle_key` statt `key` | `KEY` ist in MariaDB ein reserviertes Wort |
| `login_tokens` | `created_at` | Nachvollziehbarkeit |
| `import_messages` | `created_at` | Nachvollziehbarkeit |
| `users` | `created_at`, `updated_at` | Nachvollziehbarkeit |
| neu: `app_state` | Schlüssel/Wert | siehe B5 |
| neu: `schema_migrations` | – | für `db:migrate` |

### B5. Warnung „seit 24 Stunden kein neuer Export“ würde falsch auslösen
Ein Import-Lauf wird nur protokolliert, wenn sich die Daten geändert haben (Prüfsumme). Ändert sich in MP Feuer einen Tag lang nichts, etwa am Wochenende, gäbe es keinen Lauf, und `monitor:check` bzw. `/health` würden fälschlich warnen.
**Umgesetzt:** Der Import soll in `app_state` den Wert `import.last_export_seen_at` setzen, sobald ein Export eingeht, auch einer mit unveränderten Daten. Überwachung und `/health` werten diesen Zeitpunkt aus.

### B6. „Begrüßungs-E-Mail erneut senden“
Laut Projektbeschreibung geht das „in der Nutzerübersicht mit einem Klick“, laut technischer Doku in der Detailansicht.
**Umgesetzt:** in der Detailansicht. Die Übersicht verlinkt dorthin, ein Klick mehr.

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

---

## D. Nicht geprüft bzw. später zu erledigen

- **MariaDB:** In der Arbeitsumgebung lief nur SQLite (wie vereinbart). Das SQL ist auf beide ausgelegt (keine `NOW()`-Aufrufe, kein `ON DUPLICATE KEY`, Sperren gekapselt). Der echte Test steht noch aus: Die Tests können per `TEST_DB_DSN` gegen `kalender_test` laufen (siehe README).
- **Docker-Image, Caddyfile und Log-Filter** konnten hier nicht gebaut bzw. gestartet werden. Die Syntax des Log-Filters setzt Caddy 2.8+ voraus.
- **Bibliotheken:** Packagist war in der Arbeitsumgebung gesperrt. `vendor/` wurde aus den GitHub-Quellen gebaut, getestet mit Slim 4.15, Twig 3.30, sabre/dav 4.7.1, sabre/vobject 4.5, Symfony 7.4 und PHPUnit 11.5. Eine `composer.lock` liegt deshalb nicht bei. Das Dockerfile installiert die Pakete auf dem Server von Packagist.
- **Gerätetests** (iOS, DAVx⁵, Outlook klassisch/neu/Web, Google) – Arbeitspaket 11.
- **Anleitungen mit Screenshots** – derzeit nur Text, wie vereinbart.
- **iOS-Profil unsigniert:** iOS zeigt „Nicht verifiziert“. Signieren wäre z. B. mit dem Zertifikat der Domain möglich.
- **DNS:** SRV-Einträge `_caldavs._tcp` sowie SPF/DKIM/DMARC für die Absenderdomain. `setup-server.sh` prüft diese Einträge bisher nicht.
- **Import aus MP Feuer** (Quellen, Mapper, `import:run`, E-Mail-/DNS-Prüfung, Plausibilitätsprüfung) – laut Auftrag nicht enthalten.
