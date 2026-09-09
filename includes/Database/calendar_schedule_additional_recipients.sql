ALTER TABLE calendar_schedule
    ADD COLUMN IF NOT EXISTS additional_recipients JSONB NOT NULL DEFAULT '[]'::jsonb;
