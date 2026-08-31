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
use Apexianlab\Calendar\Repository\ScheduleRepository;
use Apexianlab\Calendar\Service\Mail\MailDebugLog;

final class BookingService
{
    private ?IntegrationService $integrationService = null;
    private ?NotificationService $notificationService = null;

    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly ScheduleRepository $scheduleRepository,
    ) {
    }

    public function setIntegrationService(IntegrationService $svc): void
    {
        $this->integrationService = $svc;
    }

    public function setNotificationService(NotificationService $svc): void
    {
        $this->notificationService = $svc;
    }

    // ------------------------------------------------------------------
    //  High-level operations (shared by Calendar UI and AI Assistant)
    // ------------------------------------------------------------------

    /**
     * Create a booking, push to external calendar, confirm, and notify.
     *
     * @return array{ok:bool,booking_id?:string,meeting_join_url?:string,message?:string}
     */
    public function bookAndConfirm(
        string $scheduleId,
        string $email,
        string $phone,
        string $firstName,
        string $lastName,
        string $subject,
        string $description,
        int $duration,
        DateTime $dateTime
    ): array {
        $schedule = $this->scheduleRepository->findById($scheduleId);
        if ($schedule === null) {
            return ['ok' => false, 'message' => 'Schedule not found.'];
        }

        if ($subject === '') {
            $subject = trim((string) ($schedule['subject'] ?? ''));
        }

        try {
            $bookingId = $this->bookingRepository->create(
                $scheduleId,
                $email,
                $phone,
                $firstName,
                $lastName,
                $subject,
                $description,
                $duration,
                $dateTime
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Failed to create booking.'];
        }

        if ($bookingId === '') {
            return ['ok' => false, 'message' => 'Failed to create booking.'];
        }

        if ($this->integrationService !== null) {
            try {
                $pushed = $this->integrationService->pushEvent($bookingId);
            } catch (\Throwable $e) {
                $pushed = false;
            }
            if (!$pushed) {
                return ['ok' => false, 'message' => 'Failed to create calendar events.'];
            }
        }

        $this->confirm($bookingId);

        // SMTP confirmation emails (attendee + organiser) take ~10-20s.
        // Defer them to WP cron so the booking response returns immediately.
        wp_schedule_single_event(time() + 1, self::CRON_CONFIRMED_EMAILS, [$bookingId]);

        $meetingRow = $this->bookingRepository->findById($bookingId);
        $joinUrl    = is_array($meetingRow) && !empty($meetingRow['meeting_join_url'])
            ? (string) $meetingRow['meeting_join_url'] : '';

        return [
            'ok'               => true,
            'booking_id'       => $bookingId,
            'meeting_join_url' => $joinUrl,
        ];
    }

    /**
     * Cancel (soft-delete) a meeting and remove external calendar events.
     *
     * @return array{ok:bool,message?:string}
     */
    public const CRON_CANCEL_CLEANUP   = 'apexianlab_cleanup_cancelled_meeting';
    public const CRON_CONFIRMED_EMAILS = 'apexianlab_send_confirmed_emails';
    public const CRON_GCAL_SYNC        = 'apexianlab_gcal_sync';

    public function cancelMeeting(string $meetingId): array
    {
        $booking = $this->bookingRepository->findById($meetingId);
        if ($booking === null) {
            return ['ok' => false, 'message' => 'Meeting not found or already cancelled.'];
        }

        $deleted = $this->bookingRepository->softDelete($meetingId);
        if (!$deleted) {
            return ['ok' => false, 'message' => 'Failed to cancel the meeting.'];
        }

        // External cleanup (Google) is slow and not user-blocking —
        // defer to WP cron so the response returns immediately.
        wp_schedule_single_event(time() + 1, self::CRON_CANCEL_CLEANUP, [$meetingId]);

        return ['ok' => true];
    }

    /** WP cron callback: delete external events (Google) for a soft-deleted meeting. */
    public function cleanupCancelledMeeting(string $meetingId): void
    {
        $booking = $this->bookingRepository->findByIdAnyState($meetingId);
        if ($booking === null) {
            return;
        }
        $scheduleId = (string) ($booking['calendar_schedule_id'] ?? '');
        $schedule   = $scheduleId !== '' ? $this->scheduleRepository->findById($scheduleId) : null;

        $this->deleteExternalEvents($booking, $schedule);

        // Send explicit cancellation emails to both organiser and attendee.
        // Only for confirmed meetings: unconfirmed bookings never reached the organiser's calendar.
        $wasConfirmed = in_array($booking['meeting_confirmed'] ?? false, [true, 't', '1', 1], true);
        if ($wasConfirmed && $this->notificationService !== null) {
            try {
                $this->notificationService->sendBookingCancelledEmailToOrganiser($meetingId);
            } catch (\Throwable $e) { /* non-fatal */ }
            try {
                $this->notificationService->sendBookingCancelledEmailToAttendee($meetingId);
            } catch (\Throwable $e) { /* non-fatal */ }
        }
    }

    /** Poll Google: mirror external cancel/reschedule of plugin events into bookings. */
    public function syncGoogleExternalChanges(int $limit = 200): void
    {
        if (!class_exists(GoogleOAuthHandler::class)) {
            return;
        }

        $candidates = $this->bookingRepository->findConfirmedUpcomingWithGoogleEvent($limit);
        if ($candidates === []) {
            return;
        }

        $handler = GoogleOAuthHandler::getInstance();
        $tokens  = [];

        foreach ($candidates as $row) {
            $meetingId  = (string) ($row['id'] ?? '');
            $scheduleId = (string) ($row['calendar_schedule_id'] ?? '');
            $eventId    = (string) ($row['google_event_id'] ?? '');
            $calendarId = trim((string) ($row['google_calendar_id'] ?? ''));
            $calendarId = $calendarId !== '' ? $calendarId : 'primary';
            if ($meetingId === '' || $scheduleId === '' || $eventId === '') {
                continue;
            }

            $schedule   = $this->scheduleRepository->findById($scheduleId);
            $ownerEmail = is_array($schedule) ? trim((string) ($schedule['email'] ?? '')) : '';
            if ($ownerEmail === '') {
                continue;
            }

            if (!array_key_exists($ownerEmail, $tokens)) {
                $tokens[$ownerEmail] = $handler->getAccessTokenForScheduleEmail($ownerEmail);
            }
            $token = $tokens[$ownerEmail];
            if ($token === null || $token === '') {
                continue;
            }

            $result = GoogleCalendarService::getEvent($token, $eventId, $calendarId);
            $code   = (int) ($result['code'] ?? 0);
            $event  = $result['event'] ?? null;
            $status = is_array($event) ? (string) ($event['status'] ?? '') : '';

            // Gone → cancel. Transient errors skipped (no false cancel).
            if ($code === 404 || $code === 410 || ($code === 200 && $status === 'cancelled')) {
                $this->applyExternalCancel($meetingId);
                continue;
            }

            if ($code === 200 && $status === 'confirmed' && is_array($event)) {
                $this->applyExternalRescheduleIfChanged($meetingId, $row, $event);
            }
        }
    }

    private function applyExternalCancel(string $meetingId): void
    {
        // Event already gone in Google — clear link, soft-delete, notify. No deleteEvent.
        $this->bookingRepository->update($meetingId, [
            'meeting_join_url'   => null,
            'google_event_id'    => null,
            'google_calendar_id' => null,
        ]);

        if (!$this->bookingRepository->softDelete($meetingId)) {
            return;
        }

        if ($this->notificationService !== null) {
            try {
                $this->notificationService->sendBookingCancelledEmailToOrganiser($meetingId);
            } catch (\Throwable $e) { /* non-fatal */ }
            try {
                $this->notificationService->sendBookingCancelledEmailToAttendee($meetingId);
            } catch (\Throwable $e) { /* non-fatal */ }
        }
    }

    /**
     * @param array<string, mixed> $row    Booking row (datetime, duration)
     * @param array<string, mixed> $event  Google event body
     */
    private function applyExternalRescheduleIfChanged(string $meetingId, array $row, array $event): void
    {
        $newStart = $this->parseGoogleDateTimeUtc($event['start'] ?? null);
        $newEnd   = $this->parseGoogleDateTimeUtc($event['end'] ?? null);
        if ($newStart === null || $newEnd === null) {
            return;
        }

        $newDuration = (int) round(($newEnd->getTimestamp() - $newStart->getTimestamp()) / 60);
        if ($newDuration <= 0) {
            return;
        }

        try {
            $oldStart = new DateTime((string) ($row['datetime'] ?? ''), new DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return;
        }
        $oldDuration = (int) ($row['duration'] ?? 0);

        if ($oldStart->getTimestamp() === $newStart->getTimestamp() && $oldDuration === $newDuration) {
            return;
        }

        $updated = $this->bookingRepository->update($meetingId, [
            'datetime'          => $newStart->format('Y-m-d H:i:sP'),
            'duration'          => $newDuration,
            'previous_datetime' => $oldStart->format('Y-m-d H:i:sP'),
        ]);
        if ($updated === '') {
            return;
        }

        if ($this->notificationService !== null) {
            try {
                $this->notificationService->sendExternalRescheduledEmails($meetingId);
            } catch (\Throwable $e) { /* non-fatal */ }
        }
    }

    /**
     * @param mixed $node Google start/end node ({dateTime} or {date})
     */
    private function parseGoogleDateTimeUtc($node): ?DateTime
    {
        if (!is_array($node)) {
            return null;
        }
        $raw = '';
        if (!empty($node['dateTime'])) {
            $raw = (string) $node['dateTime'];
        } elseif (!empty($node['date'])) {
            $raw = (string) $node['date'];
        }
        if ($raw === '') {
            return null;
        }
        try {
            return (new DateTime($raw))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * WP cron callback: send the two confirmation emails for a confirmed booking.
     */
    public function sendConfirmationEmailsAsync(string $bookingId): void
    {
        if ($this->notificationService === null) {
            MailDebugLog::add('cron: notificationService is null, booking ' . $bookingId);
            return;
        }
        try {
            MailDebugLog::add('cron: sending confirmation emails for booking ' . $bookingId);
            $this->notificationService->sendBookingConfirmEmail($bookingId);
            $this->notificationService->sendBookingConfirmEmailToOrganiser($bookingId);
        } catch (\Throwable $e) {
            MailDebugLog::add('cron: ' . get_class($e) . ' — ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /**
     * Reschedule a meeting: clean up old external events, update the
     * booking row, push new events, confirm, and notify.
     *
     * @return array{ok:bool,booking_id?:string,meeting_join_url?:string,message?:string}
     */
    public function rescheduleMeeting(
        string $meetingId,
        DateTime $newDateTime,
        int $duration,
        ?string $attendeeTimezone = null
    ): array {
        $booking = $this->bookingRepository->findById($meetingId);
        if ($booking === null) {
            return ['ok' => false, 'message' => 'Meeting not found.'];
        }

        $scheduleId = (string) ($booking['calendar_schedule_id'] ?? '');
        $schedule   = $scheduleId !== '' ? $this->scheduleRepository->findById($scheduleId) : null;
        if ($schedule === null) {
            return ['ok' => false, 'message' => 'Schedule not found.'];
        }

        // Capture pre-reschedule datetime (stored as UTC) for "rescheduled from X" emails.
        $previousDate = null;
        try {
            $previousDate = new DateTime((string) $booking['datetime'], new DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            $previousDate = null;
        }

        // Delete the old Google events and clear their reference
        // columns so pushEvent() recreates everything for the new slot.
        if ($this->integrationService !== null) {
            $this->integrationService->resetExternalEvents($meetingId);
        }

        $dtUtc = clone $newDateTime;
        $dtUtc->setTimezone(new DateTimeZone('UTC'));
        $exp = new DateTime('now', new DateTimeZone('UTC'));
        $exp->modify('+1 hour');

        $updates = [
            'datetime'                   => $dtUtc->format('Y-m-d H:i:sP'),
            'duration'                   => $duration,
            'meeting_confirmed'          => 1,
            'expiration_of_confirmation' => $exp->format('Y-m-d H:i:sP'),
            // Remember the pre-reschedule slot so a later "resend" from the
            // Reschedule-confirmed page re-sends the reschedule email (with the
            // old → new time) instead of a fresh booking confirmation. Mirrors
            // ExternalSyncService, which already persists previous_datetime.
            'previous_datetime'          => $previousDate !== null ? $previousDate->format('Y-m-d H:i:sP') : null,
            'previous_timezone'          => (string) ($booking['timezone'] ?? ''),
            // Drop any stale payload from an abandoned non-OAuth reschedule.
            'pending_reschedule'         => null,
        ];
        // Persist the attendee's picker choice so subsequent emails / cancel
        // views display in the timezone they just selected.
        $newTz = $attendeeTimezone !== null ? trim($attendeeTimezone) : '';
        if ($newTz !== '') {
            $updates['timezone'] = TimeHelper::canonicalTimezone($newTz);
        }
        $this->bookingRepository->update($meetingId, $updates);

        if ($this->integrationService !== null) {
            try {
                $pushed = $this->integrationService->pushEvent($meetingId);
            } catch (\Throwable $e) {
                $pushed = false;
            }
            if (!$pushed) {
                return ['ok' => false, 'message' => 'Failed to create calendar events.'];
            }
        }

        $this->confirm($meetingId);

        if ($this->notificationService !== null) {
            try {
                $this->notificationService->sendBookingConfirmEmail($meetingId, $previousDate);
                $this->notificationService->sendBookingConfirmEmailToOrganiser($meetingId, $previousDate);
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        $meetingRow = $this->bookingRepository->findById($meetingId);
        $joinUrl    = is_array($meetingRow) && !empty($meetingRow['meeting_join_url'])
            ? (string) $meetingRow['meeting_join_url'] : '';

        return [
            'ok'               => true,
            'booking_id'       => $meetingId,
            'meeting_join_url' => $joinUrl,
        ];
    }

    // ------------------------------------------------------------------
    //  Existing helpers
    // ------------------------------------------------------------------

    public function isExpirationValid(string $bookingId): bool
    {
        $booking = $this->bookingRepository->findById($bookingId);
        if ($booking === null || !isset($booking['expiration_of_confirmation'])) {
            return false;
        }

        $expiration = new DateTime($booking['expiration_of_confirmation']);
        $now = new DateTime();

        $diffSeconds = $expiration->getTimestamp() - $now->getTimestamp();
        if ($diffSeconds < 0) {
            return false;
        }

        return ($diffSeconds / 3600) <= 1;
    }

    public function isNonceValid(string $bookingId, string $nonce): bool
    {
        $booking = $this->bookingRepository->findById($bookingId);
        if ($booking === null) {
            return false;
        }

        $token = (string) ($booking['confirmation_token'] ?? '');
        $provided = (string) $nonce;

        if ($token === '' || $provided === '') {
            if ($token === '' && $provided !== '') {
                $this->bookingRepository->update($bookingId, ['confirmation_token' => $provided]);
                return true;
            }
            return false;
        }

        return hash_equals($token, $provided);
    }

    /**
     * True when $token matches the booking's stored cancel_token and the
     * cancel_token_expiration is still in the future.
     */
    public function isCancelTokenValid(string $bookingId, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $booking = $this->bookingRepository->findById($bookingId);
        if ($booking === null) {
            return false;
        }

        $stored = (string) ($booking['cancel_token'] ?? '');
        if ($stored === '') {
            return false;
        }

        $expiresRaw = (string) ($booking['cancel_token_expiration'] ?? '');
        if ($expiresRaw === '') {
            return false;
        }

        try {
            $expires = new \DateTime($expiresRaw);
        } catch (\Throwable $e) {
            return false;
        }

        if ($expires < new \DateTime('now', new \DateTimeZone('UTC'))) {
            return false;
        }

        return hash_equals($stored, $token);
    }

    public function isConfirmed(string $bookingId): bool
    {
        $booking = $this->bookingRepository->findById($bookingId);
        if ($booking === null) {
            return false;
        }

        $v = $booking['meeting_confirmed'] ?? false;

        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }

    public function confirm(string $bookingId): void
    {
        // Keep confirmation_token so repeat clicks on the email link still
        // resolve the booking and land on the "already confirmed" view.
        $this->bookingRepository->update($bookingId, [
            'meeting_confirmed' => 1,
        ]);
    }

    public function setExpiration(string $bookingId, ?\DateTime $datetime = null): void
    {
        if ($datetime === null) {
            $datetime = new DateTime('now', new DateTimeZone('UTC'));
            $datetime->modify('+1 hour');
        }

        $this->bookingRepository->update($bookingId, [
            'expiration_of_confirmation' => $datetime->format('Y-m-d H:i:sP'),
        ]);
    }

    /**
     * @param array<string, mixed> $schedule
     */
    public function normalizeReminderMinutes(array $schedule): int
    {
        return TimeHelper::normalizeReminderMinutes($schedule);
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed>      $booking
     * @param array<string,mixed>|null $schedule
     */
    private function deleteExternalEvents(array $booking, ?array $schedule): void
    {
        $meetingId = (string) ($booking['id'] ?? '');
        if ($this->integrationService !== null && $meetingId !== '') {
            try {
                $this->integrationService->cancelGoogleMeeting($meetingId);
            } catch (\Throwable $e) {
                // best-effort
            }
        }
    }

}
