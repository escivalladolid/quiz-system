-- Soft archive support for classes and exams.
-- This migration is additive and safe to run more than once. Existing rows
-- remain untouched; legacy status='ARCHIVED' rows are still treated as archived
-- by the API until they are explicitly unarchived.
USE quiz_system;

SET @has_classes_is_archived := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'classes' AND column_name = 'is_archived'
);
SET @ddl_classes_is_archived := IF(@has_classes_is_archived = 0,
    'ALTER TABLE classes ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1');
PREPARE stmt_classes_is_archived FROM @ddl_classes_is_archived;
EXECUTE stmt_classes_is_archived;
DEALLOCATE PREPARE stmt_classes_is_archived;

SET @has_classes_archived_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'classes' AND column_name = 'archived_at'
);
SET @ddl_classes_archived_at := IF(@has_classes_archived_at = 0,
    'ALTER TABLE classes ADD COLUMN archived_at DATETIME NULL AFTER is_archived',
    'SELECT 1');
PREPARE stmt_classes_archived_at FROM @ddl_classes_archived_at;
EXECUTE stmt_classes_archived_at;
DEALLOCATE PREPARE stmt_classes_archived_at;

SET @has_exams_is_archived := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'exams' AND column_name = 'is_archived'
);
SET @ddl_exams_is_archived := IF(@has_exams_is_archived = 0,
    'ALTER TABLE exams ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1');
PREPARE stmt_exams_is_archived FROM @ddl_exams_is_archived;
EXECUTE stmt_exams_is_archived;
DEALLOCATE PREPARE stmt_exams_is_archived;

SET @has_exams_archived_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'exams' AND column_name = 'archived_at'
);
SET @ddl_exams_archived_at := IF(@has_exams_archived_at = 0,
    'ALTER TABLE exams ADD COLUMN archived_at DATETIME NULL AFTER is_archived',
    'SELECT 1');
PREPARE stmt_exams_archived_at FROM @ddl_exams_archived_at;
EXECUTE stmt_exams_archived_at;
DEALLOCATE PREPARE stmt_exams_archived_at;

SET @has_classes_archive_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'classes' AND index_name = 'idx_classes_archive_teacher'
);
SET @ddl_classes_archive_index := IF(@has_classes_archive_index = 0,
    'ALTER TABLE classes ADD INDEX idx_classes_archive_teacher (is_archived, teacher_id, class_id)',
    'SELECT 1');
PREPARE stmt_classes_archive_index FROM @ddl_classes_archive_index;
EXECUTE stmt_classes_archive_index;
DEALLOCATE PREPARE stmt_classes_archive_index;

SET @has_exams_archive_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'exams' AND index_name = 'idx_exams_archive_class'
);
SET @ddl_exams_archive_index := IF(@has_exams_archive_index = 0,
    'ALTER TABLE exams ADD INDEX idx_exams_archive_class (is_archived, class_id, exam_id)',
    'SELECT 1');
PREPARE stmt_exams_archive_index FROM @ddl_exams_archive_index;
EXECUTE stmt_exams_archive_index;
DEALLOCATE PREPARE stmt_exams_archive_index;
