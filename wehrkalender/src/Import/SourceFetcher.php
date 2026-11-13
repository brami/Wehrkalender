<?php

declare(strict_types=1);

namespace Wehrkalender\Import;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
use Wehrkalender\App;
use Wehrkalender\Web\SourcesController;

/**
 * Abruf der iCal-Feeds (Quellen der Art ical) und Übernahme über den IcalProcessor.
 *
 * - nur HTTPS; interne und private Adressen sind gesperrt, auch wenn ein Name
 *   erst beim Abruf auf eine solche Adresse auflöst (NoPrivateNetworkHttpClient)
 * - höchstens 5 MB, 30 s, 3 Weiterleitungen
 * - schonend: If-None-Match / If-Modified-Since; 304 = unverändert
 * - Plausibilität: auffällige Befüllungen werden nicht übernommen (Fehler an der Quelle)
 */
final class SourceFetcher
{
    private HttpClientInterface $http;

    public function __construct(private App $app, ?HttpClientInterface $http = null)
    {
        $this->http = $http ?? new NoPrivateNetworkHttpClient(HttpClient::create([
            'timeout' => 30,
            'max_duration' => 60,
            'max_redirects' => 3,
            'headers' => ['User-Agent' => 'Wehrkalender/1.0 (+' . $app->config->baseUrl() . ')', 'Accept' => 'text/calendar, */*;q=0.5'],
        ]));
    }

    /** @return list<array<string,mixed>> Quellen, deren Abrufintervall abgelaufen ist */
    public function due(): array
    {
        $now = $this->app->clock();
        return array_values(array_filter(
            $this->app->db()->all("SELECT * FROM sources WHERE type = 'ical' AND active = 1 ORDER BY id"),
            fn ($s) => $s['last_run_at'] === null || $s['last_run_at'] <= $now->db('-' . (int) $s['interval_minutes'] . ' minutes')
        ));
    }

    /**
     * Eine Quelle abrufen und übernehmen.
     *
     * @param array<string,mixed> $source
     * @return array{status:string,message:string}  status: ok | unchanged | error | blocked
     */
    public function fetch(array $source, bool $force = false): array
    {
        $db = $this->app->db();
        $now = $this->app->clock()->nowDb();
        $fail = function (string $message) use ($db, $source, $now): array {
            $db->update('sources', ['last_run_at' => $now, 'last_error' => mb_substr($message, 0, 500), 'updated_at' => $now], ['id' => $source['id']]);
            return ['status' => 'error', 'message' => $message];
        };
        if ($error = SourcesController::urlError((string) $source['url'])) {
            return $fail($error);
        }
        $headers = [];
        if (!$force && $source['http_etag']) {
            $headers['If-None-Match'] = $source['http_etag'];
        }
        if (!$force && $source['http_last_modified']) {
            $headers['If-Modified-Since'] = $source['http_last_modified'];
        }
        try {
            $response = $this->http->request('GET', (string) $source['url'], [
                'headers' => $headers,
                'on_progress' => function (int $downloaded): void {
                    if ($downloaded > IcalProcessor::MAX_BYTES) {
                        throw new IcalError('Der Feed ist größer als 5 MB.');
                    }
                },
            ]);
            $status = $response->getStatusCode();
            if ($status === 304) {
                $db->update('sources', ['last_run_at' => $now, 'last_success_at' => $now, 'last_error' => null, 'updated_at' => $now], ['id' => $source['id']]);
                return ['status' => 'unchanged', 'message' => 'Feed unverändert (304).'];
            }
            if ($status >= 400) {
                return $fail('HTTP ' . $status);
            }
            $body = $response->getContent(false);
            $respHeaders = $response->getHeaders(false);
        } catch (Throwable $e) {
            return $fail(self::shortError($e));
        }
        $processor = $this->app->icalProcessor();
        try {
            $events = $processor->parse($body);
        } catch (IcalError $e) {
            return $fail($e->getMessage());
        }
        if ($events === []) {
            // ein plötzlich leerer Feed ist fast immer ein Fehler beim Anbieter
            return $fail('Der Feed enthält keine Termine – nichts übernommen.');
        }
        $stats = $db->transaction(function () use ($processor, $source, $events, $force): array {
            return $processor->apply($processor->plan($source, $events), $force);
        });
        if ($stats['blocked'] !== []) {
            return ['status' => 'blocked'] + $fail('Nicht übernommen (Plausibilität): ' . implode(' ', $stats['blocked']));
        }
        $db->update('sources', [
            'last_run_at' => $now, 'last_success_at' => $now, 'last_error' => null, 'updated_at' => $now,
            'http_etag' => $respHeaders['etag'][0] ?? null,
            'http_last_modified' => $respHeaders['last-modified'][0] ?? null,
        ], ['id' => $source['id']]);
        return ['status' => 'ok', 'message' => sprintf('%d Termine gelesen: %d neu, %d geändert, %d entfernt, %d unverändert.',
            count($events), $stats['added'], $stats['changed'], $stats['removed'], $stats['unchanged'])];
    }

    private static function shortError(Throwable $e): string
    {
        $m = $e->getMessage();
        if (stripos($m, 'private') !== false || stripos($m, 'blocked') !== false) {
            return 'Adresse zeigt auf ein internes Netz – gesperrt.';
        }
        if (stripos($m, 'resolve host') !== false || stripos($m, 'Could not resolve') !== false) {
            return 'Server nicht gefunden (DNS).';
        }
        if (stripos($m, 'timeout') !== false || stripos($m, 'timed out') !== false) {
            return 'Zeitüberschreitung beim Abruf.';
        }
        return mb_substr($m, 0, 200);
    }
}
