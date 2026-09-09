-- Holds a non-OAuth reschedule request until the attendee confirms it by
-- email. rescheduleMeetingRequest stores the requested changes here as JSON
-- instead of mutating the live row, so the original meeting stays unchanged
-- everywhere (timegrid, external calendars) until confirmBookingRequest
-- applies the payload and clears this column.
ALTER TABLE calendar_booking ADD COLUMN IF NOT EXISTS pending_reschedule JSONB NULL;
