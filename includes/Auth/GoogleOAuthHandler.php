<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\Auth;

use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Apexianlab\Calendar\Database\Connection;
use Apexianlab\Calendar\Repository\CredentialsRepository;
use Apexianlab\Calendar\Service\GoogleCalendarService;

class GoogleOAuthHandler
{
    private static ?self $instance = null;

    private const SESSION_STATE_KEY               = 'google_calendar_oauth_state';
    private const SESSION_VERIFIER_KEY            = 'google_calendar_oauth_verifier';
    private const SESSION_REDIRECT_AFTER_AUTH_KEY = 'google_calendar_oauth_redirect_after_auth';
    /** Logged-in user identity (email/name from the Google profile). */
    private const SESSION_USER_KEY                = 'apexianlab_google_user';

    private const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const DEFAULT_SCOPES = 'openid email profile https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/calendar.events';

    private function __construct()
    {
        $this->init();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private static function credentialsRepo(): CredentialsRepository
    {
        $conn = Connection::getInstance();
        return new CredentialsRepository($conn->pdo(), $conn);
    }

    private function init(): void
    {
        add_action('init', function () {
            add_filter('query_vars', function ($vars) {
                $vars[] = 'google_calendar_callback';
                return $vars;
            });
        });

        add_action('template_redirect', function () {
            // Read from $_GET too, not only get_query_var: the registered query
            // var depends on rewrite rules being flushed, which is unreliable
            // right after a deploy/migration. The OAuth redirect_uri uses the
            // ?google_calendar_callback=1 query form, so $_GET is authoritative.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback carries its own `state` CSRF token, verified in handleCallback().
            if (get_query_var('google_calendar_callback') || !empty($_GET['google_calendar_callback'])) {
                $this->handleCallback();
            }

            // "Sign in with Google" trigger: ?apexianlab_google_login=1 starts the
            // OAuth flow for an anonymous visitor (the Google account becomes the
            // logged-in identity). The flow itself uses a `state` token for CSRF.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (!empty($_GET['apexianlab_google_login']) && !$this->isAuthenticated()) {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $returnUrl = remove_query_arg(['apexianlab_google_login']);
                $this->redirectToAuth(esc_url_raw($returnUrl));
            }
        });

        add_action('wp_ajax_google_calendar_connect_url', [$this, 'handleConnectUrl']);
        add_action('wp_ajax_nopriv_google_calendar_connect_url', [$this, 'handleConnectUrl']);
        add_action('wp_ajax_google_calendar_status', [$this, 'handleStatus']);
        add_action('wp_ajax_nopriv_google_calendar_status', [$this, 'handleStatus']);
        add_action('wp_ajax_google_calendar_list', [$this, 'handleListCalendars']);
        add_action('wp_ajax_nopriv_google_calendar_list', [$this, 'handleListCalendars']);
        add_action('wp_ajax_google_calendar_set', [$this, 'handleSetCalendar']);
        add_action('wp_ajax_nopriv_google_calendar_set', [$this, 'handleSetCalendar']);
        add_action('wp_ajax_oauth2_logout', [$this, 'handleLogout']);
        add_action('wp_ajax_nopriv_oauth2_logout', [$this, 'handleLogout']);
    }

    /**
     * Email of the currently logged-in Google user, or '' when anonymous.
     * The login identity and the schedule-owner identity are the same thing.
     */
    private function getCurrentScheduleOwnerEmail(): string
    {
        $user = apexianlab_get_session(self::SESSION_USER_KEY);
        return is_array($user) && !empty($user['email']) ? (string) $user['email'] : '';
    }

    // ---------------------------------------------------------------------
    //  Session identity API (login provider)
    // ---------------------------------------------------------------------

