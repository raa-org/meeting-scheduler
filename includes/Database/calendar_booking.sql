CREATE TABLE IF NOT EXISTS calendar_booking (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    calendar_schedule_id UUID REFERENCES calendar_schedule(id),
    email VARCHAR(320) NOT NULL,
    phone VARCHAR(32),
    first_name VARCHAR(32) NOT NULL,
    last_name VARCHAR(32) NOT NULL,
    subject VARCHAR(128) NOT NULL,
    description VARCHAR(512),
    duration SMALLINT NOT NULL,
    datetime TIMESTAMPTZ NOT NULL,
    timezone VARCHAR(64) NOT NULL,
    email_sent BOOLEAN NOT NULL DEFAULT FALSE,
    meeting_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
    expiration_of_confirmation TIMESTAMPTZ NOT NULL DEFAULT NOW() +  INTERVAL '1 hour',
    number_attempts INTEGER NOT NULL DEFAULT 5,
    expiration_of_mail_block TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    confirmation_token VARCHAR(256),
    meeting_join_url VARCHAR(511),
    google_event_id VARCHAR(255),
    google_calendar_id VARCHAR(255),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ NULL
    );

CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
NEW.updated_at = NOW();
RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_calendar_booking_updated_at
    BEFORE UPDATE ON calendar_booking
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

CREATE OR REPLACE FUNCTION check_number_attempts()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.number_attempts = 0 THEN
        NEW.number_attempts := 5;
        NEW.expiration_of_mail_block := NOW() + INTERVAL '15 minutes';
END IF;
RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_calendar_booking_number_attempts
    BEFORE UPDATE ON calendar_booking
    FOR EACH ROW
    EXECUTE FUNCTION check_number_attempts();
