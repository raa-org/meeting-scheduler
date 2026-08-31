<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Template name: Meeting Booking
 */
use Apexianlab\Calendar\Auth\GoogleOAuthHandler;
use Apexianlab\Calendar\Database\Connection;
use Apexianlab\Calendar\Dto\BookingMeeting;
use Apexianlab\Calendar\Helper\DisplayHelper;
use Apexianlab\Calendar\Repository\BookingRepository;
use Apexianlab\Calendar\Repository\MeansOfCommunicationRepository;
use Apexianlab\Calendar\Repository\ScheduleRepository;
use Apexianlab\Calendar\Service\BookingService;

$connection = Connection::getInstance();
$pdo = $connection->pdo();
$scheduleRepo = new ScheduleRepository($pdo);
$bookingRepo = new BookingRepository($pdo);
$meansRepo = new MeansOfCommunicationRepository($pdo);
$bookingService = new BookingService($bookingRepo, $scheduleRepo);

$calendar_id = get_query_var('calendar_id');
$schedule = !empty($calendar_id) ? $scheduleRepo->findById($calendar_id) : null;
$apexianlab_calendar_unavailable = get_query_var('apexianlab_calendar_unavailable') === '1' || empty($schedule);

if ($apexianlab_calendar_unavailable) {
    $apexianlab_timegrid_booking = true;
    require APEXIANLAB_MEETING_SCHEDULER_PATH . 'templates/partials/header-timegrid.php';
    ?>
    <section class="booking__frame booking__frame--unavailable current">
        <div class="booking__container booking__container--unavailable">
            <h1 class="booking__unavailable-title"><?php esc_html_e('This calendar is no longer available', 'apexianlab'); ?></h1>
            <p class="booking__unavailable-text"><?php esc_html_e('The organiser has removed or deactivated this calendar. The link you used is no longer valid.', 'apexianlab'); ?></p>
        </div>
    </section>
    <?php
    if (is_readable(APEXIANLAB_MEETING_SCHEDULER_PATH . 'templates/partials/footer-timegrid.php')) {
        require APEXIANLAB_MEETING_SCHEDULER_PATH . 'templates/partials/footer-timegrid.php';
    }
    return;
}

$is_public = !empty($schedule) && ($schedule['is_public'] === true || $schedule['is_public'] === 't');
$require_email_verification = !empty($schedule) && ($schedule['require_email_verification'] === true || $schedule['require_email_verification'] === 't');


if (!$is_public) {
    $googleAuth = GoogleOAuthHandler::getInstance();
    $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')) . sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
    apexianlab_set_session('oauth2_redirect_after_auth', $currentUrl);
    $googleAuth->requireAuth();

    // A private (non-public) schedule is restricted to the organiser's own
    // email domain — only people in the same organisation may view/book it.
    // (When the Google app is "External", Google no longer enforces this, so we
    // gate it here.) Empty owner domain → no restriction (fail-open by design).
    $apexianlab_owner_email   = (string) ($schedule['email'] ?? '');
    $apexianlab_owner_domain  = strtolower(trim((string) substr((string) strrchr($apexianlab_owner_email, '@'), 1)));
    $apexianlab_viewer        = $googleAuth->getUserInfo();
    $apexianlab_viewer_email  = is_array($apexianlab_viewer) ? (string) ($apexianlab_viewer['email'] ?? '') : '';
    $apexianlab_viewer_domain = strtolower(trim((string) substr((string) strrchr($apexianlab_viewer_email, '@'), 1)));

    if ($apexianlab_owner_domain !== '' && $apexianlab_viewer_domain !== $apexianlab_owner_domain) {
        $apexianlab_timegrid_booking = true;
        require APEXIANLAB_MEETING_SCHEDULER_PATH . 'templates/partials/header-timegrid.php';
        ?>
        <section class="booking__frame booking__frame--unavailable current">
            <div class="booking__container booking__container--unavailable">
                <h1 class="booking__unavailable-title"><?php esc_html_e('Access restricted', 'apexianlab'); ?></h1>
                <p class="booking__unavailable-text">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: organiser email domain, e.g. example.com */
                            __('This meeting is private. Only members of %s can view and book it. You are signed in with a different account.', 'apexianlab'),
                            '@' . $apexianlab_owner_domain
                        )
                    );
                    ?>
                </p>
            </div>
        </section>
        <?php
        if (is_readable(APEXIANLAB_MEETING_SCHEDULER_PATH . 'templates/partials/footer-timegrid.php')) {
            require APEXIANLAB_MEETING_SCHEDULER_PATH . 'templates/partials/footer-timegrid.php';
        }
        return;
    }
}

$apexianlab_timegrid_booking = true;
require APEXIANLAB_MEETING_SCHEDULER_PATH . 'templates/partials/header-timegrid.php';
$days_of_week = DisplayHelper::weekDays();

