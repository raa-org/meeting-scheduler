<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar;

use Apexianlab\Calendar\Database\Connection;
use Apexianlab\Calendar\Repository\SlugRepository;

/**
 * Routes per-owner slug URLs without rewrite rules.
 *
 * A bare top-level segment (/{slug}/) cannot be claimed by a rewrite rule
 * without shadowing real theme pages — WordPress's built-in page rule is a
 * greedy catch-all. Instead this hooks `parse_request`: it inspects the raw
 * path, and only when the first segment is a known slug does it override the
 * query vars. Unknown segments are left untouched, so real pages and the
 * normal 404 path are unaffected.
 *
 *   /{slug}/                         -> schedule page (owner admin)
 *   /{slug}/{base62-hash}/           -> cal page (public booking)
 *   /{slug}/ai-assistant/{uuid}/     -> cal page (AI assistant)
 */
final class SlugRouter
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
        add_action('parse_request', [$this, 'route']);
        // Our slug URLs intentionally differ from the cal/schedule page
        // permalinks — suppress WordPress's canonical redirect for them.
        add_filter('redirect_canonical', static function ($redirect) {
            return (string) get_query_var('apexianlab_route') !== '' ? false : $redirect;
        });
    }

    public function route(\WP $wp): void
    {
        $path = trim((string) $wp->request, '/');
        if ($path === '') {
            return;
        }

        $segments = explode('/', $path);
        $slug     = strtolower($segments[0]);

        // Cheap charset gate before touching the database.
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            return;
        }

        // `cal` and `ai-assistant` are internal WordPress pages used only as
        // slug-routing targets — direct visits (including legacy /cal/{id}/
        // links) must 404, not render the bare page.
        if ($slug === 'cal' || $slug === 'ai-assistant') {
            $wp->query_vars = ['error' => '404'];
            return;
        }

        try {
            $pdo = Connection::getInstance()->pdo();
            $row = (new SlugRepository($pdo))->findBySlug($slug);
        } catch (\Throwable $e) {
            return;
        }
        if ($row === null) {
            return; // Not a known slug — let WordPress route normally.
        }

        $count = count($segments);

        // /{slug}/ — owner admin.
        if ($count === 1) {
            $wp->query_vars = [
                'pagename'       => 'schedule',
                'apexianlab_owner_slug' => $slug,
                'apexianlab_route'      => 'admin',
            ];
            return;
        }

        // /{slug}/ai-assistant/{uuid}/
        if ($count === 3
            && $segments[1] === 'ai-assistant'
            && preg_match('/^[a-fA-F0-9-]+$/', $segments[2]) === 1
        ) {
            $wp->query_vars = [
                'pagename'       => 'cal',
                'apexianlab_owner_slug' => $slug,
                'ai_chat_id'     => $segments[2],
                'apexianlab_route'      => 'ai',
            ];
            return;
        }

        // /{slug}/{base62-hash}/
        if ($count === 2 && preg_match('/^[0-9A-Za-z]{4,11}$/', $segments[1]) === 1) {
            $wp->query_vars = [
                'pagename'                  => 'cal',
                'apexianlab_owner_slug'            => $slug,
                'calendar_booking_short_id' => $segments[1],
                'apexianlab_route'                 => 'booking',
            ];
            return;
        }

        // Known slug but an unrecognised sub-path — leave WordPress to 404 it.
    }
}
