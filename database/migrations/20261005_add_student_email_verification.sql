ALTER TABLE students
  ADD COLUMN email_verified_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;

UPDATE students
SET email_verified_at = UTC_TIMESTAMP()
WHERE email_verified_at IS NULL;

CREATE TABLE email_verification_tokens (
  id CHAR(36) NOT NULL,
  student_id CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_verification_token_hash (token_hash),
  KEY idx_email_verification_student (student_id),
  KEY idx_email_verification_expiration (expires_at),
  CONSTRAINT fk_email_verification_student FOREIGN KEY (student_id)
    REFERENCES students (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;
