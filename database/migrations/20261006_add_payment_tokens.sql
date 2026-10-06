CREATE TABLE payment_tokens (
  id CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  invoice_id CHAR(36) NOT NULL,
  student_id CHAR(36) NOT NULL,
  issued_by CHAR(36) NULL,
  amount DECIMAL(12, 2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'NGN',
  expires_at DATETIME NOT NULL,
  redeemed_at DATETIME NULL,
  transaction_id CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_tokens_hash (token_hash),
  UNIQUE KEY uq_payment_tokens_transaction (transaction_id),
  KEY idx_payment_tokens_student_invoice (student_id, invoice_id),
  KEY idx_payment_tokens_expiration (expires_at),
  CONSTRAINT fk_payment_tokens_invoice FOREIGN KEY (invoice_id)
    REFERENCES invoices (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_payment_tokens_student FOREIGN KEY (student_id)
    REFERENCES students (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_payment_tokens_issuer FOREIGN KEY (issued_by)
    REFERENCES administrators (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_payment_tokens_transaction FOREIGN KEY (transaction_id)
    REFERENCES transactions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_payment_tokens_amount CHECK (amount > 0)
) ENGINE=InnoDB;
