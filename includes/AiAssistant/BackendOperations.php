<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\AiAssistant;

use DateTime;
use DateTimeZone;
use Apexianlab\Calendar\Service\AvailabilityService;
use Apexianlab\Calendar\Service\BookingService;
use Apexianlab\Calendar\Service\NotificationService;
use Apexianlab\Calendar\Repository\BookingRepository;
use Apexianlab\Calendar\Repository\ScheduleRepository;

/**
 * Pure backend operations the conversation flow needs:
 *
 *   - checkAvailability(date, time, duration, ctx)
 *       Returns whether the requested slot is free, and if not the list of
 *       free 15-minute-grid windows for that day so the flow can present
 *       alternatives.
 *
 *   - listUpcomingMeetings(ctx, guestEmail)
 *       Returns the user's upcoming meetings (max 10), already formatted
 *       with human-readable labels.
 *
 *   - book / reschedule / cancel
 *       Delegated to the existing BookingService / BookingRepository which
 *       carry all the production-grade logic (Google Calendar, email confirmation
 *       for unauthenticated guests, notification dispatch, etc.).
 *
 * This class never talks to the LLM. It is called only by ConversationFlow,
 * deterministically, after the flow has determined what data to operate on.
 */
final class BackendOperations
{
    public const MEETING_LIST_HORIZON_MONTHS = 3;

    public function __construct(
        private AvailabilityService $availabilityService,
        private BookingService $bookingService,
        private NotificationService $notificationService,
        private BookingRepository $bookingRepo,
        private ScheduleRepository $scheduleRepo
    ) {
    }

    /**
     * @param array<string,mixed> $context
     * @return array{free:bool, slot_start:?int, free_windows:list<array{start_local:string,end_local:string}>}
     */
    public function checkAvailability(string $date, string $time, int $duration, array $context): array
    {
        $tz       = (string) ($context['timezone'] ?? 'UTC');
        $calId    = (string) ($context['calendar_id'] ?? '');
        if ($calId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            return ['free' => false, 'slot_start' => null, 'free_windows' => []];
        }
        [$h, $m] = array_map('intval', explode(':', $time));
        $duration = max(1, $duration);

        try {
            $timezone = new DateTimeZone($tz);
            $from     = new DateTime($date . ' 00:00:00', $timezone);
            $to       = (clone $from)->modify('+1 day');
            $window   = $this->availabilityService->getAvailabilityList($calId, $from, $to, $timezone);
            $ranges   = $window->toArray();
        } catch (\Throwable $e) {
            return ['free' => false, 'slot_start' => null, 'free_windows' => []];
        }

        $list   = isset($ranges['list']) && is_array($ranges['list']) ? $ranges['list'] : [];
        $booked = isset($ranges['booked_intervals']) && is_array($ranges['booked_intervals']) ? $ranges['booked_intervals'] : [];
        usort($booked, static fn($a, $b) => ((int) ($a['start'] ?? 0)) <=> ((int) ($b['start'] ?? 0)));

        // Compute the requested Unix slot_start in this timezone.
        try {
            $reqDt = new DateTime($date, $timezone);
            $reqDt->setTime($h, $m, 0);
            $reqStart = $reqDt->getTimestamp();
        } catch (\Throwable $e) {
            return ['free' => false, 'slot_start' => null, 'free_windows' => []];
        }
        $reqEnd  = $reqStart + $duration * 60;
        $nowTs   = time();

        $isFree = static function (int $slotStart, int $slotEnd) use ($booked): bool {
            foreach ($booked as $b) {
                $bs = (int) ($b['start'] ?? 0);
                $be = (int) ($b['end'] ?? 0);
                if ($bs >= $slotEnd) {
                    break;
                }
                if ($bs < $slotEnd && $be > $slotStart) {
                    return false;
                }
            }
            return true;
        };

        // Is the requested slot inside a working range AND not booked AND in the future?
        $insideWorking = false;
        foreach ($list as $range) {
            $rs = (int) ($range['start'] ?? 0);
            $re = (int) ($range['end'] ?? 0);
            if ($reqStart >= $rs && $reqEnd <= $re) {
                $insideWorking = true;
                break;
            }
        }

        $free = $insideWorking && $reqStart >= $nowTs && $isFree($reqStart, $reqEnd);
        if ($free) {
            return ['free' => true, 'slot_start' => $reqStart, 'free_windows' => []];
        }

        // Not free — build a list of free windows for that day to suggest alternatives.
        $freeWindows = $this->collapseFreeWindows($list, $booked, $duration, $timezone, $nowTs);

        return ['free' => false, 'slot_start' => null, 'free_windows' => $freeWindows];
    }

