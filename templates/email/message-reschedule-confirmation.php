<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Reschedule-confirmation email for anonymous attendees.
 *
 * @var array{
 *   subject:string, target_name:string, name:string, description:string,
 *   old_date:string, new_date:string, duration:int|string, location:string, confirm_link:string
 * } $args
 */
if (!defined('ABSPATH')) {
    exit;
}

if (
    empty($args['old_date'])
    || empty($args['new_date'])
    || empty($args['location'])
    || empty($args['confirm_link'])
) {
    return;
}

$subject      = (string) ($args['subject'] ?? 'Confirm meeting reschedule');
$target_name  = (string) ($args['target_name'] ?? '');
$name         = (string) ($args['name'] ?? '');
$description  = (string) ($args['description'] ?? '');
$old_date     = (string) $args['old_date'];
$new_date     = (string) $args['new_date'];
$duration     = (int) ($args['duration'] ?? 0);
$location     = (string) $args['location'];
$confirm_link = (string) $args['confirm_link'];

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
    <title><?php echo esc_html($subject); ?></title>
</head>

<body style="margin:0;padding:0;background:#ffffff;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;">
    <tr>
        <td align="center" style="padding:0 12px;">

            <!-- Container -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:568px;">

                <tr>
                    <td align="left" style="padding:32px 0 0 0;">
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
                            <tr>
                                <td valign="top" width="48" style="padding:0 12px 0 0;">
                                    <img
                                        src="<?php echo esc_url($icons . 'calendar-icon.svg'); ?>"
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
                                        <?php echo esc_html($subject); ?>
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
                            Hi <strong style="font-weight:700;"><?php echo esc_html($target_name !== '' ? $target_name : 'there'); ?></strong>,
                            you requested to reschedule your meeting<?php echo $name !== '' ? ' with <strong style="font-weight:700;">' . esc_html($name) . '</strong>' : ''; ?>.
                            To confirm the new time, please click the button below.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td align="left" style="padding:16px 0 0 0;">
                        <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse:collapse;">
                            <tr>
                                <td valign="middle" style="padding:0 12px 8px 0;">
                                    <a href="<?php echo esc_url($confirm_link); ?>"
                                       style="
                                               display:inline-block;
                                               box-sizing:border-box;
                                               min-width:160px;
                                               max-width:100%;
                                               padding:16px 32px;
                                               border-radius:50px;
                                               background-color:#4CAF50;
                                               font-family:<?php echo esc_attr($font_stack); ?>;
                                               font-weight:500;
                                               font-size:14px;
                                               line-height:1.2;
                                               letter-spacing:0;
                                               text-align:center;
                                               color:#FFFFFF;
                                               text-decoration:none;
                                               -webkit-text-size-adjust:none;
                                               mso-line-height-rule:exactly;
                                               "
                                       target="_blank" rel="noopener">
                                        Confirm reschedule
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- Card: reschedule details -->
                <tr>
                    <td style="padding:24px 0 32px 0;">

                        <table width="100%" cellpadding="0" cellspacing="0" border="0"
                               style="background:#FFFFFF;border:1px solid #4CAF50;border-radius:16px;border-collapse:separate;mso-border-alt:solid #4CAF50 1px;">

                            <tr>
                                <td style="padding:24px;">
                                    <?php if ($description !== '') : ?>
                                    <h2 style="
                                            margin:0;
                                            padding-bottom:16px;
                                            border-bottom:1px solid rgba(26,26,26,0.1);
                                            font-family:<?php echo esc_attr($font_stack); ?>;
                                            font-size:20px;
                                            line-height:150%;
                                            font-weight:600;
                                            letter-spacing:0;
                                            color:#181A20;
                                            text-align:left;
                                            ">
                                        A brief overview of the project
                                    </h2>
                                    <p style="
                                            margin:0;
                                            padding-top:8px;
                                            padding-bottom:16px;
                                            font-family:<?php echo esc_attr($font_stack); ?>;
                                            font-size:14px;
                                            line-height:150%;
                                            font-weight:400;
                                            letter-spacing:0;
                                            color:#181A20;
                                            ">
                                        <?php echo nl2br(esc_html($description)); ?>
                                    </p>
                                    <?php endif; ?>
                                    
                                    <!-- Meeting Details -->
                                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid rgba(26,26,26,0.1);margin-top:16px;">
                                        <tr>
                                            <td style="padding-top:16px;">
                                                <h2 style="
                                                        margin:0 0 12px 0;
                                                        padding:0;
                                                        border:none;
                                                        font-family:<?php echo esc_attr($font_stack); ?>;
                                                        font-size:14px;
                                                        line-height:150%;
                                                        font-weight:600;
                                                        letter-spacing:0;
                                                        color:#181A20;
                                                        text-align:left;
                                                        ">
                                                    Meeting with <?php echo esc_html($name !== '' ? $name : 'Organizer'); ?>
                                                </h2>
                                                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px 0;">
                                                    <tr>
                                                        <td width="20" valign="top" style="padding:2px 8px 0 0;">
                                                            <img src="<?php echo esc_url($location_icon); ?>"
                                                                 width="20"
                                                                 height="20"
                                                                 alt=""
                                                                 style="display:block;border:0;">
                                                        </td>
                                                        <td valign="top" style="
                                                                font-family:<?php echo esc_attr($font_stack); ?>;
                                                                font-size:14px;
                                                                line-height:150%;
                                                                font-weight:500;
                                                                color:#181A20;
                                                                ">
                                                            <?php echo esc_html($location); ?>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>

                                    <!-- Original Time (Strikethrough) -->
                                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid rgba(26,26,26,0.1);margin-top:16px;">
                                        <tr>
                                            <td style="padding-top:16px;">
                                                <h3 style="
                                                        margin:0 0 8px 0;
                                                        font-family:<?php echo esc_attr($font_stack); ?>;
                                                        font-size:12px;
                                                        line-height:150%;
                                                        font-weight:600;
                                                        letter-spacing:0.5px;
                                                        color:#81878F;
                                                        text-transform:uppercase;
                                                        ">
                                                    Original Time
                                                </h3>
                                                <p style="
                                                        margin:0;
                                                        font-family:<?php echo esc_attr($font_stack); ?>;
                                                        font-size:14px;
                                                        line-height:150%;
                                                        font-weight:400;
                                                        color:#81878F;
                                                        text-decoration:line-through;
                                                        ">
                                                    <?php echo esc_html($old_date); ?>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>

                                    <!-- New Time (Highlighted) -->
                                    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;">
                                        <tr>
                                            <td style="padding:0;">
                                                <h3 style="
                                                        margin:0 0 8px 0;
                                                        font-family:<?php echo esc_attr($font_stack); ?>;
                                                        font-size:12px;
                                                        line-height:150%;
                                                        font-weight:600;
                                                        letter-spacing:0.5px;
                                                        color:#4CAF50;
                                                        text-transform:uppercase;
                                                        ">
                                                    New Time
                                                </h3>
                                                <p style="
                                                        margin:0;
                                                        font-family:<?php echo esc_attr($font_stack); ?>;
                                                        font-size:16px;
                                                        line-height:150%;
                                                        font-weight:600;
                                                        color:#181A20;
                                                        ">
                                                    <?php echo esc_html($new_date); ?>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>

                                    <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation"
                                           style="border-top:1px solid rgba(26,26,26,0.1);margin-top:16px;">
                                        <tr>
                                            <td align="left" style="padding:16px 0 0 0;">
                                                <p style="
                                                        margin:0;
                                                        font-family:<?php echo esc_attr($font_stack); ?>;
                                                        font-size:12px;
                                                        line-height:150%;
                                                        font-weight:400;
                                                        color:#81878F;
                                                        text-align:left;
                                                        ">
                                                    If you did not request this, ignore this email — your meeting stays at the original time. This link expires in 1 hour.
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>

                        </table>

                    </td>
                </tr>

            </table>
            <!-- /Container -->

        </td>
    </tr>
</table>

</body>
</html>
