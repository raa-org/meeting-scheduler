<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Template name: AI Assistant (Apexianlab)
 *
 * @package APEXIANLAB_Meeting_Scheduler
 */

declare(strict_types=1);

use Apexianlab\Calendar\Auth\GoogleOAuthHandler;
use Apexianlab\Calendar\Database\Connection;
use Apexianlab\Calendar\Helper\DisplayHelper;
use Apexianlab\Calendar\AiAssistant\AiChatRepository;
use Apexianlab\Calendar\Repository\ScheduleRepository;

$connection   = Connection::getInstance();
$pdo          = $connection->pdo();
$aiChatRepo   = new AiChatRepository($pdo);
$scheduleRepo = new ScheduleRepository($pdo);

$chat_id = (string) get_query_var('ai_chat_id');
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing params (chat id / one-time token) for a public AI-assistant page; no state mutation.
if ($chat_id === '') {
    $chat_id = isset($_GET['chat_id']) ? sanitize_text_field(wp_unslash($_GET['chat_id'])) : '';
}
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$calendar_id    = '';
$calendar_email = '';
$schedule       = null;

if ($chat_id !== '') {
    $chat = $aiChatRepo->getById($chat_id);
    if (is_array($chat)) {
        $calendar_id    = (string) ($chat['calendar_schedule_id'] ?? '');
        $calendar_email = (string) ($chat['calendar_email'] ?? '');
    }
    if ($calendar_id !== '') {
        $schedule = $scheduleRepo->findById($calendar_id);
    }
}

if ($chat_id === '' || !is_array($schedule) || $schedule === []) {
    status_header(404);
    nocache_headers();
    require get_404_template();
    exit;
}

// Set cookie from URL token parameter (initial redirect from ai_start=1),
// then redirect to the clean slug URL without the token query parameter.
// phpcs:ignore -- read-only one-time token param on a public AI page.
if (!empty($_GET['token'])) {
    $token = sanitize_text_field(wp_unslash($_GET['token']));
    apexianlab_ai_set_chat_cookie($chat_id, $token);
    $clean_ai_url = DisplayHelper::aiAssistantUrl($schedule, $chat_id);
    wp_safe_redirect($clean_ai_url !== '' ? $clean_ai_url : home_url('/'));
    exit;
}

if (!apexianlab_ai_verify_chat_cookie($chat_id, $aiChatRepo)) {
    $fallback = $calendar_id !== '' ? \Apexianlab\Calendar\Helper\DisplayHelper::bookingUrl($schedule) : home_url('/');
    wp_safe_redirect($fallback);
    exit;
}

$is_public = !empty($schedule) && ($schedule['is_public'] === true || $schedule['is_public'] === 't');
if (!$is_public) {
    $googleAuth = GoogleOAuthHandler::getInstance();
    $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')) . sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
    if (function_exists('apexianlab_set_session')) {
        apexianlab_set_session('oauth2_redirect_after_auth', $currentUrl);
    }
    $googleAuth->requireAuth();
}

$booking_back_url = DisplayHelper::bookingUrl($schedule);

$organiser_display = DisplayHelper::scheduleOrganiserName($schedule);
if ($organiser_display === '') {
    $organiser_display = trim((string) ($schedule['email'] ?? ''));
}

$ai_icon_yellow_url = APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/images/ai-assistant/icons/ai-icon-animated.svg';
$ai_arrow_left_url  = APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/images/ai-assistant/icons/arrow-left.svg';
$ai_mic_icon_url    = APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/images/ai-assistant/icons/microphone.svg';
$ai_send_icon_url   = APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/images/ai-assistant/icons/arrow-up.svg';

$chip_icons = [
    'calendar'   => APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/images/ai-assistant/icons/calendar.svg',
    'reschedule' => APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/images/ai-assistant/icons/reschedule.svg',
    'cancel'     => APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/images/ai-assistant/icons/cancel.svg',
];

// ── Detect browser locale from Accept-Language header ───────────
$browser_locale = '';
if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && is_string($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $al = strtolower(trim(explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])))[0]));
    $al = explode(';', $al)[0];
    $browser_locale = trim($al);
}
$is_english = ($browser_locale === '' || str_starts_with($browser_locale, 'en'));

