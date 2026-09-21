-- In-app notifications (Phase 2)
-- Apply once to an existing quiz_system database. This migration is additive.

CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    target_type ENUM('CLASS', 'USER', 'ROLE') NOT NULL,
    target_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    reference_type VARCHAR(50) DEFAULT NULL,
    reference_id INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME DEFAULT NULL,
    event_key VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_notifications_event_key (event_key),
    KEY idx_notifications_target (target_type, target_id, created_at),
    KEY idx_notifications_expiry (expires_at, created_at),
    KEY idx_notifications_reference (reference_type, reference_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_reads (
    notification_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, user_id),
    KEY idx_notification_reads_user (user_id, read_at),
    CONSTRAINT fk_notification_reads_notification
        FOREIGN KEY (notification_id) REFERENCES notifications(notification_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_notification_reads_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB;
