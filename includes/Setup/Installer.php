<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Setup;

/**
 * Creates the WordPress pages the plugin routes expect.
 *
 * SlugRouter / ScheduleAdminRouter / MeetingBookingTemplate key off
 * is_page('cal'|'schedule') and page templates — without these pages
 * the plugin cannot serve booking or admin UI.
 */
final class Installer
{
    private const OPTION_VERSION = 'apexianlab_meeting_scheduler_pages_version';

    /** Bump when required page set or templates change. */
    private const PAGES_VERSION = '1';

    /**
     * @return list<array{slug:string,title:string,template:string}>
     */
    private static function requiredPages(): array
    {
        return [
            [
                'slug'     => 'cal',
                'title'    => 'Meeting Booking',
                'template' => 'meeting-booking.php',
            ],
            [
                'slug'     => 'schedule',
                'title'    => 'Create Schedule',
                'template' => 'create-schedule.php',
            ],
            [
                'slug'     => 'ai-assistant',
                'title'    => 'AI Assistant',
                'template' => 'ai-assistant.php',
            ],
        ];
    }

    public static function ensurePages(): void
    {
        if (get_option(self::OPTION_VERSION) === self::PAGES_VERSION) {
            return;
        }

        foreach (self::requiredPages() as $page) {
            self::ensurePage($page['slug'], $page['title'], $page['template']);
        }

        update_option(self::OPTION_VERSION, self::PAGES_VERSION, false);
    }

    private static function ensurePage(string $slug, string $title, string $template): void
    {
        $existing = get_page_by_path($slug);
        if ($existing instanceof \WP_Post) {
            $current = (string) get_post_meta($existing->ID, '_wp_page_template', true);
            if ($current !== $template) {
                update_post_meta($existing->ID, '_wp_page_template', $template);
            }
            if ($existing->post_status !== 'publish') {
                wp_update_post([
                    'ID'          => $existing->ID,
                    'post_status' => 'publish',
                ]);
            }

            return;
        }

        $pageId = wp_insert_post([
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '',
        ], true);

        if (is_wp_error($pageId) || !is_int($pageId) || $pageId <= 0) {
            return;
        }

        update_post_meta($pageId, '_wp_page_template', $template);
    }
}
