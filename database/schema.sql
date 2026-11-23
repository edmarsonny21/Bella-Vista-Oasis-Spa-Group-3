CREATE DATABASE IF NOT EXISTS bellavista_oasis_app
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bellavista_oasis_app;

CREATE TABLE IF NOT EXISTS services (
  id VARCHAR(50) PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL,
  base_rate DECIMAL(10,2) NOT NULL,
  base_pax TINYINT UNSIGNED NOT NULL,
  max_pax TINYINT UNSIGNED NOT NULL,
  extra_pax_fee DECIMAL(10,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rooms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_code VARCHAR(20) NOT NULL UNIQUE,
  service_id VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  capacity TINYINT UNSIGNED NOT NULL,
  CONSTRAINT fk_rooms_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS therapists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  specialties VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS guest_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  email_verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS guest_email_verifications (
  guest_user_id BIGINT UNSIGNED PRIMARY KEY,
  code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_sent_at DATETIME NOT NULL,
  CONSTRAINT fk_guest_verifications_user
    FOREIGN KEY (guest_user_id) REFERENCES guest_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS guest_password_resets (
  guest_user_id BIGINT UNSIGNED PRIMARY KEY,
  code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_sent_at DATETIME NOT NULL,
  CONSTRAINT fk_guest_password_resets_user
    FOREIGN KEY (guest_user_id) REFERENCES guest_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS guest_password_changes (
  guest_user_id BIGINT UNSIGNED PRIMARY KEY,
  password_hash VARCHAR(255) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_sent_at DATETIME NOT NULL,
  CONSTRAINT fk_guest_password_changes_user
    FOREIGN KEY (guest_user_id) REFERENCES guest_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS guest_email_changes (
  guest_user_id BIGINT UNSIGNED PRIMARY KEY,
  pending_email VARCHAR(254) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  verification_stage ENUM('current', 'new') NOT NULL DEFAULT 'current',
  expires_at DATETIME NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_sent_at DATETIME NOT NULL,
  CONSTRAINT fk_guest_email_changes_user
    FOREIGN KEY (guest_user_id) REFERENCES guest_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appointments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_code VARCHAR(30) NOT NULL UNIQUE,
  guest_user_id BIGINT UNSIGNED NULL,
  service_id VARCHAR(50) NOT NULL,
  room_id INT UNSIGNED NOT NULL,
  therapist_id INT UNSIGNED NULL,
  guest_name VARCHAR(150) NOT NULL,
  guest_email VARCHAR(254) NOT NULL,
  guest_phone VARCHAR(50) NOT NULL,
  booking_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  cleaning_end_time TIME NOT NULL,
  guest_count TINYINT UNSIGNED NOT NULL,
  extra_pax_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  base_rate DECIMAL(10,2) NOT NULL,
  extra_pax_fee_total DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(10,2) NOT NULL,
  special_requests TEXT NULL,
  health_declared TINYINT(1) NOT NULL,
  terms_accepted TINYINT(1) NOT NULL,
  status ENUM('on_progress', 'confirmed', 'in_session', 'cleaning', 'completed', 'cancelled') NOT NULL DEFAULT 'on_progress',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_appointments_date_room (booking_date, room_id, status),
  INDEX idx_appointments_guest_date (guest_user_id, booking_date),
  CONSTRAINT fk_appointments_guest FOREIGN KEY (guest_user_id) REFERENCES guest_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_appointments_service FOREIGN KEY (service_id) REFERENCES services(id),
  CONSTRAINT fk_appointments_room FOREIGN KEY (room_id) REFERENCES rooms(id),
  CONSTRAINT fk_appointments_therapist FOREIGN KEY (therapist_id) REFERENCES therapists(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET @schema_sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
     AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%on_progress%') = 0,
  'ALTER TABLE appointments MODIFY COLUMN status ENUM(''on_progress'', ''confirmed'', ''in_session'', ''cleaning'', ''completed'', ''cancelled'') NOT NULL DEFAULT ''on_progress''',
  'SELECT 1'
);
PREPARE schema_statement FROM @schema_sql;
EXECUTE schema_statement;
DEALLOCATE PREPARE schema_statement;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id BIGINT UNSIGNED NOT NULL,
  order_id VARCHAR(30) NOT NULL,
  customer_name VARCHAR(150) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  gcash_ref_number CHAR(13) NOT NULL UNIQUE,
  receipt_image VARCHAR(255) NULL,
  payment_status ENUM('Pending Verification', 'Verified', 'Rejected')
    NOT NULL DEFAULT 'Pending Verification',
  rejection_reason VARCHAR(255) NULL,
  verified_by INT UNSIGNED NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_payments_appointment_created (appointment_id, created_at),
  INDEX idx_payments_status_created (payment_status, created_at),
  CONSTRAINT fk_payments_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_verifier FOREIGN KEY (verified_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET @schema_sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'guest_user_id') = 0,
  'ALTER TABLE appointments ADD COLUMN guest_user_id BIGINT UNSIGNED NULL AFTER booking_code',
  'SELECT 1'
);
PREPARE schema_statement FROM @schema_sql;
EXECUTE schema_statement;
DEALLOCATE PREPARE schema_statement;

SET @schema_sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_appointments_guest_date') = 0,
  'ALTER TABLE appointments ADD INDEX idx_appointments_guest_date (guest_user_id, booking_date)',
  'SELECT 1'
);
PREPARE schema_statement FROM @schema_sql;
EXECUTE schema_statement;
DEALLOCATE PREPARE schema_statement;

SET @schema_sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
     AND CONSTRAINT_NAME = 'fk_appointments_guest' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
  'ALTER TABLE appointments ADD CONSTRAINT fk_appointments_guest FOREIGN KEY (guest_user_id) REFERENCES guest_users(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE schema_statement FROM @schema_sql;
EXECUTE schema_statement;
DEALLOCATE PREPARE schema_statement;

SET @schema_sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guest_email_changes'
     AND COLUMN_NAME = 'verification_stage') = 0,
  'ALTER TABLE guest_email_changes ADD COLUMN verification_stage ENUM(''current'', ''new'') NOT NULL DEFAULT ''new'' AFTER code_hash',
  'SELECT 1'
);
PREPARE schema_statement FROM @schema_sql;
EXECUTE schema_statement;
DEALLOCATE PREPARE schema_statement;

INSERT INTO services (id, name, duration_minutes, base_rate, base_pax, max_pax, extra_pax_fee) VALUES
  ('single-room', 'Single Room Massage', 60, 800, 1, 1, 0),
  ('couples-suite', 'Couples Suite', 60, 1800, 2, 2, 0),
  ('family-lounge', 'Barkada / Family Spa Lounge', 90, 4000, 4, 6, 750)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO rooms (room_code, service_id, name, capacity) VALUES
  ('SR-1', 'single-room', 'Lotus Sanctuary', 1),
  ('SR-2', 'single-room', 'Cedar Serenity', 1),
  ('SR-3', 'single-room', 'Bamboo Haven', 1),
  ('CS-1', 'couples-suite', 'Harmony Pavilion', 2),
  ('CS-2', 'couples-suite', 'Jasmine Haven', 2),
  ('FL-1', 'family-lounge', 'Estate Grand Veranda', 6),
  ('FL-2', 'family-lounge', 'Orchid Oasis Lounge', 6)
ON DUPLICATE KEY UPDATE name = VALUES(name), capacity = VALUES(capacity);

INSERT INTO therapists (id, full_name, specialties) VALUES
  (1, 'Elena Morales', 'Traditional Hilot, Deep Tissue, Basalt Hot Stone'),
  (2, 'Mateo Santos', 'Swedish Massage, Aromatherapy, Acupressure'),
  (3, 'Camille Reyes', 'Couples Rituals, Lymphatic Drainage, Herbal Compress'),
  (4, 'Rafael Cruz', 'Reflexology, Sports Recovery, Hilot Energy Balance'),
  (5, 'Isla Patricia Tan', 'Pre-Natal Gentle Care, Facial Acupressure, Swedish Relax'),
  (6, 'Danilo Gomez', 'Family Lounge Group Lead, Traditional Bamboo Therapy')
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), specialties = VALUES(specialties);
