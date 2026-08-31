<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Dto;

final class SchedulePayload
{
    /**
     * @param array<int, array{date: string, start: string, end: string, timezone: string}> $scheduleRanges
     * @param array<string, mixed>|null                                                      $weeklyRule
     * @param array<int, int>                                                                $durations
     * @param array<int, string>                                                             $additionalRecipients
     */
    public function __construct(
        public readonly string $name,
        public readonly string $subject,
        public readonly string $description,
        public readonly string $meansOfCommunicationId,
        public readonly ?int $color,
        public readonly int $reminderMinutes,
        public readonly array $scheduleRanges,
        public readonly ?array $weeklyRule,
        public readonly bool $isPublic,
        public readonly bool $requireEmailVerification,
        public readonly string $scheduleRepeat,
        public readonly int $repeatIntervalWeeks,
        public readonly string $nameFormat,
        public readonly ?string $nameFormatCustom,
        public readonly array $durations = [15, 30, 45, 60, 90],
        public readonly array $additionalRecipients = [],
    ) {
    }

    public function weeklyRuleJson(): ?string
    {
        return $this->weeklyRule !== null ? wp_json_encode($this->weeklyRule) : null;
    }

    public function rangesJson(): string
    {
        return (string) wp_json_encode($this->scheduleRanges);
    }

    public function durationsJson(): string
    {
        return (string) wp_json_encode(array_values($this->durations));
    }

    public function additionalRecipientsJson(): string
    {
        return (string) wp_json_encode(array_values($this->additionalRecipients));
    }
}
