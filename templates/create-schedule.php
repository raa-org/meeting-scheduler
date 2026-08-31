<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Template name: Create Schedule
 */
use Apexianlab\Calendar\Auth\GoogleOAuthHandler;
use Apexianlab\Calendar\Database\Connection;
use Apexianlab\Calendar\Helper\ColorHelper;
use Apexianlab\Calendar\Helper\DisplayHelper;
use Apexianlab\Calendar\Repository\BookingRepository;
use Apexianlab\Calendar\Repository\MeansOfCommunicationRepository;
use Apexianlab\Calendar\Repository\ScheduleRepository;

$googleAuth = GoogleOAuthHandler::getInstance();
$currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')) . sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
apexianlab_set_session('oauth2_redirect_after_auth', $currentUrl);
$googleAuth->requireAuthorizedAdmin();

$userEmail = null;
$userInfo = $googleAuth->getUserInfo();
$isAuthenticated = $googleAuth->isAuthenticated();
if (!empty($userInfo['email'])) {
    $userEmail = $userInfo['email'];
}

$connection = Connection::getInstance();
$pdo = $connection->pdo();
$scheduleRepo = new ScheduleRepository($pdo);
$meansRepo = new MeansOfCommunicationRepository($pdo);

$schedule_filters = $scheduleRepo->findAllByEmail($userEmail);

// Hide "Invited Meetings" filter when user has no bookings on others' schedules.
$bookingRepo = new BookingRepository($pdo);
$ownScheduleIds = array_values(array_filter(array_column($schedule_filters, 'id')));
$has_invited_meetings = $userEmail !== null
    && $bookingRepo->hasInvitedBookings((string) $userEmail, $ownScheduleIds);

$organiser_full_preview = '';
if (is_array($userInfo) && $userInfo !== []) {
    $organiser_full_preview = mb_substr(DisplayHelper::fullNameFromUserInfo($userInfo), 0, 64);
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Calendar</title>
    <?php wp_head(); ?>
</head>
<?php
$initials = 'U';
if ($userInfo) {
    $firstName = $userInfo['given_name'] ?? $userInfo['name'] ?? '';
    $lastName = $userInfo['family_name'] ?? '';
    if (!empty($firstName) && !empty($lastName)) {
        $initials = mb_strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1));
    } elseif (!empty($firstName)) {
        $initials = mb_strtoupper(mb_substr($firstName, 0, 1));
    } elseif (!empty($userInfo['email'])) {
        $initials = mb_strtoupper(mb_substr($userInfo['email'], 0, 1));
    }
}
?>
<body <?php body_class(); ?>>
<div class="user-menu">
    <button class="user-menu__toggle" aria-label="User menu">
        <span class="user-menu__initials"><?php echo esc_html($initials); ?></span>
    </button>
    <div class="user-menu__dropdown">
        <?php if (!empty($userInfo)): ?>
            <div class="user-menu__info">
                <?php if (!empty($userInfo['name'])): ?>
                    <div class="user-menu__name"><?php echo esc_html($userInfo['name']); ?></div>
                <?php endif; ?>
                <?php if (!empty($userInfo['email'])): ?>
                    <div class="user-menu__email"><?php echo esc_html($userInfo['email']); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($isAuthenticated): ?>
            <button class="user-menu__logout" data-logout-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                Logout
            </button>
        <?php endif; ?>
    </div>
</div>

