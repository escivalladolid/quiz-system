-- Speeds the live-monitoring grouped violation counts and legacy event feed.
SET @has_monitoring_event_index := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'exam_proctoring_log'
      AND index_name = 'idx_proc_exam_event_user_created'
);
SET @monitoring_event_index_ddl := IF(
    @has_monitoring_event_index = 0,
    'ALTER TABLE exam_proctoring_log ADD INDEX idx_proc_exam_event_user_created (exam_id, event_type, user_id, created_at)',
    'SELECT 1'
);
PREPARE stmt_monitoring_event_index FROM @monitoring_event_index_ddl;
EXECUTE stmt_monitoring_event_index;
DEALLOCATE PREPARE stmt_monitoring_event_index;
