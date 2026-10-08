CREATE DATABASE IF NOT EXISTS ruts36 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ruts36;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  login_id VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('student','staff','admin') NOT NULL,
  first_name VARCHAR(100) NOT NULL DEFAULT '',
  last_name VARCHAR(100) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE student_profiles (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  student_code VARCHAR(30) NOT NULL UNIQUE,
  national_id VARCHAR(13) NULL UNIQUE,
  faculty VARCHAR(150) NOT NULL,
  major VARCHAR(150) NOT NULL,
  study_year VARCHAR(10) NOT NULL,
  semester VARCHAR(10) NOT NULL DEFAULT '1',
  academic_year VARCHAR(10) NOT NULL DEFAULT '2569',
  final_approved TINYINT(1) NOT NULL DEFAULT 0,
  final_approved_at DATETIME NULL,
  final_approved_by BIGINT UNSIGNED NULL,
  CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_profile_approver FOREIGN KEY (final_approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE submission_windows (
  academic_year VARCHAR(10) PRIMARY KEY,
  open_date DATE NOT NULL,
  close_date DATE NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_window_staff FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE activities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  activity_name VARCHAR(255) NOT NULL,
  activity_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  hours DECIMAL(5,2) NOT NULL,
  organization VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT,
  certifier_name VARCHAR(150) NOT NULL,
  certifier_position VARCHAR(150) NOT NULL DEFAULT '',
  certifier_org VARCHAR(255) NOT NULL DEFAULT '',
  evidence_images LONGTEXT NULL,
  signature LONGTEXT NULL,
  status ENUM('draft','submitted','resubmitted','revision','approved','rejected') NOT NULL DEFAULT 'submitted',
  review_note TEXT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activities_student (student_id),
  CONSTRAINT fk_activity_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_activity_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  activity_id BIGINT UNSIGNED NULL,
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_user (user_id, is_read),
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notification_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created (created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  login_id VARCHAR(50) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempt (login_id, ip_address, attempted_at)
) ENGINE=InnoDB;

-- รหัสผ่านบัญชีตัวอย่างคือ 1234; เมื่อสร้างบัญชีจริงให้ใช้ password_hash() ของ PHP
INSERT INTO users (id, login_id, password_hash, role, must_change_password) VALUES
(1, '6580000000', SHA2('1234', 256), 'student', 0),
(2, 'STAFF001', SHA2('1234', 256), 'staff', 0),
(3, 'ADMIN001', SHA2('1234', 256), 'admin', 0);

INSERT INTO student_profiles (user_id, first_name, last_name, student_code, faculty, major, study_year, semester, academic_year) VALUES
(1, 'กรรณิการ์', 'ทิพย์จักษุ', '6580000000', 'คณะบริหารธุรกิจ', 'สาขาระบบสารสนเทศธุรกิจดิจิทัล', '3', '1', '2569');

INSERT INTO activities (student_id, activity_name, activity_date, start_time, end_time, hours, organization, description, certifier_name, status) VALUES
(1, 'กิจกรรมปลูกต้นไม้เพื่อสิ่งแวดล้อม', '2026-07-20', '08:00', '14:00', 6, 'มหาวิทยาลัย', 'ร่วมปลูกต้นไม้และดูแลพื้นที่สีเขียว', 'ผู้รับรองตัวอย่าง', 'submitted'),
(1, 'กิจกรรมทำความสะอาดพื้นที่สาธารณะ', '2026-07-28', '08:00', '12:00', 4, 'ชุมชน', 'ช่วยทำความสะอาดและจัดพื้นที่', 'ผู้รับรองตัวอย่าง', 'submitted');
