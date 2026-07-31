<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Display / input boundary for Eastern wall-clock times while storage stays UTC.
 *
 * Do not set APP_TIMEZONE to America/New_York — Eloquent would then persist
 * local times. Convert only at display and Filament input boundaries.
 */
final class DisplayTime
{
    public static function zone(): string
    {
        return (string) config('app.display_timezone', 'America/New_York');
    }

    public static function format(?DateTimeInterface $value, string $format = 'D, M j, Y g:i A'): string
    {
        if ($value === null) {
            return '—';
        }

        return Carbon::instance($value)->timezone(self::zone())->format($format);
    }

    /**
     * Drop-in replacement for Carbon::toDayDateTimeString() in teacher/student UI.
     */
    public static function toDayDateTimeString(?DateTimeInterface $value): string
    {
        if ($value === null) {
            return '—';
        }

        return Carbon::instance($value)->timezone(self::zone())->toDayDateTimeString();
    }

    /**
     * Interpret a Filament/form datetime string as display-timezone local time
     * and return a UTC Carbon for persistence.
     */
    public static function parseInput(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->copy()->utc();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc();
        }

        return Carbon::parse((string) $value, self::zone())->utc();
    }
}
