<?php

declare(strict_types=1);

namespace Kalender\Web;

use Kalender\Clock;
use Kalender\Config;
use Kalender\Domain\DeviceDetector;
use Kalender\Domain\UsernamePolicy;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class TwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private Config $config)
    {
    }

    public function getGlobals(): array
    {
        return [
            'app_name' => $this->config->appName(),
            'base_url' => $this->config->baseUrl(),
            'domain' => $this->config->domain(),
            'app_env' => $this->config->env(),
            'operator' => $this->config->operator(),
        ];
    }

    public function getFilters(): array
    {
        return [
            // UTC-DB-Zeit in Ortszeit formatieren
            new TwigFilter('lt', fn (?string $v, string $format = 'd.m.Y H:i') => $v ? Clock::toLocal($v)?->format($format) : ''),
            new TwigFilter('device', fn (?string $ua) => DeviceDetector::label($ua)),
            new TwigFilter('duration', fn (?int $s) => $s === null ? '–' : ($s < 60 ? $s . ' s' : intdiv($s, 60) . ' min ' . ($s % 60) . ' s')),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('username_outdated', fn (array $u) => UsernamePolicy::isOutdated($u['username'], $u['first_name'], $u['last_name'])),
            new TwigFunction('username_stem', fn (array $u) => UsernamePolicy::stem($u['first_name'], $u['last_name'])),
        ];
    }
}
