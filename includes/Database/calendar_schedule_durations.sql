ALTER TABLE calendar_schedule
    ADD COLUMN IF NOT EXISTS durations JSONB NOT NULL DEFAULT '[15,30,45,60,90]'::jsonb;
