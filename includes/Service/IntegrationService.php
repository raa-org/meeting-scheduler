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
use Apexianlab\Calendar\Helper\TimeHelper;
use Apexianlab\Calendar\Repository\BookingRepository;
use Apexianlab\Calendar\Repository\MeansOfCommunicationRepository;
use Apexianlab\Calendar\Repository\ScheduleRepository;

final class IntegrationService
{
    /** Async (wp-cron) hooks — keep Google round-trips out of the request. */
    public const CRON_SYNC_SCHEDULE   = 'apexianlab_sync_schedule_google';
    public const CRON_DELETE_SCHEDULE = 'apexianlab_delete_schedule_google';

    public function __construct(
        private readonly BookingRepository $bookingRepo,
        private readonly ScheduleRepository $scheduleRepo,
        private readonly MeansOfCommunicationRepository $meansRepo,
    ) {
    }

    public function pushEvent(string $meetingId): bool
    {
        $meeting = $this->bookingRepo->findById($meetingId);
        if ($meeting === null) {
            return false;
        }

        $schedule = $this->scheduleRepo->findById((string) $meeting['calendar_schedule_id']);
        if ($schedule === null) {
            return false;
        }

        $reminderMinutes = TimeHelper::normalizeReminderMinutes($schedule);

        $start = new DateTime((string) $meeting['datetime'], new DateTimeZone('UTC'));
        if (!empty($meeting['timezone'])) {
            $start->setTimezone(new DateTimeZone(TimeHelper::canonicalTimezone((string) $meeting["timezone"])));
        }
        $end = clone $start;
        $end->modify('+' . (int) $meeting['duration'] . ' minutes');

        $means      = $this->meansRepo->findById((string) $schedule['calendar_means_of_communication_id']);
        $meansTitle = isset($means['title']) ? trim((string) $means['title']) : '';
        $meansKey   = strtolower($meansTitle);

        $locationLink = !empty($meeting['meeting_join_url']) ? (string) $meeting['meeting_join_url'] : '';


        if ($locationLink === '' && $meansKey === 'google calendar') {
            $locationLink = $this->createGoogleEvent($meeting, $schedule, $start, $end, $reminderMinutes, $meetingId);
        }

        return true;
    }