$bookingRow = null;
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing/display params on a public booking page; state changes go through nonce-verified Ajax handlers.
if (!empty($_GET['meeting_id'])) {
    $meeting_id_q = sanitize_text_field(wp_unslash($_GET['meeting_id']));
    if (get_query_var('apexianlab_booking_display_cancelled') === '1') {
        $loadedBooking = $bookingRepo->findByIdAnyState($meeting_id_q);
        $bookingRow = is_array($loadedBooking) ? $loadedBooking : null;
    } else {
        $loadedBooking = $bookingRepo->findById($meeting_id_q);
        $bookingRow = is_array($loadedBooking) ? $loadedBooking : null;
    }
}
$scheduleRow = is_array($schedule) ? $schedule : null;

$scheduleDurations = [15, 30, 45, 60, 90];
if (is_array($scheduleRow) && isset($scheduleRow['durations'])) {
    $rawDurations = $scheduleRow['durations'];
    $decoded = is_string($rawDurations) ? json_decode($rawDurations, true) : $rawDurations;
    if (is_array($decoded)) {
        $clean = [];
        foreach ($decoded as $v) {
            $n = (int) $v;
            if ($n >= 1 && $n <= 1440) {
                $clean[$n] = true;
            }
        }
        if ($clean !== []) {
            $scheduleDurations = array_keys($clean);
            sort($scheduleDurations, SORT_NUMERIC);
        }
    }
}
$defaultDuration = $scheduleDurations[0] ?? 30;

// On the post-reschedule "confirm" frame, surface the requested (pending)
// slot: the live row still holds the pre-reschedule values until the
// attendee confirms by email.
$apexianlab_frame_param = isset($_GET['frame']) ? sanitize_text_field(wp_unslash($_GET['frame'])) : '';
if ($apexianlab_frame_param === 'confirm'
    && is_array($bookingRow)
    && !empty($bookingRow['pending_reschedule'])
) {
    $apexianlab_pending = json_decode((string) $bookingRow['pending_reschedule'], true);
    if (is_array($apexianlab_pending)) {
        $bookingRow = array_merge($bookingRow, $apexianlab_pending);
    }
}

$meeting = new BookingMeeting($scheduleRow, $bookingRow);

$apexianlab_ai_assistant_booking_url = is_array($schedule)
    ? \Apexianlab\Calendar\Helper\DisplayHelper::bookingUrl($schedule, ['ai_start' => '1'])
    : '';
$apexianlab_ai_assistant_url = $apexianlab_ai_assistant_booking_url !== ''
    ? $apexianlab_ai_assistant_booking_url
    : home_url('/ai-assistant/');

$is_confirmation_expired = false;
if (!empty($_GET['meeting_id']) && $meeting->hasBooking()) {
    $meeting_id_for_exp = sanitize_text_field(wp_unslash($_GET['meeting_id']));
    if (!$bookingService->isConfirmed($meeting_id_for_exp)) {
        $is_confirmation_expired = !$bookingService->isExpirationValid($meeting_id_for_exp);
    }
}

$location = $meeting->getTypeCommunication($meansRepo);
$location_class = $meeting->getLocationDisplayClass($meansRepo);

// The URL carries a `for` hint placed there by NotificationService so the
// shared link from the organiser's email always renders in the organiser's
// timezone, and the link from the attendee's email in the attendee's. Anyone
// arriving without the hint falls back to the saved booking timezone.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only TZ hint on a public page; mutations go through nonce-verified Ajax.
$viewer_role_hint   = isset($_GET['for']) ? strtolower(sanitize_text_field(wp_unslash($_GET['for']))) : '';
$viewer_tz_override = null;
if ($viewer_role_hint === 'organiser' && is_array($schedule)) {
    $organiserTz = \Apexianlab\Calendar\Helper\TimeHelper::organiserTimezoneFromSchedule($schedule);
    if ($organiserTz !== '') {
        $viewer_tz_override = $organiserTz;
    }
}

$date_str = $meeting->getDisplayDateString($viewer_tz_override);
$time_str = $meeting->getDisplayTimeMultiLine($viewer_tz_override);
$time_parts = preg_split('/\R/', (string) $time_str) ?: [];
$time_main = isset($time_parts[0]) ? trim((string) $time_parts[0]) : '';
$time_tz = isset($time_parts[1]) ? trim((string) $time_parts[1]) : '';
$has_details_date = trim((string) $date_str) !== '';
$has_details_time = $time_main !== '' || $time_tz !== '';

$search_frame = !empty($_GET['frame']) ? sanitize_text_field(wp_unslash($_GET['frame'])) : '1';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
if ($search_frame === 'confirmed' && get_query_var('apexianlab_booking_display_cancelled') === '1') {
    $search_frame = '6';
}

function get_frame_class($current_frame, $search_frame)
{
    $list_of_frames = ['1', '2', 'confirm', 'confirmed', '5', '6', 'slot-not-available', 'creation-event-error'];
    $search_index = array_search($search_frame, $list_of_frames);
    $current_index = array_search($current_frame, $list_of_frames);

    if ($search_index === $current_index) {
        return 'current';
    } elseif ($search_index > $current_index) {
        return 'prev';
    }

    return 'next';
}

