<?php

declare(strict_types=1);

namespace Kalender\Web;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * iCalendar-Feed für Outlook & Co.: GET /abo/<token>.ics
 * 404 bei unbekanntem/widerrufenem Token, gesperrtem Benutzer oder entzogener Zuordnung.
 */
final class FeedController extends Controller
{
    public function show(Request $request, Response $response, array $args): Response
    {
        $db = $this->app->db();
        $token = (string) $args['token'];
        $row = preg_match('/^[A-Za-z0-9_-]{43}$/', $token) ? $db->one(
            "SELECT f.id AS feed_id, f.user_id, f.calendar_id, c.display_name, c.color, c.sync_token
               FROM feed_tokens f
               JOIN users u ON u.id = f.user_id
               JOIN calendars c ON c.id = f.calendar_id
               JOIN user_calendars uc ON uc.user_id = f.user_id AND uc.calendar_id = f.calendar_id
              WHERE f.token = ? AND f.revoked_at IS NULL AND u.status = 'active' AND c.active = 1",
            [$token]
        ) : null;
        if ($row === null) {
            $response->getBody()->write("Dieser Abo-Link ist ungültig.\n");
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $etag = '"feed-' . $row['calendar_id'] . '-' . $row['sync_token'] . '"';
        $this->touch((int) $row['feed_id'], $request);
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=300',
        ];
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true)) {
            foreach ($headers as $k => $v) {
                $response = $response->withHeader($k, $v);
            }
            return $response->withStatus(304);
        }

        // Termine ab 12 Monaten in der Vergangenheit, unbegrenzt in die Zukunft
        $ics = $db->column(
            'SELECT ics FROM events WHERE calendar_id = ? AND ends_at >= ? ORDER BY starts_at',
            [$row['calendar_id'], $this->app->clock()->db('-12 months')]
        );
        $body = $this->app->ics()->buildFeed($row['display_name'], $row['color'], $ics);
        $response->getBody()->write($body);
        $response = $response->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $row['display_name']) . '.ics"');
        foreach ($headers as $k => $v) {
            $response = $response->withHeader($k, $v);
        }
        return $response;
    }

    private function touch(int $feedId, Request $request): void
    {
        $this->app->db()->update('feed_tokens', [
            'last_access_at' => $this->app->clock()->nowDb(),
            'last_client' => mb_substr($request->getHeaderLine('User-Agent'), 0, 255),
        ], ['id' => $feedId]);
    }
}
