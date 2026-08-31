<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Migration;

use Apexianlab\Calendar\Database\Connection;

final class Migrator
{
    private const SQL_DIR = __DIR__ . '/../Database';

    private const TABLE_FILES = [
        'calendar_means_of_communication',
        'calendar_google_credentials',
        'calendar_schedule',
        'calendar_booking',
        'ai_chats',
        'ai_chat_messages',
        'ai_chat_state',
        'ai_chat_action_log',
        'calendar_user_slug',
    ];

    /** @var list<string> Idempotent ALTER scripts run on every plugin boot. */
    private const ALTER_FILES = [
        'ai_chats_session_token',
        'calendar_schedule_repeat_custom',
        'calendar_schedule_short_id_base62',
        'calendar_booking_previous_datetime',
        'calendar_schedule_durations',
        'calendar_schedule_additional_recipients',
        'calendar_schedule_drop_calendar_type',
        'calendar_booking_cancel_token',
        'calendar_booking_pending_reschedule',
        'calendar_google_credentials_selected_calendar',
        'calendar_schedule_google_event_ids',
        'calendar_schedule_require_email_verification',
        'calendar_google_credentials_granted_scopes',
        'calendar_booking_name_length',
    ];

    public static function run(Connection $connection): void
    {
        $pdo = $connection->pdo();

        self::createTables($pdo);
        self::applyAlters($pdo);
        self::ensureBookingGoogleColumns($pdo);
        self::seedMeansOfCommunication($pdo);
        self::backfillUserSlugs($pdo);
    }

    /**
     * Give every existing schedule owner a slug. Idempotent — only fills
     * gaps; owners with a slug row are skipped.
     */
    private static function backfillUserSlugs(\PDO $pdo): void
    {
        try {
            $rows = $pdo->query(
                "SELECT DISTINCT ON (email) email, name
                 FROM calendar_schedule
                 WHERE deleted_at IS NULL AND email <> ''
                 ORDER BY email, created_at"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return;
        }

        $repo = new \Apexianlab\Calendar\Repository\SlugRepository($pdo);
        $svc  = new \Apexianlab\Calendar\Service\SlugService($repo);

        foreach ($rows as $row) {
            $email = (string) ($row['email'] ?? '');
            if ($email === '' || $repo->findByEmail($email) !== null) {
                continue;
            }
            $svc->ensureSlug($email, (string) ($row['name'] ?? ''));
        }
    }

    private static function applyAlters(\PDO $pdo): void
    {
        foreach (self::ALTER_FILES as $script) {
            $file = self::SQL_DIR . '/' . $script . '.sql';
            if (!is_file($file)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false || $sql === '') {
                continue;
            }
            try {
                $pdo->exec($sql);
            } catch (\Throwable $e) {
                // Migration script must be idempotent (IF NOT EXISTS); ignore failures.
            }
        }
    }

    private static function createTables(\PDO $pdo): void
    {
        foreach (self::TABLE_FILES as $table) {
            $file = self::SQL_DIR . '/' . $table . '.sql';
            if (!is_file($file)) {
                continue;
            }

            $stmt = $pdo->query("SELECT to_regclass('public.{$table}')");
            if ($stmt->fetchColumn() !== null) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql !== false && $sql !== '') {
                $pdo->exec($sql);
            }
        }
    }

    private static function seedMeansOfCommunication(\PDO $pdo): void
    {
        try {
            $pdo->exec(
                "CREATE UNIQUE INDEX IF NOT EXISTS calendar_means_of_communication_title_uq
                 ON calendar_means_of_communication (title)"
            );
            // Additive only: ensure the Google Calendar type exists. Legacy
            // means rows are intentionally left intact — the database is shared
            // with the full-integrations branch that still needs them.
            $pdo->exec(
                "INSERT INTO calendar_means_of_communication (title)
                 VALUES ('Google Calendar')
                 ON CONFLICT (title) DO NOTHING"
            );
        } catch (\Throwable $e) {
            // Seed errors are non-fatal
        }
    }

    private static function ensureBookingGoogleColumns(\PDO $pdo): void
    {
        try {
            $pdo->exec(
                "ALTER TABLE calendar_booking
                 ADD COLUMN IF NOT EXISTS google_event_id VARCHAR(255),
                 ADD COLUMN IF NOT EXISTS google_calendar_id VARCHAR(255)"
            );
        } catch (\Throwable $e) {
            // ALTER errors are non-fatal
        }
    }
}
