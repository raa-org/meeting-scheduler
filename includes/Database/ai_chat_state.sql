CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE TABLE IF NOT EXISTS ai_chat_state (
    chat_id UUID PRIMARY KEY REFERENCES ai_chats(id) ON DELETE CASCADE,
    state VARCHAR(64) NOT NULL DEFAULT 'idle',
    pending_action VARCHAR(128) NULL,
    active_conversation_id UUID NULL,
    context_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ai_chat_state_state_idx
    ON ai_chat_state (state);

CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
NEW.updated_at = NOW();
RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_ai_chat_state_updated_at
    BEFORE UPDATE ON ai_chat_state
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

