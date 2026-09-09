<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Ajax;

use DateTime;
use DateTimeZone;
use Apexianlab\Calendar\Auth\GoogleOAuthHandler;
use Apexianlab\Calendar\Config;
use Apexianlab\Calendar\Dto\SchedulePayload;
use Apexianlab\Calendar\Helper\ColorHelper;
use Apexianlab\Calendar\Helper\DisplayHelper;
use Apexianlab\Calendar\Helper\QrCodeGenerator;
use Apexianlab\Calendar\Helper\TimeHelper;
use Apexianlab\Calendar\Service\AvailabilityService;
use Apexianlab\Calendar\Service\IntegrationService;
use Apexianlab\Calendar\Service\NotificationService;

final class ScheduleAjaxHandler extends AbstractAjaxHandler
{
    private AvailabilityService $availabilityService;

    private NotificationService $notificationService;

    private IntegrationService $integrationService;

    public static function register(): self
    {
        $instance = new self();
        $instance->registerHooks();

        return $instance;
    }

    protected function __construct()
    {
        parent::__construct();

        $this->availabilityService = new AvailabilityService(
            $this->scheduleRepo,
            $this->bookingRepo
        );

        $this->notificationService = new NotificationService(
            $this->bookingRepo,
            $this->scheduleRepo,
            $this->meansRepo
        );

        $this->integrationService = new IntegrationService(
            $this->bookingRepo,
            $this->scheduleRepo,
            $this->meansRepo
        );
    }

    protected function actions(): array
    {
        return [
            'get_schedule_events' => 'getScheduleEvents',
            'create_schedule'     => 'createSchedule',
            'update_schedule'     => 'updateSchedule',
            'delete_schedule'     => 'deleteSchedule',
            'get_schedule_qr'     => 'getScheduleQr',
        ];
    }

    // ------------------------------------------------------------------
    //  AJAX handlers
    // ------------------------------------------------------------------

