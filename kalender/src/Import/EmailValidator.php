<?php

declare(strict_types=1);

namespace Kalender\Import;

use RuntimeException;

/**
 * Prüft E-Mail-Adressen für den Import (Baustein für import:run).
 *
 * Gültig: filter_var(FILTER_VALIDATE_EMAIL) und die Domain hat einen MX- oder
 * A/AAAA-Eintrag. Ergebnisse werden je Domain für die Lebensdauer des Objekts
 * (= ein Importlauf) zwischengespeichert.
 *
 * DNS-Ausfall: Schlägt eine Domain fehl, wird einmalig eine Kontrolldomain
 * abgefragt. Ist auch diese nicht auflösbar, wird DnsUnavailable geworfen, damit
 * der Import abbricht, statt Adressen fälschlich als ungültig zu werten.
 */
final class EmailValidator
{
    /** @var array<string,bool> */
    private array $cache = [];
    private ?bool $dnsWorks = null;
    /** @var callable(string,string):bool */
    private $resolver;

    /**
     * @param null|callable(string $host, string $type):bool $resolver für Tests; Standard: checkdnsrr
     */
    public function __construct(?callable $resolver = null, private string $probeDomain = 'denic.de')
    {
        $this->resolver = $resolver ?? static fn (string $host, string $type): bool => checkdnsrr($host, $type);
    }

    /** @throws DnsUnavailable */
    public function isValid(?string $email): bool
    {
        $email = trim((string) $email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $domain = strtolower(substr($email, strrpos($email, '@') + 1));
        if (function_exists('idn_to_ascii')) {
            $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain;
        }
        if (!array_key_exists($domain, $this->cache)) {
            $ok = $this->resolves($domain);
            if (!$ok && !$this->dnsWorks()) {
                throw new DnsUnavailable('DNS nicht erreichbar – E-Mail-Adressen können nicht geprüft werden.');
            }
            $this->cache[$domain] = $ok;
        }
        return $this->cache[$domain];
    }

    private function resolves(string $domain): bool
    {
        $host = rtrim($domain, '.') . '.';
        foreach (['MX', 'A', 'AAAA'] as $type) {
            if (($this->resolver)($host, $type)) {
                return true;
            }
        }
        return false;
    }

    private function dnsWorks(): bool
    {
        return $this->dnsWorks ??= $this->resolves($this->probeDomain);
    }
}
