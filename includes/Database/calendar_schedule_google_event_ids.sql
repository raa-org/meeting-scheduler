-- Google Calendar event IDs created to mirror a schedule's availability
-- windows (recurring, transparent/free). JSON array of event id strings.
-- NULL means nothing was pushed (e.g. non-Google means, or not connected).
-- Idempotent, additive — safe for the shared database.
ALTER TABLE calendar_schedule
    ADD COLUMN IF NOT EXISTS google_schedule_event_ids JSONB;
