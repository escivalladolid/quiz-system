-- ============================================================
-- Migration: EMAIL-VERIFIED ROSTER REGISTRATION
-- ============================================================
-- New registrations remain PENDING until the one-time code sent to the
-- supplied email address is verified. Existing ACTIVE accounts are unchanged.
-- Run with the quiz_system database selected. This migration is idempotent.
-- ============================================================

ALTER TABLE users
    MODIFY COLUMN status ENUM('PENDING','ACTIVE','INACTIVE','BANNED') DEFAULT 'ACTIVE';

SET @student_email_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'student_roster'
      AND COLUMN_NAME = 'email'
);
SET @student_email_ddl := IF(@student_email_exists = 0,
    'ALTER TABLE student_roster ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER program',
    'SELECT 1');
PREPARE stmt_student_email FROM @student_email_ddl;
EXECUTE stmt_student_email;
DEALLOCATE PREPARE stmt_student_email;

SET @teacher_email_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'teacher_roster'
      AND COLUMN_NAME = 'email'
);
SET @teacher_email_ddl := IF(@teacher_email_exists = 0,
    'ALTER TABLE teacher_roster ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER department',
    'SELECT 1');
PREPARE stmt_teacher_email FROM @teacher_email_ddl;
EXECUTE stmt_teacher_email;
DEALLOCATE PREPARE stmt_teacher_email;

CREATE TABLE IF NOT EXISTS email_verifications (
    verification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    token_hash      VARCHAR(64) NOT NULL,
    expires_at      DATETIME NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_verification_user (user_id),
    UNIQUE KEY unique_verification_token (token_hash),
    KEY idx_verification_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Verify:
--   SHOW COLUMNS FROM users LIKE 'status';
--   SHOW COLUMNS FROM student_roster LIKE 'email';
--   SHOW COLUMNS FROM teacher_roster LIKE 'email';
--   SHOW TABLES LIKE 'email_verifications';
