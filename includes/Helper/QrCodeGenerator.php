<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Helper;

/**
 * Renders a QR code PNG from arbitrary string data using the vendored
 * single-file pure-PHP QR generator. No composer dependency.
 */
final class QrCodeGenerator
{
    /**
     * Encode $data as a QR code and write it to a temporary PNG file.
     *
     * @return string|null Absolute path to the temp PNG, or null on failure.
     *                     The caller owns the file and must delete it.
     */
    public static function toPngFile(string $data): ?string
    {
        if ($data === '' || !function_exists('imagepng')) {
            return null;
        }

        $vendor = (defined('APEXIANLAB_MEETING_SCHEDULER_PATH') ? APEXIANLAB_MEETING_SCHEDULER_PATH : '')
            . 'includes/Vendor/qrcode.php';
        if (!is_readable($vendor)) {
            return null;
        }
        require_once $vendor;

        if (!class_exists('QRCode')) {
            return null;
        }

        try {
            $qr    = \QRCode::getMinimumQRCode($data, QR_ERROR_CORRECT_LEVEL_M);
            $image = $qr->createImage(6, 4);
            if (!is_resource($image) && !($image instanceof \GdImage)) {
                return null;
            }

            $path = (string) tempnam(sys_get_temp_dir(), 'apexianlab_qr_');
            if ($path === '') {
                return null;
            }

            $ok = imagepng($image, $path);
            imagedestroy($image);

            if ($ok !== true) {
                @unlink($path);
                return null;
            }

            return $path;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Encode $data as a QR code and return it as a base64 `data:` URI,
     * suitable for embedding directly in an <img src> or a JSON response.
     *
     * @return string|null The data URI, or null on failure.
     */
    public static function toDataUri(string $data): ?string
    {
        if ($data === '' || !function_exists('imagepng')) {
            return null;
        }

        $vendor = (defined('APEXIANLAB_MEETING_SCHEDULER_PATH') ? APEXIANLAB_MEETING_SCHEDULER_PATH : '')
            . 'includes/Vendor/qrcode.php';
        if (!is_readable($vendor)) {
            return null;
        }
        require_once $vendor;

        if (!class_exists('QRCode')) {
            return null;
        }

        try {
            $qr    = \QRCode::getMinimumQRCode($data, QR_ERROR_CORRECT_LEVEL_M);
            $image = $qr->createImage(6, 4);
            if (!is_resource($image) && !($image instanceof \GdImage)) {
                return null;
            }

            ob_start();
            $ok  = imagepng($image);
            $png = (string) ob_get_clean();
            imagedestroy($image);

            if ($ok !== true || $png === '') {
                return null;
            }

            return 'data:image/png;base64,' . base64_encode($png);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
