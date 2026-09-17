-- Live exam activity monitoring.
-- Records app-level presence/progress only; no answer text, screenshots, or
-- activity outside the quiz app is collected.

CREATE TABLE IF NOT EXISTS exam_live_presence (
    presence_id          INT AUTO_INCREMENT PRIMARY KEY,
    exam_id              INT NOT NULL,
    user_id              INT NOT NULL,
    status               VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
    current_question_id  INT NULL,
    question_index       INT NULL,
    answered_count       INT NULL,
    total_questions      INT NULL,
    last_event           VARCHAR(30) NOT NULL DEFAULT 'HEARTBEAT',
    last_seen_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_exam_presence (exam_id, user_id),
    KEY idx_presence_exam_seen (exam_id, last_seen_at),
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exam_activity_log (
    activity_id      BIGINT AUTO_INCREMENT PRIMARY KEY,
    exam_id          INT NOT NULL,
    user_id          INT NOT NULL,
    attempt_id       INT NULL,
    event_type       VARCHAR(30) NOT NULL,
    question_id      INT NULL,
    question_index   INT NULL,
    answered_count   INT NULL,
    total_questions  INT NULL,
    network_state    VARCHAR(16) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_activity_exam_created (exam_id, created_at),
    KEY idx_activity_exam_user (exam_id, user_id, created_at),
    KEY idx_activity_attempt_scope (exam_id, user_id, attempt_id, event_type, created_at),
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (attempt_id) REFERENCES exam_attempts(attempt_id) ON DELETE CASCADE
) ENGINE=InnoDB;
