<?php

declare(strict_types=1);

class CronSchedule
{
    private const RANGES = [
        'minutes' => [0, 59],
        'hours'   => [0, 23],
        'dom'     => [1, 31],
        'month'   => [1, 12],
        'dow'     => [0, 6],
    ];

    private const MONTH_EN = ['', 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];

    private const MONTH_PL = ['', 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec',
        'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    private const DOW_EN = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    private const DOW_PL       = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
    private const DOW_PL_GEN   = ['niedzieli', 'poniedziałku', 'wtorku', 'środy', 'czwartku', 'piątku', 'soboty'];

    /** @var array<string, array{expr: string, values: int[]}> */
    private array $fields;

    public function __construct(private readonly string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            throw new \InvalidArgumentException(
                sprintf('Expression must have 5 fields, got %d', count($parts))
            );
        }

        $names = array_keys(self::RANGES);
        foreach ($parts as $i => $part) {
            $name = $names[$i];
            [$min, $max] = self::RANGES[$name];
            $this->fields[$name] = [
                'expr'   => $part,
                'values' => $this->parseField($part, $min, $max, $name),
            ];
        }
    }

    /** @return int[] */
    private function parseField(string $field, int $min, int $max, string $name): array
    {
        $values = [];

        foreach (explode(',', $field) as $segment) {
            $segment = trim($segment);

            if ($segment === '*') {
                for ($i = $min; $i <= $max; $i++) {
                    $values[] = $i;
                }
                continue;
            }

            if (str_contains($segment, '/')) {
                [$range, $step] = explode('/', $segment, 2);
                $step = (int) $step;
                if ($step < 1) {
                    throw new \InvalidArgumentException("Step must be >= 1 in field '$name'");
                }

                if ($range === '*') {
                    [$start, $end] = [$min, $max];
                } elseif (str_contains($range, '-')) {
                    [$start, $end] = array_map('intval', explode('-', $range, 2));
                } else {
                    $start = (int) $range;
                    $end   = $max;
                }

                for ($i = $start; $i <= $end; $i += $step) {
                    $values[] = $i;
                }
                continue;
            }

            if (str_contains($segment, '-')) {
                [$start, $end] = array_map('intval', explode('-', $segment, 2));
                if ($start > $end) {
                    throw new \InvalidArgumentException(
                        "Range start > end in field '$name': $start-$end"
                    );
                }
                for ($i = $start; $i <= $end; $i++) {
                    $values[] = $i;
                }
                continue;
            }

            if (!is_numeric($segment)) {
                throw new \InvalidArgumentException(
                    "Invalid value '$segment' in field '$name'"
                );
            }

            $values[] = (int) $segment;
        }

        foreach ($values as $v) {
            if ($v < $min || $v > $max) {
                throw new \InvalidArgumentException(
                    sprintf('%s value %d out of range %d–%d', ucfirst($name), $v, $min, $max)
                );
            }
        }

        return array_values(array_unique($values));
    }

    /** @return \DateTimeImmutable[] */
    public function nextRuns(int $count = 10, string $timezone = 'Europe/Warsaw'): array
    {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Unknown timezone: '$timezone'");
        }

        $now     = new \DateTimeImmutable('now', $tz);
        $current = $now
            ->setTime((int) $now->format('H'), (int) $now->format('i'), 0)
            ->modify('+1 minute');

        $runs  = [];
        $limit = 527040; // 366 days × 1440 minutes

        for ($i = 0; $i < $limit && count($runs) < $count; $i++) {
            if (
                in_array((int) $current->format('n'), $this->fields['month']['values'], true) &&
                in_array((int) $current->format('j'), $this->fields['dom']['values'], true)   &&
                in_array((int) $current->format('w'), $this->fields['dow']['values'], true)   &&
                in_array((int) $current->format('G'), $this->fields['hours']['values'], true) &&
                in_array((int) $current->format('i'), $this->fields['minutes']['values'], true)
            ) {
                $runs[] = $current;
            }

            $current = $current->modify('+1 minute');
        }

