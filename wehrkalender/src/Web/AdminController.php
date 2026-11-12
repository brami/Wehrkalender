<?php

declare(strict_types=1);

namespace Wehrkalender\Web;

use Wehrkalender\Calendar\FeedFilter;
use Wehrkalender\Domain\CalendarChoice;
use Wehrkalender\Domain\UsernamePolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/** Status-Seite und Verwaltung für Administratoren */
final class AdminController extends Controller
{
    public const FILTERS = [
        'nie_angemeldet' => 'nie angemeldet',
        'begruessung_offen' => 'Begrüßungslink nie aufgerufen',
        'kein_abruf' => 'kein Abruf seit n Tagen',
        'gesperrt' => 'gesperrt',
    ];
    private const SORTS = ['name', 'username', 'status', 'welcome_sent_at', 'welcome_used_at', 'first_web_login_at',
        'last_web_login_at', 'last_dav_at', 'last_feed_at', 'calendars'];

    public function dashboard(Request $request, Response $response): Response
    {
        $db = $this->app->db();
        $status = $this->app->status();
        $runs = array_map(fn ($r) => $status->decorateRun($r), $db->all('SELECT * FROM import_runs ORDER BY started_at DESC, id DESC LIMIT 50'));

        $outdated = [];
        foreach ($db->all('SELECT * FROM users ORDER BY last_name, first_name') as $u) {
            if (UsernamePolicy::isOutdated($u['username'], $u['first_name'], $u['last_name'])) {
                $u['suggestion'] = $this->app->usernames()->suggestion($u);
                $outdated[] = $u;
            }
        }
        $lastSuccess = $status->lastSuccessfulRun();
        $unmapped = $lastSuccess === null ? [] : $db->all(
            "SELECT message, created_at FROM import_messages WHERE run_id = ? AND type = 'event_unmapped' ORDER BY id",
            [$lastSuccess['id']]
        );
        $areasWithoutCalendar = $db->all(
            'SELECT m.mp_key, MIN(m.label) AS label, COUNT(DISTINCT m.user_id) AS members
               FROM memberships m LEFT JOIN calendar_sources cs ON cs.mp_key = m.mp_key
              WHERE cs.id IS NULL GROUP BY m.mp_key ORDER BY m.mp_key'
        );
        $sourceProblems = [];
        foreach ($db->all("SELECT * FROM sources WHERE type = 'ical' AND active = 1 ORDER BY name") as $src) {
            $state = SourcesController::state($src, $this->app->clock());
            if (in_array($state, ['error', 'warn', 'never'], true)) {
                $sourceProblems[] = $src + ['state' => $state];
            }
        }
        return $this->render($response, 'admin/dashboard.twig', [
            'source_problems' => $sourceProblems,
            'last_run' => $runs[0] ?? null,
            'runs' => $runs,
            'problems' => $status->problems(),
            'locked' => $db->all("SELECT * FROM users WHERE status = 'locked_email' ORDER BY last_name, first_name"),
            'outdated' => $outdated,
            'history' => $db->all(
                'SELECT h.*, u.first_name, u.last_name, c.username AS changed_by_name
                   FROM username_history h JOIN users u ON u.id = h.user_id
                   LEFT JOIN users c ON c.id = h.changed_by
                  ORDER BY h.changed_at DESC LIMIT 100'
            ),
            'unmapped' => $unmapped,
            'areas_without_calendar' => $areasWithoutCalendar,
            'mail_counts' => $this->mailCounts(),
            'mails_failed' => $db->all("SELECT * FROM mail_queue WHERE status = 'failed' ORDER BY created_at DESC LIMIT 50"),
            'mails_open' => $db->all("SELECT * FROM mail_queue WHERE status = 'queued' ORDER BY created_at DESC LIMIT 50"),
            'mail_mode' => $this->app->mailSender()->deliveryMode(),
            'usage' => $status->usage(),
            'blocked' => $this->app->throttle()->blocked(),
        ]);
    }