    public function getScheduleEvents(): void
    {
        $this->verifyNonce('schedule_events_nonce', 'schedule_events_nonce');
        $auth = $this->requireAuth();

        $startStr = $this->post('start');
        $endStr   = $this->post('end');

        if ($startStr === '' || $endStr === '') {
            wp_send_json_success([]);
        }

        try {
            $start = new DateTime($startStr);
            $end   = new DateTime($endStr);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Invalid date range']);
        }

        $scheduleIds  = $this->parseScheduleIds();
        $userEmail    = (string) $auth['email'];

        $events = [];

        $scheduleRanges = $scheduleIds === []
            ? []
            : $this->scheduleRepo->getRangesForCalendar($start, $end, $scheduleIds);
        foreach ($scheduleRanges as $range) {
            $events[] = [
                'id'              => 'range_' . $range['schedule_id'] . '_' . $range['start']->getTimestamp(),
                'title'           => '',
                'start'           => $range['start']->format('c'),
                'end'             => $range['end']->format('c'),
                'backgroundColor' => ColorHelper::backgroundHex($range['color']),
                'borderColor'     => 'transparent',
                'textColor'       => '#000',
                'display'         => 'background',
                'rendering'       => 'background',
                'classNames'      => ['schedule-range'],
            ];
        }

        // Invited-meetings filter toggle, defaults ON for backward compat.
        $includeInvited = !isset($_POST['include_invited'])
            || in_array((string) $_POST['include_invited'], ['1', 'true', 'on'], true);

        $bookings = $this->bookingRepo->findForCalendar(
            $start,
            $end,
            $scheduleIds,
            $includeInvited ? $userEmail : null
        );
        // Owner-slug resolution hits the DB; cache the base booking URL per
        // schedule so a month of events triggers one lookup per calendar.
        $baseBookingUrlCache = [];
        foreach ($bookings as $b) {
            $dt    = new DateTime($b['datetime'], new DateTimeZone('UTC'));
            $endDt = clone $dt;
            $endDt->modify('+' . (int) $b['duration'] . ' minutes');

            $colorCode = isset($b['color']) ? (int) $b['color'] : null;
            $meansTitle = $this->resolveBookingMeansTitle($b);

            $fullSubject = (string) ($b['subject'] ?? '');
            $shortTitle  = mb_strlen($fullSubject) > 21
                ? mb_substr($fullSubject, 0, 21) . '...'
                : $fullSubject;

            $isGuest = !in_array((string) ($b['calendar_schedule_id'] ?? ''), $scheduleIds, true);

            // Pre-build the reschedule URL server-side: it needs the owner
            // slug, which the JS popover cannot derive (legacy /cal/{id}/
            // paths now 404 via SlugRouter).
            $scheduleKey = (string) ($b['calendar_schedule_id'] ?? '');
            if (!array_key_exists($scheduleKey, $baseBookingUrlCache)) {
                $baseBookingUrlCache[$scheduleKey] = DisplayHelper::bookingUrl([
                    'booking_short_id' => (string) ($b['booking_short_id'] ?? ''),
                    'email'            => (string) ($b['schedule_email'] ?? ''),
                    'name'             => (string) ($b['schedule_name'] ?? ''),
                ]);
            }
            $baseBookingUrl = $baseBookingUrlCache[$scheduleKey];
            $meetingId      = (string) ($b['id'] ?? '');
            $rescheduleUrl  = '';
            if ($baseBookingUrl !== '' && $meetingId !== '') {
                $rescheduleUrl = add_query_arg([
                    'meeting_id' => $meetingId,
                    'reschedule' => '1',
                    't'          => DisplayHelper::meetingAccessToken($meetingId),
                ], $baseBookingUrl);
            }

            // Guest bookings flagged via class; colour from CSS, owner palette dropped.
            $eventEntry = [
                'id'              => $b['id'],
                'title'           => $shortTitle,
                'start'           => $dt->format('c'),
                'end'             => $endDt->format('c'),
                'borderColor'     => 'transparent',
                'textColor'       => '#000',
                'display'         => 'block',
                'classNames'      => $isGuest ? ['fc-event--guest'] : [],
                'extendedProps'   => [
                    'meeting_id'                => (string) ($b['id'] ?? ''),
                    'meeting_access_token'      => DisplayHelper::meetingAccessToken((string) ($b['id'] ?? '')),
                    'schedule_id'               => (string) ($b['calendar_schedule_id'] ?? ''),
                    'schedule_booking_short_id' => (string) ($b['booking_short_id'] ?? ''),
                    'reschedule_url'            => $rescheduleUrl,
                    'subject'                   => $fullSubject,
                    'description'               => (string) ($b['description'] ?? ''),
                    'attendee_email'            => (string) ($b['email'] ?? ''),
                    'attendee_name'             => trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')),
                    'organiser_email'           => (string) ($b['schedule_email'] ?? ''),
                    'organiser_name'            => DisplayHelper::scheduleOrganiserName([
                        'name'               => $b['schedule_name'] ?? '',
                        'name_format'        => $b['name_format'] ?? 'full',
                        'name_format_custom' => $b['name_format_custom'] ?? '',
                    ]),
                    'phone'                     => (string) ($b['phone'] ?? ''),
                    'meeting_join_url'          => (string) ($b['meeting_join_url'] ?? ''),
                    'location'                  => $meansTitle,
                    'is_guest'                  => $isGuest,
                ],
            ];

            // Own events keep backgroundColor; guest events painted by CSS.
            if (!$isGuest) {
                $eventEntry['backgroundColor'] = ColorHelper::backgroundHex($colorCode);
            }

            $events[] = $eventEntry;
        }

        wp_send_json_success($events);
    }

