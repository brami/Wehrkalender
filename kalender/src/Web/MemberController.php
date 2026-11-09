<?php

declare(strict_types=1);

namespace Kalender\Web;

use Kalender\Domain\DeviceDetector;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/** Seiten für Mitglieder */
final class MemberController extends Controller
{
    public function home(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $accounts = $this->app->accounts();
        $outdated = $accounts->isUsernameOutdated($user);
        return $this->render($response, 'member/home.twig', [
            'calendars' => $this->calendarsWithFeeds((int) $user['id']),
            'outdated' => $outdated,
            'suggestion' => $outdated ? $accounts->suggestedUsername($user) : null,
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
      <key>PayloadDescription</key><string>Kalender der FF KRZ (nur lesend)</string>
      <key>PayloadDisplayName</key><string>{$e($name)}</string>
      <key>PayloadIdentifier</key><string>{$e($reverse)}.kalender.caldav</string>
      <key>PayloadType</key><string>com.apple.caldav.account</string>
      <key>PayloadUUID</key><string>{$uuid('caldav')}</string>
      <key>PayloadVersion</key><integer>1</integer>
    </dict>
  </array>
  <key>PayloadDescription</key><string>Richtet die Kalender der FF KRZ auf diesem Gerät ein.</string>
  <key>PayloadDisplayName</key><string>{$e($name)}</string>
  <key>PayloadIdentifier</key><string>{$e($reverse)}.kalender</string>
  <key>PayloadOrganization</key><string>Freiwillige Feuerwehr KRZ</string>
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
            ->withHeader('Content-Disposition', 'attachment; filename="kalender-ff-krz.mobileconfig"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function memberships(Request $request, Response $response): Response
    {
        $user = $this->currentUser($request);
        $rows = $this->app->db()->all(
            'SELECT m.mp_key, m.label, c.display_name AS calendar, c.color, c.active
               FROM memberships m
               LEFT JOIN calendar_sources cs ON cs.mp_key = m.mp_key
               LEFT JOIN calendars c ON c.id = cs.calendar_id
              WHERE m.user_id = ? ORDER BY m.label',
            [$user['id']]
        );
        $allMembers = $this->app->db()->all('SELECT display_name, color FROM calendars WHERE all_members = 1 AND active = 1 ORDER BY display_name');
        return $this->render($response, 'member/memberships.twig', ['memberships' => $rows, 'all_members' => $allMembers]);
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
        return $this->redirect($response, '/geraete');
    }

    public function regenerateFeeds(Request $request, Response $response): Response
    {
        $this->app->accounts()->regenerateFeedTokens((int) $this->currentUser($request)['id']);
        $this->session->flash('success', 'Neue Abo-Links wurden erzeugt. Die bisherigen Links gelten ab sofort nicht mehr – bitte in Outlook ersetzen.');
        return $this->redirect($response, '/');
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
        return $this->redirect($response, '/geraete');
    }

    // ---------------------------------------------------- Hilfen

    /** @return list<array<string,mixed>> */
    private function calendarsWithFeeds(int $userId): array
    {
        $rows = $this->app->db()->all(
            'SELECT c.id, c.slug, c.display_name, c.color, c.description, uc.since,
                    (SELECT f.token FROM feed_tokens f WHERE f.user_id = uc.user_id AND f.calendar_id = c.id AND f.revoked_at IS NULL ORDER BY f.id DESC LIMIT 1) AS token,
                    (SELECT f.last_access_at FROM feed_tokens f WHERE f.user_id = uc.user_id AND f.calendar_id = c.id AND f.revoked_at IS NULL ORDER BY f.id DESC LIMIT 1) AS last_access_at,
                    (SELECT f.last_client FROM feed_tokens f WHERE f.user_id = uc.user_id AND f.calendar_id = c.id AND f.revoked_at IS NULL ORDER BY f.id DESC LIMIT 1) AS last_client
               FROM user_calendars uc JOIN calendars c ON c.id = uc.calendar_id
              WHERE uc.user_id = ? AND c.active = 1
              ORDER BY c.display_name',
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
