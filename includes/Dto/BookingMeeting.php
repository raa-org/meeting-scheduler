<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Dto;

use DateTime;
use DateTimeZone;
use Apexianlab\Calendar\Helper\DisplayHelper;
use Apexianlab\Calendar\Repository\MeansOfCommunicationRepository;

final class BookingMeeting
{
    public const COMMUNICATION_TYPE_PHONE_CALL = 'Phone Call';

    /** @var array<string, mixed>|null */
    private ?array $schedule;

    /** @var array<string, mixed>|null */
    private ?array $booking;

    /**
     * @param array<string, mixed>|null $scheduleRow
     * @param array<string, mixed>|null $bookingRow
     */
    public function __construct(?array $scheduleRow, ?array $bookingRow = null)
    {
        $this->schedule = $scheduleRow !== null && $scheduleRow !== [] ? $scheduleRow : null;
        $this->booking = $bookingRow !== null && $bookingRow !== [] ? $bookingRow : null;
    }

    public function hasSchedule(): bool
    {
        return $this->schedule !== null;
    }

    public function hasBooking(): bool
    {
        return $this->booking !== null;
    }

    public function getTypeCommunication(MeansOfCommunicationRepository $meansRepo): string
    {
        return DisplayHelper::communicationTitle($this->schedule, $meansRepo);
    }

    public function requiresPhoneNumber(MeansOfCommunicationRepository $meansRepo): bool
    {
        return $this->getTypeCommunication($meansRepo) === self::COMMUNICATION_TYPE_PHONE_CALL;
    }

    public function getTitle(): string
    {
        return $this->str($this->schedule, 'subject');
    }

    public function getDescription(): string
    {
        return $this->str($this->schedule, 'description');
    }

    public function getLocationDisplayClass(MeansOfCommunicationRepository $meansRepo): string
    {
        $loc = $this->getTypeCommunication($meansRepo);
        if ($loc === '') {
            return '';
        }
        return sanitize_html_class('details__platform--' . sanitize_title($loc));
    }

    public function getScheduleId(): string
    {
        return $this->str($this->schedule, 'id');
    }

    public function getMeansOfCommunicationId(): string
    {
        return $this->str($this->schedule, 'calendar_means_of_communication_id');
    }

    public function getScheduleAuthorEmail(): string
    {
        return $this->str($this->schedule, 'email');
    }

    public function getScheduleName(): string
    {
        return $this->str($this->schedule, 'name');
    }

    public function getOrganiserDisplayName(): string
    {
        return DisplayHelper::scheduleOrganiserName($this->schedule);
    }

    public function getScheduleSubject(): string
    {
        return $this->str($this->schedule, 'subject');
    }

    public function getScheduleDescription(): string
    {
        return $this->str($this->schedule, 'description');
    }

    public function getScheduleColor(): ?int
    {
        if ($this->schedule === null || !isset($this->schedule['color']) || $this->schedule['color'] === null || $this->schedule['color'] === '') {
            return null;
        }
        return (int) $this->schedule['color'];
    }

    public function getReminderMinutes(): ?int
    {
        if ($this->schedule === null || !isset($this->schedule['reminder_minutes']) || $this->schedule['reminder_minutes'] === null || $this->schedule['reminder_minutes'] === '') {
            return null;
        }
        return (int) $this->schedule['reminder_minutes'];
    }

    /** @return array<mixed> */
    public function getScheduleRanges(): array
    {
        if ($this->schedule === null || !isset($this->schedule['schedule_ranges'])) {
            return [];
        }
        $r = $this->schedule['schedule_ranges'];
        if (is_string($r)) {
            $decoded = json_decode($r, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($r) ? $r : [];
    }

    public function isSchedulePublic(): bool
    {
        return $this->bool($this->schedule, 'is_public');
    }

    public function getScheduleRepeat(): string
    {
        return $this->str($this->schedule, 'schedule_repeat');
    }

    /** @return array<string, mixed>|null */
    public function getRawScheduleRow(): ?array
    {
        return $this->schedule;
    }

    public function getBookingId(): ?string
    {
        if (!$this->hasBooking()) {
            return null;
        }
        $id = $this->booking['id'] ?? null;
        return $id !== null && $id !== '' ? (string) $id : null;
    }

    public function getCalendarScheduleId(): string
    {
        return $this->str($this->booking, 'calendar_schedule_id');
    }

    public function getGuestEmail(): string
    {
        return $this->str($this->booking, 'email');
    }

    public function getPhone(): string
    {
        return $this->str($this->booking, 'phone');
    }

    public function getFirstName(): string
    {
        return $this->str($this->booking, 'first_name');
    }

    public function getLastName(): string
    {
        return $this->str($this->booking, 'last_name');
    }

    public function getDuration(): int
    {
        return $this->hasBooking() ? (int) ($this->booking['duration'] ?? 0) : 0;
    }

    public function getDatetime(): string
    {
        return $this->str($this->booking, 'datetime');
    }

    public function getTimezone(): string
    {
        $tz = $this->str($this->booking, 'timezone');
        return $tz !== '' ? $tz : 'UTC';
    }

    public function isEmailSent(): bool
    {
        return $this->bool($this->booking, 'email_sent');
    }

    public function isMeetingConfirmed(): bool
    {
        return $this->bool($this->booking, 'meeting_confirmed');
    }

    public function getMeetingJoinUrl(): string
    {
        return $this->str($this->booking, 'meeting_join_url');
    }

    /** @return array<string, mixed>|null */
    public function getRawBookingRow(): ?array
    {
        return $this->booking;
    }

    /**
     * @param string|null $tzOverride Force IANA timezone (organiser view).
     */
    public function getDisplayDateString(?string $tzOverride = null): string
    {
        if (!$this->hasBooking()) {
            return '';
        }
        try {
            $tzName = $tzOverride !== null && $tzOverride !== '' ? $tzOverride : $this->getTimezone();
            $date = new DateTime((string) $this->booking['datetime'], new DateTimeZone('UTC'));
            $date->setTimezone(new DateTimeZone($tzName));
            return DisplayHelper::formatMeetingDateRange($date, $this->getDuration());
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function getDisplayTimeMultiLine(?string $tzOverride = null): string
    {
        if (!$this->hasBooking()) {
            return '';
        }
        try {
            $tzName = $tzOverride !== null && $tzOverride !== '' ? $tzOverride : $this->getTimezone();
            $date = new DateTime((string) $this->booking['datetime'], new DateTimeZone('UTC'));
            $date->setTimezone(new DateTimeZone($tzName));
            $end = clone $date;
            $end->modify('+' . $this->getDuration() . ' minutes');
            $tzLabel = $tzName . ' (UTC' . $date->format('P') . ')';
            $nextDay = $end->format('Y-m-d') !== $date->format('Y-m-d') ? ' (' . $end->format('M j') . ')' : '';
            return $date->format('g:i a') . ' - ' . $end->format('g:i a') . $nextDay . "\n" . $tzLabel;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @param array<string, mixed>|null $row */
    private function str(?array $row, string $key): string
    {
        if ($row === null || !isset($row[$key]) || $row[$key] === null) {
            return '';
        }
        return trim((string) $row[$key]);
    }

    /** @param array<string, mixed>|null $row */
    private function bool(?array $row, string $key): bool
    {
        if ($row === null || !array_key_exists($key, $row)) {
            return false;
        }
        $v = $row[$key];
        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }
}
