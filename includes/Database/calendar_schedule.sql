CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE TABLE IF NOT EXISTS calendar_schedule (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    booking_short_id VARCHAR(11),
    calendar_means_of_communication_id UUID REFERENCES calendar_means_of_communication(id),
    email VARCHAR(320) NOT NULL,
    name VARCHAR(64) NOT NULL,
    subject VARCHAR(128) NOT NULL,
    description VARCHAR(512),
    color INTEGER,
    reminder_minutes INTEGER, -- short int
    schedule_ranges JSONB NOT NULL,
    is_public BOOLEAN DEFAULT false,
    schedule_repeat VARCHAR(32) NOT NULL DEFAULT 'weekly'
        CHECK (schedule_repeat IN ('does_not_repeat', 'weekly', 'custom')),
    repeat_interval_weeks SMALLINT NOT NULL DEFAULT 1
        CHECK (repeat_interval_weeks >= 1 AND repeat_interval_weeks <= 52),
    schedule_weekly_rule JSONB NULL,
    name_format VARCHAR(32) NOT NULL DEFAULT 'full',
    name_format_custom VARCHAR(128) NULL,
    durations JSONB NOT NULL DEFAULT '[15,30,45,60,90]'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    prodid VARCHAR(32) NOT NULL,
    deleted_at TIMESTAMPTZ NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS calendar_schedule_booking_short_id_uq
    ON calendar_schedule (booking_short_id)
    WHERE deleted_at IS NULL AND booking_short_id IS NOT NULL;

CREATE SEQUENCE IF NOT EXISTS calendar_schedule_short_id_seq
    AS BIGINT
    START WITH 1000000
    INCREMENT BY 1
    NO CYCLE;

CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
NEW.updated_at = NOW();
RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_calendar_schedule_updated_at
    BEFORE UPDATE ON calendar_schedule
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();
