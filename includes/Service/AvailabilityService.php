<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Service;

use DateTime;
use DateTimeZone;
use Apexianlab\Calendar\Auth\GoogleOAuthHandler;
use Apexianlab\Calendar\Dto\AvailabilityWindow;
use Apexianlab\Calendar\Helper\TimeHelper;
use Apexianlab\Calendar\Repository\BookingRepository;
use Apexianlab\Calendar\Repository\ScheduleRepository;
use RuntimeException;

final class AvailabilityService
{
    private ScheduleRepository $scheduleRepo;
    private BookingRepository $bookingRepo;

    public function __construct(
        ScheduleRepository $scheduleRepo,
        BookingRepository $bookingRepo
    ) {
        $this->scheduleRepo = $scheduleRepo;
        $this->bookingRepo = $bookingRepo;
    }

    // ------------------------------------------------------------------
    //  Schedule range expansion
    // ------------------------------------------------------------------

    /**
     * Expand a recurrence rule into concrete date+time range entries.
     *
     * Walk from $dateFrom to $dateTo day-by-day. For each day check whether
     * it belongs to an active week (anchor + interval). Look up daily_slots
     * for the ISO day-of-week (1=Mon…7=Sun), normalize times, and emit
     * [date, start, end, timezone] tuples.
     *
     * @param string                                                             $dateFrom            YYYY-MM-DD walk start
     * @param string                                                             $dateTo              YYYY-MM-DD walk end (inclusive)
     * @param string                                                             $timezone            IANA timezone
     * @param array<int|string, array<int, array{start: string, end: string}>>   $dailySlots          1=Mon…7=Sun
     * @param string                                                             $scheduleRepeat      'weekly'|'does_not_repeat'
     * @param int                                                                $repeatIntervalWeeks Weeks between active weeks (1–52)
     * @param string|null                                                        $repeatAnchorDate    YYYY-MM-DD anchor for week-index calculation
     * @return array<int, array{date: string, start: string, end: string, timezone: string}>
     */
    public static function buildScheduleRanges(
        string $dateFrom,
        string $dateTo,
        string $timezone,
        array $dailySlots,
        string $scheduleRepeat = 'weekly',
        int $repeatIntervalWeeks = 1,
        ?string $repeatAnchorDate = null
    ): array {
        $repeatIntervalWeeks = max(1, min(52, $repeatIntervalWeeks));
        if ($scheduleRepeat === 'does_not_repeat') {
            $repeatIntervalWeeks = 1;
        }

        $anchorDate = $repeatAnchorDate;
        if ($anchorDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchorDate)) {
            $anchorDate = $dateFrom;
        }

        try {
            $from = new DateTime($dateFrom);
            $to = new DateTime($dateTo);
            $anchor = new DateTime($anchorDate);
            // Count active weeks by calendar week (Monday start), matching Google:
            // start week is active, then every Nth calendar week. Without snapping,
            // the week index is rolling 7-day blocks from the start weekday and the
            // active-week phase drifts off the calendar grid.
            $anchor->modify('monday this week');
        } catch (\Exception $e) {
            return [];
        }

        if ($from > $to) {
            return [];
        }

        $ranges = [];
        $cursor = clone $from;
        $anchorTs = $anchor->getTimestamp();

