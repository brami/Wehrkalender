<?php

declare(strict_types=1);

namespace Kalender\Web;

/**
 * Liste der mitgelieferten Bibliotheken mit Version und Lizenz für die Seite
 * „Impressum“. Quelle ist vendor/composer/installed.json, das Composer bei
 * jeder Installation schreibt – die Liste ist damit immer auf dem Stand des
 * gebauten Images. Entwicklungswerkzeuge (require-dev) werden ausgelassen.
 */
final class Licenses
{
    /** @return list<array{name:string, version:string, license:string, url:string}> */
    public static function fromInstalled(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }
        // Composer 2: {"packages": [...], "dev-package-names": [...]}; Composer 1: [...]
        $packages = $data['packages'] ?? $data;
        $dev = array_flip($data['dev-package-names'] ?? []);
        $list = [];
        foreach ($packages as $p) {
            if (!is_array($p) || !isset($p['name']) || isset($dev[$p['name']])) {
                continue;
            }
            $url = (string) ($p['homepage'] ?? '');
            if ($url === '' && isset($p['source']['url'])) {
                $url = preg_replace('/\.git$/', '', (string) $p['source']['url']);
            }
            $list[] = [
                'name' => (string) $p['name'],
                'version' => ltrim((string) ($p['version'] ?? ''), 'v'),
                'license' => implode(' / ', (array) ($p['license'] ?? [])) ?: 'unbekannt',
                'url' => str_starts_with($url, 'https://') ? $url : '',
            ];
        }
        usort($list, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $list;
    }
}
