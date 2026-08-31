<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\AiAssistant;

use DateTimeZone;
use Apexianlab\Calendar\Ajax\AbstractAjaxHandler;
use Apexianlab\Calendar\Auth\GoogleOAuthHandler;
use Apexianlab\Calendar\Config;
use Apexianlab\Calendar\Service\AvailabilityService;
use Apexianlab\Calendar\Service\BookingService;
use Apexianlab\Calendar\Service\IntegrationService;
use Apexianlab\Calendar\Service\NotificationService;

/**
 * AJAX entry points for the AI booking assistant.
 *
 * This handler does only three things:
 *   1. Build the per-turn $context (calendar, schedule, authenticated user).
 *   2. Hand the turn off to {@see ConversationFlow}.
 *   3. Persist the result + send the JSON response.
 *
 * All conversation logic lives in ConversationFlow / SlotExtractor /
 * BackendOperations / MessageTemplates.
 */
final class AiAjaxHandler extends AbstractAjaxHandler
{
    private AiChatRepository $aiChatRepo;
    private AvailabilityService $availabilityService;
    private BookingService $bookingService;
    private NotificationService $notificationService;

    public static function register(): self
    {
        $instance = new self();
        $instance->registerHooks();
        return $instance;
    }

    protected function __construct()
    {
        parent::__construct();

        $this->aiChatRepo          = new AiChatRepository($this->pdo);
        $this->availabilityService = new AvailabilityService($this->scheduleRepo, $this->bookingRepo);
        $this->notificationService = new NotificationService($this->bookingRepo, $this->scheduleRepo, $this->meansRepo);
        $this->bookingService      = new BookingService($this->bookingRepo, $this->scheduleRepo);
        $this->bookingService->setIntegrationService(new IntegrationService($this->bookingRepo, $this->scheduleRepo, $this->meansRepo));
        $this->bookingService->setNotificationService($this->notificationService);
    }

    protected function actions(): array
    {
        return [
            'apexianlab_ai_assistant_create_chat'     => 'createAiChat',
            'apexianlab_ai_assistant_chat_step'       => 'assistantChatStepAgent',
            'apexianlab_ai_assistant_chat_step_agent' => 'assistantChatStepAgent',
            'apexianlab_ai_assistant_chat_history'    => 'assistantChatHistory',
            'apexianlab_ai_assistant_transcribe'      => 'transcribeAudio',
            'apexianlab_ai_assistant_has_meetings'    => 'hasMeetings',
        ];
    }

    // ─── Chat creation ───────────────────────────────────────────────────

    public function createAiChat(): void
    {
        $this->verifyNonce('nonce', 'calendar_nonce_action');

        $schedule      = $this->requireScheduleByShortId();
        $scheduleId    = (string) $schedule['id'];
        $scheduleEmail = (string) ($schedule['email'] ?? '');

        $sessionToken = AiChatRepository::generateSessionToken();
        $chatId       = $this->aiChatRepo->create(null, $scheduleId, $scheduleEmail, $sessionToken['hash']);
        if (!is_string($chatId) || $chatId === '') {
            wp_send_json_error(['message' => 'Could not create AI chat session.']);
        }

        apexianlab_ai_set_chat_cookie($chatId, $sessionToken['token']);

        wp_send_json_success([
            'chat_id'  => $chatId,
            'chat_url' => \Apexianlab\Calendar\Helper\DisplayHelper::aiAssistantUrl($schedule, $chatId),
        ]);
    }

    // ─── Main turn handler ───────────────────────────────────────────────

    public function assistantChatStepAgent(): void
    {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ignore_user_abort may be disabled by the host; the @ keeps a long LLM turn from emitting a warning.
        @ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit may be disabled by the host; the @ keeps a long LLM turn from emitting a warning.
            @set_time_limit(240);
        }

        $this->verifyNonce('nonce', 'apexianlab_ai_llm_action');

        if (!Config::isLlmConfigured()) {
            wp_send_json_error(['message' => 'LLM is not configured']);
        }

