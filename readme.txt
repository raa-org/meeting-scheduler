=== Apexianlab Meeting Scheduler ===
Contributors: apexianlab
Tags: calendar, booking, google-calendar, meetings, scheduling, appointments
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted meeting scheduling for WordPress with Google Calendar and an optional AI booking assistant.

== Description ==

Apexianlab Meeting Scheduler keeps booking on your WordPress site instead of a third-party SaaS calendar.

* Organisers sign in with Google; the same OAuth grant powers Calendar access
* Availability from Google Calendar FreeBusy
* Confirmed meetings become Calendar events (with Google Meet when available)
* Recurring and one-off schedules, short public booking links
* Email confirmation, cancel, and reschedule flows
* Optional LLM assistant to find slots, book, reschedule, and cancel in chat

**Requirements (not bundled):** PostgreSQL 13+, a Google Cloud OAuth 2.0 client with the Calendar API enabled, and outbound mail (SMTP or Gmail API). Configuration is done via `wp-config.php` constants — see the plugin README.

On activation the plugin creates the `cal`, `schedule`, and `ai-assistant` pages and flushes rewrite rules. No special theme is required.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/apexianlab-meeting-scheduler/` or install the zip via **Plugins → Add New**.
2. Activate the plugin through the **Plugins** screen.
3. Add PostgreSQL and Google OAuth constants to `wp-config.php` (see README / FAQ).
4. Under **Settings → Permalinks**, use a pretty structure (e.g. Post name) if needed.
5. In production, disable WP’s internal cron and hit `wp-cron.php` from a real cron job.

== Frequently Asked Questions ==

= Does this replace Calendly? =

It provides self-hosted booking on WordPress backed by Google Calendar. Guests stay on your site; you keep the data and keys.

= Why PostgreSQL instead of the WordPress MySQL database? =

Schedules, bookings, and encrypted Google tokens are stored in a dedicated PostgreSQL database. WordPress continues to use its usual MySQL/MariaDB database for pages and options.

= Do I need a special theme? =

No. Page templates and captcha ship inside the plugin.

= How do I configure Google sign-in? =

Create an OAuth 2.0 client in Google Cloud (Calendar API enabled). Register redirect URI `https://your-site.example/?google_calendar_callback=1`. Set `GOOGLE_CALENDAR_CLIENT_ID` and `GOOGLE_CALENDAR_CLIENT_SECRET` in `wp-config.php`.

= Is the AI assistant required? =

No. Set `APEXIANLAB_LLM_BASE_URL` (and optional model / API key) only if you want the chat assistant.

== Screenshots ==

1. Schedule management for organisers
2. Public booking flow
3. Optional AI assistant

== Changelog ==

= 1.2 =
* Public-prep: auto-create routing pages on activation
* Captcha served by the plugin (any theme)
* Admin notices when PostgreSQL or Google OAuth constants are missing
* Documentation and GPLv2 LICENSE for open-source release

== Upgrade Notice ==

= 1.2 =
Activation creates/updates the cal, schedule, and ai-assistant pages. Captcha no longer depends on the theme.
