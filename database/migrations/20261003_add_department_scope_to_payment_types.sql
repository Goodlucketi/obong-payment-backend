ALTER TABLE payment_types
  DROP INDEX uq_payment_types_scope_code,
  ADD COLUMN department_id BIGINT UNSIGNED NULL AFTER faculty_id,
  ADD COLUMN department_scope_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (COALESCE(department_id, 0)) STORED AFTER faculty_scope_id,
  ADD UNIQUE KEY uq_payment_types_scope_code (
    academic_session_id,
    code,
    faculty_scope_id,
    department_scope_id,
    applicable_level
  ),
  ADD KEY idx_payment_types_department (department_id),
  ADD CONSTRAINT fk_payment_types_department
    FOREIGN KEY (department_id) REFERENCES departments (id)
    ON UPDATE CASCADE ON DELETE RESTRICT;
