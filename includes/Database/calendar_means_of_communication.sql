CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE TABLE IF NOT EXISTS calendar_means_of_communication (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title VARCHAR (63) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS calendar_means_of_communication_title_uq
    ON calendar_means_of_communication (title);

INSERT INTO calendar_means_of_communication (title)
VALUES
    ('Google Calendar')
ON CONFLICT (title) DO NOTHING;

CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
NEW.updated_at = NOW();
RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_calendar_means_of_communication_updated_at
    BEFORE UPDATE ON calendar_means_of_communication
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();
