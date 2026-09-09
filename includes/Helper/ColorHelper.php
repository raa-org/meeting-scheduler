<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Helper;

final class ColorHelper
{
    public const BLUE   = 'blue';
    public const GREEN  = 'green';
    public const CYAN   = 'cyan';
    public const PINK   = 'pink';
    public const YELLOW = 'yellow';
    public const RED    = 'red';
    public const ORANGE = 'orange';
    public const VIOLET = 'violet';

    /** @var array<int, string> */
    private const CODE_MAP = [
        1 => self::BLUE,
        2 => self::GREEN,
        3 => self::CYAN,
        4 => self::PINK,
        5 => self::YELLOW,
        6 => self::RED,
        7 => self::ORANGE,
        8 => self::VIOLET,
    ];

    /** @var array<int, string> */
    private const BACKGROUND_HEX = [
        1 => '#CCE9FF', 2 => '#BAF5D1', 3 => '#C2F7FF', 4 => '#FFD6F8',
        5 => '#FFF1D6', 6 => '#FAC8D0', 7 => '#FFD3C2', 8 => '#E8E0FF',
    ];

    /** @var array<int, string> */
    private const BORDER_HEX = [
        1 => '#0088FF', 2 => '#00B86B', 3 => '#00B8D9', 4 => '#E83EC8',
        5 => '#EFA40F', 6 => '#E53950', 7 => '#FF5718', 8 => '#7A5CFF',
    ];

    /** @var array<int, string> */
    private const TEXT_HEX = [
        1 => '#0088FF', 2 => '#00B86B', 3 => '#00B8D9', 4 => '#E83EC8',
        5 => '#EFA40F', 6 => '#E53950', 7 => '#FF5718', 8 => '#7A5CFF',
    ];

    public static function classByCode(?int $code): string
    {
        return self::CODE_MAP[$code ?? 1] ?? self::BLUE;
    }

    /** @return array<int, string> */
    public static function map(): array
    {
        return self::CODE_MAP;
    }

    public static function backgroundHex(?int $code): string
    {
        return self::BACKGROUND_HEX[$code ?? 1] ?? self::BACKGROUND_HEX[1];
    }

    public static function borderHex(?int $code): string
    {
        return self::BORDER_HEX[$code ?? 1] ?? self::BORDER_HEX[1];
    }

    public static function textHex(?int $code): string
    {
        return self::TEXT_HEX[$code ?? 1] ?? self::TEXT_HEX[1];
    }
}