        $chatId         = $this->post('chat_id');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by verifyNonce() above.
        $userMessage    = isset($_POST['user_message']) ? trim(sanitize_textarea_field(wp_unslash((string) $_POST['user_message']))) : '';
        $clientTimezone = $this->post('client_timezone');
        $clientLocale   = $this->post('client_locale');

        if ($chatId === '' || $userMessage === '') {
            wp_send_json_error(['message' => 'Missing required fields.']);
        }

        $chatId = $this->validateUuid($chatId, 'chat_id');

        if (!$this->aiChatRepo->exists($chatId)) {
            wp_send_json_error(['message' => 'Invalid chat session. Reopen AI assistant from booking page.']);
        }
        if (!apexianlab_ai_verify_chat_cookie($chatId, $this->aiChatRepo)) {
            wp_send_json_error(['message' => 'Session expired. Please reopen the chat.', 'code' => 'session_expired']);
        }

        $chat          = $this->aiChatRepo->getById($chatId);
        $calendarId    = is_array($chat) ? (string) ($chat['calendar_schedule_id'] ?? '') : '';
        $calendarEmail = is_array($chat) ? (string) ($chat['calendar_email'] ?? '') : '';
        if ($calendarId === '' || $calendarEmail === '') {
            wp_send_json_error(['message' => 'Chat context is incomplete.']);
        }

        $stateRow             = $this->aiChatRepo->getState($chatId);
        $activeConversationId = is_array($stateRow) ? (string) ($stateRow['active_conversation_id'] ?? '') : '';
        if ($activeConversationId === '') {
            $activeConversationId = $this->aiChatRepo->generateUuid();
        }

        $context = is_array($stateRow) ? (array) ($stateRow['context'] ?? []) : [];
        $this->buildContext($context, $calendarId, $calendarEmail, $clientTimezone, $clientLocale);

        $context['user_language'] = (new ReplyLanguageResolver())->resolve(
            $userMessage,
            (string) ($context['user_language'] ?? ''),
            (string) ($context['locale'] ?? '')
        );

        $llm  = new AiLlmClient(Config::getLlmBaseUrl(), Config::getLlmModel(), Config::getLlmApiKey());
        $flow = new ConversationFlow(
            new SlotExtractor($llm),
            new BackendOperations(
                $this->availabilityService,
                $this->bookingService,
                $this->notificationService,
                $this->bookingRepo,
                $this->scheduleRepo
            ),
            new Translator($llm)
        );

        try {
            $result = $flow->handle($userMessage, $context);
        } catch (\Throwable $e) {
            $this->aiChatRepo->addActionLog($chatId, 'flow', 'error', ['message' => $userMessage], [], $e->getMessage());
            error_log('[ConversationFlow] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            wp_send_json_error(['message' => $this->llmUnavailableMessage()]);
        }

        $assistantMessage = (string) ($result['assistant_message'] ?? '');
        $uiActions        = $flow->computeUiActions($context);

        // Persist every LLM call this turn (slot_extract + any translate)
        // to ai_chat_action_log so we can replay what the model said.
        foreach ($flow->llmCalls as $call) {
            $this->aiChatRepo->addActionLog(
                $chatId,
                (string) ($call['name'] ?? 'llm'),
                'ok',
                is_array($call['request']  ?? null) ? $call['request']  : [],
                is_array($call['response'] ?? null) ? $call['response'] : [],
                null
            );
        }

        if (!empty($result['executed'])) {
            $actionResult = is_array($result['action_result'] ?? null) ? $result['action_result'] : [];
            $this->aiChatRepo->addActionLog(
                $chatId,
                'flow_execute',
                !empty($actionResult['ok']) ? 'ok' : 'error',
                [],
                $actionResult,
                !empty($actionResult['ok']) ? null : (string) ($actionResult['message'] ?? 'failed')
            );
        }

        $this->aiChatRepo->upsertState($chatId, 'active', null, $context, $activeConversationId);
        $this->aiChatRepo->addMessage($chatId, $activeConversationId, 'user', $userMessage, [], [], []);
        $this->aiChatRepo->addMessage($chatId, $activeConversationId, 'assistant', $assistantMessage, [], [], $uiActions);

        wp_send_json_success([
            'assistant_message'      => $assistantMessage,
            'assistant_message_meta' => [],
            'ui_actions'             => $uiActions,
            'conversation'           => ['id' => $activeConversationId, 'routing' => 'continue'],
            'state'                  => ['state' => 'active', 'pending_action' => null, 'context' => $context, 'is_final_step' => false],
            'action_result'          => null,
            'llm_model'              => Config::getLlmModel(),
        ]);
    }

