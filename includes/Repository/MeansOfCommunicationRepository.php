<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Repository;

use PDO;

final class MeansOfCommunicationRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Find a means-of-communication record by UUID.
     *
     * @return array{id: string, title: string, created_at: string, updated_at: string}|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM calendar_means_of_communication WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Get visible means of communication. Currently restricted to Google Calendar
     * only — other titles (Microsoft Teams, Phone Call, In-person meeting) remain
     * in DB and code-paths for re-enable.
     *
     * @return array<int, array{id: string, title: string}>
     */
    public function getAll(): array
    {
        $sql = "SELECT id, title
                FROM calendar_means_of_communication
                WHERE title = 'Google Calendar'
                ORDER BY title";

        $stmt   = $this->pdo->query($sql);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $result ?: [];
    }

    /**
     * Create a new means-of-communication record.
     *
     * @return string The UUID of the created record.
     */
    public function create(string $title): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO calendar_means_of_communication (title) VALUES (:title) RETURNING id'
        );
        $stmt->bindValue(':title', $title, PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (string) $row['id'];
    }

    /**
     * Delete a means-of-communication record if no active schedules reference it.
     *
     * Returns false when the record is still referenced or does not exist.
     */
    public function delete(string $id): bool
    {
        $check = $this->pdo->prepare(
            'SELECT COUNT(*) FROM calendar_schedule
             WHERE deleted_at IS NULL AND calendar_means_of_communication_id = :id'
        );
        $check->bindValue(':id', $id, PDO::PARAM_STR);
        $check->execute();

        if ((int) $check->fetchColumn() > 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM calendar_means_of_communication WHERE id = :id'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }
}
