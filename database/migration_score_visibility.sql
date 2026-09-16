-- Per-exam score visibility. 0 (default) shows auto-graded scores after
-- submission; 1 keeps them hidden until the teacher closes the exam.
-- Run with the quiz_system database selected. This migration is idempotent.
SET @hold_scores_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'exams'
      AND COLUMN_NAME = 'hold_scores'
);
SET @hold_scores_ddl := IF(@hold_scores_exists = 0,
    'ALTER TABLE exams ADD COLUMN hold_scores TINYINT(1) NOT NULL DEFAULT 0 AFTER passing_score',
    'SELECT 1');
PREPARE stmt_hold_scores FROM @hold_scores_ddl;
EXECUTE stmt_hold_scores;
DEALLOCATE PREPARE stmt_hold_scores;
