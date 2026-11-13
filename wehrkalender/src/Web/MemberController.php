<?php

declare(strict_types=1);

namespace Wehrkalender\Web;

use Wehrkalender\Domain\CalendarChoice;
use DateTimeImmutable;
use Wehrkalender\Calendar\YearPlanner;
use Wehrkalender\Clock;
use Wehrkalender\Domain\DeviceDetector;
use Wehrkalender\Pdf\YearPlannerPdf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/** Seiten für Mitglieder */
final class MemberController extends Controller
{
    private const MONTH_NAMES = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
        'August', 'September', 'Oktober', 'November', 'Dezember'];

    public function home(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $accounts = $this->app->accounts();
        $outdated = $accounts->isUsernameOutdated($user);
        $year = (int) $this->app->clock()->now()->setTimezone(Clock::localTz())->format('Y');
        [$calendars, $unmapped] = $this->calendarsWithOrigin((int) $user['id'], $year);
        $stands = $this->app->sourceState()->calendarStands();
        foreach ($calendars as &$c) {
            $c['stand'] = $stands[(int) $c['id']] ?? null;
        }
        unset($c);
        $on = array_values(array_filter($calendars, fn ($c) => (int) $c['enabled'] === 1));
        $off = array_values(array_filter($calendars, fn ($c) => (int) $c['enabled'] !== 1));
        return $this->render($response, 'member/home.twig', [
            'calendars' => $calendars,
            'calendars_on' => $on,
            'calendars_off' => $off,
            'unmapped' => $unmapped,
            'year' => $year,
            'outdated' => $outdated,
            'suggestion' => $outdated ? $accounts->suggestedUsername($user) : null,
        ]);
    }

    /** Kalender ein- oder ausschalten (POST /kalender/{slug}/ein|aus) */
    public function toggleCalendar(Request $request, Response $response, array $args): Response
    {
        $user = $this->currentUser($request);
        $db = $this->app->db();
        $cal = $db->one('SELECT id, display_name FROM calendars WHERE slug = ? AND active = 1', [(string) $args['slug']]);
        $on = $args['action'] === 'ein';
        if ($cal === null || !$this->app->calendarChoice()->set((int) $user['id'], (int) $cal['id'], $on)) {
            $this->session->flash('error', 'Dieser Kalender ist für Sie nicht freigeschaltet.');
            return $this->redirect($response, '/');
        }
        $this->session->flash('success', $on
            ? 'Der Kalender „' . $cal['display_name'] . '“ ist eingeschaltet. Auf Smartphone und Tablet erscheint er beim nächsten Abgleich; für Outlook und Google finden Sie den Abo-Link unter „Kalender abonnieren“.'
            : 'Der Kalender „' . $cal['display_name'] . '“ ist ausgeschaltet. Er verschwindet beim nächsten Abgleich von Smartphone und Tablet; ein Abo in Outlook oder Google bleibt leer, bis Sie ihn wieder einschalten.');
        return $this->redirect($response, '/');
    }

    /** Übersicht „Kalender abonnieren“ */
    public function subscribe(Request $request, Response $response): Response
    {
        return $this->render($response, 'member/subscribe.twig');
    }

    /** Abo-Links für Outlook und Google Kalender */
    public function feeds(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        return $this->render($response, 'member/feeds.twig', [
            'calendars' => $this->calendarsWithFeeds((int) $user['id']),
            'android' => stripos($request->getHeaderLine('User-Agent'), 'Android') !== false,
        ]);
    }

    public function devices(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        return $this->render($response, 'member/devices.twig', [
            'password' => $this->app->accounts()->plainPassword($user),
            'server' => $this->app->config->domain(),
            'principal_url' => $this->app->config->baseUrl() . '/dav/principals/' . $user['username'] . '/',
            'device' => $user['last_dav_client'] ? DeviceDetector::label($user['last_dav_client']) : null,
        ]);
    }

    /** iOS-Konfigurationsprofil (.mobileconfig, unsigniert) */
    public function mobileconfig(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $cfg = $this->app->config;
        $domain = $cfg->domain();
        $reverse = implode('.', array_reverse(explode('.', $domain)));
        $uuid = fn (string $salt) => self::uuidFrom($domain . '|' . $user['id'] . '|' . $salt);
        $e = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $password = (string) $this->app->accounts()->plainPassword($user);
        $name = $cfg->appName();
        $wehr = $cfg->wehrName();
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>PayloadContent</key>
  <array>
    <dict>
      <key>CalDAVAccountDescription</key><string>{$e($name)}</string>
      <key>CalDAVHostName</key><string>{$e($domain)}</string>
      <key>CalDAVPort</key><integer>443</integer>
      <key>CalDAVUseSSL</key><true/>
      <key>CalDAVPrincipalURL</key><string>/dav/principals/{$e($user['username'])}/</string>
      <key>CalDAVUsername</key><string>{$e($user['username'])}</string>
      <key>CalDAVPassword</key><string>{$e($password)}</string>
      <key>PayloadDescription</key><string>Kalender der {$e($wehr)} (nur lesend)</string>
      <key>PayloadDisplayName</key><string>{$e($name)}</string>
      <key>PayloadIdentifier</key><string>{$e($reverse)}.kalender.caldav</string>
      <key>PayloadType</key><string>com.apple.caldav.account</string>
      <key>PayloadUUID</key><string>{$uuid('caldav')}</string>
      <key>PayloadVersion</key><integer>1</integer>
    </dict>
  </array>
  <key>PayloadDescription</key><string>Richtet die Kalender der {$e($wehr)} auf diesem Gerät ein.</string>
  <key>PayloadDisplayName</key><string>{$e($name)}</string>
  <key>PayloadIdentifier</key><string>{$e($reverse)}.kalender</string>
  <key>PayloadOrganization</key><string>{$e($wehr)}</string>
  <key>PayloadRemovalDisallowed</key><false/>
  <key>PayloadType</key><string>Configuration</string>
  <key>PayloadUUID</key><string>{$uuid('profile')}</string>
  <key>PayloadVersion</key><integer>1</integer>
</dict>
</plist>

XML;
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/x-apple-aspen-config')
            ->withHeader('Content-Disposition', 'attachment; filename="wehrkalender.mobileconfig"')
            ->withHeader('Cache-Control', 'no-store');
    }

    // ---------------------------------------------------- Terminübersicht (PDF)

    public function plannerForm(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $now = $this->app->clock()->now()->setTimezone(Clock::localTz());
        $first = new DateTimeImmutable($now->format('Y-01-01'), Clock::localTz());
        $options = [];
        for ($i = -12; $i <= 35; $i++) {
            $m = $first->modify(($i >= 0 ? '+' : '') . $i . ' months');
            $options[] = ['value' => $m->format('Y-m'), 'label' => self::MONTH_NAMES[(int) $m->format('n') - 1] . ' ' . $m->format('Y')];
        }
        return $this->render($response, 'member/planner.twig', [
            'calendars' => $this->app->yearPlanner()->calendarsFor((int) $user['id']),
            'start_options' => $options,
            'start' => $now->format('Y') . '-01',
            'months' => YearPlanner::MAX_MONTHS,
            'max_months' => YearPlanner::MAX_MONTHS,
        ]);
    }

    public function plannerPdf(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $q = $request->getQueryParams();
        $start = (string) ($q['start'] ?? '');
        $months = filter_var($q['monate'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => YearPlanner::MAX_MONTHS]]);
        $slugs = array_values(array_filter((array) ($q['k'] ?? []), 'is_string'));
        $pages = ($q['seiten'] ?? '1') === '2' ? 2 : 1;
        if (!preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $start) || $months === false) {
            $this->session->flash('error', 'Bitte Startmonat und Zeitraum auswählen.');
            return $this->redirect($response, '/terminuebersicht');
        }
        $planner = $this->app->yearPlanner();
        $plan = $planner->build((int) $user['id'], new DateTimeImmutable($start . '-01', Clock::localTz()), $months, $slugs);
        if ($plan['calendars'] === []) {
            $this->session->flash('error', 'Bitte mindestens einen Kalender auswählen.');
            return $this->redirect($response, '/terminuebersicht');
        }
        $pdf = YearPlannerPdf::render(
            $plan,
            trim($user['first_name'] . ' ' . $user['last_name']),
            $this->app->clock()->now()->setTimezone(Clock::localTz()),
            $this->app->config->appName(),
            $pages
        );
        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . YearPlannerPdf::fileName($plan['start'], $plan['end']) . '"')
            ->withHeader('Cache-Control', 'no-store, private');
    }

    // ---------------------------------------------------- Neu generieren

    public function confirm(Request $request, Response $response, array $args): Response
    {
        $user = $this->currentUser($request);
        $action = (string) $args['action'];
        $data = ['action' => $action];
        if ($action === 'benutzername') {
            if (!$this->app->accounts()->isUsernameOutdated($user)) {
                $this->session->flash('info', 'Ihr Benutzername passt zu Ihrem Namen. Eine Umstellung ist nicht nötig.');
                return $this->redirect($response, '/');
            }
            $data['suggestion'] = $this->app->accounts()->suggestedUsername($user);
        } elseif (!in_array($action, ['passwort', 'abo-links'], true)) {
            $this->notFound($request);
        }
        return $this->render($response, 'member/confirm.twig', $data);
    }

    public function regeneratePassword(Request $request, Response $response): Response
    {
        $this->app->accounts()->regeneratePassword((int) $this->currentUser($request)['id']);
        $this->session->flash('success', 'Ein neues Passwort wurde erzeugt. Das alte Passwort gilt ab sofort nicht mehr – bitte auf Smartphone und Tablet ersetzen.');
        return $this->redirect($response, '/abonnieren/geraete');
    }

    public function regenerateFeeds(Request $request, Response $response): Response
    {
        $this->app->accounts()->regenerateFeedTokens((int) $this->currentUser($request)['id']);
        $this->session->flash('success', 'Neue Abo-Links wurden erzeugt. Die bisherigen Links gelten ab sofort nicht mehr – bitte in Outlook bzw. im Google Kalender ersetzen.');
        return $this->redirect($response, '/abonnieren/outlook');
    }

    public function changeUsername(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        try {
            $new = $this->app->accounts()->changeUsername((int) $user['id'], (int) $user['id']);
            $this->session->flash('success', "Ihr Benutzername lautet jetzt „{$new}“. Bitte richten Sie Smartphone und Tablet mit dem neuen Benutzernamen neu ein. Outlook läuft ohne Änderung weiter.");
        } catch (RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
        }
        return $this->redirect($response, '/abonnieren/geraete');
    }

    // ---------------------------------------------------- Hilfen

    /**
     * Eigene Kalender mit Anzahl der Termine im Kalenderjahr (ohne abgesagte)
     * @return list<array<string,mixed>>
     */
    private function calendarsWithCounts(int $userId, int $year): array
    {
        $tz = Clock::localTz();
        $from = Clock::toDb(new DateTimeImmutable(sprintf('%04d-01-01', $year), $tz));
        $to = Clock::toDb(new DateTimeImmutable(sprintf('%04d-01-01', $year + 1), $tz));
        return $this->app->db()->all(
            "SELECT c.id, c.slug, c.display_name, c.color, c.description, c.default_enabled,
                    (SELECT COUNT(*) FROM events e
                      WHERE e.calendar_id = c.id AND e.status <> 'CANCELLED'
                        AND e.starts_at >= ? AND e.starts_at < ?) AS event_count,
                    (SELECT ch.enabled FROM user_calendar_choice ch
                      WHERE ch.user_id = uc.user_id AND ch.calendar_id = uc.calendar_id) AS choice,
                    CASE WHEN " . CalendarChoice::enabledSql() . " THEN 1 ELSE 0 END AS enabled
               FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id
              WHERE uc.user_id = ? AND c.active = 1
              ORDER BY c.display_name",
            [$from, $to, $userId]
        );
    }

    /**
     * Kalender mit Terminanzahl und Herkunft (Mitgliedschaften bzw. „für alle Mitglieder“)
     * sowie Mitgliedschaften ohne eigenen Kalender.
     * @return array{0:list<array<string,mixed>>,1:list<string>}
     */
    private function calendarsWithOrigin(int $userId, int $year): array
    {
        $db = $this->app->db();
        $calendars = $this->calendarsWithCounts($userId, $year);
        $all = array_flip(array_map('intval', $db->column('SELECT id FROM calendars WHERE all_members = 1 AND active = 1')));
        $rows = $db->all(
            'SELECT m.label, c.id AS calendar_id
               FROM memberships m
               LEFT JOIN calendar_sources cs ON cs.mp_key = m.mp_key AND cs.grants_access = 1
               LEFT JOIN calendars c ON c.id = cs.calendar_id AND c.active = 1
              WHERE m.user_id = ?
              ORDER BY m.label',
            [$userId]
        );
        $origin = [];
        $mapped = [];
        foreach ($rows as $r) {
            if ($r['calendar_id'] !== null) {
                $origin[(int) $r['calendar_id']][$r['label']] = true;
                $mapped[$r['label']] = true;
            }
        }
        $unmapped = [];
        foreach ($rows as $r) {
            if ($r['calendar_id'] === null && !isset($mapped[$r['label']])) {
                $unmapped[$r['label']] = true;
            }
        }
        foreach ($calendars as &$c) {
            $c['origins'] = array_keys($origin[(int) $c['id']] ?? []);
            $c['all_members'] = isset($all[(int) $c['id']]);
        }
        unset($c);
        return [$calendars, array_keys($unmapped)];
    }

    /** @return list<array<string,mixed>> */
    private function calendarsWithFeeds(int $userId): array
    {
        $rows = $this->app->db()->all(
            'SELECT c.id, c.slug, c.display_name, c.color, c.description, uc.since,
                    CASE WHEN ' . CalendarChoice::enabledSql() . ' THEN 1 ELSE 0 END AS enabled,
                    (SELECT f.token FROM feed_tokens f WHERE f.user_id = uc.user_id AND f.calendar_id = c.id AND f.revoked_at IS NULL ORDER BY f.id DESC LIMIT 1) AS token,
                    (SELECT f.last_access_at FROM feed_tokens f WHERE f.user_id = uc.user_id AND f.calendar_id = c.id AND f.revoked_at IS NULL ORDER BY f.id DESC LIMIT 1) AS last_access_at,
                    (SELECT f.last_client FROM feed_tokens f WHERE f.user_id = uc.user_id AND f.calendar_id = c.id AND f.revoked_at IS NULL ORDER BY f.id DESC LIMIT 1) AS last_client
               FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id
              WHERE uc.user_id = ? AND c.active = 1
              ORDER BY enabled DESC, c.display_name',
            [$userId]
        );
        $base = $this->app->config->baseUrl();
        foreach ($rows as &$r) {
            if ($r['token'] === null) {
                $r['token'] = $this->app->memberships()->ensureFeedToken($userId, (int) $r['id']);
            }
            $r['feed_url'] = $base . '/abo/' . $r['token'] . '.ics';
            $r['webcal_url'] = preg_replace('#^https?://#', 'webcal://', $r['feed_url']);
        }
        return $rows;
    }

    public static function uuidFrom(string $seed): string
    {
        $h = md5($seed);
        return strtoupper(sprintf('%s-%s-4%s-%s%s-%s',
            substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3),
            dechex((hexdec($h[16]) & 0x3) | 0x8), substr($h, 17, 3), substr($h, 20, 12)));
    }
}