<?php
?>
<main class="calendar-page">
    <aside class="menu-section">
        <a href="#" class="menu-section__logo">
            <img src="<?php echo esc_url(APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/images/apexianlab-logo.svg'); ?>" alt="apexianlab" />
        </a>
        <button class="menu-section__btn">Appointment Schedule</button>

        <div class="menu-section__filtration filtration">
            <?php foreach ($schedule_filters as $schedule): ?>
                <?php
                    $color_class = ColorHelper::classByCode(isset($schedule['color']) ? (int) $schedule['color'] : null);
                    $schedule_id = isset($schedule['id']) ? (string) $schedule['id'] : '';
                    $booking_url = DisplayHelper::bookingUrl($schedule);
                    $weekly_rule_raw     = $schedule['schedule_weekly_rule'] ?? null;
                    $weekly_rule_payload = is_array($weekly_rule_raw)
                        ? $weekly_rule_raw
                        : (is_string($weekly_rule_raw) && $weekly_rule_raw !== ''
                            ? (json_decode($weekly_rule_raw, true) ?: null)
                            : null);
                    $durations_raw = $schedule['durations'] ?? null;
                    $durations_payload = is_string($durations_raw)
                        ? (json_decode($durations_raw, true) ?: [15, 30, 45, 60, 90])
                        : (is_array($durations_raw) ? $durations_raw : [15, 30, 45, 60, 90]);
                    $schedule_payload = [
                        'id' => $schedule_id,
                        'subject' => (string) ($schedule['subject'] ?? ''),
                        'description' => (string) ($schedule['description'] ?? ''),
                        'color' => isset($schedule['color']) ? (int) $schedule['color'] : null,
                        'reminder_minutes' => isset($schedule['reminder_minutes']) ? (int) $schedule['reminder_minutes'] : null,
                        'calendar_means_of_communication_id' => (string) ($schedule['calendar_means_of_communication_id'] ?? ''),
                        'schedule_repeat' => (string) ($schedule['schedule_repeat'] ?? 'weekly'),
                        'repeat_interval_weeks' => isset($schedule['repeat_interval_weeks']) ? (int) $schedule['repeat_interval_weeks'] : 1,
                        'is_public' => !empty($schedule['is_public']) && ($schedule['is_public'] === true || $schedule['is_public'] === 't' || $schedule['is_public'] === 1 || $schedule['is_public'] === '1'),
                        'require_email_verification' => !empty($schedule['require_email_verification']) && ($schedule['require_email_verification'] === true || $schedule['require_email_verification'] === 't' || $schedule['require_email_verification'] === 1 || $schedule['require_email_verification'] === '1'),
                        'schedule_ranges' => $schedule['schedule_ranges'] ?? '[]',
                        'schedule_weekly_rule' => $weekly_rule_payload,
                        'name_format' => (string) ($schedule['name_format'] ?? 'full'),
                        'name_format_custom' => (string) ($schedule['name_format_custom'] ?? ''),
                        'durations' => array_values(array_map('intval', $durations_payload)),
                        'additional_recipients' => (static function ($raw) {
                            $list = is_string($raw) ? json_decode($raw, true) : $raw;
                            return is_array($list) ? array_values(array_map('strval', $list)) : [];
                        })($schedule['additional_recipients'] ?? null),
                    ];
                ?>
                <div class="checkbox-container <?php echo esc_attr($color_class); ?>" data-schedule-id="<?php echo esc_attr($schedule_id); ?>" data-schedule-booking-url="<?php echo esc_url($booking_url); ?>" data-schedule="<?php echo esc_attr(wp_json_encode($schedule_payload)); ?>">
                    <label class="checkbox-container__label">
                        <input type="checkbox" class="js-schedule-filter" value="<?php echo esc_attr($schedule_id); ?>" name="schedule_filter[]" checked />
                        <span class="custom-checkbox"></span>
                        <span class="checkbox-container__title"><?php echo esc_html($schedule['subject']); ?></span>
                    </label>
                    <div class="checkbox-container__actions">
                        <button type="button" class="checkbox-container__qr-btn js-schedule-qr" title="<?php esc_attr_e('Show QR code', 'apexianlab'); ?>" aria-label="<?php esc_attr_e('Show QR code', 'apexianlab'); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M3 3h8v8H3V3zm2 2v4h4V5H5z"/>
                                <path d="M3 13h8v8H3v-8zm2 2v4h4v-4H5z"/>
                                <path d="M13 3h8v8h-8V3zm2 2v4h4V5h-4z"/>
                                <path d="M13 13h2v2h-2zM17 13h2v2h-2zM15 15h2v2h-2zM13 17h2v2h-2zM19 15h2v2h-2zM17 17h2v2h-2zM19 19h2v2h-2zM15 19h2v2h-2z"/>
                            </svg>
                        </button>
                        <button type="button" class="checkbox-container__copy-link js-schedule-link-toggle" aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e('Share link', 'apexianlab'); ?>" aria-label="<?php esc_attr_e('Share link', 'apexianlab'); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M10.59 13.41a1.5 1.5 0 010-2.12l3.88-3.88a3 3 0 114.24 4.24l-2.12 2.12" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M13.41 10.59a1.5 1.5 0 010 2.12l-3.88 3.88a3 3 0 11-4.24-4.24l2.12-2.12" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <button type="button" class="checkbox-container__menu-trigger" aria-haspopup="true" aria-expanded="false" aria-label="<?php esc_attr_e('Open schedule menu', 'apexianlab'); ?>">
                            <span></span><span></span><span></span>
                        </button>
                    </div>
                    <div class="checkbox-container__link-menu" role="menu">
                        <button type="button" class="checkbox-container__menu-item js-schedule-copy-link" role="menuitem"><?php esc_html_e('Copy link', 'apexianlab'); ?></button>
                        <button type="button" class="checkbox-container__menu-item js-schedule-generate-qr" role="menuitem"><?php esc_html_e('Generate QR', 'apexianlab'); ?></button>
                    </div>
                    <div class="checkbox-container__menu" role="menu">
                        <button type="button" class="checkbox-container__menu-item js-schedule-open" role="menuitem"><?php esc_html_e('Open', 'apexianlab'); ?></button>
                        <button type="button" class="checkbox-container__menu-item js-schedule-edit" role="menuitem"><?php esc_html_e('Edit', 'apexianlab'); ?></button>
                        <button type="button" class="checkbox-container__menu-item js-schedule-delete" role="menuitem"><?php esc_html_e('Delete', 'apexianlab'); ?></button>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($schedule_filters)): ?>
                <p class="filtration__empty"><?php esc_html_e('No schedules found.', 'apexianlab'); ?></p>
            <?php endif; ?>
            <?php if ($has_invited_meetings): ?>
            <div class="filtration__divider" role="separator" aria-hidden="true"></div>
            <div class="checkbox-container checkbox-container--invited gray">
                <label class="checkbox-container__label">
                    <input type="checkbox" class="js-invited-filter" name="invited_filter" checked />
                    <span class="custom-checkbox"></span>
                    <span class="checkbox-container__title"><?php esc_html_e('Invited', 'apexianlab'); ?></span>
                </label>
            </div>
            <?php endif; ?>
        </div>
        <span class="menu-section__copyright">
            Powered by <span class="menu-section__copyright-brand">Right&Above</span>
        </span>
    </aside>
    <section class="calendar-section"></section>
