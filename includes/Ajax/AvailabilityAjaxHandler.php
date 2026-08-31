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
use Apexianlab\Calendar\Service\AvailabilityService;

final class AvailabilityAjaxHandler extends AbstractAjaxHandler
{
    private AvailabilityService $availabilityService;

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
    }

    protected function actions(): array
    {
        return [
            'get_available_ranges'  => 'getAvailableRanges',
            'save_booking_timezone' => 'saveBookingTimezone',
        ];
    }

    public function getAvailableRanges(): void
    {
        $this->verifyNonce('calendar_nonce', 'calendar_nonce_action');

        if (
            $this->post('timezone') === ''
            || $this->post('year') === ''
            || $this->post('month') === ''
            || $this->post('day') === ''
            || $this->post('duration') === ''
        ) {
            $this->sendJsonError(['message' => 'Missing required fields.']);
        }

        $schedule   = $this->requireScheduleByShortId();
        $scheduleId = (string) $schedule['id'];
        $timezone   = new DateTimeZone($this->post('timezone'));

        $year  = $this->postInt('year');
        $month = $this->postInt('month');

        if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) {
            $this->sendJsonError(['message' => 'Invalid date.']);
        }

        // Reschedule mode: meeting's own slot stays in booked set so it can't
        // be re-picked. HMAC `t` required to prevent UUID fingerprinting.
        $rescheduleMeetingId = '';
        $meetingIdRaw = trim($this->postRaw('meeting_id'));
        if ($meetingIdRaw !== '') {
            $rescheduleMeetingId = $this->validateUuid($meetingIdRaw, 'meeting id');
            $this->requireMeetingAccess($rescheduleMeetingId);
        }

        $monthPadded = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $from = new DateTime("{$year}-{$monthPadded}-01", $timezone);
        $from->setTime(0, 0, 0);
        $to = clone $from;
        $to->modify('+3 month');

        $attendeeEmail = $this->currentAttendeeEmail();

        // currentAttendeeEmail() is the only session read in this handler. The
        // Google availability fetch below is slow (network round-trips),
        // and PHP holds the session file lock until the request ends — which
        // serializes concurrent get_available_ranges calls and blocks unrelated
        // AJAX (logout, calendar switch) behind them. Release the lock now.
        if (PHP_SESSION_ACTIVE === session_status()) {
            session_write_close();
        }

        $window = $this->availabilityService->getAvailabilityList(
            $scheduleId,
            $from,
            $to,
            $timezone,
            $rescheduleMeetingId !== '' ? $rescheduleMeetingId : null,
            $attendeeEmail !== '' ? $attendeeEmail : null
        );

        $payload = $window->toArray();
        $this->sendJsonSuccess($payload);
    }

    /**
     * Email of the currently authenticated attendee, or '' when unauthenticated.
     * Reserved for attendee-side conflict checks.
     */
    private function currentAttendeeEmail(): string
    {
        if (!class_exists(GoogleOAuthHandler::class)) {
            return '';
        }
        $oauth = GoogleOAuthHandler::getInstance();
        if (!$oauth->isAuthenticated()) {
            return '';
        }
        $userInfo = (array) ($oauth->getUserInfo() ?? []);

        return sanitize_email((string) ($userInfo['email'] ?? ''));
    }

    public function saveBookingTimezone(): void
    {
        $this->verifyNonce('calendar_nonce', 'calendar_nonce_action');

        $tz = $this->post('timezone');
        if ($tz === '') {
            $this->sendJsonError(['message' => 'Missing timezone']);
        }

        try {
            new DateTimeZone($tz);
        } catch (\Exception $e) {
            $this->sendJsonError(['message' => 'Invalid timezone']);
        }

        if (function_exists('apexianlab_set_session')) {
            apexianlab_set_session('apexianlab_booking_timezone', $tz);
        }

        $this->sendJsonSuccess(['timezone' => $tz]);
    }
}
