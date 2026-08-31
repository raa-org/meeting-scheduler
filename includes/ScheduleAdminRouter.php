<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar;

use Apexianlab\Calendar\Auth\GoogleOAuthHandler;
use Apexianlab\Calendar\Database\Connection;
use Apexianlab\Calendar\Helper\DisplayHelper;
use Apexianlab\Calendar\Repository\SlugRepository;

/**
 * Routes the owner admin panel:
 *   /{slug}/   -> the schedule-management page, owner-only
 *   /schedule/ -> 301 to the logged-in owner's /{slug}/
 */
final class ScheduleAdminRouter
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
        add_action('template_redirect', [$this, 'handleAdminRoute']);
    }

    public function handleAdminRoute(): void
    {
        if (!is_page('schedule')) {
            return;
        }

        // Wrong-domain logins bounced to login; anonymous handled by template.
        // SCHEDULE_ALLOW_ANY_ACCOUNT (demo) skips the domain check entirely.
        $gate = GoogleOAuthHandler::getInstance();
        if ($gate->isAuthenticated() && !$gate->allowsAnyAccount()) {
            $gateEmail = (string) (($gate->getUserInfo() ?? [])['email'] ?? '');
            if (!$gate->isAdminEmailAllowed($gateEmail)) {
                $notice = $gate->adminAccessNotice($gateEmail);
                $gate->clearSession();
                $gate->renderLoginGate($notice);
                exit;
            }
        }

        $ownerSlug = (string) get_query_var('apexianlab_owner_slug');

        // Bare /schedule/ — redirect to the logged-in owner's slug URL.
        if ($ownerSlug === '') {
            $oauth = GoogleOAuthHandler::getInstance();
            if (!$oauth->isAuthenticated()) {
                return; // create-schedule.php's requireAuth() drives login.
            }
            $email  = sanitize_email((string) (($oauth->getUserInfo() ?? [])['email'] ?? ''));
            $target = $email !== '' ? DisplayHelper::ownerAdminUrl($email) : '';
            if ($target !== '') {
                // 302, not 301 — the target depends on who is logged in, so
                // the redirect must never be cached as permanent.
                wp_safe_redirect($target);
                exit;
            }
            return;
        }

        // /{slug}/ — must be the owner.
        $pdo = Connection::getInstance()->pdo();
        $row = (new SlugRepository($pdo))->findBySlug($ownerSlug);
        if ($row === null) {
            $this->trigger404();
            return;
        }

        $oauth = GoogleOAuthHandler::getInstance();
        if (!$oauth->isAuthenticated()) {
            return; // create-schedule.php's requireAuth() drives login, then returns here.
        }

        $email = sanitize_email((string) (($oauth->getUserInfo() ?? [])['email'] ?? ''));
        if ($email === '' || strcasecmp($email, $row['email']) !== 0) {
            // A different logged-in user — send them to their own admin.
            $target = $email !== '' ? DisplayHelper::ownerAdminUrl($email) : '';
            wp_safe_redirect($target !== '' ? $target : home_url('/'));
            exit;
        }
        // Owner match — fall through; create-schedule.php renders.
    }

    private function trigger404(): void
    {
        global $wp_query;
        if ($wp_query instanceof \WP_Query) {
            $wp_query->set_404();
        }
        status_header(404);
        nocache_headers();
    }
}
