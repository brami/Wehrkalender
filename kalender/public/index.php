<?php

declare(strict_types=1);

use Kalender\App;
use Kalender\Dav\DavServerFactory;
use Kalender\Web\ClientIp;
use Kalender\Web\WebApp;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::boot(dirname(__DIR__));
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Statische Dateien beim lokalen Test mit "php -S" direkt ausliefern
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

if ($path === '/dav' || str_starts_with($path, '/dav/')) {
    $server = DavServerFactory::create(
        $app,
        ClientIp::resolve($_SERVER, $app->config->list('TRUSTED_PROXIES')),
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
    );
    $server->start();
    return;
}

WebApp::create($app)->run();
