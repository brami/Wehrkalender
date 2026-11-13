<?php

declare(strict_types=1);

namespace Wehrkalender\TestData;

use DateTimeImmutable;
use DateTimeZone;
use Wehrkalender\App;
use Wehrkalender\Clock;

/**
 * Füllt die Datenbank mit Testdaten, die alle Anwendungsfälle abdecken.
 *
 * Die Vorgeschichte (Begrüßung vor 20 Tagen, Namensänderung, entzogener
 * Kalender …) wird nicht durch nachträgliches Ändern von Zeitstempeln
 * nachgestellt, sondern über die normalen Dienste der Anwendung bei
 * zurückgestellter Uhr. So entstehen Tokens, E-Mails, Sync-Änderungen und
 * Feed-Tokens genau so, wie sie im Betrieb entstehen würden.
 */
final class TestDataLoader
{
    private DateTimeImmutable $now;
    /** @var array<string,int> slug => id */
    private array $cal = [];
    /** @var array<string,array<string,mixed>> mp_id => user */
    private array $users = [];
    /** @var list<array{username:string,password:string,status:string,case:string}> */
    private array $logins = [];
    /** @var array<string,string> Beschreibung => URL/Wert */
    private array $links = [];
    /** @var array<string,string> mp_id => letzter Begrüßungslink-Token */
    private array $welcomeTokens = [];
    /** @var callable(string):void */
    private $log;

    /** @var array<string,int> */
    private array $src = [];
    /** @var array<string,int> */
    private array $feedId = [];

    public function __construct(private App $app)
    {
        $this->log = static fn (string $s) => null;
    }

    /**
     * @param array{import?:string,password?:?string} $options import: ok|failed|stale
     * @return array{logins:list<array>,links:array<string,string>,counts:array<string,int>}
     */
    public function load(array $options = [], ?callable $log = null): array
    {
        if ($log !== null) {
            $this->log = $log;
        }
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        mt_srand(4711);

        ($this->log)('Datenbank neu aufbauen …');
        $this->app->migrator()->dropAll();
        $this->app->migrator()->migrate();

        $this->calendars();
        $this->members();
        $this->events();
        $this->importRuns($options['import'] ?? 'ok');
        $this->finishMails();
        $this->usage();
        if (!empty($options['password'])) {
            $this->setAllPasswords((string) $options['password']);
        }
        $this->app->clock()->fix(null);
        $this->collectLogins();

        $db = $this->app->db();
        $counts = [];
        foreach (['users', 'calendars', 'calendar_sources', 'memberships', 'user_calendars', 'events', 'calendar_changes',
                  'feed_tokens', 'login_tokens', 'import_runs', 'import_messages', 'mail_queue', 'username_history'] as $t) {
            $counts[$t] = (int) $db->value("SELECT COUNT(*) FROM $t");
        }
        return ['logins' => $this->logins, 'links' => $this->links, 'counts' => $counts];
    }

    // ================================================================ Uhr

    /** Uhr auf jetzt + $modify stellen (z. B. "-20 days") */
    private function at(string $modify): void
    {
        $this->app->clock()->fix($this->now->modify($modify));
    }

    private function nowDb(string $modify = 'now'): string
    {
        return $this->now->modify($modify)->format(Clock::DB_FORMAT);
    }

    // ================================================================ Kalender

    private function calendars(): void
    {
        ($this->log)('Kalender und Zuordnungen …');
        $db = $this->app->db();
        $defs = [
            // slug, Name, Farbe, Beschreibung, aktiv, für alle[, Vorgabe eingeschaltet]
            ['ff-krz', 'FF KRZ', '#B91C1C', 'Termine der gesamten Wehr, z. B. Jahreshauptversammlung', 1, 1],
            ['lg-drove', 'LG Drove', '#1D4ED8', 'Termine der Löschgruppe Drove', 1, 0],
            ['lg-kreuzau', 'LG Kreuzau', '#0F766E', 'Termine der Löschgruppe Kreuzau', 1, 0],
            ['lg-stockheim', 'LG Stockheim', '#7C3AED', 'Termine der Löschgruppe Stockheim', 1, 0],
            ['gjf', 'GJF', '#EA580C', 'Termine der Gemeindejugendfeuerwehr', 1, 0],
            ['jf-gr1', 'JF Gr1', '#CA8A04', 'Termine der Jugendfeuerwehr-Gruppe 1', 1, 0],
            ['kf', 'KF', '#DB2777', 'Termine der Kinderfeuerwehr', 1, 0],
            ['maschinist-2-2027', 'Maschinist 2/2027', '#059669', 'Kreislehrgang Maschinist (Termine aus dem Lehrgangsplan des Kreises)', 1, 0],
            ['atemschutz', 'Atemschutz', '#475569', 'Übungen und Belastungsübungen der Atemschutzgeräteträger', 1, 0],
            ['tm1-2025', 'TM1 2025', '#9CA3AF', 'Abgeschlossener Lehrgang (deaktiviert)', 0, 0],
            // aus externen Quellen (iCal): Verband für alle, Abfall je Ort für alle mit Vorgabe „aus“
            ['kfv', 'Kreisfeuerwehrverband', '#0E7490', 'Termine des Kreisfeuerwehrverbands', 1, 1],
            ['abfall-drove', 'Abfall Drove', '#78716C', 'Abfuhrtermine der Gemeinde für Drove', 1, 1, 0],
            ['abfall-kreuzau', 'Abfall Kreuzau', '#A8A29E', 'Abfuhrtermine der Gemeinde für Kreuzau', 1, 1, 0],
            ['abfall-stockheim', 'Abfall Stockheim', '#57534E', 'Abfuhrtermine der Gemeinde für Stockheim', 1, 1, 0],
        ];
        $this->at('-400 days');
        foreach ($defs as $def) {
            [$slug, $name, $color, $desc, $active, $all] = $def;
            $this->cal[$slug] = $db->insert('calendars', [
                'slug' => $slug, 'display_name' => $name, 'color' => $color, 'description' => $desc,
                'sync_token' => 1, 'active' => $active, 'all_members' => $all, 'default_enabled' => $def[6] ?? 1,
                'created_at' => $this->app->clock()->nowDb(), 'updated_at' => $this->app->clock()->nowDb(),
            ]);
        }
        $sources = [
            'FF-KRZ' => 'ff-krz', 'LG-DROVE' => 'lg-drove', 'LG-KREUZAU' => 'lg-kreuzau', 'LG-STOCKHEIM' => 'lg-stockheim',
            'GJF' => 'gjf', 'JF-GR1' => 'jf-gr1', 'JF-GR1-BETREUER' => 'jf-gr1', 'KF' => 'kf',
            'LEHRGANG-MASCHINIST-2-2027' => 'maschinist-2-2027', 'AGT' => 'atemschutz', 'LEHRGANG-TM1-2025' => 'tm1-2025',
        ];
        foreach ($sources as $key => $slug) {
            $db->insert('calendar_sources', [
                'mp_key' => $key, 'calendar_id' => $this->cal[$slug],
                // Kreislehrgang: Teilnahme aus MP Feuer schaltet frei, die Termine liefert die Datei des Kreises
                'grants_access' => 1, 'fills' => $key === 'LEHRGANG-MASCHINIST-2-2027' ? 0 : 1,
                'created_at' => $this->app->clock()->nowDb(),
            ]);
        }
        $this->sources();
    }

