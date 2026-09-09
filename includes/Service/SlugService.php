<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Service;

use Apexianlab\Calendar\Repository\SlugRepository;

/**
 * Generates and resolves stable, unique public URL slugs for schedule owners.
 */
final class SlugService
{
    /**
     * Slugs that would collide with real WordPress pages or system routes.
     * A generated slug equal to one of these is given a numeric suffix.
     */
    private const RESERVED = [
        'cal', 'schedule', 'ai-assistant', 'oauth2', 'wp-admin', 'wp-login',
        'wp-content', 'wp-includes', 'wp-json', 'index', 'about', 'services',
        'blog', 'contact', 'home', 'projects', 'career', 'testimonials',
        'our-industries', 'privacy-policy', 'cookie-policy', 'feed', 'admin',
    ];

    public function __construct(private readonly SlugRepository $repository)
    {
    }

    /**
     * Normalise a person's name into a slug body: first letter of the first
     * token + the last token, ASCII, lowercase, [a-z0-9-] only.
     * Returns '' when nothing usable remains (caller must fall back).
     */
    public function slugify(string $name): string
    {
        $ascii = $this->toAscii($name);
        $ascii = strtolower($ascii);
        $tokens = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return '';
        }
        if (count($tokens) === 1) {
            return $tokens[0];
        }

        $first = $tokens[0];
        $last  = $tokens[count($tokens) - 1];

        return substr($first, 0, 1) . $last;
    }

    /**
     * Return the owner's existing slug, or generate, persist, and return a
     * new unique one. Never rewrites an existing mapping.
     */
    public function ensureSlug(string $email, string $fullName): string
    {
        $email = trim($email);
        if ($email === '') {
            return '';
        }

        $existing = $this->repository->findByEmail($email);
        if ($existing !== null) {
            return $existing['slug'];
        }

        $base = $this->slugify($fullName);
        if ($base === '') {
            $base = $this->slugify((string) (explode('@', $email, 2)[0] ?? ''));
        }
        if ($base === '') {
            $base = 'user';
        }

        $slug     = $base;
        $counter  = 1;
        $attempts = 0;
        while (true) {
            while ($this->isUnavailable($slug)) {
                $counter++;
                $slug = $base . $counter;
            }
            try {
                $this->repository->create($email, $slug);
                break;
            } catch (\Throwable $e) {
                // The slug was taken by a concurrent insert between the
                // availability check and ours — advance to the next suffix.
                // (An email conflict cannot reach here: create() uses
                // ON CONFLICT (email) DO NOTHING.)
                if (++$attempts > 100) {
                    throw $e;
                }
                $counter++;
                $slug = $base . $counter;
            }
        }

        // Re-read: a concurrent login may have won the race and inserted a
        // different slug for this email (INSERT used ON CONFLICT DO NOTHING).
        $stored = $this->repository->findByEmail($email);

        return $stored !== null ? $stored['slug'] : $slug;
    }

    /**
     * Read-only lookup of an owner's slug. Empty string when none exists.
     */
    public function slugForEmail(string $email): string
    {
        $row = $this->repository->findByEmail(trim($email));

        return $row !== null ? $row['slug'] : '';
    }

    private function isUnavailable(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true) || $this->repository->slugExists($slug);
    }

    private function toAscii(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('transliterator_transliterate')) {
            $out = transliterator_transliterate('Any-Latin; Latin-ASCII; [^\\x20-\\x7E] remove', $value);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        if (function_exists('iconv')) {
            $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        return $value;
    }
}
