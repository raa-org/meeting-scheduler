<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar;

use Apexianlab\Calendar\Database\Connection;
use Apexianlab\Calendar\Helper\DisplayHelper;
use Apexianlab\Calendar\Repository\BookingRepository;
use Apexianlab\Calendar\Repository\ScheduleRepository;
use Apexianlab\Calendar\Service\BookingService;

final class MeetingBookingTemplate
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', static function (): void {
            // Slug URLs (/{slug}/, /{slug}/{hash}, /{slug}/ai-assistant/{uuid}/)
            // are routed by SlugRouter on the `parse_request` hook, not by
            // rewrite rules — a bare top-level segment cannot be matched by a
            // rewrite rule without shadowing real theme pages. This flush only
            // rebuilds WP defaults, dropping the removed legacy /cal/ rules.
            if (get_option('apexianlab_meeting_scheduler_booking_rewrite_version') !== '11') {
                flush_rewrite_rules(false);
                update_option('apexianlab_meeting_scheduler_booking_rewrite_version', '11', false);
            }
        });

        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'calendar_booking_short_id';
            $vars[] = 'calendar_id';
            $vars[] = 'apexianlab_booking_display_cancelled';
            $vars[] = 'apexianlab_calendar_unavailable';
            $vars[] = 'ai_chat_id';
            $vars[] = 'apexianlab_owner_slug';
            $vars[] = 'apexianlab_route';
            return $vars;
        });

        add_action('template_redirect', [$this, 'handleBookingRedirect']);
    }

    public function handleBookingRedirect(): void
    {
        if (!is_page('cal')) {
            return;
        }

        // AI assistant route — handled by template_include, skip booking redirect logic.
        if ((string) get_query_var('ai_chat_id') !== '') {
            return;
        }

        $shortId = $this->resolveShortId();
        if ($shortId === '') {
            set_query_var('apexianlab_calendar_unavailable', '1');
            return;
        }

        $conn = Connection::getInstance();
        $pdo = $conn->pdo();
        $scheduleRepo = new ScheduleRepository($pdo);
        $bookingRepo = new BookingRepository($pdo);
        $bookingService = new BookingService($bookingRepo, $scheduleRepo);

        $schedule = $scheduleRepo->findBy(['booking_short_id' => $shortId]);
        if (!is_array($schedule) || empty($schedule['id'])) {
            set_query_var('apexianlab_calendar_unavailable', '1');
            return;
        }

        // The slug in the URL must belong to this schedule's owner.
        $ownerSlug = (string) get_query_var('apexianlab_owner_slug');
        if ($ownerSlug !== '') {
            $expected = (new \Apexianlab\Calendar\Repository\SlugRepository($pdo))
                ->findByEmail((string) ($schedule['email'] ?? ''));
            if ($expected === null || $expected['slug'] !== $ownerSlug) {
                self::trigger404();
                return;
            }
        }

        $calendarId = (string) $schedule['id'];
        set_query_var('calendar_id', $calendarId);
        set_query_var('calendar_booking_short_id', $shortId);
        set_query_var('apexianlab_booking_display_cancelled', '0');

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing params for a public booking page (template_redirect hook); no state mutation here.
        $meetingId = !empty($_GET['meeting_id']) ? sanitize_text_field(wp_unslash($_GET['meeting_id'])) : '';
        $nonce     = !empty($_GET['check']) ? sanitize_text_field(wp_unslash($_GET['check'])) : '';
        $frame     = !empty($_GET['frame']) ? sanitize_text_field(wp_unslash($_GET['frame'])) : '';
        $ai_start  = !empty($_GET['ai_start']) ? sanitize_text_field(wp_unslash($_GET['ai_start'])) : '';
        $token     = !empty($_GET['t']) ? sanitize_text_field(wp_unslash($_GET['t'])) : '';
        $cancelTok = !empty($_GET['cancel_token']) ? sanitize_text_field(wp_unslash($_GET['cancel_token'])) : '';

        if ($meetingId === '' && !empty($_GET['c'])) {
            $shortToken = sanitize_text_field(wp_unslash($_GET['c']));
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            $bookingByToken = $bookingRepo->findByConfirmationToken($shortToken);
            if (is_array($bookingByToken) && (string) ($bookingByToken['calendar_schedule_id'] ?? '') === $calendarId) {
                wp_safe_redirect(DisplayHelper::bookingUrl($schedule, [
                    'meeting_id' => (string) $bookingByToken['id'],
                    'check'      => $shortToken,
                    'frame'      => 'confirm',
                ]));
                exit;
            }
        }

        $baseUrl = DisplayHelper::bookingUrl($schedule);

        // Access gate: `meeting_id` requires HMAC `t`, `check` nonce, or a
        // valid `cancel_token`; bare UUID redirects out.
        if ($meetingId !== '') {
            $tokenOk  = $token !== '' && DisplayHelper::isMeetingAccessToken($meetingId, $token);
            $checkOk  = $nonce !== '' && $bookingService->isNonceValid($meetingId, $nonce);
            $cancelOk = $cancelTok !== '' && $bookingService->isCancelTokenValid($meetingId, $cancelTok);

            if (!$tokenOk && !$checkOk && !$cancelOk) {
                wp_safe_redirect($baseUrl);
                exit;
            }
        }

        if ($frame === 'confirmed' && $meetingId !== '') {
            $bookingAny = $bookingRepo->findByIdAnyState($meetingId);
            if (!is_array($bookingAny) || ($bookingAny['id'] ?? '') === '' || (string) ($bookingAny['calendar_schedule_id'] ?? '') !== $calendarId) {
                wp_safe_redirect($baseUrl);
                exit;
            }
            if (!empty($bookingAny['deleted_at'])) {
                set_query_var('apexianlab_booking_display_cancelled', '1');
            }
        }

        if ($frame === '5' && $meetingId !== '') {
            $bookingAny = $bookingRepo->findByIdAnyState($meetingId);
            if (!is_array($bookingAny) || ($bookingAny['id'] ?? '') === '' || (string) ($bookingAny['calendar_schedule_id'] ?? '') !== $calendarId) {
                wp_safe_redirect($baseUrl);
                exit;
            }
            // Re-opening a cancel link for an already-cancelled meeting: load
            // the soft-deleted row so frame-5 can render the "already
            // cancelled" state instead of the cancel-confirmation prompt.
            if (!empty($bookingAny['deleted_at'])) {
                set_query_var('apexianlab_booking_display_cancelled', '1');
            }
        }

        if ($ai_start === '1') {
            try {
                $scheduleEmail = (string) ($schedule['email'] ?? '');
                $aiChatRepo = new \Apexianlab\Calendar\AiAssistant\AiChatRepository($pdo);
                $sessionToken = \Apexianlab\Calendar\AiAssistant\AiChatRepository::generateSessionToken();
                $ai_chat_id = $aiChatRepo->create(null, $calendarId, $scheduleEmail, $sessionToken['hash']);
                if (is_string($ai_chat_id) && $ai_chat_id !== '') {
                    // Pass token via URL so it can be set as cookie on the target page
                    $aiUrl = \Apexianlab\Calendar\Helper\DisplayHelper::aiAssistantUrl($schedule, $ai_chat_id);
                    if ($aiUrl !== '') {
                        wp_safe_redirect(add_query_arg('token', $sessionToken['token'], $aiUrl));
                        exit;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[Apexianlab AI] ai_start create chat failed: ' . $e->getMessage());
            }
        }

        if ($frame === 'confirm' && $meetingId !== '') {
            $bookingRow = $bookingRepo->findById($meetingId);
            if (empty($bookingRow) || (string) ($bookingRow['calendar_schedule_id'] ?? '') !== $calendarId) {
                wp_safe_redirect($baseUrl);
                exit;
            }
        }

        if ($meetingId !== '' && $nonce !== '') {
            $isConfirmed      = $bookingService->isConfirmed($meetingId);
            $isValidExpiration = $bookingService->isExpirationValid($meetingId);
            $isValidNonce     = $bookingService->isNonceValid($meetingId, $nonce);

            if ($isConfirmed) {
                if ($frame !== 'confirmed') {
                    wp_safe_redirect(DisplayHelper::bookingUrl($schedule, ['meeting_id' => $meetingId, 'frame' => 'confirmed']));
                    exit;
                }
            } elseif ($isValidExpiration && $isValidNonce) {
                if ($frame !== 'confirm') {
                    wp_safe_redirect(DisplayHelper::bookingUrl($schedule, ['meeting_id' => $meetingId, 'check' => $nonce, 'frame' => 'confirm']));
                    exit;
                }
            } elseif (!$isValidExpiration) {
                if ($frame !== 'confirm') {
                    wp_safe_redirect(DisplayHelper::bookingUrl($schedule, ['meeting_id' => $meetingId, 'frame' => 'confirm']));
                    exit;
                }
            } else {
                wp_safe_redirect($baseUrl);
                exit;
            }
        }
    }

    private function resolveShortId(): string
    {
        $shortId = (string) get_query_var('calendar_booking_short_id');

        if ($shortId !== '' && preg_match('/^[0-9]+$/', $shortId) && strlen($shortId) < 6) {
            $shortId = str_pad($shortId, 6, '0', STR_PAD_LEFT);
        }

        return $shortId;
    }

    /**
     * Turn the current request into a clean WordPress 404.
     */
    private static function trigger404(): void
    {
        global $wp_query;
        if ($wp_query instanceof \WP_Query) {
            $wp_query->set_404();
        }
        status_header(404);
        nocache_headers();
    }
}
