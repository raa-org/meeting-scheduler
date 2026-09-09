<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Helper;

use DateTime;

final class TimeHelper
{
    /**
     * Canonicalise IANA timezone identifiers that have been renamed.
     * On servers with older tzdata `Europe/Kiev` may still resolve fine; on
     * newer ones the canonical name is `Europe/Kyiv`. Some calendar
     * APIs are also picky about the renamed forms.
     */
    public static function canonicalTimezone(string $tz): string
    {
        $tz = trim($tz);
        if ($tz === '') {
            return 'UTC';
        }
        $aliases = [
            'Europe/Kiev'         => 'Europe/Kyiv',
            'Asia/Calcutta'       => 'Asia/Kolkata',
            'Asia/Saigon'         => 'Asia/Ho_Chi_Minh',
            'Asia/Rangoon'        => 'Asia/Yangon',
            'Asia/Katmandu'       => 'Asia/Kathmandu',
            'Asia/Dacca'          => 'Asia/Dhaka',
            'Asia/Macao'          => 'Asia/Macau',
            'Asia/Thimbu'         => 'Asia/Thimphu',
            'Asia/Ashkhabad'      => 'Asia/Ashgabat',
            'Asia/Ujung_Pandang'  => 'Asia/Makassar',
            'Asia/Ulan_Bator'     => 'Asia/Ulaanbaatar',
            'Asia/Tel_Aviv'       => 'Asia/Jerusalem',
            'Asia/Istanbul'       => 'Europe/Istanbul',
            'Atlantic/Faeroe'     => 'Atlantic/Faroe',
            'America/Godthab'     => 'America/Nuuk',
            'America/Buenos_Aires'  => 'America/Argentina/Buenos_Aires',
            'America/Catamarca'   => 'America/Argentina/Catamarca',
            'America/Cordoba'     => 'America/Argentina/Cordoba',
            'America/Jujuy'       => 'America/Argentina/Jujuy',
            'America/Mendoza'     => 'America/Argentina/Mendoza',
            'America/Indianapolis'  => 'America/Indiana/Indianapolis',
            'America/Fort_Wayne'  => 'America/Indiana/Indianapolis',
            'America/Knox_IN'     => 'America/Indiana/Knox',
            'America/Louisville'  => 'America/Kentucky/Louisville',
            'America/Atka'        => 'America/Adak',
            'America/Coral_Harbour' => 'America/Atikokan',
            'America/Ensenada'    => 'America/Tijuana',
            'America/Virgin'      => 'America/Port_of_Spain',
            'Australia/Currie'    => 'Australia/Hobart',
            'Australia/Yancowinna' => 'Australia/Broken_Hill',
            'Pacific/Ponape'      => 'Pacific/Pohnpei',
            'Pacific/Truk'        => 'Pacific/Chuuk',
            'Pacific/Samoa'       => 'Pacific/Pago_Pago',
            'Pacific/Johnston'    => 'Pacific/Honolulu',
            'Africa/Asmera'       => 'Africa/Asmara',
            'Africa/Timbuktu'     => 'Africa/Abidjan',
        ];
        $candidate = $aliases[$tz] ?? $tz;
        try {
            new \DateTimeZone($candidate);
            return $candidate;
        } catch (\Throwable $e) {
            try {
                new \DateTimeZone($tz);
                return $tz;
            } catch (\Throwable $e2) {
                return 'UTC';
            }
        }
    }

