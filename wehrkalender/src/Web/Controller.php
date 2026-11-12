<?php

declare(strict_types=1);

namespace Wehrkalender\Web;

use Wehrkalender\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

abstract class Controller
{
    public function __construct(protected App $app, protected Session $session)
    {
    }

    protected function render(Response $response, string $template, array $data = [], int $status = 200): Response
    {
        return $this->app->view()->render($response->withStatus($status), $template, $data);
    }

    protected function redirect(Response $response, string $to, int $status = 303): Response
    {
        return $response->withHeader('Location', $to)->withStatus($status);
    }

    /** @return array<string,mixed> */
    protected function currentUser(Request $request): array
    {
        return $request->getAttribute('user');
    }

    /** @return array<string,mixed> */
    protected function post(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    protected function notFound(Request $request): never
    {
        throw new HttpNotFoundException($request);
    }

    protected function clientIp(Request $request): string
    {
        return ClientIp::resolve($request->getServerParams(), $this->app->config->list('TRUSTED_PROXIES'));
    }
}
