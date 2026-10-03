ALTER TABLE payment_types
  DROP INDEX uq_payment_types_session_code,
  ADD COLUMN faculty_id BIGINT UNSIGNED NULL AFTER academic_session_id,
  ADD COLUMN faculty_scope_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (COALESCE(faculty_id, 0)) STORED AFTER applicable_level,
  ADD UNIQUE KEY uq_payment_types_scope_code (
    academic_session_id,
    code,
    faculty_scope_id,
    applicable_level
  ),
  ADD KEY idx_payment_types_faculty (faculty_id),
  ADD CONSTRAINT fk_payment_types_faculty
    FOREIGN KEY (faculty_id) REFERENCES faculties (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;
