<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Dto;

final class AvailabilityWindow
{
    /**
     * @param int                                                            $from            Unix timestamp window start.
     * @param int                                                            $to              Unix timestamp window end.
     * @param array<int, array{start: int, end: int, duration_minutes: int}> $list            Available ranges (timestamps).
     * @param array<int, array{start: int, end: int}>                        $bookedIntervals Occupied ranges (timestamps).
     */
    public function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly array $list,
        public readonly array $bookedIntervals,
    ) {
    }

    /** @return array{from: int, to: int, list: array, booked_intervals: array} */
    public function toArray(): array
    {
        return [
            'from'             => $this->from,
            'to'               => $this->to,
            'list'             => $this->list,
            'booked_intervals' => $this->bookedIntervals,
        ];
    }
}
