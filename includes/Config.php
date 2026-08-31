<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configuration class for Meeting Scheduler.
 */
final class Config
{
    public static function isCalendarEnabled(): bool
    {
        // Google-only deployment needs just the Postgres calendar DB.
        // CALENDAR_HOST / CALENDAR_PRODID are legacy CalDAV/iCal flags:
        // CALENDAR_HOST is unused, CALENDAR_PRODID falls back in getCalendarProdid().
        return defined('POSTGRESQL_HOST')
            && defined('POSTGRESQL_DBNAME')
            && defined('POSTGRESQL_USER')
            && defined('POSTGRESQL_PASSWORD');
    }

    public static function getCalendarProdid(): string
    {
        return defined('CALENDAR_PRODID') ? (string) CALENDAR_PRODID : 'Apexianlab';
    }

    public static function getPostgresqlHost(): string
    {
        return defined('POSTGRESQL_HOST') ? (string) POSTGRESQL_HOST : '';
    }

    public static function getPostgresqlDbname(): string
    {
        return defined('POSTGRESQL_DBNAME') ? (string) POSTGRESQL_DBNAME : '';
    }

    public static function getPostgresqlUser(): string
    {
        return defined('POSTGRESQL_USER') ? (string) POSTGRESQL_USER : '';
    }

    public static function getPostgresqlPassword(): string
    {
        return defined('POSTGRESQL_PASSWORD') ? (string) POSTGRESQL_PASSWORD : '';
    }

    /** True when Google OAuth client id + secret are defined in wp-config. */
    public static function isGoogleOAuthConfigured(): bool
    {
        $id = defined('GOOGLE_CALENDAR_CLIENT_ID') ? trim((string) GOOGLE_CALENDAR_CLIENT_ID) : '';
        $secret = defined('GOOGLE_CALENDAR_CLIENT_SECRET') ? trim((string) GOOGLE_CALENDAR_CLIENT_SECRET) : '';

        return $id !== '' && $secret !== '';
    }

    /** LLM: OpenAI-compatible API. Set APEXIANLAB_LLM_BASE_URL (required), APEXIANLAB_LLM_MODEL, APEXIANLAB_LLM_API_KEY. */
    public static function isLlmConfigured(): bool
    {
        return self::getLlmBaseUrl() !== '';
    }

    public static function getLlmBaseUrl(): string
    {
        if (!defined('APEXIANLAB_LLM_BASE_URL')) {
            return '';
        }
        $url = trim((string) APEXIANLAB_LLM_BASE_URL);

        return rtrim($url, '/');
    }

    public static function getLlmModel(): string
    {
        if (defined('APEXIANLAB_LLM_MODEL') && trim((string) APEXIANLAB_LLM_MODEL) !== '') {
            return trim((string) APEXIANLAB_LLM_MODEL);
        }

        return 'llama3.1';
    }

    public static function getLlmApiKey(): ?string
    {
        if (!defined('APEXIANLAB_LLM_API_KEY')) {
            return null;
        }
        $key = trim((string) APEXIANLAB_LLM_API_KEY);

        return $key !== '' ? $key : null;
    }

    /**
     * STT (Speech-to-Text) endpoint — OpenAI-compatible /audio/transcriptions.
     * Falls back to APEXIANLAB_LLM_BASE_URL if APEXIANLAB_STT_BASE_URL is not defined.
     */
    public static function getSttBaseUrl(): string
    {
        if (defined('APEXIANLAB_STT_BASE_URL') && trim((string) APEXIANLAB_STT_BASE_URL) !== '') {
            return rtrim(trim((string) APEXIANLAB_STT_BASE_URL), '/');
        }

        return self::getLlmBaseUrl();
    }

    public static function getSttModel(): string
    {
        if (defined('APEXIANLAB_STT_MODEL') && trim((string) APEXIANLAB_STT_MODEL) !== '') {
            return trim((string) APEXIANLAB_STT_MODEL);
        }

        return 'whisper-1';
    }

    public static function isSttConfigured(): bool
    {
        return self::getSttBaseUrl() !== '';
    }

}
