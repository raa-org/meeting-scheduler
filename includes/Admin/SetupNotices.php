<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Admin;

use Apexianlab\Calendar\Config;

/**
 * Admin notices when required wp-config constants are missing.
 */
final class SetupNotices
{
    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && isset($screen->id) && $screen->id === 'update') {
            return;
        }

        if (!Config::isCalendarEnabled()) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__(
                'Apexianlab Meeting Scheduler is inactive: define POSTGRESQL_HOST, POSTGRESQL_DBNAME, POSTGRESQL_USER, and POSTGRESQL_PASSWORD in wp-config.php.',
                'apexianlab-meeting-scheduler'
            );
            echo '</p></div>';

            return;
        }

        if (!Config::isGoogleOAuthConfigured()) {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__(
                'Apexianlab Meeting Scheduler: define GOOGLE_CALENDAR_CLIENT_ID and GOOGLE_CALENDAR_CLIENT_SECRET in wp-config.php (Google Cloud OAuth client with Calendar API enabled).',
                'apexianlab-meeting-scheduler'
            );
            echo '</p></div>';
        }
    }
}
