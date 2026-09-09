<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Ajax;

use Apexianlab\Calendar\Auth\GoogleOAuthHandler;
use Apexianlab\Calendar\Database\Connection;
use Apexianlab\Calendar\Helper\DisplayHelper;
use Apexianlab\Calendar\Repository\BookingRepository;
use Apexianlab\Calendar\Repository\CredentialsRepository;
use Apexianlab\Calendar\Repository\MeansOfCommunicationRepository;
use Apexianlab\Calendar\Repository\ScheduleRepository;

abstract class AbstractAjaxHandler
{
    protected \PDO $pdo;
    protected Connection $connection;
    protected ScheduleRepository $scheduleRepo;
    protected BookingRepository $bookingRepo;
    protected MeansOfCommunicationRepository $meansRepo;
    protected CredentialsRepository $credentialsRepo;

    protected function __construct()
    {
        $this->connection = Connection::getInstance();
        $this->pdo = $this->connection->pdo();
        $this->scheduleRepo = new ScheduleRepository($this->pdo);
        $this->bookingRepo = new BookingRepository($this->pdo);
        $this->meansRepo = new MeansOfCommunicationRepository($this->pdo);
        $this->credentialsRepo = new CredentialsRepository($this->pdo, $this->connection);
    }

    /**
     * Register wp_ajax_* and wp_ajax_nopriv_* hooks for each action.
     *
     * @return array<string, string> map of action => method
     */
    abstract protected function actions(): array;

    protected function registerHooks(): void
    {
        foreach ($this->actions() as $action => $method) {
            add_action('wp_ajax_' . $action, [$this, $method]);
            add_action('wp_ajax_nopriv_' . $action, [$this, $method]);
        }
    }

    protected function verifyNonce(string $field, string $action): void
    {
        if (!isset($_POST[$field]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$field])), $action)) {
            $this->sendJsonError(['message' => 'Invalid security']);
        }
    }

    /**
     * @return array{authenticated: bool, email: string, userInfo: array<string, mixed>}
     */
    protected function requireAuth(): array
    {
        $oauth = GoogleOAuthHandler::getInstance();
        if (!$oauth->isAuthenticated()) {
            $this->sendJsonError(['message' => 'Unauthorized'], 401);
        }
        $userInfo = (array) ($oauth->getUserInfo() ?? []);
        $email = sanitize_email((string) ($userInfo['email'] ?? ''));
        if ($email === '') {
            $this->sendJsonError(['message' => 'User email required']);
        }

        return ['authenticated' => true, 'email' => $email, 'userInfo' => $userInfo];
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireScheduleByShortId(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the concrete handler method before this helper is called.
        $shortId = sanitize_text_field(wp_unslash((string) ($_POST['booking_short_id'] ?? '')));
        if (!preg_match('/^[0-9A-Za-z]{4,11}$/', $shortId)) {
            $this->sendJsonError(['message' => 'Invalid schedule.']);
        }

        $schedule = $this->scheduleRepo->findBy(['booking_short_id' => $shortId]);
        if (!is_array($schedule) || empty($schedule['id'])) {
            $this->sendJsonError([
                'message' => 'This calendar is no longer available.',
                'code'    => 'schedule_unavailable',
            ]);
        }

        return $schedule;
    }

    protected function validateUuid(string $value, string $label = 'ID'): string
    {
        $value = sanitize_text_field($value);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            $this->sendJsonError(['message' => 'Invalid ' . $label . '.']);
        }
        return $value;
    }

    /**
     * Require valid per-meeting HMAC token (`t`) in POST.
     * Blocks booking actions via guessed UUIDs.
     */
    protected function requireMeetingAccess(string $meetingId): void
    {
        $token = $this->post('t');
        if (!DisplayHelper::isMeetingAccessToken($meetingId, $token)) {
            $this->sendJsonError(['message' => 'Unauthorized.'], 403);
        }
    }

    /**
     * @param array<string, mixed>|null $data
     */
    protected function sendJsonSuccess(?array $data = null): void
    {
        wp_send_json_success($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function sendJsonError(array $data, ?int $status_code = null): void
    {
        if ($status_code !== null) {
            wp_send_json_error($data, $status_code);

            return;
        }
        wp_send_json_error($data);
    }

    protected function post(string $key, string $default = ''): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- generic POST accessor; nonce is verified by the concrete handler before this is called.
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : $default;
    }

    protected function postRaw(string $key, string $default = ''): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- generic raw POST accessor; nonce is verified by the concrete handler, and callers validate the raw value themselves (UUID/date/regex checks).
        return isset($_POST[$key]) ? wp_unslash((string) $_POST[$key]) : $default;
    }

    protected function postInt(string $key, int $default = 0): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- generic POST accessor; nonce is verified by the concrete handler before this is called.
        return isset($_POST[$key]) ? (int) $_POST[$key] : $default;
    }
}
