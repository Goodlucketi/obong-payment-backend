CREATE TABLE academic_sessions (
  id CHAR(36) NOT NULL,
  name VARCHAR(20) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  status ENUM('ACTIVE', 'CLOSED') NOT NULL DEFAULT 'CLOSED',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_academic_sessions_name (name),
  KEY idx_academic_sessions_status (status)
) ENGINE=InnoDB;

CREATE TABLE faculties (
  id CHAR(36) NOT NULL,
  name VARCHAR(160) NOT NULL,
  code VARCHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_faculties_name (name),
  UNIQUE KEY uq_faculties_code (code)
) ENGINE=InnoDB;

CREATE TABLE departments (
  id CHAR(36) NOT NULL,
  faculty_id CHAR(36) NOT NULL,
  name VARCHAR(160) NOT NULL,
  code VARCHAR(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_faculty_name (faculty_id, name),
  UNIQUE KEY uq_departments_code (code),
  CONSTRAINT fk_departments_faculty FOREIGN KEY (faculty_id)
    REFERENCES faculties (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE programmes (
  id CHAR(36) NOT NULL,
  department_id CHAR(36) NOT NULL,
  name VARCHAR(180) NOT NULL,
  code VARCHAR(32) NOT NULL,
  study_mode ENUM('FULL_TIME', 'PART_TIME') NOT NULL DEFAULT 'FULL_TIME',
  award VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_programmes_department_name (department_id, name),
  UNIQUE KEY uq_programmes_code (code),
  CONSTRAINT fk_programmes_department FOREIGN KEY (department_id)
    REFERENCES departments (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE students (
  id CHAR(36) NOT NULL,
  academic_session_id CHAR(36) NOT NULL,
  programme_id CHAR(36) NOT NULL,
  registration_number VARCHAR(40) NOT NULL,
  surname VARCHAR(100) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  other_names VARCHAR(160) NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(32) NULL,
  level VARCHAR(12) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  email_verified_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  account_status ENUM('ACTIVE', 'INACTIVE', 'SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_registration_number (registration_number),
  UNIQUE KEY uq_students_email (email),
  KEY idx_students_programme_level (programme_id, level),
  CONSTRAINT fk_students_session FOREIGN KEY (academic_session_id)
    REFERENCES academic_sessions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_students_programme FOREIGN KEY (programme_id)
    REFERENCES programmes (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

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

CREATE TABLE administrators (
  id CHAR(36) NOT NULL,
  name VARCHAR(180) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('SUPER_ADMIN', 'BURSAR', 'REGISTRAR') NOT NULL,
  department VARCHAR(160) NULL,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_administrators_email (email),
  KEY idx_administrators_status_role (status, role)
) ENGINE=InnoDB;

CREATE TABLE payment_types (
  id CHAR(36) NOT NULL,
  academic_session_id CHAR(36) NOT NULL,
  faculty_id CHAR(36) NULL,
  department_id CHAR(36) NULL,
  name VARCHAR(140) NOT NULL,
  code VARCHAR(48) NOT NULL,
  amount DECIMAL(12, 2) NOT NULL,
  applicable_level VARCHAR(24) NOT NULL DEFAULT 'ALL',
  faculty_scope_id CHAR(36) NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000',
  department_scope_id CHAR(36) NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000',
  is_mandatory BOOLEAN NOT NULL DEFAULT TRUE,
  allow_partial_payment BOOLEAN NOT NULL DEFAULT FALSE,
  status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  description TEXT NULL,
  created_by CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_types_scope_code (academic_session_id, code, faculty_scope_id, department_scope_id, applicable_level),
  KEY idx_payment_types_session_status (academic_session_id, status),
  KEY idx_payment_types_faculty (faculty_id),
  KEY idx_payment_types_department (department_id),
  CONSTRAINT fk_payment_types_session FOREIGN KEY (academic_session_id)
    REFERENCES academic_sessions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_payment_types_faculty FOREIGN KEY (faculty_id)
    REFERENCES faculties (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_payment_types_department FOREIGN KEY (department_id)
    REFERENCES departments (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_payment_types_creator FOREIGN KEY (created_by)
    REFERENCES administrators (id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_payment_types_amount CHECK (amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE payment_type_fee_schedules (
  id CHAR(36) NOT NULL,
  payment_type_id CHAR(36) NOT NULL,
  department_id CHAR(36) NOT NULL,
  applicable_level VARCHAR(24) NOT NULL,
  amount DECIMAL(12, 2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_type_department_level (payment_type_id, department_id, applicable_level),
  KEY idx_payment_type_fee_schedules_department (department_id, applicable_level),
  CONSTRAINT fk_payment_type_fee_schedules_payment_type FOREIGN KEY (payment_type_id)
    REFERENCES payment_types (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_payment_type_fee_schedules_department FOREIGN KEY (department_id)
    REFERENCES departments (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_payment_type_fee_schedules_amount CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE invoices (
  id CHAR(36) NOT NULL,
  student_id CHAR(36) NOT NULL,
  payment_type_id CHAR(36) NOT NULL,
  academic_session_id CHAR(36) NOT NULL,
  amount DECIMAL(12, 2) NOT NULL,
  amount_paid DECIMAL(12, 2) NOT NULL DEFAULT 0,
  status ENUM('UNPAID', 'PARTIAL', 'PAID', 'VOID', 'OVERDUE') NOT NULL DEFAULT 'UNPAID',
  due_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoice_student_type_session (student_id, payment_type_id, academic_session_id),
  KEY idx_invoices_student_status (student_id, status),
  KEY idx_invoices_session_status (academic_session_id, status),
  CONSTRAINT fk_invoices_student FOREIGN KEY (student_id)
    REFERENCES students (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_invoices_payment_type FOREIGN KEY (payment_type_id)
    REFERENCES payment_types (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_invoices_session FOREIGN KEY (academic_session_id)
    REFERENCES academic_sessions (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_invoices_amount CHECK (amount >= 0 AND amount_paid >= 0 AND amount_paid <= amount)
) ENGINE=InnoDB;

CREATE TABLE transactions (
  id CHAR(36) NOT NULL,
  invoice_id CHAR(36) NOT NULL,
  student_id CHAR(36) NOT NULL,
  transaction_reference VARCHAR(100) NOT NULL,
  paystack_reference VARCHAR(100) NULL,
  amount DECIMAL(12, 2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'NGN',
  gateway VARCHAR(32) NOT NULL DEFAULT 'PAYSTACK',
  channel VARCHAR(40) NULL,
  status ENUM('PENDING', 'PAID', 'FAILED', 'ABANDONED', 'REVERSED') NOT NULL DEFAULT 'PENDING',
  gateway_response JSON NULL,
  verified_at DATETIME NULL,
  reconciled_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transactions_reference (transaction_reference),
  UNIQUE KEY uq_transactions_paystack_reference (paystack_reference),
  KEY idx_transactions_student_status_date (student_id, status, created_at),
  KEY idx_transactions_invoice_status (invoice_id, status),
  CONSTRAINT fk_transactions_invoice FOREIGN KEY (invoice_id)
    REFERENCES invoices (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transactions_student FOREIGN KEY (student_id)
    REFERENCES students (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_transactions_amount CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE receipts (
  id CHAR(36) NOT NULL,
  transaction_id CHAR(36) NOT NULL,
  receipt_number VARCHAR(64) NOT NULL,
  verification_code CHAR(64) NOT NULL,
  issued_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receipts_transaction (transaction_id),
  UNIQUE KEY uq_receipts_number (receipt_number),
  UNIQUE KEY uq_receipts_verification_code (verification_code),
  CONSTRAINT fk_receipts_transaction FOREIGN KEY (transaction_id)
    REFERENCES transactions (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id CHAR(36) NOT NULL,
  administrator_id CHAR(36) NULL,
  actor_type ENUM('ADMIN', 'STUDENT', 'SYSTEM') NOT NULL,
  actor_id VARCHAR(64) NULL,
  actor_name VARCHAR(180) NOT NULL,
  actor_role VARCHAR(32) NOT NULL,
  action VARCHAR(180) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_reference VARCHAR(120) NULL,
  ip_address VARCHAR(45) NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_logs_created_at (created_at),
  KEY idx_audit_logs_entity (entity_type, entity_reference),
  KEY idx_audit_logs_actor (actor_type, actor_id),
  CONSTRAINT fk_audit_logs_administrator FOREIGN KEY (administrator_id)
    REFERENCES administrators (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE app_settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_value JSON NOT NULL,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_app_settings_updater FOREIGN KEY (updated_by)
    REFERENCES administrators (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
  id CHAR(36) NOT NULL,
  identity_type ENUM('STUDENT', 'ADMIN') NOT NULL,
  identity_id CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_reset_token_hash (token_hash),
  KEY idx_password_reset_identity (identity_type, identity_id),
  KEY idx_password_reset_expiration (expires_at)
) ENGINE=InnoDB;

CREATE TABLE api_tokens (
  id CHAR(36) NOT NULL,
  actor_type ENUM('STUDENT', 'ADMIN') NOT NULL,
  actor_id CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_tokens_hash (token_hash),
  KEY idx_api_tokens_actor (actor_type, actor_id),
  KEY idx_api_tokens_expiration (expires_at)
) ENGINE=InnoDB;