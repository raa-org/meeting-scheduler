-- Одноразовый токен для двухзапросного анонимного потока отмены:
-- request_cancel_meeting пишет токен + срок жизни, confirm_cancel_meeting
-- валидирует и очищает обе колонки.
ALTER TABLE calendar_booking ADD COLUMN IF NOT EXISTS cancel_token VARCHAR(64) NULL;
ALTER TABLE calendar_booking ADD COLUMN IF NOT EXISTS cancel_token_expiration TIMESTAMPTZ NULL;
