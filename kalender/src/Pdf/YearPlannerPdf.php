<?php

declare(strict_types=1);

namespace Kalender\Pdf;

use DateTimeImmutable;
use FPDF;
use Kalender\Calendar\NrwHolidays;
use Kalender\Calendar\NrwSchoolHolidays;

/**
 * Terminübersicht als PDF: A4 quer, eine Spalte je Monat, 31 Zeilen.
 *
 * Je Termin ein Oval in der Kalenderfarbe mit der Startzeit darin (ganztägig:
 * leeres Oval). Passen nicht alle Termine eines Tages als Oval in die Zelle,
 * zeigt die Zelle nur farbige Punkte. Abgesagte Termine: leerer Kreis bzw.
 * leeres Oval mit farbigem Rand. Wochenenden und Feiertage grau, Schulferien
 * NRW als Streifen am linken Zellrand.
 */
final class YearPlannerPdf extends FPDF
{
    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli',
        'August', 'September', 'Oktober', 'November', 'Dezember'];
    private const WEEKDAYS = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    private const MARGIN = 8.0;
    private const GRID = [205, 209, 214];
    private const WEEKEND = [236, 237, 239];
    private const HOLIDAY = [214, 216, 220];
    private const FERIEN = [96, 165, 250];
    private const HEAD = [185, 28, 28];
    private const TEXT = [31, 35, 40];
    private const MUTED = [91, 100, 112];

    /** @var array<string,mixed> */
    private array $plan;
    private string $footerNote = '';

    /**
     * @param array<string,mixed> $plan Ergebnis von YearPlanner::build()
     */
    public static function render(array $plan, string $ownerName, DateTimeImmutable $now, string $appName, int $pages = 1): string
    {
        $pdf = new self('L', 'mm', 'A4');
        $pdf->plan = $plan;
        $pdf->SetCreator($appName, true);
        $pdf->SetAuthor($appName, true);
        $pdf->SetTitle(self::rangeTitle($plan['start'], $plan['end']), true);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(self::MARGIN, self::MARGIN);
        $months = (int) $plan['months'];
        $pages = max(1, min($pages, 2, $months));
        $perPage = (int) ceil($months / $pages);
        for ($p = 0; $p < $pages; $p++) {
            $pdf->AddPage();
            $pdf->draw(
                $ownerName, $now, $appName,
                $plan['start']->modify('+' . ($p * $perPage) . ' months'),
                min($perPage, $months - $p * $perPage),
                $perPage,
                $pages > 1 ? 'Seite ' . ($p + 1) . ' von ' . $pages : ''
            );
        }
        return $pdf->Output('S');
    }

    public static function rangeTitle(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        $last = $end->modify('-1 day');
        $a = self::MONTHS[(int) $start->format('n') - 1] . ' ' . $start->format('Y');
        $b = self::MONTHS[(int) $last->format('n') - 1] . ' ' . $last->format('Y');
        return 'Termine ' . ($a === $b ? $a : "$a – $b");
    }

    public static function fileName(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        $last = $end->modify('-1 day');
        return 'termine-' . $start->format('Y-m') . ($start->format('Y-m') === $last->format('Y-m') ? '' : '-bis-' . $last->format('Y-m')) . '.pdf';
    }

    // ------------------------------------------------------------ Zeichnen

    private function draw(string $owner, DateTimeImmutable $now, string $appName,
        DateTimeImmutable $pageStart, int $months, int $columns, string $pageLabel): void
    {
        /** @var DateTimeImmutable $start */
        $start = $this->plan['start'];
        /** @var DateTimeImmutable $end */
        $end = $this->plan['end'];
        $pageW = $this->GetPageWidth();
        $pageH = $this->GetPageHeight();
        $m = self::MARGIN;
        $innerW = $pageW - 2 * $m;

        // Kopf
        $this->SetTextColor(...self::TEXT);
        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY($m, $m);
        $this->Cell($innerW * 0.7, 6, $this->t(self::rangeTitle($start, $end)));
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(...self::MUTED);
        $this->SetXY($m + $innerW * 0.7, $m);
        $this->Cell($innerW * 0.3, 6, $this->t($pageLabel !== '' ? "$owner · $pageLabel" : $owner), 0, 0, 'R');

        // Fußzeile zuerst vermessen
        $legendH = $this->legendHeight($innerW);
        $footerH = $legendH + 5.0;
        $gridTop = $m + 8.0;
        $monthHeadH = 6.0;
        $gridBottom = $pageH - $m - $footerH;
        $rowH = ($gridBottom - $gridTop - $monthHeadH) / 31;
        $colW = $innerW / $columns;

        $lastDay = $end->modify('-1 day')->format('Y-m-d');
        $ferienKnown = NrwSchoolHolidays::covers($start->format('Y-m-d'), $lastDay);

        $month = $pageStart;
        for ($c = 0; $c < $months; $c++, $month = $month->modify('+1 month')) {
            $x = $m + $c * $colW;
            // Monatskopf
            $this->SetFillColor(...self::HEAD);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Helvetica', 'B', $columns > 8 ? 7.5 : 9);
            $this->SetXY($x, $gridTop);
            $label = self::MONTHS[(int) $month->format('n') - 1] . ($columns > 8 ? ' ' . $month->format('y') : ' ' . $month->format('Y'));
            $this->Cell($colW, $monthHeadH, $this->t($label), 0, 0, 'C', true);

            $daysInMonth = (int) $month->format('t');
            for ($d = 1; $d <= 31; $d++) {
                $y = $gridTop + $monthHeadH + ($d - 1) * $rowH;
                if ($d > $daysInMonth) {
                    $this->SetFillColor(250, 250, 251);
                    $this->SetDrawColor(...self::GRID);
                    $this->SetLineWidth(0.15);
                    $this->Rect($x, $y, $colW, $rowH, 'DF');
                    continue;
                }
                $this->drawDay($month->setDate((int) $month->format('Y'), (int) $month->format('n'), $d), $x, $y, $colW, $rowH);
            }
        }

        // Rahmen um Monate
        $this->SetDrawColor(150, 155, 162);
        $this->SetLineWidth(0.3);
        for ($c = 0; $c <= $months; $c++) {
            $x = $m + $c * $colW;
            $this->Line($x, $gridTop, $x, $gridBottom);
        }
        $this->Line($m, $gridBottom, $m + $months * $colW, $gridBottom);

        $this->footerNote = $ferienKnown ? '' : 'Schulferien NRW sind nur von '
            . (new DateTimeImmutable(NrwSchoolHolidays::COVERED_FROM))->format('d.m.Y') . ' bis '
            . (new DateTimeImmutable(NrwSchoolHolidays::COVERED_UNTIL))->format('d.m.Y') . ' hinterlegt.';
        $this->drawLegend($m, $gridBottom + 2.0, $innerW);

        // letzte Zeile: Stand
        $this->SetFont('Helvetica', '', 6.5);
        $this->SetTextColor(...self::MUTED);
        $this->SetXY($m, $pageH - $m - 3.0);
        $note = 'Stand: ' . $now->format('d.m.Y, H:i') . ' Uhr · ' . $appName
            . ' · Termine werden in MP Feuer gepflegt; aktuelle Angaben auf Smartphone, Tablet bzw. in Outlook.';
        $this->Cell($innerW * 0.75, 3, $this->t($note));
        if ($this->footerNote !== '') {
            $this->SetXY($m + $innerW * 0.75, $pageH - $m - 3.0);
            $this->Cell($innerW * 0.25, 3, $this->t($this->footerNote), 0, 0, 'R');
        }
    }

    private function drawDay(DateTimeImmutable $date, float $x, float $y, float $w, float $h): void
    {
        $key = $date->format('Y-m-d');
        $dow = (int) $date->format('N');
        $holiday = NrwHolidays::get($key);
        $ferien = NrwSchoolHolidays::get($key);

        $fill = [255, 255, 255];
        if ($holiday !== null) {
            $fill = self::HOLIDAY;
        } elseif ($dow >= 6) {
            $fill = self::WEEKEND;
        }
        $this->SetFillColor(...$fill);
        $this->SetDrawColor(...self::GRID);
        $this->SetLineWidth(0.15);
        $this->Rect($x, $y, $w, $h, 'DF');

        if ($ferien !== null) {
            $this->SetFillColor(...self::FERIEN);
            $this->Rect($x + 0.15, $y + 0.15, 0.9, $h - 0.3, 'F');
        }

        // Tag und Wochentag
        $numW = 3.6;
        $wdW = 3.7;
        $this->SetTextColor(...self::TEXT);
        $this->SetFont('Helvetica', $dow >= 6 || $holiday ? 'B' : '', 6.5);
        $this->SetXY($x + 1.0, $y);
        $this->Cell($numW, $h, (string) (int) $date->format('j'), 0, 0, 'R');
        $this->SetFont('Helvetica', '', 5.5);
        $this->SetTextColor(...self::MUTED);
        $this->Cell($wdW, $h, self::WEEKDAYS[$dow]);

        $areaX = $x + 1.0 + $numW + $wdW + 0.3;
        $areaW = $x + $w - 0.6 - $areaX;
        $used = $this->drawEntries($this->plan['days'][$key] ?? [], $areaX, $y, $areaW, $h);

        // Kurzname des Feiertags, falls noch Platz ist
        if ($holiday !== null) {
            $this->SetFont('Helvetica', 'I', 4.8);
            $txt = $this->t($holiday[1]);
            $tw = $this->GetStringWidth($txt);
            if ($areaW - $used >= $tw + 0.8) {
                $this->SetTextColor(...self::MUTED);
                $margin = $this->cMargin;
                $this->cMargin = 0;
                $this->SetXY($x + $w - 0.6 - $tw, $y);
                $this->Cell($tw, $h, $txt);
                $this->cMargin = $margin;
            }
        }
    }

    /**
     * @param list<array{time:?string,color:string,cancelled:bool}> $entries
     * @return float benötigte Breite
     */
    private function drawEntries(array $entries, float $x, float $y, float $w, float $h): float
    {
        if ($entries === []) {
            return 0.0;
        }
        $gap = 0.6;
        $pillH = min($h - 1.3, 3.8);
        $fontSize = $pillH >= 3.4 ? 5.6 : 5.0;
        $this->SetFont('Helvetica', 'B', $fontSize);
        $widths = [];
        foreach ($entries as $e) {
            $widths[] = $e['time'] !== null ? $this->GetStringWidth($e['time']) + 1.6 : $pillH * 1.5;
        }
        $total = array_sum($widths) + $gap * (count($widths) - 1);
        $cy = $y + ($h - $pillH) / 2;

        if ($total <= $w) {
            $cx = $x;
            foreach ($entries as $i => $e) {
                $this->pill($cx, $cy, $widths[$i], $pillH, $e['color'], $e['cancelled'], $e['time']);
                $cx += $widths[$i] + $gap;
            }
            return $total;
        }

        // nur Punkte
        $dot = min($pillH * 0.75, 2.6);
        $dGap = 0.5;
        $dy = $y + ($h - $dot) / 2;
        $fit = (int) floor(($w + $dGap) / ($dot + $dGap));
        $count = count($entries);
        $show = $count;
        if ($count > $fit) {
            // Platz für "+n" freihalten
            $this->SetFont('Helvetica', 'B', 5.0);
            $more = $this->GetStringWidth('+' . $count) + 0.4;
            $show = max(0, (int) floor(($w - $more + $dGap) / ($dot + $dGap)));
        }
        $cx = $x;
        for ($i = 0; $i < $show; $i++) {
            $this->pill($cx, $dy, $dot, $dot, $entries[$i]['color'], $entries[$i]['cancelled'], null);
            $cx += $dot + $dGap;
        }
        if ($show < $count) {
            $this->SetFont('Helvetica', 'B', 5.0);
            $this->SetTextColor(...self::TEXT);
            $txt = '+' . ($count - $show);
            $this->SetXY($cx - 0.2, $y);
            $this->Cell($this->GetStringWidth($txt) + 0.4, $h, $txt);
            $cx += $this->GetStringWidth($txt) + 0.4;
        }
        return $cx - $x;
    }

    private function pill(float $x, float $y, float $w, float $h, string $color, bool $cancelled, ?string $text): void
    {
        [$r, $g, $b] = self::rgb($color);
        $this->SetDrawColor($r, $g, $b);
        if ($cancelled) {
            $this->SetLineWidth(0.3);
            $this->SetFillColor(255, 255, 255);
            $this->roundedRect($x + 0.15, $y + 0.15, $w - 0.3, $h - 0.3, ($h - 0.3) / 2, 'DF');
        } else {
            $this->SetLineWidth(0.1);
            $this->SetFillColor($r, $g, $b);
            $this->roundedRect($x, $y, $w, $h, $h / 2, 'DF');
        }
        if ($text !== null) {
            if ($cancelled) {
                $this->SetTextColor(...self::MUTED);
            } else {
                $this->SetTextColor(...(self::isLight($r, $g, $b) ? [20, 20, 20] : [255, 255, 255]));
            }
            $this->SetXY($x, $y);
            $this->Cell($w, $h, $text, 0, 0, 'C');
        }
    }

    // ------------------------------------------------------------ Legende

    /** @return list<array{type:string,label:string,color?:string}> */
    private function legendItems(): array
    {
        $items = [];
        foreach ($this->plan['calendars'] as $c) {
            $items[] = ['type' => 'cal', 'label' => $c['display_name'], 'color' => $c['color']];
        }
        $items[] = ['type' => 'sep', 'label' => ''];
        $items[] = ['type' => 'cancelled', 'label' => 'abgesagt'];
        $items[] = ['type' => 'allday', 'label' => 'ganztägig'];
        $items[] = ['type' => 'weekend', 'label' => 'Wochenende'];
        $items[] = ['type' => 'holiday', 'label' => 'Feiertag NRW'];
        $items[] = ['type' => 'ferien', 'label' => 'Schulferien NRW'];
        $items[] = ['type' => 'dots', 'label' => 'Punkte: zu viele Termine für Uhrzeiten'];
        return $items;
    }

    private function itemWidth(array $it): float
    {
        if ($it['type'] === 'sep') {
            return 3.0;
        }
        $this->SetFont('Helvetica', $it['type'] === 'cal' ? 'B' : '', 7);
        return 5.0 + $this->GetStringWidth($this->t($it['label'])) + 4.0;
    }

    private function legendHeight(float $w): float
    {
        $lines = 1;
        $cx = 0.0;
        foreach ($this->legendItems() as $it) {
            $iw = $this->itemWidth($it);
            if ($cx > 0 && $cx + $iw > $w) {
                $lines++;
                $cx = 0.0;
            }
            $cx += $iw;
        }
        return $lines * 4.2;
    }

    private function drawLegend(float $x, float $y, float $w): void
    {
        $cx = 0.0;
        $lineH = 4.2;
        $cy = $y;
        foreach ($this->legendItems() as $it) {
            $iw = $this->itemWidth($it);
            if ($cx > 0 && $cx + $iw > $w) {
                $cx = 0.0;
                $cy += $lineH;
            }
            $sx = $x + $cx;
            $sy = $cy + 0.6;
            switch ($it['type']) {
                case 'cal':
                    $this->pill($sx, $sy, 4.0, 3.0, $it['color'], false, null);
                    break;
                case 'cancelled':
                    $this->pill($sx, $sy, 4.0, 3.0, '#6b7280', true, null);
                    break;
                case 'allday':
                    $this->pill($sx, $sy, 4.5, 3.0, '#6b7280', false, null);
                    break;
                case 'weekend':
                case 'holiday':
                    $this->SetFillColor(...($it['type'] === 'weekend' ? self::WEEKEND : self::HOLIDAY));
                    $this->SetDrawColor(...self::GRID);
                    $this->SetLineWidth(0.15);
                    $this->Rect($sx, $sy, 4.0, 3.0, 'DF');
                    break;
                case 'ferien':
                    $this->SetFillColor(255, 255, 255);
                    $this->SetDrawColor(...self::GRID);
                    $this->SetLineWidth(0.15);
                    $this->Rect($sx, $sy, 4.0, 3.0, 'DF');
                    $this->SetFillColor(...self::FERIEN);
                    $this->Rect($sx + 0.15, $sy + 0.15, 0.9, 2.7, 'F');
                    break;
                case 'dots':
                    $this->pill($sx, $sy + 0.4, 2.2, 2.2, '#6b7280', false, null);
                    break;
                case 'sep':
                    $cx += $iw;
                    continue 2;
            }
            $this->SetFont('Helvetica', $it['type'] === 'cal' ? 'B' : '', 7);
            $this->SetTextColor(...($it['type'] === 'cal' ? self::TEXT : self::MUTED));
            $this->SetXY($sx + 5.0, $cy);
            $this->Cell($iw - 5.0, $lineH, $this->t($it['label']));
            $cx += $iw;
        }
    }

    // ------------------------------------------------------------ Hilfen

    /** UTF-8 → Windows-1252 für die Standardschriften von FPDF */
    private function t(string $s): string
    {
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function isLight(int $r, int $g, int $b): bool
    {
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 165;
    }

    /** Rechteck mit runden Ecken; r = h/2 ergibt Oval bzw. Kreis */
    private function roundedRect(float $x, float $y, float $w, float $h, float $r, string $style): void
    {
        $k = $this->k;
        $hp = $this->h;
        $op = match ($style) {
            'F' => 'f',
            'FD', 'DF' => 'B',
            default => 'S',
        };
        $r = min($r, $w / 2, $h / 2);
        $a = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->arc($xc + $r * $a, $yc - $r, $xc + $r, $yc - $r * $a, $xc + $r, $yc);
        $xc = $x + $w - $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->arc($xc + $r, $yc + $r * $a, $xc + $r * $a, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->arc($xc - $r * $a, $yc + $r, $xc - $r, $yc + $r * $a, $xc - $r, $yc);
        $xc = $x + $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->arc($xc - $r, $yc - $r * $a, $xc - $r * $a, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    private function arc(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void
    {
        $h = $this->h;
        $k = $this->k;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $k, ($h - $y1) * $k, $x2 * $k, ($h - $y2) * $k, $x3 * $k, ($h - $y3) * $k));
    }
}
