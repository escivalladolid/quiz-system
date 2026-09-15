-- ============================================================
-- Migration: exam_submissions.score -> RAW EARNED POINTS
-- ============================================================
-- Older builds scaled earned points onto exams.total_points. The current
-- scoring contract stores raw earned points and derives percentages from
-- SUM(questions.points). Convert existing rows before using the reports.
-- Exams without both totals are left untouched.
-- ============================================================

UPDATE exam_submissions es
JOIN exams e ON e.exam_id = es.exam_id
JOIN (
    SELECT exam_id, COALESCE(SUM(points), 0) AS question_points
    FROM questions
    GROUP BY exam_id
) qtp ON qtp.exam_id = es.exam_id
SET es.score = ROUND(es.score / NULLIF(e.total_points, 0) * qtp.question_points)
WHERE e.total_points > 0 AND qtp.question_points > 0;

-- Verify after applying (all implied percentages should be 0..100):
-- SELECT es.submission_id, es.score AS raw_score,
--        qtp.question_points,
--        ROUND(es.score / qtp.question_points * 100, 2) AS implied_percentage
-- FROM exam_submissions es
-- JOIN (
--     SELECT exam_id, COALESCE(SUM(points), 0) AS question_points
--     FROM questions GROUP BY exam_id
-- ) qtp ON qtp.exam_id = es.exam_id
-- ORDER BY es.submission_id;