    /**
     * Return the upcoming dates (up to $maxDays days ahead) that have at least
     * one free slot of the requested duration. Used when the user asks "what
     * days are available?" without specifying a particular date.
     *
     * @param array<string,mixed> $context
     * @return list<string>  YYYY-MM-DD strings in ascending order
     */
    /**
     * @param string $preferredTime  Optional HH:MM — if set, only include days
     *                               where that specific time is free. Used when
     *                               the user asks "which days have a slot at 11pm".
     */
    /**
     * @param string $fromDate  Optional YYYY-MM-DD; if set, start scanning from this date
     *                          (used for "next week" so current week is skipped).
     */
    public function getAvailableDays(array $context, int $duration = 30, int $maxDays = 14, string $preferredTime = '', string $fromDate = ''): array
    {
        $tz    = (string) ($context['timezone'] ?? 'UTC');
        $calId = (string) ($context['calendar_id'] ?? '');
        if ($calId === '' || $duration <= 0) {
            return [];
        }

        try {
            $timezone = new DateTimeZone($tz);
            $from     = $fromDate !== ''
                ? new DateTime($fromDate . ' 00:00:00', $timezone)
                : new DateTime('now', $timezone);
            $to       = (clone $from)->modify("+{$maxDays} days");
            $window   = $this->availabilityService->getAvailabilityList($calId, $from, $to, $timezone);
            $ranges   = $window->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $list   = isset($ranges['list']) && is_array($ranges['list']) ? $ranges['list'] : [];
        $booked = isset($ranges['booked_intervals']) && is_array($ranges['booked_intervals']) ? $ranges['booked_intervals'] : [];
        usort($booked, static fn($a, $b) => ((int) ($a['start'] ?? 0)) <=> ((int) ($b['start'] ?? 0)));

        $needed = $duration * 60;
        $nowTs  = time();
        $grid   = 15 * 60;

        $isFree = static function (int $slotStart, int $slotEnd) use ($booked): bool {
            foreach ($booked as $b) {
                $bs = (int) ($b['start'] ?? 0);
                $be = (int) ($b['end'] ?? 0);
                if ($bs >= $slotEnd) {
                    break;
                }
                if ($bs < $slotEnd && $be > $slotStart) {
                    return false;
                }
            }
            return true;
        };

        // Parse preferred time (HH:MM) if supplied.
        $prefHour = null;
        $prefMin  = null;
        if ($preferredTime !== '' && preg_match('/^(\d{1,2}):(\d{2})$/', $preferredTime, $pm)) {
            $prefHour = (int) $pm[1];
            $prefMin  = (int) $pm[2];
        }

        $availableDates = [];
        foreach ($list as $range) {
            $rs = (int) ($range['start'] ?? 0);
            $re = (int) ($range['end'] ?? 0);
            if ($rs <= 0 || $re <= $rs) {
                continue;
            }

            if ($prefHour !== null) {
                // Time-filtered mode: check whether the preferred time is free
                // on the date that this working range belongs to.
                $dayDt = (new DateTime('@' . $rs))->setTimezone($timezone);
                $dayDt->setTime($prefHour, $prefMin, 0);
                $prefTs  = $dayDt->getTimestamp();
                $prefEnd = $prefTs + $needed;

                // Check that the slot starts within this working range and is not in the past
                if ($prefTs >= $rs && $prefTs < $re && $prefTs >= $nowTs) {
                    // For slots that may cross midnight, check that the entire duration
                    // is covered by working hours (possibly spanning multiple ranges)
                    $isFullyCovered = false;
                    $checkEnd = $prefTs;
                    foreach ($list as $coverRange) {
                        $coverStart = (int) ($coverRange['start'] ?? 0);
                        $coverEnd = (int) ($coverRange['end'] ?? 0);
                        // If this range continues from where we left off (or overlaps)
                        if ($coverStart <= $checkEnd + 60 && $coverEnd > $checkEnd) {
                            $checkEnd = max($checkEnd, $coverEnd);
                            if ($checkEnd >= $prefEnd) {
                                $isFullyCovered = true;
                                break;
                            }
                        }
                    }
                    
                    if ($isFullyCovered && $isFree($prefTs, $prefEnd)) {
                        $date = $dayDt->format('Y-m-d');
                        if (!in_array($date, $availableDates, true)) {
                            $availableDates[] = $date;
                        }
                    }
                }
            } else {
                // Standard mode: include the day if any free slot exists.
                $cursor = max($rs, $nowTs);
                $cursor = (int) ceil($cursor / $grid) * $grid;

                while ($cursor < $re) {
                    $slotEnd = $cursor + $needed;
                    
                    // Check if the entire slot is covered by working hours
                    $isFullyCovered = false;
                    $checkEnd = $cursor;
                    foreach ($list as $coverRange) {
                        $coverStart = (int) ($coverRange['start'] ?? 0);
                        $coverEnd = (int) ($coverRange['end'] ?? 0);
                        if ($coverStart <= $checkEnd + 60 && $coverEnd > $checkEnd) {
                            $checkEnd = max($checkEnd, $coverEnd);
                            if ($checkEnd >= $slotEnd) {
                                $isFullyCovered = true;
                                break;
                            }
                        }
                    }
                    
                    if ($isFullyCovered && $isFree($cursor, $slotEnd)) {
                        $dt   = (new DateTime('@' . $cursor))->setTimezone($timezone);
                        $date = $dt->format('Y-m-d');
                        if (!in_array($date, $availableDates, true)) {
                            $availableDates[] = $date;
                        }
                        $dayEnd = (new DateTime($date . ' 23:59:59', $timezone))->getTimestamp();
                        $cursor = $dayEnd;
                        break;
                    }
                    $cursor += $grid;
                    
                    // Stop if we've gone past the end of this range
                    if ($cursor >= $re) {
                        break;
                    }
                }
            }
        }

        sort($availableDates);
        return $availableDates;
    }

    /**
     * Return all free time windows for a given date so the user can browse
     * availability before deciding whether to book.
     *
     * @param array<string,mixed> $context
     * @return list<array{start_local:string,end_local:string}>
     */
    public function getAvailableWindows(string $date, array $context): array
    {
        $tz    = (string) ($context['timezone'] ?? 'UTC');
        $calId = (string) ($context['calendar_id'] ?? '');
        if ($calId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }

        try {
            $timezone = new DateTimeZone($tz);
            $from     = new DateTime($date . ' 00:00:00', $timezone);
            $to       = (clone $from)->modify('+1 day');
            $window   = $this->availabilityService->getAvailabilityList($calId, $from, $to, $timezone);
            $ranges   = $window->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $list   = isset($ranges['list']) && is_array($ranges['list']) ? $ranges['list'] : [];
        $booked = isset($ranges['booked_intervals']) && is_array($ranges['booked_intervals']) ? $ranges['booked_intervals'] : [];
        usort($booked, static fn($a, $b) => ((int) ($a['start'] ?? 0)) <=> ((int) ($b['start'] ?? 0)));

        // Use 15 min as the minimum slot — shows all meaningful windows.
        return $this->collapseFreeWindows($list, $booked, 15, $timezone, time());
    }

    /**
     * Find the earliest free slot of the given duration within the next 30
     * days. Used by the "find earliest" UI shortcut so the user doesn't have
     * to pick a date/time manually.
     *
     * Walks the schedule's working ranges forward, skipping booked intervals,
     * snapping to a 15-minute grid. Returns null if nothing fits within 30 days.
     *
     * @param array<string,mixed> $context
     * @return array{slot_start:int, date:string, time:string}|null
     */
    public function findEarliestSlot(int $duration, array $context): ?array
    {
        $tz    = (string) ($context['timezone'] ?? 'UTC');
        $calId = (string) ($context['calendar_id'] ?? '');
        if ($calId === '' || $duration <= 0) {
            return null;
        }

        try {
            $timezone = new DateTimeZone($tz);
            $from     = new DateTime('now', $timezone);
            $to       = (clone $from)->modify('+30 days');
            $window   = $this->availabilityService->getAvailabilityList($calId, $from, $to, $timezone);
            $ranges   = $window->toArray();
        } catch (\Throwable $e) {
            return null;
        }

        $list   = isset($ranges['list']) && is_array($ranges['list']) ? $ranges['list'] : [];
        $booked = isset($ranges['booked_intervals']) && is_array($ranges['booked_intervals']) ? $ranges['booked_intervals'] : [];
        usort($booked, static fn($a, $b) => ((int) ($a['start'] ?? 0)) <=> ((int) ($b['start'] ?? 0)));

        $needed = $duration * 60;
        $nowTs  = time();
        $grid   = 15 * 60;

        $isFree = static function (int $slotStart, int $slotEnd) use ($booked): bool {
            foreach ($booked as $b) {
                $bs = (int) ($b['start'] ?? 0);
                $be = (int) ($b['end'] ?? 0);
                if ($bs >= $slotEnd) {
                    break;
                }
                if ($bs < $slotEnd && $be > $slotStart) {
                    return false;
                }
            }
            return true;
        };

        // Helper to check if slot is fully covered by working hours
        $isSlotCovered = static function (int $slotStart, int $slotEnd) use ($list): bool {
            $checkEnd = $slotStart;
            foreach ($list as $coverRange) {
                $coverStart = (int) ($coverRange['start'] ?? 0);
                $coverEnd = (int) ($coverRange['end'] ?? 0);
                if ($coverStart <= $checkEnd + 60 && $coverEnd > $checkEnd) {
                    $checkEnd = max($checkEnd, $coverEnd);
                    if ($checkEnd >= $slotEnd) {
                        return true;
                    }
                }
            }
            return false;
        };

        foreach ($list as $range) {
            $rs = (int) ($range['start'] ?? 0);
            $re = (int) ($range['end'] ?? 0);
            if ($rs <= 0 || $re <= $rs) {
                continue;
            }
            $rawCursor    = max($rs, $nowTs);
            $snappedCursor = (int) ceil($rawCursor / $grid) * $grid;

            // If the snapped cursor overshoots the range end (narrow window near
            // end of day), try the exact current-time position first — it may
            // still fit before the range ends (e.g. now=14:53, range ends 15:05,
            // duration=10 → 14:53+10=15:03 fits, but snap to 15:00+10=15:10 > 15:05).
            // HOWEVER: Don't use rawCursor if it equals nowTs (current time),
            // because the current minute is already partially elapsed and will be
            // marked as busy when checked. Only use rawCursor for past range starts.
            $rawSlotEnd = $rawCursor + $needed;
            if ($rawCursor !== $nowTs && $isSlotCovered($rawCursor, $rawSlotEnd) && $isFree($rawCursor, $rawSlotEnd)) {
                $dt = (new DateTime('@' . $rawCursor))->setTimezone($timezone);
                return [
                    'slot_start' => $rawCursor,
                    'date'       => $dt->format('Y-m-d'),
                    'time'       => $dt->format('H:i'),
                ];
            }

            $cursor = $snappedCursor;
            while ($cursor < $re) {
                $slotEnd = $cursor + $needed;
                if ($isSlotCovered($cursor, $slotEnd) && $isFree($cursor, $slotEnd)) {
                    $dt = (new DateTime('@' . $cursor))->setTimezone($timezone);
                    return [
                        'slot_start' => $cursor,
                        'date'       => $dt->format('Y-m-d'),
                        'time'       => $dt->format('H:i'),
                    ];
                }
                $cursor += $grid;
                
                // Stop if we've gone past the end of this range
                if ($cursor >= $re) {
                    break;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $list
     * @param array<int,array<string,mixed>> $booked
     * @return list<array{start_local:string,end_local:string}>
     */
    private function collapseFreeWindows(array $list, array $booked, int $duration, DateTimeZone $tz, int $nowTs): array
    {
        $needed = $duration * 60;
        $out    = [];
        $fmt    = static function (int $ts) use ($tz): string {
            $dt = (new DateTime('@' . $ts))->setTimezone($tz);
            return $dt->format('H:i');
        };

        $isFree = static function (int $slotStart, int $slotEnd) use ($booked): bool {
            foreach ($booked as $b) {
                $bs = (int) ($b['start'] ?? 0);
                $be = (int) ($b['end'] ?? 0);
                if ($bs >= $slotEnd) {
                    break;
                }
                if ($bs < $slotEnd && $be > $slotStart) {
                    return false;
                }
            }
            return true;
        };

        foreach ($list as $range) {
            $rs = (int) ($range['start'] ?? 0);
            $re = (int) ($range['end'] ?? 0);
            if ($rs <= 0 || $re <= $rs) {
                continue;
            }

            // Walk the booked intervals inside this range to carve out free segments.
            $cursor = max($rs, $nowTs);
            $stops  = [$re];
            foreach ($booked as $b) {
                $bs = (int) ($b['start'] ?? 0);
                $be = (int) ($b['end'] ?? 0);
                if ($be <= $rs || $bs >= $re) {
                    continue;
                }
                $stops[] = $bs;
                $stops[] = max($be, $cursor);
            }
            sort($stops);

            $segments = [];
            $segStart = $cursor;
            foreach ($stops as $stop) {
                if ($stop <= $segStart) {
                    continue;
                }
                if ($isFree($segStart, $stop) && $stop - $segStart >= $needed) {
                    $segments[] = [$segStart, $stop];
                }
                // advance past any booked block at this stop
                foreach ($booked as $b) {
                    $bs = (int) ($b['start'] ?? 0);
                    $be = (int) ($b['end'] ?? 0);
                    if ($bs === $stop) {
                        $segStart = max($be, $stop);
                        continue 2;
                    }
                }
                $segStart = $stop;
            }

            foreach ($segments as [$a, $b]) {
                $out[] = ['start_local' => $fmt($a), 'end_local' => $fmt($b)];
            }
        }

        // Deduplicate
        $seen = [];
        $dedup = [];
        foreach ($out as $w) {
            $k = $w['start_local'] . '-' . $w['end_local'];
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $dedup[]  = $w;
            }
        }
        return $dedup;
    }

    /**
     * @return list<array{id:string,label:string,datetime:string,duration:int}>
     */
    public function listUpcomingMeetings(array $context, string $guestEmail = ''): array
    {
        $calId    = (string) ($context['calendar_id'] ?? '');
        $timezone = (string) ($context['timezone'] ?? 'UTC');
        if ($calId === '') {
            return [];
        }

        // If the authenticated user IS the calendar owner (their email matches
        // the schedule email), show ALL upcoming meetings on the schedule —
        // they are the organiser and need to manage guest bookings.
        // Otherwise filter to only meetings where the user is the booker (guest).
        $calendarEmail = trim((string) ($context['calendar_email'] ?? ''));
        $userEmail     = trim((string) ($context['user_email'] ?? ''));
        $isOwner       = $userEmail !== ''
            && $calendarEmail !== ''
            && strcasecmp($userEmail, $calendarEmail) === 0;

        $email = trim($guestEmail);
        if (!$isOwner) {
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                foreach (['user_email', 'guest_email'] as $k) {
                    $candidate = trim((string) ($context[$k] ?? ''));
                    if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                        $email = $candidate;
                        break;
                    }
                }
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [];
            }
        }

        try {
            $tz    = new DateTimeZone($timezone);
            $now   = new DateTime('now', $tz);
            $until = (clone $now)->modify('+' . self::MEETING_LIST_HORIZON_MONTHS . ' months');
            // includePending=true so the user can see / reschedule / cancel a
            // guest-public booking that's stuck on email confirmation. Without
            // this they get a "no meetings" reply for a booking they remember
            // making, and have no way to clean it up.
            $rows = $this->bookingRepo->findForCalendar($now, $until, [$calId], null, true);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            // In owner mode every booking on the schedule is included.
            // In guest mode only rows where the booker email matches.
            if (!$isOwner && strcasecmp((string) ($row['email'] ?? ''), $email) !== 0) {
                continue;
            }
            $dtRaw = (string) ($row['datetime'] ?? '');
            if ($dtRaw === '') {
                continue;
            }
            try {
                $dt = new DateTime($dtRaw);
                $dt->setTimezone($tz);
            } catch (\Throwable $e) {
                continue;
            }
            $duration  = (int) ($row['duration'] ?? 30);
            $subject   = trim((string) ($row['subject'] ?? ''));
            $confirmed = !empty($row['meeting_confirmed']);
            $label     = $dt->format('l, F j \\a\\t g:i a') . ' (' . $duration . ' min)';
            // Append subject only when it adds meaningful information.
            // Skip subjects that are just a duration label (e.g., "Duration 30 min")
            // — the duration is already shown in the parenthesised part of the label.
            $subjectIsDurationOnly = $subject !== '' && (bool) preg_match(
                '/^(?:duration|продолжительность|тривалість|длительность)?\s*\d+\s*(?:min|мин|мін|хв|minutes|хвилин)\.?$/iu',
                $subject
            );
            if ($subject !== '' && !$subjectIsDurationOnly) {
                $label .= ' — ' . $subject;
            }
            if (!$confirmed) {
                $label .= ' [pending email confirmation]';
            }
            $out[] = [
                'id'       => strtolower((string) $row['id']),
                'label'    => $label,
                'datetime' => $dt->format('c'),
                'duration' => $duration,
            ];
        }
        usort($out, static fn($a, $b) => $a['datetime'] <=> $b['datetime']);
        return array_slice($out, 0, 10);
    }

    /**
     * @return array{ok:bool, message?:string, meeting_id?:string, awaiting_email_confirmation?:bool}
     */
    public function book(int $slotStart, int $duration, string $name, string $email, string $phone, array $context): array
    {
        $tz      = (string) ($context['timezone'] ?? 'UTC');
        $calId   = (string) ($context['calendar_id'] ?? '');
        if ($calId === '' || $slotStart <= 0 || $duration <= 0) {
            return ['ok' => false, 'message' => 'Missing booking context.'];
        }

        try {
            $dt = (new DateTime('@' . $slotStart))->setTimezone(new DateTimeZone($tz));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Invalid timezone or timestamp.'];
        }

        $nameParts = explode(' ', trim($name), 2);
        $firstName = trim($nameParts[0] ?? '');
        $lastName  = trim($nameParts[1] ?? '');

        if ($this->scheduleRequiresEmailVerification($context)) {
            // Pending booking + confirmation email — same as BookingAjaxHandler::bookingRequest.
            $schedule = $this->scheduleRepo->findById($calId);
            $subject  = is_array($schedule) ? trim((string) ($schedule['subject'] ?? '')) : '';

            try {
                $bookingId = $this->bookingRepo->create(
                    $calId, $email, $phone, $firstName, $lastName, $subject, '', $duration, $dt
                );
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => 'Failed to create booking.'];
            }
            if ($bookingId === '') {
                return ['ok' => false, 'message' => 'Failed to create booking.'];
            }

            $holdExpiry = new DateTime('now', new DateTimeZone('UTC'));
            $holdExpiry->modify('+15 minutes');
            $this->bookingService->setExpiration($bookingId, $holdExpiry);

            $emailSent = false;
            try {
                $emailSent = $this->notificationService->sendBookingConfirmationEmail($bookingId);
            } catch (\Throwable $e) {
                $emailSent = false;
            }
            if (!$emailSent) {
                return [
                    'ok'                          => false,
                    'message'                     => 'Booking created but confirmation email could not be sent.',
                    'meeting_id'                  => $bookingId,
                    'awaiting_email_confirmation' => true,
                ];
            }
            return [
                'ok'                          => true,
                'meeting_id'                  => $bookingId,
                'awaiting_email_confirmation' => true,
            ];
        }

        $result = $this->bookingService->bookAndConfirm(
            $calId, $email, $phone, $firstName, $lastName, '', '', $duration, $dt
        );
        if (!$result['ok']) {
            return $result;
        }
        return ['ok' => true, 'meeting_id' => (string) ($result['booking_id'] ?? '')];
    }

    /**
     * @return array{ok:bool, message?:string, awaiting_email_confirmation?:bool}
     */
    public function reschedule(string $meetingId, int $slotStart, int $duration, array $context): array
    {
        $tz = (string) ($context['timezone'] ?? 'UTC');
        if ($meetingId === '' || $slotStart <= 0 || $duration <= 0) {
            return ['ok' => false, 'message' => 'Missing reschedule context.'];
        }
        try {
            $dt = (new DateTime('@' . $slotStart))->setTimezone(new DateTimeZone($tz));
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Invalid timezone.'];
        }

        if ($this->scheduleRequiresEmailVerification($context)) {
            // Pending reschedule + confirmation email — same as BookingAjaxHandler
            // when require_email_verification is enabled (any booker, not only guests).
            $dtUtc = clone $dt;
            $dtUtc->setTimezone(new DateTimeZone('UTC'));
            $exp = new DateTime('now', new DateTimeZone('UTC'));
            $exp->modify('+1 hour');

            $pending = [
                'datetime' => $dtUtc->format('Y-m-d H:i:sP'),
                'duration' => $duration,
                'timezone' => $tz,
            ];

            $this->bookingRepo->update($meetingId, [
                'meeting_confirmed'          => 0,
                'expiration_of_confirmation' => $exp->format('Y-m-d H:i:sP'),
                'pending_reschedule'         => json_encode($pending),
            ]);

            $emailSent = false;
            try {
                $emailSent = $this->notificationService->sendBookingConfirmationEmail($meetingId);
            } catch (\Throwable $e) {
                $emailSent = false;
            }

            if (!$emailSent) {
                return [
                    'ok'                          => false,
                    'message'                     => 'Reschedule request created but confirmation email could not be sent.',
                    'awaiting_email_confirmation' => true,
                ];
            }
            return [
                'ok'                          => true,
                'awaiting_email_confirmation' => true,
            ];
        }

        return $this->bookingService->rescheduleMeeting($meetingId, $dt, $duration, $tz);
    }

    /**
     * @return array{ok:bool, message?:string, awaiting_email_confirmation?:bool}
     */
    public function cancel(string $meetingId, array $context): array
    {
        if ($meetingId === '') {
            return ['ok' => false, 'message' => 'No meeting selected.'];
        }

        if ($this->scheduleRequiresEmailVerification($context)) {
            // Email confirmation before cancel — only when the schedule requires it.
            $emailSent = false;
            try {
                $emailSent = $this->notificationService->sendCancelConfirmationEmail($meetingId);
            } catch (\Throwable $e) {
                $emailSent = false;
            }
            if (!$emailSent) {
                return [
                    'ok'                          => false,
                    'message'                     => 'Cancellation confirmation email could not be sent.',
                    'awaiting_email_confirmation' => true,
                ];
            }
            return [
                'ok'                          => true,
                'awaiting_email_confirmation' => true,
            ];
        }

        return $this->bookingService->cancelMeeting($meetingId);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function scheduleRequiresEmailVerification(array $context): bool
    {
        if (array_key_exists('require_email_verification', $context)) {
            return !empty($context['require_email_verification']);
        }
        $calId = (string) ($context['calendar_id'] ?? '');
        if ($calId === '') {
            return false;
        }
        $schedule = $this->scheduleRepo->findById($calId);
        if (!is_array($schedule)) {
            return false;
        }

        return ($schedule['require_email_verification'] ?? false) === true
            || ($schedule['require_email_verification'] ?? false) === 't';
    }
}
