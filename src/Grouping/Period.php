<?php

namespace Storyfeed\Grouping;

use DateTimeInterface;

/**
 * The calendar bucket a verb's groups live in: the VALUE of the Day segment
 * of every axis key (`Field::Day`, the `d` token), declared per verb with
 * `->groupedHourly()`, `->groupedDaily()`, `->groupedWeekly()` or
 * `->groupedMonthly()`.
 *
 * A period is a calendar cut, never a sliding window: opens at 14:59 and
 * 15:01 land in different hourly groups. Sliding windows belong to batching
 * (`->batched(within:)`), and "within" only ever means sliding.
 *
 * | Period | Value           | Example         |
 * |--------|-----------------|-----------------|
 * | Hour   | `Y-m-d\TH`      | `2026-09-23T14` |
 * | Day    | `Y-m-d`         | `2026-09-23`    |
 * | Week   | ISO `o-\WW`     | `2026-W39`      |
 * | Month  | `Y-m`           | `2026-09`       |
 *
 * Day's value is `toDateString()`, byte for byte what every key carried
 * before periods existed, so a verb that declares nothing hashes as it
 * always has (AxisHashStabilityTest).
 *
 * A WEEK STARTS ON MONDAY, EVERYWHERE, with no config key. The ISO year and
 * week (`o`, `W`) are read by `format()`, which no locale changes; Carbon's
 * `startOfWeek()` follows the locale, and a hash must not depend on one.
 * (The scheduler's `->weekly()` fires on Sunday: that is a cadence, and this
 * is a bucket.)
 */
enum Period: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** This period's value for a moment, in the moment's own zone. */
    public function valueFor(DateTimeInterface $moment): string
    {
        return match ($this) {
            self::Hour => $moment->format('Y-m-d\TH'),
            self::Day => $moment->format('Y-m-d'),
            self::Week => $moment->format('o-\WW'),
            self::Month => $moment->format('Y-m'),
        };
    }

    /**
     * How many days back a curation pass must reach to see every member of
     * an open group: the longest the period lasts, plus a day. Day's answer
     * is `storyfeed.curate.window`'s default of 2.
     */
    public function lookBackDays(): int
    {
        return match ($this) {
            self::Hour => 1,
            self::Day => 2,
            self::Week => 8,
            self::Month => 32,
        };
    }

    /** The scheduler's word for it: `hourly`, `daily`, `weekly`, `monthly`. */
    public function adverb(): string
    {
        return match ($this) {
            self::Hour => 'hourly',
            self::Day => 'daily',
            self::Week => 'weekly',
            self::Month => 'monthly',
        };
    }
}