    public function isAuthenticated(): bool
    {
        $user = apexianlab_get_session(self::SESSION_USER_KEY);
        return is_array($user) && !empty($user['email']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUserInfo(): ?array
    {
        $user = apexianlab_get_session(self::SESSION_USER_KEY);
        return is_array($user) && !empty($user['email']) ? $user : null;
    }

    public function getAccessToken(): ?string
    {
        $email = $this->getCurrentScheduleOwnerEmail();
        return $email !== '' ? $this->getAccessTokenForScheduleEmail($email) : null;
    }

    public function requireAuth(): void
    {
        if ($this->isAuthenticated()) {
            return;
        }
        // The login-gate button points at ?apexianlab_google_login=1. The early
        // template_redirect trigger normally catches it, but when the gate is
        // reached via the page template (template_include runs after
        // template_redirect) that trigger may have been registered too late.
        // Start the OAuth flow directly here so the button always works.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the OAuth flow carries its own `state` CSRF token, verified in handleCallback().
        if (!empty($_GET['apexianlab_google_login'])) {
            $returnUrl = remove_query_arg(['apexianlab_google_login']);
            $this->redirectToAuth(esc_url_raw($returnUrl));
        }
        $this->renderLoginGate();
        exit;
    }

    /**
     * Admin login domain from wp-config SCHEDULE_LOGIN_DOMAIN.
     * Empty means no domain gate (pair with SCHEDULE_ALLOW_ANY_ACCOUNT for demos,
     * or set an explicit domain for production).
     */
    public function allowedAdminDomain(): string
    {
        $domain = defined('SCHEDULE_LOGIN_DOMAIN') ? (string) constant('SCHEDULE_LOGIN_DOMAIN') : '';

        return strtolower(trim(ltrim(trim($domain), '@')));
    }

    /**
     * Demo mode - wp-config SCHEDULE_ALLOW_ANY_ACCOUNT. Opens the admin area to
     * any signed-in Google account. Does not widen isAdminEmailAllowed(), so the
     * cancel-any-meeting privilege stays domain-bound.
     */
    public function allowsAnyAccount(): bool
    {
        return defined('SCHEDULE_ALLOW_ANY_ACCOUNT') && (bool) constant('SCHEDULE_ALLOW_ANY_ACCOUNT');
    }

    /** True when the given email belongs to the configured admin login domain. */
    public function isAdminEmailAllowed(string $email): bool
    {
        $at = strrchr($email, '@');
        $emailDomain = $at === false ? '' : strtolower(trim(substr($at, 1)));

        return $emailDomain !== '' && $emailDomain === $this->allowedAdminDomain();
    }

    /** Drop the logged-in identity from the server session. */
    public function clearSession(): void
    {
        apexianlab_set_session(self::SESSION_USER_KEY, null);
    }

    /** Notice shown when a wrong-domain account hits the admin gate. */
    public function adminAccessNotice(string $email): string
    {
        $email = trim($email);

        return $email !== ''
            ? sprintf('%s has no access here. Sign in with a @%s account.', $email, $this->allowedAdminDomain())
            : sprintf('Access is restricted to @%s accounts.', $this->allowedAdminDomain());
    }

    /** Admin gate: require login + allowed domain, else sign out and show login. */
    public function requireAuthorizedAdmin(): void
    {
        $this->requireAuth();

        // Demo: any signed-in account passes.
        if ($this->allowsAnyAccount()) {
            return;
        }

        $email = (string) (($this->getUserInfo() ?? [])['email'] ?? '');
        if ($this->isAdminEmailAllowed($email)) {
            return;
        }

        $notice = $this->adminAccessNotice($email);
        $this->clearSession();
        $this->renderLoginGate($notice);
        exit;
    }

    /**
     * Gate an anonymous visitor: send them straight into the Google sign-in
     * flow, returning to the page they tried to reach.
     */
    public function renderLoginGate(string $notice = ''): void
    {
        $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''))
            . sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));

        $login_url = add_query_arg('apexianlab_google_login', '1', $currentUrl);

        $base    = defined('APEXIANLAB_MEETING_SCHEDULER_URL') ? APEXIANLAB_MEETING_SCHEDULER_URL : '';
        $version = defined('APEXIANLAB_MEETING_SCHEDULER_VERSION') ? APEXIANLAB_MEETING_SCHEDULER_VERSION : '1';
        $css_url = $base . 'assets/css/auth/login-gate.css?ver=' . rawurlencode((string) $version);

        $templatePath = (defined('APEXIANLAB_MEETING_SCHEDULER_PATH') ? APEXIANLAB_MEETING_SCHEDULER_PATH : '')
            . 'templates/auth/login-gate.php';

        header('Content-Type: text/html; charset=UTF-8');
        if (is_readable($templatePath)) {
            include $templatePath;
        }
    }

    /** Begin the Google OAuth login flow and redirect the browser to Google. */
    public function redirectToAuth(string $returnUrl = ''): void
    {
        if ($this->getClientId() === '' || $this->getClientSecret() === '') {
            wp_die('Google sign-in is not configured.');
        }
        $state    = bin2hex(random_bytes(16));
        $verifier = $this->buildCodeVerifier();
        apexianlab_set_session(self::SESSION_STATE_KEY, $state);
        apexianlab_set_session(self::SESSION_VERIFIER_KEY, $verifier);
        apexianlab_set_session(
            self::SESSION_REDIRECT_AFTER_AUTH_KEY,
            $returnUrl !== '' ? $returnUrl : home_url('/schedule/')
        );

        wp_redirect(self::AUTH_URL . '?' . http_build_query([
            'client_id'              => $this->getClientId(),
            'redirect_uri'           => $this->getRedirectUri(),
            'response_type'          => 'code',
            'scope'                  => $this->getScopes(),
            'state'                  => $state,
            'code_challenge'         => $this->buildCodeChallenge($verifier),
            'code_challenge_method'  => 'S256',
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
        ]));
        exit;
    }

    public function handleLogout(): void
    {
        check_ajax_referer('oauth2_logout', 'nonce');
        apexianlab_set_session(self::SESSION_USER_KEY, null);
        wp_send_json_success(['logged_out' => true]);
    }

    private function getClientId(): string
    {
        if (defined('GOOGLE_CALENDAR_CLIENT_ID') && (string) GOOGLE_CALENDAR_CLIENT_ID !== '') {
            return trim((string) GOOGLE_CALENDAR_CLIENT_ID);
        }
        return '';
    }

    private function getClientSecret(): string
    {
        if (defined('GOOGLE_CALENDAR_CLIENT_SECRET') && (string) GOOGLE_CALENDAR_CLIENT_SECRET !== '') {
            return trim((string) GOOGLE_CALENDAR_CLIENT_SECRET);
        }
        return '';
    }

    private function getScopes(): string
    {
        if (defined('GOOGLE_CALENDAR_SCOPES') && (string) GOOGLE_CALENDAR_SCOPES !== '') {
            return (string) GOOGLE_CALENDAR_SCOPES;
        }
        return self::DEFAULT_SCOPES;
    }

    private function getRedirectUri(): string
    {
        if (defined('GOOGLE_CALENDAR_REDIRECT_URI') && (string) GOOGLE_CALENDAR_REDIRECT_URI !== '') {
            return (string) GOOGLE_CALENDAR_REDIRECT_URI;
        }
        return add_query_arg('google_calendar_callback', '1', home_url('/'));
    }

    private function buildCodeVerifier(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function buildCodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function handleConnectUrl(): void
    {
        check_ajax_referer('google_calendar_nonce', 'nonce');

        // No prior-auth gate: this is the "Sign in with Google" entry point.
        if ($this->getClientId() === '' || $this->getClientSecret() === '') {
            wp_send_json_error(['message' => 'Google Calendar is not configured'], 400);
        }

        $state    = bin2hex(random_bytes(16));
        $verifier = $this->buildCodeVerifier();
        apexianlab_set_session(self::SESSION_STATE_KEY, $state);
        apexianlab_set_session(self::SESSION_VERIFIER_KEY, $verifier);

        // Restrict the post-auth return target to this site to prevent an
        // open redirect: a hostile ?redirect=https://evil.example would otherwise
        // be honoured after a successful Google sign-in.
        $redirectAfterAuth = isset($_POST['redirect'])
            ? wp_validate_redirect(esc_url_raw(wp_unslash((string) $_POST['redirect'])), home_url('/schedule/'))
            : home_url('/schedule/');
        apexianlab_set_session(self::SESSION_REDIRECT_AFTER_AUTH_KEY, $redirectAfterAuth);

        $params = [
            'client_id'              => $this->getClientId(),
            'redirect_uri'           => $this->getRedirectUri(),
            'response_type'          => 'code',
            'scope'                  => $this->getScopes(),
            'state'                  => $state,
            'code_challenge'         => $this->buildCodeChallenge($verifier),
            'code_challenge_method'  => 'S256',
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
        ];

        $url = self::AUTH_URL . '?' . http_build_query($params);
        wp_send_json_success(['url' => $url]);
    }

    public function handleCallback(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback carries its own `state` token, verified below.
        $state = isset($_GET['state']) ? (string) $_GET['state'] : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $code  = isset($_GET['code']) ? (string) $_GET['code'] : '';

        // No code → user declined consent (Google strips ?error from $_GET).
        if ($code === '') {
            apexianlab_set_session(self::SESSION_STATE_KEY, null);
            apexianlab_set_session(self::SESSION_VERIFIER_KEY, null);
            $afterAuth = (string) apexianlab_get_session(self::SESSION_REDIRECT_AFTER_AUTH_KEY, '');
            apexianlab_set_session(self::SESSION_REDIRECT_AFTER_AUTH_KEY, null);
            // Cancelled initial login → back to the login page; cancelled
            // re-consent (already signed in) → back where they were.
            $target = ($this->isAuthenticated() && $afterAuth !== '')
                ? $afterAuth
                : home_url('/schedule/');
            wp_safe_redirect(wp_validate_redirect($target, home_url('/schedule/')));
            exit;
        }

        if ($state === '') {
            wp_die('Invalid callback parameters.');
        }

        $sessionState = (string) apexianlab_get_session(self::SESSION_STATE_KEY, '');
        if ($sessionState === '' || !hash_equals($sessionState, $state)) {
            // Stale or replayed callback (back button, old tab, or a re-login
            // after logout where the address bar still holds an old callback
            // URL). Restart the flow once instead of dead-ending; a session
            // guard prevents an infinite loop if the session truly can't hold
            // the state.
            $retried = (int) apexianlab_get_session('google_oauth_retry', 0);
            if ($retried < 1) {
                apexianlab_set_session('google_oauth_retry', $retried + 1);
                $this->redirectToAuth(home_url('/schedule/'));
            }
            apexianlab_set_session('google_oauth_retry', 0);
            wp_die('Sign-in session expired. Please open the sign-in page again.');
        }
        apexianlab_set_session('google_oauth_retry', 0);
        $verifier = (string) apexianlab_get_session(self::SESSION_VERIFIER_KEY, '');
        if ($verifier === '') {
            wp_die('Code verifier not found.');
        }

        $tokenData = $this->exchangeCodeForToken($code, $verifier);
        if (empty($tokenData)) {
            wp_die('Unable to exchange authorization code.');
        }

        $accessToken  = (string) ($tokenData['access_token'] ?? '');
        $refreshToken = isset($tokenData['refresh_token']) ? (string) $tokenData['refresh_token'] : null;
        $expiresIn    = (int) ($tokenData['expires_in'] ?? 3600);
        $expiresAt    = time() + max(300, $expiresIn - 120);
        $grantedScopes = isset($tokenData['scope']) ? (string) $tokenData['scope'] : null;

        $userInfo = $this->fetchUserInfo($accessToken);
        if (empty($userInfo) || empty($userInfo['sub'])) {
            wp_die('Unable to load Google profile.');
        }

        $googleEmail = !empty($userInfo['email']) ? (string) $userInfo['email'] : '';
        if ($googleEmail === '') {
            wp_die('Google account email is unavailable.');
        }

        // The Google account is the logged-in identity AND the schedule owner.
        // Persist the token keyed by that email so background calendar work
        // (availability, event push) can run without an active session.
        $saved = self::credentialsRepo()->upsertGoogle(
            $googleEmail,
            (string) $userInfo['sub'],
            $googleEmail,
            $accessToken,
            $refreshToken,
            $expiresAt,
            $grantedScopes
        );
        if (!$saved) {
            wp_die('Unable to save credentials.');
        }

        apexianlab_set_session(self::SESSION_USER_KEY, [
            'email'       => $googleEmail,
            'name'        => (string) ($userInfo['name'] ?? ''),
            'given_name'  => (string) ($userInfo['given_name'] ?? ''),
            'family_name' => (string) ($userInfo['family_name'] ?? ''),
            'picture'     => (string) ($userInfo['picture'] ?? ''),
        ]);

        apexianlab_set_session(self::SESSION_STATE_KEY, null);
        apexianlab_set_session(self::SESSION_VERIFIER_KEY, null);

        $redirectUrl = (string) apexianlab_get_session(self::SESSION_REDIRECT_AFTER_AUTH_KEY, home_url('/schedule/'));
        apexianlab_set_session(self::SESSION_REDIRECT_AFTER_AUTH_KEY, null);
        // Validate at the consumption point too: this is the single chokepoint
        // for the post-auth bounce, so any tainted session value is contained here.
        $redirectUrl = wp_validate_redirect($redirectUrl, home_url('/schedule/'));
        wp_safe_redirect($redirectUrl);
        exit;
    }

    private function exchangeCodeForToken(string $code, string $codeVerifier): ?array
    {
        $response = wp_remote_post(self::TOKEN_URL, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept'       => 'application/json',
            ],
            'body' => http_build_query([
                'client_id'     => $this->getClientId(),
                'client_secret' => $this->getClientSecret(),
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->getRedirectUri(),
                'code_verifier' => $codeVerifier,
            ]),
            'timeout'   => 30,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        $httpCode = wp_remote_retrieve_response_code($response);
        $body     = json_decode(wp_remote_retrieve_body($response), true);
        if ($httpCode !== 200 || empty($body['access_token'])) {
            return null;
        }
        return is_array($body) ? $body : null;
    }

    private function refreshToken(string $refreshToken): ?array
    {
        $response = wp_remote_post(self::TOKEN_URL, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept'       => 'application/json',
            ],
            'body' => http_build_query([
                'client_id'     => $this->getClientId(),
                'client_secret' => $this->getClientSecret(),
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]),
            'timeout'   => 30,
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $httpCode = wp_remote_retrieve_response_code($response);
        $body     = json_decode(wp_remote_retrieve_body($response), true);
        if ($httpCode !== 200 || empty($body['access_token'])) {
            return null;
        }
        return is_array($body) ? $body : null;
    }

    private function fetchUserInfo(string $accessToken): ?array
    {
        $response = wp_remote_get(self::USERINFO_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept'        => 'application/json',
            ],
            'timeout'   => 20,
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) ? $body : null;
    }

    public function getAccessTokenForScheduleEmail(string $scheduleEmail): ?string
    {
        $row = self::credentialsRepo()->getGoogleByEmail($scheduleEmail);
        if (empty($row)) {
            return null;
        }

        $expiresAtRaw = (string) ($row['token_expires_at'] ?? '');
        $expiresAt    = $expiresAtRaw !== '' ? strtotime($expiresAtRaw) : 0;
        if ($expiresAt > time() + 60) {
            return (string) ($row['access_token'] ?? '');
        }

        $refreshToken = isset($row['refresh_token']) ? (string) $row['refresh_token'] : '';
        if ($refreshToken === '') {
            return null;
        }
        $tokenData = $this->refreshToken($refreshToken);
        if (empty($tokenData['access_token'])) {
            return null;
        }

        $newAccessToken  = (string) $tokenData['access_token'];
        $newRefreshToken = !empty($tokenData['refresh_token']) ? (string) $tokenData['refresh_token'] : $refreshToken;
        $expiresIn       = (int) ($tokenData['expires_in'] ?? 3600);
        $newExpiresAt    = time() + max(300, $expiresIn - 120);
        $googleUserId    = (string) ($row['google_user_id'] ?? '');
        $googleEmail     = (string) ($row['google_email'] ?? '');
        $grantedScopes   = isset($tokenData['scope']) ? (string) $tokenData['scope'] : null;

        if (!self::credentialsRepo()->upsertGoogle(
            $scheduleEmail,
            $googleUserId,
            $googleEmail,
            $newAccessToken,
            $newRefreshToken,
            $newExpiresAt,
            $grantedScopes
        )) {
            return null;
        }

        return $newAccessToken;
    }

    /**
     * @return array{conflict: ?bool, blocking: ?array<string, mixed>}
     */
    public function evaluateCalendarConflict(
        string $scheduleEmail,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?string $excludeEventId = null
    ): array {
        $token = $this->getAccessTokenForScheduleEmail($scheduleEmail);
        if ($token === null || $token === '') {
            return ['conflict' => null, 'blocking' => null];
        }

        $busy = GoogleCalendarService::freeBusy($token, $start, $end, [$this->getSelectedCalendarId($scheduleEmail)]);
        if ($busy === null) {
            return ['conflict' => null, 'blocking' => null];
        }

        $slotStartTs = $this->toUtcTimestamp($start);
        $slotEndTs   = $this->toUtcTimestamp($end);

        foreach ($busy as $interval) {
            $bs = strtotime((string) ($interval['start'] ?? ''));
            $be = strtotime((string) ($interval['end'] ?? ''));
            if ($bs === false || $be === false) {
                continue;
            }
            if ($bs < $slotEndTs && $be > $slotStartTs) {
                return [
                    'conflict' => true,
                    'blocking' => [
                        'start' => $interval['start'] ?? null,
                        'end'   => $interval['end'] ?? null,
                    ],
                ];
            }
        }

        return ['conflict' => false, 'blocking' => null];
    }

    public function hasCalendarConflictForScheduleEmail(
        string $scheduleEmail,
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?string $excludeEventId = null
    ): ?bool {
        return $this->evaluateCalendarConflict($scheduleEmail, $start, $end, $excludeEventId)['conflict'];
    }

    /**
     * Google FreeBusy busy intervals for booking grid. UTC DateTime instances.
     *
     * @return array<int, array{start: DateTime, end: DateTime}>|null null = no token or API error
     */
    public function fetchGoogleBusyIntervalsUtc(
        string $scheduleEmail,
        DateTimeInterface $rangeStartUtc,
        DateTimeInterface $rangeEndUtc
    ): ?array {
        $token = $this->getAccessTokenForScheduleEmail($scheduleEmail);
        if ($token === null || $token === '') {
            return null;
        }

        $busy = GoogleCalendarService::freeBusy($token, $rangeStartUtc, $rangeEndUtc, [$this->getSelectedCalendarId($scheduleEmail)]);
        if ($busy === null) {
            return null;
        }

        $out = [];
        foreach ($busy as $interval) {
            $startTs = strtotime((string) ($interval['start'] ?? ''));
            $endTs   = strtotime((string) ($interval['end'] ?? ''));
            if ($startTs === false || $endTs === false || $startTs >= $endTs) {
                continue;
            }
            $start = new DateTime('@' . $startTs);
            $start->setTimezone(new DateTimeZone('UTC'));
            $end = new DateTime('@' . $endTs);
            $end->setTimezone(new DateTimeZone('UTC'));
            $out[] = ['start' => $start, 'end' => $end];
        }

        return $out;
    }

    private function toUtcTimestamp(DateTimeInterface $dt): int
    {
        $mutable = $dt instanceof \DateTimeImmutable ? DateTime::createFromImmutable($dt) : clone $dt;
        $mutable->setTimezone(new DateTimeZone('UTC'));
        return $mutable->getTimestamp();
    }

    /**
     * The owner's chosen Google calendar id, or 'primary' when none is set.
     */
    public function getSelectedCalendarId(string $scheduleEmail): string
    {
        $row = self::credentialsRepo()->getGoogleByEmail($scheduleEmail);
        $sel = is_array($row) ? trim((string) ($row['selected_calendar_id'] ?? '')) : '';

        return $sel !== '' ? $sel : 'primary';
    }

    /** AJAX: list the owner's writable Google calendars + the current choice. */
    public function handleListCalendars(): void
    {
        check_ajax_referer('google_calendar_nonce', 'nonce');
        $email = $this->getCurrentScheduleOwnerEmail();
        if ($email === '') {
            wp_send_json_error(['message' => 'Unauthorized'], 401);
        }

        $token = $this->getAccessTokenForScheduleEmail($email);
        if ($token === null || $token === '') {
            wp_send_json_success(['connected' => false, 'calendars' => []]);
        }

        $calendars = GoogleCalendarService::listCalendars($token);
        if ($calendars === null) {
            wp_send_json_error(['message' => 'Unable to load Google calendars']);
        }

        // Google returns the primary calendar's summary as the bare email. Show
        // the account's display name instead so it matches the Google sidebar.
        $user        = apexianlab_get_session(self::SESSION_USER_KEY);
        $displayName = is_array($user) && !empty($user['name']) ? (string) $user['name'] : '';
        if ($displayName !== '') {
            foreach ($calendars as &$cal) {
                if (!empty($cal['primary'])) {
                    $cal['summary'] = $displayName;
                }
            }
            unset($cal);
        }

        wp_send_json_success([
            'connected' => true,
            'calendars' => $calendars,
            'selected'  => $this->getSelectedCalendarId($email),
        ]);
    }

    /** AJAX: save the owner's chosen Google calendar. */
    public function handleSetCalendar(): void
    {
        check_ajax_referer('google_calendar_nonce', 'nonce');
        $email = $this->getCurrentScheduleOwnerEmail();
        if ($email === '') {
            wp_send_json_error(['message' => 'Unauthorized'], 401);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $calendarId = isset($_POST['calendar_id']) ? sanitize_text_field(wp_unslash((string) $_POST['calendar_id'])) : '';

        // Validate against the user's writable calendars (skip for 'primary'/reset).
        if ($calendarId !== '' && $calendarId !== 'primary') {
            $token = $this->getAccessTokenForScheduleEmail($email);
            $calendars = $token !== null && $token !== '' ? GoogleCalendarService::listCalendars($token) : null;
            $ids = is_array($calendars) ? array_column($calendars, 'id') : [];
            if (!in_array($calendarId, $ids, true)) {
                wp_send_json_error(['message' => 'Calendar is not in your account']);
            }
        }

        self::credentialsRepo()->setGoogleSelectedCalendar($email, $calendarId);
        wp_send_json_success(['saved' => true, 'selected' => $calendarId !== '' ? $calendarId : 'primary']);
    }

    public function handleStatus(): void
    {
        check_ajax_referer('google_calendar_nonce', 'nonce');
        $ownerEmail = $this->getCurrentScheduleOwnerEmail();
        if ($ownerEmail === '') {
            wp_send_json_error(['message' => 'Unauthorized'], 401);
        }

        $row = self::credentialsRepo()->getGoogleByEmail($ownerEmail);
        if (empty($row)) {
            wp_send_json_success(['connected' => false]);
        }

        $token = $this->getAccessTokenForScheduleEmail($ownerEmail);
        if ($token === null || $token === '') {
            wp_send_json_success(['connected' => false]);
        }

        $missing = $this->getMissingScopesForEmail($ownerEmail);

        wp_send_json_success([
            'connected'      => true,
            'email'          => (string) ($row['google_email'] ?? ''),
            'scopes_ok'      => $missing === [],
            'missing_scopes' => array_values(array_map([self::class, 'scopeLabel'], $missing)),
        ]);
    }

    /**
     * @return list<string>
     */
    private function getRequiredScopes(): array
    {
        $all    = preg_split('/\s+/', trim($this->getScopes())) ?: [];
        $ignore = ['openid', 'email', 'profile'];
        $out    = [];
        foreach ($all as $scope) {
            $scope = trim((string) $scope);
            if ($scope === '' || in_array($scope, $ignore, true)) {
                continue;
            }
            $out[] = $scope;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function getMissingScopesForEmail(string $email): array
    {
        try {
            $granted = trim((string) (self::credentialsRepo()->getGrantedScopes($email) ?? ''));
            if ($granted === '') {
                return [];
            }

            $grantedSet = preg_split('/\s+/', $granted) ?: [];
            $missing    = [];
            foreach ($this->getRequiredScopes() as $scope) {
                if (!in_array($scope, $grantedSet, true)) {
                    $missing[] = $scope;
                }
            }

            return $missing;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function scopeLabel(string $scope): string
    {
        $map = [
            'https://www.googleapis.com/auth/calendar'        => 'View and edit your Google Calendar events',
            'https://www.googleapis.com/auth/calendar.events' => 'Create and manage calendar events',
            'https://www.googleapis.com/auth/gmail.send'      => 'Send email on your behalf',
            'https://mail.google.com/'                        => 'Send email on your behalf',
        ];

        return $map[$scope] ?? $scope;
    }
}
