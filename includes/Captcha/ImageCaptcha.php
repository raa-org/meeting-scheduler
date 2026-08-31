<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Captcha;

/**
 * GD image captcha — previously lived in the active theme
 * (core/r_captcha.php). Served via ?apexianlab_captcha=1&k=…
 * so the plugin works with any theme.
 */
final class ImageCaptcha
{
    public static function register(): void
    {
        add_action('init', [self::class, 'maybeRender'], 0);
    }

    public static function url(string $key): string
    {
        return add_query_arg(
            [
                'apexianlab_captcha' => '1',
                'k'                  => $key,
                'r'                  => (string) wp_rand(100000, 999999),
            ],
            home_url('/')
        );
    }

    public static function maybeRender(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public captcha image endpoint; key is a form namespace, not a capability.
        if (empty($_GET['apexianlab_captcha'])) {
            return;
        }

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            status_header(503);
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $key = isset($_GET['k']) ? sanitize_key(wp_unslash((string) $_GET['k'])) : '';
        if ($key === '') {
            status_header(400);
            exit;
        }

        $width         = 85;
        $height        = 48;
        $fontSize      = 16;
        $letterAmount  = 4;
        $fonLetAmount  = 20;
        $font          = APEXIANLAB_MEETING_SCHEDULER_PATH . 'assets/fonts/cour.ttf';
        $letters       = ['a', 'b', 'c', 'd', 'e', 'f', 'g', '3', '4', '5', '2'];
        $colors        = ['90', '110', '130', '150', '170', '190', '210'];

        if (!is_readable($font)) {
            status_header(503);
            exit;
        }

        $src = imagecreatetruecolor($width, $height);
        if ($src === false) {
            status_header(503);
            exit;
        }

        $fon = imagecolorallocate($src, 255, 255, 255);
        if ($fon === false) {
            imagedestroy($src);
            status_header(503);
            exit;
        }
        imagefill($src, 0, 0, $fon);

        $letterMax = count($letters) - 1;
        $colorMax  = count($colors) - 1;

        for ($i = 0; $i < $fonLetAmount; $i++) {
            $color = imagecolorallocatealpha(
                $src,
                random_int(0, 255),
                random_int(0, 255),
                random_int(0, 255),
                100
            );
            if ($color === false) {
                continue;
            }
            $letter = $letters[random_int(0, $letterMax)];
            $size   = random_int($fontSize - 2, $fontSize + 2);
            imagettftext(
                $src,
                $size,
                random_int(0, 45),
                random_int((int) ($width * 0.1), (int) ($width - $width * 0.1)),
                random_int((int) ($height * 0.2), $height),
                $color,
                $font,
                $letter
            );
        }

        $codeParts = [];
        for ($i = 0; $i < $letterAmount; $i++) {
            $color = imagecolorallocatealpha(
                $src,
                (int) $colors[random_int(0, $colorMax)],
                (int) $colors[random_int(0, $colorMax)],
                (int) $colors[random_int(0, $colorMax)],
                random_int(20, 40)
            );
            if ($color === false) {
                continue;
            }
            $letter      = $letters[random_int(0, $letterMax)];
            $size        = random_int($fontSize * 2 - 2, $fontSize * 2 + 2);
            $x           = (int) (($i + 1) * $fontSize + random_int(1, 5) - $fontSize / 1.4);
            $y           = (int) ((($height * 2) / 3) + random_int(1, 5));
            $codeParts[] = $letter;
            imagettftext($src, $size, random_int(0, 15), $x, $y, $color, $font, $letter);
        }

        $code = implode('', $codeParts);

        if (PHP_SESSION_ACTIVE !== session_status()) {
            @session_start(); // phpcs:ignore
        }
        $verify = $_SESSION['verify'] ?? [];
        if (!is_array($verify)) {
            $verify = [];
        }
        $verify[$key]     = $code;
        $_SESSION['verify'] = $verify;

        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        imagegif($src);
        imagedestroy($src);
        exit;
    }
}