$ui_strings = [
    'greeting'          => __("Hi! I'm your AI assistant. I can help you schedule meetings, find the best time, and reschedule or cancel events. What would you like to do?", 'apexianlab-meeting-scheduler'),
    'find_earliest'     => __('Find earliest slot', 'apexianlab-meeting-scheduler'),
    'reschedule'        => __('Reschedule', 'apexianlab-meeting-scheduler'),
    'cancel_meeting'    => __('Cancel a meeting', 'apexianlab-meeting-scheduler'),
    'ask_placeholder'   => __('Ask something…', 'apexianlab-meeting-scheduler'),
    /* translators: %s is the meeting organiser's name. */
    'meeting_with'      => __('Meeting with %s', 'apexianlab-meeting-scheduler'),
];

if (!$is_english && \Apexianlab\Calendar\Config::isLlmConfigured()) {
    $transient_key = 'apexianlab_ai_ui_l10n_v2_' . md5($browser_locale);
    $cached = get_transient($transient_key);
    if (is_array($cached) && count($cached) === count($ui_strings)) {
        $ui_strings = array_combine(array_keys($ui_strings), $cached);
    } else {
        try {
            $client = new \Apexianlab\Calendar\AiAssistant\AiLlmClient(
                \Apexianlab\Calendar\Config::getLlmBaseUrl(),
                \Apexianlab\Calendar\Config::getLlmModel(),
                \Apexianlab\Calendar\Config::getLlmApiKey()
            );
            $payload = wp_json_encode(array_values($ui_strings), JSON_UNESCAPED_UNICODE);
            $prompt  = "Translate ALL of the following UI texts COMPLETELY to locale \"{$browser_locale}\". "
                     . "Every word must be translated — do NOT leave any English words mixed in. "
                     . "Keep the placeholder %s as-is (it will be replaced with a name). "
                     . "Keep translations short, natural, and idiomatic. "
                     . "Return ONLY a JSON array of translated strings in the same order.\n"
                     . "Input: {$payload}";
            $result = $client->chat(
                [['role' => 'system', 'content' => $prompt]],
                ['temperature' => 0.1, 'max_tokens' => 400]
            );
            $translated = json_decode((string) ($result['content'] ?? ''), true);
            if (!is_array($translated)) {
                $raw = trim((string) ($result['content'] ?? ''));
                if (preg_match('/```(?:json)?\s*(\[[\s\S]*?\])\s*```/i', $raw, $m)) {
                    $translated = json_decode((string) $m[1], true);
                }
            }
            if (is_array($translated) && count($translated) === count($ui_strings)) {
                set_transient($transient_key, $translated, DAY_IN_SECONDS);
                $ui_strings = array_combine(array_keys($ui_strings), $translated);
            }
        } catch (\Throwable $e) {
            // keep English defaults
        }
    }
}

$assistant_chips = [
    ['action' => 'find-earliest-slot', 'label' => $ui_strings['find_earliest'],  'icon' => 'calendar'],
    ['action' => 'reschedule',         'label' => $ui_strings['reschedule'],      'icon' => 'reschedule'],
    ['action' => 'cancel',             'label' => $ui_strings['cancel_meeting'],  'icon' => 'cancel', 'modifier' => 'danger'],
];

// Server-side fallback for when JavaScript is disabled. Note: `wp_date()`
// already converts a UTC timestamp to the site's timezone — passing
// `current_time('timestamp')` (which is already shifted) causes a double
// offset. Use a raw UTC timestamp instead.
$message_time = wp_date('g:i a');

require APEXIANLAB_MEETING_SCHEDULER_PATH . 'templates/partials/header-timegrid.php';

