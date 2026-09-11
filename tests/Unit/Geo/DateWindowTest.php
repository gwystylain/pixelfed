<?php

namespace Tests\Unit\Geo;

use App\Geo\Support\DateWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 */
class DateWindowTest extends TestCase
{
    #[Test]
    public function it_reads_a_closed_range_inclusively_at_both_ends(): void
    {
        $window = DateWindow::parse('2026-03-01', '2026-09-11');

        // A viewer who drags the slider to 1 March expects the photos taken
        // on 1 March, and the same at the far end.
        $this->assertSame('2026-03-01 00:00:00', $window->from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-11 23:59:59', $window->to->format('Y-m-d H:i:s'));
        $this->assertFalse($window->isEmpty());
    }

    #[Test]
    public function it_leaves_an_omitted_end_open(): void
    {
        $from = DateWindow::parse('2026-03-01', null);
        $this->assertSame('2026-03-01', $from->from->toDateString());
        $this->assertNull($from->to);

        $to = DateWindow::parse(null, '2026-09-11');
        $this->assertNull($to->from);
        $this->assertSame('2026-09-11', $to->to->toDateString());

        $neither = DateWindow::parse(null, null);
        $this->assertTrue($neither->isEmpty());
        $this->assertTrue(DateWindow::none()->isEmpty());
    }

    #[Test]
    public function it_swaps_a_reversed_range(): void
    {
        $window = DateWindow::parse('2026-09-11', '2026-03-01');

        $this->assertSame('2026-03-01', $window->from->toDateString());
        $this->assertSame('2026-09-11', $window->to->toDateString());
    }

    #[Test]
    #[DataProvider('malformed')]
    public function it_treats_malformed_input_as_no_bound(string $value): void
    {
        $this->assertNull(DateWindow::parse($value, null)->from);
        $this->assertNull(DateWindow::parse(null, $value)->to);
    }

    public static function malformed(): array
    {
        return [
            'empty' => [''],
            'words' => ['yesterday'],
            'month 13' => ['2026-13-01'],
            'day 30 of february' => ['2026-02-30'],
            'day 32' => ['2026-01-32'],
            'unpadded' => ['2026-1-1'],
            'no separators' => ['20260101'],
            'iso timestamp' => ['2026-01-01T00:00:00Z'],
            'zeroes' => ['0000-00-00'],
            'trailing junk' => ['2026-01-01; DROP TABLE statuses'],
            'negative' => ['-001-01-01'],
        ];
    }

    #[Test]
    public function it_accepts_a_leap_day_in_a_leap_year_only(): void
    {
        $this->assertSame('2024-02-29', DateWindow::parse('2024-02-29', null)->from->toDateString());
        $this->assertNull(DateWindow::parse('2026-02-29', null)->from);
    }

    #[Test]
    public function it_keys_each_distinct_window_distinctly(): void
    {
        $keys = [
            DateWindow::none()->cacheKey(),
            DateWindow::parse('2026-03-01', null)->cacheKey(),
            DateWindow::parse(null, '2026-03-01')->cacheKey(),
            DateWindow::parse('2026-03-01', '2026-09-11')->cacheKey(),
            DateWindow::parse('2026-03-02', '2026-09-11')->cacheKey(),
        ];

        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    #[Test]
    public function it_keys_equivalent_windows_identically(): void
    {
        // Including the reversed pair, which normalises to the same window
        // and so must not cache separately from it.
        $this->assertSame(
            DateWindow::parse('2026-03-01', '2026-09-11')->cacheKey(),
            DateWindow::parse('2026-09-11', '2026-03-01')->cacheKey()
        );

        $this->assertSame(
            DateWindow::none()->cacheKey(),
            DateWindow::parse('nonsense', '')->cacheKey()
        );
    }
}
