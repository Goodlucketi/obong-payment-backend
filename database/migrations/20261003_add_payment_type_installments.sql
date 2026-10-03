ALTER TABLE payment_types
  ADD COLUMN allow_partial_payment BOOLEAN NOT NULL DEFAULT FALSE
    AFTER is_mandatory;