    /**
     * Normalize "9:00", "2:30 pm", "14:00" → "HH:MM" (24h).
     */
    public static function normalizeTimeTo24h(string $time): string
    {
        $time = trim($time);

        if (preg_match('/^(\d{1,2}):(\d{2})\s*(am|pm)?$/i', $time, $m)) {
            $hours = (int) $m[1];
            $minutes = (int) $m[2];
            $period = strtolower($m[3] ?? '');

            if ($period === 'pm' && $hours < 12) {
                $hours += 12;
            } elseif ($period === 'am' && $hours === 12) {
                $hours = 0;
            }

            return sprintf('%02d:%02d', $hours, $minutes);
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return '';
    }

    /**
     * Duration in minutes between two DateTimes.
     */
    public static function calculateDuration(DateTime $from, DateTime $to): int
    {
        return (int) abs(($to->getTimestamp() - $from->getTimestamp()) / 60);
    }

    /**
     * Merge overlapping/touching intervals. Each interval: ['start' => DateTime, 'end' => DateTime].
     *
     * @param  array<int, array{start: DateTime, end: DateTime}> $intervals Sorted or unsorted.
     * @return array<int, array{start: DateTime, end: DateTime, duration_minutes: int}>
     */
    public static function mergeOverlappingIntervals(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }

        usort($intervals, static fn(array $a, array $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        $unique = [];
        $seen = [];
        foreach ($intervals as $iv) {
            $key = $iv['start']->getTimestamp() . '_' . $iv['end']->getTimestamp();
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $iv;
            }
        }

        if ($unique === []) {
            return [];
        }

        $merged = [];
        $current = $unique[0];

        for ($i = 1, $len = count($unique); $i < $len; $i++) {
            $next = $unique[$i];

            if ($current['end']->getTimestamp() >= $next['start']->getTimestamp()) {
                if ($next['end']->getTimestamp() > $current['end']->getTimestamp()) {
                    $current['end'] = $next['end'];
                }
            } else {
                $current['duration_minutes'] = self::calculateDuration($current['start'], $current['end']);
                $merged[] = $current;
                $current = $next;
            }
        }

        $current['duration_minutes'] = self::calculateDuration($current['start'], $current['end']);
        $merged[] = $current;

        return $merged;
    }

    /**
     * Sort intervals by start ascending.
     *
     * @param  array<int, array{start: DateTime}> $intervals
     * @return array<int, array{start: DateTime}>
     */
    public static function sortByStart(array $intervals): array
    {
        usort($intervals, static fn(array $a, array $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());
        return $intervals;
    }

    /**
     * Clamp schedule reminder_minutes to a sane range (0–20 160 min / 2 weeks).
     * Defaults to 15 minutes when the value is absent or empty.
     *
     * @param array<string, mixed> $schedule
     */
    public static function normalizeReminderMinutes(array $schedule): int
    {
        $raw = $schedule['reminder_minutes'] ?? null;
        if ($raw === null || $raw === '') {
            return 15;
        }

        $n = (int) $raw;
        if ($n < 0) {
            return 0;
        }

        return min($n, 20160);
    }

    /**
     * Parse optional YYYY-MM-DD, fallback on empty/invalid.
     */
    public static function parseOptionalYmd(mixed $value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        $str = (string) $value;

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) ? $str : $fallback;
    }

    /**
     * Organiser IANA timezone from schedule (weekly_rule or first range). Empty = none set.
     *
     * @param array<string, mixed> $schedule
     */
    public static function organiserTimezoneFromSchedule(array $schedule): string
    {
        $ruleRaw = $schedule['schedule_weekly_rule'] ?? null;
        if (is_array($ruleRaw) && !empty($ruleRaw['timezone']) && is_string($ruleRaw['timezone'])) {
            return self::canonicalTimezone(trim($ruleRaw['timezone']));
        }
        if (is_string($ruleRaw) && $ruleRaw !== '') {
            $rule = json_decode($ruleRaw, true);
            if (is_array($rule) && !empty($rule['timezone']) && is_string($rule['timezone'])) {
                return self::canonicalTimezone(trim($rule['timezone']));
            }
        }

        $rangesRaw = $schedule['schedule_ranges'] ?? null;
        if (is_array($rangesRaw) && isset($rangesRaw[0]['timezone']) && is_string($rangesRaw[0]['timezone'])) {
            return self::canonicalTimezone(trim($rangesRaw[0]['timezone']));
        }
        if (is_string($rangesRaw) && $rangesRaw !== '') {
            $ranges = json_decode($rangesRaw, true);
            if (is_array($ranges) && isset($ranges[0]['timezone']) && is_string($ranges[0]['timezone'])) {
                return self::canonicalTimezone(trim($ranges[0]['timezone']));
            }
        }

        return '';
    }
}
