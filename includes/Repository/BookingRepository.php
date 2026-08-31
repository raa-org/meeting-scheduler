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
use PDO;

class BookingRepository
{
    private const TABLE = 'calendar_booking';

    private const ALLOWED_FILTER_COLUMNS = [
        'id',
        'calendar_schedule_id',
        'email',
    ];

    private const BLOCKED_UPDATE_COLUMNS = [
        'id',
        'deleted_at',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param string $id Booking UUID
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $id = strtolower(trim($id));
        if ($id === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByConfirmationToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE
            . " WHERE confirmation_token = :t AND confirmation_token <> '' AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->bindValue(':t', $token, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param string $id Booking UUID
     * @return array<string, mixed>|null
     */
    public function findByIdAnyState(string $id): ?array
    {
        $id = strtolower(trim($id));
        if ($id === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $conditions Key-value pairs for WHERE clause (allowed: id, calendar_schedule_id, email)
     * @return array<string, mixed>|null
     */
    public function findBy(array $conditions): ?array
    {
        $query = $this->buildSelectQuery($conditions);
        if ($query === null) {
            return null;
        }

        [$sql, $params] = $query;
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $conditions Key-value pairs for WHERE clause (allowed: id, calendar_schedule_id, email)
     * @return array<int, array<string, mixed>>
     */
    public function findAllBy(array $conditions): array
    {
        $query = $this->buildSelectQuery($conditions);
        if ($query === null) {
            return [];
        }

        [$sql, $params] = $query;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param DateTime          $start       Range start
     * @param DateTime          $end            Range end
     * @param array<int,string> $scheduleIds    UUIDs of calendar_schedule
     * @param ?string           $attendeeEmail  Optional attendee email filter
     * @param bool              $includePending If true, also return bookings where
     *                                          `meeting_confirmed = FALSE` (e.g. a
     *                                          guest-public booking whose confirmation
     *                                          email never went out). Default false to
     *                                          keep existing callers' behaviour intact.
     * @return array<int, array<string, mixed>>
     */
    public function findForCalendar(
        DateTime $start,
        DateTime $end,
        array $scheduleIds,
        ?string $attendeeEmail = null,
        bool $includePending = false
    ): array {
        $attendeeEmail = $attendeeEmail !== null ? trim($attendeeEmail) : null;
        if ($attendeeEmail === '') {
            $attendeeEmail = null;
        }

        if ($scheduleIds === [] && $attendeeEmail === null) {
            return [];
        }

        $startUtc = (clone $start)->setTimezone(new DateTimeZone('UTC'));
        $endUtc   = (clone $end)->setTimezone(new DateTimeZone('UTC'));

        // Include explicit UTC offset so the comparison is timezone-safe
        // regardless of the PostgreSQL session timezone (which may be EDT etc.).
        $params = [
            ':start' => $startUtc->format('Y-m-d H:i:sP'),
            ':end'   => $endUtc->format('Y-m-d H:i:sP'),
        ];

        $orClauses = [];

        if ($scheduleIds !== []) {
            $placeholders = [];
            foreach (array_values($scheduleIds) as $i => $id) {
                $key = ':sid' . $i;
                $placeholders[] = $key;
                $params[$key] = $id;
            }
            $orClauses[] = 'b.calendar_schedule_id IN (' . implode(',', $placeholders) . ')';
        }

        if ($attendeeEmail !== null) {
            $orClauses[] = 'b.email = :attendee_email';
            $params[':attendee_email'] = $attendeeEmail;
        }

        // A confirmed meeting with a parked reschedule has meeting_confirmed
        // flipped to FALSE while it waits for email confirmation — keep it on
        // the calendar at its original slot until the reschedule is applied.
        $confirmedClause = $includePending
            ? ''
            : "AND (b.meeting_confirmed = TRUE OR b.pending_reschedule IS NOT NULL)\n                  ";

        $sql = "SELECT b.id, b.calendar_schedule_id, b.subject, b.description,
                       b.email, b.phone, b.first_name, b.last_name,
                       b.meeting_join_url, b.datetime, b.duration, b.timezone,
                       b.meeting_confirmed, b.expiration_of_confirmation,
                       s.color, s.email AS schedule_email,
                       s.name AS schedule_name, s.name_format, s.name_format_custom,
                       s.booking_short_id, s.calendar_means_of_communication_id
                FROM calendar_booking b
                INNER JOIN calendar_schedule s ON s.id = b.calendar_schedule_id
                WHERE b.deleted_at IS NULL
                  AND s.deleted_at IS NULL
                  {$confirmedClause}AND b.datetime >= :start
                  AND b.datetime < :end
                  AND (" . implode(' OR ', $orClauses) . ")
                ORDER BY b.datetime ASC";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Has email any confirmed bookings on schedules they don't own.
     *
     * @param array<int, string> $ownScheduleIds Owned schedules to exclude.
     */
    public function hasInvitedBookings(string $attendeeEmail, array $ownScheduleIds): bool
    {
        $attendeeEmail = trim($attendeeEmail);
        if ($attendeeEmail === '') {
            return false;
        }

        $sql = 'SELECT 1
                FROM calendar_booking b
                INNER JOIN calendar_schedule s ON s.id = b.calendar_schedule_id
                WHERE b.deleted_at IS NULL
                  AND s.deleted_at IS NULL
                  AND b.meeting_confirmed = TRUE
                  AND b.email = :email';

        $params = [':email' => $attendeeEmail];

        if ($ownScheduleIds !== []) {
            $placeholders = [];
            foreach (array_values($ownScheduleIds) as $i => $id) {
                $key = ':sid' . $i;
                $placeholders[] = $key;
                $params[$key] = $id;
            }
            $sql .= ' AND b.calendar_schedule_id NOT IN (' . implode(',', $placeholders) . ')';
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param DateTime    $from       Expiration range start
     * @param DateTime    $to         Expiration range end
     * @param string|null $scheduleId Optional schedule UUID filter
     * @return array<int, array<string, mixed>>
     */
    public function findPendingConfirmation(DateTime $from, DateTime $to, ?string $scheduleId = null): array
    {
        $sql = "SELECT b.id, b.calendar_schedule_id, b.datetime, b.duration,
                       b.timezone, b.expiration_of_confirmation
                FROM calendar_booking b
                INNER JOIN calendar_schedule s ON s.id = b.calendar_schedule_id AND s.deleted_at IS NULL
                WHERE b.deleted_at IS NULL
                  AND b.meeting_confirmed = FALSE
                  AND b.expiration_of_confirmation BETWEEN :from AND :to";

        $params = [
            ':from' => $from->format('Y-m-d H:i:s'),
            ':to'   => $to->format('Y-m-d H:i:s'),
        ];

        if ($scheduleId !== null) {
            $sql .= ' AND b.calendar_schedule_id = :schedule_id';
            $params[':schedule_id'] = $scheduleId;
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * IDs of confirmed, not-yet-past bookings for a schedule.
     *
     * @return list<string>
     */
    public function findConfirmedUpcomingIdsBySchedule(string $scheduleId): array
    {
        $scheduleId = trim($scheduleId);
        if ($scheduleId === '') {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id FROM ' . self::TABLE . '
             WHERE calendar_schedule_id = :sid
               AND deleted_at IS NULL
               AND meeting_confirmed = TRUE
               AND datetime >= NOW()
             ORDER BY datetime ASC'
        );
        $stmt->bindValue(':sid', $scheduleId, PDO::PARAM_STR);
        $stmt->execute();

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Confirmed upcoming bookings with a Google event — poll candidates.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findConfirmedUpcomingWithGoogleEvent(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, calendar_schedule_id, google_event_id, google_calendar_id, datetime, duration
             FROM " . self::TABLE . "
             WHERE deleted_at IS NULL
               AND meeting_confirmed = TRUE
               AND datetime >= NOW()
               AND google_event_id IS NOT NULL
               AND google_event_id <> ''
             ORDER BY datetime ASC
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param string   $scheduleId  Schedule UUID
     * @param string   $email       Booker email
     * @param string   $phone       Booker phone
     * @param string   $firstName   Booker first name
     * @param string   $lastName    Booker last name
     * @param string   $subject     Meeting subject
     * @param string   $description Meeting description
     * @param int      $duration    Duration in minutes
     * @param DateTime $datetime    Meeting datetime (timezone preserved, stored as UTC)
     * @return string  Created booking UUID
     */
    public function create(
        string $scheduleId,
        string $email,
        string $phone,
        string $firstName,
        string $lastName,
        string $subject,
        string $description,
        int $duration,
        DateTime $datetime
    ): string {
        $timezoneStr = $datetime->getTimezone()->getName();
        $datetimeUtc = (clone $datetime)->setTimezone(new DateTimeZone('UTC'));

        $sql = "INSERT INTO calendar_booking (
                    calendar_schedule_id, email, phone, first_name, last_name,
                    subject, description, duration, datetime, timezone, email_sent
                ) VALUES (
                    :schedule_id, :email, :phone, :first_name, :last_name,
                    :subject, :description, :duration, :datetime, :timezone, FALSE
                ) RETURNING id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':schedule_id', $scheduleId, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
        $stmt->bindValue(':first_name', $firstName, PDO::PARAM_STR);
        $stmt->bindValue(':last_name', $lastName, PDO::PARAM_STR);
        $stmt->bindValue(':subject', $subject, PDO::PARAM_STR);
        $stmt->bindValue(':description', $description, PDO::PARAM_STR);
        $stmt->bindValue(':duration', $duration, PDO::PARAM_INT);
        $stmt->bindValue(':datetime', $datetimeUtc->format('Y-m-d H:i:sP'), PDO::PARAM_STR);
        $stmt->bindValue(':timezone', $timezoneStr, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (string) $row['id'];
    }

    /**
     * @param string              $id   Booking UUID
     * @param array<string,mixed> $data Column-value pairs to update
     * @return string Updated booking UUID
     */
    public function update(string $id, array $data): string
    {
        $setClauses = [];
        $params = [':id' => $id];

        foreach ($data as $column => $value) {
            if (in_array($column, self::BLOCKED_UPDATE_COLUMNS, true)) {
                continue;
            }

            $setClauses[] = $column === 'pending_reschedule'
                ? "{$column} = :{$column}::jsonb"
                : "{$column} = :{$column}";

            $params[":{$column}"] = $value;
        }

        if ($setClauses === []) {
            return $id;
        }

        $sql = 'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $setClauses)
             . ' WHERE id = :id AND deleted_at IS NULL RETURNING id';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false && isset($row['id']) ? (string) $row['id'] : '';
    }

    /**
     * @param string $id Booking UUID
     * @return bool True if a row was soft-deleted
     */
    /**
     * Release pending (unconfirmed, non-expired) holds owned by the given
     * email on a schedule. Returns the number of rows released. Used to keep
     * a single attendee from hoarding multiple slots — the latest booking
     * attempt wins; older holds are soft-deleted so their slots reopen.
     */
    public function releasePendingHoldsForEmail(string $email, string $scheduleId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE calendar_booking
                SET deleted_at = NOW()
              WHERE deleted_at IS NULL
                AND meeting_confirmed = FALSE
                AND expiration_of_confirmation > NOW()
                AND calendar_schedule_id = :sid
                AND LOWER(email) = LOWER(:email)'
        );
        $stmt->bindValue(':sid', $scheduleId, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
        return (int) $stmt->rowCount();
    }

    public function softDelete(string $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->bindValue(':id', $id);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildSelectQuery(array $conditions): ?array
    {
        $where = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            if (!in_array($column, self::ALLOWED_FILTER_COLUMNS, true)) {
                continue;
            }
            $where[] = "{$column} = :{$column}";
            $params[":{$column}"] = $value;
        }

        if ($where === []) {
            return null;
        }

        $sql = 'SELECT * FROM ' . self::TABLE
             . ' WHERE ' . implode(' AND ', $where)
             . ' AND deleted_at IS NULL';

        return [$sql, $params];
    }
}
