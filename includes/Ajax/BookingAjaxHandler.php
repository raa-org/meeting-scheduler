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
use Apexianlab\Calendar\Dto\BookingMeeting;
use Apexianlab\Calendar\Helper\DisplayHelper;
use Apexianlab\Calendar\Helper\TimeHelper;
use Apexianlab\Calendar\Service\AvailabilityService;
use Apexianlab\Calendar\Service\BookingService;
use Apexianlab\Calendar\Service\IntegrationService;
use Apexianlab\Calendar\Service\NotificationService;

final class BookingAjaxHandler extends AbstractAjaxHandler
{
    private static ?self $instance = null;

    private AvailabilityService $availabilityService;
    private BookingService $bookingService;
    private IntegrationService $integrationService;
    private NotificationService $notificationService;

    private function __construct()
    {
        parent::__construct();

        $this->availabilityService = new AvailabilityService(
            $this->scheduleRepo,
            $this->bookingRepo
        );
        $this->integrationService = new IntegrationService($this->bookingRepo, $this->scheduleRepo, $this->meansRepo);
        $this->notificationService = new NotificationService($this->bookingRepo, $this->scheduleRepo, $this->meansRepo);
        $this->bookingService = new BookingService($this->bookingRepo, $this->scheduleRepo);
        $this->bookingService->setIntegrationService($this->integrationService);
        $this->bookingService->setNotificationService($this->notificationService);

        $this->registerHooks();
    }

