<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Repository;

use DateTime;
use DateTimeZone;
use Exception;
use PDO;
use Apexianlab\Calendar\Helper\TimeHelper;
use PDOException;
use Apexianlab\Calendar\Service\AvailabilityService;

final class ScheduleRepository
{

    private const ALLOWED_FIND_COLUMNS = [
        'id',
        'email',
        'booking_short_id',
        'calendar_means_of_communication_id',
    ];

    private const ALLOWED_UPDATE_COLUMNS = [
        'name',
        'subject',
        'description',
        'calendar_means_of_communication_id',
        'color',
        'reminder_minutes',
        'schedule_ranges',
        'schedule_weekly_rule',
        'is_public',
        'require_email_verification',
        'schedule_repeat',
        'repeat_interval_weeks',
        'name_format',
        'name_format_custom',
        'durations',
        'additional_recipients',
    ];

    private const LIST_COLUMNS = 'id, booking_short_id, name, subject, description, color, email, schedule_ranges, is_public, require_email_verification, schedule_repeat, repeat_interval_weeks, schedule_weekly_rule, calendar_means_of_communication_id, reminder_minutes, name_format, name_format_custom, durations, additional_recipients';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Find a schedule by its primary key.
     *
     * @param string $id Schedule UUID
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM calendar_schedule WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Find a single schedule by arbitrary safe conditions.
     *
     * @param array<string, string> $conditions Column => value pairs (only whitelisted columns)
     * @return array<string, mixed>|null
     */
    public function findBy(array $conditions): ?array
    {
        if ($conditions === []) {
            return null;
        }

        $where = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            if (!in_array($column, self::ALLOWED_FIND_COLUMNS, true)) {
                throw new \InvalidArgumentException(esc_html("Column '{$column}' is not allowed in findBy()."));
            }
            $placeholder = ':' . $column;
            $where[] = $column . ' = ' . $placeholder;
            $params[$placeholder] = $value;
        }