        while ($cursor <= $to) {
            $daysSinceAnchor = (int) (($cursor->getTimestamp() - $anchorTs) / 86400);
            $weekIndex = (int) floor($daysSinceAnchor / 7);

            $isActiveWeek = $scheduleRepeat === 'does_not_repeat'
                ? $weekIndex === 0
                : ($repeatIntervalWeeks === 1 || $weekIndex % $repeatIntervalWeeks === 0);

            if (!$isActiveWeek) {
                $cursor->modify('+1 day');
                continue;
            }

            $dow = (int) $cursor->format('N');
            $daySlots = self::getSlotsForDayOfWeek($dailySlots, $dow);

            if ($daySlots !== null) {
                foreach ($daySlots as $slot) {
                    $start = TimeHelper::normalizeTimeTo24h((string) ($slot['start'] ?? ''));
                    $end = TimeHelper::normalizeTimeTo24h((string) ($slot['end'] ?? ''));

                    if ($start === '' || $end === '') {
                        continue;
                    }

                    if ($start < $end) {
                        // Normal case: working hours within the same day (e.g., 09:00 - 18:00)
                        $ranges[] = [
                            'date' => $cursor->format('Y-m-d'),
                            'start' => $start,
                            'end' => $end,
                            'timezone' => $timezone,
                        ];
                    } elseif ($end < $start) {
                        // Working hours cross midnight (e.g., 23:00 - 01:00)
                        // Split into two ranges using 24:00 as the boundary so
                        // mergeOverlappingIntervals can join them into one
                        // contiguous range (24:00 overflows to next-day 00:00).
                        $ranges[] = [
                            'date' => $cursor->format('Y-m-d'),
                            'start' => $start,
                            'end' => '24:00',
                            'timezone' => $timezone,
                        ];
                        $nextDay = clone $cursor;
                        $nextDay->modify('+1 day');
                        $ranges[] = [
                            'date' => $nextDay->format('Y-m-d'),
                            'start' => '00:00',
                            'end' => $end,
                            'timezone' => $timezone,
                        ];
                    }
                    // If $start === $end, skip (zero-duration slot)
                }
            }

            $cursor->modify('+1 day');
        }

