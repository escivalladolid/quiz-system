-- Login/support reports submitted from the mobile login and registration screens.
-- Run with the quiz_system database selected. This migration is idempotent.
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
    status      ENUM('OPEN','RESOLVED') NOT NULL DEFAULT 'OPEN',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_problem_created (created_at),
    KEY idx_login_problem_ip (ip_address, created_at),
    KEY idx_login_problem_status (status, created_at)
) ENGINE=InnoDB;
