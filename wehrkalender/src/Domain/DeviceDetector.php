<?php

declare(strict_types=1);

namespace Wehrkalender\Domain;

/** Grober Gerätetyp aus dem User-Agent, für Anzeige im Web-Frontend */
final class DeviceDetector
{
    public static function label(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        if ($ua === '') {
            return 'unbekannt';
        }
        $rules = [
            '/DAVx5|DAVdroid|bitfire/i' => 'DAVx⁵ (Android)',
            '/iOS\/|iPhone|iPad|dataaccessd/i' => 'iOS',
            '/macOS\/|Mac OS X.*(CalendarAgent|Calendar\/)|CalendarAgent/i' => 'macOS',
            '/Thunderbird|Lightning/i' => 'Thunderbird',
            '/Microsoft Outlook|Outlook-iOS|Outlook-Android|MSOffice|Microsoft Office/i' => 'Outlook',
            '/Google-Calendar-Importer|Google/i' => 'Google Kalender',
            '/OneCalendar|aCalendar|Etar|Fossify|Simple Calendar/i' => 'Android-Kalender-App',
            '/Android/i' => 'Android',
            '/Windows/i' => 'Windows',
            '/curl|wget|python|Go-http-client/i' => 'Skript/Werkzeug',
        ];
        foreach ($rules as $pattern => $label) {
            if (preg_match($pattern, $ua)) {
                return $label;
            }
        }
        return 'sonstiges Gerät';
    }
}
