<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar;

use Apexianlab\Calendar\Admin\MailDebugPage;
use Apexianlab\Calendar\Admin\SetupNotices;
use Apexianlab\Calendar\AiAssistant\AiAjaxHandler as AiAjaxHandler;
use Apexianlab\Calendar\Ajax\AvailabilityAjaxHandler;
use Apexianlab\Calendar\Ajax\BookingAjaxHandler;
use Apexianlab\Calendar\Ajax\ScheduleAjaxHandler;
use Apexianlab\Calendar\Auth\GoogleOAuthHandler;
use Apexianlab\Calendar\Captcha\ImageCaptcha;
use Apexianlab\Calendar\Database\Connection;
use Apexianlab\Calendar\Migration\Migrator;
use Apexianlab\Calendar\Repository\BookingRepository;
use Apexianlab\Calendar\Repository\MeansOfCommunicationRepository;
use Apexianlab\Calendar\Repository\ScheduleRepository;
use Apexianlab\Calendar\Service\BookingService;
use Apexianlab\Calendar\Service\IntegrationService;
use Apexianlab\Calendar\Service\NotificationService;
use Apexianlab\Calendar\Setup\Installer;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        register_activation_hook(APEXIANLAB_MEETING_SCHEDULER_PATH . 'apexianlab-meeting-scheduler.php', [self::class, 'onActivation']);
        register_deactivation_hook(APEXIANLAB_MEETING_SCHEDULER_PATH . 'apexianlab-meeting-scheduler.php', [self::class, 'onDeactivation']);

        add_action('plugins_loaded', [$this, 'init'], 20);
    }

    public function init(): void
    {
        $this->maybeStartSession();
        ImageCaptcha::register();
        $this->registerPageTemplates();
        $this->maybeFlushRewriteRules();
        $this->registerMailFilters();

        // Ensure routing pages exist even when the plugin was already active
        // before Installer landed (no reactivation required).
        add_action('init', static function (): void {
            Installer::ensurePages();
        }, 5);

        // Registered before the calendar gate so diagnostics / setup notices
        // stay reachable even when the module fails to boot.
        if (is_admin()) {
            SetupNotices::register();
            MailDebugPage::register();
        }

        if (!Config::isCalendarEnabled()) {
            return;
        }

        $this->bootCalendarModule();
    }

    private function bootCalendarModule(): void
    {
        try {
            $connection = Connection::getInstance();
            Migrator::run($connection);

            AvailabilityAjaxHandler::register();
            BookingAjaxHandler::register();
            ScheduleAjaxHandler::register();
            AiAjaxHandler::register();

            MeetingBookingTemplate::getInstance();
            SlugRouter::getInstance();
            ScheduleAdminRouter::getInstance();
            GoogleOAuthHandler::getInstance();

            // Async post-booking work (emails) and post-cancel cleanup (Google)
            // — kept out of the user-facing request so the HTTP response returns fast.
            $bookingServiceFactory = static function (): BookingService {
                $pdo          = Connection::getInstance()->pdo();
                $bookingRepo  = new BookingRepository($pdo);
                $scheduleRepo = new ScheduleRepository($pdo);
                $meansRepo    = new MeansOfCommunicationRepository($pdo);
                $service      = new BookingService($bookingRepo, $scheduleRepo);
                $service->setIntegrationService(new IntegrationService($bookingRepo, $scheduleRepo, $meansRepo));
                $service->setNotificationService(new NotificationService($bookingRepo, $scheduleRepo, $meansRepo));
                return $service;
            };
            add_action(BookingService::CRON_CANCEL_CLEANUP, static function (string $meetingId) use ($bookingServiceFactory): void {
                $bookingServiceFactory()->cleanupCancelledMeeting($meetingId);
            }, 10, 1);
            add_action(BookingService::CRON_CONFIRMED_EMAILS, static function (string $meetingId) use ($bookingServiceFactory): void {
                $bookingServiceFactory()->sendConfirmationEmailsAsync($meetingId);
            }, 10, 1);

            // Async Google mirror of a schedule's availability windows — kept
            // off the request so create/update/delete return fast.
            $integrationFactory = static function (): IntegrationService {
                $pdo = Connection::getInstance()->pdo();

                return new IntegrationService(
                    new BookingRepository($pdo),
                    new ScheduleRepository($pdo),
                    new MeansOfCommunicationRepository($pdo)
                );
            };
            add_action(IntegrationService::CRON_SYNC_SCHEDULE, static function (string $scheduleId) use ($integrationFactory): void {
                $integrationFactory()->syncScheduleGoogle($scheduleId);
            }, 10, 1);
            add_action(IntegrationService::CRON_DELETE_SCHEDULE, static function (string $email, array $eventIds) use ($integrationFactory): void {
                $integrationFactory()->deleteScheduleEvents($email, $eventIds);
            }, 10, 2);

            // Recurring poll: sync Google-side cancel/reschedule into bookings.
            add_filter('cron_schedules', static function (array $schedules): array {
                if (!isset($schedules['apexianlab_5min'])) {
                    $schedules['apexianlab_5min'] = [
                        'interval' => 5 * MINUTE_IN_SECONDS,
                        'display'  => 'Every 5 minutes (Apexianlab Google sync)',
                    ];
                }
                return $schedules;
            });
            if (!wp_next_scheduled(BookingService::CRON_GCAL_SYNC)) {
                wp_schedule_event(time() + 60, 'apexianlab_5min', BookingService::CRON_GCAL_SYNC);
            }
            add_action(BookingService::CRON_GCAL_SYNC, static function () use ($bookingServiceFactory): void {
                $bookingServiceFactory()->syncGoogleExternalChanges();
            }, 10, 0);

            $this->registerAssets();
        } catch (\Throwable $e) {
            add_action('admin_notices', static function () use ($e): void {
                echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }

    private function registerPageTemplates(): void
    {
        add_filter('theme_page_templates', static function (array $templates): array {
            $templates['meeting-booking.php'] = __('Meeting Booking (Apexianlab)', 'apexianlab-meeting-scheduler');
            $templates['create-schedule.php'] = __('Create Schedule (Apexianlab)', 'apexianlab-meeting-scheduler');
            $templates['ai-assistant.php']    = __('AI Assistant (Apexianlab)', 'apexianlab-meeting-scheduler');
            return $templates;
        }, 20);

        add_filter('template_include', static function (string $template): string {
            if (function_exists('get_query_var') && (string) get_query_var('ai_chat_id') !== '') {
                $candidate = APEXIANLAB_MEETING_SCHEDULER_PATH . 'templates/ai-assistant.php';
                if (is_readable($candidate)) {
                    return $candidate;
                }
            }
            $slug = get_page_template_slug();
            $map = [
                'meeting-booking.php' => 'templates/meeting-booking.php',
                'create-schedule.php' => 'templates/create-schedule.php',
                'ai-assistant.php'    => 'templates/ai-assistant.php',
            ];
            if (isset($map[$slug])) {
                $candidate = APEXIANLAB_MEETING_SCHEDULER_PATH . $map[$slug];
                if (is_readable($candidate)) {
                    return $candidate;
                }
            }
            return $template;
        }, 50);

        add_filter('body_class', static function (array $classes): array {
            if (!function_exists('get_page_template_slug') || !function_exists('is_page')) {
                return $classes;
            }
            $isAiAssistant = (
                (is_page() && (get_page_template_slug() === 'ai-assistant.php' || is_page('ai-assistant')))
                || (function_exists('get_query_var') && (string) get_query_var('ai_chat_id') !== '')
                || (function_exists('get_queried_object') && ($obj = get_queried_object()) instanceof \WP_Post && $obj->post_name === 'ai-assistant')
            );
            if ($isAiAssistant) {
                $classes[] = 'apexianlab-ai-assistant-full';
            }
            return $classes;
        }, 10);
    }

    private static function isAiAssistantScreen(): bool
    {
        if (!function_exists('is_page')) {
            return false;
        }
        $tpl = function_exists('get_page_template_slug') ? (string) get_page_template_slug() : '';
        if ($tpl === 'ai-assistant.php' || is_page('ai-assistant')) {
            return true;
        }

        // Pretty URL /cal/ai-assistant/{uuid}/ — matched via rewrite rule, not page template.
        if (function_exists('get_query_var') && (string) get_query_var('ai_chat_id') !== '') {
            return true;
        }

        return false;
    }

    private function registerAssets(): void
    {
        add_action('wp_enqueue_scripts', static function (): void {
            // AI assistant route shares the `cal` page (template=meeting-booking.php) but
            // serves the ai-assistant template via template_include — must check first.
            if (self::isAiAssistantScreen()) {
                self::enqueueAiAssistantAssets();
                return;
            }

            $template = get_page_template_slug();

            if ($template === 'meeting-booking.php') {
                self::enqueueBookingAssets();
                return;
            }
            if ($template === 'create-schedule.php') {
                self::enqueueScheduleAssets();
                return;
            }
        }, 20);
    }

    private static function enqueueBookingAssets(): void
    {
        $base = APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/';
        $ver = self::assetVersion();

        $bookingModules = ['state', 'api', 'utils', 'calendar', 'frames', 'form', 'slots'];
        $importMap = ['imports' => []];
        foreach ($bookingModules as $mod) {
            $importMap['imports']['apexianlab-booking/' . $mod] = $base . 'js/booking/' . $mod . '.js?ver=' . rawurlencode($ver);
        }
        $importMap['imports']['apexianlab-shared/timezones'] = $base . 'js/shared/timezones.js?ver=' . rawurlencode($ver);
        $importMapJson = (string) wp_json_encode($importMap);

        wp_enqueue_script('apexianlab_ms_jstz', 'https://cdn.jsdelivr.net/npm/jstz@2/dist/jstz.min.js', [], '2.1.1', true);
        wp_enqueue_script('apexianlab_ms_booking', $base . 'js/booking/main.js', [], $ver, ['strategy' => 'defer', 'in_footer' => true]);
        wp_enqueue_style('apexianlab_ms_booking_vars', $base . 'css/booking/variables.css', [], $ver);
        wp_enqueue_style('apexianlab_ms_booking_global', $base . 'css/booking/global.css', ['apexianlab_ms_booking_vars'], $ver);
        wp_enqueue_style('apexianlab_ms_booking_calendar', $base . 'css/booking/calendar.css', ['apexianlab_ms_booking_global'], $ver);
        wp_enqueue_style('apexianlab_ms_booking_ant_select', $base . 'css/booking/ant-select.css', [], $ver);
        wp_enqueue_style('apexianlab_ms_booking_slots', $base . 'css/booking/slots.css', ['apexianlab_ms_booking_global', 'apexianlab_ms_booking_ant_select'], $ver);
        wp_enqueue_style('apexianlab_ms_booking_frames', $base . 'css/booking/frames.css', ['apexianlab_ms_booking_global'], $ver);
        wp_enqueue_style('apexianlab_ms_booking_form', $base . 'css/booking/form.css', ['apexianlab_ms_booking_global'], $ver);
        wp_enqueue_style('apexianlab_ms_fonts_inter', 'https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap', []);
        wp_enqueue_style('apexianlab_ms_shared_modals', $base . 'css/shared/modals.css', [], $ver);
        wp_enqueue_script('apexianlab_ms_user_menu', $base . 'js/shared/user-menu.js', [], $ver, ['strategy' => 'defer', 'in_footer' => true]);

        add_filter('script_loader_tag', static function (string $tag, string $handle) use ($importMapJson): string {
            if ($handle === 'apexianlab_ms_booking') {
                $tag = preg_replace('/\stype=["\'][^"\']*["\']/', '', $tag, 1);
                $tag = str_replace('<script ', '<script type="module" ', $tag);
                $tag = '<script type="importmap">' . $importMapJson . '</script>' . "\n" . $tag;
            }
            return $tag;
        }, 10, 2);

        $savedTz = self::resolveSavedBookingTimezone();

        wp_localize_script('apexianlab_ms_booking', 'calendar_vars', [
            'ajax_url'                   => admin_url('admin-ajax.php'),
            'calendar_nonce'             => wp_create_nonce('calendar_nonce_action'),
            'saved_booking_timezone'     => $savedTz,
            'schedule_booking_short_id'  => sanitize_text_field((string) get_query_var('calendar_booking_short_id')),
        ]);
        wp_localize_script('apexianlab_ms_booking', 'apexianlab_oauth2_vars', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'logout_nonce' => wp_create_nonce('oauth2_logout'),
        ]);
    }

    /**
     * Determine the timezone to preload into the booking page's TZ picker.
     * Priority: existing booking row (when the URL targets a specific meeting
     * — covers reschedule / view-details / cancel) > session preference >
     * empty (let the client detect from Intl/jstz).
     */
    private static function resolveSavedBookingTimezone(): string
    {
        $bookingTz = self::timezoneFromBookingIdInUrl();
        if ($bookingTz !== '') {
            return $bookingTz;
        }
        return function_exists('apexianlab_get_session')
            ? (string) apexianlab_get_session('apexianlab_booking_timezone', '')
            : '';
    }

    private static function timezoneFromBookingIdInUrl(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only TZ hint on a public page; no state change.
        $meetingId = isset($_GET['meeting_id']) ? sanitize_text_field(wp_unslash((string) $_GET['meeting_id'])) : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $meetingId)) {
            return '';
        }
        try {
            $pdo         = Connection::getInstance()->pdo();
            $bookingRepo = new BookingRepository($pdo);
            $row         = $bookingRepo->findByIdAnyState($meetingId);
        } catch (\Throwable $e) {
            return '';
        }
        if (!is_array($row)) {
            return '';
        }

        // When the organiser opens the page (either from their email link or
        // while authenticated via OAuth), the calendar must render in the
        // organiser's timezone, not the attendee's saved booking timezone.
        // Exception: if the URL explicitly says for=attendee (attendee-facing
        // email link), always use the attendee's timezone even if the viewer
        // happens to have an active OAuth session in the same browser.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only TZ hint on a public page; no state change.
        $forHint   = isset($_GET['for']) ? strtolower(sanitize_text_field(wp_unslash((string) $_GET['for']))) : '';
        $isOAuth   = false;
        try {
            $isOAuth = GoogleOAuthHandler::getInstance()->isAuthenticated();
        } catch (\Throwable $e) {
            $isOAuth = false;
        }
        if ($forHint !== 'attendee' && ($forHint === 'organiser' || $isOAuth) && !empty($row['calendar_schedule_id'])) {
            try {
                $schedule = (new ScheduleRepository($pdo))->findById((string) $row['calendar_schedule_id']);
            } catch (\Throwable $e) {
                $schedule = null;
            }
            if (is_array($schedule)) {
                $organiserTz = \Apexianlab\Calendar\Helper\TimeHelper::organiserTimezoneFromSchedule($schedule);
                if ($organiserTz !== '') {
                    return $organiserTz;
                }
            }
        }

        if (empty($row['timezone'])) {
            return '';
        }
        return trim((string) $row['timezone']);
    }

    private static function assetVersion(): string
    {
        $ver = APEXIANLAB_MEETING_SCHEDULER_VERSION;
        $isLocal = (defined('WP_DEBUG') && WP_DEBUG)
            || (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local');
        if ($isLocal) {
            $ver .= '.' . time();
        }
        return $ver;
    }

    private static function enqueueScheduleAssets(): void
    {
        $base = APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/';
        $ver = self::assetVersion();

        $scheduleModules = ['state', 'api', 'fullcalendar-adapter', 'sidebar', 'schedule-modal', 'userSettings'];
        $scheduleImportMap = ['imports' => []];
        foreach ($scheduleModules as $mod) {
            $scheduleImportMap['imports']['apexianlab-schedule/' . $mod] = $base . 'js/schedule/' . $mod . '.js?ver=' . rawurlencode($ver);
        }
        $scheduleImportMap['imports']['apexianlab-shared/timezones'] = $base . 'js/shared/timezones.js?ver=' . rawurlencode($ver);
        $scheduleImportMapJson = (string) wp_json_encode($scheduleImportMap);

        wp_enqueue_script('apexianlab_ms_jstz', 'https://cdn.jsdelivr.net/npm/jstz@2/dist/jstz.min.js', [], '2.1.1', true);
        wp_enqueue_script('apexianlab_ms_fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js', [], '6.1.15', true);
        wp_enqueue_script('apexianlab_ms_schedule', $base . 'js/schedule/main.js', ['apexianlab_ms_fullcalendar'], $ver, ['strategy' => 'defer', 'in_footer' => true]);
        wp_enqueue_style('apexianlab_ms_schedule_vars', $base . 'css/schedule/variables.css', [], $ver);
        wp_enqueue_style('apexianlab_ms_schedule_global', $base . 'css/schedule/global.css', ['apexianlab_ms_schedule_vars'], $ver);
        wp_enqueue_style('apexianlab_ms_schedule_calendar', $base . 'css/schedule/calendar.css', ['apexianlab_ms_schedule_global'], $ver);
        wp_enqueue_style('apexianlab_ms_schedule_sidebar', $base . 'css/schedule/sidebar.css', ['apexianlab_ms_schedule_global'], $ver);
        wp_enqueue_style('apexianlab_ms_schedule_modals', $base . 'css/shared/modals.css', ['apexianlab_ms_schedule_global'], $ver);
        wp_enqueue_style('apexianlab_ms_fonts_inter', 'https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap', []);
        wp_enqueue_script('apexianlab_ms_user_menu', $base . 'js/shared/user-menu.js', [], $ver, ['strategy' => 'defer', 'in_footer' => true]);

        add_filter('script_loader_tag', static function (string $tag, string $handle) use ($scheduleImportMapJson): string {
            if ($handle === 'apexianlab_ms_schedule') {
                $tag = preg_replace('/\stype=["\'][^"\']*["\']/', '', $tag, 1);
                $tag = str_replace('<script ', '<script type="module" ', $tag);
                $tag = '<script type="importmap">' . $scheduleImportMapJson . '</script>' . $tag;
            }
            return $tag;
        }, 10, 2);

        wp_localize_script('apexianlab_ms_schedule', 'apexianlab_oauth2_vars', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'logout_nonce' => wp_create_nonce('oauth2_logout'),
        ]);

        $calendarMeans = [];
        try {
            $conn = Connection::getInstance();
            $meansRepo = new MeansOfCommunicationRepository($conn->pdo());
            $calendarMeans = $meansRepo->getAll();
        } catch (\Throwable $e) {
            // ignore
        }

        $ownerEmail = '';
        try {
            $scheduleOauth = GoogleOAuthHandler::getInstance();
            if ($scheduleOauth->isAuthenticated()) {
                $ownerUi    = (array) ($scheduleOauth->getUserInfo() ?? []);
                $ownerEmail = (string) ($ownerUi['email'] ?? $ownerUi['preferred_username'] ?? '');
            }
        } catch (\Throwable $e) {
            // ignore — owner email is best-effort; backend still de-dupes.
        }

        wp_localize_script('apexianlab_ms_schedule', 'schedule_calendar_vars', [
            'ajax_url'                 => admin_url('admin-ajax.php'),
            'home_url'                 => home_url('/'),
            'owner_email'              => $ownerEmail,
            'schedule_events_nonce'    => wp_create_nonce('schedule_events_nonce'),
            'create_schedule_nonce'    => wp_create_nonce('create_schedule_nonce'),
            'schedule_manage_nonce'    => wp_create_nonce('schedule_manage_nonce'),
            'google_calendar_nonce'    => wp_create_nonce('google_calendar_nonce'),
            'calendar_nonce'           => wp_create_nonce('calendar_nonce_action'),
            'calendar_means'           => $calendarMeans,
            /* translators: %d is the number of weeks between schedule repetitions. */
            'i18n_repeat_every_weeks'  => __('Repeat every %d weeks', 'apexianlab'),
            'i18n_copied'              => __('Copied!', 'apexianlab'),
            'i18n_link_copied_toast'   => __('Link successfully copied to clipboard', 'apexianlab'),
            'i18n_copy_failed'         => __('Could not copy link', 'apexianlab'),
        ]);
    }

    private static function enqueueAiAssistantAssets(): void
    {
        $base = APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/';
        $ver  = self::assetVersion();

        $aiCurrentUser = ['email' => '', 'firstName' => '', 'lastName' => ''];
        try {
            $oauth = GoogleOAuthHandler::getInstance();
            if ($oauth->isAuthenticated()) {
                $ui = (array) ($oauth->getUserInfo() ?? []);
                $aiCurrentUser['email']     = (string) ($ui['email'] ?? $ui['preferred_username'] ?? '');
                $aiCurrentUser['firstName'] = (string) ($ui['given_name'] ?? '');
                $aiCurrentUser['lastName']  = (string) ($ui['family_name'] ?? '');
                if (($aiCurrentUser['firstName'] === '' || $aiCurrentUser['lastName'] === '') && !empty($ui['name'])) {
                    $parts = preg_split('/\s+/', trim((string) $ui['name'])) ?: [];
                    if ($aiCurrentUser['firstName'] === '' && isset($parts[0])) {
                        $aiCurrentUser['firstName'] = (string) $parts[0];
                    }
                    if ($aiCurrentUser['lastName'] === '' && count($parts) > 1) {
                        $aiCurrentUser['lastName'] = (string) implode(' ', array_slice($parts, 1));
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore, fallback to empty values.
        }


        $absSlots = APEXIANLAB_MEETING_SCHEDULER_PATH . 'assets/js/booking-availability-slots.js';
        $verSlots = file_exists($absSlots) ? (string) filemtime($absSlots) : $ver;
        wp_enqueue_script('apexianlab_ms_availability_slots', $base . 'js/booking-availability-slots.js', [], $verSlots, true);

        $absLlm = APEXIANLAB_MEETING_SCHEDULER_PATH . 'assets/js/ai-assistant/llm.js';
        $verLlm = file_exists($absLlm) ? (string) filemtime($absLlm) : $ver;
        wp_enqueue_script('apexianlab_ms_ai_llm', $base . 'js/ai-assistant/llm.js', ['jquery'], $verLlm, true);

        $absActions = APEXIANLAB_MEETING_SCHEDULER_PATH . 'assets/js/ai-assistant/actions.js';
        $verActions = file_exists($absActions) ? (string) filemtime($absActions) : $ver;
        wp_enqueue_script('apexianlab_ms_ai_actions', $base . 'js/ai-assistant/actions.js', ['jquery', 'apexianlab_ms_ai_llm', 'apexianlab_ms_availability_slots'], $verActions, true);

        wp_localize_script('apexianlab_ms_ai_llm', 'raaAiLlm', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('apexianlab_ai_llm_action'),
            'action'  => 'apexianlab_ai_llm_chat',
        ]);

        wp_localize_script('apexianlab_ms_ai_actions', 'raaAiAssistant', [
            'ajaxUrl'               => admin_url('admin-ajax.php'),
            'homeUrl'               => home_url('/'),
            'calendarNonce'         => wp_create_nonce('calendar_nonce_action'),
            'scheduleEventsNonce'   => wp_create_nonce('schedule_events_nonce'),
            'assistantStepNonce'    => wp_create_nonce('apexianlab_ai_llm_action'),
            'actionGetScheduleEvents' => 'get_schedule_events',
            'actionChatStep'        => 'apexianlab_ai_assistant_chat_step_agent',
            'actionChatHistory'     => 'apexianlab_ai_assistant_chat_history',
            'actionTranscribe'      => 'apexianlab_ai_assistant_transcribe',
            'sttEnabled'            => \Apexianlab\Calendar\Config::isSttConfigured(),
            'actionGetAvailableRanges' => 'get_available_ranges',
            'currentUser'           => $aiCurrentUser,
            'defaultDuration'       => 30,
            'horizonDays'           => 14,
            'iconUrl'               => APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/images/ai-assistant/icons/ai-icon-animated.svg',
            'i18n'                  => [],
        ]);

        wp_enqueue_style('apexianlab_ms_booking_vars', $base . 'css/booking/variables.css', [], $ver);
        wp_enqueue_style('apexianlab_ms_booking_global', $base . 'css/booking/global.css', ['apexianlab_ms_booking_vars'], $ver);
        wp_enqueue_style('apexianlab_ms_booking_frames', $base . 'css/booking/frames.css', ['apexianlab_ms_booking_global'], $ver);
        wp_enqueue_style('apexianlab_ms_ai_assistant', $base . 'css/ai-assistant/ai-assistant.css', ['apexianlab_ms_booking_global', 'apexianlab_ms_booking_frames'], $ver);
        wp_enqueue_style('apexianlab_ms_fonts_inter', 'https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&display=swap', []);
        wp_enqueue_style('apexianlab_ms_shared_modals', $base . 'css/shared/modals.css', [], $ver);
        wp_enqueue_script('apexianlab_ms_user_menu', $base . 'js/shared/user-menu.js', [], $ver, ['strategy' => 'defer', 'in_footer' => true]);

        wp_localize_script('apexianlab_ms_user_menu', 'apexianlab_oauth2_vars', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'logout_nonce' => wp_create_nonce('oauth2_logout'),
        ]);
    }

    private function maybeFlushRewriteRules(): void
    {
        if (get_option('apexianlab_meeting_scheduler_rewrite_version') === APEXIANLAB_MEETING_SCHEDULER_VERSION) {
            return;
        }
        add_action('init', static function (): void {
            flush_rewrite_rules(false);
            update_option('apexianlab_meeting_scheduler_rewrite_version', APEXIANLAB_MEETING_SCHEDULER_VERSION, false);
        }, 100);
    }

    private function maybeStartSession(): void
    {
        if (PHP_SESSION_ACTIVE !== session_status()) {
            @session_start(); // phpcs:ignore
        }
    }

    private function registerMailFilters(): void
    {
        // No global From override: every booking/schedule email sets its own
        // From header (schedule owner's mailbox + name_format display name) in
        // NotificationService::buildFromHeader().

        // Route wp_mail() through an SMTP server when SMTP_HOST is defined in
        // wp-config.php. Without this, WordPress may shell out to sendmail,
        // which is often missing in containers / minimal hosts.
        // Only register the hook when the host is actually reachable — the same
        // constant can exist in production pointing at a local-dev SMTP that
        // is not available there.
        if (defined('SMTP_HOST') && SMTP_HOST !== '') {
            $smtpHost = (string) SMTP_HOST;
            $smtpPort = defined('SMTP_PORT') ? (int) SMTP_PORT : 25;
            $socket   = @fsockopen($smtpHost, $smtpPort, $errNo, $errStr, 2);
            if ($socket !== false) {
                fclose($socket);
                add_action('phpmailer_init', static function ($phpmailer): void {
                    $phpmailer->isSMTP();
                    $phpmailer->Host       = (string) SMTP_HOST;
                    $phpmailer->Port       = defined('SMTP_PORT') ? (int) SMTP_PORT : 25;
                    $phpmailer->SMTPAuth   = defined('SMTP_PASS') && SMTP_PASS !== '';
                    if ($phpmailer->SMTPAuth) {
                        $phpmailer->Username = defined('SMTP_EMAIL') ? (string) SMTP_EMAIL : '';
                        $phpmailer->Password = (string) SMTP_PASS;
                    }
                    $phpmailer->SMTPSecure = defined('SMTP_SECURE') ? (string) SMTP_SECURE : '';
                });
            }
        }

    }

    public static function captchaUrl(string $key): string
    {
        return ImageCaptcha::url($key);
    }

    public static function onActivation(): void
    {
        Installer::ensurePages();

        // Google OAuth callback keeps an explicit rewrite rule; booking +
        // ai-assistant URLs are routed by SlugRouter on `parse_request`.
        add_rewrite_rule('^google-calendar/callback$', 'index.php?google_calendar_callback=1', 'top');
        flush_rewrite_rules(false);
    }

    public static function onDeactivation(): void
    {
        wp_clear_scheduled_hook(BookingService::CRON_GCAL_SYNC);
        flush_rewrite_rules(false);
    }
}
