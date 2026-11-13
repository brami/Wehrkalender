<?php

declare(strict_types=1);

namespace Wehrkalender\Web;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Wehrkalender\Calendar\FeedFilter;
use Wehrkalender\Clock;
use Wehrkalender\Import\IcalError;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Verwaltung der Quellen (MP Feuer, externe iCal-Feeds) und der Befüllungen
 * „Quelle + Filter → Kalender“. Abruf und Import der iCal-Feeds folgen mit dem
 * Importer; hier werden Quellen und Befüllungen eingerichtet und ihr Zustand
 * angezeigt.
 */
final class SourcesController extends Controller
{
    public const TYPES = ['mpfeuer' => 'MP Feuer', 'ical' => 'iCal-Feed', 'upload' => 'iCal-Datei'];
    public const UPLOAD_MAX = 2 * 1024 * 1024;
    private const MIN_INTERVAL = 60;
    private const MAX_INTERVAL = 10080;

    public function index(Request $request, Response $response): Response
    {
        $db = $this->app->db();
        $sources = $db->all(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM events e WHERE e.source_id = s.id) AS events,
                    (SELECT COUNT(*) FROM calendar_feeds f WHERE f.source_id = s.id) AS feeds,
                    (SELECT MAX(sf.applied_at) FROM source_files sf WHERE sf.source_id = s.id AND sf.status = \'active\') AS last_file_at
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
            $s['state'] = $this->app->sourceState()->state($s);
            $s['problem'] = $this->app->sourceState()->problem($s);
        }
        unset($s);
        $mpFills = $db->all(
            'SELECT cs.mp_key, c.display_name, c.color FROM calendar_sources cs JOIN calendars c ON c.id = cs.calendar_id
              WHERE cs.fills = 1 ORDER BY c.display_name, cs.mp_key'
        );
        $files = [];
        foreach ($db->all(
            "SELECT f.id, f.source_id, f.name, f.status, f.event_count, f.first_start, f.last_end, f.uploaded_at, f.applied_at, u.first_name AS uploader_first, u.last_name AS uploader_last,
                    (SELECT COUNT(*) FROM events e WHERE e.file_id = f.id) AS events
               FROM source_files f LEFT JOIN users u ON u.id = f.uploaded_by
              WHERE f.status = 'active' ORDER BY f.first_start, f.id"
        ) as $f) {
            $files[(int) $f['source_id']][] = $f;
        }
        return $this->render($response, 'admin/sources.twig', [
            'files' => $files,
            'sources' => $sources,
            'feeds' => $feeds,
            'mp_fills' => $mpFills,
            'calendars' => $db->all('SELECT id, display_name FROM calendars ORDER BY display_name'),
            'ical_sources' => array_values(array_filter($sources, fn ($s) => $s['type'] !== 'mpfeuer')),
            'upload_max_mb' => intdiv(self::UPLOAD_MAX, 1024 * 1024),
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
        if ($id !== null && ($existing === null || $existing['type'] === 'mpfeuer')) {
            $this->notFound($request);
        }
        $type = $existing['type'] ?? (($p['type'] ?? 'ical') === 'upload' ? 'upload' : 'ical');
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
        if ($type === 'ical' && ($error = self::urlError($url))) {
            $errors[] = $error;
        }
        if ($type === 'upload') {
            $url = '';
            $interval = max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $interval));
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
            'url' => $url !== '' ? $url : null,
            'id_from_content' => !empty($p['id_from_content']) ? 1 : 0,
            'interval_minutes' => $interval,
            'active' => !empty($p['active']) ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $db->insert('sources', $data + ['source_key' => $key, 'type' => $type, 'created_at' => $now]);
            $this->session->flash('success', "Quelle „{$name}“ angelegt. Legen Sie jetzt fest, welche Kalender sie befüllt"
                . ($type === 'upload' ? ', und laden Sie dann die Datei hoch.' : '.'));
        } else {
            if (($existing['url'] ?? '') !== $url) {
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
        if ($s === null || $s['type'] === 'mpfeuer') {
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
        $source = $db->one("SELECT * FROM sources WHERE id = ? AND type <> 'mpfeuer'", [$sourceId]);
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
            'show_stand' => !empty($p['show_stand']) ? 1 : 0,
            'active' => !empty($p['active']) ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $db->insert('calendar_feeds', $data + ['calendar_id' => $calendarId, 'source_id' => $sourceId, 'created_at' => $now]);
            $mixed = $db->value('SELECT 1 FROM calendar_sources WHERE calendar_id = ? AND fills = 1', [$calendarId]) !== null
                || (int) $db->value('SELECT COUNT(DISTINCT source_id) FROM calendar_feeds WHERE calendar_id = ?', [$calendarId]) > 1;
            $this->session->flash('success', "„{$source['name']}“ befüllt jetzt den Kalender „{$calendar['display_name']}“ ({$filter->describe()}). "
                . ($source['type'] === 'upload' ? 'Die Termine erscheinen, sobald Sie eine Datei hochladen bzw. bei der vorhandenen Datei „Erneut übernehmen“ wählen.' : 'Die Termine erscheinen nach dem nächsten Abruf („Jetzt abrufen“).'));
            if ($mixed) {
                $this->session->flash('info', "Der Kalender „{$calendar['display_name']}“ wird damit aus mehreren Quellen befüllt. Fällt eine Quelle aus, fehlt still ein Teil der Termine – eigene Kalender je Quelle sind übersichtlicher.");
            }
        } else {
            $db->update('calendar_feeds', $data, ['id' => $id]);
            $this->session->flash('success', "Befüllung „{$source['name']} → {$calendar['display_name']}“ gespeichert. "
                . ($source['type'] === 'upload' ? 'Ein geänderter Filter wirkt mit „Erneut übernehmen“ bei der Datei.' : 'Ein geänderter Filter wirkt beim nächsten Abruf („Jetzt abrufen“).'));
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

    /** iCal-Datei hochladen (POST /admin/quellen/{id}/datei) → Vorschau */
    public function fileUpload(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        $source = $db->one("SELECT * FROM sources WHERE id = ? AND type = 'upload'", [(int) $args['id']]);
        if ($source === null) {
            $this->notFound($request);
        }
        $p = $this->post($request);
        $file = $request->getUploadedFiles()['datei'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $tooBig = $file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            $this->session->flash('error', $tooBig ? 'Die Datei ist zu groß (höchstens ' . intdiv(self::UPLOAD_MAX, 1048576) . ' MB).' : 'Bitte eine iCal-Datei (.ics) auswählen.');
            return $this->redirect($response, '/admin/quellen');
        }
        $ics = (string) $file->getStream();
        $replaces = (int) ($p['replaces_id'] ?? 0) ?: null;
        if ($replaces !== null && $db->value("SELECT 1 FROM source_files WHERE id = ? AND source_id = ? AND status = 'active'", [$replaces, $source['id']]) === null) {
            $replaces = null;
        }
        try {
            $events = $this->app->icalProcessor()->parse($ics, self::UPLOAD_MAX);
        } catch (IcalError $e) {
            $this->session->flash('error', 'Datei „' . $file->getClientFilename() . '“: ' . $e->getMessage());
            return $this->redirect($response, '/admin/quellen');
        }
        $user = $this->currentUser($request);
        // ältere, nicht übernommene Vorschauen dieser Quelle verwerfen
        $db->execute("DELETE FROM source_files WHERE source_id = ? AND status = 'pending'", [$source['id']]);
        $name = trim((string) $file->getClientFilename()) ?: 'kalender.ics';
        $id = $db->insert('source_files', [
            'source_id' => $source['id'],
            'name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 255),
            'ics' => $ics,
            'sha256' => hash('sha256', $ics),
            'status' => 'pending',
            'replaces_id' => $replaces,
            'event_count' => count($events),
            'first_start' => $events === [] ? null : Clock::toDb(min(array_map(fn ($e) => $e['start'], $events))),
            'last_end' => $events === [] ? null : Clock::toDb(max(array_map(fn ($e) => $e['end'], $events))),
            'uploaded_at' => $this->app->clock()->nowDb(),
            'uploaded_by' => (int) $user['id'],
        ]);
        return $this->redirect($response, '/admin/quellen/datei/' . $id);
    }

    /** Vorschau einer hochgeladenen Datei: was ändert sich je Befüllung? */
    public function filePreview(Request $request, Response $response, array $args): Response
    {
        [$file, $source, $plan] = $this->pendingPlan($request, (int) $args['id']);
        $replaced = $file['replaces_id'] !== null ? $this->app->db()->one('SELECT * FROM source_files WHERE id = ?', [$file['replaces_id']]) : null;
        $same = $replaced !== null && $replaced['sha256'] === $file['sha256'];
        foreach ($plan['feeds'] as &$p) {
            $p['filter_text'] = FeedFilter::fromJson($p['feed']['filter'])->describe();
        }
        unset($p);
        return $this->render($response, 'admin/source_file.twig', [
            'file' => $file, 'source' => $source, 'plan' => $plan, 'replaced' => $replaced, 'same_file' => $same,
            'blocked' => array_filter($plan['feeds'], fn ($f) => $f['blocked'] !== null) !== [],
        ]);
    }

    /** Vorschau übernehmen */
    public function fileApply(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        [$file, $source, $plan] = $this->pendingPlan($request, (int) $args['id']);
        $force = !empty($this->post($request)['force']);
        $target = $file['replaces_id'] !== null ? (int) $file['replaces_id'] : (int) $file['id'];
        $stats = $db->transaction(function () use ($db, $file, $plan, $force, $target): array {
            $stats = $this->app->icalProcessor()->apply($plan, $force, $target);
            $now = $this->app->clock()->nowDb();
            $meta = ['name' => $file['name'], 'ics' => $file['ics'], 'sha256' => $file['sha256'], 'event_count' => $file['event_count'],
                     'first_start' => $file['first_start'], 'last_end' => $file['last_end'], 'uploaded_at' => $file['uploaded_at'],
                     'uploaded_by' => $file['uploaded_by'], 'applied_at' => $now, 'status' => 'active', 'replaces_id' => null];
            if ($target !== (int) $file['id']) {
                // dieselbe Datei erneut übernommen: Name und Hochladedatum der bisherigen Fassung behalten
                $old = $db->one('SELECT name, sha256, uploaded_at, uploaded_by FROM source_files WHERE id = ?', [$target]);
                if ($old !== null && $old['sha256'] === $file['sha256']) {
                    $meta['name'] = $old['name'];
                    $meta['uploaded_at'] = $old['uploaded_at'];
                    $meta['uploaded_by'] = $old['uploaded_by'];
                }
                $db->update('source_files', $meta, ['id' => $target]);
                $db->execute('DELETE FROM source_files WHERE id = ?', [$file['id']]);
            } else {
                $db->update('source_files', $meta, ['id' => $target]);
            }
            $db->update('sources', ['last_run_at' => $now, 'last_success_at' => $now, 'last_error' => null, 'updated_at' => $now], ['id' => $file['source_id']]);
            return $stats;
        });
        $this->session->flash('success', sprintf('„%s“ übernommen: %d neu, %d geändert, %d entfernt, %d unverändert.',
            $file['name'], $stats['added'], $stats['changed'], $stats['removed'], $stats['unchanged']));
        foreach ($stats['blocked'] as $b) {
            $this->session->flash('error', 'Nicht übernommen – ' . $b);
        }
        return $this->redirect($response, '/admin/quellen');
    }

    /**
     * Bereits übernommene Datei erneut verarbeiten, z. B. nach einer neuen oder geänderten
     * Befüllung – ohne sie noch einmal hochzuladen. Zeigt dieselbe Vorschau.
     */
    public function fileReapply(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        $f = $db->one("SELECT * FROM source_files WHERE id = ? AND status = 'active'", [(int) $args['id']]);
        if ($f === null) {
            $this->notFound($request);
        }
        $user = $this->currentUser($request);
        $db->execute("DELETE FROM source_files WHERE source_id = ? AND status = 'pending'", [$f['source_id']]);
        $id = $db->insert('source_files', [
            'source_id' => $f['source_id'], 'name' => $f['name'], 'ics' => $f['ics'], 'sha256' => $f['sha256'],
            'status' => 'pending', 'replaces_id' => $f['id'], 'event_count' => $f['event_count'],
            'first_start' => $f['first_start'], 'last_end' => $f['last_end'],
            'uploaded_at' => $this->app->clock()->nowDb(), 'uploaded_by' => (int) $user['id'],
        ]);
        return $this->redirect($response, '/admin/quellen/datei/' . $id);
    }

    /** Vorschau verwerfen */
    public function fileDiscard(Request $request, Response $response, array $args): Response
    {
        $this->app->db()->execute("DELETE FROM source_files WHERE id = ? AND status = 'pending'", [(int) $args['id']]);
        $this->session->flash('info', 'Hochgeladene Datei verworfen; es wurde nichts geändert.');
        return $this->redirect($response, '/admin/quellen');
    }

    /** Übernommene Datei entfernen; ihre Termine verschwinden */
    public function fileDelete(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        $f = $db->one("SELECT f.*, s.source_key FROM source_files f JOIN sources s ON s.id = f.source_id WHERE f.id = ? AND f.status = 'active'", [(int) $args['id']]);
        if ($f === null) {
            $this->notFound($request);
        }
        $removed = 0;
        foreach ($db->column('SELECT external_id FROM events WHERE file_id = ?', [$f['id']]) as $externalId) {
            $removed += $this->app->events()->delete((string) $externalId, (string) $f['source_key']) ? 1 : 0;
        }
        $db->execute('DELETE FROM source_files WHERE id = ?', [$f['id']]);
        $this->session->flash('success', "Datei „{$f['name']}“ entfernt ({$removed} Termine entfernt).");
        return $this->redirect($response, '/admin/quellen');
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>} */
    private function pendingPlan(Request $request, int $fileId): array
    {
        $db = $this->app->db();
        $file = $db->one("SELECT * FROM source_files WHERE id = ? AND status = 'pending'", [$fileId]);
        $source = $file !== null ? $db->one('SELECT * FROM sources WHERE id = ?', [$file['source_id']]) : null;
        if ($file === null || $source === null) {
            $this->session->flash('info', 'Diese Vorschau gibt es nicht mehr (bereits übernommen oder verworfen).');
            $this->notFound($request);
        }
        $processor = $this->app->icalProcessor();
        $plan = $processor->plan($source, $processor->parse((string) $file['ics'], self::UPLOAD_MAX),
            $file['replaces_id'] !== null ? (int) $file['replaces_id'] : (int) $file['id']);
        return [$file, $source, $plan];
    }

    /** Feed jetzt abrufen (POST /admin/quellen/{id}/abrufen), optional trotz Plausibilitätsproblem übernehmen */
    public function fetchNow(Request $request, Response $response, array $args): Response
    {
        $source = $this->app->db()->one("SELECT * FROM sources WHERE id = ? AND type = 'ical'", [(int) $args['id']]);
        if ($source === null) {
            $this->notFound($request);
        }
        $force = !empty($this->post($request)['force']);
        $result = $this->app->sourceFetcher()->fetch($source, $force);
        $this->session->flash(in_array($result['status'], ['ok', 'unchanged'], true) ? 'success' : 'error', "„{$source['name']}“: " . $result['message']);
        return $this->redirect($response, '/admin/quellen');
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
