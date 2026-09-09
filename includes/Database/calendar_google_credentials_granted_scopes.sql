ALTER TABLE calendar_google_credentials
    ADD COLUMN IF NOT EXISTS granted_scopes TEXT;
