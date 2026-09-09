ALTER TABLE calendar_schedule DROP CONSTRAINT IF EXISTS calendar_schedule_schedule_repeat_check;
ALTER TABLE calendar_schedule ADD CONSTRAINT calendar_schedule_schedule_repeat_check
    CHECK (schedule_repeat IN ('does_not_repeat', 'weekly', 'custom'));
