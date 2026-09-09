<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Repository;

use PDO;

/**
 * Maps schedule-owner emails to unique public URL slugs.
 */
final class SlugRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{email: string, slug: string}|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT email, slug FROM calendar_user_slug WHERE email = :email LIMIT 1'
        );
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['email' => (string) $row['email'], 'slug' => (string) $row['slug']] : null;
    }

    /**
     * @return array{email: string, slug: string}|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT email, slug FROM calendar_user_slug WHERE slug = :slug LIMIT 1'
        );
        $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['email' => (string) $row['email'], 'slug' => (string) $row['slug']] : null;
    }

    public function slugExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM calendar_user_slug WHERE slug = :slug LIMIT 1');
        $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    public function create(string $email, string $slug): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO calendar_user_slug (email, slug) VALUES (:email, :slug)
             ON CONFLICT (email) DO NOTHING'
        );
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
        $stmt->execute();
    }
}
