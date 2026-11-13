<?php

declare(strict_types=1);

namespace Wehrkalender\Calendar;

use DateTimeImmutable;
use Wehrkalender\Clock;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Erzeugt das VCALENDAR je Termin (gespeichert in events.ics) und setzt den
 * Abo-Feed eines Kalenders aus den gespeicherten Terminen zusammen.
 */
final class IcsBuilder
{
    public const PRODID = '-//Wehrkalender//Wehrkalender//DE';
    public const CANCELLED_PREFIX = 'Abgesagt: ';

    private const VTIMEZONE = "BEGIN:VTIMEZONE\r\n"
        . "TZID:Europe/Berlin\r\n"
        . "X-LIC-LOCATION:Europe/Berlin\r\n"
        . "BEGIN:DAYLIGHT\r\n"
        . "TZOFFSETFROM:+0100\r\n"
        . "TZOFFSETTO:+0200\r\n"
        . "TZNAME:CEST\r\n"
        . "DTSTART:19700329T020000\r\n"
        . "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\n"
        . "END:DAYLIGHT\r\n"
        . "BEGIN:STANDARD\r\n"
        . "TZOFFSETFROM:+0200\r\n"
        . "TZOFFSETTO:+0100\r\n"
        . "TZNAME:CET\r\n"
        . "DTSTART:19701025T030000\r\n"
        . "RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\n"
        . "END:STANDARD\r\n"
        . "END:VTIMEZONE\r\n";

    /**
     * @param array{uid:string,summary:string,location:?string,description:?string,starts_at:string,
     *              ends_at:string,all_day:int|bool,status:string,sequence_no:int,updated_at:string,created_at:string} $event
     *        Zeiten als UTC-DB-Strings; bei ganztägigen Terminen ist ends_at exklusiv (Tag nach dem letzten Tag, 00:00 lokal)
     */
    public function buildEvent(array $event, string $calendarName): string
    {
        $vcal = new VCalendar(['PRODID' => self::PRODID]);
        $cancelled = $event['status'] === 'CANCELLED';
        $summary = $event['summary'];
        if ($cancelled && !str_starts_with($summary, self::CANCELLED_PREFIX)) {
            $summary = self::CANCELLED_PREFIX . $summary;
        }
        $tz = Clock::localTz();
        $start = Clock::fromDb($event['starts_at'])->setTimezone($tz);
        $end = Clock::fromDb($event['ends_at'])->setTimezone($tz);
        $updated = Clock::fromDb($event['updated_at']);

        $vevent = $vcal->add('VEVENT', [
            'UID' => $event['uid'],
            'DTSTAMP' => $updated,
            'LAST-MODIFIED' => $updated,
            'CREATED' => Clock::fromDb($event['created_at']),
            'SEQUENCE' => (int) $event['sequence_no'],
            'SUMMARY' => $summary,
            'STATUS' => $cancelled ? 'CANCELLED' : 'CONFIRMED',
            'TRANSP' => $event['all_day'] ? 'TRANSPARENT' : 'OPAQUE',
            'CLASS' => 'PUBLIC',
            'CATEGORIES' => $calendarName,
        ]);
        if ($event['all_day']) {
            $dtstart = $vevent->add('DTSTART', $start->format('Ymd'));
            $dtstart['VALUE'] = 'DATE';
            $dtend = $vevent->add('DTEND', $end->format('Ymd'));
            $dtend['VALUE'] = 'DATE';
        } else {
            $vevent->add('DTSTART', $start);
            $vevent->add('DTEND', $end);
        }
        if (($event['location'] ?? '') !== '') {
            $vevent->add('LOCATION', $event['location']);
        }
        if (($event['description'] ?? '') !== '') {
            $vevent->add('DESCRIPTION', $event['description']);
        }
        return $this->withTimezone($vcal->serialize(), (bool) $event['all_day']);
    }

    /**
     * Abo-Feed (Outlook, Google) aus den gespeicherten VCALENDAR-Texten.
     *
     * @param list<string> $eventIcs
     */
    public function buildFeed(string $calendarName, string $color, array $eventIcs, ?string $description = null): string
    {
        $out = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . 'PRODID:' . self::PRODID . "\r\n"
            . "CALSCALE:GREGORIAN\r\n"
            . "METHOD:PUBLISH\r\n"
            . $this->fold('X-WR-CALNAME:' . $this->escapeText($calendarName))
            . ($description !== null && $description !== '' ? $this->fold('X-WR-CALDESC:' . $this->escapeText($description)) : '')
            . "X-WR-TIMEZONE:Europe/Berlin\r\n"
            . $this->fold('X-APPLE-CALENDAR-COLOR:' . $color)
            . "REFRESH-INTERVAL;VALUE=DURATION:PT1H\r\n"
            . "X-PUBLISHED-TTL:PT1H\r\n"
            . self::VTIMEZONE;
        foreach ($eventIcs as $ics) {
            if (preg_match('/BEGIN:VEVENT\r?\n.*?END:VEVENT\r?\n/s', $ics, $m)) {
                $out .= str_replace(["\r\n", "\n"], ["\n", "\r\n"], $m[0]);
            }
        }
        return $out . "END:VCALENDAR\r\n";
    }

    /** Prüft, ob ein Text gültiges iCalendar ist (für Tests und Diagnose) */
    public static function parse(string $ics): VCalendar
    {
        /** @var VCalendar $v */
        $v = Reader::read($ics);
        return $v;
    }

    private function withTimezone(string $ics, bool $allDay): string
    {
        if ($allDay) {
            return $ics;
        }
        // VTIMEZONE direkt nach dem Kopf einfügen (vor dem ersten VEVENT)
        return preg_replace('/BEGIN:VEVENT\r\n/', self::VTIMEZONE . "BEGIN:VEVENT\r\n", $ics, 1) ?? $ics;
    }

    private function escapeText(string $s): string
    {
        return addcslashes(str_replace(["\r\n", "\n"], '\\n', $s), ',;\\');
    }

    private function fold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $cut = 75;
            // keine UTF-8-Zeichen zerschneiden
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
        }
        return $out . $line . "\r\n";
    }

    public static function localDateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, Clock::localTz());
    }
}
