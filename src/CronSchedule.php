<?php 

declare(strict_types=1);

class CronSchedule
{
    private const RANGES = [
        'minutes' => [0, 59],
        'hours' => [0, 23],
        'dom' => [1, 31],
        'month' => [1, 12],
        'dow' => [0, 6],
    ];

    /** @var array<string, array{expr: string, values: int[]}> */
    private array $fields;

    public function __construct(
        private readonly string $expression
    ) {
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
                'expr' => $part,
                'values' => $this->parseField($part, $min, $max, $name),
            ];
        }
    }

    /** @return int[] */
    private function parseField(string $field, int $min, int $max, string $name): array
    {
        $values = [];
        foreach (explode(',', $field) as $segment) {
            $values = array_merge($values, $this->parseSegment(trim($segment), $min, $max, $name));
        }
        $this->validateBounds($values, $min, $max, $name);
        return array_values(array_unique($values));
    }

    /** @return int[] */
    private function parseSegment(string $segment, int $min, int $max, string $name): array
    {
        if ($segment === '*') {
            return range($min, $max);
        }
        if (str_contains($segment, '/')) {
            return $this->parseStep($segment, $min, $max, $name);
        }
        if (str_contains($segment, '-')) {
            return $this->parseRange($segment, $name);
        }
        if (!is_numeric($segment)) {
            throw new \InvalidArgumentException("Invalid value '$segment' in field '$name'");
        }
        return [(int) $segment];
    }

    /** @return int[] */
    private function parseStep(string $segment, int $min, int $max, string $name): array
    {
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
        return range($start, $end, $step);
    }

    /** @return int[] */
    private function parseRange(string $segment, string $name): array
    {
        [$start, $end] = array_map('intval', explode('-', $segment, 2));
        if ($start > $end) {
            throw new \InvalidArgumentException("Range start > end in field '$name': $start-$end");
        }
        return range($start, $end);
    }

    /** @param int[] $values */
    private function validateBounds(array $values, int $min, int $max, string $name): void
    {
        foreach ($values as $v) {
            if ($v < $min || $v > $max) {
                throw new \InvalidArgumentException(
                    sprintf('%s value %d out of range %d-%d', ucfirst($name), $v, $min, $max)
                );
            }
        }
    }

    /**
     * @return \DateTimeImmutable[]
     */
    public function nextRuns(int $count = 10, string $timezone = 'Europe/Warsaw'): array
    {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Unknown timezone: '$timezone'");
        }

        $now = new \DateTimeImmutable('now', $tz);
        $current = $now
            ->setTime((int) $now->format('H'), (int) $now->format('i'), 0)
            ->modify('+1 minute');

        $runs = [];
        $limit = 527040;  // 366 days × 1440 minutes

        for ($i = 0; $i < $limit && count($runs) < $count; $i++) {
            if (
                in_array((int) $current->format('n'), $this->fields['month']['values'], true) &&
                in_array((int) $current->format('j'), $this->fields['dom']['values'], true) &&
                in_array((int) $current->format('w'), $this->fields['dow']['values'], true) &&
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
        $langFile = __DIR__ . '/../lang/' . $locale . '.php';
        $t = file_exists($langFile) ? require $langFile : require __DIR__ . '/../lang/en.php';

        $min = $this->fields['minutes']['expr'];
        $hr = $this->fields['hours']['expr'];
        $dom = $this->fields['dom']['expr'];
        $mon = $this->fields['month']['expr'];
        $dow = $this->fields['dow']['expr'];

        if ($min === '*' && $hr === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
            return $t['every_minute'];
        }

        $parts = [];

        $parts[] = $this->explainTime($min, $hr, $t);

        if ($dom !== '*')
            $parts[] = $this->explainDom($dom, $t);
        if ($mon !== '*')
            $parts[] = $this->explainMonth($mon, $t);
        if ($dow !== '*')
            $parts[] = $this->explainDow($dow, $t);

        return ucfirst(implode(', ', array_filter($parts)));
    }

    /** @param array<string, mixed> $t */
    private function explainTime(string $minExpr, string $hrExpr, array $t): string
    {
        if (is_numeric($minExpr) && is_numeric($hrExpr)) {
            return $this->explainExactTime($minExpr, $hrExpr, $t);
        }
        if (preg_match('/^\*\/(\d+)$/', $minExpr, $m)) {
            return $this->explainSteppedMinutes((int) $m[1], $hrExpr, $t);
        }
        if ($minExpr === '0') {
            return $this->explainOnTheHour($hrExpr, $t);
        }
        if ($minExpr === '*' && $hrExpr !== '*') {
            return sprintf($t['every_min_during'], $this->formatHourRange($hrExpr, $t));
        }
        return $this->explainFallbackTime($minExpr, $hrExpr, $t);
    }

    /** @param array<string, mixed> $t */
    private function explainExactTime(string $minExpr, string $hrExpr, array $t): string
    {
        $time = sprintf('%02d:%02d', (int) $hrExpr, (int) $minExpr);
        return sprintf($t['at_time'], $time);
    }

    /** @param array<string, mixed> $t */
    private function explainSteppedMinutes(int $step, string $hrExpr, array $t): string
    {
        $freq = sprintf($t['every_n_minutes'], $step, $this->plural($step, $t['minute_forms']));
        if ($hrExpr === '*') {
            return $freq;
        }
        return $freq . ', ' . $this->formatHourRange($hrExpr, $t);
    }

    /** @param array<string, mixed> $t */
    private function explainOnTheHour(string $hrExpr, array $t): string
    {
        if ($hrExpr === '*') {
            return $t['at_start_of_hour'];
        }
        return $this->formatHourRange($hrExpr, $t);
    }

    /** @param array<string, mixed> $t */
    private function explainFallbackTime(string $minExpr, string $hrExpr, array $t): string
    {
        $parts = [];
        if ($minExpr !== '*') {
            $parts[] = sprintf($t['at_minute'], (int) $minExpr);
        }
        if ($hrExpr !== '*') {
            $parts[] = $this->formatHourRange($hrExpr, $t);
        }
        return implode(', ', $parts) ?: $t['every_minute'];
    }

    /**
     * @param array<string, mixed> $t
     */
    private function formatHourRange(string $expr, array $t): string
    {
        if (is_numeric($expr)) {
            return sprintf($t['at_time'], sprintf('%02d:00', (int) $expr));
        }

        if (preg_match('/^(\d+)-(\d+)$/', $expr, $m)) {
            return sprintf($t['time_range'],
                sprintf('%02d:00', (int) $m[1]),
                sprintf('%02d:00', (int) $m[2]));
        }

        if (preg_match('/^\*\/(\d+)$/', $expr, $m)) {
            $n = (int) $m[1];
            return sprintf($t['every_n_hours'], $n, $this->plural($n, $t['hour_forms']));
        }

        $times = array_map(fn($h) => sprintf('%02d:00', (int) $h), explode(',', $expr));
        $last = array_pop($times);
        return sprintf($t['time_list'], implode(', ', $times), $last);
    }

    /**
     * @param array<string, mixed> $t
     */
    private function explainDom(string $expr, array $t): string
    {
        if (is_numeric($expr)) {
            return sprintf($t['dom_day'], (int) $expr);
        }
        if (preg_match('/^(\d+)-(\d+)$/', $expr, $m)) {
            return sprintf($t['dom_range'], (int) $m[1], (int) $m[2]);
        }
        return $t['dom_select'];
    }

    /**
     * @param array<string, mixed> $t
     */
    private function explainMonth(string $expr, array $t): string
    {
        if (is_numeric($expr)) {
            return sprintf($t['month_in'], $t['months'][(int) $expr]);
        }
        if (preg_match('/^(\d+)-(\d+)$/', $expr, $m)) {
            return sprintf($t['month_range'], $t['months'][(int) $m[1]], $t['months'][(int) $m[2]]);
        }
        if (str_contains($expr, ',')) {
            $list = array_map(fn($n) => $t['months'][(int) $n], explode(',', $expr));
            $last = array_pop($list);
            return sprintf($t['month_list'], implode(', ', $list), $last);
        }
        return '';
    }

    /**
     * @param array<string, mixed> $t
     */
    private function explainDow(string $expr, array $t): string
    {
        if (is_numeric($expr)) {
            return sprintf($t['dow_single'], $t['dow'][(int) $expr]);
        }
        if (preg_match('/^(\d+)-(\d+)$/', $expr, $m)) {
            return sprintf($t['dow_range'], $t['dow_gen'][(int) $m[1]], $t['dow_gen'][(int) $m[2]]);
        }
        if (str_contains($expr, ',')) {
            $list = array_map(fn($n) => $t['dow'][(int) $n], explode(',', $expr));
            $last = array_pop($list);
            return sprintf($t['dow_list'], implode(', ', $list), $last);
        }
        return '';
    }

    /**
     * @param array<string> $forms [one, few, many] or [one, many]
     */
    private function plural(int $n, array $forms): string
    {
        if (count($forms) === 2) {
            return $n === 1 ? $forms[0] : $forms[1];
        }
        // Slavic pluralization...
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($n === 1)
            return $forms[0];
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 > 20))
            return $forms[1];
        return $forms[2];
    }
}
