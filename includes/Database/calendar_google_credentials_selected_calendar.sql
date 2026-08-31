-- Per-owner chosen Google calendar (when the account has more than one).
-- NULL/empty means use the account's primary calendar. Idempotent, additive.
ALTER TABLE calendar_google_credentials
    ADD COLUMN IF NOT EXISTS selected_calendar_id VARCHAR(320);
