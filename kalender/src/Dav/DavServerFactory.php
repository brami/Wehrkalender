<?php

declare(strict_types=1);

namespace Kalender\Dav;

use Kalender\App;
use Sabre\CalDAV;
use Sabre\DAV;
use Sabre\DAVACL;

/**
 * Baut den CalDAV-Server (sabre/dav) unter /dav/ auf:
 * Baum = Principals + CalendarRoot, Plugins = Auth, ACL, CalDAV, Sync.
 */
final class DavServerFactory
{
    public const BASE_URI = '/dav/';

    public static function create(App $app, string $clientIp, string $userAgent): DAV\Server
    {
        $db = $app->db();
        $authBackend = new FfAuthBackend($db, $app->passwords(), $app->throttle(), $app->clock(), $clientIp, $userAgent);
        $authPlugin = new DAV\Auth\Plugin($authBackend);

        $current = static function () use ($authPlugin): ?string {
            $p = $authPlugin->getCurrentPrincipal();
            return $p === null ? null : trim($p, '/');
        };
        $principalBackend = new FfPrincipalBackend($db, \Closure::fromCallable($current));
        $calendarBackend = new FfCalendarBackend($db);

        $tree = [
            new CalDAV\Principal\Collection($principalBackend, 'principals'),
            new CalDAV\CalendarRoot($principalBackend, $calendarBackend, 'principals'),
        ];

        DAV\Server::$exposeVersion = false;
        $server = new DAV\Server($tree);
        $server->setBaseUri(self::BASE_URI);

        $server->addPlugin($authPlugin);

        $acl = new DAVACL\Plugin();
        $acl->allowUnauthenticatedAccess = false;
        $acl->hideNodesFromListings = true;
        $server->addPlugin($acl);

        $server->addPlugin(new CalDAV\Plugin());
        $server->addPlugin(new DAV\Sync\Plugin());

        if ($app->config->env() === 'dev') {
            // Nur zur Fehlersuche, nie im Betrieb
            $server->addPlugin(new DAV\Browser\Plugin(false));
        }
        return $server;
    }
}
