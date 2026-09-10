<?php

namespace Storyfeed\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The one timestamp format feed chronology is written and compared in.
 *
 * `published_at` was a whole-second column for the package's first nine
 * releases, so two activities published milliseconds apart were tied in
 * storage and the tiebreak decided their order — a stability question
 * wearing a chronology question's clothes. The column now carries
 * microseconds, and this is the format that makes the column mean anything:
 *
 *  - The Activity model writes with it (`$dateFormat`), because Laravel's
 *    grammar format is `Y-m-d H:i:s` on every driver and a wider column
 *    filled through a narrower format is a change that looks shipped and
 *    does nothing.
 *  - Every WHERE that binds a date against the column formats through it,
 *    because `Connection::prepareBindings()` formats a bare Carbon with the
 *    GRAMMAR's format, not the model's — so `where('published_at', '<=',
 *    now())` would compare `.000000` against rows that carry `.400000` and
 *    hide a row for up to a second after it was published.
 *  - Cursors round-trip through it, so a position inside a second is a
 *    position and not a whole second.
 *
 * On SQLite the column is text and compares lexically, which is
 * chronological exactly when every value has the same shape. The upgrade
 * migration pads legacy values to this width for that reason.
 */
final class Chronology
{
    public const FORMAT = 'Y-m-d H:i:s.u';

    /** Format a date the way the column stores it, so a bind compares like for like. */
    public static function stamp(DateTimeInterface|string $value): string
    {
        return match (true) {
            $value instanceof CarbonInterface => $value->format(self::FORMAT),
            $value instanceof DateTimeInterface => Carbon::instance($value)->format(self::FORMAT),
            default => Carbon::parse($value)->format(self::FORMAT),
        };
    }
}
