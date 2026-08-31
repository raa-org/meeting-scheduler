# Apexianlab Meeting Scheduler

**White Paper**

Self-hosted scheduling for WordPress, with Google Calendar and an optional AI booking assistant.

## Problem

Teams still run WordPress, but booking usually lives in a SaaS calendar: another vendor, another bill, guest data off-site.

## Solution

Apexianlab Meeting Scheduler keeps scheduling on your WordPress site. Organisers sign in with Google; availability is read from Google Calendar (FreeBusy); confirmed meetings become Calendar events with Google Meet. Guests book through a public page. Optionally, an LLM assistant can find slots, book, reschedule, and cancel in chat.

## How it works

The plugin is a WordPress module with its own PostgreSQL database (schedules, bookings, encrypted Google tokens). Google OAuth covers both sign-in and Calendar access (PKCE). Email goes through SMTP or Gmail API. Recurring and one-off availability, short links (`/cal/…`), email verification, cancel/reschedule tokens, and WP-Cron for mail and Calendar sync. The AI assistant is optional: any OpenAI-compatible LLM plus optional speech-to-text.

## Who it is for

Organisations that already run WordPress and Google Workspace and want Calendly-like booking without sending guests to a third-party SaaS.

Typical setup: PHP 8.2+, WordPress 6.0+, PostgreSQL 13+, a Google Cloud OAuth client, and a real cron hitting `wp-cron.php`.

## Open source

Licensed under GPLv2 or later. Configuration stays in `wp-config.php` (database, OAuth, mail, LLM). No SaaS lock-in: you host the plugin, the database, and the keys.