    public function createSchedule(): void
    {
        $this->verifyCreateOrCalendarNonce();
        $auth = $this->requireAuth();
        $this->guardGoogleScopes((string) $auth['email']);

        $payload = $this->prepareSchedulePayload($auth['userInfo']);

        try {
            $id = $this->scheduleRepo->create(
                $auth['email'],
                $payload->name,
                $payload->subject,
                $payload->description,
                $payload->meansOfCommunicationId,
                $payload->color,
                $payload->reminderMinutes,
                $payload->rangesJson(),
                $payload->isPublic,
                $payload->requireEmailVerification,
                Config::getCalendarProdid(),
                $payload->scheduleRepeat,
                $payload->repeatIntervalWeeks,
                $payload->nameFormat,
                $payload->nameFormatCustom,
                $payload->weeklyRuleJson(),
                $payload->durationsJson(),
                $payload->additionalRecipientsJson()
            );
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        // Mirror availability windows into the owner's Google calendar
        // asynchronously (transparent/free events) — keeps the response fast.
        wp_schedule_single_event(time() + 1, IntegrationService::CRON_SYNC_SCHEDULE, [$id]);

        // Best-effort notification — never block schedule creation on email.
        try {
            $this->notificationService->sendScheduleCreatedEmail($id);
        } catch (\Throwable $e) {
            // Swallow: the schedule was created successfully.
        }

        wp_send_json_success(['id' => $id, 'subject' => $payload->subject]);
    }

    public function updateSchedule(): void
    {
        $this->verifyNonce('schedule_manage_nonce', 'schedule_manage_nonce');
        $auth = $this->requireAuth();
        $this->guardGoogleScopes((string) $auth['email']);
        $id   = $this->validateUuid($this->post('id'));

        $payload  = $this->prepareSchedulePayload($auth['userInfo']);
        $previous = $this->scheduleRepo->findById($id);

        $updated = $this->scheduleRepo->update($id, $auth['email'], [
            'name'                                => $payload->name,
            'subject'                             => $payload->subject,
            'description'                         => $payload->description,
            'calendar_means_of_communication_id'  => $payload->meansOfCommunicationId,
            'color'                               => $payload->color,
            'reminder_minutes'                    => $payload->reminderMinutes,
            'schedule_ranges'                     => $payload->rangesJson(),
            'schedule_weekly_rule'                => $payload->weeklyRuleJson(),
            'is_public'                           => $payload->isPublic,
            'require_email_verification'          => $payload->requireEmailVerification,
            'schedule_repeat'                     => $payload->scheduleRepeat,
            'repeat_interval_weeks'               => $payload->repeatIntervalWeeks,
            'name_format'                         => $payload->nameFormat,
            'name_format_custom'                  => $payload->nameFormatCustom,
            'durations'                           => $payload->durationsJson(),
            'additional_recipients'               => $payload->additionalRecipientsJson(),
        ]);

        if (!$updated) {
            wp_send_json_error(['message' => 'Cannot update this schedule']);
        }

        // Re-sync Google events only when availability changed, not on metadata edits.
        if ($this->scheduleAvailabilityChanged(is_array($previous) ? $previous : null, $payload)) {
            wp_schedule_single_event(time() + 1, IntegrationService::CRON_SYNC_SCHEDULE, [$id]);
        }

        // Notify attendees only when fields visible in their confirmation email changed.
        $attendeeRelevantChanged = is_array($previous) && (
            (string) ($previous['subject'] ?? '')                              !== $payload->subject
            || (string) ($previous['description'] ?? '')                       !== $payload->description
            || (string) ($previous['calendar_means_of_communication_id'] ?? '') !== $payload->meansOfCommunicationId
        );

        // Organiser always gets one schedule-level notification.
        try {
            $this->notificationService->sendScheduleUpdatedNotification($id);
        } catch (\Throwable $e) {
            error_log('[Apexianlab] sendScheduleUpdatedNotification failed for ' . $id . ': ' . $e->getMessage());
        }

        // Attendees notified only when their booking-visible fields changed.
        if ($attendeeRelevantChanged) {
            foreach ($this->bookingRepo->findConfirmedUpcomingIdsBySchedule($id) as $meetingId) {
                try {
                    $this->notificationService->sendScheduleUpdatedEmail($meetingId);
                } catch (\Throwable $e) {
                    error_log('[Apexianlab] sendScheduleUpdatedEmail failed for ' . $meetingId . ': ' . $e->getMessage());
                }
            }
        }

        wp_send_json_success(['id' => $id, 'subject' => $payload->subject]);
    }

    /** True when fields driving the Google events changed. */
    private function scheduleAvailabilityChanged(?array $previous, SchedulePayload $payload): bool
    {
        if ($previous === null) {
            return true;
        }

        return (string) ($previous['calendar_means_of_communication_id'] ?? '') !== $payload->meansOfCommunicationId
            || (string) ($previous['schedule_repeat'] ?? '')       !== $payload->scheduleRepeat
            || (string) ($previous['repeat_interval_weeks'] ?? '') !== (string) $payload->repeatIntervalWeeks
            || !$this->sameJson($previous['schedule_ranges'] ?? null, $payload->rangesJson())
            || !$this->sameJson($previous['schedule_weekly_rule'] ?? null, $payload->weeklyRuleJson())
            || !$this->sameJson($previous['durations'] ?? null, $payload->durationsJson());
    }

    /** Order-insensitive JSON equality (JSONB re-orders keys; null/[]/'' all empty). */
    private function sameJson($a, $b): bool
    {
        return $this->canonJson($a) === $this->canonJson($b);
    }

    /** @param mixed $json */
    private function canonJson($json): string
    {
        $value = json_decode((string) $json, true);
        if (!is_array($value) || $value === []) {
            return '[]';
        }
        $this->ksortRecursive($value);

        return (string) json_encode($value);
    }

    /** @param array<mixed> $arr */
    private function ksortRecursive(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }

    public function deleteSchedule(): void
    {
        $this->verifyNonce('schedule_manage_nonce', 'schedule_manage_nonce');
        $auth = $this->requireAuth();
        $id   = $this->validateUuid($this->post('id'));

        // Read the schedule (for its Google mirror event IDs) before soft-delete.
        $schedule = $this->scheduleRepo->findById($id);

        if (!$this->scheduleRepo->softDelete($id, $auth['email'])) {
            wp_send_json_error(['message' => 'Cannot delete this schedule']);
        }

        // Remove the mirror events from Google asynchronously (the row is now
        // soft-deleted, so pass the owner email + captured IDs to the cron job).
        $ids = $this->integrationService->extractEventIds(is_array($schedule) ? ($schedule['google_schedule_event_ids'] ?? null) : null);
        if ($ids !== []) {
            wp_schedule_single_event(time() + 1, IntegrationService::CRON_DELETE_SCHEDULE, [$auth['email'], $ids]);
        }

        wp_send_json_success([]);
    }

    /**
     * Build a QR code (as a data URI) for a schedule's public booking link.
     */
    public function getScheduleQr(): void
    {
        $this->verifyNonce('schedule_manage_nonce', 'schedule_manage_nonce');
        $auth = $this->requireAuth();
        $id   = $this->validateUuid($this->post('id'));

        $schedule = $this->scheduleRepo->findById($id);
        if ($schedule === null || (string) ($schedule['email'] ?? '') !== $auth['email']) {
            wp_send_json_error(['message' => 'Cannot access this schedule']);
        }

        $bookingUrl = DisplayHelper::bookingUrl($schedule);
        if ($bookingUrl === '') {
            wp_send_json_error(['message' => 'This schedule has no shareable link.']);
        }

        $qr = QrCodeGenerator::toDataUri($bookingUrl);
        if ($qr === null) {
            wp_send_json_error(['message' => 'Could not generate the QR code.']);
        }

        // Download filename: {owner-slug}-{schedule-title}-qr.png
        $slug     = '';
        $segments = array_values(array_filter(explode('/', (string) wp_parse_url($bookingUrl, PHP_URL_PATH))));
        if (isset($segments[0])) {
            $slug = (string) $segments[0];
        }
        // Keep Unicode letters readable (sanitize_title would percent-encode
        // Cyrillic); strip only filesystem-unsafe characters.
        $subjectSlug = trim((string) ($schedule['subject'] ?? ''));
        $subjectSlug = (string) preg_replace('/\s+/u', '-', $subjectSlug);
        $subjectSlug = (string) preg_replace('/[^\p{L}\p{N}_-]+/u', '', $subjectSlug);
        $subjectSlug = trim(mb_strtolower($subjectSlug), '-');

        $parts    = array_filter([$slug, $subjectSlug, 'qr']);
        $filename = ($parts !== [] ? implode('-', $parts) : 'schedule-qr') . '.png';

        wp_send_json_success([
            'qr'       => $qr,
            'url'      => $bookingUrl,
            'filename' => $filename,
        ]);
    }

    // ------------------------------------------------------------------
    //  Private: schedule payload builder
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $userInfo
     */
    private function prepareSchedulePayload(array $userInfo): SchedulePayload
    {
        [$nameFormat, $nameFormatCustom] = $this->parseNameFormat();

        $authorFullName = mb_substr(DisplayHelper::fullNameFromUserInfo($userInfo), 0, 64);
        $scheduleTitle  = $this->post('name');
        $subject        = $this->post('subject') !== '' ? $this->post('subject') : $scheduleTitle;
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by createSchedule()/updateSchedule() (verifyCreateOrCalendarNonce / verifyNonce) before this private helper runs.
        $description    = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash((string) $_POST['description'])) : '';
        $calendarMeansId = $this->post('calendar_means_of_communication_id');
        $color          = isset($_POST['color']) ? (int) $_POST['color'] : null;
        $leadValue      = $this->postInt('booking_lead_value', 2);
        $leadUnit       = $this->post('booking_lead_unit', 'hours');
        $timezone       = TimeHelper::canonicalTimezone($this->post('timezone', 'UTC'));
        $dateFrom       = $this->post('date_from');
        $dateTo         = $this->post('date_to');
        $isPublic = true;
        if (isset($_POST['is_public'])) {
            $isPublic = in_array((string) $_POST['is_public'], ['1', 'true', 'on'], true);
        }
        $requireEmailVerification = isset($_POST['require_email_verification'])
            && in_array((string) $_POST['require_email_verification'], ['1', 'true', 'on'], true);

        $repeatRaw         = $this->post('repeat', 'does_not_repeat');
        $intervalFromPost   = max(1, min(52, $this->postInt('repeat_interval_weeks', 1)));

        [$scheduleRepeat, $intervalWeeks] = match ($repeatRaw) {
            'weekly'          => ['weekly', 1],
            'custom'          => ['custom', $intervalFromPost],
            default           => ['does_not_repeat', 1],
        };

        $repeatEndNever = isset($_POST['repeat_end_never'])
            && in_array((string) $_POST['repeat_end_never'], ['1', 'true', 'on'], true);

        if ($repeatEndNever && $dateFrom !== '') {
            try {
                $dateTo = (new \DateTimeImmutable($dateFrom))
                    ->modify('+10 years')
                    ->format('Y-m-d');
            } catch (\Exception $e) {
                // keep posted date_to
            }
        }

        if ($scheduleTitle === '' || $calendarMeansId === '') {
            wp_send_json_error(['message' => 'Schedule title and Calendar are required']);
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $calendarMeansId)) {
            wp_send_json_error(['message' => 'Invalid calendar selected. Please choose a calendar from the list.']);
        }

