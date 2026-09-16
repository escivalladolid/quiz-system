-- ============================================================
-- Migration: exam_submissions.score → RAW EARNED POINTS
-- ============================================================
-- Previously Backend-PHP scored submissions by scaling the earned
-- proportion onto exams.total_points:
--     old score = ROUND((earned / SUM(question.points)) * exams.total_points)
-- The corrected convention (matching deploy/) stores RAW earned points
--     score = SUM(points of correctly answered questions)
-- and derives every percentage on the fly as
--     pct = (score / SUM(question.points)) * 100
--
-- This migration rescales any existing stored scores back to raw earned
-- points using the inverse of the old formula. Safe to run when the exam
-- has >0 total_points and >0 question points; rows that do not participate
-- (e.g. an exam with no questions) are left untouched so manual scores
-- cannot be zeroed out accidentally.
-- ============================================================

UPDATE exam_submissions es
JOIN exams e ON e.exam_id = es.exam_id
JOIN (
    SELECT exam_id, COALESCE(SUM(points), 0) AS tp
    FROM questions
    GROUP BY exam_id
) qtp ON qtp.exam_id = es.exam_id
SET es.score = ROUND(es.score / NULLIF(e.total_points, 0) * qtp.tp)
WHERE e.total_points > 0 AND qtp.tp > 0;

-- ============================================================
-- Verify (should print consistent values, e.g. seed row →
--   score 4, question points 5, implied percentage 80.00):
-- ============================================================
-- SELECT es.submission_id, es.score AS raw_score,
--        qtp.tp AS question_points,
--        ROUND(es.score / qtp.tp * 100, 2) AS implied_pct
-- FROM exam_submissions es
-- JOIN exams e ON e.exam_id = es.exam_id
-- JOIN (SELECT exam_id, COALESCE(SUM(points),0) AS tp FROM questions GROUP BY exam_id) qtp
--   ON qtp.exam_id = es.exam_id
-- ORDER BY es.submission_id;