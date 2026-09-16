-- ============================================================
-- Migration: ADMIN SUPPORT / LOGIN ISSUES CENTER
-- ============================================================
-- Stores system mail-delivery failures separately from student reports so
-- administrators can triage both in one queue. Run with quiz_system selected.
-- The statements are safe to run more than once.
-- ============================================================

CREATE TABLE IF NOT EXISTS system_alerts (
    alert_id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    alert_type          VARCHAR(64) NOT NULL,
    severity            ENUM('ERROR','WARNING','INFO') NOT NULL DEFAULT 'ERROR',
    affected_user_id    INT DEFAULT NULL,
    affected_identifier VARCHAR(255) DEFAULT NULL,
    description         VARCHAR(1000) NOT NULL,
    details             JSON DEFAULT NULL,
    status              ENUM('NEW','IN_PROGRESS','RESOLVED') NOT NULL DEFAULT 'NEW',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at         DATETIME DEFAULT NULL,
    resolved_by         INT DEFAULT NULL,
    KEY idx_system_alert_created (created_at, alert_id),
    KEY idx_system_alert_status (status, created_at),
    KEY idx_system_alert_type (alert_type, created_at),
    KEY idx_system_alert_user (affected_user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_problem_reports (
    report_id   BIGINT AUTO_INCREMENT PRIMARY KEY,
    contact     VARCHAR(255) NOT NULL,
    role        ENUM('STUDENT','TEACHER','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
    step        VARCHAR(40) NOT NULL,
    description VARCHAR(1000) NOT NULL,
    screen      VARCHAR(80) DEFAULT NULL,
    app_version VARCHAR(40) DEFAULT NULL,
    device_info VARCHAR(255) DEFAULT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    status      ENUM('OPEN','IN_PROGRESS','RESOLVED') NOT NULL DEFAULT 'OPEN',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_problem_created (created_at),
    KEY idx_login_problem_ip (ip_address, created_at),
    KEY idx_login_problem_status (status, created_at)
) ENGINE=InnoDB;

-- Older installations used only OPEN/RESOLVED for student reports. Keep the
-- stored value OPEN for compatibility while allowing the support center to
-- represent an item that is actively being worked on.
ALTER TABLE login_problem_reports
    MODIFY COLUMN status ENUM('OPEN','IN_PROGRESS','RESOLVED') NOT NULL DEFAULT 'OPEN';
