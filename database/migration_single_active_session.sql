-- One active session check support.
-- The API still enforces the rule transactionally; this index keeps the
-- active-session lookup fast on existing databases.

SET @has_session_scope_index := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'sessions'
      AND index_name = 'idx_sessions_user_expiry'
);

SET @session_scope_index_ddl := IF(
    @has_session_scope_index = 0,
    'ALTER TABLE sessions ADD INDEX idx_sessions_user_expiry (user_id, expires_at)',
    'SELECT 1'
);
PREPARE stmt_session_scope_index FROM @session_scope_index_ddl;
EXECUTE stmt_session_scope_index;
DEALLOCATE PREPARE stmt_session_scope_index;
