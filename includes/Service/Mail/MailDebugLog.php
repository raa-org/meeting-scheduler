<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Service\Mail;

/**
 * Ring buffer of mail-pipeline events, kept in a WP option so the admin can
 * read failures without shell access to debug.log.
 */
final class MailDebugLog
{
    private const OPTION = 'apexianlab_mail_debug_log';
    private const LIMIT  = 100;

    public static function add(string $message): void
    {
        error_log('[apexianlab-mail] ' . $message);

        $entries = self::entries();
        $entries[] = [
            'time'    => gmdate('Y-m-d H:i:s'),
            'message' => $message,
        ];

        if (count($entries) > self::LIMIT) {
            $entries = array_slice($entries, -self::LIMIT);
        }

        update_option(self::OPTION, $entries, false);
    }

    /**
     * @return list<array{time: string, message: string}>
     */
    public static function entries(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? array_values($stored) : [];
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }
}