    // ─── Has-meetings check (used to disable Cancel/Reschedule chips) ────

    /**
     * Returns whether the current user has any upcoming meetings on this
     * calendar. Only meaningful for authenticated users; for guests without
     * a known email we return null so the frontend keeps buttons enabled.
     *
     * Response: { has_meetings: bool|null }
     */
    public function hasMeetings(): void
    {
        $this->verifyNonce('nonce', 'apexianlab_ai_llm_action');

        $chatId = $this->post('chat_id');
        if ($chatId === '') {
            wp_send_json_success(['has_meetings' => null]);
        }

        $chatId   = $this->validateUuid($chatId, 'chat_id');
        $stateRow = $this->aiChatRepo->getState($chatId);
        $context  = is_array($stateRow) ? (array) ($stateRow['context'] ?? []) : [];

        // Re-check current OAuth session so a logged-out user gets null (buttons
        // stay enabled) rather than being evaluated against the old DB context.
        [$currentEmail] = $this->resolveAuthenticatedUser();
        if ($currentEmail !== '') {
            $email = $currentEmail;
        } else {
            // Not currently authenticated — use stored guest email if any,
            // but if neither is available return null to keep buttons enabled.
            $email = trim((string) ($context['guest_email'] ?? ''));
        }
        $calId = trim((string) ($context['calendar_id'] ?? ''));

        if ($email === '' || $calId === '') {
            // Unknown identity — can't check; tell JS to keep buttons enabled.
            wp_send_json_success(['has_meetings' => null]);
        }

        // Rebuild context enough for listUpcomingMeetings.
        $context['calendar_id'] = $calId;
        $backend  = new BackendOperations(
            $this->availabilityService,
            $this->bookingService,
            $this->notificationService,
            $this->bookingRepo,
            $this->scheduleRepo
        );

        $meetings = $backend->listUpcomingMeetings($context, $email);
        wp_send_json_success(['has_meetings' => !empty($meetings)]);
    }

    // ─── History ─────────────────────────────────────────────────────────

    public function assistantChatHistory(): void
    {
        $this->verifyNonce('nonce', 'apexianlab_ai_llm_action');

        $chatId = $this->post('chat_id');
        if ($chatId === '') {
            wp_send_json_error(['message' => 'Missing required fields.']);
        }
        $chatId = $this->validateUuid($chatId, 'chat_id');
        if (!$this->aiChatRepo->exists($chatId)) {
            wp_send_json_error(['message' => 'Invalid chat session.']);
        }

        $stateRow             = $this->aiChatRepo->getState($chatId);
        $activeConversationId = is_array($stateRow) ? (string) ($stateRow['active_conversation_id'] ?? '') : '';
        $messages             = $this->aiChatRepo->getRecentMessagesUi($chatId, 200, null);

        wp_send_json_success([
            'messages'     => $messages,
            'conversation' => ['id' => $activeConversationId],
        ]);
    }

    // ─── Voice transcription ─────────────────────────────────────────────