    public function run(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        $run = $this->app->status()->decorateRun($db->one('SELECT * FROM import_runs WHERE id = ?', [(int) $args['id']]));
        if ($run === null) {
            $this->notFound($request);
        }
        $q = $request->getQueryParams();
        $sql = 'SELECT m.*, u.username, u.first_name, u.last_name FROM import_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.run_id = ?';
        $params = [$run['id']];
        if (!empty($q['typ'])) {
            $sql .= ' AND m.type = ?';
            $params[] = $q['typ'];
        }
        if (!empty($q['stufe'])) {
            $sql .= ' AND m.level = ?';
            $params[] = $q['stufe'];
        }
        return $this->render($response, 'admin/run.twig', [
            'run' => $run,
            'messages' => $db->all($sql . ' ORDER BY m.id', $params),
            'types' => $db->column('SELECT DISTINCT type FROM import_messages WHERE run_id = ? ORDER BY type', [$run['id']]),
            'filter' => ['typ' => $q['typ'] ?? '', 'stufe' => $q['stufe'] ?? ''],
        ]);
    }

    // ------------------------------------------------------------ Nutzer

    public function users(Request $request, Response $response): Response
    {
        $db = $this->app->db();
        $q = $request->getQueryParams();
        $filter = (string) ($q['filter'] ?? '');
        $days = max(1, (int) ($q['tage'] ?? 30));
        $calendarSlug = (string) ($q['kalender'] ?? '');
        $search = trim((string) ($q['q'] ?? ''));
        $sort = in_array($q['sort'] ?? '', self::SORTS, true) ? $q['sort'] : 'name';
        $dir = ($q['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $users = $db->all(
            'SELECT u.*, (SELECT MAX(f.last_access_at) FROM feed_tokens f WHERE f.user_id = u.id) AS last_feed_at FROM users u'
        );
        $calByUser = [];
        foreach ($db->all('SELECT uc.user_id, c.slug, c.display_name, c.color,
                                  CASE WHEN ' . CalendarChoice::enabledSql() . ' THEN 1 ELSE 0 END AS enabled
                             FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id ORDER BY enabled DESC, c.display_name') as $r) {
            $calByUser[$r['user_id']][] = $r;
        }
        $cutoff = $this->app->clock()->db("-{$days} days");
        $needle = mb_strtolower($search);
        $rows = [];
        foreach ($users as $u) {
            $u['calendars'] = $calByUser[$u['id']] ?? [];
            $ok = match ($filter) {
                'nie_angemeldet' => $u['first_web_login_at'] === null,
                'begruessung_offen' => $u['welcome_sent_at'] !== null && $u['welcome_used_at'] === null,
                'kein_abruf' => ($u['last_dav_at'] === null || $u['last_dav_at'] < $cutoff)
                    && ($u['last_feed_at'] === null || $u['last_feed_at'] < $cutoff),
                'gesperrt' => $u['status'] === 'locked_email',
                default => true,
            };
            if ($ok && $calendarSlug !== '') {
                $ok = in_array($calendarSlug, array_column($u['calendars'], 'slug'), true);
            }
            if ($ok && $needle !== '') {
                $hay = mb_strtolower($u['first_name'] . ' ' . $u['last_name'] . ' ' . $u['last_name'] . ', ' . $u['first_name'] . ' ' . $u['username']);
                $ok = str_contains($hay, $needle);
            }
            if ($ok) {
                $rows[] = $u;
            }
        }
        $collator = new \Collator('de_DE');
        usort($rows, function ($a, $b) use ($sort, $dir, $collator) {
            $va = $this->sortValue($a, $sort);
            $vb = $this->sortValue($b, $sort);
            // leere Werte immer ans Ende
            if ($va === null || $va === '') {
                return ($vb === null || $vb === '') ? 0 : 1;
            }
            if ($vb === null || $vb === '') {
                return -1;
            }
            $c = is_int($va) ? $va <=> $vb : (int) $collator->compare((string) $va, (string) $vb);
            return $dir === 'asc' ? $c : -$c;
        });
        return $this->render($response, 'admin/users.twig', [
            'users' => $rows,
            'total' => count($users),
            'filters' => self::FILTERS,
            'calendars' => $db->all('SELECT slug, display_name, color FROM calendars ORDER BY display_name'),
            'q' => ['filter' => $filter, 'tage' => $days, 'kalender' => $calendarSlug, 'q' => $search, 'sort' => $sort, 'dir' => $dir],
            'query' => http_build_query(array_filter($q, fn ($v) => $v !== '' && $v !== null)),
        ]);
    }

    public function user(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        $u = $db->one('SELECT * FROM users WHERE id = ?', [(int) $args['id']]);
        if ($u === null) {
            $this->notFound($request);
        }
        $calendars = $db->all(
            'SELECT c.id, c.display_name, c.color, c.slug, c.default_enabled, uc.since,
                    CASE WHEN ' . CalendarChoice::enabledSql() . ' THEN 1 ELSE 0 END AS enabled,
                    (SELECT ch.enabled FROM user_calendar_choice ch WHERE ch.user_id = uc.user_id AND ch.calendar_id = uc.calendar_id) AS choice,
                    (SELECT MAX(f.last_access_at) FROM feed_tokens f WHERE f.user_id = uc.user_id AND f.calendar_id = c.id) AS last_access_at,
                    (SELECT f.last_client FROM feed_tokens f WHERE f.user_id = uc.user_id AND f.calendar_id = c.id AND f.last_access_at IS NOT NULL ORDER BY f.last_access_at DESC LIMIT 1) AS last_client
               FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id
              WHERE uc.user_id = ? ORDER BY c.display_name',
            [$u['id']]
        );
        $memberships = $db->all(
            'SELECT m.mp_key, m.label, c.display_name AS calendar, c.color FROM memberships m
               LEFT JOIN calendar_sources cs ON cs.mp_key = m.mp_key LEFT JOIN calendars c ON c.id = cs.calendar_id
              WHERE m.user_id = ? ORDER BY m.label',
            [$u['id']]
        );
        $outdated = UsernamePolicy::isOutdated($u['username'], $u['first_name'], $u['last_name']);
        $u['last_feed_at'] = $db->value('SELECT MAX(last_access_at) FROM feed_tokens WHERE user_id = ?', [$u['id']]);
        return $this->render($response, 'admin/user.twig', [
            'u' => $u,
            'calendars' => $calendars,
            'memberships' => $memberships,
            'outdated' => $outdated,
            'suggestion' => $outdated ? $this->app->usernames()->suggestion($u) : null,
            'history' => $db->all('SELECT * FROM username_history WHERE user_id = ? ORDER BY changed_at DESC', [$u['id']]),
            'mails' => $db->all('SELECT id, type, subject, status, created_at, sent_at FROM mail_queue WHERE user_id = ? ORDER BY created_at DESC LIMIT 20', [$u['id']]),
            'query' => $this->backQuery($request),
            'blocked' => array_values(array_filter($this->app->throttle()->blocked(), fn ($b) => $b['user_id'] === (int) $u['id'])),
        ]);
    }

    /** Sperre(n) wegen zu vieler Fehlversuche aufheben (A9) */
    public function unblock(Request $request, Response $response): Response
    {
        $p = $this->post($request);
        $throttle = $this->app->throttle();
        if (!empty($p['alle'])) {
            $n = $throttle->clearAll();
            $this->session->flash('success', "Alle Sperren aufgehoben ({$n} Einträge gelöscht).");
        } else {
            $key = (string) ($p['key'] ?? '');
            $kind = explode(':', $key, 2)[0];
            if (!isset(\Wehrkalender\Security\LoginThrottle::KINDS[$kind])) {
                $this->notFound($request);
            }
            $throttle->clear($key);
            $this->session->flash('success', 'Sperre aufgehoben: ' . \Wehrkalender\Security\LoginThrottle::KINDS[$kind][1]
                . (($p['info'] ?? '') !== '' ? ' (' . $p['info'] . ')' : '') . '.');
        }
        $back = (string) ($p['back'] ?? '/admin');
        return $this->redirect($response, str_starts_with($back, '/admin') ? $back : '/admin');
    }

    public function resendWelcome(Request $request, Response $response, array $args): Response
    {
        $u = $this->app->accounts()->find((int) $args['id']);
        if ($u === null) {
            $this->notFound($request);
        }
        if ($u['status'] !== 'active') {
            $this->session->flash('error', 'Das Mitglied ist gesperrt (ungültige E-Mail-Adresse). Die Adresse muss zuerst in MP Feuer korrigiert werden.');
        } else {
            $this->app->accounts()->queueWelcome((int) $u['id']);
            $this->session->flash('success', "Die Begrüßungs-E-Mail an {$u['email']} wurde eingereiht (neuer Link, 14 Tage gültig).");
        }
        return $this->redirect($response, $this->userUrl((int) $u['id'], $request));
    }

    public function usernameConfirm(Request $request, Response $response, array $args): Response
    {
        $u = $this->app->accounts()->find((int) $args['id']);
        if ($u === null) {
            $this->notFound($request);
        }
        if (!UsernamePolicy::isOutdated($u['username'], $u['first_name'], $u['last_name'])) {
            $this->session->flash('info', 'Der Benutzername passt zum Namen. Eine Umstellung ist nicht möglich.');
            return $this->redirect($response, $this->userUrl((int) $u['id'], $request));
        }
        return $this->render($response, 'admin/username_confirm.twig', [
            'u' => $u,
            'suggestion' => $this->app->usernames()->suggestion($u),
            'query' => $this->backQuery($request),
        ]);
    }

    public function usernameChange(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        try {
            $new = $this->app->accounts()->changeUsername($id, (int) $this->currentUser($request)['id']);
            $this->session->flash('success', "Benutzername umgestellt auf „{$new}“. Das Mitglied wurde per E-Mail informiert.");
        } catch (RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
        }
        return $this->redirect($response, $this->userUrl($id, $request));
    }

    // ------------------------------------------------------------ E-Mails

    public function mails(Request $request, Response $response): Response
    {
        $status = (string) ($request->getQueryParams()['status'] ?? '');
        $sql = 'SELECT m.*, u.username FROM mail_queue m LEFT JOIN users u ON u.id = m.user_id';
        $params = [];
        if (in_array($status, ['queued', 'sent', 'failed', 'suppressed'], true)) {
            $sql .= ' WHERE m.status = ?';
            $params[] = $status;
        }
        return $this->render($response, 'admin/mails.twig', [
            'mails' => $this->app->db()->all($sql . ' ORDER BY m.created_at DESC, m.id DESC LIMIT 300', $params),
            'status' => $status,
            'counts' => $this->mailCounts(),
            'mail_mode' => $this->app->mailSender()->deliveryMode(),
        ]);
    }

    public function mail(Request $request, Response $response, array $args): Response
    {
        $mail = $this->app->db()->one('SELECT m.*, u.username FROM mail_queue m LEFT JOIN users u ON u.id = m.user_id WHERE m.id = ?', [(int) $args['id']]);
        if ($mail === null) {
            $this->notFound($request);
        }
        return $this->render($response, 'admin/mail.twig', ['mail' => $mail]);
    }

    /** HTML-Version einer E-Mail, abgeschottet (eigene CSP, Sandbox) */
    public function mailHtml(Request $request, Response $response, array $args): Response
    {
        $html = $this->app->db()->value('SELECT body_html FROM mail_queue WHERE id = ?', [(int) $args['id']]);
        if ($html === null) {
            $this->notFound($request);
        }
        $response->getBody()->write((string) $html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
    }

    public function mailRetry(Request $request, Response $response, array $args): Response
    {
        $this->app->mailSender()->retry((int) $args['id']);
        $this->session->flash('success', 'Die E-Mail wurde erneut eingereiht.');
        return $this->redirect($response, '/admin/mails/' . (int) $args['id']);
    }

    // ------------------------------------------------------------ Kalender und Zuordnungen

    public function calendars(Request $request, Response $response): Response
    {
        $db = $this->app->db();
        $calendars = $db->all(
            'SELECT c.*, (SELECT COUNT(*) FROM user_calendars uc WHERE uc.calendar_id = c.id) AS members,
                    (SELECT COUNT(*) FROM user_calendars uc WHERE uc.calendar_id = c.id AND ' . CalendarChoice::enabledSql() . ') AS members_on,
                    (SELECT COUNT(*) FROM events e WHERE e.calendar_id = c.id) AS events
               FROM calendars c ORDER BY c.display_name'
        );
        // Woher kommen die Termine? (MP-Feuer-Bereiche mit „befüllen“ und externe Befüllungen)
        $filledBy = [];
        foreach ($db->all('SELECT calendar_id, mp_key FROM calendar_sources WHERE fills = 1 ORDER BY mp_key') as $r) {
            $filledBy[(int) $r['calendar_id']][] = 'MP Feuer (' . $r['mp_key'] . ')';
        }
        foreach ($db->all('SELECT f.calendar_id, f.filter, s.name FROM calendar_feeds f JOIN sources s ON s.id = f.source_id WHERE f.active = 1 ORDER BY s.name') as $r) {
            $filter = FeedFilter::fromJson($r['filter']);
            $filledBy[(int) $r['calendar_id']][] = $r['name'] . ($filter->isEmpty() ? '' : ' (' . $filter->describe() . ')');
        }
        foreach ($calendars as &$c) {
            $c['filled_by'] = $filledBy[(int) $c['id']] ?? [];
        }
        unset($c);
        $sources = $db->all('SELECT cs.*, c.display_name, (SELECT MIN(label) FROM memberships m WHERE m.mp_key = cs.mp_key) AS label,
                                    (SELECT COUNT(DISTINCT user_id) FROM memberships m WHERE m.mp_key = cs.mp_key) AS members
                               FROM calendar_sources cs JOIN calendars c ON c.id = cs.calendar_id ORDER BY cs.mp_key');
        $areas = $db->all(
            'SELECT m.mp_key, MIN(m.label) AS label, COUNT(DISTINCT m.user_id) AS members
               FROM memberships m LEFT JOIN calendar_sources cs ON cs.mp_key = m.mp_key
              WHERE cs.id IS NULL GROUP BY m.mp_key ORDER BY m.mp_key'
        );
        return $this->render($response, 'admin/calendars.twig', [
            'calendars' => $calendars,
            'sources' => $sources,
            'areas' => $areas,
        ]);
    }

    public function calendarSave(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        $p = $this->post($request);
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $name = trim((string) ($p['display_name'] ?? ''));
        $slug = strtolower(trim((string) ($p['slug'] ?? '')));
        $color = strtoupper(trim((string) ($p['color'] ?? '#B91C1C')));
        $errors = [];
        if ($name === '') {
            $errors[] = 'Bitte einen Namen angeben.';
        }
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            $errors[] = 'Farbe bitte als #RRGGBB angeben.';
        }
        if ($id === null) {
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,98}$/', $slug)) {
                $errors[] = 'Kurzname (URL) nur aus a–z, 0–9 und „-“.';
            } elseif ($db->value('SELECT 1 FROM calendars WHERE slug = ?', [$slug]) !== null) {
                $errors[] = 'Kurzname ist bereits vergeben.';
            }
        }
        if ($errors !== []) {
            foreach ($errors as $e) {
                $this->session->flash('error', $e);
            }
            return $this->redirect($response, '/admin/kalender');
        }
        $now = $this->app->clock()->nowDb();
        $data = [
            'display_name' => $name,
            'color' => $color,
            'description' => trim((string) ($p['description'] ?? '')) ?: null,
            'active' => !empty($p['active']) ? 1 : 0,
            'all_members' => !empty($p['all_members']) ? 1 : 0,
            'default_enabled' => !empty($p['default_enabled']) ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($id === null) {
            $db->insert('calendars', $data + ['slug' => $slug, 'sync_token' => 1, 'created_at' => $now]);
            $this->session->flash('success', "Kalender „{$name}“ angelegt.");
        } else {
            $old = $db->one('SELECT * FROM calendars WHERE id = ?', [$id]);
            if ($old === null) {
                $this->notFound($request);
            }
            $db->update('calendars', $data, ['id' => $id]);
            if ($old['display_name'] !== $name) {
                $this->app->events()->rebuildCalendar($id); // CATEGORIES enthält den Namen
            }
            if ($old['color'] !== $color || $old['display_name'] !== $name) {
                // Clients sollen Name/Farbe neu lesen
                $db->execute('UPDATE calendars SET sync_token = sync_token + 1 WHERE id = ?', [$id]);
            }
            $this->session->flash('success', "Kalender „{$name}“ gespeichert.");
        }
        $stats = $this->app->memberships()->recomputeAll(true);
        if ($stats['added'] > 0) {
            $this->session->flash('info', "{$stats['added']} neue Kalenderzuordnung(en); die Mitglieder werden per E-Mail informiert.");
        }
        return $this->redirect($response, '/admin/kalender');
    }

    public function sourceAdd(Request $request, Response $response): Response
    {
        $db = $this->app->db();
        $p = $this->post($request);
        $key = trim((string) ($p['mp_key'] ?? ''));
        $calId = (int) ($p['calendar_id'] ?? 0);
        $grants = !empty($p['grants_access']);
        $fills = !empty($p['fills']);
        if ($key === '' || $db->value('SELECT 1 FROM calendars WHERE id = ?', [$calId]) === null) {
            $this->session->flash('error', 'Bitte Bereich (Schlüssel aus MP Feuer) und Kalender angeben.');
        } elseif (!$grants && !$fills) {
            $this->session->flash('error', 'Eine Zuordnung muss den Kalender freischalten, befüllen oder beides.');
        } elseif ($db->value('SELECT 1 FROM calendar_sources WHERE mp_key = ?', [$key]) !== null) {
            $this->session->flash('error', "Der Bereich „{$key}“ ist bereits einem Kalender zugeordnet.");
        } else {
            $db->insert('calendar_sources', [
                'mp_key' => $key, 'calendar_id' => $calId,
                'grants_access' => $grants ? 1 : 0, 'fills' => $fills ? 1 : 0,
                'created_at' => $this->app->clock()->nowDb(),
            ]);
            $stats = $this->app->memberships()->recomputeAll(true);
            $this->session->flash('success', "Zuordnung „{$key}“ gespeichert; {$stats['added']} neue Freischaltung(en)."
                . ($fills ? ' Termine dieses Bereichs erscheinen nach dem nächsten Import.' : ''));
        }
        return $this->redirect($response, '/admin/kalender');
    }

    public function sourceDelete(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        $db->execute('DELETE FROM calendar_sources WHERE id = ?', [(int) $args['id']]);
        $this->app->memberships()->recomputeAll(true);
        $this->session->flash('success', 'Zuordnung entfernt. Betroffene Mitglieder sehen den Kalender nicht mehr, sofern keine andere Mitgliedschaft dazu führt.');
        return $this->redirect($response, '/admin/kalender');
    }

    // ------------------------------------------------------------ Hilfen

    /** @return array<string,int> */
    private function mailCounts(): array
    {
        $counts = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'suppressed' => 0];
        foreach ($this->app->db()->all('SELECT status, COUNT(*) AS n FROM mail_queue GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $counts;
    }

    private function sortValue(array $u, string $sort): mixed
    {
        return match ($sort) {
            'name' => mb_strtolower($u['last_name'] . ' ' . $u['first_name']),
            'calendars' => count($u['calendars']),
            default => $u[$sort] ?? null,
        };
    }

    /** Filter/Suche der Nutzerübersicht beim Zurückspringen erhalten */
    private function backQuery(Request $request): string
    {
        $q = $request->getQueryParams();
        $allowed = array_intersect_key($q, array_flip(['filter', 'tage', 'kalender', 'q', 'sort', 'dir']));
        return http_build_query(array_filter($allowed, fn ($v) => $v !== '' && $v !== null));
    }

    private function userUrl(int $id, Request $request): string
    {
        $q = (string) ($this->post($request)['back'] ?? '');
        return '/admin/benutzer/' . $id . ($q !== '' ? '?' . $q : '');
    }
}
