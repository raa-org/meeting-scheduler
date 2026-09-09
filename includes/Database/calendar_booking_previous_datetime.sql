-- Carries the pre-reschedule slot across the two-request non-OAuth
-- reschedule flow (rescheduleMeetingRequest writes it, confirmBookingRequest
-- reads it to render a "rescheduled from X" email, then clears it).
ALTER TABLE calendar_booking ADD COLUMN IF NOT EXISTS previous_datetime TIMESTAMPTZ NULL;
ALTER TABLE calendar_booking ADD COLUMN IF NOT EXISTS previous_timezone VARCHAR(64) NULL;