        $sql = 'SELECT * FROM calendar_schedule WHERE '
            . implode(' AND ', $where)
            . ' AND deleted_at IS NULL LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * List all active schedules, optionally filtered by owner email.
     *
     * @param string|null $email Owner email filter
     * @return array<int, array<string, mixed>>
     */
    public function findAllByEmail(?string $email = null): array
    {
        $sql = 'SELECT ' . self::LIST_COLUMNS . ' FROM calendar_schedule WHERE deleted_at IS NULL';
        $params = [];

        if ($email !== null && $email !== '') {
            $sql .= ' AND email = :email';
            $params[':email'] = $email;
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Resolve concrete availability ranges for given schedules within a time window.
     *
     * @param DateTime $start  Window start (any timezone)
     * @param DateTime $end    Window end   (any timezone)
     * @param array<string> $scheduleIds Schedule UUIDs
     * @return array<int, array{schedule_id: string, start: DateTime, end: DateTime, color: int|null}>
     */
    public function getRangesForCalendar(DateTime $start, DateTime $end, array $scheduleIds): array
    {
        if ($scheduleIds === []) {
            return [];
        }

        $startUtc = (clone $start)->setTimezone(new DateTimeZone('UTC'));
        $endUtc = (clone $end)->setTimezone(new DateTimeZone('UTC'));

        $placeholders = [];
        $params = [];
        foreach (array_values($scheduleIds) as $i => $id) {
            $key = ':sid' . $i;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $sql = 'SELECT id, schedule_ranges, color, schedule_repeat, schedule_weekly_rule'
            . ' FROM calendar_schedule'
            . ' WHERE deleted_at IS NULL AND id IN (' . implode(',', $placeholders) . ')';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ranges = [];
        foreach ($schedules as $schedule) {
            $resolved = AvailabilityService::resolveScheduleRangesForWindow($schedule, $start, $end);
            if (!is_array($resolved)) {
                continue;
            }

            foreach ($resolved as $range) {
                if (empty($range['date']) || empty($range['start']) || empty($range['end'])) {
                    continue;
                }

                $rangeTz = new DateTimeZone(
                    TimeHelper::canonicalTimezone(!empty($range['timezone']) ? (string) $range['timezone'] : 'UTC')
                );

                $rangeStart = (new DateTime($range['date'] . ' ' . $range['start'], $rangeTz))
                    ->setTimezone(new DateTimeZone('UTC'));
                $rangeEnd = (new DateTime($range['date'] . ' ' . $range['end'], $rangeTz))
                    ->setTimezone(new DateTimeZone('UTC'));

                if ($rangeStart >= $endUtc || $rangeEnd <= $startUtc) {
                    continue;
                }

                $actualStart = $rangeStart > $startUtc ? $rangeStart : clone $startUtc;
                $actualEnd = $rangeEnd < $endUtc ? $rangeEnd : clone $endUtc;

                if ($actualStart < $actualEnd) {
                    $ranges[] = [
                        'schedule_id' => $schedule['id'],
                        'start' => $actualStart,
                        'end' => $actualEnd,
                        'color' => isset($schedule['color']) ? (int) $schedule['color'] : null,
                    ];
                }
            }
        }

        return $ranges;
    }

    /**
     * Create a new schedule and assign a booking short ID.
     *
     * @param int         $repeatIntervalWeeks Clamped to 1..52; ignored when $scheduleRepeat is 'does_not_repeat'.
     * @return string New schedule UUID
     */
    public function create(
        string $email,
        string $name,
        string $subject,
        string $description,
        string $meansId,
        ?int $color,
        int $reminderMinutes,
        string $scheduleRangesJson,
        bool $isPublic,
        bool $requireEmailVerification,
        string $prodid,
        string $scheduleRepeat,
        int $repeatIntervalWeeks,
        string $nameFormat,
        ?string $nameFormatCustom,
        ?string $weeklyRuleJson,
        ?string $durationsJson = null,
        ?string $additionalRecipientsJson = null
    ): string {
        $allowedRepeat = ['does_not_repeat', 'weekly', 'custom'];
        $scheduleRepeat = in_array($scheduleRepeat, $allowedRepeat, true) ? $scheduleRepeat : 'weekly';

        $repeatIntervalWeeks = max(1, min(52, $repeatIntervalWeeks));
        if ($scheduleRepeat === 'does_not_repeat') {
            $repeatIntervalWeeks = 1;
        }

        $allowedNameFormats = ['full', 'first_last_initial', 'initial_last', 'first_only', 'initials', 'custom'];
        $nameFormat = in_array($nameFormat, $allowedNameFormats, true) ? $nameFormat : 'full';

        $hasDurations = ($durationsJson !== null && $durationsJson !== '');
        $hasRecipients = ($additionalRecipientsJson !== null && $additionalRecipientsJson !== '');

        $sql = "INSERT INTO calendar_schedule (
                    booking_short_id, email, name, subject, description,
                    calendar_means_of_communication_id, color, reminder_minutes,
                    schedule_ranges, is_public, require_email_verification, prodid, schedule_repeat,
                    repeat_interval_weeks, name_format, name_format_custom, schedule_weekly_rule"
                . ($hasDurations ? ", durations" : "")
                . ($hasRecipients ? ", additional_recipients" : "")
                . ") VALUES (
                    NULL, :email, :name, :subject, :description,
                    :means_id, :color, :reminder_minutes,
                    :schedule_ranges::jsonb, :is_public, :require_email_verification, :prodid, :schedule_repeat,
                    :repeat_interval_weeks, :name_format, :name_format_custom, :schedule_weekly_rule"
                . ($hasDurations ? ", :durations::jsonb" : "")
                . ($hasRecipients ? ", :additional_recipients::jsonb" : "")
                . ") RETURNING id";

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':subject', $subject, PDO::PARAM_STR);
            $stmt->bindValue(':description', $description, PDO::PARAM_STR);
            $stmt->bindValue(':means_id', $meansId, PDO::PARAM_STR);
            $stmt->bindValue(':color', $color, PDO::PARAM_INT);
            $stmt->bindValue(':reminder_minutes', $reminderMinutes, PDO::PARAM_INT);
            $stmt->bindValue(':schedule_ranges', $scheduleRangesJson, PDO::PARAM_STR);
            $stmt->bindValue(':is_public', $isPublic, PDO::PARAM_BOOL);
            $stmt->bindValue(':require_email_verification', $requireEmailVerification, PDO::PARAM_BOOL);
            $stmt->bindValue(':prodid', $prodid, PDO::PARAM_STR);
            $stmt->bindValue(':schedule_repeat', $scheduleRepeat, PDO::PARAM_STR);
            $stmt->bindValue(':repeat_interval_weeks', $repeatIntervalWeeks, PDO::PARAM_INT);
            $stmt->bindValue(':name_format', $nameFormat, PDO::PARAM_STR);
            $stmt->bindValue(
                ':name_format_custom',
                ($nameFormatCustom !== null && $nameFormatCustom !== '') ? $nameFormatCustom : null,
                ($nameFormatCustom !== null && $nameFormatCustom !== '') ? PDO::PARAM_STR : PDO::PARAM_NULL
            );
            $stmt->bindValue(
                ':schedule_weekly_rule',
                ($weeklyRuleJson !== null && $weeklyRuleJson !== '') ? $weeklyRuleJson : null,
                ($weeklyRuleJson !== null && $weeklyRuleJson !== '') ? PDO::PARAM_STR : PDO::PARAM_NULL
            );
            if ($hasDurations) {
                $stmt->bindValue(':durations', $durationsJson, PDO::PARAM_STR);
            }
            if ($hasRecipients) {
                $stmt->bindValue(':additional_recipients', $additionalRecipientsJson, PDO::PARAM_STR);
            }

            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $newId = (string) ($row['id'] ?? '');

            if ($newId === '') {
                throw new Exception('Failed to create calendar schedule.');
            }

            $this->ensureBookingShortId($newId);
            $this->pdo->commit();

            return $newId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing schedule with whitelisted fields.
     *
     * @param string              $id    Schedule UUID
     * @param string              $email Owner email (ownership guard)
     * @param array<string, mixed> $data  Column => value pairs
     * @return bool Whether any row was affected
     */
    public function update(string $id, string $email, array $data): bool
    {
        $setClauses = [];
        $params = [
            ':id' => $id,
            ':email' => $email,
        ];

        foreach (self::ALLOWED_UPDATE_COLUMNS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            if ($key === 'durations' || $key === 'additional_recipients') {
                $setClauses[] = $key . ' = :' . $key . '::jsonb';
            } else {
                $setClauses[] = $key . ' = :' . $key;
            }
            $params[':' . $key] = $data[$key];
        }

        if ($setClauses === []) {
            return false;
        }

        $sql = 'UPDATE calendar_schedule SET ' . implode(', ', $setClauses)
            . ' WHERE id = :id AND email = :email AND deleted_at IS NULL';

        try {
            $stmt = $this->pdo->prepare($sql);

            foreach ($params as $k => $v) {
                if ($k === ':is_public' || $k === ':require_email_verification') {
                    $stmt->bindValue($k, (bool) $v, PDO::PARAM_BOOL);
                } elseif (in_array($k, [':color', ':reminder_minutes', ':repeat_interval_weeks'], true)) {
                    $stmt->bindValue($k, $v, PDO::PARAM_INT);
                } elseif (in_array($k, [':name_format_custom', ':schedule_weekly_rule'], true) && ($v === null || $v === '')) {
                    $stmt->bindValue($k, null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue($k, $v);
                }
            }

            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Soft-delete a schedule and all its bookings.
     *
     * @param string $id    Schedule UUID
     * @param string $email Owner email (ownership guard)
     * @return bool Whether the schedule row was affected
     */
    public function softDelete(string $id, string $email): bool
    {
        try {
            $bookings = $this->pdo->prepare(
                'UPDATE calendar_booking SET deleted_at = NOW()
                 WHERE calendar_schedule_id = :sid AND deleted_at IS NULL'
            );
            $bookings->bindValue(':sid', $id, PDO::PARAM_STR);
            $bookings->execute();

            $stmt = $this->pdo->prepare(
                'UPDATE calendar_schedule SET deleted_at = NOW()
                 WHERE id = :id AND email = :email AND deleted_at IS NULL'
            );
            $stmt->bindValue(':id', $id, PDO::PARAM_STR);
            $stmt->bindValue(':email', $email, PDO::PARAM_STR);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Persist the Google Calendar event IDs that mirror a schedule's
     * availability windows. Pass [] to clear them.
     *
     * @param list<string> $ids
     */
    public function setGoogleScheduleEventIds(string $id, array $ids): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE calendar_schedule
                    SET google_schedule_event_ids = :ids, updated_at = NOW()
                  WHERE id = :id'
            );
            $stmt->bindValue(':ids', $ids === [] ? null : (string) wp_json_encode(array_values($ids)));
            $stmt->bindValue(':id', $id, PDO::PARAM_STR);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Ensure a unique base62-encoded booking_short_id is assigned to a schedule.
     *
     * @param string $scheduleId Schedule UUID
     * @return string|null The assigned short ID, or null if the schedule does not exist / is deleted
     * @throws Exception When allocation fails after retries
     */
    public function ensureBookingShortId(string $scheduleId): ?string
    {
        $scheduleId = trim($scheduleId);
        if ($scheduleId === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT booking_short_id, deleted_at FROM calendar_schedule WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $scheduleId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || !empty($row['deleted_at'])) {
            return null;
        }

        $existing = trim((string) ($row['booking_short_id'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = self::encodeBase62($this->nextShortIdSequenceValue());

            if ($this->tryAssignShortId($scheduleId, $candidate)) {
                return $candidate;
            }

            $stmt = $this->pdo->prepare(
                'SELECT booking_short_id FROM calendar_schedule WHERE id = :id AND deleted_at IS NULL LIMIT 1'
            );
            $stmt->bindValue(':id', $scheduleId, PDO::PARAM_STR);
            $stmt->execute();
            $existing = trim((string) $stmt->fetchColumn());
            if ($existing !== '') {
                return $existing;
            }
        }

        throw new Exception('Unable to allocate a unique booking short ID.');
    }

    // ------------------------------------------------------------------
    //  Private helpers
    // ------------------------------------------------------------------

    /**
     * Pull next value from the short-id sequence.
     */
    private function nextShortIdSequenceValue(): int
    {
        $stmt = $this->pdo->query("SELECT nextval('calendar_schedule_short_id_seq')");
        return (int) $stmt->fetchColumn();
    }

    /**
     * Encode a non-negative integer as base62 (0-9a-zA-Z).
     */
    private static function encodeBase62(int $n): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if ($n <= 0) {
            return '0';
        }
        $result = '';
        while ($n > 0) {
            $result = $alphabet[$n % 62] . $result;
            $n = intdiv($n, 62);
        }
        return $result;
    }

    /**
     * Atomically assign a short-ID code to a schedule if still free.
     *
     * @param string $scheduleId Schedule UUID
     * @param string $code       base62 candidate code
     * @return bool Whether the assignment succeeded
     */
    private function tryAssignShortId(string $scheduleId, string $code): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE calendar_schedule AS t
                 SET booking_short_id = :code
                 WHERE t.id = :schedule_id
                   AND t.deleted_at IS NULL
                   AND (t.booking_short_id IS NULL OR btrim(t.booking_short_id) = '')
                   AND NOT EXISTS (
                       SELECT 1 FROM calendar_schedule x
                       WHERE x.booking_short_id = :code_dup
                         AND x.id <> t.id
                         AND x.deleted_at IS NULL
                   )"
            );
            $stmt->bindValue(':code', $code, PDO::PARAM_STR);
            $stmt->bindValue(':code_dup', $code, PDO::PARAM_STR);
            $stmt->bindValue(':schedule_id', $scheduleId, PDO::PARAM_STR);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // unique-constraint violation — another process won the race
            if (($e->errorInfo[0] ?? '') === '23505') {
                return false;
            }
            throw $e;
        }
    }

}