        $reminderMinutes = $leadUnit === 'hours' ? $leadValue * 60 : $leadValue;
        $reminderMinutes = max(0, min(20160, $reminderMinutes));

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw JSON payload, decoded via json_decode immediately below.
        $dailySlotsRaw = isset($_POST['daily_slots']) ? wp_unslash($_POST['daily_slots']) : '';
        $dailySlots    = is_string($dailySlotsRaw) ? json_decode($dailySlotsRaw, true) : $dailySlotsRaw;
        if (!is_array($dailySlots)) {
            $dailySlots = [];
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $nonRepeatingRaw   = $this->postRaw('non_repeating_slots');
        $nonRepeatingSlots = null;
        if ($nonRepeatingRaw !== '') {
            $decoded = json_decode($nonRepeatingRaw, true);
            if (is_array($decoded)) {
                $nonRepeatingSlots = $decoded;
            }
        }

        $weeklyRule         = null;
        $materializedDateTo = $dateTo;

        if ($scheduleRepeat === 'weekly' || $scheduleRepeat === 'custom') {
            $weeklyRule = [
                'date_from'             => $dateFrom,
                'date_to'               => $repeatEndNever ? null : ($dateTo !== '' ? $dateTo : null),
                'timezone'              => $timezone,
                'daily_slots'           => $dailySlots,
                'repeat_interval_weeks' => $intervalWeeks,
            ];
            $materializedDateTo = $this->capMaterializationDate($dateFrom, $dateTo, $repeatEndNever);
        }

        if ($scheduleRepeat === 'does_not_repeat' && is_array($nonRepeatingSlots) && $nonRepeatingSlots !== []) {
            $scheduleRanges = $this->buildNonRepeatingRanges($nonRepeatingSlots, $timezone);
        } else {
            $scheduleRanges = AvailabilityService::buildScheduleRanges(
                $dateFrom,
                $materializedDateTo,
                $timezone,
                $dailySlots,
                $scheduleRepeat,
                $intervalWeeks
            );
        }

        if ($scheduleRanges === []) {
            wp_send_json_error(['message' => 'At least one time slot is required']);
        }

        $durations = $this->sanitizeDurations($this->postRaw('durations'));

        $additionalRecipients = $this->sanitizeRecipients($this->postRaw('additional_recipients'));

        return new SchedulePayload(
            name: $authorFullName,
            subject: $subject,
            description: $description,
            meansOfCommunicationId: $calendarMeansId,
            color: $color,
            reminderMinutes: $reminderMinutes,
            scheduleRanges: $scheduleRanges,
            weeklyRule: $weeklyRule,
            isPublic: $isPublic,
            requireEmailVerification: $requireEmailVerification,
            scheduleRepeat: $scheduleRepeat,
            repeatIntervalWeeks: $intervalWeeks,
            nameFormat: $nameFormat,
            nameFormatCustom: $nameFormatCustom,
            durations: $durations,
            additionalRecipients: $additionalRecipients,
        );
    }

