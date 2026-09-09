ALTER TABLE calendar_schedule
    ADD COLUMN IF NOT EXISTS require_email_verification BOOLEAN NOT NULL DEFAULT FALSE;
