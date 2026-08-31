ALTER TABLE calendar_schedule ALTER COLUMN booking_short_id TYPE VARCHAR(11);

CREATE SEQUENCE IF NOT EXISTS calendar_schedule_short_id_seq
    AS BIGINT
    START WITH 1000000
    INCREMENT BY 1
    NO CYCLE;