    /**
     * Parse additional CC recipients from a comma/newline/semicolon-separated
     * string. Keeps only valid, lower-cased, de-duplicated addresses (max 20).
     * Invalid fragments are silently dropped so a single typo never blocks the
     * save — the field is optional.
     *
     * @return array<int, string>
     */
    private function sanitizeRecipients(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        // Accept a JSON array (sent by the modal) or a plain delimited string.
        $candidates = [];
        $decoded    = json_decode($raw, true);
        if (is_array($decoded)) {
            $candidates = $decoded;
        } else {
            $candidates = preg_split('/[\s,;]+/', $raw) ?: [];
        }

        $clean = [];
        foreach ($candidates as $candidate) {
            $email = sanitize_email((string) $candidate);
            if ($email === '' || !is_email($email)) {
                continue;
            }
            $clean[strtolower($email)] = true;
        }

        return array_slice(array_keys($clean), 0, 20);
    }

    /**
     * @return array<int, int>
     */
    private function sanitizeDurations(string $raw): array
    {
        $default = [15, 30, 45, 60, 90];
        if ($raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            wp_send_json_error(['message' => 'Add at least one meeting duration.']);
        }
        $clean = [];
        foreach ($decoded as $v) {
            $n = (int) $v;
            if ($n >= 1 && $n <= 1440) {
                $clean[$n] = true;
            }
        }
        if ($clean === []) {
            wp_send_json_error(['message' => 'Add at least one meeting duration.']);
        }
        $list = array_keys($clean);
        sort($list, SORT_NUMERIC);
        return array_slice($list, 0, 20);
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    /**
     * @return array{0: string, 1: string|null}
     */
    private function parseNameFormat(): array
    {
        $allowed = ['full', 'first_last_initial', 'initial_last', 'first_only', 'initials', 'custom'];
        $fmt     = $this->post('name_format', 'full');

        if (!in_array($fmt, $allowed, true)) {
            $fmt = 'full';
        }

        $custom = mb_substr($this->post('name_format_custom'), 0, 128);

        if ($fmt !== 'custom') {
            return [$fmt, null];
        }

        return [$fmt, $custom !== '' ? $custom : null];
    }

    private function capMaterializationDate(string $dateFrom, string $dateTo, bool $repeatEndNever): string
    {
        try {
            $from = new \DateTimeImmutable($dateFrom);
            $cap  = $from->modify('+24 months');

            if (!$repeatEndNever && $dateTo !== '') {
                $userTo = new \DateTimeImmutable($dateTo);
                if ($userTo < $cap) {
                    $cap = $userTo;
                }
            }

            return $cap->format('Y-m-d');
        } catch (\Exception $e) {
            return $dateTo;
        }
    }

    /**
     * @param array<int, array{date?: string, start?: string, end?: string}> $rows
     * @return array<int, array{date: string, start: string, end: string, timezone: string}>
     */
    private function buildNonRepeatingRanges(array $rows, string $timezone): array
    {
        $ranges = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = isset($row['date']) ? sanitize_text_field((string) $row['date']) : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            $start = TimeHelper::normalizeTimeTo24h((string) ($row['start'] ?? ''));
            $end   = TimeHelper::normalizeTimeTo24h((string) ($row['end'] ?? ''));
            if ($start === '' || $end === '' || $start === $end) {
                continue;
            }

            if ($start < $end) {
                $ranges[] = [
                    'date'     => $date,
                    'start'    => $start,
                    'end'      => $end,
                    'timezone' => $timezone,
                ];
            } else {
                // Cross-midnight: split at 24:00/00:00 so mergeOverlappingIntervals
                // can join adjacent ranges into one contiguous block.
                $ranges[] = [
                    'date'     => $date,
                    'start'    => $start,
                    'end'      => '24:00',
                    'timezone' => $timezone,
                ];
                try {
                    $nextDay = (new \DateTime($date))->modify('+1 day')->format('Y-m-d');
                } catch (\Exception $e) {
                    continue;
                }
                $ranges[] = [
                    'date'     => $nextDay,
                    'start'    => '00:00',
                    'end'      => $end,
                    'timezone' => $timezone,
                ];
            }
        }

        usort($ranges, static fn(array $a, array $b): int =>
            strcmp($a['date'], $b['date']) ?: strcmp($a['start'], $b['start']));

        return $ranges;
    }

