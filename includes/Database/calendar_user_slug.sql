CREATE TABLE IF NOT EXISTS calendar_user_slug (
    email      VARCHAR(320) PRIMARY KEY,
    slug       VARCHAR(64)  NOT NULL,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS calendar_user_slug_slug_uq
    ON calendar_user_slug (slug);
