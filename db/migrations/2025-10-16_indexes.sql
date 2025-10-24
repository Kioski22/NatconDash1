-- One-time indexes and constraints for scale and idempotency
-- Run once on production database

-- Certificates issued: ensure uniqueness per user/type and quick lookups
CREATE TABLE IF NOT EXISTS certificates_issued (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  cert_type VARCHAR(32) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  cert_number VARCHAR(120) NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_type (user_id, cert_type),
  KEY idx_user (user_id),
  KEY idx_type (cert_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Quizzes: lookup by speaker_id latest first
ALTER TABLE quizzes ADD INDEX IF NOT EXISTS idx_quizzes_speaker (speaker_id, id);

-- Quiz responses: fast joins/filters
ALTER TABLE quiz_responses ADD INDEX IF NOT EXISTS idx_qr_user_speaker (user_id, speaker_id);
ALTER TABLE quiz_responses ADD INDEX IF NOT EXISTS idx_qr_quiz (quiz_id);

-- Speaker evaluations
ALTER TABLE speaker_evaluations ADD INDEX IF NOT EXISTS idx_se_user_speaker (user_id, speaker_id);

-- Attendance: user-day queries and counts
ALTER TABLE attendance ADD INDEX IF NOT EXISTS idx_att_user_day (user_id, day_number);
ALTER TABLE attendance ADD INDEX IF NOT EXISTS idx_att_user_created (user_id, created_at);

-- Main program certificates (overall and attendance) uniqueness by user
ALTER TABLE main_program_certificates ADD UNIQUE KEY IF NOT EXISTS uq_mpc_user (user_id);
ALTER TABLE main_program_attendance_certificates ADD UNIQUE KEY IF NOT EXISTS uq_mpac_user (user_id);

-- Admin settings key uniqueness for UPSERT
ALTER TABLE admin_settings ADD UNIQUE KEY IF NOT EXISTS uq_setting_key (setting_key);
