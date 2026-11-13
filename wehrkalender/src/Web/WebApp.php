<?php

declare(strict_types=1);

namespace Wehrkalender\Web;

use Wehrkalender\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\App as SlimApp;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Throwable;

/**
 * Web-Frontend (Slim 4): Routen und Middleware.
 * CalDAV unter /dav/ läuft außerhalb von Slim (siehe public/index.php).
 */
final class WebApp
{
    public const GUIDES = ['ios' => 'iPhone und iPad', 'android' => 'Android', 'outlook' => 'Outlook'];

    public static function create(App $app, ?Session $session = null): SlimApp
    {
        $session ??= new Session($app->config->bool('SESSION_SECURE', true));
        $slim = AppFactory::create();
        $view = $app->view();

        $auth = new AuthController($app, $session);
        $member = new MemberController($app, $session);
        $feed = new FeedController($app, $session);
        $admin = new AdminController($app, $session);
        $sources = new SourcesController($app, $session);

        // ---------------------------------------------------- öffentlich
        $slim->get('/.well-known/caldav', fn (Request $q, Response $r) => $r->withHeader('Location', '/dav/')->withStatus(301));
        $slim->get('/.well-known/carddav', fn (Request $q, Response $r) => $r->withStatus(404));
        $slim->get('/health', function (Request $q, Response $r) use ($app) {
            $h = $app->status()->health();
            $r->getBody()->write(json_encode(['status' => $h['ok'] ? 'ok' : 'warning', 'db' => $h['db'], 'problems' => $h['problems']], JSON_UNESCAPED_UNICODE));
            return $r->withStatus($h['ok'] ? 200 : 503)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
        });
        $slim->get('/abo/{token}.ics', [$feed, 'show']);

        $slim->get('/login', [$auth, 'loginForm']);
        $slim->post('/login', [$auth, 'login']);
        $slim->get('/login/link', [$auth, 'linkForm']);
        $slim->post('/login/link', [$auth, 'linkRequest']);
        $slim->get('/login/link/{token}', [$auth, 'linkShow']);
        $slim->post('/login/link/{token}', [$auth, 'linkConsume']);
        $slim->post('/logout', [$auth, 'logout']);

        $slim->get('/anleitung/{geraet}', function (Request $q, Response $r, array $a) use ($view) {
            if (!isset(self::GUIDES[$a['geraet']])) {
                throw new HttpNotFoundException($q);
            }
            return $view->render($r, 'guide/' . $a['geraet'] . '.twig', ['guide' => $a['geraet'], 'guides' => self::GUIDES]);
        });
        $slim->get('/impressum', fn (Request $q, Response $r) => $view->render($r, 'imprint.twig', [
            'libraries' => Licenses::fromInstalled($app->config->baseDir() . '/vendor/composer/installed.json'),
        ]));
        $slim->get('/datenschutz', fn (Request $q, Response $r) => $view->render($r, 'privacy.twig'));

        // ---------------------------------------------------- Mitglieder
        $requireUser = function (Request $request, Handler $handler): Response {
            if ($request->getAttribute('user') === null) {
                $r = new \Slim\Psr7\Response();
                return $r->withHeader('Location', '/login')->withStatus(303);
            }
            return $handler->handle($request);
        };
        $slim->group('', function (RouteCollectorProxy $g) use ($member) {
            $g->get('/', [$member, 'home']);
            $g->post('/kalender/{slug:[a-z0-9-]+}/{action:ein|aus}', [$member, 'toggleCalendar']);
            $g->get('/abonnieren', [$member, 'subscribe']);
            $g->get('/abonnieren/geraete', [$member, 'devices']);
            $g->get('/abonnieren/outlook', [$member, 'feeds']);
            $g->get('/abonnieren/geraete/wehrkalender.mobileconfig', [$member, 'mobileconfig']);
            $g->get('/terminuebersicht', [$member, 'plannerForm']);
            $g->get('/terminuebersicht.pdf', [$member, 'plannerPdf']);
            $g->get('/konto/{action:passwort|abo-links|benutzername}', [$member, 'confirm']);
            $g->post('/konto/passwort', [$member, 'regeneratePassword']);
            $g->post('/konto/abo-links', [$member, 'regenerateFeeds']);
            $g->post('/konto/benutzername', [$member, 'changeUsername']);
        })->add($requireUser);

        // ---------------------------------------------------- Administratoren
        $slim->group('/admin', function (RouteCollectorProxy $g) use ($admin, $sources) {
            $g->get('', [$admin, 'dashboard']);
            $g->get('/import/{id:\d+}', [$admin, 'run']);
            $g->get('/benutzer', [$admin, 'users']);
            $g->get('/benutzer/{id:\d+}', [$admin, 'user']);
            $g->post('/benutzer/{id:\d+}/begruessung', [$admin, 'resendWelcome']);
            $g->get('/benutzer/{id:\d+}/benutzername', [$admin, 'usernameConfirm']);
            $g->post('/benutzer/{id:\d+}/benutzername', [$admin, 'usernameChange']);
            $g->get('/mails', [$admin, 'mails']);
            $g->get('/mails/{id:\d+}', [$admin, 'mail']);
            $g->get('/mails/{id:\d+}/html', [$admin, 'mailHtml']);
            $g->post('/mails/{id:\d+}/erneut', [$admin, 'mailRetry']);
            $g->get('/kalender', [$admin, 'calendars']);
            $g->post('/kalender', [$admin, 'calendarSave']);
            $g->post('/kalender/{id:\d+}', [$admin, 'calendarSave']);
            $g->post('/zuordnung', [$admin, 'sourceAdd']);
            $g->post('/zuordnung/{id:\d+}/loeschen', [$admin, 'sourceDelete']);
            $g->post('/sperren/aufheben', [$admin, 'unblock']);
            $g->get('/quellen', [$sources, 'index']);
            $g->post('/quellen', [$sources, 'save']);
            $g->post('/quellen/{id:\d+}', [$sources, 'save']);
            $g->post('/quellen/{id:\d+}/loeschen', [$sources, 'delete']);
            $g->post('/quellen/{id:\d+}/datei', [$sources, 'fileUpload']);
            $g->get('/quellen/datei/{id:\d+}', [$sources, 'filePreview']);
            $g->post('/quellen/datei/{id:\d+}/uebernehmen', [$sources, 'fileApply']);
            $g->post('/quellen/datei/{id:\d+}/verwerfen', [$sources, 'fileDiscard']);
            $g->post('/quellen/datei/{id:\d+}/loeschen', [$sources, 'fileDelete']);
            $g->post('/quellen/{id:\d+}/abrufen', [$sources, 'fetchNow']);
            $g->post('/befuellung', [$sources, 'feedSave']);
            $g->post('/befuellung/{id:\d+}', [$sources, 'feedSave']);
            $g->post('/befuellung/{id:\d+}/loeschen', [$sources, 'feedDelete']);
        })->add(function (Request $request, Handler $handler): Response {
            $user = $request->getAttribute('user');
            if ($user === null) {
                return (new \Slim\Psr7\Response())->withHeader('Location', '/login')->withStatus(303);
            }
            if ((int) $user['is_admin'] !== 1) {
                throw new HttpForbiddenException($request);
            }
            return $handler->handle($request);
        });

        // ---------------------------------------------------- Middleware (letzte = äußerste)
        // Benutzer laden, CSRF prüfen, Twig-Globals setzen
        $slim->add(function (Request $request, Handler $handler) use ($app, $session, $view): Response {
            $path = $request->getUri()->getPath();
            $stateless = str_starts_with($path, '/abo/') || $path === '/health' || str_starts_with($path, '/.well-known/');
            if ($stateless) {
                return $handler->handle($request);
            }
            $session->start();
            if ($request->getMethod() === 'POST') {
                $body = $request->getParsedBody();
                if (!$session->csrfValid(is_array($body) ? ($body['_csrf'] ?? null) : null)) {
                    $r = $view->render(new \Slim\Psr7\Response(), 'error.twig', [
                        'code' => 400,
                        'message' => 'Das Formular ist abgelaufen. Bitte die Seite neu laden und erneut versuchen.',
                    ]);
                    return $r->withStatus(400);
                }
            }
            $user = null;
            if (($id = $session->userId()) !== null) {
                $user = $app->db()->one('SELECT * FROM users WHERE id = ?', [$id]);
                if ($user === null || $user['status'] !== 'active') {
                    $session->logout();
                    $user = null;
                }
            }
            $env = $view->getEnvironment();
            $env->addGlobal('current_user', $user);
            $env->addGlobal('csrf', $session->csrfToken());
            $env->addGlobal('flashes', $session->takeFlashes());
            $env->addGlobal('last_import', $app->status()->lastSuccessfulRun());
            $env->addGlobal('last_attempt', $app->status()->lastRun());
            $env->addGlobal('current_path', $path);
            return $handler->handle($request->withAttribute('user', $user));
        });

        $slim->addBodyParsingMiddleware();
        $slim->addRoutingMiddleware();

        // Fehlerseiten
        // Fehlerdetails im Browser nur außerhalb der Produktion (APP_ENV=test oder dev)
        $errors = $slim->addErrorMiddleware($app->config->env() !== 'prod', true, true);
        $errors->setDefaultErrorHandler(function (Request $request, Throwable $e, bool $displayDetails) use ($view, $app): Response {
            $code = $e instanceof \Slim\Exception\HttpException ? $e->getCode() : 500;
            if ($code >= 500) {
                error_log('[kalender] ' . $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
            $message = match ($code) {
                403 => 'Für diese Seite fehlen Ihnen die Rechte.',
                404 => 'Diese Seite gibt es nicht.',
                405 => 'Diese Aktion ist hier nicht erlaubt.',
                default => 'Es ist ein Fehler aufgetreten. Bitte später erneut versuchen.',
            };
            $env = $view->getEnvironment();
            foreach (['current_user' => null, 'csrf' => '', 'flashes' => [], 'last_import' => null, 'last_attempt' => null, 'current_path' => ''] as $k => $v) {
                if (!array_key_exists($k, $env->getGlobals())) {
                    $env->addGlobal($k, $v);
                }
            }
            $r = $view->render(new \Slim\Psr7\Response(), 'error.twig', [
                'code' => $code,
                'message' => $message,
                'details' => $displayDetails ? (string) $e : null,
            ]);
            return $r->withStatus($code);
        });

        // Sicherheits-Header (äußerste Schicht)
        $slim->add(function (Request $request, Handler $handler): Response {
            $response = $handler->handle($request);
            $path = $request->getUri()->getPath();
            if (!$response->hasHeader('Content-Security-Policy')) {
                $response = $response->withHeader('Content-Security-Policy',
                    "default-src 'self'; img-src 'self' data:; style-src 'self'; style-src-attr 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
            }
            $response = $response
                ->withHeader('X-Frame-Options', 'DENY')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Referrer-Policy', 'no-referrer');
            if (!str_starts_with($path, '/abo/') && !$response->hasHeader('Cache-Control')) {
                $response = $response->withHeader('Cache-Control', 'no-store');
            }
            return $response;
        });

        return $slim;
    }
}