?>
<section id="frame-1" class="booking__frame booking__frame-1 <?php echo esc_attr(get_frame_class('1', $search_frame)); ?>">
    <div class="booking__container">
        <div class="booking__left-side">
            <div class="details details--accordion">
                <button type="button" class="details__accordion-toggle" id="frame-1-details-toggle" aria-expanded="false" aria-controls="frame-1-details-panel">
                    <span class="details__calendar-title"><?php echo esc_html($meeting->getTitle()); ?></span>
                    <span class="details__accordion-icon" aria-hidden="true"></span>
                </button>
                <div class="details__accordion-panel" id="frame-1-details-panel" role="region" aria-labelledby="frame-1-details-toggle" aria-hidden="true">
                    <span class="details__calendar-title details__calendar-title--desktop"><?php echo esc_html($meeting->getTitle()); ?></span>
                    <span class="details__calendar-description"><?php echo esc_html($meeting->getDescription()); ?></span>
                    <div class="details__divider" role="presentation"></div>
                    <h2 class="details__meeting-heading" style="display:none">Meeting Details</h2>
                    <span class="details__name"><?php echo esc_html($meeting->getOrganiserDisplayName()); ?></span>
                    <span class="details__date" style="display:none"><?php echo esc_html(wp_date('l, F j', current_time('timestamp'))); ?></span>
                    <span class="details__platform <?php echo esc_attr($location_class); ?>"><?php echo esc_html($location); ?></span>
                </div>
            </div>
            <div class="ai__button-wrap">
                <a
                    class="ai__button"
                    href="<?php echo esc_url($apexianlab_ai_assistant_url); ?>"
                >
                    <span class="ai__button-text">Schedule with AI</span>
                </a>
            </div>
        </div>
        <div class="calendar__top">
                <div class="booking__frame-top">
                    <div class="booking__frame-main">
                        <div class="booking__frame-head">
                            <h1>Select convenient date and time</h1>
                            <div class="calendar__view-switch" role="group" aria-label="Calendar view">
                                <button type="button" class="calendar__view-switch-btn is-active" data-calendar-view="month">Month</button>
                                <button type="button" class="calendar__view-switch-btn" data-calendar-view="week">Week</button>
                            </div>
                        </div>
                        <div class="booking__frame-toolbar">
                            <div class="booking__toolbar-timezone">
                            <span class="booking__toolbar-timezone-label">
                                <svg class="booking__toolbar-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z" stroke="currentColor" stroke-width="1.75"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" stroke="currentColor" stroke-width="1.75"/></svg>
                                Time zone
                            </span>
                            <div class="slots__timezone-picker">
                                <div class="ant-select ant-select-single ant-select-show-arrow ant-select-show-search ant-select-lg slots__timezone-ant">
                                    <div class="ant-select-selector">
                                        <span class="ant-select-selection-search">
                                            <input type="search" class="ant-select-selection-search-input slots__timezone-input" placeholder="Search timezone…" autocomplete="off" aria-label="Timezone" />
                                        </span>
                                    </div>
                                    <span class="ant-select-arrow" aria-hidden="true">
                                        <span class="anticon anticon-down ant-select-arrow-icon">
                                            <svg viewBox="64 64 896 896" focusable="false" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M884 256h-75c-5.1 0-9.9 2.5-12.6 6.5L512 654.2 227.9 262.5c-2.7-4.1-7.5-6.5-12.6-6.5h-75c-6.5 0-10.3 7.4-6.5 12.7l352.6 486.1c12.8 17.6 39 17.6 51.8 0l352.6-486.1c3.9-5.3.1-12.7-6.4-12.7z"></path></svg>
                                        </span>
                                    </span>
                                    <div class="ant-select-dropdown slots__timezone-dropdown ant-select-dropdown-hidden" role="listbox"></div>
                                </div>
                            </div>
                            <select class="slots__timezone" name="timezone" aria-hidden="true" tabindex="-1"></select>
                        </div>
                        </div>
                    </div>
                </div>
        </div>
        <div class="booking__calendar-slots-row">
            <div class="calendar">
                <header class="calendar__header">
                    <div class="calendar__current-date">
                        <span class="calendar__current-month"></span>
                        <span class="calendar__current-day"></span>
                        <span class="calendar__week-range" hidden></span>
                    </div>
                    <div class="calendar__navigation">
                        <button type="button" class="calendar__button calendar__button--prev" aria-label="Previous"></button>
                        <button type="button" class="calendar__button calendar__button--next" aria-label="Next"></button>
                    </div>
                </header>
                <div class="calendar__month-panel">
                    <div class="calendar__main">
                        <ul class="calendar__weeks">
                            <?php foreach($days_of_week as $day): ?>
                            <li><?php echo esc_html($day); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <ul class="calendar__days"></ul>
                    </div>
                </div>
                <div class="calendar__week-panel" hidden>
                    <div class="calendar__main calendar__main--week">
                        <div class="calendar__week-grid" aria-live="polite"></div>
                    </div>
                </div>
            </div>
            <div class="slots">
                <div class="booking__toolbar-duration">
                    <div class="duration duration--toolbar-card">
                        <span class="duration__toolbar-heading">Duration</span>
                        <div class="duration__controller">
                            <button type="button" class="duration__btn duration__btn--less" aria-label="Decrease duration"></button>
                            <span class="duration__value" data-duration="<?php echo esc_attr((string) $defaultDuration); ?>" data-durations="<?php echo esc_attr((string) wp_json_encode($scheduleDurations)); ?>"></span>
                            <button type="button" class="duration__btn duration__btn--more" aria-label="Increase duration"></button>
                        </div>
                    </div>
                </div>
                <div class="slots__container"></div>
            </div>
        </div>
    </div>
