<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Helper;

use Apexianlab\Calendar\Repository\MeansOfCommunicationRepository;
use Apexianlab\Calendar\Repository\ScheduleRepository;

final class DisplayHelper
{
    /**
     * Format organiser name according to the schedule's name_format setting.
     */
    public static function formatOrganiserName(string $fullName, string $format, ?string $custom): string
    {
        $allowed = ['full', 'first_last_initial', 'initial_last', 'first_only', 'initials', 'custom'];
        if (!in_array($format, $allowed, true)) {
            $format = 'full';
        }

        if ($format === 'custom') {
            $c = trim((string) $custom);
            return $c !== '' ? $c : trim($fullName);
        }

        $fullName = trim($fullName);
        if ($fullName === '') {
            return '';
        }

        $parts = preg_split('/\s+/u', $fullName) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? (string) $parts[count($parts) - 1] : '';

        return match ($format) {
            'first_last_initial' => $last !== ''
                ? $first . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.'
                : $first,
            'initial_last' => $first !== '' && $last !== ''
                ? mb_strtoupper(mb_substr($first, 0, 1)) . '. ' . $last
                : $fullName,
            'first_only' => $first,
            'initials' => implode('', array_map(
                static fn(string $p) => $p !== '' ? mb_strtoupper(mb_substr($p, 0, 1)) . '.' : '',
                $parts
            )),
            default => $fullName,
        };
    }

    /**
     * Organiser display name from a schedule row.
     */
    public static function scheduleOrganiserName(?array $schedule): string
    {
        if ($schedule === null) {
            return '';
        }
        $raw = trim((string) ($schedule['name'] ?? ''));
        $format = (string) ($schedule['name_format'] ?? 'full');
        $custom = isset($schedule['name_format_custom']) ? trim((string) $schedule['name_format_custom']) : null;

        return self::formatOrganiserName($raw, $format, $custom !== '' ? $custom : null);
    }

    /**
     * Full organiser name from OAuth user info.
     */
    public static function fullNameFromUserInfo(array $userInfo): string
    {
        $given = trim((string) ($userInfo['given_name'] ?? ''));
        $family = trim((string) ($userInfo['family_name'] ?? ''));

        if ($given !== '' && $family !== '') {
            return $given . ' ' . $family;
        }
        if ($given !== '') {
            return $given;
        }

        $name = trim((string) ($userInfo['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $email = (string) ($userInfo['email'] ?? '');
        if ($email !== '') {
            return explode('@', $email, 2)[0] ?: 'User';
        }

        return 'User';
    }

    /**
     * Build booking URL: /{owner-slug}/{base62-short-id}/
     * Auto-attaches HMAC `t` when `meeting_id` present. See meetingAccessToken().
     */
    public static function bookingUrl(?array $schedule, array $queryArgs = []): string
    {
        $shortId = self::bookingShortId($schedule);
        if ($shortId === '') {
            return '';
        }

        $slug = self::ownerSlug(
            (string) ($schedule['email'] ?? ''),
            (string) ($schedule['name'] ?? '')
        );
        if ($slug === '') {
            return '';
        }

        $url = home_url($slug . '/' . rawurlencode($shortId) . '/');

        if (!empty($queryArgs['meeting_id']) && empty($queryArgs['t'])) {
            $token = self::meetingAccessToken((string) $queryArgs['meeting_id']);
            if ($token !== '') {
                $queryArgs['t'] = $token;
            }
        }

        return $queryArgs !== [] ? add_query_arg($queryArgs, $url) : $url;
    }

    /**
     * Build the owner admin URL: /{owner-slug}/
     * Empty string when the owner has no slug and one cannot be minted.
     */
    public static function ownerAdminUrl(string $email, string $ownerName = ''): string
    {
        $slug = self::ownerSlug($email, $ownerName);

        return $slug !== '' ? home_url($slug . '/') : '';
    }

    /**
     * Build the AI assistant URL: /{owner-slug}/ai-assistant/{chat-id}/
     *
     * @param array<string, mixed>|null $schedule
     */
    public static function aiAssistantUrl(?array $schedule, string $chatId): string
    {
        if (!is_array($schedule) || $chatId === '') {
            return '';
        }
        $slug = self::ownerSlug(
            (string) ($schedule['email'] ?? ''),
            (string) ($schedule['name'] ?? '')
        );
        if ($slug === '') {
            return '';
        }

        return home_url($slug . '/ai-assistant/' . rawurlencode($chatId) . '/');
    }

    /**
     * Resolve (or lazily mint) the public slug for a schedule owner email.
     */
    private static function ownerSlug(string $email, string $ownerName = ''): string
    {
        $email = trim($email);
        if ($email === '') {
            return '';
        }
        try {
            $pdo  = \Apexianlab\Calendar\Database\Connection::getInstance()->pdo();
            $repo = new \Apexianlab\Calendar\Repository\SlugRepository($pdo);
            $svc  = new \Apexianlab\Calendar\Service\SlugService($repo);
            $slug = $svc->slugForEmail($email);
            if ($slug === '') {
                $slug = $svc->ensureSlug($email, $ownerName);
            }
            return $slug;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * HMAC token for booking actions (view/reschedule/cancel).
     * Derived from UUID + WP auth salt; stable across reschedules.
     */
    public static function meetingAccessToken(string $meetingId): string
    {
        $meetingId = strtolower(trim($meetingId));
        if ($meetingId === '') {
            return '';
        }

        return substr(hash_hmac('sha256', $meetingId, wp_salt('auth') . '|apexianlab-booking-access'), 0, 32);
    }

    /**
     * Constant-time check of a provided `t` against the expected HMAC.
     */
    public static function isMeetingAccessToken(string $meetingId, string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        $expected = self::meetingAccessToken($meetingId);
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /**
     * Booking short ID from schedule row.
     */
    public static function bookingShortId(?array $schedule): string
    {
        if ($schedule === null) {
            return '';
        }

        return trim((string) ($schedule['booking_short_id'] ?? ''));
    }

    /**
     * Communication means title for a schedule.
     */
    public static function communicationTitle(?array $schedule, MeansOfCommunicationRepository $meansRepo): string
    {
        if ($schedule === null || empty($schedule['calendar_means_of_communication_id'])) {
            return '';
        }

        $means = $meansRepo->findById((string) $schedule['calendar_means_of_communication_id']);

        return !empty($means['title']) ? trim((string) $means['title']) : '';
    }

    /**
     * Week days array.
     *
     * @return array<int, string>
     */
    public static function weekDays(): array
    {
        return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    }

    /**
     * Format a meeting date, showing both start and end date when the meeting crosses midnight.
     * e.g. "Saturday, May 30" or "Saturday, May 30 – Sunday, May 31"
     */
    public static function formatMeetingDateRange(\DateTime $date, int $duration): string
    {
        $end = clone $date;
        $end->modify('+' . $duration . ' minutes');
        if ($date->format('Y-m-d') === $end->format('Y-m-d')) {
            return $date->format('l, F j');
        }
        return $date->format('l, F j') . ' – ' . $end->format('l, F j');
    }
}