        return $ranges;
    }

    /**
     * Materialize schedule ranges that overlap [windowFrom, windowTo).
     *
     * For weekly schedules with a stored rule, ranges are computed on-the-fly
     * instead of reading pre-expanded JSONB rows. Falls back to the stored
     * schedule_ranges for legacy rows without a rule.
     *
     * @param array<string, mixed> $schedule   Row from calendar_schedule
     * @param DateTime             $windowFrom Start of the query window (inclusive)
     * @param DateTime             $windowTo   End of the query window (exclusive)
     * @return array<int, array{date: string, start: string, end: string, timezone: string}>
     */
    public static function resolveScheduleRangesForWindow(
        array $schedule,
        DateTime $windowFrom,
        DateTime $windowTo
    ): array {
        $scheduleRepeat = (string) ($schedule['schedule_repeat'] ?? '');
        $rule = self::decodeWeeklyRule($schedule['schedule_weekly_rule'] ?? null);
        $fallbackRanges = $schedule['schedule_ranges'] ?? '[]';

        if (($scheduleRepeat !== 'weekly' && $scheduleRepeat !== 'custom') || $rule === null) {
            return self::decodeScheduleRanges($fallbackRanges);
        }

        $ruleStart = (string) ($rule['date_from'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ruleStart)) {
            return self::decodeScheduleRanges($fallbackRanges);
        }

        $dailySlots = isset($rule['daily_slots']) && is_array($rule['daily_slots'])
            ? $rule['daily_slots']
            : [];
        if ($dailySlots === []) {
            return self::decodeScheduleRanges($fallbackRanges);
        }

        $ruleTimezone = TimeHelper::canonicalTimezone((string) ($rule['timezone'] ?? ''));
        $ruleTz       = new DateTimeZone($ruleTimezone);

        $intervalWeeks = max(1, min(52, (int) ($rule['repeat_interval_weeks'] ?? 1)));
        $ruleEndDate = TimeHelper::parseOptionalYmd($rule['date_to'] ?? null, '2099-12-31');

        $localFrom = clone $windowFrom;
        $localFrom->setTimezone($ruleTz);
        $localTo = clone $windowTo;
        $localTo->setTimezone($ruleTz);
        $localTo->modify('-1 second');

        $walkFrom = max($ruleStart, $localFrom->format('Y-m-d'));
        $walkTo = min($ruleEndDate, $localTo->format('Y-m-d'));

        if ($walkFrom > $walkTo) {
            return [];
        }

        return self::buildScheduleRanges(
            $walkFrom,
            $walkTo,
            $ruleTimezone,
            $dailySlots,
            'weekly',
            $intervalWeeks,
            $ruleStart
        );
    }

    // ------------------------------------------------------------------
    //  Availability list (booking page)
    // ------------------------------------------------------------------

    /**
     * Build the full availability grid for a booking page.
     *
     * Steps:
     *  a) Load schedule row; throw if missing.
     *  b) Collect Google Calendar busy times.
     *  c) Collect confirmed DB bookings for *all* schedules of the same email.
     *  d) Collect Google Calendar busy intervals.
     *  e) Resolve working-hour ranges for the requested schedule.
     *  f) Merge, deduplicate, convert to timestamps.
     *  g) Optionally convert to the caller's timezone.
     *  h) Return AvailabilityWindow DTO.
     *
     * @param string            $scheduleId Schedule UUID
     * @param DateTime          $from       Window start
     * @param DateTime          $to         Window end
     * @param DateTimeZone|null $timezone   Target timezone for the returned ranges (optional)
     * @return AvailabilityWindow
     *
     * @throws RuntimeException When the schedule is not found or email is invalid
     */
    public function getAvailabilityList(
        string $scheduleId,
        DateTime $from,
        DateTime $to,
        ?DateTimeZone $timezone = null,
        ?string $rescheduleMeetingId = null,
        ?string $attendeeEmail = null
    ): AvailabilityWindow {
        $scheduleRow = $this->scheduleRepo->findById($scheduleId);
        if ($scheduleRow === null) {
            throw new RuntimeException('Schedule not found.');
        }
        $ownerEmail = trim((string) ($scheduleRow['email'] ?? ''));
        if ($ownerEmail === '') {
            throw new RuntimeException('Schedule not found.');
        }

        $allSchedules = $this->scheduleRepo->findAllByEmail($ownerEmail);
        $scheduleIds  = array_column($allSchedules, 'id');
        if ($scheduleIds === []) {
            return new AvailabilityWindow($from->getTimestamp(), $to->getTimestamp(), [], []);
        }

        $bookedTimes = array_merge(
            $this->collectExternalBusyTimesParallel($ownerEmail, $scheduleRow, $from, $to),
            $this->collectAttendeeBusyTimes($attendeeEmail, $ownerEmail, $from, $to),
            $this->confirmedBookingsBusyTimes($scheduleIds, $from, $to),
            $this->rescheduleSelfBlockBusyTimes($rescheduleMeetingId),
        );

        $bookedTimes   = TimeHelper::sortByStart($bookedTimes);
        $scheduleTimes = TimeHelper::mergeOverlappingIntervals(
            $this->buildWorkingHourRanges($allSchedules, $scheduleId, $from, $to)
        );

        $bookedIntervals = self::deduplicateToTimestamps($bookedTimes);
        $list            = self::deduplicateRangesToTimestamps($scheduleTimes);
        if ($timezone !== null) {
            $list = self::convertListToTimezone($list, $timezone);
        }

        return new AvailabilityWindow($from->getTimestamp(), $to->getTimestamp(), $list, $bookedIntervals);
    }

    /**
     * Attendee-side busy times. In the Google-only model attendees don't grant
     * calendar access, so there is no external attendee calendar to consult.
     *
     * @return array<int, array{start: DateTime, end: DateTime}>
     */
    private function collectAttendeeBusyTimes(?string $attendeeEmail, string $ownerEmail, DateTime $from, DateTime $to): array
    {
        return [];
    }

    /**
     * Convert previously confirmed bookings (rows already in our DB) into
     * busy intervals so two attendees can't book the same slot.
     *
     * @param list<string> $scheduleIds
     * @return array<int, array{start: DateTime, end: DateTime}>
     */
    private function confirmedBookingsBusyTimes(array $scheduleIds, DateTime $from, DateTime $to): array
    {
        $intervals = [];
        foreach ($this->bookingRepo->findForCalendar($from, $to, $scheduleIds, null, true) as $booking) {
            if (!self::isConfirmedBooking($booking) && !self::isActiveHold($booking)) {
                continue;
            }
            $start = new DateTime($booking['datetime'], new DateTimeZone('UTC'));
            $end   = clone $start;
            $end->modify('+' . (int) $booking['duration'] . ' minutes');
            $intervals[] = ['start' => $start, 'end' => $end];
        }

        return $intervals;
    }

    /**
     * When rescheduling, keep the meeting's current slot in the busy set so
     * the user is forced to pick a different time.
     *
     * @return array<int, array{start: DateTime, end: DateTime}>
     */
    private function rescheduleSelfBlockBusyTimes(?string $rescheduleMeetingId): array
    {
        if ($rescheduleMeetingId === null || $rescheduleMeetingId === '') {
            return [];
        }
        $meeting = $this->bookingRepo->findById($rescheduleMeetingId);
        if (!is_array($meeting) || empty($meeting['datetime'])) {
            return [];
        }
        try {
            $start = new DateTime((string) $meeting['datetime'], new DateTimeZone('UTC'));
            $end   = clone $start;
            $end->modify('+' . (int) ($meeting['duration'] ?? 0) . ' minutes');
            return [['start' => $start, 'end' => $end]];
        } catch (\Throwable $e) {
            // Bad stored datetime — let the reschedule proceed unblocked.
            return [];
        }
    }

    // ------------------------------------------------------------------
    //  Booking availability checks
    // ------------------------------------------------------------------

    /**
     * Check whether a new booking slot overlaps any confirmed booking.
     *
     * @param string      $scheduleId       Schedule UUID
     * @param DateTime    $dateTime         Requested start time
     * @param int         $duration         Duration in minutes
     * @param string|null $excludeBookingId Booking UUID to skip (e.g. for reschedule)
     * @return bool True when the slot is free
     */
    public function isBookingTimeAvailable(
        string $scheduleId,
        DateTime $dateTime,
        int $duration,
        ?string $excludeBookingId = null,
        bool $includeHolds = true
    ): bool {
        $allBookings = $this->bookingRepo->findAllBy(['calendar_schedule_id' => $scheduleId]);

        $newStart = clone $dateTime;
        $newStart->setTimezone(new DateTimeZone('UTC'));
        $newEnd = clone $dateTime;
        $newEnd->setTimezone(new DateTimeZone('UTC'));
        $newEnd->modify('+' . $duration . ' minutes');

        foreach ($allBookings as $existing) {
            if ($excludeBookingId !== null && !empty($existing['id']) && (string) $existing['id'] === $excludeBookingId) {
                continue;
            }

            $isHold = $includeHolds && self::isActiveHold($existing);
            if (!self::isConfirmedBooking($existing) && !$isHold) {
                continue;
            }

            $existingStart = new DateTime($existing['datetime'], new DateTimeZone('UTC'));
            $existingEnd = clone $existingStart;
            $existingEnd->modify('+' . (int) $existing['duration'] . ' minutes');

            if (
                ($newStart >= $existingStart && $newStart < $existingEnd)
                || ($newEnd > $existingStart && $newEnd <= $existingEnd)
                || ($newStart <= $existingStart && $newEnd >= $existingEnd)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether an external time slot is free (Google Calendar FreeBusy).
     *
     * @param array<string, mixed>      $schedule   Schedule row
     * @param string                    $meansTitle Communication means title
     * @param DateTime                  $startUtc   Start (UTC)
     * @param DateTime                  $endUtc     End (UTC)
     * @param array<string, mixed>|null $meeting    Unused; kept for signature compat
     * @return bool True when the slot has no conflicts
     */
    public function isExternalTimeSlotAvailable(
        array $schedule,
        string $meansTitle,
        DateTime $startUtc,
        DateTime $endUtc,
        ?array $meeting = null
    ): bool {
        if (strtolower(trim($meansTitle)) === 'google calendar') {
            return $this->checkGoogleAvailability($schedule, $startUtc, $endUtc);
        }

        return true;
    }

    // ------------------------------------------------------------------
    //  Private helpers – slot lookup / JSON decode
    // ------------------------------------------------------------------

    /**
     * Look up slot definitions for a given ISO day-of-week.
     *
     * @param array<int|string, mixed> $dailySlots 1=Mon…7=Sun
     * @param int                      $dow        ISO day-of-week
     * @return array<int, array{start: string, end: string}>|null
     */
    private static function getSlotsForDayOfWeek(array $dailySlots, int $dow): ?array
    {
        foreach ([$dow, (string) $dow] as $key) {
            if (isset($dailySlots[$key]) && is_array($dailySlots[$key])) {
                return $dailySlots[$key];
            }
        }

        return null;
    }

    /**
     * Decode a schedule_weekly_rule column value (string or array) into an array.
     *
     * @param mixed $raw Column value (JSONB string, array, or null)
     * @return array<string, mixed>|null
     */
    private static function decodeWeeklyRule(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Decode a schedule_ranges column value (string or array) into a flat array.
     *
     * @param mixed $raw Column value (JSONB string, array, or null)
     * @return array<int, array{date: string, start: string, end: string, timezone: string}>
     */
    private static function decodeScheduleRanges(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    // ------------------------------------------------------------------
    //  Private helpers – availability list internals
    // ------------------------------------------------------------------

    /**
     * Collect owner busy intervals from Google Calendar FreeBusy.
     *
     * @param array<string, mixed> $scheduleRow
     * @return array<int, array{start: DateTime, end: DateTime}>
     */
    private function collectExternalBusyTimesParallel(
        string $email,
        array $scheduleRow,
        DateTime $from,
        DateTime $to
    ): array {
        return $this->collectGoogleBusyTimes($email, $from, $to);
    }

    /**
     * Google Calendar busy intervals for the schedule owner. No-ops silently
     * when Google is not connected or unreachable.
     *
     * @return array<int, array{start: DateTime, end: DateTime}>
     */
    private function collectGoogleBusyTimes(string $email, DateTime $from, DateTime $to): array
    {
        if ($email === '' || !class_exists(GoogleOAuthHandler::class)) {
            return [];
        }

        try {
            $fromUtc = (clone $from)->setTimezone(new DateTimeZone('UTC'));
            $toUtc   = (clone $to)->setTimezone(new DateTimeZone('UTC'));
            $busy    = GoogleOAuthHandler::getInstance()->fetchGoogleBusyIntervalsUtc($email, $fromUtc, $toUtc);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($busy) ? $busy : [];
    }


    /**
     * Resolve working-hour ranges for the given schedule within [from, to).
     *
     * @param array<int, array<string, mixed>> $allSchedules All schedules for the email
     * @param string                           $scheduleId   Target schedule UUID
     * @return array<int, array{start: DateTime, end: DateTime}>
     */
    private function buildWorkingHourRanges(
        array $allSchedules,
        string $scheduleId,
        DateTime $from,
        DateTime $to
    ): array {
        $scheduleTimes = [];

        foreach ($allSchedules as $schedule) {
            if ((string) $schedule['id'] !== $scheduleId) {
                continue;
            }

            $ranges = self::resolveScheduleRangesForWindow($schedule, $from, $to);
            if (!is_array($ranges)) {
                break;
            }

            foreach ($ranges as $range) {
                $rangeTz = new DateTimeZone(
                    TimeHelper::canonicalTimezone((string) ($range['timezone'] ?? 'UTC'))
                );

                $start = new DateTime($range['date'] . ' ' . $range['start'], $rangeTz);
                $start->setTimezone(new DateTimeZone('UTC'));
                $end = new DateTime($range['date'] . ' ' . $range['end'], $rangeTz);
                $end->setTimezone(new DateTimeZone('UTC'));

                if ($start < $end && $start < $to && $end > $from) {
                    $scheduleTimes[] = [
                        'start' => $start,
                        'end' => $end,
                        'duration_minutes' => TimeHelper::calculateDuration($start, $end),
                    ];
                }
            }

            break;
        }

        return $scheduleTimes;
    }

    /**
     * Check Google Calendar availability for a time slot via FreeBusy.
     *
     * @param array<string, mixed> $schedule
     */
    private function checkGoogleAvailability(
        array $schedule,
        DateTime $startUtc,
        DateTime $endUtc
    ): bool {
        if (!class_exists(GoogleOAuthHandler::class)) {
            return false;
        }

        $scheduleEmail = !empty($schedule['email']) ? (string) $schedule['email'] : '';
        if ($scheduleEmail === '') {
            return false;
        }

        try {
            $handler = GoogleOAuthHandler::getInstance();
            $result  = $handler->evaluateCalendarConflict($scheduleEmail, $startUtc, $endUtc);

            if ($result['conflict'] === true) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    //  Private helpers – timestamp conversion
    // ------------------------------------------------------------------

    /**
     * Deduplicate booked intervals and convert to [start, end] timestamp pairs.
     *
     * @param array<int, array{start: DateTime, end: DateTime}> $intervals
     * @return array<int, array{start: int, end: int}>
     */
    private static function deduplicateToTimestamps(array $intervals): array
    {
        $result = [];
        $seen = [];

        foreach ($intervals as $iv) {
            $s = $iv['start']->getTimestamp();
            $e = $iv['end']->getTimestamp();
            $key = $s . '_' . $e;

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = ['start' => $s, 'end' => $e];
        }

        return $result;
    }

    /**
     * Deduplicate schedule ranges and convert to timestamp arrays.
     *
     * @param array<int, array{start: DateTime, end: DateTime, duration_minutes?: int}> $ranges
     * @return array<int, array{start: int, end: int, duration_minutes: int}>
     */
    private static function deduplicateRangesToTimestamps(array $ranges): array
    {
        $result = [];
        $seen = [];

        foreach ($ranges as $range) {
            $s = $range['start']->getTimestamp();
            $e = $range['end']->getTimestamp();
            $key = $s . '_' . $e;

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = [
                'start' => $s,
                'end' => $e,
                'duration_minutes' => $range['duration_minutes'] ?? TimeHelper::calculateDuration($range['start'], $range['end']),
            ];
        }

        return $result;
    }

    /**
     * Convert start/end timestamps back to the caller's timezone (for display).
     *
     * @param array<int, array{start: int, end: int, duration_minutes: int}> $list
     * @return array<int, array{start: int, end: int, duration_minutes: int}>
     */
    private static function convertListToTimezone(array $list, DateTimeZone $timezone): array
    {
        return array_map(static function (array $item) use ($timezone): array {
            $start = (new DateTime('@' . $item['start']))->setTimezone($timezone);
            $end = (new DateTime('@' . $item['end']))->setTimezone($timezone);

            $item['start'] = $start->getTimestamp();
            $item['end'] = $end->getTimestamp();

            return $item;
        }, $list);
    }

    /**
     * Check whether a booking row represents a confirmed meeting.
     *
     * @param array<string, mixed> $booking
     */
    private static function isConfirmedBooking(array $booking): bool
    {
        if (empty($booking['meeting_confirmed'])) {
            return false;
        }

        $val = $booking['meeting_confirmed'];

        return $val === true || $val === 't' || (int) $val === 1;
    }

    /**
     * Pending booking still inside its confirmation window — treat as a
     * temporary hold so a second attendee cannot grab the same slot before
     * the first one finishes confirming (or the link expires).
     *
     * @param array<string, mixed> $booking
     */
    private static function isActiveHold(array $booking): bool
    {
        if (self::isConfirmedBooking($booking)) {
            return false;
        }
        $exp = (string) ($booking['expiration_of_confirmation'] ?? '');
        if ($exp === '') {
            return false;
        }
        try {
            $expDt = new DateTime($exp, new DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return false;
        }
        return $expDt->getTimestamp() > (new DateTime('now', new DateTimeZone('UTC')))->getTimestamp();
    }

}
