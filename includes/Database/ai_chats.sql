CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE TABLE IF NOT EXISTS ai_chats (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NULL,
    calendar_schedule_id UUID NULL REFERENCES calendar_schedule(id) ON DELETE SET NULL,
    calendar_email VARCHAR(255) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS ai_chats_user_id_idx
    ON ai_chats (user_id)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS ai_chats_calendar_schedule_id_idx
    ON ai_chats (calendar_schedule_id)
    WHERE deleted_at IS NULL;

CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
NEW.updated_at = NOW();
RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_ai_chats_updated_at
    BEFORE UPDATE ON ai_chats
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();