    /**
     * Tear down the external Google Calendar event tied to a booking so a
     * following pushEvent() recreates it. Used by the reschedule path.
     */
    public function resetExternalEvents(string $meetingId): void
    {
        $meeting = $this->bookingRepo->findByIdAnyState($meetingId);
        if ($meeting === null) {
            return;
        }

        // Nothing was pushed yet — skip the external round-trip entirely.
        if (empty($meeting['meeting_join_url']) && empty($meeting['google_event_id'])) {
            return;
        }

        // Deletes the Google Calendar event and nulls the google_* columns.
        try {
            $this->cancelGoogleMeeting($meetingId);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    public function cancelGoogleMeeting(string $meetingId): void
    {
        // Use findByIdAnyState because cancel cleanup runs after the booking
        // is soft-deleted.
        $meeting = $this->bookingRepo->findByIdAnyState($meetingId);
        if ($meeting === null || empty($meeting['calendar_schedule_id'])) {
            return;
        }

        $schedule = $this->scheduleRepo->findById((string) $meeting['calendar_schedule_id']);
        if ($schedule === null) {
            return;
        }

        $means      = $this->meansRepo->findById((string) $schedule['calendar_means_of_communication_id']);
        $meansTitle = isset($means['title']) ? trim((string) $means['title']) : '';
        if (strtolower($meansTitle) !== 'google calendar') {
            return;
        }

        if (!class_exists(GoogleOAuthHandler::class)) {
            return;
        }

        $scheduleEmail = !empty($schedule['email']) ? (string) $schedule['email'] : '';
        $eventId       = !empty($meeting['google_event_id']) ? (string) $meeting['google_event_id'] : '';
        $calendarId    = !empty($meeting['google_calendar_id']) ? (string) $meeting['google_calendar_id'] : 'primary';

        if ($scheduleEmail === '' || $eventId === '') {
            return;
        }

        $token = GoogleOAuthHandler::getInstance()->getAccessTokenForScheduleEmail($scheduleEmail);
        if (empty($token)) {
            return;
        }

        try {
            GoogleCalendarService::deleteEvent($token, $eventId, $calendarId);
            $this->bookingRepo->update($meetingId, [
                'meeting_join_url'   => null,
                'google_event_id'    => null,
                'google_calendar_id' => null,
            ]);
        } catch (\Throwable $e) {
            // Silently fail to avoid breaking booking flow.
        }
    }

    /**
     * Mirror a schedule's availability windows into the owner's selected
     * Google calendar as transparent (free) events — recurring for weekly
     * schedules, single dated events otherwise. Returns the created event IDs
     * (also persisted on the schedule). Best-effort: only for the
     * "Google Calendar" means and a connected owner.
     *
     * @return list<string>
     */
    public function pushScheduleEvents(string $scheduleId): array
    {
        if (!class_exists(GoogleOAuthHandler::class)) {
            return [];
        }

        $schedule = $this->scheduleRepo->findById($scheduleId);
        if ($schedule === null) {
            return [];
        }

        $means      = $this->meansRepo->findById((string) ($schedule['calendar_means_of_communication_id'] ?? ''));
        $meansTitle = isset($means['title']) ? trim((string) $means['title']) : '';
        if (strtolower($meansTitle) !== 'google calendar') {
            return [];
        }

        $email = trim((string) ($schedule['email'] ?? ''));
        if ($email === '') {
            return [];
        }

        $handler = GoogleOAuthHandler::getInstance();
        $token   = $handler->getAccessTokenForScheduleEmail($email);
        if (empty($token)) {
            return [];
        }
        $calendarId = $handler->getSelectedCalendarId($email);

        $subject     = trim((string) ($schedule['subject'] ?? ''));
        $name        = trim((string) ($schedule['name'] ?? ''));
        $summary     = $subject !== '' ? $subject : ($name !== '' ? $name : 'Availability');
        $description = trim((string) ($schedule['description'] ?? ''));

        $ids = [];
        foreach ($this->buildScheduleEventPayloads($schedule, $summary, $description) as $payload) {
            try {
                $created = GoogleCalendarService::createEvent($token, $payload, $calendarId, false, false);
                if (is_array($created) && !empty($created['id'])) {
                    $ids[] = (string) $created['id'];
                }
            } catch (\Throwable $e) {
                // best-effort
            }
        }

        $this->scheduleRepo->setGoogleScheduleEventIds($scheduleId, $ids);

        return $ids;
    }

    /**
     * Cron entry point: remove any previously-created schedule mirror events.
     * RAACAL-176 — availability is no longer mirrored as Google events, so this
     * only cleans up leftovers; it never (re)creates them. Booking events are
     * handled separately and unaffected.
     */
    public function syncScheduleGoogle(string $scheduleId): void
    {
        $schedule = $this->scheduleRepo->findById($scheduleId);
        if ($schedule === null) {
            return;
        }
        $email  = trim((string) ($schedule['email'] ?? ''));
        $oldIds = $this->extractEventIds($schedule['google_schedule_event_ids'] ?? null);
        if ($email !== '' && $oldIds !== []) {
            $this->deleteScheduleEvents($email, $oldIds);
            $this->scheduleRepo->setGoogleScheduleEventIds($scheduleId, []);
        }
    }

    /**
     * Normalize stored event IDs (JSON string or array) into a list.
     *
     * @param mixed $raw
     * @return list<string>
     */
    public function extractEventIds($raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $eid) {
            $eid = (string) $eid;
            if ($eid !== '') {
                $out[] = $eid;
            }
        }

        return $out;
    }

    /**
     * Delete previously created schedule mirror events. Best-effort.
     *
     * @param list<string> $eventIds
     */
    public function deleteScheduleEvents(string $email, array $eventIds, ?string $calendarId = null): void
    {
        if ($eventIds === [] || !class_exists(GoogleOAuthHandler::class)) {
            return;
        }
        $email = trim($email);
        if ($email === '') {
            return;
        }

        $handler = GoogleOAuthHandler::getInstance();
        $token   = $handler->getAccessTokenForScheduleEmail($email);
        if (empty($token)) {
            return;
        }
        $calId = ($calendarId !== null && $calendarId !== '') ? $calendarId : $handler->getSelectedCalendarId($email);

        foreach ($eventIds as $eid) {
            $eid = (string) $eid;
            if ($eid === '') {
                continue;
            }
            try {
                GoogleCalendarService::deleteEvent($token, $eid, $calId, false);
            } catch (\Throwable $e) {
                // best-effort
            }
        }
    }

    /**
     * Build Google event payloads for a schedule's availability windows.
     *
     * @param array<string, mixed> $schedule
     * @return list<array<string, mixed>>
     */
    private function buildScheduleEventPayloads(array $schedule, string $summary, string $description): array
    {
        $repeat   = strtolower(trim((string) ($schedule['schedule_repeat'] ?? '')));
        $payloads = [];

        $weeklyRaw = $schedule['schedule_weekly_rule'] ?? null;
        $rule      = is_string($weeklyRaw) && $weeklyRaw !== '' ? json_decode($weeklyRaw, true) : null;

        // 'weekly' and 'custom' both store a weekly rule with daily_slots and a
        // repeat interval (custom = every N weeks) — represent both as a single
        // recurring event per weekday/slot, never expanded into per-day events.
        $isRecurring = in_array($repeat, ['weekly', 'custom'], true);
        if ($isRecurring && is_array($rule) && !empty($rule['daily_slots']) && is_array($rule['daily_slots'])) {
            $tz       = $this->canonicalScheduleTz((string) ($rule['timezone'] ?? 'UTC'));
            $dateFrom = (string) ($rule['date_from'] ?? '');
            if ($dateFrom === '') {
                return [];
            }
            $interval = max(1, (int) ($rule['repeat_interval_weeks'] ?? $schedule['repeat_interval_weeks'] ?? 1));
            $until    = !empty($rule['date_to']) ? $this->rruleUntilUtc((string) $rule['date_to'], $tz) : '';
            $byDay    = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];

            foreach ($rule['daily_slots'] as $dow => $slots) {
                $dow = (int) $dow;
                if (!isset($byDay[$dow]) || !is_array($slots)) {
                    continue;
                }
                foreach ($slots as $slot) {
                    if (!is_array($slot) || empty($slot['start']) || empty($slot['end'])) {
                        continue;
                    }
                    $firstDate = $this->firstWeekdayOnOrAfter($dateFrom, $dow, $tz);
                    if ($firstDate === '') {
                        continue;
                    }
                    $rrule = 'RRULE:FREQ=WEEKLY;INTERVAL=' . $interval . ';BYDAY=' . $byDay[$dow];
                    if ($until !== '') {
                        $rrule .= ';UNTIL=' . $until;
                    }
                    $payloads[] = $this->transparentEventPayload(
                        $summary,
                        $description,
                        $tz,
                        $firstDate . 'T' . $this->normalizeHm((string) $slot['start']) . ':00',
                        $firstDate . 'T' . $this->normalizeHm((string) $slot['end']) . ':00',
                        [$rrule]
                    );
                }
            }

            return $payloads;
        }

        // Non-recurring: concrete dated ranges.
        $rangesRaw = $schedule['schedule_ranges'] ?? null;
        $ranges    = is_string($rangesRaw) && $rangesRaw !== '' ? json_decode($rangesRaw, true) : null;
        if (is_array($ranges)) {
            foreach ($ranges as $range) {
                if (!is_array($range) || empty($range['date']) || empty($range['start']) || empty($range['end'])) {
                    continue;
                }
                $tz         = $this->canonicalScheduleTz((string) ($range['timezone'] ?? 'UTC'));
                $payloads[] = $this->transparentEventPayload(
                    $summary,
                    $description,
                    $tz,
                    (string) $range['date'] . 'T' . $this->normalizeHm((string) $range['start']) . ':00',
                    (string) $range['date'] . 'T' . $this->normalizeHm((string) $range['end']) . ':00',
                    []
                );
            }
        }

        return $payloads;
    }

