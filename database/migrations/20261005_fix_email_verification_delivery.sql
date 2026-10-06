ALTER TABLE students
  MODIFY email_verified_at DATETIME NULL DEFAULT NULL;

ALTER TABLE email_verification_tokens
  ADD COLUMN sent_at DATETIME NULL AFTER expires_at;
