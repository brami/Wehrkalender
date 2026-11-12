<?php

declare(strict_types=1);

namespace Wehrkalender\Web;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Wehrkalender\Calendar\FeedFilter;
use Wehrkalender\Clock;

/**
 * Verwaltung der Quellen (MP Feuer, externe iCal-Feeds) und der Befüllungen
 * „Quelle + Filter → Kalender“. Abruf und Import der iCal-Feeds folgen mit dem
 * Importer; hier werden Quellen und Befüllungen eingerichtet und ihr Zustand
 * angezeigt.
 */
final class SourcesController extends Controller
{
    public const TYPES = ['mpfeuer' => 'MP Feuer', 'ical' => 'iCal-Feed'];
    private const MIN_INTERVAL = 60;
    private const MAX_INTERVAL = 10080;

    public function index(Request $request, Response $response): Response
    {
        $db = $this->app->db();
        $sources = $db->all(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM events e WHERE e.source_id = s.id) AS events,
                    (SELECT COUNT(*) FROM calendar_feeds f WHERE f.source_id = s.id) AS feeds
               FROM sources s ORDER BY CASE WHEN s.type = \'mpfeuer\' THEN 0 ELSE 1 END, s.name'
        );
        $feeds = $db->all(
            'SELECT f.*, c.display_name, c.color, c.slug, s.name AS source_name, s.source_key,
                    (SELECT COUNT(*) FROM events e WHERE e.feed_id = f.id) AS events
               FROM calendar_feeds f JOIN calendars c ON c.id = f.calendar_id JOIN sources s ON s.id = f.source_id
              ORDER BY s.name, c.display_name'
        );
        foreach ($feeds as &$f) {
            $f['filter_text'] = FeedFilter::fromJson($f['filter'])->describe();
            $f['filter_data'] = $f['filter'] ? json_decode((string) $f['filter'], true) : null;
        }
        unset($f);
        foreach ($sources as &$s) {
            $s['state'] = self::state($s, $this->app->clock());
        }
        unset($s);
        $mpFills = $db->all(
            'SELECT cs.mp_key, c.display_name, c.color FROM calendar_sources cs JOIN calendars c ON c.id = cs.calendar_id
              WHERE cs.fills = 1 ORDER BY c.display_name, cs.mp_key'
        );
        return $this->render($response, 'admin/sources.twig', [
            'sources' => $sources,
            'feeds' => $feeds,
            'mp_fills' => $mpFills,
            'calendars' => $db->all('SELECT id, display_name FROM calendars ORDER BY display_name'),
            'ical_sources' => array_values(array_filter($sources, fn ($s) => $s['type'] === 'ical')),
            'fields' => FeedFilter::FIELDS,
            'ops' => FeedFilter::OPS,
            'types' => self::TYPES,
        ]);
    }

    /** Neue iCal-Quelle (POST /admin/quellen) bzw. bestehende bearbeiten (POST /admin/quellen/{id}) */
    public function save(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        $p = $this->post($request);
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $existing = $id !== null ? $db->one('SELECT * FROM sources WHERE id = ?', [$id]) : null;
        if ($id !== null && ($existing === null || $existing['type'] !== 'ical')) {
            $this->notFound($request);
        }
        $key = strtolower(trim((string) ($p['source_key'] ?? '')));
        $name = trim((string) ($p['name'] ?? ''));
        $url = trim((string) ($p['url'] ?? ''));
        $interval = (int) ($p['interval_minutes'] ?? 360);
        $errors = [];
        if ($id === null) {
            if (!preg_match('/^[a-z][a-z0-9-]{1,38}$/', $key)) {
                $errors[] = 'Schlüssel: 2–39 Zeichen aus a–z, 0–9 und „-“, beginnend mit einem Buchstaben.';
            } elseif ($db->value('SELECT 1 FROM sources WHERE source_key = ?', [$key]) !== null) {
                $errors[] = "Der Schlüssel „{$key}“ ist bereits vergeben.";
            }
        }
        if ($name === '') {
            $errors[] = 'Bitte einen Namen angeben.';
        }
        if ($error = self::urlError($url)) {
            $errors[] = $error;
        }
        if ($interval < self::MIN_INTERVAL || $interval > self::MAX_INTERVAL) {
            $errors[] = 'Abrufintervall: ' . self::MIN_INTERVAL . ' bis ' . self::MAX_INTERVAL . ' Minuten.';
        }
        if ($errors !== []) {
            foreach ($errors as $e) {
                $this->session->flash('error', $e);
            }
            return $this->redirect($response, '/admin/quellen');
        }
        $now = $this->app->clock()->nowDb();
        $data = [
            'name' => $name,
            'url' => $url,
            'interval_minutes' => $interval,
            'active' => !empty($p['active']) ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $db->insert('sources', $data + ['source_key' => $key, 'type' => 'ical', 'created_at' => $now]);
            $this->session->flash('success', "Quelle „{$name}“ angelegt. Legen Sie jetzt fest, welche Kalender sie befüllt.");
        } else {
            if ($existing['url'] !== $url) {
                // Neue Adresse: Zwischenspeicher für bedingte Abrufe verwerfen
                $data += ['http_etag' => null, 'http_last_modified' => null];
            }
            $db->update('sources', $data, ['id' => $id]);
            $this->session->flash('success', "Quelle „{$name}“ gespeichert.");
        }
        return $this->redirect($response, '/admin/quellen');
    }

    /** Quelle löschen: nur iCal-Quellen ohne Termine (sonst deaktivieren) */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        $s = $db->one('SELECT * FROM sources WHERE id = ?', [(int) $args['id']]);
        if ($s === null || $s['type'] !== 'ical') {
            $this->notFound($request);
        }
        if ((int) $db->value('SELECT COUNT(*) FROM events WHERE source_id = ?', [$s['id']]) > 0) {
            $this->session->flash('error', "Die Quelle „{$s['name']}“ hat noch Termine. Entfernen Sie zuerst ihre Befüllungen (das entfernt auch die Termine) oder deaktivieren Sie die Quelle.");
            return $this->redirect($response, '/admin/quellen');
        }
        $db->execute('DELETE FROM sources WHERE id = ?', [$s['id']]);
        $this->session->flash('success', "Quelle „{$s['name']}“ gelöscht.");
        return $this->redirect($response, '/admin/quellen');
    }

    /** Befüllung anlegen (POST /admin/befuellung) bzw. ändern (POST /admin/befuellung/{id}) */
    public function feedSave(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        $p = $this->post($request);
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $existing = $id !== null ? $db->one('SELECT * FROM calendar_feeds WHERE id = ?', [$id]) : null;
        if ($id !== null && $existing === null) {
            $this->notFound($request);
        }
        $sourceId = $existing !== null ? (int) $existing['source_id'] : (int) ($p['source_id'] ?? 0);
        $calendarId = $existing !== null ? (int) $existing['calendar_id'] : (int) ($p['calendar_id'] ?? 0);
        $source = $db->one("SELECT * FROM sources WHERE id = ? AND type = 'ical'", [$sourceId]);
        $calendar = $db->one('SELECT * FROM calendars WHERE id = ?', [$calendarId]);
        if ($source === null || $calendar === null) {
            $this->session->flash('error', 'Bitte eine iCal-Quelle und einen Kalender auswählen.');
            return $this->redirect($response, '/admin/quellen');
        }
        try {
            $filter = FeedFilter::fromForm($p);
        } catch (InvalidArgumentException $e) {
            $this->session->flash('error', 'Filter: ' . $e->getMessage());
            return $this->redirect($response, '/admin/quellen');
        }
        $now = $this->app->clock()->nowDb();
        $data = [
            'filter' => $filter->toJson(),
            'source_note' => !empty($p['source_note']) ? 1 : 0,
            'active' => !empty($p['active']) ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $db->insert('calendar_feeds', $data + ['calendar_id' => $calendarId, 'source_id' => $sourceId, 'created_at' => $now]);
            $mixed = $db->value('SELECT 1 FROM calendar_sources WHERE calendar_id = ? AND fills = 1', [$calendarId]) !== null
                || (int) $db->value('SELECT COUNT(*) FROM calendar_feeds WHERE calendar_id = ?', [$calendarId]) > 1;
            $this->session->flash('success', "„{$source['name']}“ befüllt jetzt den Kalender „{$calendar['display_name']}“ ({$filter->describe()}). Die Termine erscheinen nach dem nächsten Abruf.");
            if ($mixed) {
                $this->session->flash('info', "Der Kalender „{$calendar['display_name']}“ wird damit aus mehreren Quellen befüllt. Fällt eine Quelle aus, fehlt still ein Teil der Termine – eigene Kalender je Quelle sind übersichtlicher.");
            }
        } else {
            $db->update('calendar_feeds', $data, ['id' => $id]);
            $this->session->flash('success', "Befüllung „{$source['name']} → {$calendar['display_name']}“ gespeichert. Ein geänderter Filter wirkt beim nächsten Abruf.");
        }
        return $this->redirect($response, '/admin/quellen');
    }

    /** Befüllung entfernen; ihre Termine verschwinden aus dem Kalender */
    public function feedDelete(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        $f = $db->one(
            'SELECT f.*, s.source_key, s.name AS source_name, c.display_name FROM calendar_feeds f
               JOIN sources s ON s.id = f.source_id JOIN calendars c ON c.id = f.calendar_id WHERE f.id = ?',
            [(int) $args['id']]
        );
        if ($f === null) {
            $this->notFound($request);
        }
        $events = $this->app->events();
        $removed = 0;
        foreach ($db->column('SELECT external_id FROM events WHERE feed_id = ?', [$f['id']]) as $externalId) {
            $removed += $events->delete((string) $externalId, (string) $f['source_key']) ? 1 : 0;
        }
        $db->execute('DELETE FROM calendar_feeds WHERE id = ?', [$f['id']]);
        $this->session->flash('success', "Befüllung „{$f['source_name']} → {$f['display_name']}“ entfernt ({$removed} Termine entfernt).");
        return $this->redirect($response, '/admin/quellen');
    }

    /**
     * Zustand einer Quelle für die Anzeige: ok | warn | error | never | off | mp
     * @param array<string,mixed> $s
     */
    public static function state(array $s, Clock $clock): string
    {
        if ($s['type'] === 'mpfeuer') {
            return 'mp';
        }
        if ((int) $s['active'] !== 1) {
            return 'off';
        }
        if ($s['last_success_at'] === null) {
            return $s['last_error'] !== null ? 'error' : 'never';
        }
        if ($s['last_error'] !== null && $s['last_run_at'] !== null && $s['last_run_at'] > $s['last_success_at']) {
            return 'error';
        }
        // länger als zwei Intervalle (mind. 1 Tag) kein erfolgreicher Abruf
        $limit = $clock->db('-' . max(1440, 2 * (int) $s['interval_minutes']) . ' minutes');
        return $s['last_success_at'] < $limit ? 'warn' : 'ok';
    }

    /**
     * Adresse eines Feeds prüfen: nur HTTPS, kein Benutzer/Passwort in der URL,
     * keine IP-Adressen aus privaten oder internen Netzen. (Die Namensauflösung
     * prüft der Importer beim Abruf erneut.)
     */
    public static function urlError(string $url): ?string
    {
        $parts = parse_url($url);
        if ($url === '' || $parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return 'Adresse: bitte eine vollständige https://-Adresse angeben (webcal:// durch https:// ersetzen).';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Adresse: Benutzername oder Passwort in der Adresse sind nicht erlaubt.';
        }
        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'Adresse: interne oder private IP-Adressen sind nicht erlaubt.';
        }
        if (in_array(strtolower($host), ['localhost', 'db', 'app', 'worker', 'caddy'], true) || str_ends_with(strtolower($host), '.local')) {
            return 'Adresse: interne Rechnernamen sind nicht erlaubt.';
        }
        return null;
    }
}