?>
<div class="ai-assistant"<?php echo $chat_id !== '' ? ' data-ai-chat-id="' . esc_attr($chat_id) . '"' : ''; ?>>
    <div class="ai-assistant__shell">
        <?php if ($booking_back_url !== '' || $organiser_display !== '') : ?>
            <div class="ai-assistant__context">
                <div class="ai-assistant__context-heading">
                    <?php if ($booking_back_url !== '') : ?>
                        <a class="ai-assistant__context-back" href="<?php echo esc_url($booking_back_url); ?>" aria-label="<?php esc_attr_e('Back to calendar', 'apexianlab-meeting-scheduler'); ?>">
                            <img src="<?php echo esc_url($ai_arrow_left_url); ?>" alt="" width="24" height="24" decoding="async" />
                        </a>
                    <?php endif; ?>
                    <?php if ($organiser_display !== '') : ?>
                        <h1 class="ai-assistant__context-title"><?php printf(esc_html($ui_strings['meeting_with']), esc_html($organiser_display)); ?></h1>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="ai-assistant__messages" role="log" aria-live="polite" aria-label="<?php esc_attr_e('Chat messages', 'apexianlab-meeting-scheduler'); ?>">
            <div class="ai-assistant__row">
                <div class="ai-assistant__avatar" aria-hidden="true">
                    <img src="<?php echo esc_url($ai_icon_yellow_url); ?>" alt="" width="32" height="32" decoding="async" />
                </div>
                <div class="ai-assistant__bubble">
                    <p class="ai-assistant__bubble-text">
                        <?php echo esc_html($ui_strings['greeting']); ?>
                    </p>
                    <time class="ai-assistant__bubble-time" data-ai-initial-time="true" datetime="<?php echo esc_attr(wp_date('c')); ?>">
                        <?php echo esc_html($message_time); ?>
                    </time>
                </div>
            </div>
        </div>

        <div class="ai-assistant__dock">
            <div class="ai-assistant__chips" role="group" aria-label="<?php esc_attr_e('Quick actions', 'apexianlab-meeting-scheduler'); ?>">
                <?php foreach ($assistant_chips as $chip) : ?>
                    <?php
                    $chip_classes  = 'ai-assistant__chip';
                    if (($chip['modifier'] ?? '') === 'danger') {
                        $chip_classes .= ' ai-assistant__chip--danger';
                    }
                    $ikey          = isset($chip['icon']) ? (string) $chip['icon'] : '';
                    $chip_icon_url = ($ikey !== '' && isset($chip_icons[$ikey])) ? (string) $chip_icons[$ikey] : '';
                    ?>
                    <button type="button" class="<?php echo esc_attr($chip_classes); ?>" data-ai-assistant-action="<?php echo esc_attr((string) $chip['action']); ?>">
                        <?php if ($chip_icon_url !== '') : ?>
                            <span class="ai-assistant__chip-icon">
                                <img src="<?php echo esc_url($chip_icon_url); ?>" alt="" width="20" height="20" decoding="async" />
                            </span>
                        <?php endif; ?>
                        <span><?php echo esc_html((string) $chip['label']); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

        <div class="ai-assistant__composer">
            <div class="ai-assistant__field" data-ai-recording="false">
                <label class="screen-reader-text" for="ai-assistant-input"><?php esc_html_e('Message', 'apexianlab-meeting-scheduler'); ?></label>
                <div class="ai-assistant__field-stack">
                    <div class="ai-assistant__field-text">
                        <textarea id="ai-assistant-input" class="ai-assistant__input" name="ai_assistant_message" rows="1" autocomplete="off" placeholder="<?php echo esc_attr($ui_strings['ask_placeholder']); ?>"></textarea>
                    </div>
                    <div class="ai-assistant__record" aria-hidden="true">
                        <button type="button" class="ai-assistant__record-cancel" aria-label="<?php esc_attr_e('Cancel recording', 'apexianlab-meeting-scheduler'); ?>">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <canvas class="ai-assistant__record-wave" width="480" height="40"></canvas>
                        <span class="ai-assistant__record-time" aria-live="polite">0:00</span>
                    </div>
                    <div class="ai-assistant__field-controls">
                        <button type="button" class="ai-assistant__mic" aria-label="<?php esc_attr_e('Voice input', 'apexianlab-meeting-scheduler'); ?>" aria-pressed="false">
                            <img src="<?php echo esc_url($ai_mic_icon_url); ?>" alt="" width="22" height="22" decoding="async" />
                        </button>
                        <button type="button" class="ai-assistant__send" aria-label="<?php esc_attr_e('Send', 'apexianlab-meeting-scheduler'); ?>">
                            <img src="<?php echo esc_url($ai_send_icon_url); ?>" alt="" width="20" height="20" decoding="async" />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
require APEXIANLAB_MEETING_SCHEDULER_PATH . 'templates/partials/footer-timegrid.php';