</main>

<div id="create-schedule-modal" class="create-schedule-modal csm--rail" aria-hidden="true">
    <div class="create-schedule-modal__backdrop"></div>
    <div class="create-schedule-modal__box">
        <header class="create-schedule-modal__header">
            <h2 class="create-schedule-modal__title"><?php esc_html_e('Create Schedule', 'apexianlab'); ?></h2>
            <button type="button" class="create-schedule-modal__close" aria-label="<?php esc_attr_e('Close', 'apexianlab'); ?>">&times;</button>
        </header>
        <form id="create-schedule-form" class="create-schedule-form">
            <div id="schedule-scope-warning" class="create-schedule-form__scope-warning" role="alert" hidden>
                <p class="create-schedule-form__scope-warning-text"></p>
                <button type="button" class="text-btn" id="schedule-scope-reconnect"><?php esc_html_e('Reconnect Google account', 'apexianlab'); ?></button>
            </div>
            <div class="section">
                <div class="section__rail-head">
                    <span class="section__num" aria-hidden="true">1</span>
                    <h3 class="section__title"><?php esc_html_e('Details', 'apexianlab'); ?></h3>
                </div>
                <div class="section__body">
            <div class="create-schedule-form__row create-schedule-form__row--name-format">
                <div class="create-schedule-form__field">
                    <label for="name_format"><?php esc_html_e('Organiser name display', 'apexianlab'); ?></label>
                    <select name="name_format" id="name_format">
                        <?php
                        $name_format_option_keys = ['full', 'first_last_initial', 'initial_last', 'first_only', 'initials'];
                        foreach ($name_format_option_keys as $nf_key) {
                            $opt_label = $organiser_full_preview !== ''
                                ? DisplayHelper::formatOrganiserName($organiser_full_preview, $nf_key, null)
                                : '—';
                            ?>
                        <option value="<?php echo esc_attr($nf_key); ?>"><?php echo esc_html($opt_label); ?></option>
                            <?php
                        }
                        ?>
                        <option value="custom"><?php esc_html_e('Custom…', 'apexianlab'); ?></option>
                    </select>
                </div>
                <div class="create-schedule-form__field" id="name_format_custom_wrap" style="display:none;">
                    <label for="name_format_custom"><?php esc_html_e('Custom name', 'apexianlab'); ?></label>
                    <input type="text" id="name_format_custom" name="name_format_custom" maxlength="128" autocomplete="off" />
                </div>
            </div>
            <div class="create-schedule-form__field">
                <input type="text" id="schedule-name" name="name" placeholder="<?php esc_attr_e('Title', 'apexianlab'); ?>" maxlength="64" required />
                <p class="create-schedule-form__checkbox-note"><?php esc_html_e('This field will appear as the meeting name on the booking page (e.g. "Meeting with Clients").', 'apexianlab'); ?></p>
            </div>
            <div class="create-schedule-form__field">
                <textarea id="schedule-description" name="description" placeholder="Description"></textarea>
                <p class="create-schedule-form__checkbox-note"><?php esc_html_e('Shown under the meeting title on the booking page to give attendees more context (e.g. "Discuss project requirements and timeline").', 'apexianlab'); ?></p>
            </div>
                </div>
            </div>
            <div class="section">
                <div class="section__rail-head">
                    <span class="section__num" aria-hidden="true">2</span>
                    <h3 class="section__title"><?php esc_html_e('Availability', 'apexianlab'); ?></h3>
                </div>
                <div class="section__body">
            <fieldset class="create-schedule-form__section">
                <div class="create-schedule-form__row create-schedule-form__row--repeat-timezone">
                    <div id="schedule-availability" class="create-schedule-form__field" data-mode="none">
                        <input type="hidden" name="repeat" id="schedule-repeat-hidden" value="does_not_repeat" />
                        <input type="hidden" name="repeat_interval_weeks" id="schedule-repeat-interval-hidden" value="2" />
                        <input type="hidden" name="repeat_end_never" id="schedule-repeat-end-never-hidden" value="1" />
                    </div>
                    <div class="create-schedule-form__field">
                        <label for="schedule-timezone-input"><?php esc_html_e('Time Zone', 'apexianlab'); ?></label>
                        <div class="tz-picker">
                            <input type="text" id="schedule-timezone-input" class="tz-picker__input" placeholder="<?php esc_attr_e('Search timezone…', 'apexianlab'); ?>" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="schedule-timezone-dropdown" />
                            <span class="tz-picker__arrow" aria-hidden="true"></span>
                            <div id="schedule-timezone-dropdown" class="tz-picker__dropdown" role="listbox" hidden></div>
                        </div>
                        <select id="schedule-timezone" name="timezone" aria-hidden="true" tabindex="-1"></select>
                    </div>
                    <div class="create-schedule-form__field create-schedule-form__field--availability">
                        <div class="calendar-means-row">
                            <div class="avail-picker" id="schedule-availability-picker">
                                <button type="button" class="avail-picker__trigger" id="schedule-availability-trigger" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="schedule-availability-label">
                                    <span class="avail-picker__value" id="schedule-availability-value"><?php esc_html_e('Does not repeat', 'apexianlab'); ?></span>
                                    <span class="avail-picker__arrow" aria-hidden="true"></span>
                                </button>
                                <div class="avail-picker__dropdown" id="schedule-availability-dropdown" role="listbox" hidden></div>
                            </div>
                            <select id="schedule-availability-select" aria-hidden="true" tabindex="-1">
                                <option value="none"><?php esc_html_e('Does not repeat', 'apexianlab'); ?></option>
                                <option value="weekly"><?php esc_html_e('Repeat weekly', 'apexianlab'); ?></option>
                                <option value="custom-label" hidden><?php esc_html_e('Repeat every 2 weeks', 'apexianlab'); ?></option>
                                <option value="custom"><?php esc_html_e('Custom', 'apexianlab'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <div id="specific-dates-panel" class="availability-panel">
                    <div id="specific-dates-list" class="specific-dates-list"></div>
                    <button type="button" class="specific-dates__add text-btn" id="specific-date-add"><?php esc_html_e('+ Add a date', 'apexianlab'); ?></button>
                </div>
                <template id="specific-date-row-template">
                    <div class="specific-date-row">
                        <input type="date" class="specific-date-row__date create-schedule-form__input" />
                        <div class="specific-date-row__times">
                            <div class="time-picker-gc">
                                <input type="hidden" class="slot-start" value="09:00" />
                                <button type="button" class="time-picker-gc__trigger" tabindex="0">9:00 AM</button>
                                <div class="time-picker-gc__dropdown"></div>
                            </div>
                            <span class="specific-date-row__dash">–</span>
                            <div class="time-picker-gc">
                                <input type="hidden" class="slot-end" value="17:00" />
                                <button type="button" class="time-picker-gc__trigger" tabindex="0">5:00 PM</button>
                                <div class="time-picker-gc__dropdown"></div>
                            </div>
                        </div>
                        <button type="button" class="specific-date-row__remove" title="<?php esc_attr_e('Remove', 'apexianlab'); ?>" aria-label="<?php esc_attr_e('Remove', 'apexianlab'); ?>">
                            <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10.75 16.75V15.25H21.25V16.75H10.75Z" fill="#5C6166"/></svg>
                        </button>
                    </div>
                </template>
                <div id="weekly-availability-panel" class="availability-panel is-hidden">
                <div class="create-schedule-form__daily" id="daily-availability">
                    <?php
                    $weekdays = [
                        1 => __('Mon', 'apexianlab'),
                        2 => __('Tue', 'apexianlab'),
                        3 => __('Wed', 'apexianlab'),
                        4 => __('Thu', 'apexianlab'),
                        5 => __('Fri', 'apexianlab'),
                        6 => __('Sat', 'apexianlab'),
                        7 => __('Sun', 'apexianlab'),
                    ];
                    foreach ($weekdays as $dow => $label):
                    ?>
                    <div class="create-schedule-form__day" data-dow="<?php echo (int) $dow; ?>">
                        <span class="create-schedule-form__day-label"><?php echo esc_html($label); ?></span>
                        <div class="create-schedule-form__slots">
                            <div class="create-schedule-form__slot">
                                <div class="time-picker-gc">
                                    <input type="hidden" class="slot-start" value="09:00" />
                                    <button type="button" class="time-picker-gc__trigger" tabindex="0">9:00 AM</button>
                                    <div class="time-picker-gc__dropdown"></div>
                                </div>
                                <span>–</span>
                                <div class="time-picker-gc">
                                    <input type="hidden" class="slot-end" value="18:00" />
                                    <button type="button" class="time-picker-gc__trigger" tabindex="0">6:00 PM</button>
                                    <div class="time-picker-gc__dropdown"></div>
                                </div>
                                <button type="button" class="create-schedule-form__slot-remove" title="<?php esc_attr_e('No free time on this day', 'apexianlab'); ?>"><svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10.75 16.75V15.25H21.25V16.75H10.75Z" fill="#5C6166"/></svg></button>
                            </div>
                        </div>
                        <div class="create-schedule-form__unavailable-text" aria-hidden="true"><?php esc_html_e('Not Available', 'apexianlab'); ?></div>
                        <div class="create-schedule-form__day-actions">
                            <input type="checkbox" class="day-not-available" <?php echo ($dow >= 6) ? 'checked' : ''; ?> style="position:absolute;clip:rect(0,0,0,0);" aria-hidden="true" />
                            <button type="button" class="create-schedule-form__day-action create-schedule-form__day-action--add-period" title="<?php esc_attr_e('Add another period for this day', 'apexianlab'); ?>"><svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><mask id="mask0_4548_5214" style="mask-type:alpha" maskUnits="userSpaceOnUse" x="7" y="7" width="18" height="18"><rect x="7" y="7" width="18" height="18" fill="#D9D9D9"/></mask><g mask="url(#mask0_4548_5214)"><path d="M16 22.75C15.7875 22.75 15.6094 22.6781 15.4656 22.5344C15.3219 22.3906 15.25 22.2125 15.25 22V16.75H10C9.7875 16.75 9.60938 16.6781 9.46562 16.5344C9.32187 16.3906 9.25 16.2125 9.25 16C9.25 15.7875 9.32187 15.6094 9.46562 15.4656C9.60938 15.3219 9.7875 15.25 10 15.25H15.25V10C15.25 9.7875 15.3219 9.60938 15.4656 9.46562C15.6094 9.32187 15.7875 9.25 16 9.25C16.2125 9.25 16.3906 9.32187 16.5344 9.46562C16.6781 9.60938 16.75 9.7875 16.75 10V15.25H22C22.2125 15.25 22.3906 15.3219 22.5344 15.4656C22.6781 15.6094 22.75 15.7875 22.75 16C22.75 16.2125 22.6781 16.3906 22.5344 16.5344C22.3906 16.6781 22.2125 16.75 22 16.75H16.75V22C16.75 22.2125 16.6781 22.3906 16.5344 22.5344C16.3906 22.6781 16.2125 22.75 16 22.75Z" fill="#5C6166"/></g></svg></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                </div>
                <div id="custom-availability-panel" class="availability-panel is-hidden"></div>
                <template id="slot-row-template">
                    <div class="create-schedule-form__slot">
                        <div class="time-picker-gc">
                            <input type="hidden" class="slot-start" value="" />
                            <button type="button" class="time-picker-gc__trigger" tabindex="0">--:--</button>
                            <div class="time-picker-gc__dropdown"></div>
                        </div>
                        <span>–</span>
                        <div class="time-picker-gc">
                            <input type="hidden" class="slot-end" value="" />
                            <button type="button" class="time-picker-gc__trigger" tabindex="0">--:--</button>
                            <div class="time-picker-gc__dropdown"></div>
                        </div>
                        <button type="button" class="create-schedule-form__slot-remove" title="<?php esc_attr_e('No free time on this day', 'apexianlab'); ?>"><svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10.75 16.75V15.25H21.25V16.75H10.75Z" fill="#5C6166"/></svg></button>
                    </div>
                </template>
                <input type="hidden" name="date_from" id="schedule-date-from" value="<?php echo esc_attr(date('Y-m-d')); ?>" />
                <input type="hidden" name="date_to" id="schedule-date-to" value="<?php echo esc_attr(date('Y-m-d', strtotime('+1 months'))); ?>" />
                <div class="create-schedule-form__field create-schedule-form__field--durations">
                    <label><?php esc_html_e('Meeting length options', 'apexianlab'); ?></label>
                    <p class="create-schedule-form__hint"><?php esc_html_e('Choose which meeting durations guests can pick when booking a time slot. They select one option before confirming.', 'apexianlab'); ?></p>
                    <div class="durations-chips" id="schedule-durations-chips" role="group"></div>
                </div>
            </fieldset>
                </div>
            </div>
            <div class="section">
                <div class="section__rail-head">
                    <span class="section__num" aria-hidden="true">3</span>
                    <h3 class="section__title"><?php esc_html_e('Meeting format', 'apexianlab'); ?></h3>
                </div>
                <div class="section__body">
            <div class="create-schedule-form__row create-schedule-form__row--calendar">
                <div class="create-schedule-form__field create-schedule-form__field--calendar-wrap">
                    <label for="schedule-calendar"><?php esc_html_e('Please select a meeting format', 'apexianlab'); ?></label>
                    <div class="calendar-means-row">
                        <select id="schedule-calendar" name="calendar_means_of_communication_id" required>
                            <?php
                            $calendar_means = $meansRepo->getAll();
                            foreach ($calendar_means as $mean):
                            ?>
                            <option value="<?php echo esc_attr($mean['id']); ?>" data-title="<?php echo esc_attr((string) ($mean['title'] ?? '')); ?>"><?php echo esc_html($mean['title'] ?: $mean['id']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="google-calendar-connect" class="calendar-means-row" style="display:none;margin-top:8px;">
                        <div id="google-calendar-connect-status"><?php esc_html_e('Your Google Calendar is not connected', 'apexianlab'); ?></div>
                        <button type="button" id="google-calendar-connect-btn" class="text-btn"><?php esc_html_e('Connect Google Calendar', 'apexianlab'); ?></button>
                    </div>
                    <div id="google-calendar-picker" class="create-schedule-form__field" style="display:none;margin-top:8px;">
                        <label class="create-schedule-form__label" for="google-calendar-select"><?php esc_html_e('Save events to calendar', 'apexianlab'); ?></label>
                        <select id="google-calendar-select" name="google_calendar_select" class="create-schedule-form__input"></select>
                    </div>
                </div>
            </div>
                </div>
            </div>
            <div class="section">
                <div class="section__rail-head">
                    <span class="section__num" aria-hidden="true">4</span>
                    <h3 class="section__title"><?php esc_html_e('Notifications & access', 'apexianlab'); ?></h3>
                </div>
                <div class="section__body">
            <div class="create-schedule-form__row">
                <div class="create-schedule-form__field create-schedule-form__field--lead">
                    <label><?php esc_html_e('Meeting reminder', 'apexianlab'); ?></label>
                    <div class="create-schedule-form__lead">
                        <input type="number" name="booking_lead_value" min="0" max="20160" value="2" />
                        <select name="booking_lead_unit">
                            <option value="hours" selected><?php esc_html_e('hours', 'apexianlab'); ?></option>
                            <option value="minutes"><?php esc_html_e('minutes', 'apexianlab'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="create-schedule-form__field">
                    <label for="schedule-color"><?php esc_html_e('Color', 'apexianlab'); ?></label>
                    <select id="schedule-color" name="color">
                        <option value="1" selected><?php esc_html_e('Blue', 'apexianlab'); ?></option>
                        <option value="2"><?php esc_html_e('Green', 'apexianlab'); ?></option>
                        <option value="3"><?php esc_html_e('Cyan', 'apexianlab'); ?></option>
                        <option value="4"><?php esc_html_e('Pink', 'apexianlab'); ?></option>
                        <option value="5"><?php esc_html_e('Yellow', 'apexianlab'); ?></option>
                        <option value="6"><?php esc_html_e('Red', 'apexianlab'); ?></option>
                        <option value="7"><?php esc_html_e('Orange', 'apexianlab'); ?></option>
                        <option value="8"><?php esc_html_e('Violet', 'apexianlab'); ?></option>
                    </select>
                </div>
            </div>
            <input type="hidden" name="daily_slots" id="daily-slots-input" value="" />
            <input type="hidden" name="durations" id="schedule-durations-input-hidden" value="[15,30,45,60,90]" />
            <div class="create-schedule-form__row">
                <div class="create-schedule-form__field create-schedule-form__field--recipients">
                    <label><?php esc_html_e('Additional recipients', 'apexianlab'); ?></label>
                    <p class="create-schedule-form__hint"><?php esc_html_e('These email addresses receive a copy (CC) of every notification sent to the schedule owner — new bookings, reschedules and cancellations (e.g. "olivia@example.com", "liam@example.com").', 'apexianlab'); ?></p>
                    <div id="schedule-recipients-list" class="recipients-list"></div>
                    <button type="button" class="recipients__add text-btn" id="schedule-recipient-add"><?php esc_html_e('+ Add recipient', 'apexianlab'); ?></button>
                </div>
            </div>
            <div class="create-schedule-form__row">
                <div class="create-schedule-form__field create-schedule-form__field--checkbox">
                    <label class="create-schedule-form__checkbox-label" for="schedule-require-email-verification">
                        <input type="checkbox" id="schedule-require-email-verification" name="require_email_verification" value="1" class="create-schedule-form__checkbox-input" />
                        <span><?php esc_html_e('Require email verification', 'apexianlab'); ?></span>
                    </label>
                    <p class="create-schedule-form__checkbox-note"><?php esc_html_e('Attendees confirm each booking via an emailed link. When off, unregistered users are confirmed automatically.', 'apexianlab'); ?></p>
                </div>
            </div>
            <?php // is_public toggle removed: every schedule is public. See schedule-modal.js collect(). ?>
            <template id="recipient-row-template">
                <div class="recipient-row">
                    <input type="email" class="recipient-row__email create-schedule-form__input" placeholder="example@test.com" inputmode="email" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" name="apexianlab-recipient-no-autofill" readonly data-lpignore="true" data-1p-ignore data-form-type="other" />
                    <button type="button" class="recipient-row__remove" title="<?php esc_attr_e('Remove', 'apexianlab'); ?>" aria-label="<?php esc_attr_e('Remove', 'apexianlab'); ?>">
                        <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10.75 16.75V15.25H21.25V16.75H10.75Z" fill="#5C6166"/></svg>
                    </button>
                </div>
            </template>
            <button type="submit" class="create-schedule-form__submit"><?php esc_html_e('Save Schedule', 'apexianlab'); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="custom-availability-modal" class="create-schedule-modal create-schedule-modal--centered" aria-hidden="true">
    <div class="create-schedule-modal__backdrop"></div>
    <div class="create-schedule-modal__box create-schedule-modal__box--narrow">
        <header class="create-schedule-modal__header">
            <h2 class="create-schedule-modal__title" id="custom-availability-modal-title"><?php esc_html_e('Custom recurrence', 'apexianlab'); ?></h2>
            <button type="button" class="create-schedule-modal__close" id="custom-availability-modal-close" aria-label="<?php esc_attr_e('Close', 'apexianlab'); ?>">&times;</button>
        </header>
        <div class="custom-availability-form">
            <div class="custom-availability-form__row custom-availability-form__row--inline">
                <span class="custom-availability-form__static"><?php esc_html_e('Repeat every', 'apexianlab'); ?></span>
                <input type="number" class="custom-availability-form__input custom-availability-form__interval" id="custom-availability-interval" min="1" max="52" value="2" />
                <span class="custom-availability-form__static"><?php esc_html_e('weeks', 'apexianlab'); ?></span>
            </div>
            <div class="custom-availability-form__row">
                <label class="custom-availability-form__label" for="custom-availability-starts"><?php esc_html_e('Starts', 'apexianlab'); ?></label>
                <div class="custom-date">
                    <input type="date" class="custom-availability-form__input custom-date__native" id="custom-availability-starts" />
                    <span class="custom-date__display" aria-hidden="true"></span>
                </div>
            </div>
            <div class="custom-availability-form__row">
                <span class="custom-availability-form__label custom-availability-form__label--block"><?php esc_html_e('Ends', 'apexianlab'); ?></span>
                <div class="custom-availability-form__ends">
                    <label class="custom-availability-form__radio-label">
                        <input type="radio" name="custom_availability_ends" value="never" id="custom-availability-ends-never" checked />
                        <?php esc_html_e('Never', 'apexianlab'); ?>
                    </label>
                    <label class="custom-availability-form__radio-label">
                        <input type="radio" name="custom_availability_ends" value="on_date" id="custom-availability-ends-on" />
                        <?php esc_html_e('On date', 'apexianlab'); ?>
                    </label>
                </div>
                <div class="custom-date">
                    <input type="date" class="custom-availability-form__input custom-date__native" id="custom-availability-ends-date" disabled />
                    <span class="custom-date__display" aria-hidden="true"></span>
                </div>
            </div>
            <div class="custom-availability-form__actions">
                <button type="button" class="create-schedule-form__submit" id="custom-availability-done"><?php esc_html_e('Done', 'apexianlab'); ?></button>
            </div>
        </div>
    </div>
</div>

<div id="schedule-copy-snackbar" class="schedule-copy-snackbar" role="status" aria-live="polite" aria-atomic="true" aria-hidden="true" hidden>
    <span class="schedule-copy-snackbar__text"></span>
    <button type="button" class="schedule-copy-snackbar__close" aria-label="<?php esc_attr_e('Close', 'apexianlab'); ?>">&times;</button>
</div>

<?php wp_footer(); ?>
</body>
</html>
