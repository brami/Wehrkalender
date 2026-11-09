<?php

declare(strict_types=1);

namespace Kalender\Web;

use Kalender\Security\LoginThrottle;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Anmeldung mit Passwort (Benutzername oder E-Mail) und per Einmal-Link.
 */
final class AuthController extends Controller
{
    private const GENERIC_ERROR = 'Benutzername/E-Mail oder Passwort ist falsch.';

    public function loginForm(Request $request, Response $response): Response
    {
        if ($this->session->userId() !== null) {
            return $this->redirect($response, '/');
        }
        return $this->render($response, 'auth/login.twig', ['identity' => '']);
    }

    public function login(Request $request, Response $response): Response
    {
        $post = $this->post($request);
        $identity = trim((string) ($post['identity'] ?? ''));
        $password = (string) ($post['password'] ?? '');
        $throttle = $this->app->throttle();
        $ipKey = $throttle->ipKey($this->clientIp($request));

        $fail = fn (string $msg, int $status = 200) => $this->render($response, 'auth/login.twig', [
            'identity' => $identity,
            'error' => $msg,
        ], $status);

        if ($identity === '' || $password === '') {
            return $fail('Bitte Benutzername oder E-Mail-Adresse und Passwort eingeben.');
        }
        if ($throttle->tooMany($ipKey, LoginThrottle::IP_FAILS_PER_HOUR)) {
            return $fail('Zu viele fehlgeschlagene Anmeldungen. Bitte später erneut versuchen oder einen Anmeldelink anfordern.', 429);
        }

        $candidates = $this->candidates($identity);
        $passwords = $this->app->passwords();
        foreach ($candidates as $user) {
            if ($throttle->tooMany('user:' . $user['id'], LoginThrottle::USER_FAILS_PER_HOUR)) {
                continue;
            }
            if ($passwords->verify($user['password_enc'], $password)) {
                if ($user['status'] !== 'active') {
                    return $this->render($response, 'auth/login.twig', ['identity' => $identity, 'locked' => true]);
                }
                $throttle->clear('user:' . $user['id']);
                $this->session->login((int) $user['id']);
                $this->app->accounts()->recordWebLogin((int) $user['id']);
                return $this->redirect($response, '/');
            }
        }
        $throttle->hit($ipKey);
        foreach ($candidates as $user) {
            $throttle->hit('user:' . $user['id']);
        }
        return $fail(self::GENERIC_ERROR);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->session->logout();
        $this->session->flash('info', 'Sie wurden abgemeldet.');
        return $this->redirect($response, '/login');
    }

    public function linkForm(Request $request, Response $response): Response
    {
        return $this->render($response, 'auth/link_request.twig', ['identity' => '']);
    }

    /** Antwort ist immer gleich, damit nicht erkennbar ist, ob ein Konto besteht */
    public function linkRequest(Request $request, Response $response): Response
    {
        $identity = trim((string) ($this->post($request)['identity'] ?? ''));
        $throttle = $this->app->throttle();
        $ipKey = 'link' . $throttle->ipKey($this->clientIp($request));
        if ($identity !== '' && !$throttle->tooMany($ipKey, LoginThrottle::IP_FAILS_PER_HOUR)) {
            $throttle->hit($ipKey);
            foreach ($this->candidates($identity) as $user) {
                if ($user['status'] !== 'active') {
                    continue;
                }
                $key = 'link:' . $user['id'];
                if ($throttle->tooMany($key, LoginThrottle::LINK_REQUESTS_PER_HOUR)) {
                    continue;
                }
                $throttle->hit($key);
                $this->app->accounts()->queueLoginLink((int) $user['id']);
            }
        }
        return $this->render($response, 'auth/link_sent.twig');
    }

    /**
     * Einmal-Link aufrufen. GET verbraucht den Link NICHT, sondern zeigt eine
     * Schaltfläche (POST). So entwerten Link-Prüfer von Mailprogrammen
     * (z. B. Outlook "Sichere Links") den Link nicht vorab.
     */
    public function linkShow(Request $request, Response $response, array $args): Response
    {
        $check = $this->app->accounts()->checkLoginToken((string) $args['token']);
        return $this->render($response, 'auth/link_confirm.twig', [
            'status' => $check['status'],
            'token' => $args['token'],
            'purpose' => $check['token']['purpose'] ?? null,
            'username' => $check['status'] === 'ok' ? $check['user']['username'] : null,
        ], $check['status'] === 'ok' ? 200 : 410);
    }

    public function linkConsume(Request $request, Response $response, array $args): Response
    {
        $user = $this->app->accounts()->consumeLoginToken((string) $args['token']);
        if ($user === null) {
            return $this->redirect($response, '/login/link/' . rawurlencode((string) $args['token']));
        }
        $this->session->login((int) $user['id']);
        $this->app->accounts()->recordWebLogin((int) $user['id']);
        return $this->redirect($response, '/');
    }

    /** @return list<array<string,mixed>> */
    private function candidates(string $identity): array
    {
        $db = $this->app->db();
        if (str_contains($identity, '@')) {
            // Mehrere Konten mit derselben Adresse sind möglich (z. B. Geschwister in der Kinderfeuerwehr)
            return $db->all('SELECT * FROM users WHERE LOWER(email) = ? ORDER BY id', [mb_strtolower($identity)]);
        }
        return $db->all('SELECT * FROM users WHERE username = ?', [mb_strtolower($identity)]);
    }
}