    /**
     * @param list<string> $recurrence
     * @return array<string, mixed>
     */
    private function transparentEventPayload(
        string $summary,
        string $description,
        string $tz,
        string $startLocal,
        string $endLocal,
        array $recurrence
    ): array {
        $payload = [
            'summary'      => $summary,
            'description'  => $description,
            'transparency' => 'transparent',
            'visibility'   => 'private',
            'start'        => ['dateTime' => $startLocal, 'timeZone' => $tz],
            'end'          => ['dateTime' => $endLocal, 'timeZone' => $tz],
            'reminders'    => ['useDefault' => false],
        ];
        if ($recurrence !== []) {
            $payload['recurrence'] = $recurrence;
        }

        return $payload;
    }

    private function canonicalScheduleTz(string $tz): string
    {
        $tz = str_replace('Europe/Kiev', 'Europe/Kyiv', trim($tz));
        try {
            new DateTimeZone($tz);

            return $tz;
        } catch (\Throwable $e) {
            return 'UTC';
        }
    }

    /** Normalize "9:0"/"09:00"/"24:00" → "HH:MM" (clamps 24:00 → 23:59, Google rejects 24:00). */
    private function normalizeHm(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '00:00';
        }
        $parts = explode(':', $value);
        $h     = (int) ($parts[0] ?? 0);
        $m     = (int) ($parts[1] ?? 0);
        if ($h >= 24) {
            $h = 23;
            $m = 59;
        }

