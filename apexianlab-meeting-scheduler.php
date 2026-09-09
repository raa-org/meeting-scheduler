<?php
/**
 * Plugin Name: Apexianlab — Meeting Scheduler
 * Plugin URI:  https://github.com/raa-org/meeting-scheduler
 * Description: Self-hosted meeting scheduling with Google Calendar and an optional AI assistant.
 * Version:     1.2
 * Author:      Right&Above, LLC
 * Author URI:  https://rightandabove.com/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: apexianlab-meeting-scheduler
 *
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('APEXIANLAB_MEETING_SCHEDULER_VERSION', '1.2');
define('APEXIANLAB_MEETING_SCHEDULER_PATH', plugin_dir_path(__FILE__));
define('APEXIANLAB_MEETING_SCHEDULER_URL', plugin_dir_url(__FILE__));

require_once APEXIANLAB_MEETING_SCHEDULER_PATH . 'includes/Autoloader.php';
\Apexianlab\Calendar\Autoloader::register();

add_action('after_setup_theme', static function (): void {
    if (!function_exists('apexianlab_get_session')) {
        function apexianlab_get_session($key, $default = null)
        {
            if (PHP_SESSION_ACTIVE !== session_status()) {
                @session_start(); // phpcs:ignore
            }
            return $_SESSION[$key] ?? $default;
        }
    }

    if (!function_exists('apexianlab_set_session')) {
        function apexianlab_set_session($key, $value): void
        {
            if (PHP_SESSION_ACTIVE !== session_status()) {
                @session_start(); // phpcs:ignore
            }
            $_SESSION[$key] = $value;
        }
    }

    if (!function_exists('apexianlab_ai_set_chat_cookie')) {
        function apexianlab_ai_set_chat_cookie(string $chatId, string $plainToken): void
        {
            $cookieValue = $chatId . ':' . $plainToken;
            $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            error_log('[Apexianlab AI Cookie] Setting cookie. chatId=' . $chatId . ', secure=' . ($secure ? 'true' : 'false'));
            setcookie('apexianlab_ai_chat_token', $cookieValue, [
                'expires'  => 0,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    if (!function_exists('apexianlab_ai_verify_chat_cookie')) {
        function apexianlab_ai_verify_chat_cookie(string $chatId, \Apexianlab\Calendar\AiAssistant\AiChatRepository $repo): bool
        {
            $cookieVal = isset($_COOKIE['apexianlab_ai_chat_token']) ? (string) $_COOKIE['apexianlab_ai_chat_token'] : '';
            if ($cookieVal === '') {
                error_log('[Apexianlab AI Cookie] No cookie found. chatId=' . $chatId);
                return false;
            }
            $parts = explode(':', $cookieVal, 2);
            if (count($parts) !== 2) {
                error_log('[Apexianlab AI Cookie] Invalid cookie format. cookieVal=' . $cookieVal);
                return false;
            }
            if ($parts[0] !== $chatId) {
                error_log('[Apexianlab AI Cookie] chatId mismatch. cookie=' . $parts[0] . ', expected=' . $chatId);
                return false;
            }
            $verified = $repo->verifySessionToken($chatId, $parts[1]);
            error_log('[Apexianlab AI Cookie] Token verification: ' . ($verified ? 'OK' : 'FAILED') . ' for chatId=' . $chatId);
            return $verified;
        }
    }
}, 1);

\Apexianlab\Calendar\Plugin::instance();