    /** Externe Quellen (iCal-Feeds und hochgeladene Dateien) und ihre Befüllungen */
    private function sources(): void
    {
        $db = $this->app->db();
        $now = $this->app->clock()->nowDb();
        $defs = [
            // Schlüssel, Art, Name, URL, Intervall (min)
            ['kreis', 'upload', 'Lehrgänge Kreis Düren', null, 1440],
            ['kfv', 'ical', 'Kreisfeuerwehrverband', 'https://kfv.example.org/termine.ics', 720],
            ['abfall-drove', 'ical', 'Abfallkalender Drove', 'https://abfall.example.org/ical/drove.ics', 1440],
            ['abfall-kreuzau', 'ical', 'Abfallkalender Kreuzau', 'https://abfall.example.org/ical/kreuzau.ics', 1440],
            ['abfall-stockheim', 'ical', 'Abfallkalender Stockheim', 'https://abfall.example.org/ical/stockheim.ics', 1440],
            ['ortsverein-drove', 'ical', 'Ortsverein Drove', 'https://ortsverein-drove.example.org/kalender.ics', 1440],
        ];
        foreach ($defs as [$key, $type, $name, $url, $interval]) {
            $this->src[$key] = $db->insert('sources', [
                'source_key' => $key, 'type' => $type, 'name' => $name, 'url' => $url, 'interval_minutes' => $interval, 'active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $feeds = [
            // Quelle, Kalender, Filter, Hinweis „Quelle: …“, „Stand“
            // Der Kreis-Plan nennt schon „Stand …“ in der Beschreibung → hier kein eigener Stand
            ['kreis', 'maschinist-2-2027', '{"conditions":[{"field":"summary","op":"starts","value":"Maschinist 2/2027"}]}', 0, 0],
            ['kfv', 'kfv', null, 0, 1],
            ['abfall-drove', 'abfall-drove', null, 0, 1],
            ['abfall-kreuzau', 'abfall-kreuzau', null, 0, 1],
            ['abfall-stockheim', 'abfall-stockheim', null, 0, 1],
            // gemischter Kalender: LG Drove aus MP Feuer und das Dorffest des Ortsvereins
            ['ortsverein-drove', 'lg-drove', '{"conditions":[{"field":"categories","op":"contains","value":"Feuerwehr"}]}', 1, 1],
        ];
        foreach ($feeds as [$src, $slug, $filter, $note, $stand]) {
            $this->feedId[$src . '>' . $slug] = $db->insert('calendar_feeds', [
                'calendar_id' => $this->cal[$slug], 'source_id' => $this->src[$src], 'filter' => $filter,
                'source_note' => $note, 'show_stand' => $stand, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    // ================================================================ Mitglieder

    private const LABELS = [
        'FF-KRZ' => 'FF KRZ', 'LG-DROVE' => 'LG Drove', 'LG-KREUZAU' => 'LG Kreuzau', 'LG-STOCKHEIM' => 'LG Stockheim',
        'GJF' => 'Gemeindejugendfeuerwehr (GJF)', 'JF-GR1' => 'JF Gruppe 1', 'JF-GR1-BETREUER' => 'JF Gruppe 1 – Betreuer',
        'KF' => 'Kinderfeuerwehr (KF)', 'LEHRGANG-MASCHINIST-2-2027' => 'Maschinist 2/2027 (Kreis)', 'LEHRGANG-TM1-2025' => 'TM1 2025',
        'AGT' => 'Atemschutzgeräteträger', 'FOERDERVEREIN' => 'Förderverein', 'EHRENABTEILUNG' => 'Ehrenabteilung',
        'MUSIKZUG' => 'Musikzug',
    ];

    /** @param list<string> $keys */
    private function ms(array $keys): array
    {
        return array_map(fn ($k) => ['schluessel' => $k, 'bezeichnung' => self::LABELS[$k] ?? $k], $keys);
    }

    /**
     * Mitglied so anlegen, wie es der Import tun würde: Zugang, Mitgliedschaften,
     * Kalender, Abo-Links, Begrüßungs-E-Mail.
     */
    private function create(string $mpId, string $first, string $last, string $email, array $keys, string $when, bool $welcome = true): array
    {
        $this->at($when);
        $user = $this->app->accounts()->createUser($mpId, $first, $last, $email);
        $this->app->memberships()->replaceMemberships((int) $user['id'], $this->ms($keys));
        $this->app->memberships()->recompute((int) $user['id'], false);
        if ($welcome) {
            $this->welcomeTokens[$mpId] = $this->app->accounts()->queueWelcome((int) $user['id']);
        }
        return $this->users[$mpId] = $this->app->accounts()->find((int) $user['id']);
    }

    /** Begrüßungslink aufrufen und anmelden (wie im Browser) */
    private function useWelcome(string $mpId, string $when): void
    {
        $this->at($when);
        $uid = (int) $this->users[$mpId]['id'];
        if ($this->app->accounts()->consumeLoginToken($this->welcomeTokens[$mpId]) === null) {
            throw new \RuntimeException("Begrüßungslink für $mpId nicht verwendbar");
        }
        $this->app->accounts()->recordWebLogin($uid);
    }

    private function webLogin(string $mpId, string $when): void
    {
        $this->at($when);
        $this->app->accounts()->recordWebLogin((int) $this->users[$mpId]['id']);
    }

    private function dav(string $mpId, string $when, string $ua): void
    {
        $this->app->db()->update('users', ['last_dav_at' => $this->nowDb($when), 'last_dav_client' => $ua], ['id' => $this->users[$mpId]['id']]);
    }

    private function feed(string $mpId, string $slug, string $when, string $ua): void
    {
        $this->app->db()->execute(
            'UPDATE feed_tokens SET last_access_at = ?, last_client = ? WHERE user_id = ? AND calendar_id = ? AND revoked_at IS NULL',
            [$this->nowDb($when), $ua, $this->users[$mpId]['id'], $this->cal[$slug]]
        );
    }

    /** Änderung aus MP Feuer übernehmen, wie es der Import täte */
    private function importUpdate(string $mpId, string $when, array $fields, ?array $keys = null, bool $notify = true): void
    {
        $this->at($when);
        $uid = (int) $this->users[$mpId]['id'];
        $fields['updated_at'] = $this->app->clock()->nowDb();
        $this->app->db()->update('users', $fields, ['id' => $uid]);
        if ($keys !== null) {
            $this->app->memberships()->replaceMemberships($uid, $this->ms($keys));
            $this->app->memberships()->recompute($uid, $notify);
        }
        $user = $this->app->accounts()->find($uid);
        if ($this->app->accounts()->needsWelcome($user)) {
            $this->welcomeTokens[$mpId] = $this->app->accounts()->queueWelcome($uid);
        }
        $this->users[$mpId] = $this->app->accounts()->find($uid);
    }

    private const UA_IOS = 'iOS/18.1 (22B83) dataaccessd/1.0';
    private const UA_IPAD = 'iPadOS/17.6 (21G80) dataaccessd/1.0';
    private const UA_MAC = 'macOS/15.1 (24B83) CalendarAgent/1000';
    private const UA_DAVX5 = 'DAVx5/4.4.3-ose (2024/09/18; dav4jvm; okhttp/4.12.0) Android/14';
    private const UA_OUTLOOK = 'Microsoft Office/16.0 (Windows NT 10.0; Microsoft Outlook 16.0.18129; Pro)';
    private const UA_GOOGLE = 'Google-Calendar-Importer';

    private function members(): void
    {
        ($this->log)('Mitglieder und Zugänge …');
        $acc = $this->app->accounts();

        // 1 Administrator, nutzt alles
        $this->create('1001', 'Michael', 'Bramer', 'm.bramer@example.org', ['FF-KRZ', 'LG-DROVE', 'AGT'], '-120 days');
        $this->useWelcome('1001', '-119 days');
        $acc->setAdmin('michael.bramer', true);
        $this->webLogin('1001', '-2 hours');
        $this->dav('1001', '-12 minutes', self::UA_IOS);

        // 2 Beispiel aus der Projektbeschreibung: LG Drove + GJF + JF Gr1 + KF, Umlaut im Namen
        $this->create('1002', 'Jürgen', 'Müller', 'juergen.mueller@example.org', ['LG-DROVE', 'GJF', 'JF-GR1-BETREUER', 'KF'], '-110 days');
        $this->useWelcome('1002', '-108 days');
        $this->dav('1002', '-25 minutes', self::UA_DAVX5);

        // 3 Heirat: Anna Schmidt → Anna Becker, Benutzername bleibt anna.schmidt (veraltet)
        $this->create('1003', 'Anna', 'Schmidt', 'anna.schmidt@example.org', ['LG-KREUZAU'], '-200 days');
        $this->useWelcome('1003', '-199 days');
        $this->importUpdate('1003', '-30 days', ['last_name' => 'Becker', 'email' => 'anna.becker@example.org']);
        $this->useWelcome('1003', '-29 days');
        $this->dav('1003', '-40 minutes', self::UA_IOS);

        // 4/5 Namensgleichheit → max.mustermann und max.mustermann2
        $this->create('1004', 'Max', 'Mustermann', 'max.mustermann@example.org', ['LG-KREUZAU'], '-150 days');
        $this->useWelcome('1004', '-149 days');
        $this->dav('1004', '-2 days', self::UA_DAVX5);
        $this->create('1005', 'Max', 'Mustermann', 'max.mustermann.jun@example.org', ['JF-GR1'], '-90 days');
        $this->useWelcome('1005', '-89 days');
        $this->dav('1005', '-3 hours', self::UA_IOS);

        // 6/7 Doppelnamen, Leerzeichen, Bindestriche
        $this->create('1006', 'Karl-Heinz', 'Müller-Lüdenscheidt', 'kh.ml@example.org', ['LG-STOCKHEIM', 'AGT'], '-100 days');
        $this->useWelcome('1006', '-98 days');
        $this->feed('1006', 'lg-stockheim', '-5 hours', self::UA_OUTLOOK);
        $this->create('1007', 'Hans Peter', 'von der Heide', 'hp.vdh@example.org', ['LG-DROVE'], '-100 days');
        $this->useWelcome('1007', '-97 days');
        $this->feed('1007', 'lg-drove', '-2 hours', self::UA_GOOGLE);
        $this->feed('1007', 'ff-krz', '-2 hours', self::UA_GOOGLE);

        // 8 Sonderzeichen per Transliterator, 9 türkische Buchstaben
        $this->create('1008', 'François', 'Đorđević', 'f.dordevic@example.org', ['LG-KREUZAU', 'FOERDERVEREIN'], '-80 days');
        $this->useWelcome('1008', '-80 days');
        $this->create('1009', 'Ömer', 'Yılmaz', 'oemer.yilmaz@example.org', ['LG-DROVE', 'LEHRGANG-MASCHINIST-2-2027'], '-60 days');
        $this->useWelcome('1009', '-59 days');
        $this->dav('1009', '-1 day', self::UA_IPAD);

        // 10 ß und gesperrt: hatte Zugang, Adresse in MP Feuer jetzt ungültig
        $this->create('1010', 'Sabine', 'Weiß', 'sabine.weiss@example.org', ['LG-DROVE', 'KF'], '-180 days');
        $this->useWelcome('1010', '-179 days');
        $this->dav('1010', '-6 days', self::UA_IOS);
        $this->feed('1010', 'kf', '-6 days', self::UA_OUTLOOK);
        $this->importUpdate('1010', '-5 days', ['email' => 'sabine.weiss@gmx', 'status' => 'locked_email']);

        // 11 gesperrt: Domain ohne MX/A-Eintrag
        $this->create('1011', 'Thomas', 'Schröder', 'thomas.schroeder@example.org', ['LG-STOCKHEIM'], '-140 days');
        $this->useWelcome('1011', '-139 days');
        $this->importUpdate('1011', '-2 days', ['email' => 'thomas.schroeder@gmx.dee', 'status' => 'locked_email']);

        // 12 neu, Begrüßung vor 3 Tagen, nie angemeldet (Link noch gültig)
        $this->create('1012', 'Lena', 'Fischer', 'lena.fischer@example.org', ['LG-KREUZAU', 'AGT'], '-3 days');

        // 13 Begrüßung abgelaufen (vor 20 Tagen, Link nie aufgerufen)
        $this->create('1013', 'Paul', 'Wagner', 'paul.wagner@example.org', ['LG-STOCKHEIM'], '-20 days');

        // 14 angemeldet, aber noch kein Gerät und kein Abo
        $this->create('1014', 'Julia', 'Hoffmann', 'julia.hoffmann@example.org', ['GJF', 'JF-GR1-BETREUER'], '-45 days');
        $this->useWelcome('1014', '-44 days');
        $this->webLogin('1014', '-10 days');

        // 15 Lehrgang + Atemschutz, letzter Abruf vor 40 Tagen ("kein Abruf seit 30 Tagen")
        $this->create('1015', 'Tim', 'Krämer', 'tim.kraemer@example.org', ['LG-KREUZAU', 'AGT', 'LEHRGANG-MASCHINIST-2-2027'], '-160 days');
        $this->useWelcome('1015', '-160 days');
        $this->dav('1015', '-40 days', self::UA_DAVX5);
        $this->feed('1015', 'atemschutz', '-41 days', self::UA_OUTLOOK);

        // 16/17 Geschwister in der Kinderfeuerwehr mit gemeinsamer Eltern-Adresse
        $this->create('1016', 'Mia', 'Schulz', 'familie.schulz@example.org', ['KF'], '-70 days');
        $this->useWelcome('1016', '-69 days');
        $this->create('1017', 'Noah', 'Schulz', 'familie.schulz@example.org', ['KF'], '-70 days');
        $this->feed('1016', 'kf', '-8 hours', self::UA_OUTLOOK);

        // 18 Jugendfeuerwehr
        $this->create('1018', 'Leon', 'Braun', 'leon.braun@example.org', ['JF-GR1', 'GJF'], '-75 days');
        $this->useWelcome('1018', '-74 days');
        $this->dav('1018', '-50 minutes', self::UA_DAVX5);

        // 19 nur Bereiche ohne Kalender → sieht nur FF KRZ (für alle)
        $this->create('1019', 'Erika', 'Neumann', 'erika.neumann@example.org', ['EHRENABTEILUNG', 'FOERDERVEREIN'], '-90 days');
        $this->useWelcome('1019', '-85 days');
        $this->feed('1019', 'ff-krz', '-1 day', self::UA_OUTLOOK);

        // 20 Kalender entzogen (LG Stockheim weggefallen) + früher Abo-Links neu erzeugt
        $this->create('1020', 'Felix', 'Zimmermann', 'felix.zimmermann@example.org', ['LG-STOCKHEIM', 'LG-KREUZAU'], '-130 days');
        $this->useWelcome('1020', '-130 days');
        $oldToken = (string) $this->app->db()->value('SELECT token FROM feed_tokens WHERE user_id = ? AND calendar_id = ? AND revoked_at IS NULL',
            [$this->users['1020']['id'], $this->cal['lg-kreuzau']]);
        $this->at('-60 days');
        $acc->regenerateFeedTokens((int) $this->users['1020']['id']);
        $this->links['Abo-Link widerrufen (Felix, „Neue Abo-Links“) → 404'] = $this->feedUrl($oldToken);
        $stockheim = (string) $this->app->db()->value('SELECT token FROM feed_tokens WHERE user_id = ? AND calendar_id = ? AND revoked_at IS NULL',
            [$this->users['1020']['id'], $this->cal['lg-stockheim']]);
        $this->feed('1020', 'lg-stockheim', '-15 days', self::UA_OUTLOOK);
        $this->importUpdate('1020', '-14 days', [], ['LG-KREUZAU']);
        $this->links['Abo-Link mit entzogener Zuordnung (Felix, LG Stockheim) → 404'] = $this->feedUrl($stockheim);
        $this->feed('1020', 'lg-kreuzau', '-30 minutes', self::UA_OUTLOOK);

        // 21 neuer Kalender seit gestern (Lehrgang hinzugekommen) → Hinweis-E-Mail offen
        $this->create('1021', 'Sophie', 'Klein', 'sophie.klein@example.org', ['LG-DROVE'], '-100 days');
        $this->useWelcome('1021', '-99 days');
        $this->importUpdate('1021', '-1 day', [], ['LG-DROVE', 'LEHRGANG-MASCHINIST-2-2027']);
        $this->dav('1021', '-5 hours', self::UA_IOS);

        // 22 zweiter Administrator; Tippfehler im Namen korrigiert und Benutzername vom Admin umgestellt
        $this->create('1022', 'Dieter', 'Langer', 'dieter.lang@example.org', ['LG-KREUZAU', 'AGT'], '-300 days');
        $this->useWelcome('1022', '-299 days');
        $this->importUpdate('1022', '-200 days', ['last_name' => 'Lang']);
        $this->at('-199 days');
        $acc->changeUsername((int) $this->users['1022']['id'], (int) $this->users['1001']['id']);
        $acc->setAdmin('dieter.lang', true);
        $this->users['1022'] = $acc->find((int) $this->users['1022']['id']);
        $this->dav('1022', '-1 hour', self::UA_MAC);

        // 23 neues Mitglied mit reserviertem Namen → dieter.langer2
        $this->create('1023', 'Dieter', 'Langer', 'd.langer@example.org', ['LG-STOCKHEIM'], '-10 days');

        // 24 neue Anna Schmidt, während Anna Becker noch anna.schmidt heißt → anna.schmidt2
        $this->create('1024', 'Anna', 'Schmidt', 'anna.schmidt.drove@example.org', ['LG-DROVE'], '-8 days');
        $this->useWelcome('1024', '-7 days');

        // 25 Passwort neu erzeugt, Geräte noch nicht umgestellt
        $this->create('1025', 'Lukas', 'Wolf', 'lukas.wolf@example.org', ['LG-DROVE', 'AGT'], '-95 days');
        $this->useWelcome('1025', '-95 days');
        $this->dav('1025', '-3 days', self::UA_IOS);
        $this->at('-1 day');
        $acc->regeneratePassword((int) $this->users['1025']['id']);

        // 26 Begrüßung konnte nicht zugestellt werden (Adresse beim Provider unbekannt)
        $this->create('1026', 'Laura', 'Richter', 'laura.richter@example.org', ['KF', 'GJF'], '-6 days');

        // 27 E-Mail-Adresse geändert → neue Begrüßung an neue Adresse, Zugang bleibt
        $this->create('1027', 'Markus', 'Weber', 'markus.weber@alt.example.org', ['LG-KREUZAU', 'JF-GR1-BETREUER'], '-250 days');
        $this->useWelcome('1027', '-249 days');
        $this->dav('1027', '-2 days', self::UA_DAVX5);
        $this->importUpdate('1027', '-1 day', ['email' => 'markus.weber@example.org']);

        // 28 zwei Marie Koch: veralteter Name, Vorschlag ist belegt → marie.koch2
        $this->create('1028', 'Marie', 'Koch', 'marie.koch@example.org', ['GJF'], '-60 days');
        $this->useWelcome('1028', '-60 days');
        $this->create('1029', 'Marie', 'Vogel', 'marie.vogel@example.org', ['LG-DROVE'], '-220 days');
        $this->useWelcome('1029', '-219 days');
        $this->importUpdate('1029', '-12 days', ['last_name' => 'Koch']);

        // 30 Teilnehmer eines abgeschlossenen (deaktivierten) Lehrgangs
        $this->create('1030', 'Jonas', 'Becker', 'jonas.becker@example.org', ['LG-STOCKHEIM', 'LEHRGANG-TM1-2025'], '-30 days');
        $this->useWelcome('1030', '-29 days');
    }

    private function feedUrl(string $token): string
    {
        return $this->app->config->baseUrl() . '/abo/' . $token . '.ics';
    }

    // ================================================================ Termine

    private function events(): void
    {
        ($this->log)('Termine …');
        $tz = Clock::localTz();
        $today = (new DateTimeImmutable('today', $tz));
        $from = $today->modify('-14 months');
        $to = $today->modify('+7 months');
        $this->at('-400 days');

        $themes = ['Technische Hilfe', 'Brandbekämpfung', 'Wasserförderung über lange Wegstrecke', 'Erste Hilfe', 'Gerätekunde',
            'Fahrzeugkunde HLF 20', 'Absturzsicherung', 'Funkübung', 'Einsatzübung', 'Unfallverhütung'];

        // Wöchentliche bzw. zweiwöchentliche Übungsdienste
        $this->series('lg-drove', 'DRO', 'Thursday', '19:00', 120, 1, $from, $to, fn ($i) => 'Übungsdienst',
            'Gerätehaus Drove, Kirchstraße 5, 52372 Kreuzau', fn ($i) => 'Thema: ' . $themes[$i % count($themes)]);
        $this->series('lg-kreuzau', 'KRZ', 'Monday', '19:30', 120, 2, $from, $to, fn ($i) => 'Übungsdienst',
            'Feuerwehrhaus Kreuzau, Bahnhofstraße 12', fn ($i) => 'Thema: ' . $themes[($i + 3) % count($themes)]);
        $this->series('lg-stockheim', 'STO', 'Wednesday', '19:00', 120, 2, $from, $to, fn ($i) => 'Übungsdienst',
            'Gerätehaus Stockheim', fn ($i) => 'Thema: ' . $themes[($i + 5) % count($themes)]);
        $this->series('jf-gr1', 'JF1', 'Friday', '18:00', 90, 1, $from, $to, fn ($i) => 'Gruppenstunde Jugendfeuerwehr',
            'Feuerwehrhaus Kreuzau', fn ($i) => $i % 4 === 0 ? "Bitte mitbringen:\n- Helm und Handschuhe\n- Getränk\nTreffpunkt: Hof" : null);
        $this->monthly('kf', 'KF', 'first saturday', '10:00', 120, $from, $to, 'Kinderfeuerwehr', 'Gerätehaus Drove');
        $this->monthly('atemschutz', 'AGT', 'second tuesday', '18:30', 150, $from, $to, 'Belastungsübung Atemschutzstrecke',
            'Atemschutzübungsanlage Düren, Am Feuerwehrhaus 1; Halle 2');
        $this->monthly('gjf', 'GJF', 'third tuesday', '19:00', 120, $from, $to, 'Sitzung der Jugendwarte', 'Rathaus Kreuzau, Sitzungssaal');

        // Einzeltermine der Wehr
        $y = (int) $today->format('Y');
        $single = [
            ['ff-krz', 'FF-JHV-' . ($y + 1), 'Jahreshauptversammlung', ($y + 1) . '-03-12 19:00', ($y + 1) . '-03-12 22:00', false, 'Bürgerhaus Kreuzau, Saal', "Tagesordnung folgt per Aushang.\nAnschließend gemütliches Beisammensein."],
            ['ff-krz', 'FF-JHV-' . $y, 'Jahreshauptversammlung', $y . '-03-13 19:00', $y . '-03-13 22:00', false, 'Bürgerhaus Kreuzau, Saal', null],
            ['ff-krz', 'FF-WEIHNACHT-' . $y, 'Weihnachtsfeier der Wehr 🎄', $y . '-12-11 19:00', $y . '-12-11 23:30', false, 'Gerätehaus Drove', 'Anmeldung bis 30.11. bei den Löschgruppenführern'],
            ['ff-krz', 'FF-TDOT-' . ($y + 1), 'Tag der offenen Tür', ($y + 1) . '-05-09', ($y + 1) . '-05-09', true, 'Feuerwehrhaus Kreuzau', null],
            ['ff-krz', 'FF-KFT-' . ($y + 1), 'Kreisfeuerwehrtag', ($y + 1) . '-06-19', ($y + 1) . '-06-20', true, 'Düren', 'Zweitägig, Abfahrt jeweils 8:00 Uhr'],
            ['ff-krz', 'FF-SOMMER-' . ($y - 1), 'Sommerfest', ($y - 1) . '-08-22', ($y - 1) . '-08-23', true, 'Festwiese Kreuzau', 'liegt mehr als 12 Monate zurück → nicht im Abo-Feed, aber per CalDAV sichtbar'],
            // Zeitumstellung: Nacht mit Sommer- bzw. Winterzeitwechsel
            ['ff-krz', 'FF-NACHT-SOMMERZEIT', 'Nachtübung (Zeitumstellung auf Sommerzeit)', ($y + 1) . '-03-27 23:00', ($y + 1) . '-03-28 04:00', false, 'Industriegebiet Kreuzau', 'Dauer 4 Stunden, da die Uhr um 2:00 auf 3:00 springt'],
            ['ff-krz', 'FF-NACHT-WINTERZEIT', 'Nachtübung (Zeitumstellung auf Winterzeit)', $y . '-10-24 23:00', $y . '-10-25 04:00', false, 'Industriegebiet Kreuzau', 'Dauer 6 Stunden, da die Uhr um 3:00 auf 2:00 zurückgestellt wird'],
            ['gjf', 'GJF-ZELT-' . ($y + 1), 'Zeltlager der Gemeindejugendfeuerwehr', ($y + 1) . '-07-09', ($y + 1) . '-07-11', true, 'Jugendzeltplatz Heimbach', "Packliste: siehe Elternbrief\nAbfahrt Freitag 15:00 Uhr"],
            ['gjf', 'GJF-24H-' . ($y + 1), 'Berufsfeuerwehrtag (24 Stunden)', ($y + 1) . '-05-22 10:00', ($y + 1) . '-05-23 10:00', false, 'Feuerwehrhaus Kreuzau', null],
            ['tm1-2025', 'TM1-2025-1', 'TM1 Ausbildungstag 1', ($y - 1) . '-02-07 08:00', ($y - 1) . '-02-07 17:00', false, 'Feuerwehrhaus Kreuzau', null],
            ['tm1-2025', 'TM1-2025-P', 'TM1 Prüfung', ($y - 1) . '-03-07 08:00', ($y - 1) . '-03-07 14:00', false, 'Feuerwehrhaus Kreuzau', null],
            ['lg-drove', 'DRO-GRILL', 'Grillabend der Löschgruppe', $today->modify('+16 days')->format('Y-m-d') . ' 18:00', $today->modify('+16 days')->format('Y-m-d') . ' 22:00', false, 'Gerätehaus Drove', null],
            ['lg-kreuzau', 'KRZ-ENDE-FEHLT', 'Dienstbesprechung (ohne Ende im Export)', $today->modify('+9 days')->format('Y-m-d') . ' 20:00', $today->modify('+9 days')->format('Y-m-d') . ' 20:00', false, null, 'Ende = Beginn: Server setzt 1 Stunde'],
        ];
        foreach ($single as [$slug, $id, $title, $start, $end, $allDay, $loc, $desc]) {
            $this->event($slug, $id, $title, $start, $end, $allDay, $loc, $desc);
        }
        $this->externalEvents($today);

        // Später eingegangene Änderungen (ergeben Sync-Änderungen, SEQUENCE > 0)
        $this->at('-20 days');
        $this->event('ff-krz', 'FF-JHV-' . ($y + 1), 'Jahreshauptversammlung', ($y + 1) . '-03-12 19:30', ($y + 1) . '-03-12 22:30', false,
            'Bürgerhaus Kreuzau, Saal', "Beginn verschoben auf 19:30 Uhr.\nTagesordnung folgt per Aushang.");

        $this->at('-3 days');
        // Absagen
        $nextDrove = $this->firstSeriesAfter('lg-drove', $today);
        if ($nextDrove !== null) {
            $this->cancel($nextDrove);
        }
        $nextKf = $this->firstSeriesAfter('kf', $today->modify('+20 days'));
        if ($nextKf !== null) {
            $this->cancel($nextKf);
        }
        $this->cancel('FF-WEIHNACHT-' . $y);
        // Termin aus dem Export entfernt
        $del = $this->firstSeriesAfter('lg-stockheim', $today->modify('+30 days'));
        if ($del !== null) {
            $this->app->events()->delete($del);
        }
        // Termin in anderen Bereich verschoben (GJF → JF Gr1)
        $this->event('jf-gr1', 'GJF-24H-' . ($y + 1), 'Berufsfeuerwehrtag (24 Stunden)', ($y + 1) . '-05-22 10:00', ($y + 1) . '-05-23 10:00', false,
            'Feuerwehrhaus Kreuzau', 'jetzt Termin der JF Gruppe 1');

        // Ganz aktuelle Änderung: Ort eines Übungsdienstes geändert
        $this->at('-15 minutes');
        $next = $this->firstSeriesAfter('lg-kreuzau', $today);
        if ($next !== null) {
            $row = $this->app->db()->one('SELECT * FROM events WHERE external_id = ?', [$next]);
            $this->app->events()->save([
                'external_id' => $next, 'calendar_id' => (int) $row['calendar_id'], 'summary' => $row['summary'],
                'location' => 'Treffpunkt geändert: Kläranlage Kreuzau', 'description' => $row['description'],
                'start' => Clock::toLocal($row['starts_at']), 'end' => Clock::toLocal($row['ends_at']),
            ]);
        }
    }

    /**
     * Termine aus externen Quellen über die echte Verarbeitung (IcalProcessor):
     * Kreis-Lehrgänge aus der Datei tests/fixtures/kreis-lehrgaenge-2027.ics (hochgeladen),
     * Abfallkalender als Serien (RRULE), Verband mit fehlerhaftem letzten Abruf.
     */
    private function externalEvents(DateTimeImmutable $today): void
    {
        $db = $this->app->db();
        $proc = $this->app->icalProcessor();
        $source = fn (string $key) => $db->one('SELECT * FROM sources WHERE source_key = ?', [$key]);

        // Kreis: Datei hochgeladen und übernommen (vor 3 Tagen, von Michael)
        $this->at('-3 days');
        $ics = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/fixtures/kreis-lehrgaenge-2027.ics');
        $events = $proc->parse($ics);
        $fileId = $db->insert('source_files', [
            'source_id' => $this->src['kreis'], 'name' => 'Lehrgaenge_Kreis_Dueren_2027.ics', 'ics' => $ics, 'sha256' => hash('sha256', $ics),
            'status' => 'active', 'event_count' => count($events),
            'first_start' => Clock::toDb($events[0]['start']), 'last_end' => Clock::toDb(end($events)['end']),
            'uploaded_at' => $this->nowDb('-3 days'), 'uploaded_by' => $this->users['1001']['id'], 'applied_at' => $this->nowDb('-3 days'),
        ]);
        $proc->apply($proc->plan($source('kreis'), $events, $fileId), false, $fileId);
        $this->at('now');

        // Abfall: Rest- und Biotonne im Wechsel (je 14-täglich), Papier alle 4 Wochen – als Serie wie bei den Anbietern
        $start = $today->modify('-8 weeks');
        foreach (['abfall-drove' => 'tuesday', 'abfall-kreuzau' => 'wednesday', 'abfall-stockheim' => 'thursday'] as $key => $weekday) {
            $d = $start->modify("next $weekday");
            $ics = $this->ics($key, [
                ['rest', 'Restmüll', $d, 'FREQ=WEEKLY;INTERVAL=2;COUNT=18', 'Tonne bitte bis 6:30 Uhr bereitstellen.'],
                ['bio', 'Biotonne', $d->modify('+1 week'), 'FREQ=WEEKLY;INTERVAL=2;COUNT=18', 'Tonne bitte bis 6:30 Uhr bereitstellen.'],
                ['papier', 'Papiertonne', $d->modify('+1 week +1 day'), 'FREQ=WEEKLY;INTERVAL=4;COUNT=9', null],
            ]);
            $this->applyFeed($key, $ics, '-5 hours');
        }

        // Kreisfeuerwehrverband: Termine aus einem früheren Abruf, der letzte Abruf ist fehlgeschlagen
        $y = (int) $today->format('Y') + 1;
        $this->applyFeed('kfv', $this->ics('kfv', [
            ['dv', 'Delegiertenversammlung des KFV', new DateTimeImmutable("$y-03-20 19:00", Clock::localTz()), null, null, '22:00', 'Kreishaus Düren'],
            ['ln', 'Leistungsnachweis der Feuerwehren', new DateTimeImmutable("$y-06-06", Clock::localTz()), null, null, null, 'Feuerwehrschule des Kreises'],
            ['kjf', 'Kreisjugendfeuerwehrtag', $today->modify('+45 days'), null, null, null, 'Sportplatz Nideggen'],
        ]), '-4 days');
        $db->update('sources', ['last_run_at' => $this->nowDb('-1 hour'), 'last_error' => 'HTTP 404'], ['id' => $this->src['kfv']]);

        // Ortsverein: nur das Dorffest (Kategorie Feuerwehr) kommt in den Kalender LG Drove, das Konzert nicht
        $fest = $today->modify('+40 days');
        $this->applyFeed('ortsverein-drove', $this->ics('ortsverein-drove', [
            ['fest', 'Dorffest Drove (Brandwache der LG)', $fest, null, 'Die LG stellt die Brandwache, Einteilung folgt.', null, 'Dorfplatz Drove', 'Fest, Feuerwehr', 2],
            ['konzert', 'Konzert des Musikvereins', $fest->modify('+14 days')->setTime(19, 0), null, null, '22:00', 'Bürgerhaus Drove', 'Musik'],
        ]), '-8 hours');
    }

    /**
     * Kleine iCal-Datei bauen. Termine: [id, Titel, Beginn, RRULE, Beschreibung, Ende (Uhrzeit) oder null = ganztägig,
     * Ort, Kategorien, Tage]
     * @param list<array<int,mixed>> $events
     */
    private function ics(string $key, array $events): string
    {
        $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Beispiel//{$key}//DE\r\n";
        foreach ($events as $e) {
            [$id, $title, $start] = $e;
            $rrule = $e[3] ?? null;
            $desc = $e[4] ?? null;
            $endTime = $e[5] ?? null;
            $out .= "BEGIN:VEVENT\r\nUID:{$key}-{$id}@example.org\r\nDTSTAMP:20261101T000000Z\r\nSUMMARY:{$title}\r\n";
            if ($endTime === null && $start->format('H:i') === '00:00') {
                $out .= 'DTSTART;VALUE=DATE:' . $start->format('Ymd') . "\r\nDTEND;VALUE=DATE:" . $start->modify('+' . ($e[8] ?? 1) . ' days')->format('Ymd') . "\r\n";
            } else {
                [$h, $m] = explode(':', $endTime ?? '23:00');
                $out .= 'DTSTART;TZID=Europe/Berlin:' . $start->format('Ymd\THis') . "\r\nDTEND;TZID=Europe/Berlin:" . $start->setTime((int) $h, (int) $m)->format('Ymd\THis') . "\r\n";
            }
            $out .= ($rrule ? "RRULE:{$rrule}\r\n" : '') . ($desc ? "DESCRIPTION:{$desc}\r\n" : '')
                . (isset($e[6]) ? "LOCATION:{$e[6]}\r\n" : '') . (isset($e[7]) ? 'CATEGORIES:' . str_replace(', ', ',', $e[7]) . "\r\n" : '')
                . "END:VEVENT\r\n";
        }
        return $out . "END:VCALENDAR\r\n";
    }

    /** Feed-Inhalt übernehmen, als wäre er vor $ago abgerufen worden */
    private function applyFeed(string $key, string $ics, string $ago): void
    {
        $db = $this->app->db();
        $this->at($ago);
        $proc = $this->app->icalProcessor();
        $source = $db->one('SELECT * FROM sources WHERE source_key = ?', [$key]);
        $proc->apply($proc->plan($source, $proc->parse($ics)));
        $db->update('sources', ['last_run_at' => $this->nowDb($ago), 'last_success_at' => $this->nowDb($ago)], ['id' => $source['id']]);
        $this->at('now');
    }

    private function event(string $slug, string $id, string $title, string $start, string $end, bool $allDay, ?string $loc, ?string $desc,
                           bool $cancelled = false, string $source = 'mp'): void
    {
        $tz = Clock::localTz();
        $this->app->events()->save([
            'source' => $source,
            'feed_id' => $source === 'mp' ? null : $this->feedId[$source . '>' . $slug],
            'external_id' => $id,
            'calendar_id' => $this->cal[$slug],
            'summary' => $title,
            'location' => $loc,
            'description' => $desc,
            'start' => new DateTimeImmutable($start, $tz),
            'end' => new DateTimeImmutable($end, $tz),
            'all_day' => $allDay,
            'cancelled' => $cancelled,
        ]);
    }

    private function cancel(string $mpId): void
    {
        $row = $this->app->db()->one('SELECT * FROM events WHERE external_id = ?', [$mpId]);
        if ($row === null) {
            return;
        }
        $start = Clock::toLocal($row['starts_at']);
        $end = Clock::toLocal($row['ends_at']);
        if ($row['all_day']) {
            $end = $end->modify('-1 day');
        }
        $this->app->events()->save([
            'external_id' => $mpId, 'calendar_id' => (int) $row['calendar_id'], 'summary' => $row['summary'],
            'location' => $row['location'], 'description' => $row['description'],
            'start' => $start, 'end' => $end, 'all_day' => (bool) $row['all_day'], 'cancelled' => true,
        ]);
    }

    private function series(string $slug, string $prefix, string $weekday, string $time, int $minutes, int $everyWeeks,
                            DateTimeImmutable $from, DateTimeImmutable $to, callable $title, ?string $loc, callable $desc): void
    {
        $tz = Clock::localTz();
        $d = $from->modify('next ' . $weekday);
        $i = 0;
        while ($d <= $to) {
            $start = new DateTimeImmutable($d->format('Y-m-d') . ' ' . $time, $tz);
            $this->app->events()->save([
                'external_id' => sprintf('T-%s-%s', $prefix, $start->format('Ymd')),
                'calendar_id' => $this->cal[$slug],
                'summary' => $title($i),
                'location' => $loc,
                'description' => $desc($i),
                'start' => $start,
                'end' => $start->modify("+{$minutes} minutes"),
            ]);
            $d = $d->modify('+' . (7 * $everyWeeks) . ' days');
            $i++;
        }
    }

    private function monthly(string $slug, string $prefix, string $which, string $time, int $minutes,
                             DateTimeImmutable $from, DateTimeImmutable $to, string $title, string $loc): void
    {
        $tz = Clock::localTz();
        $m = $from->modify('first day of this month');
        while ($m <= $to) {
            $day = $m->modify($which . ' of this month');
            $start = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $time, $tz);
            if ($start >= $from && $start <= $to) {
                $this->app->events()->save([
                    'external_id' => sprintf('T-%s-%s', $prefix, $start->format('Ymd')),
                    'calendar_id' => $this->cal[$slug],
                    'summary' => $title,
                    'location' => $loc,
                    'start' => $start,
                    'end' => $start->modify("+{$minutes} minutes"),
                ]);
            }
            $m = $m->modify('first day of next month');
        }
    }

    private function firstSeriesAfter(string $slug, DateTimeImmutable $after): ?string
    {
        $v = $this->app->db()->value(
            "SELECT external_id FROM events WHERE calendar_id = ? AND starts_at > ? AND external_id LIKE 'T-%' AND status = 'CONFIRMED' ORDER BY starts_at LIMIT 1",
            [$this->cal[$slug], Clock::toDb($after)]
        );
        return $v === null ? null : (string) $v;
    }

    // ================================================================ Importprotokolle

    private function importRuns(string $scenario): void
    {
        ($this->log)('Importprotokolle …');
        $db = $this->app->db();
        $status = $this->app->status();
        $memberCount = count($this->users) + 3; // 3 ohne gültige Adresse
        $eventCount = (int) $db->value('SELECT COUNT(*) FROM events') + 3; // 3 ohne Kalender
        $base = ['members_total' => $memberCount, 'users_created' => 0, 'users_updated' => 0, 'users_deleted' => 0,
            'users_locked' => 0, 'users_unlocked' => 0, 'users_skipped_email' => 3, 'emails_changed' => 0,
            'memberships_changed' => 0, 'events_total' => $eventCount, 'events_added' => 0, 'events_modified' => 0,
            'events_deleted' => 0, 'events_unmapped' => 3, 'mails_queued' => 0];
        $runs = [];
        // regelmäßige Läufe der letzten Tage (nur bei neuen Daten)
        for ($h = 5 * 24; $h > 1; $h -= mt_rand(3, 9)) {
            $runs[] = [$h * 60, 'success', ['events_modified' => mt_rand(0, 4), 'events_added' => mt_rand(0, 2)], []];
        }
        $runs[] = [50 * 60, 'failed', ['error' => 'Schemaprüfung fehlgeschlagen'],
            [['error', 'schema_invalid', null, 'termine.json: Pflichtfeld „beginn“ fehlt bei Termin T-98812 (Zeile 1873). Vorhandene Daten bleiben unverändert.']]];
        $runs[] = [49 * 60, 'aborted', ['error' => 'Plausibilitätsprüfung', 'members_total' => 41],
            [['error', 'plausibility', null, "Export enthält nur 41 von {$memberCount} Mitgliedern des letzten erfolgreichen Imports (unter 80 %). Import abgebrochen; Freigabe nach Prüfung mit „import:run --force“."]]];
        // der jüngste erfolgreiche Lauf mit vollständigem Protokoll
        $last = [10, 'success', [
            'users_created' => 1, 'users_updated' => 4, 'users_deleted' => 1, 'users_locked' => 1, 'emails_changed' => 1,
            'memberships_changed' => 2, 'events_added' => 2, 'events_modified' => 3, 'events_deleted' => 1, 'mails_queued' => 3,
            'events_by_calendar' => ['LG Drove' => ['added' => 1, 'modified' => 1], 'LG Kreuzau' => ['modified' => 1],
                'LG Stockheim' => ['deleted' => 1], 'Maschinist 2/2027' => ['added' => 1], 'FF KRZ' => ['modified' => 1]],
        ], [
            ['info', 'user_created', '1023', 'Neues Mitglied Dieter Langer (dieter.langer2) angelegt; „dieter.langer“ ist reserviert.'],
            ['info', 'email_changed', '1027', 'E-Mail-Adresse geändert: markus.weber@alt.example.org → markus.weber@example.org; Begrüßung an neue Adresse eingereiht.'],
            ['info', 'membership_changed', '1021', 'Mitgliedschaft hinzugekommen: Maschinist 2/2027 (Kreis) → Kalender „Maschinist 2/2027“ freigeschaltet.'],
            ['info', 'membership_changed', '1020', 'Mitgliedschaft entfallen: LG Stockheim → Kalender „LG Stockheim“ entzogen.'],
            ['warning', 'user_locked_email', '1011', 'Zugang gesperrt: Domain „gmx.dee“ hat keinen MX- oder A-Eintrag (thomas.schroeder@gmx.dee).'],
            ['warning', 'user_locked_email', '1010', 'Zugang bleibt gesperrt: „sabine.weiss@gmx“ ist keine gültige E-Mail-Adresse.'],
            ['warning', 'user_skipped_email', null, 'Kevin Ohnemail (MP-ID 2001) nicht übernommen: keine E-Mail-Adresse hinterlegt.'],
            ['warning', 'user_skipped_email', null, 'Petra Tippfehler (MP-ID 2002) nicht übernommen: „petra@@example.org“ ist ungültig.'],
            ['warning', 'user_skipped_email', null, 'Uwe Altdomain (MP-ID 2003) nicht übernommen: Domain „feuerwehr-alt.invalid“ existiert nicht.'],
            ['info', 'user_deleted', null, 'Ausgeschieden: Gerd Alt (MP-ID 999), Zugang und alle Daten gelöscht.'],
            ['warning', 'event_unmapped', null, 'Termin M-501 „Musikzug Probe“ (Bereich MUSIKZUG) ist keinem Kalender zugeordnet.'],
            ['warning', 'event_unmapped', null, 'Termin M-502 „Musikzug Ständchen JHV“ (Bereich MUSIKZUG) ist keinem Kalender zugeordnet.'],
            ['warning', 'event_unmapped', null, 'Termin F-77 „Förderverein Mitgliederversammlung“ (Bereich FOERDERVEREIN) ist keinem Kalender zugeordnet.'],
        ]];
        if ($scenario === 'failed') {
            $runs[] = $last;
            $runs[] = [5, 'failed', ['error' => 'DNS-Ausfall'],
                [['error', 'dns_timeout', null, 'Zeitüberschreitung bei der DNS-Prüfung der E-Mail-Domains. Import abgebrochen, damit keine Adresse fälschlich als ungültig gilt.']]];
        } elseif ($scenario === 'stale') {
            // letzter Export vor 30 Stunden → Warnung durch monitor:check und /health = 503
            $last[0] = 30 * 60;
            $runs = array_values(array_filter($runs, fn ($r) => $r[0] > 30 * 60));
            $runs[] = $last;
        } else {
            $runs[] = $last;
        }
        usort($runs, fn ($a, $b) => $b[0] <=> $a[0]);

        foreach ($runs as [$minutesAgo, $st, $stats, $messages]) {
            $start = $this->now->modify("-{$minutesAgo} minutes");
            $runId = $db->insert('import_runs', [
                'started_at' => Clock::toDb($start),
                'finished_at' => Clock::toDb($start->modify('+' . mt_rand(3, 40) . ' seconds')),
                'status' => $st,
                'stats' => json_encode(array_merge($base, $stats), JSON_UNESCAPED_UNICODE),
                'source_files' => 'sftp:mitglieder.json, sftp:termine.json (SHA-256 ' . substr(hash('sha256', (string) $minutesAgo), 0, 12) . '…)',
            ]);
            foreach ($messages as [$level, $type, $mp, $text]) {
                $db->insert('import_messages', [
                    'run_id' => $runId, 'level' => $level, 'type' => $type,
                    'user_id' => $mp !== null ? (int) $this->users[$mp]['id'] : null,
                    'message' => $text, 'created_at' => Clock::toDb($start),
                ]);
            }
            if ($st === 'aborted') {
                $this->app->clock()->fix($start);
                $status->alertAdmins('Kalenderserver: Import aus MP Feuer abgebrochen', [$messages[0][3]]);
            }
        }
        $lastSeen = $scenario === 'stale' ? '-30 hours' : '-10 minutes';
        $this->at($lastSeen);
        $status->setState('import.last_export_seen_at', $this->nowDb($lastSeen));
    }

    // ================================================================ E-Mails

    private function finishMails(): void
    {
        ($this->log)('Mail-Warteschlange …');
        $db = $this->app->db();
        // Alles, was älter als eine Stunde ist, gilt als verschickt …
        $db->execute("UPDATE mail_queue SET status = 'sent', attempts = 1, sent_at = created_at WHERE created_at < ?", [$this->nowDb('-1 hour')]);
        // … außer: Begrüßung an Laura nicht zustellbar (5 Versuche, endgültig fehlgeschlagen)
        $db->execute(
            "UPDATE mail_queue SET status = 'failed', attempts = 5, sent_at = NULL, next_attempt_at = ?,
                    last_error = 'Expected response code \"250\" but got code \"550\", with message \"550 5.1.1 <laura.richter@example.org>: Recipient address rejected: User unknown in virtual mailbox table\".'
              WHERE user_id = ? AND type = 'welcome'",
            [$this->nowDb('-2 days'), $this->users['1026']['id']]
        );
        // … und: Begrüßung von Dieter Langer (neu) vorübergehend fehlgeschlagen, nächster Versuch in Kürze
        $db->execute(
            "UPDATE mail_queue SET status = 'failed', attempts = 2, sent_at = NULL, next_attempt_at = ?,
                    last_error = 'Connection could not be established with host \"smtp.example.de:587\": stream_socket_client(): Unable to connect (Connection timed out)'
              WHERE user_id = ? AND type = 'welcome'",
            [$this->nowDb('+10 minutes'), $this->users['1023']['id']]
        );
        // Begrüßung an Markus' neue Adresse und Hinweis an Sophie bleiben offen (queued)
        $db->execute("UPDATE mail_queue SET status = 'queued', attempts = 0, sent_at = NULL WHERE user_id IN (?, ?) AND created_at >= ?",
            [$this->users['1027']['id'], $this->users['1021']['id'], $this->nowDb('-2 days')]);
    }

    private function usage(): void
    {
        // Ein Admin-Login mit Link, der noch gültig ist (Anforderung vor 5 Minuten)
        $this->at('-5 minutes');
        $token = $this->app->accounts()->queueLoginLink((int) $this->users['1001']['id']);
        $this->links['Anmeldelink (angefordert, 30 min gültig) – michael.bramer'] = $this->app->config->baseUrl() . '/login/link/' . $token;
        $this->app->db()->execute("UPDATE mail_queue SET status = 'queued' WHERE user_id = ? AND type = 'login_link'", [$this->users['1001']['id']]);

        $this->links['Begrüßungslink (gültig, nie aufgerufen) – lena.fischer'] = $this->app->config->baseUrl() . '/login/link/' . $this->welcomeTokens['1012'];
        $this->links['Begrüßungslink (abgelaufen) – paul.wagner'] = $this->app->config->baseUrl() . '/login/link/' . $this->welcomeTokens['1013'];
        $this->links['Begrüßungslink (bereits verwendet) – juergen.mueller'] = $this->app->config->baseUrl() . '/login/link/' . $this->welcomeTokens['1002'];

        // A9: gesperrte Anmeldungen (Smartphone mit altem Passwort, Angriff von einer IP)
        $throttle = $this->app->throttle();
        $this->at('-25 minutes');
        for ($i = 0; $i < 12; $i++) {
            $throttle->hit('dav:lukas.wolf', 'lukas.wolf');
        }
        $this->at('-10 minutes');
        $ip = $throttle->ipKey('198.51.100.23');
        for ($i = 0; $i < 35; $i++) {
            $throttle->hit($ip, '198.51.100.x');
        }

        // gültiger Abo-Link und Abo-Link eines gesperrten Mitglieds
        $db = $this->app->db();
        $t = $db->value('SELECT token FROM feed_tokens WHERE user_id = ? AND calendar_id = ? AND revoked_at IS NULL',
            [$this->users['1002']['id'], $this->cal['lg-drove']]);
        $this->links['Abo-Link gültig (Jürgen, LG Drove)'] = $this->feedUrl((string) $t);
        $t = $db->value('SELECT token FROM feed_tokens WHERE user_id = ? AND calendar_id = ? AND revoked_at IS NULL',
            [$this->users['1010']['id'], $this->cal['kf']]);
        $this->links['Abo-Link eines gesperrten Mitglieds (Sabine, KF) → 404'] = $this->feedUrl((string) $t);
        $t = $db->value('SELECT token FROM feed_tokens WHERE user_id = ? AND calendar_id = ? AND revoked_at IS NULL',
            [$this->users['1001']['id'], $this->cal['ff-krz']]);
        $this->links['Abo-Link gültig (Michael, FF KRZ: Zeitumstellung, ganztägige und abgesagte Termine)'] = $this->feedUrl((string) $t);

        // Kalenderauswahl: eingeschaltete Abfallkalender, ausgeschaltete Kalender
        $choice = $this->app->calendarChoice();
        $this->at('-12 days');
        $choice->set((int) $this->users['1001']['id'], $this->cal['abfall-drove'], true);
        $choice->set((int) $this->users['1002']['id'], $this->cal['abfall-drove'], true);
        $choice->set((int) $this->users['1002']['id'], $this->cal['kfv'], false);
        $choice->set((int) $this->users['1003']['id'], $this->cal['abfall-kreuzau'], true);
        $choice->set((int) $this->users['1015']['id'], $this->cal['abfall-kreuzau'], true);
        $choice->set((int) $this->users['1015']['id'], $this->cal['abfall-stockheim'], true);
        $choice->set((int) $this->users['1019']['id'], $this->cal['ff-krz'], false);
        $t = $db->value('SELECT token FROM feed_tokens WHERE user_id = ? AND calendar_id = ? AND revoked_at IS NULL',
            [$this->users['1019']['id'], $this->cal['ff-krz']]);
        $this->links['Abo-Link eines ausgeschalteten Kalenders (Mitglied 1019, FF KRZ) → leere Kalenderdatei'] = $this->feedUrl((string) $t);
    }

    private function setAllPasswords(string $password): void
    {
        $enc = $this->app->passwords();
        foreach ($this->app->db()->column('SELECT id FROM users') as $id) {
            $this->app->db()->update('users', ['password_enc' => $enc->encrypt(strtolower($password))], ['id' => $id]);
        }
    }

    private function collectLogins(): void
    {
        $cases = [
            '1001' => 'Administrator, nutzt iPhone', '1002' => 'Beispiel Projektbeschreibung (5 Kalender + für alle), Abfall Drove ein, Verband aus, Umlaut, DAVx⁵',
            '1003' => 'Heirat: veralteter Benutzername', '1004' => 'Namensgleichheit (1)', '1005' => 'Namensgleichheit → Ziffer 2',
            '1006' => 'Doppelname mit Bindestrich, Outlook', '1007' => 'Namensteile mit Leerzeichen, Google Kalender',
            '1008' => 'Sonderzeichen (ç, Đ, đ)', '1009' => 'türkische Buchstaben, Lehrgang', '1010' => 'gesperrt (Adresse ungültig), ß',
            '1011' => 'gesperrt (Domain ohne MX)', '1012' => 'nie angemeldet, Begrüßung gültig', '1013' => 'Begrüßung abgelaufen',
            '1014' => 'angemeldet, nie abgerufen', '1015' => 'kein Abruf seit 40 Tagen', '1016' => 'Geschwister, gleiche E-Mail (1)',
            '1017' => 'Geschwister, gleiche E-Mail (2), nie angemeldet', '1018' => 'Jugendfeuerwehr', '1019' => 'nur Bereiche ohne Kalender',
            '1020' => 'Kalender entzogen, Abo-Links neu erzeugt', '1021' => 'neuer Kalender seit gestern', '1022' => 'Administrator, Benutzername umgestellt',
            '1023' => 'reservierter Name → Ziffer 2, Mail-Fehler (temporär)', '1024' => 'Name wie alter Benutzername von Anna Becker',
            '1025' => 'Passwort neu, Geräte noch alt → Anmeldesperre Smartphone', '1026' => 'Begrüßung nicht zustellbar', '1027' => 'E-Mail-Adresse geändert',
            '1028' => 'Marie Koch', '1029' => 'veraltet, Vorschlag belegt → marie.koch2', '1030' => 'deaktivierter Lehrgangskalender',
        ];
        foreach ($this->app->db()->all('SELECT * FROM users ORDER BY mp_id') as $u) {
            $this->logins[] = [
                'username' => $u['username'],
                'password' => (string) $this->app->passwords()->decrypt($u['password_enc']),
                'status' => $u['status'] . ($u['is_admin'] ? ', admin' : ''),
                'case' => $cases[$u['mp_id']] ?? '',
            ];
        }
    }
}
