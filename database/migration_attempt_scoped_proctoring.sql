-- Attempt-scoped proctoring state.
-- Existing rows are deliberately left with NULL attempt_id. They belong to no
-- known persisted attempt and must never contaminate a new attempt's count.

SET @has_proctor_attempt_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exam_proctoring_log'
      AND column_name = 'attempt_id'
);
SET @proctor_attempt_id_ddl := IF(
    @has_proctor_attempt_id = 0,
    'ALTER TABLE exam_proctoring_log ADD COLUMN attempt_id INT NULL AFTER user_id',
    'SELECT 1'
);
PREPARE stmt_proctor_attempt_id FROM @proctor_attempt_id_ddl;
EXECUTE stmt_proctor_attempt_id;
DEALLOCATE PREPARE stmt_proctor_attempt_id;

SET @has_proctor_attempt_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'exam_proctoring_log'
      AND index_name = 'idx_proc_attempt_scope'
);
SET @proctor_attempt_index_ddl := IF(
    @has_proctor_attempt_index = 0,
    'ALTER TABLE exam_proctoring_log ADD INDEX idx_proc_attempt_scope (exam_id, user_id, attempt_id, event_type, created_at)',
    'SELECT 1'
);
PREPARE stmt_proctor_attempt_index FROM @proctor_attempt_index_ddl;
EXECUTE stmt_proctor_attempt_index;
DEALLOCATE PREPARE stmt_proctor_attempt_index;

SET @has_proctor_attempt_fk := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'exam_proctoring_log'
      AND constraint_name = 'fk_proctoring_attempt'
);
SET @proctor_attempt_fk_ddl := IF(
    @has_proctor_attempt_fk = 0,
    'ALTER TABLE exam_proctoring_log ADD CONSTRAINT fk_proctoring_attempt FOREIGN KEY (attempt_id) REFERENCES exam_attempts(attempt_id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt_proctor_attempt_fk FROM @proctor_attempt_fk_ddl;
EXECUTE stmt_proctor_attempt_fk;
DEALLOCATE PREPARE stmt_proctor_attempt_fk;

-- Rich activity events carry the same attempt id when the live-monitoring
-- migration is installed. The application remains compatible with older
-- activity tables until this migration is applied.
SET @has_activity_table := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'exam_activity_log'
);
SET @has_activity_attempt_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exam_activity_log'
      AND column_name = 'attempt_id'
);
SET @activity_attempt_id_ddl := IF(
    @has_activity_table = 1 AND @has_activity_attempt_id = 0,
    'ALTER TABLE exam_activity_log ADD COLUMN attempt_id INT NULL AFTER user_id',
    'SELECT 1'
);
PREPARE stmt_activity_attempt_id FROM @activity_attempt_id_ddl;
EXECUTE stmt_activity_attempt_id;
DEALLOCATE PREPARE stmt_activity_attempt_id;

SET @has_activity_attempt_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'exam_activity_log'
      AND index_name = 'idx_activity_attempt_scope'
);
SET @activity_attempt_index_ddl := IF(
    @has_activity_table = 1 AND @has_activity_attempt_index = 0,
    'ALTER TABLE exam_activity_log ADD INDEX idx_activity_attempt_scope (exam_id, user_id, attempt_id, event_type, created_at)',
    'SELECT 1'
);
PREPARE stmt_activity_attempt_index FROM @activity_attempt_index_ddl;
EXECUTE stmt_activity_attempt_index;
DEALLOCATE PREPARE stmt_activity_attempt_index;

SET @has_activity_attempt_fk := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'exam_activity_log'
      AND constraint_name = 'fk_activity_attempt'
);
SET @activity_attempt_fk_ddl := IF(
    @has_activity_table = 1 AND @has_activity_attempt_fk = 0,
    'ALTER TABLE exam_activity_log ADD CONSTRAINT fk_activity_attempt FOREIGN KEY (attempt_id) REFERENCES exam_attempts(attempt_id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt_activity_attempt_fk FROM @activity_attempt_fk_ddl;
EXECUTE stmt_activity_attempt_fk;
DEALLOCATE PREPARE stmt_activity_attempt_fk;
