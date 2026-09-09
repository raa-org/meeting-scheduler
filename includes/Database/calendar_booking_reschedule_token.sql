-- Одноразовый токен для двухзапросного анонимного потока переноса встречи:
-- rescheduleWithEmailConfirmation пишет токен + срок жизни + новую дату/время в previous_datetime,
-- confirm_reschedule валидирует токен и переносит данные из temporary fields в основные.
ALTER TABLE calendar_booking ADD COLUMN IF NOT EXISTS reschedule_token VARCHAR(64) NULL;
ALTER TABLE calendar_booking ADD COLUMN IF NOT EXISTS reschedule_token_expiration TIMESTAMPTZ NULL;
