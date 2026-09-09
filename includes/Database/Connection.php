<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Database;

use PDO;
use PDOException;
use Apexianlab\Calendar\Config;

final class Connection
{
    private static ?self $instance = null;
    private PDO $pdo;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $dsn = sprintf('pgsql:host=%s;dbname=%s', Config::getPostgresqlHost(), Config::getPostgresqlDbname());

        try {
            $this->pdo = new PDO($dsn, Config::getPostgresqlUser(), Config::getPostgresqlPassword());
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- 3rd arg $e is the previous-exception for chaining, not output; message is escaped.
            throw new \RuntimeException('Cannot connect to PostgreSQL: ' . esc_html($e->getMessage()), 0, $e);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * AES-256-GCM encryption keyed by WP auth salt.
     */
    public function encryptSecret(string $plain): string
    {
        if ($plain === '') {
            throw new \InvalidArgumentException('Cannot encrypt empty secret');
        }

        $key = hash('sha256', wp_salt('auth') . '|apexianlab-meeting-scheduler|caldav', true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false || $tag === '') {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public function decryptSecret(string $encoded): string
    {
        if ($encoded === '') {
            throw new \InvalidArgumentException('Cannot decrypt empty secret');
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Invalid encrypted payload');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $key = hash('sha256', wp_salt('auth') . '|apexianlab-meeting-scheduler|caldav', true);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $plain;
    }
}
