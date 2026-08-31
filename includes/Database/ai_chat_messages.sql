CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE TABLE IF NOT EXISTS ai_chat_messages (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    chat_id UUID NOT NULL REFERENCES ai_chats(id) ON DELETE CASCADE,
    conversation_id UUID NOT NULL,
    role VARCHAR(32) NOT NULL,
    content TEXT NOT NULL,
    user_action JSONB NOT NULL DEFAULT '{}'::jsonb,
    assistant_message_meta JSONB NOT NULL DEFAULT '{}'::jsonb,
    ui_actions JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS ai_chat_messages_chat_id_idx
    ON ai_chat_messages (chat_id);

CREATE INDEX IF NOT EXISTS ai_chat_messages_chat_id_created_idx
    ON ai_chat_messages (chat_id, created_at);

CREATE INDEX IF NOT EXISTS ai_chat_messages_chat_conversation_created_idx
    ON ai_chat_messages (chat_id, conversation_id, created_at);

CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
NEW.updated_at = NOW();
RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_ai_chat_messages_updated_at
    BEFORE UPDATE ON ai_chat_messages
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();
