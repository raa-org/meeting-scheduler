CREATE TABLE IF NOT EXISTS calendar_google_credentials (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    schedule_email VARCHAR(320) NOT NULL UNIQUE,
    google_user_id VARCHAR(128) NOT NULL,
    google_email VARCHAR(320) NOT NULL,
    access_token_enc TEXT NOT NULL,
    refresh_token_enc TEXT,
    token_expires_at TIMESTAMPTZ NOT NULL,
    selected_calendar_id VARCHAR(320),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
NEW.updated_at = NOW();
RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_calendar_google_credentials_updated_at
    BEFORE UPDATE ON calendar_google_credentials
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();
