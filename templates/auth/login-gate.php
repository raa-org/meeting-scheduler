<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Standalone login-gate page shown to anonymous visitors before Google sign-in.
 *
 * Expected vars (provided by GoogleOAuthHandler::renderLoginGate()):
 *   string $login_url  Google sign-in trigger URL.
 *   string $css_url    Stylesheet URL (already cache-busted).
 */

if (!isset($login_url)) {
    return;
}

$css_url = isset($css_url) ? (string) $css_url : '';
$notice  = isset($notice) ? (string) $notice : '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in</title>
<?php if ($css_url !== '') : ?>
<link rel="stylesheet" href="<?php echo esc_url($css_url); ?>" />
<?php endif; ?>
</head>
<body>
<div class="ion-login-modal" role="dialog" aria-modal="true" aria-labelledby="ion-login-modal-title">
    <div class="ion-login-modal__backdrop"></div>
    <div class="ion-login-modal__box">
        <h2 class="ion-login-modal__title" id="ion-login-modal-title">Sign in to Your Account</h2>
        <?php if ($notice !== '') : ?>
        <p class="ion-login-modal__notice" role="alert"><?php echo esc_html($notice); ?></p>
        <?php endif; ?>
        <a class="ion-login-modal__sso" href="<?php echo esc_url($login_url); ?>">
            <svg viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.71-1.57 2.68-3.88 2.68-6.62z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/><path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/></svg>
            Sign in with Google
        </a>
    </div>
</div>
</body>
</html>
