<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Repository;

use PDO;
use Apexianlab\Calendar\Config;
use Apexianlab\Calendar\Database\Connection;

final class CredentialsRepository
{
    private PDO $pdo;
    private Connection $connection;

    public function __construct(PDO $pdo, Connection $connection)
    {
        $this->pdo = $pdo;
        $this->connection = $connection;
    }

    /**
     * Get Google Calendar credentials by schedule email.
     *
     * @return array{schedule_email: string, google_user_id: string, google_email: string, access_token: string, refresh_token: string|null, token_expires_at: string}|null
     */
    public function getGoogleByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT schedule_email, google_user_id, google_email,
                    access_token_enc, refresh_token_enc, token_expires_at,
                    selected_calendar_id
             FROM calendar_google_credentials
             WHERE schedule_email = :email
             LIMIT 1'
        );
        $stmt->bindValue(':email', $email);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'schedule_email'       => $row['schedule_email'],
            'google_user_id'       => $row['google_user_id'],
            'google_email'         => $row['google_email'],
            'access_token'         => $this->connection->decryptSecret($row['access_token_enc']),
            'refresh_token'        => $row['refresh_token_enc'] !== null
                ? $this->connection->decryptSecret($row['refresh_token_enc'])
                : null,
            'token_expires_at'     => $row['token_expires_at'],
            'selected_calendar_id' => $row['selected_calendar_id'] ?? null,
        ];
    }

    /**
     * Granted OAuth scope string, or null. Isolated + guarded so a missing
     * `granted_scopes` column (migration not yet applied) never breaks the
     * core credential read used by all calendar/email features.
     */
    public function getGrantedScopes(string $email): ?string
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT granted_scopes FROM calendar_google_credentials WHERE schedule_email = :email LIMIT 1'
            );
            $stmt->bindValue(':email', $email);
            $stmt->execute();
            $value = $stmt->fetchColumn();

            return ($value === false || $value === null) ? null : (string) $value;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Persist the owner's chosen Google calendar id (or '' / null to reset to
     * the account primary). No-op when the credential row doesn't exist.
     */
    public function setGoogleSelectedCalendar(string $email, ?string $calendarId): bool
    {
        $email = trim($email);
        if ($email === '') {
            return false;
        }
        $calendarId = ($calendarId !== null && trim($calendarId) !== '') ? trim($calendarId) : null;

        $stmt = $this->pdo->prepare(
            'UPDATE calendar_google_credentials
                SET selected_calendar_id = :cal, updated_at = NOW()
              WHERE schedule_email = :email'
        );
        $stmt->bindValue(':cal', $calendarId);
        $stmt->bindValue(':email', $email);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Insert or update Google Calendar credentials for the given schedule email.
     */
    public function upsertGoogle(
        string $email,
        string $googleUserId,
        string $googleEmail,
        string $accessToken,
        ?string $refreshToken,
        int $expiresAt,
        ?string $grantedScopes = null
    ): bool {
        $email = trim($email);
        if ($email === '' || $googleUserId === '' || $accessToken === '') {
            return false;
        }

        $accessTokenEnc  = $this->connection->encryptSecret($accessToken);
        $refreshTokenEnc = ($refreshToken !== null && $refreshToken !== '')
            ? $this->connection->encryptSecret($refreshToken)
            : null;

        $sql = 'INSERT INTO calendar_google_credentials
                    (schedule_email, google_user_id, google_email, access_token_enc, refresh_token_enc, token_expires_at)
                VALUES
                    (:schedule_email, :google_user_id, :google_email, :access_token_enc, :refresh_token_enc, to_timestamp(:expires_at))
                ON CONFLICT (schedule_email)
                DO UPDATE SET google_user_id    = EXCLUDED.google_user_id,
                              google_email      = EXCLUDED.google_email,
                              access_token_enc  = EXCLUDED.access_token_enc,
                              refresh_token_enc = COALESCE(EXCLUDED.refresh_token_enc, calendar_google_credentials.refresh_token_enc),
                              token_expires_at  = EXCLUDED.token_expires_at,
                              updated_at        = NOW()';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':schedule_email', $email);
        $stmt->bindValue(':google_user_id', $googleUserId);
        $stmt->bindValue(':google_email', $googleEmail);
        $stmt->bindValue(':access_token_enc', $accessTokenEnc);
        $stmt->bindValue(':refresh_token_enc', $refreshTokenEnc);
        $stmt->bindValue(':expires_at', $expiresAt, PDO::PARAM_INT);
        $stmt->execute();
        $ok = $stmt->rowCount() > 0;

        // Best-effort: store granted scopes separately so a missing
        // `granted_scopes` column never breaks token persistence (login).
        if ($grantedScopes !== null && trim($grantedScopes) !== '') {
            try {
                $upd = $this->pdo->prepare(
                    'UPDATE calendar_google_credentials SET granted_scopes = :scopes WHERE schedule_email = :email'
                );
                $upd->bindValue(':scopes', trim($grantedScopes));
                $upd->bindValue(':email', $email);
                $upd->execute();
            } catch (\Throwable $e) {
                // column not present yet — ignore
            }
        }

        return $ok;
    }

    /**
     * Delete Google credentials for the given schedule email.
     */
    public function deleteGoogle(string $email): bool
    {
        $email = trim($email);
        if ($email === '') {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM calendar_google_credentials WHERE schedule_email = :email');
        $stmt->bindValue(':email', $email);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }
}
