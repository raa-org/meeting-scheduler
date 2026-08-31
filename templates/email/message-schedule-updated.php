<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


if (empty($args['name']) || empty($args['schedule_link'])) {
    return;
}

$email_heading = (string) ($args['email_heading'] ?? 'Your schedule has been updated');
$schedule_name  = (string) $args['name'];
$organiser_name = trim((string) ($args['organiser_name'] ?? ''));
if ($organiser_name === '') {
    $organiser_name = $schedule_name;
}
$description   = (string) ($args['description'] ?? '');
$location      = (string) ($args['location'] ?? '');
$schedule_link = (string) $args['schedule_link'];
$durations     = is_array($args['durations'] ?? null) ? $args['durations'] : [];
$qr_cid        = (string) ($args['qr_cid'] ?? '');

$durations_str = $durations !== []
    ? implode(', ', array_map('intval', $durations)) . ' min'
    : '';

$base  = rtrim((string) APEXIANLAB_MEETING_SCHEDULER_URL, '/') . '/assets/images/booking/';
$icons = $base . 'icons/';

$location_icon = $icons . 'calendar-icon.svg';
$location_key  = strtolower(trim($location));
if ($location_key === 'phone call') {
    $location_icon = $icons . 'phone-icon.svg';
}

$font_stack = "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif";

?>
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($email_heading); ?></title>
</head>

<body style="margin:0;padding:0;background:#ffffff;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;">
    <tr>
        <td align="center" style="padding:0 12px;">

            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:568px;">

                <tr>
                    <td align="left" style="padding:32px 0 0 0;">
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
                            <tr>
                                <td valign="top" width="48" style="padding:0 12px 0 0;">
                                    <img
                                        src="<?php echo esc_url($icons . 'free_cancellation_icon.svg'); ?>"
                                        width="40"
                                        height="40"
                                        alt=""
                                        style="display:block;border:0;outline:none;text-decoration:none;"
                                    />
                                </td>
                                <td valign="middle">
                                    <h1 style="
                                            margin:0;
                                            font-family:<?php echo esc_attr($font_stack); ?>;
                                            font-size:24px;
                                            line-height:120%;
                                            font-weight:700;
                                            color:#0A0A0A;
                                            text-align:left;
                                            ">
                                        <?php echo esc_html($email_heading); ?>
                                    </h1>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td align="left" style="padding:16px 0 0 0;">
                        <p style="
                                margin:0;
                                font-family:<?php echo esc_attr($font_stack); ?>;
                                font-size:14px;
                                line-height:150%;
                                font-weight:500;
                                letter-spacing:0;
                                text-align:left;
                                color:#0A0A0A;
                                ">
                            Your schedule
                            <strong style="font-weight:700;"><?php echo esc_html($schedule_name); ?></strong>
                            has been updated. Share the link or QR code below so guests can book a time.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td align="left" style="padding:16px 0 0 0;">
                        <a href="<?php echo esc_url($schedule_link); ?>"
                           style="display:inline-block;box-sizing:border-box;min-width:160px;max-width:100%;padding:16px 32px;border-radius:50px;background-color:#00B84C;font-family:<?php echo esc_attr($font_stack); ?>;font-weight:500;font-size:14px;line-height:1.2;letter-spacing:0;text-align:center;color:#FFFFFF;text-decoration:none;-webkit-text-size-adjust:none;mso-line-height-rule:exactly;"
                           target="_blank" rel="noopener">
                            View schedule
                        </a>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 0 32px 0;">

                        <table width="100%" cellpadding="0" cellspacing="0" border="0"
                               style="background:#FFFFFF;border:1px solid #00B84C;border-radius:16px;border-collapse:separate;mso-border-alt:solid #00B84C 1px;">

                            <tr>
                                <td style="padding:24px;">
                                    <?php if ($description !== '') : ?>
                                    <h2 style="margin:0;padding-bottom:16px;border-bottom:1px solid rgba(26,26,26,0.1);font-family:<?php echo esc_attr($font_stack); ?>;font-size:20px;line-height:150%;font-weight:600;letter-spacing:0;color:#181A20;text-align:left;">
                                        About this schedule
                                    </h2>
                                    <p style="margin:0;padding-top:8px;padding-bottom:16px;font-family:<?php echo esc_attr($font_stack); ?>;font-size:14px;line-height:150%;font-weight:400;letter-spacing:0;color:#181A20;">
                                        <?php echo nl2br(esc_html($description)); ?>
                                    </p>
                                    <?php endif; ?>

                                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid rgba(26,26,26,0.1);margin-top:16px;">
                                        <tr>
                                            <td style="padding-top:16px;">
                                                <h2 style="margin:0 0 12px 0;padding:0;border:none;font-family:<?php echo esc_attr($font_stack); ?>;font-size:14px;line-height:150%;font-weight:600;letter-spacing:0;color:#181A20;text-align:left;">
                                                    Schedule Details
                                                </h2>

                                                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px 0;">
                                                    <tr>
                                                        <td width="20" valign="top" style="padding:2px 8px 0 0;">
                                                            <img src="<?php echo esc_url($icons . 'person-icon.svg'); ?>" width="20" height="20" alt="" style="display:block;border:0;">
                                                        </td>
                                                        <td valign="top" style="font-family:<?php echo esc_attr($font_stack); ?>;font-size:16px;font-weight:500;line-height:1.3;color:#181A20;">
                                                            <?php echo esc_html($organiser_name); ?>
                                                        </td>
                                                    </tr>
                                                </table>

                                                <?php if ($location !== '') : ?>
                                                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:12px 0 0 0;">
                                                    <tr>
                                                        <td width="20" valign="top" style="padding:2px 8px 0 0;">
                                                            <img src="<?php echo esc_url($location_icon); ?>" width="20" height="20" alt="" style="display:block;border:0;">
                                                        </td>
                                                        <td valign="top" style="font-family:<?php echo esc_attr($font_stack); ?>;font-size:14px;line-height:150%;font-weight:500;color:#181A20;">
                                                            <?php echo esc_html($location); ?>
                                                        </td>
                                                    </tr>
                                                </table>
                                                <?php endif; ?>

                                                <?php if ($durations_str !== '') : ?>
                                                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:12px 0 0 0;">
                                                    <tr>
                                                        <td width="20" valign="top" style="padding:2px 8px 0 0;">
                                                            <img src="<?php echo esc_url($icons . 'alarm-icon.svg'); ?>" width="20" height="20" alt="" style="display:block;border:0;">
                                                        </td>
                                                        <td valign="top" style="font-family:<?php echo esc_attr($font_stack); ?>;font-size:14px;line-height:150%;font-weight:500;color:#181A20;">
                                                            <?php echo esc_html($durations_str); ?>
                                                        </td>
                                                    </tr>
                                                </table>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>

                                    <?php if ($qr_cid !== '') : ?>
                                    <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-top:1px solid rgba(26,26,26,0.1);margin-top:16px;">
                                        <tr>
                                            <td align="center" style="padding:20px 0 0 0;">
                                                <img src="cid:<?php echo esc_attr($qr_cid); ?>" width="160" height="160" alt="QR code for the schedule link" style="display:block;border:0;width:160px;height:160px;">
                                                <p style="margin:12px 0 0 0;font-family:<?php echo esc_attr($font_stack); ?>;font-size:12px;line-height:1.4;font-weight:400;color:#616161;text-align:center;">
                                                    Scan to open and share this schedule
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                    <?php endif; ?>
                                </td>
                            </tr>

                        </table>

                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