    public function transcribeAudio(): void
    {
        $this->verifyNonce('nonce', 'apexianlab_ai_llm_action');

        if (!Config::isSttConfigured()) {
            $this->sendJsonError(['message' => 'Speech-to-text is not configured']);
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES upload metadata for a nonce-verified handler (verifyNonce above): error/size are int-cast, tmp_name is validated via is_uploaded_file(), type is matched against a hardcoded MIME whitelist below.
        if (!isset($_FILES['audio']) || !is_array($_FILES['audio']) || (int) ($_FILES['audio']['error'] ?? 4) !== UPLOAD_ERR_OK) {
            $this->sendJsonError(['message' => 'No audio file received']);
        }

        $tmpFile = sanitize_text_field(wp_unslash((string) ($_FILES['audio']['tmp_name'] ?? '')));
        $size    = (int) ($_FILES['audio']['size'] ?? 0);
        if ($tmpFile === '' || !is_uploaded_file($tmpFile) || $size < 100) {
            $this->sendJsonError(['message' => 'Invalid audio upload']);
        }
        if ($size > 25 * 1024 * 1024) {
            $this->sendJsonError(['message' => 'Audio file too large (max 25 MB)']);
        }

        $mimeType = sanitize_text_field(wp_unslash((string) ($_FILES['audio']['type'] ?? 'audio/webm')));
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $mimeMap  = [
            'audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'mp4',
            'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-m4a' => 'm4a',
        ];
        $ext = $mimeMap[$mimeType] ?? 'webm';

        $fileContent = file_get_contents($tmpFile);
        if ($fileContent === false) {
            $this->sendJsonError(['message' => 'Cannot read uploaded file']);
        }

        $boundary = wp_generate_password(24, false);
        $body  = "--{$boundary}\r\nContent-Disposition: form-data; name=\"model\"\r\n\r\n" . Config::getSttModel() . "\r\n";
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"response_format\"\r\n\r\njson\r\n";
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"voice.{$ext}\"\r\nContent-Type: {$mimeType}\r\n\r\n";
        $body .= $fileContent . "\r\n--{$boundary}--\r\n";

        $headers = ['Content-Type' => 'multipart/form-data; boundary=' . $boundary];
        $apiKey  = Config::getLlmApiKey();
        if ($apiKey !== null && $apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $response = wp_remote_post(Config::getSttBaseUrl() . '/audio/transcriptions', [
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 60,
        ]);
        if (is_wp_error($response)) {
            $this->sendJsonError(['message' => 'Transcription service unavailable: ' . $response->get_error_message()]);
        }

        $code    = (int) wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $decoded = json_decode($rawBody, true);

        if ($code < 200 || $code >= 300) {
            $errMsg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $decoded['error'] ?? $rawBody) : $rawBody;
            $this->sendJsonError(['message' => 'Transcription failed: ' . $errMsg]);
        }

        $transcript = is_array($decoded) ? trim((string) ($decoded['text'] ?? '')) : '';
        if ($transcript === '') {
            $this->sendJsonError(['message' => 'Could not transcribe audio — no speech detected']);
        }

        $this->sendJsonSuccess(['transcript' => $transcript]);
    }

    // ─── Context construction ────────────────────────────────────────────

    /**
     * Hydrate $context with everything the conversation flow expects:
     *   calendar_id, calendar_email, timezone, locale, last_user_message,
     *   is_public, meeting_type, meeting_type_label,
     *   user_is_authenticated, user_email, user_display_name.
     */
    private function buildContext(array &$context, string $calendarId, string $calendarEmail, string $clientTimezone, string $clientLocale): void
    {
        $context['calendar_id']    = $context['calendar_id']    ?? $calendarId;
        $context['calendar_email'] = $context['calendar_email'] ?? $calendarEmail;

        if ($clientTimezone !== '' && $this->isValidTimezone($clientTimezone)) {
            $context['timezone'] = $this->normalizeTimezoneIdentifier(trim($clientTimezone));
        }
        if ($clientLocale !== '') {
            $context['locale'] = substr($clientLocale, 0, 10);
        }

        // Schedule-derived values are stable per chat — cache after first load.
        if (!isset($context['is_public']) || !isset($context['require_email_verification'])
            || !isset($context['meeting_type']) || !isset($context['allowed_durations'])
        ) {
            $schedule = $this->scheduleRepo->findById($calendarId);
            $context['is_public'] = is_array($schedule)
                && ($schedule['is_public'] === true || $schedule['is_public'] === 't');
            $context['require_email_verification'] = is_array($schedule)
                && (($schedule['require_email_verification'] ?? false) === true
                    || ($schedule['require_email_verification'] ?? false) === 't');
            if (is_array($schedule)) {
                $meansId = (string) ($schedule['calendar_means_of_communication_id'] ?? '');
                $means   = $meansId !== '' ? $this->meansRepo->findById($meansId) : null;
                $title   = is_array($means) ? trim((string) ($means['title'] ?? '')) : '';
                $context['meeting_type']       = self::normalizeMeetingType($title);
                $context['meeting_type_label'] = $title;
                $context['allowed_durations'] = self::parseScheduleDurations($schedule['durations'] ?? null);
            }
        }

        // Authenticated booker (re-checked every turn so session changes apply).
        $context['user_is_authenticated'] = false;
        unset($context['user_display_name'], $context['user_email']);

        [$authEmail, $authName] = $this->resolveAuthenticatedUser();
        if ($authEmail !== '') {
            $context['user_is_authenticated'] = true;
            $context['user_email']            = $authEmail;
            if ($authName !== '') {
                $context['user_display_name'] = $authName;
            }
        }
    }

    /**
     * @return array{0:string,1:string}  [email, fullName]
     */
    private function resolveAuthenticatedUser(): array
    {
        // OAuth2 is checked first: schedule guests are often not WordPress
        // users — they sign in via Google OAuth only.
        if (class_exists(GoogleOAuthHandler::class)) {
            try {
                $oauth = GoogleOAuthHandler::getInstance();
                if ($oauth->isAuthenticated()) {
                    $info  = (array) ($oauth->getUserInfo() ?? []);
                    $email = trim((string) ($info['email'] ?? $info['preferred_username'] ?? ''));
                    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $given  = trim((string) ($info['given_name'] ?? ''));
                        $family = trim((string) ($info['family_name'] ?? ''));
                        $full   = trim($given . ' ' . $family);
                        if ($full === '') {
                            $full = trim((string) ($info['name'] ?? ''));
                        }
                        return [$email, $full];
                    }
                }
            } catch (\Throwable $e) {
                error_log('[AiAjaxHandler] OAuth2 lookup failed: ' . $e->getMessage());
            }
        }

        if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('wp_get_current_user')) {
            $u = wp_get_current_user();
            if ($u && !empty($u->user_email) && filter_var((string) $u->user_email, FILTER_VALIDATE_EMAIL)) {
                $first = trim((string) ($u->first_name ?? ''));
                $last  = trim((string) ($u->last_name ?? ''));
                $full  = trim($first . ' ' . $last);
                if ($full === '') {
                    $full = trim((string) ($u->display_name ?? ''));
                }
                return [(string) $u->user_email, $full];
            }
        }

