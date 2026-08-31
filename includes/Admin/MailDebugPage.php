<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Admin;

use Apexianlab\Calendar\Auth\GoogleOAuthHandler;
use Apexianlab\Calendar\Database\Connection;
use Apexianlab\Calendar\Service\Mail\MailDebugLog;

/**
 * Tools -> Mail Debug. Surfaces the mail pipeline's state for admins without
 * shell access: environment, stored Google grants, a live Gmail API probe and
 * the ring-buffer log written by the mailer.
 */
final class MailDebugPage
{
    private const SLUG = 'apexianlab-mail-debug';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addPage']);
    }

    public static function addPage(): void
    {
        add_management_page(
            'Apexianlab Mail Debug',
            'Mail Debug',
            'manage_options',
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $probeResult = null;
        $action = isset($_POST['apexianlab_action']) ? sanitize_text_field(wp_unslash($_POST['apexianlab_action'])) : '';

        if ($action !== '') {
            check_admin_referer(self::SLUG);
            if ($action === 'clear') {
                MailDebugLog::clear();
            } elseif ($action === 'probe') {
                $probeResult = self::runProbe(
                    sanitize_email(wp_unslash($_POST['owner_email'] ?? '')),
                    sanitize_email(wp_unslash($_POST['test_to'] ?? ''))
                );
            }
        }

        echo '<div class="wrap"><h1>Apexianlab Mail Debug</h1>';

        self::renderEnvironment();
        self::renderCredentials();
        self::renderProbeForm($probeResult);
        self::renderLog();

        echo '</div>';
    }

    private static function renderEnvironment(): void
    {
        echo '<h2>Environment</h2><table class="widefat striped" style="max-width:900px"><tbody>';

        foreach (['mbstring', 'curl', 'openssl', 'pdo_pgsql'] as $ext) {
            self::row($ext, extension_loaded($ext) ? 'loaded' : 'MISSING');
        }
        self::row('PHP', PHP_VERSION . ' (' . PHP_SAPI . ')');

        foreach (['GOOGLE_CALENDAR_SCOPES', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_EMAIL', 'SMTP_SECURE', 'DISABLE_WP_CRON'] as $const) {
            self::row($const, defined($const) ? var_export(constant($const), true) : '(not defined)');
        }
        self::row('SMTP_PASS', defined('SMTP_PASS') && SMTP_PASS !== '' ? '(set)' : '(not set)');

        $next = wp_next_scheduled('apexianlab_gcal_sync');
        self::row(
            'Next apexianlab_gcal_sync',
            $next ? gmdate('Y-m-d H:i:s', $next) . ' UTC' : 'not scheduled'
        );

        echo '</tbody></table>';
    }

    private static function renderCredentials(): void
    {
        echo '<h2>Stored Google grants</h2>';

        try {
            $pdo = Connection::getInstance()->pdo();
            $rows = $pdo->query(
                'SELECT schedule_email, google_email, granted_scopes, token_expires_at
                 FROM calendar_google_credentials ORDER BY schedule_email'
            )->fetchAll();
        } catch (\Throwable $e) {
            echo '<p><strong>DB error:</strong> ' . esc_html($e->getMessage()) . '</p>';
            return;
        }

        if ($rows === []) {
            echo '<p>No credential rows — nobody connected Google yet.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>'
            . '<th>schedule_email</th><th>granted_scopes</th><th>gmail.send</th><th>token_expires_at</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $scopes = (string) ($row['granted_scopes'] ?? '');
            $hasSend = $scopes === ''
                ? 'unknown (column empty)'
                : (str_contains($scopes, 'gmail.send') ? 'yes' : 'NO');

            echo '<tr><td>' . esc_html((string) $row['schedule_email']) . '</td>'
                . '<td style="word-break:break-all">' . esc_html($scopes !== '' ? $scopes : '(null)') . '</td>'
                . '<td>' . esc_html($hasSend) . '</td>'
                . '<td>' . esc_html((string) ($row['token_expires_at'] ?? '')) . '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /** @param array<string, string>|null $result */
    private static function renderProbeForm(?array $result): void
    {
        echo '<h2>Live Gmail API probe</h2>';
        echo '<form method="post">';
        wp_nonce_field(self::SLUG);
        echo '<input type="hidden" name="apexianlab_action" value="probe">';
        echo '<p><label>Schedule owner email<br><input type="email" name="owner_email" class="regular-text" required></label></p>';
        echo '<p><label>Send test message to<br><input type="email" name="test_to" class="regular-text" required></label></p>';
        submit_button('Run probe (sends one email)');
        echo '</form>';

        if ($result === null) {
            return;
        }

        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        foreach ($result as $key => $value) {
            self::row($key, $value);
        }
        echo '</tbody></table>';
    }

    /** @return array<string, string> */
    private static function runProbe(string $ownerEmail, string $testTo): array
    {
        $out = [];

        if ($ownerEmail === '' || $testTo === '') {
            return ['error' => 'Both addresses are required.'];
        }

        try {
            $handler = GoogleOAuthHandler::getInstance();
            $token   = $handler->getAccessTokenForScheduleEmail($ownerEmail);
        } catch (\Throwable $e) {
            return [
                'token' => 'EXCEPTION: ' . get_class($e) . ' — ' . $e->getMessage(),
                'where' => $e->getFile() . ':' . $e->getLine(),
                'hint'  => 'Decryption failures mean the stored tokens were encrypted under a different plugin name or auth salt.',
            ];
        }

        if ($token === null || $token === '') {
            return ['token' => 'NULL — no credentials row for this address, or the refresh call failed.'];
        }
        $out['token'] = 'obtained';

        $info = wp_remote_get('https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode($token));
        $out['tokeninfo'] = is_wp_error($info)
            ? 'WP_Error: ' . $info->get_error_message()
            : (string) wp_remote_retrieve_body($info);

        try {
            $missing = $handler->getMissingScopesForEmail($ownerEmail);
            $out['missing_scopes'] = $missing === [] ? '(none reported)' : implode(', ', $missing);
        } catch (\Throwable $e) {
            $out['missing_scopes'] = 'EXCEPTION: ' . $e->getMessage();
        }

        $raw = "From: {$ownerEmail}\r\nTo: {$testTo}\r\nSubject: Apexianlab mail probe\r\n"
             . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nprobe\r\n";

        $response = wp_remote_post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')]),
        ]);

        if (is_wp_error($response)) {
            $out['gmail_api'] = 'WP_Error: ' . $response->get_error_message();
            return $out;
        }

        $out['gmail_api']  = 'HTTP ' . wp_remote_retrieve_response_code($response);
        $out['gmail_body'] = (string) wp_remote_retrieve_body($response);

        return $out;
    }

    private static function renderLog(): void
    {
        $entries = MailDebugLog::entries();

        echo '<h2>Mail pipeline log (' . count($entries) . ')</h2>';

        echo '<form method="post" style="margin-bottom:12px">';
        wp_nonce_field(self::SLUG);
        echo '<input type="hidden" name="apexianlab_action" value="clear">';
        submit_button('Clear log', 'delete', 'submit', false);
        echo '</form>';

        if ($entries === []) {
            echo '<p>Empty. Book a meeting, wait for the cron tick, then reload.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th style="width:170px">UTC</th><th>Message</th></tr></thead><tbody>';
        foreach (array_reverse($entries) as $entry) {
            echo '<tr><td>' . esc_html((string) ($entry['time'] ?? '')) . '</td>'
                . '<td style="word-break:break-all">' . esc_html((string) ($entry['message'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function row(string $label, string $value): void
    {
        echo '<tr><td style="width:260px"><strong>' . esc_html($label) . '</strong></td>'
            . '<td style="word-break:break-all">' . esc_html($value) . '</td></tr>';
    }
}