    public static function register(): void
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
    }

    protected function actions(): array
    {
        return [
            'booking_request'                => 'bookingRequest',
            'confirm_booking_request'        => 'confirmBookingRequest',
            'reschedule_meeting_request'     => 'rescheduleMeetingRequest',
            'cansel_meeting_request'         => 'cancelMeetingRequest',
            'request_cancel_meeting'         => 'requestCancelMeeting',
            'confirm_cancel_meeting'         => 'confirmCancelMeeting',
            'resend_confirmed'               => 'resendConfirmed',
            'resend_confirmation'            => 'resendConfirmation',
            'request_new_confirmation_email' => 'requestNewConfirmationEmail',
        ];
    }

    // ------------------------------------------------------------------
    //  bookingRequest
    // ------------------------------------------------------------------

    public function bookingRequest(): void
    {
        $this->verifyNonce('calendar_nonce', 'calendar_nonce_action');

        [$isOAuth, $oauthUserInfo] = $this->resolveOAuthContext();

        $targetEmail = trim($this->postRaw('email'));
        $firstName   = trim($this->postRaw('first_name'));
        $lastName    = trim($this->postRaw('last_name'));
        $phone       = $this->post('phone');

        if ($isOAuth) {
            $targetEmail = $targetEmail !== '' ? $targetEmail : (string) ($oauthUserInfo['email'] ?? $oauthUserInfo['preferred_username'] ?? '');
            [$firstName, $lastName] = $this->fillNamesFromOAuth($firstName, $lastName, $oauthUserInfo);
        }

        if (
            $firstName === ''
            || $targetEmail === ''
            || (!$isOAuth && $this->postRaw('code') === '')
            || $this->postRaw('timezone') === ''
            || $this->postRaw('year') === ''
            || $this->postRaw('month') === ''
            || $this->postRaw('day') === ''
            || $this->postRaw('time') === ''
            || $this->postRaw('duration') === ''
            || $this->postRaw('booking_short_id') === ''
            || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)
        ) {
            $this->sendJsonError(['message' => 'Missing required fields.']);
        }

        $description = $this->postRaw('description') !== '' ? htmlspecialchars($this->postRaw('description')) : '';
        $subject     = $this->postRaw('subject') !== '' ? htmlspecialchars($this->postRaw('subject')) : '';

        // Reject over-long input up front so it can never overflow the booking
        // columns and raise a fatal DB error (form maxlength is client-only).
        if (
            mb_strlen($firstName) > 255
            || mb_strlen($lastName) > 255
            || mb_strlen($targetEmail) > 320
            || mb_strlen($phone) > 32
            || mb_strlen($subject) > 128
            || mb_strlen($description) > 512
        ) {
            $this->sendJsonError(['message' => 'One or more fields are too long.']);
        }

        if (!$isOAuth) {
            $codes = apexianlab_get_session('verify');
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by verifyNonce() at the start of bookingRequest().
            if (empty($codes['booking_form']) || sanitize_text_field(wp_unslash($_POST['code'] ?? '')) !== (string) $codes['booking_form']) {
                $this->sendJsonError(['message' => 'Invalid code.']);
            }
        }

        $schedule   = $this->requireScheduleByShortId();
        $scheduleId = (string) $schedule['id'];

        // Server-side guard mirroring the booking page: a private (non-public)
        // schedule may only be booked by someone in the organiser's own email
        // domain. Prevents a direct POST from bypassing the page-level gate.
        $isSchedulePublic = ($schedule['is_public'] ?? null) === true || ($schedule['is_public'] ?? null) === 't';
        if (!$isSchedulePublic) {
            $ownerDomain  = $this->emailDomain((string) ($schedule['email'] ?? ''));
            $viewerEmail  = $isOAuth ? (string) ($oauthUserInfo['email'] ?? $oauthUserInfo['preferred_username'] ?? '') : '';
            $viewerDomain = $this->emailDomain($viewerEmail);
            if ($ownerDomain !== '' && (!$isOAuth || $viewerDomain !== $ownerDomain)) {
                $this->sendJsonError(['message' => 'This meeting is private. Sign in with an authorised account.'], 403);
            }
        }

        $day      = str_pad($this->postRaw('day'), 2, '0', STR_PAD_LEFT);
        $month    = str_pad($this->postRaw('month'), 2, '0', STR_PAD_LEFT);
        $timezone = new DateTimeZone(TimeHelper::canonicalTimezone($this->postRaw('timezone')));
        $timeNorm = TimeHelper::normalizeTimeTo24h(trim($this->postRaw('time')));
        if ($timeNorm === '') {
            $this->sendJsonError(['message' => 'Invalid date/time.']);
        }
        $date     = new DateTime($this->postRaw('year') . '-' . $month . '-' . $day . ' ' . $timeNorm, $timezone);
        $duration = (int) $this->postRaw('duration');

        // Release any previous unconfirmed hold from this attendee on this
        // schedule before checking availability — otherwise a prior failed
        // attempt (e.g. email error) would permanently block the slot for them.
        $this->bookingRepo->releasePendingHoldsForEmail($targetEmail, $scheduleId);

        $internalOk = $this->availabilityService->isBookingTimeAvailable($scheduleId, $date, $duration);
        if (!$internalOk) {
            $this->wpJsonSlotNotAvailable();
        }

        if ($subject === '') {
            $subject = isset($schedule['subject']) ? trim((string) $schedule['subject']) : '';
        }

        $means      = $this->meansRepo->findById((string) $schedule['calendar_means_of_communication_id']);
        $meansTitle = isset($means['title']) ? trim((string) $means['title']) : '';

        if ($meansTitle === BookingMeeting::COMMUNICATION_TYPE_PHONE_CALL && $phone === '') {
            $this->sendJsonError(['message' => 'Phone is required.']);
        }

        $startUtc = clone $date;
        $startUtc->setTimezone(new DateTimeZone('UTC'));
        $endUtc = clone $startUtc;
        $endUtc->modify('+' . $duration . ' minutes');

        $externalOk = $this->availabilityService->isExternalTimeSlotAvailable($schedule, $meansTitle, $startUtc, $endUtc, null);
        if (!$externalOk) {
            $this->wpJsonSlotNotAvailable();
        }

        $requireEmailVerification = $this->scheduleRequiresEmailVerification($schedule);

        if (!$requireEmailVerification) {
            $result = $this->bookingService->bookAndConfirm(
                $scheduleId, $targetEmail, $phone, $firstName, $lastName,
                $subject, $description, $duration, $date
            );
            if (!$result['ok']) {
                $this->sendJsonError(['message' => $result['message'] ?? 'Something went wrong.']);
            }

            $this->sendJsonSuccess([
                'message'              => 'Booking confirmed',
                'frame'                => 'confirmed',
                'name'                 => DisplayHelper::scheduleOrganiserName($schedule),
                'date'                 => DisplayHelper::formatMeetingDateRange($date, $duration),
                'time'                 => $this->formatTimeLine($date, $duration),
                'location'             => $meansTitle,
                'phone'                => $phone,
                'meeting_join_url'     => $result['meeting_join_url'] ?? '',
                'meeting_id'           => $result['booking_id'] ?? '',
                'meeting_access_token' => DisplayHelper::meetingAccessToken((string) ($result['booking_id'] ?? '')),
            ]);
        }

        $bookingId = $this->bookingRepo->create(
            $scheduleId, $targetEmail, $phone, $firstName, $lastName,
            $subject, $description, $duration, $date
        );

        // Shorten the default 1-hour DB-side hold to 15 minutes so an
        // abandoned booking reopens the slot quickly. The attendee can
        // request a new confirmation email if they need more time.
        $holdExpiry = new DateTime('now', new DateTimeZone('UTC'));
        $holdExpiry->modify('+15 minutes');
        $this->bookingService->setExpiration($bookingId, $holdExpiry);

        $emailSent = $this->notificationService->sendBookingConfirmationEmail($bookingId);
        if (!$emailSent) {
            $this->sendJsonError(['message' => 'Fail send email.']);
        }

        $confirmUrl = DisplayHelper::bookingUrl($schedule, [
            'meeting_id' => (string) $bookingId,
            'frame'      => 'confirm',
        ]);

        $this->sendJsonSuccess([
            'message'              => 'Email sent successfully!',
            'frame'                => 'confirm',
            'name'                 => DisplayHelper::scheduleOrganiserName($schedule),
            'date'                 => DisplayHelper::formatMeetingDateRange($date, $duration),
            'time'                 => $this->formatTimeLine($date, $duration),
            'location'             => $meansTitle,
            'phone'                => $phone,
            'meeting_id'           => (string) $bookingId,
            'meeting_access_token' => DisplayHelper::meetingAccessToken((string) $bookingId),
            'redirect'             => $confirmUrl,
        ]);
    }

    // ------------------------------------------------------------------
    //  confirmBookingRequest
    // ------------------------------------------------------------------

    public function confirmBookingRequest(): void
    {
        $meetingId = $this->post('meeting_id');
        $check     = $this->post('check');

        if ($meetingId === '' || $check === '') {
            $this->sendJsonError(['message' => 'Missing required fields.']);
        }
        $meetingId = $this->validateUuid($meetingId, 'meeting id');

        $meeting = $this->bookingRepo->findById($meetingId);
        if ($meeting === null || empty($meeting['calendar_schedule_id'])) {
            $this->sendJsonError(['message' => 'Meeting not found.']);
        }

        $schedule = $this->scheduleRepo->findById((string) $meeting['calendar_schedule_id']);
        if ($schedule === null || empty($schedule['email'])) {
            $this->sendJsonError(['message' => 'This calendar is no longer available.', 'code' => 'schedule_unavailable']);
        }

        $redirectConfirmed = DisplayHelper::bookingUrl($schedule, [
            'meeting_id' => $meetingId,
            'frame'      => 'confirmed',
        ]);
        $redirectConfirm = DisplayHelper::bookingUrl($schedule, [
            'meeting_id' => $meetingId,
            'frame'      => 'confirm',
        ]);

        if ($this->bookingService->isConfirmed($meetingId)) {
            $this->sendJsonSuccess(['redirect' => $redirectConfirmed]);
        }

        if (!$this->bookingService->isExpirationValid($meetingId)) {
            $this->sendJsonError([
                'message'  => 'Confirmation link has expired.',
                'code'     => 'expired',
                'redirect' => $redirectConfirm,
            ]);
        }

        if (!$this->bookingService->isNonceValid($meetingId, $check)) {
            $this->sendJsonError([
                'message'  => 'Invalid confirmation link.',
                'code'     => 'invalid',
                'redirect' => $redirectConfirm,
            ]);
        }

        // A non-OAuth reschedule is parked in `pending_reschedule` until this
        // confirmation. A plain new-booking confirmation has no payload and
        // uses the row as-is.
        $pendingJson = isset($meeting['pending_reschedule']) ? (string) $meeting['pending_reschedule'] : '';
        $pending     = $pendingJson !== '' ? json_decode($pendingJson, true) : null;
        if (!is_array($pending)) {
            $pending = null;
        }

        $confDatetime = $pending !== null && isset($pending['datetime'])
            ? (string) $pending['datetime']
            : (string) $meeting['datetime'];
        $confDuration = $pending !== null && isset($pending['duration'])
            ? (int) $pending['duration']
            : (int) $meeting['duration'];

        try {
            $means      = $this->meansRepo->findById((string) $schedule['calendar_means_of_communication_id']);
            $meansTitle = isset($means['title']) ? trim((string) $means['title']) : '';
            $dt         = new DateTime($confDatetime, new DateTimeZone('UTC'));
            $end        = clone $dt;
            $end->modify('+' . $confDuration . ' minutes');

            // Race-condition guard: another attendee with a parallel pending
            // hold may have confirmed first. Re-check our DB excluding this
            // booking, but ignore other still-pending holds — first to
            // confirm wins.
            $internalOk = $this->availabilityService->isBookingTimeAvailable(
                (string) $schedule['id'],
                $dt,
                $confDuration,
                $meetingId,
                false
            );
            if (!$internalOk) {
                $this->wpJsonSlotNotAvailable();
            }

            if (!$this->availabilityService->isExternalTimeSlotAvailable($schedule, $meansTitle, $dt, $end, $meeting)) {
                $this->wpJsonSlotNotAvailable();
            }
        } catch (\Throwable $e) {
            $this->wpJsonSlotNotAvailable();
        }

        // Checks passed — only now promote the pending reschedule onto the
        // row, so a failed availability check above never moves the meeting.
        // Capture the pre-reschedule slot first for the "rescheduled from X"
        // line in the confirmation emails.
        $previousDate = null;
        if ($pending !== null) {
            try {
                $previousDate = new DateTime((string) $meeting['datetime'], new DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                $previousDate = null;
            }
            $this->bookingRepo->update($meetingId, array_merge($pending, [
                'pending_reschedule' => null,
                // Persist the pre-reschedule slot so a later "resend" re-sends the
                // reschedule email (old → new time), not a fresh confirmation.
                'previous_datetime'  => $previousDate !== null ? $previousDate->format('Y-m-d H:i:sP') : null,
                'previous_timezone'  => (string) ($meeting['timezone'] ?? ''),
            ]));
            $refreshed = $this->bookingRepo->findById($meetingId);
            if ($refreshed !== null) {
                $meeting = $refreshed;
            }
            $redirectConfirmed = DisplayHelper::bookingUrl($schedule, [
                'meeting_id'    => $meetingId,
                'frame'         => 'confirmed',
                'is_reschedule' => '1',
            ]);
        }

        $this->integrationService->resetExternalEvents($meetingId);

        if (!$this->integrationService->pushEvent($meetingId)) {
            $this->sendJsonError([
                'message'  => 'Failed to create calendar events.',
                'code'     => 'integration_failed',
                'redirect' => DisplayHelper::bookingUrl($schedule, ['frame' => 'creation-event-error']),
            ]);
        }

        $this->bookingService->confirm($meetingId);

        $this->notificationService->sendBookingConfirmEmail($meetingId, $previousDate);
        $this->notificationService->sendBookingConfirmEmailToOrganiser($meetingId, $previousDate);

        $this->sendJsonSuccess(['redirect' => $redirectConfirmed]);
    }

    // ------------------------------------------------------------------
    //  rescheduleMeetingRequest
    // ------------------------------------------------------------------

    public function rescheduleMeetingRequest(): void
    {
        $this->verifyNonce('calendar_nonce', 'calendar_nonce_action');

        [$isOAuth, $oauthUserInfo] = $this->resolveOAuthContext();

        if (
            $this->postRaw('meeting_id') === ''
            || $this->postRaw('timezone') === ''
            || $this->postRaw('year') === ''
            || $this->postRaw('month') === ''
            || $this->postRaw('day') === ''
            || $this->postRaw('time') === ''
            || $this->postRaw('duration') === ''
            || $this->postRaw('booking_short_id') === ''
            || (!$isOAuth && $this->postRaw('code') === '')
        ) {
            $this->sendJsonError(['message' => 'Missing required fields.']);
        }

        if (!$isOAuth) {
            $codes = apexianlab_get_session('verify');
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by verifyNonce() at the start of rescheduleMeetingRequest().
            if (empty($codes['booking_form']) || sanitize_text_field(wp_unslash($_POST['code'] ?? '')) !== (string) $codes['booking_form']) {
                $this->sendJsonError(['message' => 'Invalid code.']);
            }
        }

        $meetingId = $this->validateUuid($this->post('meeting_id'), 'meeting id');
        $this->requireMeetingAccess($meetingId);

        $schedule   = $this->requireScheduleByShortId();
        $scheduleId = (string) $schedule['id'];

        $meeting = $this->bookingRepo->findById($meetingId);
        if ($meeting === null || empty($meeting['calendar_schedule_id'])) {
            $this->sendJsonError(['message' => 'Meeting not found.']);
        }
        if ((string) $meeting['calendar_schedule_id'] !== $scheduleId) {
            $this->sendJsonError(['message' => 'Meeting does not belong to this schedule.']);
        }

        $requireEmailVerification = $this->scheduleRequiresEmailVerification($schedule);

        try {
            $day      = str_pad($this->postRaw('day'), 2, '0', STR_PAD_LEFT);
            $month    = str_pad($this->postRaw('month'), 2, '0', STR_PAD_LEFT);
            $timezone = new DateTimeZone(TimeHelper::canonicalTimezone($this->postRaw('timezone')));
            $timeNorm = TimeHelper::normalizeTimeTo24h(trim($this->postRaw('time')));
            if ($timeNorm === '') {
                $this->sendJsonError(['message' => 'Invalid date/time.']);
            }
            $date     = new DateTime($this->postRaw('year') . '-' . $month . '-' . $day . ' ' . $timeNorm, $timezone);
        } catch (\Exception $e) {
            $this->sendJsonError(['message' => 'Invalid date/time.']);
        }

        $duration = (int) $this->postRaw('duration');
        if ($duration <= 0) {
            $this->sendJsonError(['message' => 'Invalid duration.']);
        }

        if (!$this->availabilityService->isBookingTimeAvailable($scheduleId, $date, $duration, $meetingId)) {
            $this->wpJsonSlotNotAvailable();
        }

        $means      = $this->meansRepo->findById((string) $schedule['calendar_means_of_communication_id']);
        $meansTitle = isset($means['title']) ? trim((string) $means['title']) : '';
        $startUtc   = clone $date;
        $startUtc->setTimezone(new DateTimeZone('UTC'));
        $endUtc = clone $startUtc;
        $endUtc->modify('+' . $duration . ' minutes');

        if (!$this->availabilityService->isExternalTimeSlotAvailable($schedule, $meansTitle, $startUtc, $endUtc, $meeting)) {
            $this->wpJsonSlotNotAvailable();
        }

        $dateStr    = DisplayHelper::formatMeetingDateRange($date, $duration);
        $timeStr    = $this->formatTimeLine($date, $duration);
        $means      = $this->meansRepo->findById((string) $schedule['calendar_means_of_communication_id']);
        $meansLabel = $means['title'] ?? '';

        // Attendee may edit email/name/phone on reschedule form; apply only posted fields.
        $attendeeUpdates = $this->collectAttendeeUpdatesFromPost($isOAuth);

        $requireEmailVerification = ($schedule['require_email_verification'] ?? null) === true
            || ($schedule['require_email_verification'] ?? null) === 't';

        // Owner (admin) rescheduling from their own calendar applies directly —
        // exact owner-email match. Others on a verification-on schedule must
        // confirm via the emailed link.
        $viewerEmail = $isOAuth ? strtolower(trim((string) ($oauthUserInfo['email'] ?? ''))) : '';
        $ownerEmail  = strtolower(trim((string) ($schedule['email'] ?? '')));
        $isOwner     = $viewerEmail !== '' && $viewerEmail === $ownerEmail;

        if (!$requireEmailVerification || $isOwner) {
            if ($attendeeUpdates !== []) {
                $this->bookingRepo->update($meetingId, $attendeeUpdates);
            }

            $result = $this->bookingService->rescheduleMeeting(
                $meetingId,
                $date,
                $duration,
                null
            );
            if (!$result['ok']) {
                $this->sendJsonError(['message' => $result['message'] ?? 'Something went wrong.']);
            }

            $refreshedMeeting = $this->bookingRepo->findById($meetingId) ?? $meeting;
            $this->sendJsonSuccess([
                'message'              => 'Booking rescheduled',
                'frame'                => 'confirmed',
                'is_reschedule'        => true,
                'name'                 => DisplayHelper::scheduleOrganiserName($schedule),
                'date'                 => $dateStr,
                'time'                 => $timeStr,
                'location'             => $meansLabel,
                'phone'                => (string) ($refreshedMeeting['phone'] ?? ''),
                'meeting_join_url'     => $result['meeting_join_url'] ?? '',
                'meeting_id'           => $meetingId,
                'meeting_access_token' => DisplayHelper::meetingAccessToken((string) $meetingId),
            ]);
        }

        $dtUtc = clone $date;
        $dtUtc->setTimezone(new DateTimeZone('UTC'));
        $exp = new DateTime('now', new DateTimeZone('UTC'));
        $exp->modify('+1 hour');

        $description = $this->postRaw('description') !== '' ? htmlspecialchars($this->postRaw('description')) : '';
        $subject     = $this->postRaw('subject') !== '' ? htmlspecialchars($this->postRaw('subject')) : '';

        // Stash the requested change instead of mutating the live row. The
        // original meeting stays unchanged everywhere (timegrid, external
        // calendars) until confirmBookingRequest() applies this payload.
        $pending = [
            'datetime' => $dtUtc->format('Y-m-d H:i:sP'),
            'duration' => $duration,
            'timezone' => TimeHelper::canonicalTimezone((string) $this->postRaw('timezone')),
        ];
        if ($description !== '') {
            $pending['description'] = $description;
        }
        if ($subject !== '') {
            $pending['subject'] = $subject;
        }
        // Attendee edits (email/name/phone) ride along in the same payload.
        $pending = array_merge($pending, $attendeeUpdates);

        $this->bookingRepo->update($meetingId, [
            'meeting_confirmed'          => 0,
            'expiration_of_confirmation' => $exp->format('Y-m-d H:i:sP'),
            'pending_reschedule'         => json_encode($pending),
        ]);

        $emailSent = $this->notificationService->sendBookingConfirmationEmail($meetingId);
        if (!$emailSent) {
            $this->sendJsonError(['message' => 'Fail send email.']);
        }

        $confirmUrl = DisplayHelper::bookingUrl($schedule, [
            'meeting_id' => $meetingId,
            'frame'      => 'confirm',
        ]);

        $this->sendJsonSuccess([
            'message'              => 'Email sent successfully!',
            'frame'                => 'confirm',
            'name'                 => DisplayHelper::scheduleOrganiserName($schedule),
            'date'                 => $dateStr,
            'time'                 => $timeStr,
            'location'             => $meansLabel,
            'phone'                => (string) ($meeting['phone'] ?? ''),
            'meeting_id'           => $meetingId,
            'meeting_access_token' => DisplayHelper::meetingAccessToken((string) $meetingId),
            'redirect'             => $confirmUrl,
        ]);
    }

    // ------------------------------------------------------------------
    //  cancelMeetingRequest
    // ------------------------------------------------------------------

    public function cancelMeetingRequest(): void
    {
        $this->verifyNonce('calendar_nonce', 'calendar_nonce_action');

        $meetingIdRaw = trim($this->postRaw('meeting_id'));
        if ($meetingIdRaw === '') {
            $this->sendJsonError(['message' => 'Missing required fields.']);
        }

        $meetingId = $this->validateUuid($meetingIdRaw, 'meeting id');
        $this->requireMeetingAccess($meetingId);
        $meeting   = $this->bookingRepo->findById($meetingId);

        if ($meeting === null) {
            $this->sendJsonError(['message' => 'Meeting not found.']);
        }

        $scheduleId = isset($meeting['calendar_schedule_id'])
            ? trim((string) $meeting['calendar_schedule_id'])
            : '';
        if ($scheduleId === '') {
            $this->sendJsonError(['message' => 'Meeting not found.']);
        }

        $bookingShort = trim($this->postRaw('booking_short_id'));
        if ($bookingShort !== '' && preg_match('/^\d{6}$/', $bookingShort)) {
            $scheduleForShort = $this->scheduleRepo->findBy(['booking_short_id' => $bookingShort]);
            if (
                !is_array($scheduleForShort)
                || empty($scheduleForShort['id'])
                || (string) $scheduleForShort['id'] !== $scheduleId
            ) {
                $this->sendJsonError(['message' => 'Meeting not found.']);
            }
        }

        $schedule = $this->scheduleRepo->findById($scheduleId);
        $schedule = is_array($schedule) ? $schedule : [];
        $requireEmailVerification = ($schedule['require_email_verification'] ?? null) === true
            || ($schedule['require_email_verification'] ?? null) === 't';

        // Admins cancel directly (no email confirmation): the schedule owner, or
        // any signed-in user in the admin login domain (same gate as /schedule).
        // Attendees on a verification-on schedule confirm via the emailed link.
        [$isOAuth, $oauthUserInfo] = $this->resolveOAuthContext();
        $viewerEmail = $isOAuth ? strtolower(trim((string) ($oauthUserInfo['email'] ?? ''))) : '';
        $ownerEmail  = strtolower(trim((string) ($schedule['email'] ?? '')));
        $isOwner     = $viewerEmail !== '' && $viewerEmail === $ownerEmail;
        $isAdmin     = $viewerEmail !== '' && GoogleOAuthHandler::getInstance()->isAdminEmailAllowed($viewerEmail);

        if ($requireEmailVerification && !$isOwner && !$isAdmin) {
            $emailSent = $this->notificationService->sendCancelConfirmationEmail($meetingId);
            if (!$emailSent) {
                $this->sendJsonError(['message' => 'Fail send email.']);
            }
            $this->sendJsonSuccess(['message' => 'Cancellation link sent. Please check your email.']);
        }

        $result = $this->bookingService->cancelMeeting($meetingId);
        if (!$result['ok']) {
            $this->sendJsonError(['message' => $result['message'] ?? 'Failed to cancel meeting.']);
        }

        $this->sendJsonSuccess(['message' => 'Event deleted successfully.']);
    }

    // ------------------------------------------------------------------
    //  requestCancelMeeting  — anonymous: email a cancel-confirmation link
    // ------------------------------------------------------------------

    public function requestCancelMeeting(): void
    {
        $this->verifyNonce('calendar_nonce', 'calendar_nonce_action');

        $meetingIdRaw = trim($this->postRaw('meeting_id'));
        if ($meetingIdRaw === '') {
            $this->sendJsonError(['message' => 'Missing required fields.']);
        }

        $meetingId = $this->validateUuid($meetingIdRaw, 'meeting id');
        $this->requireMeetingAccess($meetingId);

        $meeting = $this->bookingRepo->findById($meetingId);
        if ($meeting === null || empty($meeting['calendar_schedule_id'])) {
            $this->sendJsonError(['message' => 'Meeting not found.']);
        }

        $schedule = $this->scheduleRepo->findById((string) $meeting['calendar_schedule_id']);
        $requireEmailVerification = is_array($schedule)
            && (($schedule['require_email_verification'] ?? null) === true
                || ($schedule['require_email_verification'] ?? null) === 't');

        if (!$requireEmailVerification) {
            $result = $this->bookingService->cancelMeeting($meetingId);
            if (!$result['ok']) {
                $this->sendJsonError(['message' => $result['message'] ?? 'Failed to cancel meeting.']);
            }
            $this->sendJsonSuccess(['message' => 'Event deleted successfully.']);
        }

        $emailSent = $this->notificationService->sendCancelConfirmationEmail($meetingId);
        if (!$emailSent) {
            $this->sendJsonError(['message' => 'Fail send email.']);
        }

        $this->sendJsonSuccess(['message' => 'Cancellation link sent. Please check your email.']);
    }

    // ------------------------------------------------------------------
    //  confirmCancelMeeting  — apply cancellation after email-link click
    // ------------------------------------------------------------------

    public function confirmCancelMeeting(): void
    {
        $this->verifyNonce('calendar_nonce', 'calendar_nonce_action');

        $meetingIdRaw = trim($this->postRaw('meeting_id'));
        $cancelToken  = trim($this->post('cancel_token'));
        if ($meetingIdRaw === '' || $cancelToken === '') {
            $this->sendJsonError(['message' => 'Missing required fields.']);
        }

        $meetingId = $this->validateUuid($meetingIdRaw, 'meeting id');

        $meeting = $this->bookingRepo->findByIdAnyState($meetingId);
        if (!is_array($meeting) || empty($meeting['id'])) {
            $this->sendJsonError(['message' => 'Meeting not found.']);
        }

        // Already cancelled — repeat click on the link lands on the
        // "already cancelled" view rather than an error.
        if (!empty($meeting['deleted_at'])) {
            $this->sendJsonSuccess(['message' => 'Meeting already cancelled.', 'already' => true]);
        }

        if (!$this->bookingService->isCancelTokenValid($meetingId, $cancelToken)) {
            $this->sendJsonError([
                'message' => 'Cancellation link is invalid or has expired.',
                'code'    => 'invalid',
            ]);
        }

        // Single-use is guaranteed by the deleted_at guard above — a re-click
        // hits the already-cancelled branch. The token is intentionally NOT
        // cleared here so a failed cancelMeeting() leaves the link retryable.
        $result = $this->bookingService->cancelMeeting($meetingId);
        if (!$result['ok']) {
            $this->sendJsonError(['message' => $result['message'] ?? 'Failed to cancel meeting.']);
        }

        $this->sendJsonSuccess(['message' => 'Meeting cancelled.']);
    }

    // ------------------------------------------------------------------
    //  resendConfirmed
    // ------------------------------------------------------------------

    public function resendConfirmed(): void
    {
        $this->verifyNonce('calendar_nonce', 'calendar_nonce_action');

        if ($this->postRaw('meeting_id') === '') {
            $this->sendJsonError(['message' => 'Missing required fields.']);
        }

        $meetingId = $this->validateUuid($this->post('meeting_id'), 'meeting id');
        $this->requireMeetingAccess($meetingId);

        $booking = $this->bookingRepo->findById($meetingId);
        if (!is_array($booking)) {
            $this->sendJsonError(['message' => 'Booking not found.']);
        }
        // This resends the "booking confirmed / details" email (the one with the
        // View-details/reschedule/cancel links), which only exists for a
        // confirmed booking — so the booking MUST be confirmed, not the reverse.
        $confirmed = $booking['meeting_confirmed'] ?? false;
        $isConfirmed = ($confirmed === true || $confirmed === 't' || $confirmed === '1' || $confirmed === 1);
        if (!$isConfirmed) {
            $this->sendJsonError(['message' => 'This booking is not confirmed yet.']);
        }

        // If the meeting was rescheduled, previous_datetime holds the slot it was
        // moved from — pass it so the resent email is the "rescheduled from X"
        // variant (matching the original), not a fresh booking confirmation.
        $previousDate = null;
        if (!empty($booking['previous_datetime'])) {
            try {
                $previousDate = new DateTime((string) $booking['previous_datetime']);
            } catch (\Throwable $e) {
                $previousDate = null;
            }
        }

        if ($this->notificationService->sendBookingConfirmEmail($meetingId, $previousDate)) {
            $this->sendJsonSuccess(['message' => 'Email sent successfully.']);
        }

        $this->sendJsonError(['message' => 'Something went wrong.']);
    }

    // ------------------------------------------------------------------
    //  resendConfirmation
    // ------------------------------------------------------------------

    public function resendConfirmation(): void
    {
        $this->verifyNonce('calendar_nonce', 'calendar_nonce_action');

        if ($this->postRaw('meeting_id') === '') {
            $this->sendJsonError(['message' => 'Missing required fields.']);
        }

        $meetingId = $this->validateUuid($this->post('meeting_id'), 'meeting id');
        $this->requireMeetingAccess($meetingId);

        $booking = $this->bookingRepo->findById($meetingId);
        if (!is_array($booking)) {
            $this->sendJsonError(['message' => 'Booking not found.']);
        }
        $confirmed = $booking['meeting_confirmed'] ?? false;
        if ($confirmed === true || $confirmed === 't' || $confirmed === '1' || $confirmed === 1) {
            $this->sendJsonError(['message' => 'This booking is already confirmed.']);
        }

        if (!$this->bookingService->isExpirationValid($meetingId)) {
            $this->sendJsonError(['message' => 'The attempts are over.']);
        }

        if ($this->notificationService->sendBookingConfirmationEmail($meetingId)) {
            $this->sendJsonSuccess(['message' => 'Email sent successfully.']);
        }

        $this->sendJsonError(['message' => 'Something went wrong.']);
    }

    // ------------------------------------------------------------------
    //  requestNewConfirmationEmail
    // ------------------------------------------------------------------

    public function requestNewConfirmationEmail(): void
    {
        $this->verifyNonce('calendar_nonce', 'calendar_nonce_action');

        if ($this->postRaw('meeting_id') === '') {
            $this->sendJsonError(['message' => 'Missing required fields.']);
        }

        $meetingId = $this->validateUuid($this->post('meeting_id'), 'meeting id');
        $this->requireMeetingAccess($meetingId);

        $booking = $this->bookingRepo->findById($meetingId);
        if (!is_array($booking)) {
            $this->sendJsonError(['message' => 'Booking not found.']);
        }
        $confirmed = $booking['meeting_confirmed'] ?? false;
        if ($confirmed === true || $confirmed === 't' || $confirmed === '1' || $confirmed === 1) {
            $this->sendJsonError(['message' => 'This booking is already confirmed.']);
        }

        $this->bookingService->setExpiration($meetingId);

        if ($this->notificationService->sendBookingConfirmationEmail($meetingId)) {
            $this->sendJsonSuccess(['message' => 'Email sent successfully.']);
        }

        $this->sendJsonError(['message' => 'Something went wrong.']);
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    /**
     * @return array{0: bool, 1: array<string, mixed>}
     */
    private function resolveOAuthContext(): array
    {
        $isOAuth      = false;
        $oauthUserInfo = [];

        if (class_exists(GoogleOAuthHandler::class)) {
            try {
                $oauth    = GoogleOAuthHandler::getInstance();
                $isOAuth  = $oauth->isAuthenticated();
                $oauthUserInfo = $isOAuth ? (array) ($oauth->getUserInfo() ?? []) : [];
            } catch (\Throwable $e) {
                $isOAuth = false;
                $oauthUserInfo = [];
            }
        }

        return [$isOAuth, $oauthUserInfo];
    }

    /** Lower-cased domain part of an email, or '' if none. */
    private function emailDomain(string $email): string
    {
        $at = strrchr($email, '@');

        return $at === false ? '' : strtolower(trim(substr($at, 1)));
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function scheduleRequiresEmailVerification(array $schedule): bool
    {
        $value = $schedule['require_email_verification'] ?? null;

        return $value === true || $value === 't';
    }


    /**
     * @param array<string, mixed> $oauthUserInfo
     * @return array{0: string, 1: string}
     */
    private function fillNamesFromOAuth(string $firstName, string $lastName, array $oauthUserInfo): array
    {
        if ($firstName === '' && !empty($oauthUserInfo['given_name'])) {
            $firstName = (string) $oauthUserInfo['given_name'];
        }
        if ($lastName === '' && !empty($oauthUserInfo['family_name'])) {
            $lastName = (string) $oauthUserInfo['family_name'];
        }
        if (($firstName === '' || $lastName === '') && !empty($oauthUserInfo['name'])) {
            $parts = preg_split('/\s+/', trim((string) $oauthUserInfo['name'])) ?: [];
            if ($firstName === '' && isset($parts[0])) {
                $firstName = (string) $parts[0];
            }
            if ($lastName === '' && count($parts) > 1) {
                $lastName = implode(' ', array_slice($parts, 1));
            }
        }

        return [$firstName, $lastName];
    }

    /**
     * Pick attendee fields from reschedule form POST as partial row update.
     * Only validated, present fields included.
     *
     * @return array<string, mixed>
     */
    private function collectAttendeeUpdatesFromPost(bool $isOAuth): array
    {
        $updates = [];

        $emailRaw = trim($this->postRaw('email'));
        if ($emailRaw !== '' && filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
            $updates['email'] = $emailRaw;
        } elseif ($emailRaw !== '' && !$isOAuth) {
            $this->sendJsonError(['message' => 'Invalid email.']);
        }

        $firstName = trim($this->postRaw('first_name'));
        if ($firstName !== '') {
            $updates['first_name'] = mb_substr($firstName, 0, 255);
        }

        $lastName = trim($this->postRaw('last_name'));
        if ($lastName !== '') {
            $updates['last_name'] = mb_substr($lastName, 0, 255);
        }

        if (isset($_POST['phone'])) {
            $phone = $this->post('phone');
            // Empty phone allowed on reschedule (optional).
            $updates['phone'] = mb_substr($phone, 0, 32);
        }

        return $updates;
    }

    private function formatTimeLine(DateTime $date, int $duration): string
    {
        $start = clone $date;
        $end   = clone $date;
        $end->modify('+' . $duration . ' minutes');

        $tzLabel = $start->getTimezone()->getName() . ' (UTC' . $start->format('P') . ')';

        $nextDay = $end->format('Y-m-d') !== $start->format('Y-m-d') ? ' (' . $end->format('M j') . ')' : '';
        return $start->format('g:i a') . ' - ' . $end->format('g:i a') . $nextDay . "\n" . $tzLabel;
    }

    private function wpJsonSlotNotAvailable(): void
    {
        $this->sendJsonError([
            'message' => 'Time slot is not available.',
            'code'    => 'slot_not_available',
        ]);
    }
}
