<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Service;

use DateTime;
use DateTimeZone;
use Apexianlab\Calendar\Config;
use Apexianlab\Calendar\Database\Connection;
use Apexianlab\Calendar\Helper\DisplayHelper;
use Apexianlab\Calendar\Helper\QrCodeGenerator;
use Apexianlab\Calendar\Helper\TimeHelper;
use Apexianlab\Calendar\Repository\BookingRepository;
use Apexianlab\Calendar\Repository\CredentialsRepository;
use Apexianlab\Calendar\Repository\MeansOfCommunicationRepository;
use Apexianlab\Calendar\Repository\ScheduleRepository;
use Apexianlab\Calendar\Service\Mail\GmailApiMailer;
use Apexianlab\Calendar\Service\Mail\MailDebugLog;

final class NotificationService
{
    private ?GmailApiMailer $gmailMailer = null;

    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly ScheduleRepository $scheduleRepository,
        private readonly MeansOfCommunicationRepository $meansRepository,
    ) {
    }

    /**
     * Load meeting, schedule, means, date, and derived display values for a booking.
     *
     * @return array{meeting: array<string,mixed>, schedule: array<string,mixed>, means: array<string,mixed>|null, date: DateTime, scheduleName: string, locationTitle: string, scheduleDescription: string}|null
     */
    private function loadBookingContext(string $meetingId, bool $anyState = false, bool $applyPending = false): ?array
    {
        $meeting = $anyState
            ? $this->bookingRepository->findByIdAnyState($meetingId)
            : $this->bookingRepository->findById($meetingId);
        if ($meeting === null) {
            return null;
        }

        // For the reschedule confirmation email, overlay the parked request so
        // the email reflects what the attendee is about to confirm. Every
        // other email keeps the live (pre-reschedule) row.
        if ($applyPending && !empty($meeting['pending_reschedule'])) {
            $pending = json_decode((string) $meeting['pending_reschedule'], true);
            if (is_array($pending)) {
                $meeting = array_merge($meeting, $pending);
            }
        }

        $schedule = $this->scheduleRepository->findById((string) $meeting['calendar_schedule_id']);
        if ($schedule === null) {
            return null;
        }

        $means = $this->meansRepository->findById((string) $schedule['calendar_means_of_communication_id']);

        $date = new DateTime((string) $meeting['datetime'], new DateTimeZone('UTC'));
        if (!empty($meeting['timezone'])) {
            $date->setTimezone(new DateTimeZone(TimeHelper::canonicalTimezone((string) $meeting['timezone'])));
        }

        $scheduleDescription = trim((string) ($schedule['description'] ?? ''));
        if ($scheduleDescription === '') {
            $scheduleDescription = trim((string) ($meeting['description'] ?? ''));
        }

        $scheduleName = DisplayHelper::scheduleOrganiserName($schedule);
        if (trim($scheduleName) === '') {
            $scheduleName = 'Meeting';
        }

        $locationTitle = trim((string) ($means['title'] ?? ''));
        if ($locationTitle === '') {
            $locationTitle = 'Location';
        }

        return compact('meeting', 'schedule', 'means', 'date', 'scheduleName', 'locationTitle', 'scheduleDescription');
    }

    /**
     * Wrapper for callsite compat — canonical resolution in TimeHelper.
     */
    private function organiserTimezone(array $schedule): string
    {
        return TimeHelper::organiserTimezoneFromSchedule($schedule);
    }

    /**
     * Resolve per-host SMTP config for the given schedule email.
     * Returns array with smtp_host/smtp_port/smtp_encryption/username/password
     * or null if no SMTP host configured for the owner's mail host.
     *
     * @return array{smtp_host: string, smtp_port: int, smtp_encryption: string, username: string, password: string}|null
     */
    private function resolveSmtpConfig(string $scheduleEmail): ?array
    {
        // CalDAV removed: no per-host SMTP. Falls back to the global SMTP_HOST
        // configured in wp-config (handled by Plugin::registerMailFilters()).
        return null;
    }

    /**
     * Send email via wp_mail, temporarily configuring PHPMailer to use the
     * schedule owner's per-host SMTP credentials if available, and to embed
     * any CID images.
     *
     * @param array<int, string>                                                  $headers
     * @param array<int, array{path: string, cid: string, name?: string, type?: string}> $embeddedImages
     */
    private function sendMail(
        array $schedule,
        string $to,
        string $subject,
        string $message,
        array $headers,
        array $embeddedImages = []
    ): bool {
        $cc = $this->ccFromHeaders($headers);

        $ownerEmail = trim((string) ($schedule['email'] ?? ''));
        if ($ownerEmail !== '' && $this->gmailMailer()->send(
            $ownerEmail,
            DisplayHelper::scheduleOrganiserName($schedule),
            $to,
            $cc,
            $subject,
            $message,
            $embeddedImages
        )) {
            return true;
        }

        $smtp          = $this->resolveSmtpConfig((string) ($schedule['email'] ?? ''));
        $smtpCallback  = null;
        $imageCallback = null;

        // Skip per-schedule SMTP when a local mail catcher is active (SMTP_HOST is defined
        // and reachable — Plugin.php's phpmailer_init at lower priority handles that path).
        if ($smtp !== null && !(defined('SMTP_HOST') && SMTP_HOST !== '') && $this->isSmtpReachable($smtp['smtp_host'], $smtp['smtp_port'])) {
            $smtpCallback = static function ($phpmailer) use ($smtp): void {
                if (!is_object($phpmailer)) {
                    return;
                }
                $phpmailer->isSMTP();
                $phpmailer->Host        = $smtp['smtp_host'];
                $phpmailer->Port        = $smtp['smtp_port'];
                $phpmailer->SMTPAuth    = true;
                $phpmailer->Username    = $smtp['username'];
                $phpmailer->Password    = $smtp['password'];
                $phpmailer->SMTPSecure  = $smtp['smtp_encryption'];
                $phpmailer->SMTPAutoTLS = $smtp['smtp_encryption'] === 'tls';
            };
            add_action('phpmailer_init', $smtpCallback, 100);
        }

        if ($embeddedImages !== []) {
            $imageCallback = static function ($phpmailer) use ($embeddedImages): void {
                if (!is_object($phpmailer)) {
                    return;
                }
                foreach ($embeddedImages as $img) {
                    if (!empty($img['path']) && is_readable($img['path']) && !empty($img['cid'])) {
                        $phpmailer->addEmbeddedImage(
                            $img['path'],
                            $img['cid'],
                            (string) ($img['name'] ?? 'image'),
                            'base64',
                            (string) ($img['type'] ?? '')
                        );
                    }
                }
            };
            add_action('phpmailer_init', $imageCallback, 100);
        }

        $mailerError = '';
        $failedHook  = static function (\WP_Error $error) use (&$mailerError): void {
            $mailerError = $error->get_error_message();
        };
        add_action('wp_mail_failed', $failedHook);

        try {
            $sent = (bool) wp_mail($to, $subject, $message, $headers);

            // Per-schedule SMTP failed with a connection-level error — retry via default transport
            // and cache the host as unreachable for 2 minutes to skip the attempt next time.
            if (!$sent && $smtpCallback !== null && $mailerError !== '' && stripos($mailerError, 'connect') !== false) {
                remove_action('phpmailer_init', $smtpCallback, 100);
                $smtpCallback = null;
                set_transient('apexianlab_smtp_reach_' . md5($smtp['smtp_host'] . ':' . $smtp['smtp_port']), '0', 2 * MINUTE_IN_SECONDS);
                $mailerError = '';
                $sent = (bool) wp_mail($to, $subject, $message, $headers);
            }

            if (!$sent) {
                MailDebugLog::add('wp_mail fallback failed for ' . $to
                    . ' — ' . ($mailerError !== '' ? $mailerError : 'no wp_mail_failed error reported')
                    . ' (SMTP_HOST ' . (defined('SMTP_HOST') && SMTP_HOST !== '' ? 'set' : 'not set') . ')');
            }

            return $sent;
        } finally {
            remove_action('wp_mail_failed', $failedHook);
            if ($smtpCallback !== null) {
                remove_action('phpmailer_init', $smtpCallback, 100);
            }
            if ($imageCallback !== null) {
                remove_action('phpmailer_init', $imageCallback, 100);
            }
        }
    }

    private function gmailMailer(): GmailApiMailer
    {
        return $this->gmailMailer ??= new GmailApiMailer();
    }

    private function buildFromHeader(array $schedule): string
    {
        $email = trim((string) ($schedule['email'] ?? ''));
        if ($email === '') {
            return '';
        }
        $name = DisplayHelper::scheduleOrganiserName($schedule);
        if ($name === '') {
            return 'From: ' . $email;
        }
        if (preg_match('/[^\x20-\x7e]/', $name)) {
            return 'From: =?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
        }
        $quoted = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
        return 'From: ' . $quoted . ' <' . $email . '>';
    }

    /**
     * Build a "Cc:" header from the schedule's additional_recipients column so
     * the configured copy-list receives the organiser-facing notifications.
     * Excludes the organiser's own address (already the To) and any local/test
     * domains. Returns an empty array when there is nothing to add.
     *
     * @return array<int, string>
     */
    private function buildCcHeaders(array $schedule): array
    {
        $cc = $this->ccRecipients($schedule);

        return $cc === [] ? [] : ['Cc: ' . implode(', ', $cc)];
    }

    /**
     * @param array<int, string> $headers
     * @return array<int, string>
     */
    private function ccFromHeaders(array $headers): array
    {
        $cc = [];
        foreach ($headers as $header) {
            if (stripos($header, 'Cc:') !== 0) {
                continue;
            }
            foreach (explode(',', substr($header, 3)) as $addr) {
                $addr = trim($addr);
                if ($addr !== '') {
                    $cc[] = $addr;
                }
            }
        }

        return $cc;
    }

    private function ccRecipients(array $schedule): array
    {
        $raw = $schedule['additional_recipients'] ?? null;
        $list = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($list) || $list === []) {
            return [];
        }

        $owner = strtolower(trim((string) ($schedule['email'] ?? '')));
        $clean = [];
        foreach ($list as $entry) {
            $email = sanitize_email((string) $entry);
            if ($email === '' || !is_email($email)) {
                continue;
            }
            $lower = strtolower($email);
            if ($lower === $owner || $this->isLocalEmail($lower)) {
                continue;
            }
            $clean[$lower] = $email;
        }

        return array_values($clean);
    }

    public function sendBookingConfirmEmail(string $meetingId, ?DateTime $previousDate = null, ?string $subjectOverride = null, bool $isScheduleUpdate = false): bool
    {
        $ctx = $this->loadBookingContext($meetingId);
        if ($ctx === null) {
            return false;
        }

        ['meeting' => $meeting, 'schedule' => $schedule, 'date' => $date,
         'scheduleName' => $scheduleName, 'locationTitle' => $locationTitle,
         'scheduleDescription' => $scheduleDescription] = $ctx;

        $targetEmail  = (string) $meeting['email'];
        $emailSubject = $subjectOverride ?? ($previousDate !== null ? 'Meeting rescheduled' : 'Booking confirmed');

        // Show the previous slot in the same timezone as the new one so the
        // attendee compares like-for-like wall-clock times.
        $previousDateLocal = $previousDate !== null
            ? (clone $previousDate)->setTimezone($date->getTimezone())
            : null;

        $detailLink = DisplayHelper::bookingUrl($schedule, [
            'meeting_id' => (string) $meeting['id'],
            'frame'      => 'confirmed',
            'for'        => 'attendee',
        ]);
        $rescheduleLink = DisplayHelper::bookingUrl($schedule, [
            'meeting_id' => (string) $meeting['id'],
            'reschedule' => '1',
            'for'        => 'attendee',
        ]);
        $cancelLink = DisplayHelper::bookingUrl($schedule, [
            'meeting_id' => (string) $meeting['id'],
            'frame'      => '5',
            'for'        => 'attendee',
        ]);

        $locationLink = (string) ($meeting['meeting_join_url'] ?? '');

        $templatePath = (defined('APEXIANLAB_MEETING_SCHEDULER_PATH') ? APEXIANLAB_MEETING_SCHEDULER_PATH : '')
            . 'templates/email/message-booking-confirmed.php';

        ob_start();
        $args = [
            'subject' => (string) ($schedule['subject'] ?? ''),
            'email_heading' => $emailSubject,
            'target_name' => trim((string) ($meeting['first_name'] ?? '') . ' ' . (string) ($meeting['last_name'] ?? '')),
            'phone' => (string) ($meeting['phone'] ?? ''),
            'description' => $scheduleDescription,
            'name' => $scheduleName,
            'author_email' => (string) ($schedule['email'] ?? ''),
            'date' => $date,
            'duration' => $meeting['duration'],
            'location' => $locationTitle,
            'link' => $locationLink,
            'detail_link' => $detailLink,
            'reschedule_link' => $rescheduleLink,
            'cancel_link' => $cancelLink,
            'previous_date'      => $previousDateLocal,
            'is_schedule_update' => $isScheduleUpdate,
        ];

        if (is_readable($templatePath)) {
            include $templatePath;
        } else {
            get_template_part('template-part/message', 'booking-confirmed', $args);
        }

        $emailMessage = (string) ob_get_clean();
        $emailHeaders = ['Content-Type: text/html; charset=UTF-8'];
        $fromHeader = $this->buildFromHeader($schedule);
        if ($fromHeader !== '') {
            $emailHeaders[] = $fromHeader;
        }

        if (trim($emailMessage) === '') {
            $startDate = clone $date;
            $endDate = clone $date;
            $endDate->modify('+' . (int) $meeting['duration'] . ' minutes');

            $emailMessage = '<p>Your booking is confirmed.</p>'
                . '<p><strong>' . esc_html($scheduleName) . '</strong></p>'
                . '<p>' . esc_html(DisplayHelper::formatMeetingDateRange($startDate, (int) $meeting['duration'])) . '<br />'
                . esc_html($startDate->format('g:i a') . ' - ' . $endDate->format('g:i a') . ($endDate->format('Y-m-d') !== $startDate->format('Y-m-d') ? ' (' . $endDate->format('M j') . ')' : '')) . '</p>'
                . '<p>' . esc_html($locationTitle) . '</p>'
                . '<p><a href="' . esc_url($detailLink) . '">View details</a></p>';
        }

        if ($this->isLocalEmail($targetEmail)) {
            return true;
        }

        $sent = $this->sendMail($schedule, $targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        if (!$sent) {
            $this->logEmail($targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        }

        return $sent;
    }

    public function sendBookingConfirmEmailToOrganiser(string $meetingId, ?DateTime $previousDate = null, ?string $subjectOverride = null): bool
    {
        $ctx = $this->loadBookingContext($meetingId);
        if ($ctx === null) {
            return false;
        }

        ['meeting' => $meeting, 'schedule' => $schedule, 'date' => $date,
         'scheduleName' => $scheduleName, 'locationTitle' => $locationTitle,
         'scheduleDescription' => $scheduleDescription] = $ctx;

        // Re-anchor to organiser timezone — the loaded $date is in the
        // attendee's tz (booking.timezone). The organiser inbox should
        // show their own local wall-clock, not the booker's.
        $organiserTz = $this->organiserTimezone($schedule);
        if ($organiserTz !== '') {
            $date = (clone $date)->setTimezone(new DateTimeZone($organiserTz));
        }

        // Show the previous slot in the same timezone as the new one.
        $previousDateLocal = $previousDate !== null
            ? (clone $previousDate)->setTimezone($date->getTimezone())
            : null;

        $targetEmail = (string) ($schedule['email'] ?? '');
        if ($targetEmail === '') {
            return false;
        }

        $emailSubject = $subjectOverride ?? ($previousDate !== null
            ? 'Meeting rescheduled'
            : 'You have a new confirmed meeting');

        $detailLink = DisplayHelper::bookingUrl($schedule, [
            'meeting_id' => (string) $meeting['id'],
            'frame'      => 'confirmed',
            'for'        => 'organiser',
        ]);

        $templatePath = (defined('APEXIANLAB_MEETING_SCHEDULER_PATH') ? APEXIANLAB_MEETING_SCHEDULER_PATH : '')
            . 'templates/email/message-booking-confirmed-organiser.php';

        ob_start();
        $args = [
            'email_heading'  => $emailSubject,
            'name'           => $scheduleName,
            'description'    => $scheduleDescription,
            'attendee_name'  => trim((string) ($meeting['first_name'] ?? '') . ' ' . (string) ($meeting['last_name'] ?? '')),
            'attendee_email' => (string) ($meeting['email'] ?? ''),
            'attendee_phone' => (string) ($meeting['phone'] ?? ''),
            'date'           => $date,
            'duration'       => $meeting['duration'],
            'location'       => $locationTitle,
            'join_url'       => (string) ($meeting['meeting_join_url'] ?? ''),
            'detail_link'    => $detailLink,
            'previous_date'  => $previousDateLocal,
        ];

        if (is_readable($templatePath)) {
            include $templatePath;
        } else {
            get_template_part('template-part/message', 'booking-confirmed-organiser', $args);
        }

        $emailMessage = (string) ob_get_clean();
        $emailHeaders = ['Content-Type: text/html; charset=UTF-8'];
        $fromHeader = $this->buildFromHeader($schedule);
        if ($fromHeader !== '') {
            $emailHeaders[] = $fromHeader;
        }
        $emailHeaders = array_merge($emailHeaders, $this->buildCcHeaders($schedule));

        if (trim($emailMessage) === '') {
            return false;
        }

        if ($this->isLocalEmail($targetEmail)) {
            return true;
        }

        $sent = $this->sendMail($schedule, $targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        if (!$sent) {
            $this->logEmail($targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        }

        return $sent;
    }

    /**
     * Send "meeting rescheduled" emails to both organiser and attendee after
     * an external reschedule was applied automatically.
     */
    public function sendExternalRescheduledEmails(string $meetingId): void
    {
        $booking = $this->bookingRepository->findById($meetingId);
        if ($booking === null) {
            return;
        }

        $previousDate = null;
        if (!empty($booking['previous_datetime'])) {
            try {
                $previousDate = new DateTime((string) $booking['previous_datetime']);
            } catch (\Throwable $e) {
            }
        }

        try {
            $this->sendBookingConfirmEmailToOrganiser($meetingId, $previousDate);
        } catch (\Throwable $e) {
            error_log('[Apexianlab ExtSync] Reschedule organiser email failed (' . $meetingId . '): ' . $e->getMessage());
        }

        try {
            $this->sendBookingConfirmEmail($meetingId, $previousDate);
        } catch (\Throwable $e) {
            error_log('[Apexianlab ExtSync] Reschedule attendee email failed (' . $meetingId . '): ' . $e->getMessage());
        }
    }

    /**
     * Notify the schedule owner that their new schedule is ready, including
     * the public booking link and a scannable QR code of that link.
     */
    public function sendScheduleUpdatedEmail(string $meetingId): bool
    {
        return $this->sendBookingConfirmEmail($meetingId, null, 'Meeting details updated', true);
    }

    public function sendScheduleUpdatedEmailToOrganiser(string $meetingId): bool
    {
        return $this->sendBookingConfirmEmailToOrganiser($meetingId, null, 'Meeting details updated');
    }

    public function sendScheduleCreatedEmail(string $scheduleId): bool
    {
        $schedule = $this->scheduleRepository->findById($scheduleId);
        if (!is_array($schedule)) {
            return false;
        }

        $targetEmail = trim((string) ($schedule['email'] ?? ''));
        if ($targetEmail === '') {
            return false;
        }

        $scheduleLink = DisplayHelper::bookingUrl($schedule);
        if ($scheduleLink === '') {
            // No public short id — cannot build a shareable link; skip.
            return false;
        }

        // The email names the schedule itself ("A new schedule X"), so use
        // the schedule title (subject), not the owner's display name.
        $scheduleName = trim((string) ($schedule['subject'] ?? ''));
        if ($scheduleName === '') {
            $scheduleName = trim((string) ($schedule['name'] ?? 'Your schedule'));
        }

        // Means of communication title.
        $location = '';
        $meansId  = (string) ($schedule['calendar_means_of_communication_id'] ?? '');
        if ($meansId !== '') {
            $means = $this->meansRepository->findById($meansId);
            if (is_array($means)) {
                $location = (string) ($means['title'] ?? '');
            }
        }

        // Durations array from the JSONB column.
        $durations   = [];
        $durationRaw = $schedule['durations'] ?? null;
        $decoded     = is_string($durationRaw) ? json_decode($durationRaw, true) : $durationRaw;
        if (is_array($decoded)) {
            foreach ($decoded as $d) {
                $n = (int) $d;
                if ($n > 0) {
                    $durations[] = $n;
                }
            }
        }

        // QR code PNG of the booking link (best-effort).
        $qrPath = QrCodeGenerator::toPngFile($scheduleLink);
        $qrCid  = $qrPath !== null ? 'schedule-qr' : '';

        $emailSubject = 'Your schedule is ready';
        $templatePath = (defined('APEXIANLAB_MEETING_SCHEDULER_PATH') ? APEXIANLAB_MEETING_SCHEDULER_PATH : '')
            . 'templates/email/message-schedule-created.php';

        ob_start();
        $args = [
            'email_heading'  => $emailSubject,
            'name'           => $scheduleName,
            'organiser_name' => DisplayHelper::scheduleOrganiserName($schedule),
            'description'    => (string) ($schedule['description'] ?? ''),
            'location'      => $location,
            'durations'     => $durations,
            'schedule_link' => $scheduleLink,
            'qr_cid'        => $qrCid,
        ];

        if (is_readable($templatePath)) {
            include $templatePath;
        }
        $emailMessage = (string) ob_get_clean();

        if (trim($emailMessage) === '') {
            if ($qrPath !== null) {
                @unlink($qrPath);
            }
            return false;
        }

        $emailHeaders = ['Content-Type: text/html; charset=UTF-8'];
        $fromHeader   = $this->buildFromHeader($schedule);
        if ($fromHeader !== '') {
            $emailHeaders[] = $fromHeader;
        }

        if ($this->isLocalEmail($targetEmail)) {
            if ($qrPath !== null) {
                @unlink($qrPath);
            }
            return true;
        }

        $embeddedImages = $qrPath !== null
            ? [['path' => $qrPath, 'cid' => $qrCid, 'name' => 'schedule-qr.png', 'type' => 'image/png']]
            : [];

        try {
            $sent = $this->sendMail(
                $schedule,
                $targetEmail,
                $emailSubject,
                $emailMessage,
                $emailHeaders,
                $embeddedImages
            );
        } finally {
            if ($qrPath !== null) {
                @unlink($qrPath);
            }
        }

        if (!$sent) {
            $this->logEmail($targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        }

        return $sent;
    }

    public function sendScheduleUpdatedNotification(string $scheduleId): bool
    {
        $schedule = $this->scheduleRepository->findById($scheduleId);
        if (!is_array($schedule)) {
            return false;
        }

        $targetEmail = trim((string) ($schedule['email'] ?? ''));
        if ($targetEmail === '') {
            return false;
        }

        $scheduleLink = DisplayHelper::bookingUrl($schedule);
        if ($scheduleLink === '') {
            return false;
        }

        $scheduleName = trim((string) ($schedule['subject'] ?? ''));
        if ($scheduleName === '') {
            $scheduleName = trim((string) ($schedule['name'] ?? 'Your schedule'));
        }

        $location = '';
        $meansId  = (string) ($schedule['calendar_means_of_communication_id'] ?? '');
        if ($meansId !== '') {
            $means = $this->meansRepository->findById($meansId);
            if (is_array($means)) {
                $location = (string) ($means['title'] ?? '');
            }
        }

        $durations   = [];
        $durationRaw = $schedule['durations'] ?? null;
        $decoded     = is_string($durationRaw) ? json_decode($durationRaw, true) : $durationRaw;
        if (is_array($decoded)) {
            foreach ($decoded as $d) {
                $n = (int) $d;
                if ($n > 0) {
                    $durations[] = $n;
                }
            }
        }

        $qrPath = QrCodeGenerator::toPngFile($scheduleLink);
        $qrCid  = $qrPath !== null ? 'schedule-qr' : '';

        $emailSubject = 'Your schedule has been updated';
        $templatePath = (defined('APEXIANLAB_MEETING_SCHEDULER_PATH') ? APEXIANLAB_MEETING_SCHEDULER_PATH : '')
            . 'templates/email/message-schedule-updated.php';

        ob_start();
        $args = [
            'email_heading'  => $emailSubject,
            'name'           => $scheduleName,
            'organiser_name' => DisplayHelper::scheduleOrganiserName($schedule),
            'description'    => (string) ($schedule['description'] ?? ''),
            'location'      => $location,
            'durations'     => $durations,
            'schedule_link' => $scheduleLink,
            'qr_cid'        => $qrCid,
        ];

        if (is_readable($templatePath)) {
            include $templatePath;
        }
        $emailMessage = (string) ob_get_clean();

        if (trim($emailMessage) === '') {
            if ($qrPath !== null) {
                @unlink($qrPath);
            }
            return false;
        }

        $emailHeaders = ['Content-Type: text/html; charset=UTF-8'];
        $fromHeader   = $this->buildFromHeader($schedule);
        if ($fromHeader !== '') {
            $emailHeaders[] = $fromHeader;
        }
        $emailHeaders = array_merge($emailHeaders, $this->buildCcHeaders($schedule));

        if ($this->isLocalEmail($targetEmail)) {
            if ($qrPath !== null) {
                @unlink($qrPath);
            }
            return true;
        }

        $embeddedImages = $qrPath !== null
            ? [['path' => $qrPath, 'cid' => $qrCid, 'name' => 'schedule-qr.png', 'type' => 'image/png']]
            : [];

        try {
            $sent = $this->sendMail(
                $schedule,
                $targetEmail,
                $emailSubject,
                $emailMessage,
                $emailHeaders,
                $embeddedImages
            );
        } finally {
            if ($qrPath !== null) {
                @unlink($qrPath);
            }
        }

        if (!$sent) {
            $this->logEmail($targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        }

        return $sent;
    }

    /**
     * Notify the schedule owner that a meeting on their calendar was cancelled.
     * Uses any-state lookup because by the time this runs the booking row has
     * already been soft-deleted (cancel cleanup is deferred to WP cron).
     */
    public function sendBookingCancelledEmailToOrganiser(string $meetingId): bool
    {
        $ctx = $this->loadBookingContext($meetingId, true);
        if ($ctx === null) {
            return false;
        }

        ['meeting' => $meeting, 'schedule' => $schedule, 'date' => $date,
         'scheduleName' => $scheduleName, 'locationTitle' => $locationTitle,
         'scheduleDescription' => $scheduleDescription] = $ctx;

        // Re-anchor to organiser timezone — see sendBookingConfirmEmailToOrganiser().
        $organiserTz = $this->organiserTimezone($schedule);
        if ($organiserTz !== '') {
            $date = (clone $date)->setTimezone(new DateTimeZone($organiserTz));
        }

        $targetEmail = (string) ($schedule['email'] ?? '');
        if ($targetEmail === '') {
            return false;
        }

        $emailSubject = 'Meeting cancelled';

        $templatePath = (defined('APEXIANLAB_MEETING_SCHEDULER_PATH') ? APEXIANLAB_MEETING_SCHEDULER_PATH : '')
            . 'templates/email/message-booking-cancelled-organiser.php';

        $attendeeFullName = trim((string) ($meeting['first_name'] ?? '') . ' ' . (string) ($meeting['last_name'] ?? ''));

        ob_start();
        $args = [
            'email_heading'  => $emailSubject,
            'name'           => $attendeeFullName !== '' ? $attendeeFullName : (string) ($meeting['email'] ?? ''),
            'organiser_name' => $scheduleName,
            'description'    => $scheduleDescription,
            'attendee_name'  => $attendeeFullName,
            'attendee_email' => (string) ($meeting['email'] ?? ''),
            'attendee_phone' => (string) ($meeting['phone'] ?? ''),
            'date'           => $date,
            'duration'       => $meeting['duration'],
            'location'       => $locationTitle,
        ];

        if (is_readable($templatePath)) {
            include $templatePath;
        } else {
            get_template_part('template-part/message', 'booking-cancelled-organiser', $args);
        }

        $emailMessage = (string) ob_get_clean();
        $emailHeaders = ['Content-Type: text/html; charset=UTF-8'];
        $fromHeader = $this->buildFromHeader($schedule);
        if ($fromHeader !== '') {
            $emailHeaders[] = $fromHeader;
        }
        $emailHeaders = array_merge($emailHeaders, $this->buildCcHeaders($schedule));

        if (trim($emailMessage) === '') {
            return false;
        }

        if ($this->isLocalEmail($targetEmail)) {
            return true;
        }

        $sent = $this->sendMail($schedule, $targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        if (!$sent) {
            $this->logEmail($targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        }

        return $sent;
    }

    /**
     * Notify the attendee that their meeting was cancelled.
     * Uses any-state lookup (row already soft-deleted by the cancel flow).
     */
    public function sendBookingCancelledEmailToAttendee(string $meetingId): bool
    {
        $ctx = $this->loadBookingContext($meetingId, true);
        if ($ctx === null) {
            return false;
        }

        ['meeting' => $meeting, 'schedule' => $schedule, 'date' => $date,
         'scheduleName' => $scheduleName, 'locationTitle' => $locationTitle,
         'scheduleDescription' => $scheduleDescription] = $ctx;

        $targetEmail = (string) ($meeting['email'] ?? '');
        if ($targetEmail === '') {
            return false;
        }

        $emailSubject = 'Meeting cancelled';

        $templatePath = (defined('APEXIANLAB_MEETING_SCHEDULER_PATH') ? APEXIANLAB_MEETING_SCHEDULER_PATH : '')
            . 'templates/email/message-booking-cancelled-attendee.php';

        $attendeeFullName = trim((string) ($meeting['first_name'] ?? '') . ' ' . (string) ($meeting['last_name'] ?? ''));

        ob_start();
        $args = [
            'email_heading'  => $emailSubject,
            'name'           => $scheduleName,
            'description'    => $scheduleDescription,
            'attendee_name'  => $attendeeFullName,
            'attendee_email' => (string) ($meeting['email'] ?? ''),
            'organiser_name' => $scheduleName,
            'date'           => $date,
            'duration'       => $meeting['duration'],
            'location'       => $locationTitle,
        ];

        if (is_readable($templatePath)) {
            include $templatePath;
        }

        $emailMessage = (string) ob_get_clean();
        $emailHeaders = ['Content-Type: text/html; charset=UTF-8'];
        $fromHeader = $this->buildFromHeader($schedule);
        if ($fromHeader !== '') {
            $emailHeaders[] = $fromHeader;
        }

        if (trim($emailMessage) === '') {
            return false;
        }

        if ($this->isLocalEmail($targetEmail)) {
            return true;
        }

        $sent = $this->sendMail($schedule, $targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        if (!$sent) {
            $this->logEmail($targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        }

        return $sent;
    }

    public function sendBookingConfirmationEmail(string $meetingId): bool
    {
        // First, load without applying pending to check if this is a reschedule
        $rawMeeting = $this->bookingRepository->findById($meetingId);
        $isReschedule = $rawMeeting !== null && !empty($rawMeeting['pending_reschedule']);

        // Now load with pending applied for the email content
        $ctx = $this->loadBookingContext($meetingId, false, true);
        if ($ctx === null) {
            return false;
        }

        ['meeting' => $meeting, 'schedule' => $schedule, 'date' => $date,
         'scheduleName' => $scheduleName, 'locationTitle' => $locationTitle,
         'scheduleDescription' => $scheduleDescription] = $ctx;

        $emailSubject = $isReschedule ? 'Meeting reschedule confirmation' : 'Meeting booking confirmation';

        // For reschedule, extract the original datetime before the change
        $originalDate = null;
        if ($isReschedule && $rawMeeting !== null && !empty($rawMeeting['datetime'])) {
            try {
                $originalDate = new \DateTime((string) $rawMeeting['datetime']);
                $tz = (string) ($rawMeeting['timezone'] ?? $meeting['timezone'] ?? 'UTC');
                $originalDate->setTimezone(new \DateTimeZone($tz));
            } catch (\Throwable $e) {
                $originalDate = null;
            }
        }

        // Reuse existing token so earlier email links survive a resend.
        $token = (string) ($rawMeeting['confirmation_token'] ?? $meeting['confirmation_token'] ?? '');
        if ($token === '') {
            $token = wp_generate_password(16, false, false);
            $this->bookingRepository->update($meetingId, ['confirmation_token' => $token]);
        }

        $confirmLink = DisplayHelper::bookingUrl($schedule, [
            'c'   => $token,
            'for' => 'attendee',
        ]);
        $rescheduleLink = DisplayHelper::bookingUrl($schedule, [
            'meeting_id' => $meetingId,
            'reschedule' => '1',
            'for'        => 'attendee',
        ]);
        $cancelLink = DisplayHelper::bookingUrl($schedule, [
            'meeting_id' => $meetingId,
            'frame'      => '5',
            'for'        => 'attendee',
        ]);

        $templatePath = (defined('APEXIANLAB_MEETING_SCHEDULER_PATH') ? APEXIANLAB_MEETING_SCHEDULER_PATH : '')
            . 'templates/email/message-booking-confirmation.php';

        ob_start();
        $args = [
            'subject' => $emailSubject,
            'target_name' => trim((string) ($meeting['first_name'] ?? '') . ' ' . (string) ($meeting['last_name'] ?? '')),
            'phone' => (string) ($meeting['phone'] ?? ''),
            'description' => $scheduleDescription,
            'name' => $scheduleName,
            'author_email' => (string) ($schedule['email'] ?? ''),
            'date' => $date,
            'duration' => $meeting['duration'],
            'location' => $locationTitle,
            'link' => $confirmLink,
            'reschedule_link' => $rescheduleLink,
            'cancel_link' => $cancelLink,
            'is_reschedule' => $isReschedule,
            'original_date' => $originalDate,
        ];

        if (is_readable($templatePath)) {
            include $templatePath;
        } else {
            get_template_part('template-part/message', 'booking-confirmation', $args);
        }

        $emailMessage = (string) ob_get_clean();
        $emailHeaders = ['Content-Type: text/html; charset=UTF-8'];
        $fromHeader = $this->buildFromHeader($schedule);
        if ($fromHeader !== '') {
            $emailHeaders[] = $fromHeader;
        }

        if (trim($emailMessage) === '') {
            $guestName = trim((string) ($meeting['first_name'] ?? '') . ' ' . (string) ($meeting['last_name'] ?? ''));
            $emailMessage = '<p style="text-align:left;margin:0;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;font-size:14px;line-height:150%;color:#0A0A0A;">Hi <strong>'
                . esc_html($guestName !== '' ? $guestName : 'there')
                . '</strong>, Thank you for booking your meeting! To confirm your booking, please click the link below.</p>'
                . '<table cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin:16px 0 0 0;"><tr>'
                . '<td valign="middle" style="padding:0 12px 8px 0;"><a href="' . esc_url($confirmLink) . '" style="display:inline-block;box-sizing:border-box;min-width:160px;padding:16px 32px;border-radius:50px;background-color:#00B84C;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;font-weight:500;font-size:14px;line-height:1.2;text-align:center;color:#FFFFFF;text-decoration:none;">Confirm booking</a></td>'
                . '<td valign="middle" style="padding:0 8px 8px 0;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;font-size:14px;color:#0A0A0A;">or</td>'
                . '<td valign="middle" style="padding:0 0 8px 0;"><a href="' . esc_url($confirmLink) . '" style="font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;font-size:14px;font-weight:500;color:#00B84C;text-decoration:underline;">Copy confirmation link</a></td>'
                . '</tr></table>';
        }

        $targetEmail = (string) $meeting['email'];

        if ($this->isLocalEmail($targetEmail)) {
            $this->bookingRepository->update($meetingId, ['email_sent' => 1]);
            return true;
        }

        $sent = $this->sendMail($schedule, $targetEmail, $emailSubject, $emailMessage, $emailHeaders);

        if (!$sent) {
            $this->logEmail($targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        }

        $this->bookingRepository->update($meetingId, [
            'email_sent' => (int) $sent,
            'number_attempts' => (int) ($meeting['number_attempts'] ?? 0) - 1,
        ]);

        return $sent;
    }

    /**
     * Generate a single-use cancel token, persist it with a 1-hour expiry,
     * and email the attendee a confirmation link. Returns false on send failure.
     */
    public function sendCancelConfirmationEmail(string $meetingId): bool
    {
        $ctx = $this->loadBookingContext($meetingId);
        if ($ctx === null) {
            return false;
        }

        ['meeting' => $meeting, 'schedule' => $schedule, 'date' => $date,
         'scheduleName' => $scheduleName, 'locationTitle' => $locationTitle,
         'scheduleDescription' => $scheduleDescription] = $ctx;

        $targetEmail = (string) ($meeting['email'] ?? '');
        if ($targetEmail === '') {
            return false;
        }

        $token = wp_generate_password(16, false, false);
        $expiry = new \DateTime('now', new \DateTimeZone('UTC'));
        $expiry->modify('+1 hour');
        $this->bookingRepository->update($meetingId, [
            'cancel_token'            => $token,
            'cancel_token_expiration' => $expiry->format('Y-m-d H:i:sP'),
        ]);

        $cancelLink = DisplayHelper::bookingUrl($schedule, [
            'meeting_id'   => $meetingId,
            'cancel_token' => $token,
            'frame'        => '5',
            'for'          => 'attendee',
        ]);

        $emailSubject = 'Confirm meeting cancellation';
        $templatePath = (defined('APEXIANLAB_MEETING_SCHEDULER_PATH') ? APEXIANLAB_MEETING_SCHEDULER_PATH : '')
            . 'templates/email/message-cancel-confirmation.php';

        ob_start();
        $args = [
            'subject'     => $emailSubject,
            'target_name' => trim((string) ($meeting['first_name'] ?? '') . ' ' . (string) ($meeting['last_name'] ?? '')),
            'name'        => $scheduleName,
            'description' => $scheduleDescription,
            'date'        => $date,
            'duration'    => $meeting['duration'],
            'location'    => $locationTitle,
            'cancel_link' => $cancelLink,
        ];
        if (is_readable($templatePath)) {
            include $templatePath;
        }
        $emailMessage = (string) ob_get_clean();

        if (trim($emailMessage) === '') {
            return false;
        }

        $emailHeaders = ['Content-Type: text/html; charset=UTF-8'];
        $fromHeader = $this->buildFromHeader($schedule);
        if ($fromHeader !== '') {
            $emailHeaders[] = $fromHeader;
        }

        if ($this->isLocalEmail($targetEmail)) {
            return true;
        }

        $sent = $this->sendMail($schedule, $targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        if (!$sent) {
            $this->logEmail($targetEmail, $emailSubject, $emailMessage, $emailHeaders);
        }

        return $sent;
    }

    private function isSmtpReachable(string $host, int $port): bool
    {
        $cacheKey = 'apexianlab_smtp_reach_' . md5($host . ':' . $port);
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached === '1';
        }

        $socket = @fsockopen($host, $port, $errNo, $errStr, 2);
        $reachable = $socket !== false;
        if ($socket !== false) {
            fclose($socket);
        }

        set_transient($cacheKey, $reachable ? '1' : '0', 2 * MINUTE_IN_SECONDS);
        return $reachable;
    }

    private function isLocalEmail(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return true;
        }
        $domain = strtolower(substr($email, $at + 1));

        foreach (['.local', '.test', '.localhost', '.invalid', '.example', '.dev'] as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return true;
            }
        }

        return $domain === 'localhost';
    }

    /**
     * @param array<int, string> $headers
     */
    public function logEmail(string $to, string $subject, string $message, array $headers): void
    {
        $uploads = wp_upload_dir();
        $dir     = isset($uploads['basedir']) ? rtrim((string) $uploads['basedir'], '/\\') . '/apexianlab-mail-failures' : '';
        if ($dir === '' || (!is_dir($dir) && !wp_mkdir_p($dir))) {
            return;
        }
        $path = $dir . '/email_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false) . '.txt';
        $file = @fopen($path, 'w');
        if ($file === false) {
            return;
        }
        fwrite($file, "Target Email: {$to}\n");
        fwrite($file, "Subject: {$subject}\n");
        fwrite($file, "Message: {$message}\n");
        fwrite($file, "Headers: " . wp_json_encode($headers) . "\n");
        fclose($file);
    }
}
