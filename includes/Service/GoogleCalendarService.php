<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Service;

final class GoogleCalendarService
{
    private const API_BASE = 'https://www.googleapis.com/calendar/v3';
    private const TIMEOUT  = 30;

    /**
     * POST /calendars/{calendarId}/events — returns decoded JSON body on 200.
     *
     * @param array<string, mixed> $eventPayload Google Calendar event body
     * @return array<string, mixed>|null
     */
    public static function createEvent(
        string $token,
        array $eventPayload,
        string $calendarId = 'primary',
        bool $withMeet = true,
        bool $sendUpdates = true
    ): ?array {
        $query = [];
        if ($withMeet) {
            $query['conferenceDataVersion'] = 1;
        }
        if ($sendUpdates) {
            $query['sendUpdates'] = 'all';
        }

        $url = self::API_BASE . '/calendars/' . rawurlencode($calendarId) . '/events';
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $response = wp_remote_post($url, [
            'headers'   => self::jsonHeaders($token),
            'body'      => wp_json_encode($eventPayload),
            'timeout'   => self::TIMEOUT,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if (!in_array($code, [200, 201], true)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($body) ? $body : null;
    }

    public static function deleteEvent(
        string $token,
        string $eventId,
        string $calendarId = 'primary',
        bool $sendUpdates = true
    ): int {
        $url = self::API_BASE . '/calendars/' . rawurlencode($calendarId)
            . '/events/' . rawurlencode($eventId);
        if ($sendUpdates) {
            $url .= '?sendUpdates=all';
        }

        $response = wp_remote_request($url, [
            'method'    => 'DELETE',
            'headers'   => ['Authorization' => 'Bearer ' . $token],
            'timeout'   => 20,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return 0;
        }

        return (int) wp_remote_retrieve_response_code($response);
    }

    /**
     * GET /calendars/{calendarId}/events/{eventId}.
     *
     * @return array{code: int, event: array<string, mixed>|null} code 0 = transport error
     */
    public static function getEvent(
        string $token,
        string $eventId,
        string $calendarId = 'primary'
    ): array {
        $url = self::API_BASE . '/calendars/' . rawurlencode($calendarId)
            . '/events/' . rawurlencode($eventId);

        $response = wp_remote_get($url, [
            'headers'   => self::jsonHeaders($token),
            'timeout'   => self::TIMEOUT,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return ['code' => 0, 'event' => null];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        return ['code' => $code, 'event' => is_array($body) ? $body : null];
    }

    /**
     * POST /freeBusy — returns busy intervals for given calendar IDs in window.
     *
     * @param list<string> $calendarIds
     * @return array<int, array{start: string, end: string}>|null
     */
    public static function freeBusy(
        string $token,
        \DateTimeInterface $timeMin,
        \DateTimeInterface $timeMax,
        array $calendarIds = ['primary']
    ): ?array {
        $minUtc = (clone $timeMin)->setTimezone(new \DateTimeZone('UTC'));
        $maxUtc = (clone $timeMax)->setTimezone(new \DateTimeZone('UTC'));

        $body = [
            'timeMin' => $minUtc->format('Y-m-d\TH:i:s\Z'),
            'timeMax' => $maxUtc->format('Y-m-d\TH:i:s\Z'),
            'items'   => array_map(static fn(string $id) => ['id' => $id], $calendarIds),
        ];

        $response = wp_remote_post(self::API_BASE . '/freeBusy', [
            'headers'   => self::jsonHeaders($token),
            'body'      => wp_json_encode($body),
            'timeout'   => self::TIMEOUT,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded) || empty($decoded['calendars']) || !is_array($decoded['calendars'])) {
            return [];
        }

        $busy = [];
        foreach ($decoded['calendars'] as $calData) {
            if (!is_array($calData) || empty($calData['busy']) || !is_array($calData['busy'])) {
                continue;
            }
            foreach ($calData['busy'] as $interval) {
                if (!is_array($interval)) {
                    continue;
                }
                $start = isset($interval['start']) ? (string) $interval['start'] : '';
                $end   = isset($interval['end']) ? (string) $interval['end'] : '';
                if ($start === '' || $end === '') {
                    continue;
                }
                $busy[] = ['start' => $start, 'end' => $end];
            }
        }

        return $busy;
    }

    /**
     * List the calendars the user can write events to (owner/writer access).
     *
     * @return array<int, array{id: string, summary: string, primary: bool}>|null
     *         null on API/transport error.
     */
    public static function listCalendars(string $token): ?array
    {
        $response = wp_remote_get(
            self::API_BASE . '/users/me/calendarList?minAccessRole=writer&maxResults=250',
            [
                'headers'   => self::jsonHeaders($token),
                'timeout'   => self::TIMEOUT,
                'sslverify' => true,
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded) || empty($decoded['items']) || !is_array($decoded['items'])) {
            return [];
        }

        $out = [];
        foreach ($decoded['items'] as $cal) {
            if (!is_array($cal) || empty($cal['id'])) {
                continue;
            }
            $out[] = [
                'id'      => (string) $cal['id'],
                'summary' => (string) ($cal['summaryOverride'] ?? $cal['summary'] ?? $cal['id']),
                'primary' => !empty($cal['primary']),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function jsonHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }
}
