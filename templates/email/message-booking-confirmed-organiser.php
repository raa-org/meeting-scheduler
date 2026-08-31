<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


if (
    empty($args['name'])
    || empty($args['date'])
    || !isset($args['duration'])
    || empty($args['location'])
) {
    return;
}

$email_heading  = (string) ($args['email_heading'] ?? 'You have a new confirmed meeting');
$schedule_name  = (string) $args['name'];
$description    = (string) ($args['description'] ?? '');
$attendee_name  = (string) ($args['attendee_name'] ?? '');
$attendee_email = (string) ($args['attendee_email'] ?? '');
$attendee_phone = (string) ($args['attendee_phone'] ?? '');
$location       = (string) $args['location'];
$join_url       = (string) ($args['join_url'] ?? '');
$detail_link    = !empty($args['detail_link']) ? (string) $args['detail_link'] : '';
$date           = $args['date'];
$duration       = (int) $args['duration'];

$start_date = clone $date;
$end_date   = clone $date;
if ($duration > 0) {
    $end_date->modify('+ ' . $duration . ' minutes');
}

$date_str  = \Apexianlab\Calendar\Helper\DisplayHelper::formatMeetingDateRange($start_date, $duration);
$tz_label  = $start_date->getTimezone()->getName() . ' (UTC' . $start_date->format('P') . ')';
$next_day  = $end_date->format('Y-m-d') !== $start_date->format('Y-m-d') ? ' (' . $end_date->format('M j') . ')' : '';
$time_main = $start_date->format('g:i a') . ' – ' . $end_date->format('g:i a') . $next_day;

// Reschedule mode: when a previous slot is supplied the email reads as a
// "rescheduled from X" notice instead of a fresh confirmation.
$previous_date  = isset($args['previous_date']) && $args['previous_date'] instanceof DateTime
    ? $args['previous_date']
    : null;
$is_reschedule  = $previous_date !== null;
$previous_str   = $is_reschedule
    ? $previous_date->format('l, F j \a\t g:i a')
    : '';

$attendee_display = $attendee_name !== '' ? $attendee_name : $attendee_email;

$base  = rtrim((string) APEXIANLAB_MEETING_SCHEDULER_URL, '/') . '/assets/images/booking/';
$icons = $base . 'icons/';

$location_icon = $icons . 'calendar-icon.svg';
$location_key  = strtolower(trim($location));
if ($location_key === 'phone call') {
    $location_icon = $icons . 'phone-icon.svg';
}

$font_stack = "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif";

