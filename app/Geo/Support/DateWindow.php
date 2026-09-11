<?php

namespace App\Geo\Support;

use Carbon\CarbonImmutable;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * The date range behind the map's slider: two optional calendar days,
 * inclusive at both ends, either of which may be open.
 *
 * Parsing lives here rather than in the controller because the window also
 * has to produce a cache key. Two requests for the same range must land on
 * the same cached viewport, which means "the same range" has to mean exactly
 * the same thing to the key as it does to the query.
 */
class DateWindow
{
    private function __construct(
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
    ) {}

    public static function none(): self
    {
        return new self(null, null);
    }

    /**
     * `Y-m-d` in, or nothing.
     *
     * An unparseable value is treated as absent rather than as an error. The
     * window is a filter on a map, and a whole map is more use to somebody
     * than a 422 — the controller rejects malformed input before this, so
     * anything arriving here has already been through validation once.
     */
    public static function parse(?string $from, ?string $to): self
    {
        $start = self::day($from);
        $end = self::day($to);

        // Only a hand-written request can reverse these; the slider cannot.
        // Read it as meant rather than returning an empty map.
        if ($start !== null && $end !== null && $start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return new self($start?->startOfDay(), $end?->endOfDay());
    }

    public function isEmpty(): bool
    {
        return $this->from === null && $this->to === null;
    }

    /**
     * Distinct for every distinct window, and stable for the life of a day.
     */
    public function cacheKey(): string
    {
        return ($this->from?->toDateString() ?? '*').'..'.($this->to?->toDateString() ?? '*');
    }

    private static function day(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        // createFromFormat is lenient: it reads '2026-13-45' as some day in
        // 2027 rather than refusing it. Check the parts before trusting them.
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
            return null;
        }

        [, $year, $month, $day] = $parts;

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        $date = CarbonImmutable::create((int) $year, (int) $month, (int) $day, 0, 0, 0);

        return $date === false ? null : $date;
    }
}