        return $runs;
    }

    public function explain(string $locale = 'en'): string
    {
        $pl  = $locale === 'pl';
        $min = $this->fields['minutes']['expr'];
        $hr  = $this->fields['hours']['expr'];
        $dom = $this->fields['dom']['expr'];
        $mon = $this->fields['month']['expr'];
        $dow = $this->fields['dow']['expr'];

        // Pure wildcard
        if ($min === '*' && $hr === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
            return $pl ? 'Co minutę' : 'Every minute';
        }

        $parts = [];

        // Time clause: minute + hour combined
        $parts[] = $this->explainTime($min, $hr, $pl);

        if ($dom !== '*') {
            $parts[] = $this->explainDom($dom, $pl);
        }
        if ($mon !== '*') {
            $parts[] = $this->explainMonth($mon, $pl);
        }
        if ($dow !== '*') {
            $parts[] = $this->explainDow($dow, $pl);
        }

        return ucfirst(implode(', ', array_filter($parts)));
    }

    private function explainTime(string $minExpr, string $hrExpr, bool $pl): string
    {
        // Specific minute + specific hour → "at HH:MM"
        if (is_numeric($minExpr) && is_numeric($hrExpr)) {
            $time = sprintf('%02d:%02d', (int) $hrExpr, (int) $minExpr);
            return $pl ? "o $time" : "at $time";
        }

        // Every N minutes
        if (preg_match('/^\*\/(\d+)$/', $minExpr, $m)) {
            $n    = (int) $m[1];
            $freq = $pl
                ? "co $n " . $this->plForm($n, 'minutę', 'minuty', 'minut')
                : "every $n minute" . ($n !== 1 ? 's' : '');

            if ($hrExpr === '*') {
                return $freq;
            }
            return "$freq, " . $this->formatHourRange($hrExpr, $pl);
        }

        // Minute 0 + wildcard hour
        if ($minExpr === '0' && $hrExpr === '*') {
            return $pl ? 'o pełnej każdej godziny' : 'at the start of every hour';
        }

        // Minute 0 + specific/range hour
        if ($minExpr === '0') {
            return $this->formatHourRange($hrExpr, $pl);
        }

        // Wildcard minute + specific hour
        if ($minExpr === '*' && $hrExpr !== '*') {
            $hourStr = $this->formatHourRange($hrExpr, $pl);
            return $pl ? "co minutę $hourStr" : "every minute $hourStr";
        }

        // Fallback: compose both
        $parts = [];
        if ($minExpr !== '*') {
            $parts[] = $pl ? "w minucie $minExpr" : "at minute $minExpr";
        }
        if ($hrExpr !== '*') {
            $parts[] = $this->formatHourRange($hrExpr, $pl);
        }
        return implode(', ', $parts) ?: ($pl ? 'co minutę' : 'every minute');
    }

    private function formatHourRange(string $expr, bool $pl): string
    {
        if (is_numeric($expr)) {
            $time = sprintf('%02d:00', (int) $expr);
            return $pl ? "o $time" : "at $time";
        }

        if (preg_match('/^(\d+)-(\d+)$/', $expr, $m)) {
            $from = sprintf('%02d:00', (int) $m[1]);
            $to   = sprintf('%02d:00', (int) $m[2]);
            return $pl ? "między $from a $to" : "from $from through $to";
        }

        if (preg_match('/^\*\/(\d+)$/', $expr, $m)) {
            $n = (int) $m[1];
            return $pl
                ? "co $n " . $this->plForm($n, 'godzinę', 'godziny', 'godzin')
                : "every $n hour" . ($n !== 1 ? 's' : '');
        }

        // Comma list
        $times = array_map(fn($h) => sprintf('%02d:00', (int) $h), explode(',', $expr));
        $last  = array_pop($times);
        return $pl
            ? 'o ' . implode(', ', $times) . " i $last"
            : 'at ' . implode(', ', $times) . " and $last";
    }

    private function explainDom(string $expr, bool $pl): string
    {
        if (is_numeric($expr)) {
            return $pl ? (int)$expr . '. dnia miesiąca' : 'on day ' . (int)$expr . ' of the month';
        }
        if (preg_match('/^(\d+)-(\d+)$/', $expr, $m)) {
            return $pl
                ? "od {$m[1]}. do {$m[2]}. dnia miesiąca"
                : "on days {$m[1]} through {$m[2]} of the month";
        }
        return $pl ? 'w wybrane dni miesiąca' : 'on selected days of the month';
    }

    private function explainMonth(string $expr, bool $pl): string
    {
        $names = $pl ? self::MONTH_PL : self::MONTH_EN;

        if (is_numeric($expr)) {
            $name = $names[(int) $expr];
            return $pl ? "w $name" : "in $name";
        }
        if (preg_match('/^(\d+)-(\d+)$/', $expr, $m)) {
            return $pl
                ? "od {$names[(int)$m[1]]} do {$names[(int)$m[2]]}"
                : "from {$names[(int)$m[1]]} through {$names[(int)$m[2]]}";
        }
        if (str_contains($expr, ',')) {
            $list = array_map(fn($n) => $names[(int) $n], explode(',', $expr));
            $last = array_pop($list);
            return $pl
                ? 'w ' . implode(', ', $list) . " i $last"
                : 'in ' . implode(', ', $list) . " and $last";
        }
        return '';
    }

    private function explainDow(string $expr, bool $pl): string
    {
        $names = $pl ? self::DOW_PL : self::DOW_EN;

        if (is_numeric($expr)) {
            $day = $names[(int) $expr];
            return $pl ? "w $day" : "on {$day}s";
        }
        if (preg_match('/^(\d+)-(\d+)$/', $expr, $m)) {
            $gen = $pl ? self::DOW_PL_GEN : $names;
            return $pl
                ? "od {$gen[(int)$m[1]]} do {$gen[(int)$m[2]]}"
                : "{$names[(int)$m[1]]} through {$names[(int)$m[2]]}";
        }
        if (str_contains($expr, ',')) {
            $list = array_map(fn($n) => $names[(int) $n], explode(',', $expr));
            $last = array_pop($list);
            return $pl
                ? 'w ' . implode(', ', $list) . " i $last"
                : 'on ' . implode(', ', $list) . " and $last";
        }
        return '';
    }

    private function plForm(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) return $one;
        $mod10  = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 > 20)) return $few;
        return $many;
    }
}
