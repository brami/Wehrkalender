<?php

declare(strict_types=1);

namespace Kalender;

/**
 * Konfiguration aus Umgebungsvariablen bzw. .env-Datei.
 * Echte Umgebungsvariablen haben Vorrang vor der .env.
 */
final class Config
{
    /** @param array<string,string> $values */
    public function __construct(private array $values)
    {
    }

    public static function load(string $baseDir, ?string $envFile = null): self
    {
        $values = [];
        $candidates = $envFile !== null ? [$envFile] : [$baseDir . '/.env', '/srv/kalender/.env'];
        foreach ($candidates as $file) {
            if (is_readable($file)) {
                $values = self::parseEnvFile($file);
                break;
            }
        }
        foreach (array_merge($_SERVER, $_ENV) as $key => $value) {
            if (is_string($key) && is_string($value) && preg_match('/^(APP|DB|MAIL|MAILER|ADMIN|SESSION|IMPORT|TRUSTED)_/', $key)) {
                $values[$key] = $value;
            }
        }
        // setup-server.sh schreibt DOMAIN=..., die Doku nennt APP_DOMAIN
        if (empty($values['APP_DOMAIN']) && !empty($values['DOMAIN'])) {
            $values['APP_DOMAIN'] = $values['DOMAIN'];
        }
        $values['BASE_DIR'] = $baseDir;
        return new self($values);
    }

    /** @return array<string,string> */
    public static function parseEnvFile(string $file): array
    {
        $values = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (preg_match('/^"(.*)"$/s', $value, $m) || preg_match("/^'(.*)'$/s", $value, $m)) {
                $value = $m[1];
            } else {
                // Kommentar am Zeilenende entfernen
                $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
            }
            $values[$key] = $value;
        }
        return $values;
    }

    public function get(string $key, string $default = ''): string
    {
        $v = $this->values[$key] ?? '';
        return $v === '' ? $default : $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = strtolower($this->get($key));
        if ($v === '') {
            return $default;
        }
        return in_array($v, ['1', 'true', 'yes', 'on', 'ja'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        return $v === '' ? $default : (int) $v;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->get($key)))));
    }

    public function with(string $key, string $value): self
    {
        $clone = clone $this;
        $clone->values[$key] = $value;
        return $clone;
    }

    public function baseDir(): string
    {
        return $this->values['BASE_DIR'];
    }

    public function domain(): string
    {
        return $this->get('APP_DOMAIN', 'kalender.example.de');
    }

    public function baseUrl(): string
    {
        $url = $this->get('APP_URL');
        return rtrim($url !== '' ? $url : 'https://' . $this->domain(), '/');
    }

    public function appName(): string
    {
        return $this->get('APP_NAME', 'Kalender FF KRZ');
    }

    /** Link zum Quellcode (AGPL-3.0 §13); leer = kein Link im Seitenfuß */
    public function sourceUrl(): string
    {
        return trim($this->get('SOURCE_URL', ''));
    }

    public function env(): string
    {
        return $this->get('APP_ENV', 'prod');
    }

    public function storagePath(string $sub = ''): string
    {
        $dir = $this->baseDir() . '/storage';
        return $sub === '' ? $dir : $dir . '/' . ltrim($sub, '/');
    }
}