        return ['', ''];
    }

    // ─── Small helpers ───────────────────────────────────────────────────

    /**
     * Map a "means-of-communication" title to a stable internal key.
     * Normalize defensively in case an integrator renames a row.
     */
    private static function normalizeMeetingType(string $title): string
    {
        $key = strtolower(trim($title));
        if ($key === '') {
            return 'unknown';
        }
        if (str_contains($key, 'google')) {
            return 'google_calendar';
        }
        if (str_contains($key, 'phone') || str_contains($key, 'call')) {
            return 'phone_call';
        }
        if (str_contains($key, 'person') || str_contains($key, 'office') || str_contains($key, 'in-person')) {
            return 'in_person';
        }
        return 'unknown';
    }

    /**
     * @param mixed $raw
     * @return array<int, int>
     */
    private static function parseScheduleDurations($raw): array
    {
        $default = [15, 30, 45, 60, 90];
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded)) {
            return $default;
        }
        $clean = [];
        foreach ($decoded as $v) {
            $n = (int) $v;
            if ($n >= 1 && $n <= 1440) {
                $clean[$n] = true;
            }
        }
        if ($clean === []) {
            return $default;
        }
        $list = array_keys($clean);
        sort($list, SORT_NUMERIC);
        return $list;
    }

    private function isValidTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($this->normalizeTimezoneIdentifier($timezone));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function normalizeTimezoneIdentifier(string $timezone): string
    {
        if (strcasecmp($timezone, 'Europe/Kiev') === 0) {
            return 'Europe/Kyiv';
        }
        return $timezone;
    }

    private function llmUnavailableMessage(): string
    {
        return 'AI assistant is temporarily unavailable. Please try again in a minute.';
    }
}
