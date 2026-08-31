<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Service\Mail;

use Apexianlab\Calendar\Auth\GoogleOAuthHandler;
use PHPMailer\PHPMailer\PHPMailer;

final class GmailApiMailer
{
    private const ENDPOINT = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    /** Every failure path logs — otherwise a dead mailer is indistinguishable from a silent one. */
    private static function log(string $message): void
    {
        MailDebugLog::add($message);
    }

    public function send(
        string $ownerEmail,
        string $fromName,
        string $to,
        array $cc,
        string $subject,
        string $htmlBody,
        array $inlineImages
    ): bool {
        if ($ownerEmail === '' || $to === '') {
            self::log(sprintf('abort: empty address (owner="%s", to="%s")', $ownerEmail, $to));
            return false;
        }

        $token = GoogleOAuthHandler::getInstance()->getAccessTokenForScheduleEmail($ownerEmail);
        if ($token === null || $token === '') {
            self::log('abort: no access token for ' . $ownerEmail . ' — missing credentials row or refresh failed');
            return false;
        }

        $raw = $this->buildMime($ownerEmail, $fromName, $to, $cc, $subject, $htmlBody, $inlineImages);
        if ($raw === '') {
            self::log('abort: buildMime() returned empty for ' . $ownerEmail . ' -> ' . $to);
            return false;
        }

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode(['raw' => $this->base64Url($raw)]),
        ]);

        if (is_wp_error($response)) {
            self::log('transport error: ' . $response->get_error_message());
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            self::log(sprintf(
                'Gmail API HTTP %d for %s -> %s: %s',
                $code,
                $ownerEmail,
                $to,
                substr((string) wp_remote_retrieve_body($response), 0, 800)
            ));
            return false;
        }

        self::log(sprintf('sent %s -> %s ("%s")', $ownerEmail, $to, $subject));

        return true;
    }

    private function buildMime(
        string $fromEmail,
        string $fromName,
        string $to,
        array $cc,
        string $subject,
        string $htmlBody,
        array $inlineImages
    ): string {
        if (!class_exists(PHPMailer::class)) {
            require_once ABSPATH . 'wp-includes/PHPMailer/PHPMailer.php';
            require_once ABSPATH . 'wp-includes/PHPMailer/Exception.php';
        }

        $mail = new PHPMailer(true);

        try {
            $mail->CharSet  = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            foreach ($cc as $address) {
                $mail->addCC((string) $address);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;

            foreach ($inlineImages as $image) {
                $path = (string) ($image['path'] ?? '');
                $cid  = (string) ($image['cid'] ?? '');
                if ($path !== '' && $cid !== '' && is_readable($path)) {
                    $mail->addEmbeddedImage(
                        $path,
                        $cid,
                        (string) ($image['name'] ?? 'image'),
                        PHPMailer::ENCODING_BASE64,
                        (string) ($image['type'] ?? '')
                    );
                }
            }

            if (!$mail->preSend()) {
                self::log('buildMime: preSend() failed — ' . $mail->ErrorInfo);
                return '';
            }

            return $mail->getSentMIMEMessage();
        } catch (\Throwable $e) {
            self::log('buildMime: ' . get_class($e) . ' — ' . $e->getMessage());
            return '';
        }
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
