<?php

declare(strict_types=1);

namespace Wehrkalender\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Filter einer Befüllung aus einer externen Quelle (calendar_feeds.filter, JSON).
 *
 * Alle Bedingungen müssen zutreffen (UND); Groß-/Kleinschreibung spielt keine
 * Rolle. Ohne Bedingungen und ohne Zeitraum werden alle Termine übernommen.
 *
 *   {"conditions":[{"field":"summary","op":"starts","value":"Maschinist 2/2027"}],
 *    "from":"2027-01-01","to":"2027-12-31"}
 */
final class FeedFilter
{
    public const FIELDS = [
        'summary' => 'Titel',
        'location' => 'Ort',
        'categories' => 'Kategorie',
        'description' => 'Beschreibung',
    ];
    public const OPS = [
        'contains' => 'enthält',
        'not_contains' => 'enthält nicht',
        'starts' => 'beginnt mit',
        'regex' => 'regulärer Ausdruck',
    ];
    public const MAX_CONDITIONS = 5;

    /**
     * @param list<array{field:string,op:string,value:string}> $conditions
     */
    public function __construct(
        public readonly array $conditions = [],
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {
        if (count($conditions) > self::MAX_CONDITIONS) {
            throw new InvalidArgumentException('Höchstens ' . self::MAX_CONDITIONS . ' Bedingungen.');
        }
        foreach ($conditions as $c) {
            if (!isset(self::FIELDS[$c['field']]) || !isset(self::OPS[$c['op']])) {
                throw new InvalidArgumentException('Unbekanntes Feld oder unbekannte Bedingung.');
            }
            if (trim($c['value']) === '') {
                throw new InvalidArgumentException('Bitte bei jeder Bedingung einen Wert angeben.');
            }
            if ($c['op'] === 'regex' && @preg_match(self::regex($c['value']), '') === false) {
                throw new InvalidArgumentException('Der reguläre Ausdruck „' . $c['value'] . '“ ist ungültig.');
            }
        }
        foreach (['von' => $from, 'bis' => $to] as $label => $d) {
            if ($d !== null && DateTimeImmutable::createFromFormat('!Y-m-d', $d) === false) {
                throw new InvalidArgumentException("Datum „{$label}“ bitte als JJJJ-MM-TT angeben.");
            }
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('Der Zeitraum endet vor seinem Beginn.');
        }
    }

    /**
     * Aus Formulardaten: field[], op[], value[] (leere Zeilen werden ignoriert), from, to.
     * @param array<string,mixed> $p
     */
    public static function fromForm(array $p): self
    {
        $conditions = [];
        $fields = (array) ($p['field'] ?? []);
        $ops = (array) ($p['op'] ?? []);
        $values = (array) ($p['value'] ?? []);
        foreach ($values as $i => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $conditions[] = ['field' => (string) ($fields[$i] ?? ''), 'op' => (string) ($ops[$i] ?? ''), 'value' => $value];
        }
        $from = trim((string) ($p['from'] ?? '')) ?: null;
        $to = trim((string) ($p['to'] ?? '')) ?: null;
        return new self($conditions, $from, $to);
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return new self();
        }
        $d = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        return new self(
            array_map(fn ($c) => ['field' => (string) $c['field'], 'op' => (string) $c['op'], 'value' => (string) $c['value']], $d['conditions'] ?? []),
            $d['from'] ?? null,
            $d['to'] ?? null,
        );
    }

    public function toJson(): ?string
    {
        if ($this->isEmpty()) {
            return null;
        }
        return json_encode(array_filter([
            'conditions' => $this->conditions,
            'from' => $this->from,
            'to' => $this->to,
        ], fn ($v) => $v !== null && $v !== []), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function isEmpty(): bool
    {
        return $this->conditions === [] && $this->from === null && $this->to === null;
    }

    /** Lesbare Beschreibung, z. B. „Titel beginnt mit „Maschinist 2/2027“ · 01.01.2027–31.12.2027“ */
    public function describe(): string
    {
        if ($this->isEmpty()) {
            return 'alle Termine';
        }
        $parts = array_map(
            fn ($c) => self::FIELDS[$c['field']] . ' ' . self::OPS[$c['op']] . ' „' . $c['value'] . '“',
            $this->conditions
        );
        if ($this->from !== null || $this->to !== null) {
            $fmt = fn (?string $d) => $d === null ? '…' : DateTimeImmutable::createFromFormat('!Y-m-d', $d)->format('d.m.Y');
            $parts[] = $fmt($this->from) . '–' . $fmt($this->to);
        }
        return implode(' · ', $parts);
    }

    /**
     * Trifft der Termin den Filter? Für den späteren iCal-Importer.
     * Zeitraum: Der Termin muss den Zeitraum berühren (Beginn ≤ bis, Ende ≥ von).
     *
     * @param array{summary?:?string,location?:?string,categories?:?string,description?:?string,
     *              start:DateTimeImmutable,end:DateTimeImmutable} $event
     */
    public function matches(array $event): bool
    {
        foreach ($this->conditions as $c) {
            $text = (string) ($event[$c['field']] ?? '');
            $ok = match ($c['op']) {
                'contains' => mb_stripos($text, $c['value']) !== false,
                'not_contains' => mb_stripos($text, $c['value']) === false,
                'starts' => mb_stripos(ltrim($text), $c['value']) === 0,
                'regex' => preg_match(self::regex($c['value']), $text) === 1,
            };
            if (!$ok) {
                return false;
            }
        }
        $tz = $event['start']->getTimezone();
        if ($this->from !== null && $event['end'] < new DateTimeImmutable($this->from . ' 00:00:00', $tz)) {
            return false;
        }
        if ($this->to !== null && $event['start'] >= (new DateTimeImmutable($this->to . ' 00:00:00', $tz))->modify('+1 day')) {
            return false;
        }
        return true;
    }

    private static function regex(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~iu';
    }
}