        return sprintf('%02d:%02d', max(0, $h), max(0, min(59, $m)));
    }

    /** First date on/after $date whose ISO weekday (1=Mon..7=Sun) equals $isoDow. */
    private function firstWeekdayOnOrAfter(string $date, int $isoDow, string $tz): string
    {
        try {
            $d = new DateTime($date, new DateTimeZone($tz));
        } catch (\Throwable $e) {
            return '';
        }
        $delta = ($isoDow - (int) $d->format('N') + 7) % 7;
        if ($delta > 0) {
            $d->modify('+' . $delta . ' days');
        }

        return $d->format('Y-m-d');
    }

    /** date_to end-of-day in $tz → RFC5545 UTC stamp for RRULE UNTIL. */
    private function rruleUntilUtc(string $dateTo, string $tz): string
    {
        try {
            $d = new DateTime($dateTo . ' 23:59:59', new DateTimeZone($tz));
            $d->setTimezone(new DateTimeZone('UTC'));

            return $d->format('Ymd\THis\Z');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function createGoogleEvent(
        array $meeting,
        array $schedule,
        DateTime $start,
        DateTime $end,
        int $reminderMinutes,
        string $meetingId
    ): string {
        if (!class_exists(GoogleOAuthHandler::class)) {
            return '';
        }

        $scheduleEmail = !empty($schedule['email']) ? (string) $schedule['email'] : '';
        if ($scheduleEmail === '') {
            return '';
        }

        try {
            $handler = GoogleOAuthHandler::getInstance();
            $token   = $handler->getAccessTokenForScheduleEmail($scheduleEmail);
            if (empty($token)) {
                return '';
            }

            $subject  = !empty($meeting['subject']) ? (string) $meeting['subject'] : (string) ($schedule['subject'] ?? 'Meeting');
            $tzRaw    = !empty($meeting['timezone']) ? (string) $meeting['timezone'] : 'UTC';
            $eventTz  = str_replace('Europe/Kiev', 'Europe/Kyiv', $tzRaw);

            $description = isset($meeting['description']) ? trim((string) $meeting['description']) : '';
            $phone       = isset($meeting['phone']) ? trim((string) $meeting['phone']) : '';
            if ($phone !== '') {
                $description .= ($description !== '' ? "\n" : '') . 'Phone: ' . $phone;
            }

            $payload = [
                'summary'     => $subject,
                'description' => $description,
                'start' => [
                    'dateTime' => $start->format('Y-m-d\TH:i:s'),
                    'timeZone' => $eventTz,
                ],
                'end' => [
                    'dateTime' => $end->format('Y-m-d\TH:i:s'),
                    'timeZone' => $eventTz,
                ],
                'conferenceData' => [
                    'createRequest' => [
                        'requestId' => 'apexianlab-' . wp_generate_uuid4(),
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                    ],
                ],
            ];

            $guestEmail = trim((string) ($meeting['email'] ?? ''));
            if ($guestEmail !== '' && filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
                $payload['attendees'] = [
                    [
                        'email'       => $guestEmail,
                        'displayName' => trim((string) ($meeting['first_name'] ?? '') . ' ' . (string) ($meeting['last_name'] ?? '')),
                    ],
                ];
            }

            if ($reminderMinutes > 0) {
                $payload['reminders'] = [
                    'useDefault' => false,
                    'overrides'  => [
                        ['method' => 'popup', 'minutes' => $reminderMinutes],
                    ],
                ];
            } else {
                $payload['reminders'] = ['useDefault' => false];
            }

            $calendarId = $handler->getSelectedCalendarId($scheduleEmail);
            $created = GoogleCalendarService::createEvent($token, $payload, $calendarId, true, true);
            if (!is_array($created) || empty($created['id'])) {
                return '';
            }

            $eventId  = (string) $created['id'];
            $meetUrl  = '';
            if (!empty($created['hangoutLink'])) {
                $meetUrl = (string) $created['hangoutLink'];
            } elseif (!empty($created['conferenceData']['entryPoints']) && is_array($created['conferenceData']['entryPoints'])) {
                foreach ($created['conferenceData']['entryPoints'] as $entry) {
                    if (is_array($entry) && ($entry['entryPointType'] ?? '') === 'video' && !empty($entry['uri'])) {
                        $meetUrl = (string) $entry['uri'];
                        break;
                    }
                }
            }

            $this->bookingRepo->update($meetingId, [
                'meeting_join_url'   => $meetUrl !== '' ? $meetUrl : null,
                'google_event_id'    => $eventId,
                'google_calendar_id' => $calendarId,
            ]);

            return $meetUrl;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Preferred IANA timezone for a schedule: weekly rule first, then first range. Empty if none found.
     *
     * @param array<string, mixed> $schedule
     */
    private function scheduleTimezone(array $schedule): string
    {
        $ruleRaw = $schedule['schedule_weekly_rule'] ?? null;
        if (is_string($ruleRaw) && $ruleRaw !== '') {
            $rule = json_decode($ruleRaw, true);
            if (is_array($rule) && !empty($rule['timezone']) && is_string($rule['timezone'])) {
                return trim($rule['timezone']);
            }
        }

        $rangesRaw = $schedule['schedule_ranges'] ?? null;
        if (is_string($rangesRaw) && $rangesRaw !== '') {
            $ranges = json_decode($rangesRaw, true);
            if (is_array($ranges) && isset($ranges[0]['timezone']) && is_string($ranges[0]['timezone'])) {
                return trim($ranges[0]['timezone']);
            }
        }

        return '';
    }
}