if (!function_exists('apexianlab_email_organiser_details_info_block')) {
    function apexianlab_email_organiser_details_info_block(
        string $person_icon,
        string $primary,
        string $secondary,
        string $font_stack
    ): void {
        ?>
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px 0;">
            <tr>
                <td width="20" valign="top" style="padding:2px 8px 0 0;">
                    <img src="<?php echo esc_url($person_icon); ?>"
                         width="20"
                         height="20"
                         alt=""
                         style="display:block;border:0;">
                </td>
                <td valign="top" style="font-family:<?php echo esc_attr($font_stack); ?>;">
                    <div style="font-size:16px;font-weight:500;line-height:1.3;letter-spacing:0;color:#181A20;">
                        <?php echo esc_html($primary); ?>
                    </div>
                    <?php if ($secondary !== '') : ?>
                        <div style="margin-top:4px;font-size:12px;font-weight:400;line-height:1.2;letter-spacing:0;color:#616161;">
                            <?php echo esc_html($secondary); ?>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }
}

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
                            <?php $headline_name = $attendee_display !== '' ? $attendee_display : $schedule_name; ?>
                            <?php if ($is_reschedule) : ?>
                            A meeting with
                            <strong style="font-weight:700;"><?php echo esc_html($headline_name); ?></strong>
                            has been rescheduled to a new time. The new details are below.
                            <?php else : ?>
                            A new booking has just been confirmed for
                            <strong style="font-weight:700;"><?php echo esc_html($headline_name); ?></strong>.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>

                <?php if ($join_url !== '' || $detail_link !== '') : ?>
                <tr>
                    <td align="left" style="padding:16px 0 0 0;">
                        <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse:collapse;">
                            <tr>
                                <?php if ($join_url !== '') : ?>
                                <td valign="middle" style="padding:0 12px 8px 0;">
                                    <a href="<?php echo esc_url($join_url); ?>"
                                       style="
                                               display:inline-block;
                                               box-sizing:border-box;
                                               min-width:160px;
                                               max-width:100%;
                                               padding:16px 32px;
                                               border-radius:50px;
                                               background-color:#00B84C;
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
                                        Join meeting
                                    </a>
                                </td>
                                <?php endif; ?>
                                <?php if ($join_url !== '' && $detail_link !== '') : ?>
                                <td valign="middle" style="padding:0 8px 8px 0;font-family:<?php echo esc_attr($font_stack); ?>;font-size:14px;line-height:150%;font-weight:500;color:#0A0A0A;">
                                    or
                                </td>
                                <?php endif; ?>
                                <?php if ($detail_link !== '') : ?>
                                <td valign="middle" style="padding:0 0 8px 0;">
                                    <a href="<?php echo esc_url($detail_link); ?>"
                                       style="
                                               font-family:<?php echo esc_attr($font_stack); ?>;
                                               font-size:14px;
                                               font-weight:500;
                                               line-height:150%;
                                               color:#00B84C;
                                               text-decoration:underline;
                                               "
                                       target="_blank" rel="noopener">
                                        View details
                                    </a>
                                </td>
                                <?php endif; ?>
                            </tr>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>

                <tr>
                    <td style="padding:24px 0 32px 0;">

                        <table width="100%" cellpadding="0" cellspacing="0" border="0"
                               style="background:#FFFFFF;border:1px solid #00B84C;border-radius:16px;border-collapse:separate;mso-border-alt:solid #00B84C 1px;">

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
                                                    Meeting Details
                                                </h2>
                                                <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
                                                    <tr>
                                                        <td width="50%" valign="top" style="padding:0 12px 0 0;">
                                                            <?php
                                                            apexianlab_email_organiser_details_info_block(
                                                                $icons . 'person-icon.svg',
                                                                $schedule_name,
                                                                '',
                                                                $font_stack
                                                            );
                                                            ?>
                                                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 0 0;">
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
                                                        <td width="50%" valign="top" style="padding:0 0 0 12px;">
                                                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px 0;">
                                                                <tr>
                                                                    <td width="20" valign="top" style="padding:2px 8px 0 0;">
                                                                        <img src="<?php echo esc_url($icons . 'calendar-icon.svg'); ?>"
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
                                                                        <?php echo esc_html($date_str); ?>
                                                                    </td>
                                                                </tr>
                                                            </table>
                                                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 0 0;">
                                                                <tr>
                                                                    <td width="20" valign="top" style="padding:2px 8px 0 0;">
                                                                        <img src="<?php echo esc_url($icons . 'alarm-icon.svg'); ?>"
                                                                             width="20"
                                                                             height="20"
                                                                             alt=""
                                                                             style="display:block;border:0;">
                                                                    </td>
                                                                    <td valign="top" style="font-family:<?php echo esc_attr($font_stack); ?>;">
                                                                        <div style="font-size:14px;line-height:150%;font-weight:500;color:#181A20;">
                                                                            <?php echo esc_html($time_main); ?>
                                                                        </div>
                                                                        <div style="margin-top:4px;font-size:12px;line-height:1.2;font-weight:400;color:#616161;">
                                                                            <?php echo esc_html($tz_label); ?>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            </table>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                    <?php if ($is_reschedule && $previous_str !== '') : ?>
                                    <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation"
                                           style="border-top:1px solid rgba(26,26,26,0.1);margin-top:16px;">
                                        <tr>
                                            <td align="left" style="padding:16px 0 0 0;">
                                                <p style="margin:0;padding:12px;background:#FFF3E0;border-left:4px solid #FF9800;border-radius:4px;font-family:<?php echo esc_attr($font_stack); ?>;font-size:13px;line-height:150%;font-weight:500;color:#0A0A0A;">
                                                    <strong style="font-weight:600;">Rescheduled from:</strong>
                                                    <?php echo esc_html($previous_str); ?>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                    <?php endif; ?>
                                    <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation"
                                           style="border-top:1px solid rgba(26,26,26,0.1);margin-top:16px;">
                                        <tr>
                                            <td align="left" style="padding:16px 0 0 0;">
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
                                                    Attendee
                                                </h2>
                                                <?php
                                                apexianlab_email_organiser_details_info_block(
                                                    $icons . 'attendees_icon.svg',
                                                    $attendee_display !== '' ? $attendee_display : '-',
                                                    $attendee_email,
                                                    $font_stack
                                                );
                                                ?>
                                                <?php if ($attendee_phone !== '') : ?>
                                                <p style="
                                                        margin:8px 0 0 0;
                                                        font-family:<?php echo esc_attr($font_stack); ?>;
                                                        font-size:14px;
                                                        line-height:150%;
                                                        font-weight:500;
                                                        color:#181A20;
                                                        text-align:left;
                                                        ">
                                                    <span style="color:#616161;font-weight:400;">Phone:</span>
                                                    <?php echo esc_html($attendee_phone); ?>
                                                </p>
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

        </td>
    </tr>
</table>

</body>
</html>
