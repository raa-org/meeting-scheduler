<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php the_title(); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class(!empty($apexianlab_timegrid_booking ?? false) ? ['apexianlab-timegrid-booking'] : []); ?>>
<?php
$initials = '';
$userInfo = null;
$isAuthenticated = false;
if (class_exists('Apexianlab\\Calendar\\Auth\\GoogleOAuthHandler')) {
    $googleAuth = \Apexianlab\Calendar\Auth\GoogleOAuthHandler::getInstance();
    $isAuthenticated = $googleAuth->isAuthenticated();
    $userInfo = $googleAuth->getUserInfo();
}
if ($userInfo) {
    $firstName = $userInfo['given_name'] ?? $userInfo['name'] ?? '';
    $lastName = $userInfo['family_name'] ?? '';
    if (!empty($firstName) && !empty($lastName)) {
        $initials = mb_strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1));
    } elseif (!empty($firstName)) {
        $initials = mb_strtoupper(mb_substr($firstName, 0, 1));
    } elseif (!empty($userInfo['email'])) {
        $initials = mb_strtoupper(mb_substr($userInfo['email'], 0, 1));
    }
}

?>
<header class="header">
    <a href="#" class="header__logo">
        <img src="<?php echo esc_url(APEXIANLAB_MEETING_SCHEDULER_URL . 'assets/images/apexianlab-logo.svg'); ?>" alt="apexianlab" />
    </a>
    <?php if ($isAuthenticated): ?>
        <div class="user-menu">
            <button class="user-menu__toggle" aria-label="User menu">
                <span class="user-menu__initials"><?php echo esc_html($initials !== '' ? $initials : 'U'); ?></span>
            </button>
            <div class="user-menu__dropdown">
                <?php if (!empty($userInfo)): ?>
                    <div class="user-menu__info">
                        <?php if (!empty($userInfo['name'])): ?>
                            <div class="user-menu__name"><?php echo esc_html($userInfo['name']); ?></div>
                        <?php endif; ?>

                        <?php if (!empty($userInfo['email'])): ?>
                            <div class="user-menu__email"><?php echo esc_html($userInfo['email']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <button class="user-menu__logout" data-logout-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                    Logout
                </button>
            </div>
        </div>
    <?php else: ?>
        <a href="<?php echo esc_url(add_query_arg('apexianlab_google_login', '1')); ?>" class="header__login-btn" id="apexianlab-login-open">Sign in with Google</a>
    <?php endif;?>
</header>
<main class="booking">