</section>

<section id="frame-2" class="booking__frame booking__frame-2 <?php echo esc_attr(get_frame_class('2', $search_frame)); ?>">
    <div class="booking__container">
        <?php
            $prefill_first_name = $meeting->getFirstName();
            $prefill_last_name = $meeting->getLastName();
            $prefill_email = $meeting->getGuestEmail();
            $prefill_phone = $meeting->getPhone();
            $is_oauth_authed = false;

            // An existing booking (reschedule / view) already carries its own
            // attendee identity. Never override it with the logged-in user's
            // OAuth profile — otherwise an organiser rescheduling the meeting
            // would clobber the attendee row with their own name/email.
            $has_existing_booking = $bookingRow !== null;

            // Google account info prefill — authoritative for a fresh
            // booking, where the logged-in user is the attendee.
            if (class_exists('Apexianlab\\Calendar\\Auth\\GoogleOAuthHandler')) {
                try {
                    $oauth = \Apexianlab\Calendar\Auth\GoogleOAuthHandler::getInstance();
                    if ($oauth->isAuthenticated()) {
                        $is_oauth_authed = true;
                        $userInfo = $has_existing_booking ? null : $oauth->getUserInfo();
                        if (is_array($userInfo)) {
                            $oauth_email = !empty($userInfo['email'])
                                ? (string) $userInfo['email']
                                : (!empty($userInfo['preferred_username']) ? (string) $userInfo['preferred_username'] : '');
                            if ($oauth_email !== '') {
                                $prefill_email = $oauth_email;
                                $prefill_phone = '';
                            }

                            $oauth_first = !empty($userInfo['given_name']) ? (string) $userInfo['given_name'] : '';
                            $oauth_last = !empty($userInfo['family_name']) ? (string) $userInfo['family_name'] : '';

                            if (($oauth_first === '' || $oauth_last === '') && !empty($userInfo['name'])) {
                                $parts = preg_split('/\s+/', trim((string) $userInfo['name'])) ?: [];
                                if ($oauth_first === '' && isset($parts[0])) {
                                    $oauth_first = (string) $parts[0];
                                }
                                if ($oauth_last === '' && count($parts) > 1) {
                                    $oauth_last = (string) implode(' ', array_slice($parts, 1));
                                }
                            }

                            if ($oauth_first !== '' || $oauth_last !== '') {
                                $prefill_first_name = $oauth_first;
                                $prefill_last_name = $oauth_last;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            $prefill_subject = $meeting->getTitle();
            $prefill_description = $meeting->getDescription();
            $prefill_full_name = trim($prefill_first_name . ' ' . $prefill_last_name);
            $lock_contact_fields = $is_oauth_authed && $prefill_full_name !== '' && $prefill_email !== '';
            $lock_attr = $lock_contact_fields ? ' readonly aria-readonly="true"' : '';

            $organiser_name = trim((string) $meeting->getOrganiserDisplayName());
            $organiser_email = trim((string) $meeting->getScheduleAuthorEmail());

            $invited_attendees = [];
            if ($is_oauth_authed) {
                $user_name = $prefill_full_name;
                $user_email = (string) $prefill_email;
                if ($user_name !== '' || $user_email !== '') {
                    $invited_attendees[] = [
                        'name' => $user_name,
                        'email' => $user_email,
                    ];
                }
            }

            $initials_for_person = function (string $person_name, string $person_email): string {
                $initials = '';
                $name_parts = preg_split('/\s+/', trim($person_name)) ?: [];
                foreach ($name_parts as $part) {
                    if ($part !== '') {
                        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
                    }
                    if (mb_strlen($initials) >= 2) {
                        break;
                    }
                }
                if ($initials === '' && $person_email !== '') {
                    $initials = mb_strtoupper(mb_substr($person_email, 0, 1));
                }

                return $initials;
            };
        ?>
        <div class="details-info">
            <h1>A brief overview of the project</h1>
            <div class="details-info__people-row">
                <div class="details-info__people-col">
                    <span class="booking__organiser-title">Organiser</span>
                    <div class="details-info__attendees details-info__attendees--organiser">
                        <div class="details-info__attendee">
                            <div class="details-info__avatar">
                                <span><?php echo esc_html($initials_for_person($organiser_name, $organiser_email)); ?></span>
                            </div>
                            <div class="details-info__text">
                                <span class="details__name"><?php echo esc_html($organiser_name); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if (count($invited_attendees) > 0): ?>
                <div class="details-info__people-col">
                    <span class="booking__attendees-title">Attendee</span>
                    <div class="details-info__attendees details-info__attendees--guests<?php echo count($invited_attendees) > 1 ? ' details-info__attendees--multi' : ''; ?>">
                        <?php foreach ($invited_attendees as $attendee): ?>
                            <?php
                                $attendee_name = trim((string) ($attendee['name'] ?? ''));
                                $attendee_email = trim((string) ($attendee['email'] ?? ''));
                                $initials = $initials_for_person($attendee_name, $attendee_email);
                            ?>
                            <div class="details-info__attendee">
                                <div class="details-info__avatar">
                                    <span><?php echo esc_html($initials); ?></span>
                                </div>
                                <div class="details-info__text">
                                    <span class="details__name"><?php echo esc_html($attendee_name); ?></span>
                                    <?php if ($attendee_email !== ''): ?>
                                        <span class="details__email"><?php echo esc_html($attendee_email); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <button class="back-button"></button>
        <form class="booking__form<?php echo $is_oauth_authed ? ' booking__form--oauth' : ''; ?>" autocomplete="off" novalidate data-oauth-authed="<?php echo $is_oauth_authed ? '1' : '0'; ?>" data-require-email-verification="<?php echo $require_email_verification ? '1' : '0'; ?>">
            <?php if (!$is_oauth_authed): ?>
            <span class="booking__attendees-title booking__form-attendee-title"><?php echo esc_html__('Attendee', 'apexianlab-meeting-scheduler'); ?></span>
            <?php endif; ?>
            <div class="row">
                <div class="booking__form-field booking__form-field--fullname">
                    <input type="text" name="full_name" placeholder=" " maxlength="151" value="<?php echo esc_attr($prefill_full_name); ?>" required autocomplete="off"<?php echo $lock_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal attribute fragment (' readonly aria-readonly="true"' or ''), not user data ?> />
                    <label>Full Name *</label>
                    <div class="field__alert" aria-live="polite"></div>
                </div>
                <div class="booking__form-field booking__form-field--email">
                    <input type="email" name="email" placeholder=" " maxlength="255" value="<?php echo esc_attr($prefill_email); ?>" required autocomplete="off"<?php echo $lock_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal attribute fragment (' readonly aria-readonly="true"' or ''), not user data ?> />
                    <label>Email *</label>
                    <div class="field__alert" aria-live="polite"></div>
                </div>
            </div>
            <div class="row">
                <div class="booking__form-field booking__form-field--phone">
                    <input type="text" name="phone" placeholder=" " maxlength="32" value="<?php echo esc_attr($prefill_phone); ?>" autocomplete="off"<?php echo $meeting->requiresPhoneNumber($meansRepo) ? ' required' : ''; ?> />
                    <label><?php echo $meeting->requiresPhoneNumber($meansRepo) ? 'Phone Number *' : 'Phone Number (Optional)'; ?></label>
                    <div class="field__alert" aria-live="polite"></div>
                </div>
            </div>
            <?php /* Frame 2: nodes for JS; hidden until slot pick or server prefill */ ?>
            <div class="row separator row--datetime"<?php echo ($has_details_date || $has_details_time) ? ' data-has-server-datetime="1"' : ' hidden'; ?>>
                <h2 class="details__meeting-heading"><?php echo esc_html__('Meeting Details', 'apexianlab-meeting-scheduler'); ?></h2>
                <span class="details__date"><?php echo $has_details_date ? esc_html($date_str) : ''; ?></span>
                <span class="details__time">
                    <span class="details__time-main"><?php echo $has_details_time ? esc_html($time_main) : ''; ?></span>
                    <span class="details__time-zone"><?php echo $has_details_time ? esc_html($time_tz) : ''; ?></span>
                </span>
            </div>
            <div class="row separator row--platform">
                <span class="details__platform <?php echo esc_attr($location_class); ?>"><?php echo esc_html($location); ?></span>
            </div>
            <div class="row">
                <span class="booking__notes-title">Meeting notes</span>
                <div class="booking__form-field booking__form-field--textarea">
                    <textarea name="description" placeholder=" " maxlength="512" rows="3" autocomplete="off"><?php echo esc_textarea((string) $prefill_description); ?></textarea>
                    <label>Describe the meeting agenda, key discussion points, or any important details. (Optional)</label>
                </div>
            </div>
            <div class="row booking__form-row--code">
                <div class="booking__form-field booking__form-field--code">
                    <input name="code" placeholder=" " maxlength="255"<?php echo !$is_oauth_authed ? ' required' : ''; ?> autocomplete="off"/>
                    <label>Verify code *</label>
                    <div class="field__alert" aria-live="polite"></div>
                    <div class="code__alert" id="code-alert" style="display:none;" aria-live="polite">Incorrect code</div>
                </div>
                <img id="code" class="code" src="<?php echo esc_url(\Apexianlab\Calendar\Plugin::captchaUrl('booking_form')); ?>" alt="Image Captcha" title="Click for reload image">
            </div>
            <button class="booking__form-btn default-btn" type="submit">
                Book Meeting
            </button>
        </form>
    </div>
</section>

<section id="frame-3" class="booking__frame booking__frame-3 <?php echo esc_attr(get_frame_class('confirm', $search_frame)); ?>">
    <?php if (!empty($is_confirmation_expired) && $search_frame === 'confirm'): ?>
        <h2 class="expired">Link expired</h2>
        <p>
            This confirmation link has expired or is no longer valid.<br />
            To confirm your booking, please request<br />
            a new confirmation email.
        </p>
        <p>
            <a href="#" class="request-new-confirmation text-btn" <?php echo $meeting->getBookingId() !== null ? 'data-meeting_id="' . esc_attr($meeting->getBookingId()) . '"' : ''; ?>>Request new confirmation email</a>
        </p>
        <div class="final-details">
            <h2>A brief overview of the project</h2>
            <p><?php echo esc_html($meeting->getScheduleDescription()); ?></p>
            <h2 class="details__meeting-heading">Meeting Details</h2>
            <div class="details-info__text">
                <span class="details__name"><?php echo esc_html($meeting->getOrganiserDisplayName()); ?></span>
            </div>
            <span class="details__date"><?php echo $has_details_date ? esc_html($date_str) : ''; ?></span>
            <span class="details__time">
                <span class="details__time-main"><?php echo $has_details_time ? esc_html($time_main) : ''; ?></span>
                <span class="details__time-zone"><?php echo $has_details_time ? esc_html($time_tz) : ''; ?></span>
            </span>
            <span class="details__platform <?php echo esc_attr($location_class); ?>"><?php echo esc_html($location); ?></span>
        </div>
    <?php else: ?>
        <?php
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing params on a public booking page.
            $confirm_meeting_id = !empty($_GET['meeting_id']) ? sanitize_text_field(wp_unslash($_GET['meeting_id'])) : '';
            $confirm_check = !empty($_GET['check']) ? sanitize_text_field(wp_unslash($_GET['check'])) : '';
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            // Show loader only when confirming via email link (both meeting_id and check are present).
            $is_ajax_confirm = ($search_frame === 'confirm' && $confirm_meeting_id !== '' && $confirm_check !== '');
        ?>
        <?php if ($is_ajax_confirm): ?>
            <div class="booking__confirm-loader" data-meeting_id="<?php echo esc_attr($confirm_meeting_id); ?>" data-check="<?php echo esc_attr($confirm_check); ?>">
                <h1>Confirming your booking…</h1>
                <p>Please wait while we create the calendar event.</p>
                <div class="booking__spinner" aria-hidden="true"></div>
                <p class="booking__confirm-error" style="display:none;"></p>
            </div>
        <?php endif; ?>
        <div class="booking__confirm-content" <?php echo $is_ajax_confirm ? 'style="display:none;"' : ''; ?>>
            <h1>Check your email to confirm</h1><br>
            <p>
                We've sent you an email with a confirmation link. Please check your
                inbox and click the link to finalize your booking.
            </p>
            <div class="final-details">
                <h2>A brief overview of the project</h2>
                <p><?php echo esc_html($meeting->getScheduleDescription()); ?></p>
                <h2 class="details__meeting-heading">Meeting Details</h2>
                <div class="details-info__text">
                    <span class="details__name"><?php echo esc_html($meeting->getOrganiserDisplayName()); ?></span>
                </div>
                <span class="details__date"><?php echo $has_details_date ? esc_html($date_str) : ''; ?></span>
                <span class="details__time">
                    <span class="details__time-main"><?php echo $has_details_time ? esc_html($time_main) : ''; ?></span>
                    <span class="details__time-zone"><?php echo $has_details_time ? esc_html($time_tz) : ''; ?></span>
                </span>
                <span class="details__platform <?php echo esc_attr($location_class); ?>"><?php echo esc_html($location); ?></span>
            </div>
            <?php
                $bookingConfirmed = $bookingRow !== null
                    && in_array($bookingRow['meeting_confirmed'] ?? null, [true, 't', '1', 1], true);
            ?>
            <?php if (!$bookingConfirmed && $search_frame !== 'confirmed'): ?>
            <div class="resend-container">
                <p>Can't see the email?</p>
                <p>
                    Check your spam folder
                    or
                    <span class="countdown"></span>
                    <button type="button" class="resend-btn text-btn" <?php echo $meeting->getBookingId() !== null ? 'data-meeting_id="' . esc_attr($meeting->getBookingId()) . '"' : ''; ?>>resend</button> confirmation email.
                </p>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<section id="frame-4" class="booking__frame booking__frame-4 <?php echo esc_attr(get_frame_class('confirmed', $search_frame)); ?>">
    <?php $is_reschedule_frame4 = isset($_GET['is_reschedule']) && $_GET['is_reschedule'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
    <h1><?php echo $is_reschedule_frame4 ? 'Reschedule confirmed' : 'Booking confirmed'; ?></h1>
    <p>
        <?php echo $is_reschedule_frame4 ? 'Your meeting has been successfully rescheduled.' : 'Your meeting has been successfully scheduled.'; ?>
        <br />
        We've also sent you an email with all the details.
    </p>
    <div class="final-details">
        <h2>A brief overview of the project</h2>
        <p><?php echo esc_html($meeting->getScheduleDescription()); ?></p>
        <h2 class="details__meeting-heading">Meeting Details</h2>
        <div class="details-info__text">
            <span class="details__name"><?php echo esc_html($meeting->getOrganiserDisplayName()); ?></span>
        </div>
        <span class="details__date"><?php echo $has_details_date ? esc_html($date_str) : ''; ?></span>
        <span class="details__time">
            <span class="details__time-main"><?php echo $has_details_time ? esc_html($time_main) : ''; ?></span>
            <span class="details__time-zone"><?php echo $has_details_time ? esc_html($time_tz) : ''; ?></span>
        </span>
        <div class="details__platform <?php echo esc_attr($location_class); ?>">
            <?php echo esc_html($location); ?>
            <?php if ($meeting->getMeetingJoinUrl() !== ''): ?>
                <button class="join-link-btn text-btn" data-link="<?php echo esc_url($meeting->getMeetingJoinUrl()); ?>">Join Link</button>
            <?php endif; ?>
        </div>

        <div class="reschedule-container">
            <p>Want to make a change?</p>
            <div>
                <?php
                    $bid = $meeting->getBookingId();
                    $bidAttrs = $bid !== null
                        ? 'data-meeting_id="' . esc_attr($bid) . '" data-meeting_access_token="' . esc_attr(DisplayHelper::meetingAccessToken((string) $bid)) . '"'
                        : '';
                ?>
                <button class="reschedule-btn" <?php echo $bidAttrs; ?>>Reschedule</button> or
                <button class="cancel-btn" <?php echo $bidAttrs; ?>>Cancel Meeting</button>
            </div>
        </div>
    </div>
    <?php
        // "View details" (and other) email links carry a `for=attendee|organiser`
        // param. Reaching this page from the email means the email obviously
        // arrived — so skip the "Can't see the email? … resend" prompt there.
        // It only shows on the genuine post-confirmation view (no `for`).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param on a public booking page.
        $frame4_from_email = !empty($_GET['for']);
    ?>
    <?php if (!$frame4_from_email): ?>
    <?php
        // The button's meeting_id is rendered server-side when the page loads on
        // an existing booking (email-confirm redirect). On the in-app booking
        // flow the page boots on frame-1 with no booking yet, so it loads empty
        // and applyConfirmedData() fills it in after the AJAX booking succeeds —
        // this is why the block must always be present (it was previously gated
        // on getBookingId() and so never appeared for logged-in/OAuth bookings).
    ?>
    <div class="resend-container">
        <p>Can't see the email?</p>
        <p>
            Check your spam folder
            or
            <span class="countdown"></span>
            <button type="button" class="resend-btn text-btn"<?php echo $meeting->getBookingId() !== null ? ' data-meeting_id="' . esc_attr($meeting->getBookingId()) . '"' : ''; ?>>resend</button> confirmation email.
        </p>
    </div>
    <?php endif; ?>
</section>

<?php
// When the cancel link is re-opened for a meeting that is already cancelled,
// frame-5 drops the confirmation prompt + button and just states the fact.
$frame5_already_cancelled = (get_query_var('apexianlab_booking_display_cancelled') === '1');
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing params on a public booking page.
$frame5_meeting_id   = !empty($_GET['meeting_id']) ? sanitize_text_field(wp_unslash($_GET['meeting_id'])) : '';
$frame5_cancel_token = !empty($_GET['cancel_token']) ? sanitize_text_field(wp_unslash($_GET['cancel_token'])) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
// Arrived via the emailed confirm-cancellation link (frame=5 + cancel_token):
// auto-apply the cancellation behind a loader — mirrors the booking-confirm
// flow — instead of asking the user to click "Cancel meeting" a second time.
$frame5_auto_cancel = (!$frame5_already_cancelled && $search_frame === '5' && $frame5_meeting_id !== '' && $frame5_cancel_token !== '');
?>
<section class="booking__frame <?php echo esc_attr(get_frame_class('5', $search_frame)); ?>" id="frame-5">
    <?php if ($frame5_already_cancelled) : ?>
        <h1>Meeting already cancelled</h1>
        <p>
            This meeting has already been cancelled.
            <br />
            You may close this window now.
        </p>
    <?php else : ?>
        <h1 class="frame5-prompt-title"<?php echo $frame5_auto_cancel ? ' hidden' : ''; ?>>Cancel meeting?</h1>
        <p class="frame5-prompt-text"<?php echo $frame5_auto_cancel ? ' hidden' : ''; ?>>Are you sure you want to cancel this meeting?</p>
        <h1 class="frame5-cancelling-title"<?php echo $frame5_auto_cancel ? '' : ' hidden'; ?>>Cancelling your meeting…</h1>
        <p class="frame5-cancelling-text"<?php echo $frame5_auto_cancel ? '' : ' hidden'; ?>>Please wait while we cancel this meeting.</p>
        <div class="booking__spinner frame5-spinner" aria-hidden="true"<?php echo $frame5_auto_cancel ? '' : ' hidden'; ?>></div>
        <h1 class="frame5-sent-title" hidden>Check your email</h1>
        <p class="frame5-sent-text" hidden>
            We've emailed you a link to confirm the cancellation.
            <br />
            Open it to finish cancelling this meeting.
        </p>
        <h1 class="frame5-expired-title" hidden>Link expired</h1>
        <p class="frame5-expired-text" hidden>
            This cancellation link has expired. Request a new one below.
        </p>
    <?php endif; ?>
    <div class="final-details">
        <h2>A brief overview of the project</h2>
        <p><?php echo esc_html($meeting->getScheduleDescription()); ?></p>
        <h2 class="details__meeting-heading">Meeting Details</h2>
        <div class="details-info__text">
            <span class="details__name"><?php echo esc_html($meeting->getOrganiserDisplayName()); ?></span>
        </div>
        <span class="details__date"><?php echo $has_details_date ? esc_html($date_str) : ''; ?></span>
        <span class="details__time">
            <span class="details__time-main"><?php echo $has_details_time ? esc_html($time_main) : ''; ?></span>
            <span class="details__time-zone"><?php echo $has_details_time ? esc_html($time_tz) : ''; ?></span>
        </span>
        <span class="details__platform <?php echo esc_attr($location_class); ?>"><?php echo esc_html($location); ?></span>
    </div>
    <?php if (!$frame5_already_cancelled) : ?>
    <button class="cancel-meeting-btn default-btn"<?php echo $frame5_auto_cancel ? ' hidden' : ''; ?>
        <?php echo $meeting->getBookingId() !== null
            ? 'data-meeting_id="' . esc_attr($meeting->getBookingId()) . '" data-meeting_access_token="' . esc_attr(DisplayHelper::meetingAccessToken((string) $meeting->getBookingId())) . '"'
            : ''; ?>
        type="button">
        Cancel meeting
    </button>
    <div class="resend-container frame5-resend" hidden>
        <p>Can't see the email?</p>
        <p>
            Check your spam folder
            or
            <span class="frame5-countdown"></span>
            <button type="button" class="resend-btn text-btn"
                <?php echo $meeting->getBookingId() !== null
                    ? 'data-meeting_id="' . esc_attr($meeting->getBookingId()) . '"'
                    : ''; ?>>resend</button> cancellation email.
        </p>
    </div>
    <?php endif; ?>
</section>

<section class="booking__frame <?php echo esc_attr(get_frame_class('6', $search_frame)); ?>" id="frame-6">
    <h1>Meeting cancelled</h1>
    <p>
        Your meeting has been canceled.
        <br />
        A notification has been sent to all participants.
        <br />
        You may close this window now.
    </p>
    <div class="final-details">
        <h2>A brief overview of the project</h2>
        <p><?php echo esc_html($meeting->getScheduleDescription()); ?></p>
        <h2 class="details__meeting-heading">Meeting Details</h2>
        <div class="details-info__text">
            <span class="details__name"><?php echo esc_html($meeting->getOrganiserDisplayName()); ?></span>
        </div>
        <span class="details__date"><?php echo $has_details_date ? esc_html($date_str) : ''; ?></span>
        <span class="details__time">
            <span class="details__time-main"><?php echo $has_details_time ? esc_html($time_main) : ''; ?></span>
            <span class="details__time-zone"><?php echo $has_details_time ? esc_html($time_tz) : ''; ?></span>
        </span>
        <span class="details__platform <?php echo esc_attr($location_class); ?>"><?php echo esc_html($location); ?></span>
    </div>
    <div class="reschedule-container">
        <p>Need to book another meeting?</p>
        <div>
            <button class="reschedule-btn" data-new-booking="1">Schedule a new one</button>
        </div>
    </div>
</section>
<section class="booking__frame booking__frame-7 <?php echo esc_attr(get_frame_class('slot-not-available', $search_frame)); ?>" id="frame-7">
    <h1>Time slot is not available</h1>
    <p>
        This time slot is no longer available. Please choose a different time.
    </p>
    <button class="default-btn select-new-time-btn" type="button">Select new time</button>
</section>


<?php
require APEXIANLAB_MEETING_SCHEDULER_PATH . 'templates/partials/footer-timegrid.php';