    /**
     * @return array<string>
     */
    private function parseScheduleIds(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by deleteSchedule() before this private helper runs.
        if (!isset($_POST['schedule_ids'])) {
            return [];
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized element-wise via sanitize_text_field just below.
        $raw = wp_unslash($_POST['schedule_ids']);
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $ids = is_array($raw)
            ? array_map('sanitize_text_field', $raw)
            : [sanitize_text_field((string) $raw)];

        return array_values(array_filter($ids));
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function resolveBookingMeansTitle(array $booking): string
    {
        if (empty($booking['calendar_means_of_communication_id'])) {
            return '';
        }

        try {
            $means = $this->meansRepo->findById((string) $booking['calendar_means_of_communication_id']);
            return !empty($means['title']) ? (string) $means['title'] : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function guardGoogleScopes(string $email): void
    {
        $missing = GoogleOAuthHandler::getInstance()->getMissingScopesForEmail($email);
        if ($missing === []) {
            return;
        }

        wp_send_json_error([
            'message'        => 'Google access is incomplete. Reconnect your Google account and grant every requested permission (calendar and sending email) so attendees can book meetings.',
            'code'           => 'missing_scopes',
            'missing_scopes' => array_values(array_map([GoogleOAuthHandler::class, 'scopeLabel'], $missing)),
        ]);
    }

    private function verifyCreateOrCalendarNonce(): void
    {
        $createValid = isset($_POST['create_schedule_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['create_schedule_nonce'])), 'create_schedule_nonce');
        $calendarValid = isset($_POST['calendar_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['calendar_nonce'])), 'calendar_nonce_action');

        if (!$createValid && !$calendarValid) {
            wp_send_json_error(['message' => 'Invalid security']);
        }
    }
}
