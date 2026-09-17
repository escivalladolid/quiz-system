# AGENTS.md — Catering Management System

## Project layout
- `catering-backend/` — FastAPI + SQLAlchemy + PostgreSQL (`catering_db` at `postgresql://postgres:postgres@localhost:5432/catering_db`)
  - `catering-mockup.html` — admin portal (single file, lives INSIDE catering-backend/)
  - `customer-portal.html` — customer portal (single file)
- API base: `http://127.0.0.1:8001` (root-level routers, no `/api` prefix)
- Admin login for tests: `escivalladolid@gmail.com` / `admin123`
- venv python: `catering-backend\venv\Scripts\python.exe`

## Running the server
```powershell
# from catering-backend/ — NO --reload flag
Start-Process -FilePath ".\venv\Scripts\python.exe" `
  -ArgumentList "-m","uvicorn","app.main:app","--host","127.0.0.1","--port","8001" `
  -WorkingDirectory "C:\xampp\htdocs\Capstone-Mobile-Quiz-System\catering-backend" `
  -WindowStyle Hidden `
  -RedirectStandardOutput "$env:TEMP\opencode\uvicorn_out.log" `
  -RedirectStandardError "$env:TEMP\opencode\uvicorn_err.log"
```
- Kill and start MUST be separate shell-tool commands; killing all python.exe breaks the tool session.
- After a kill command, the immediately following Start-Process command may report a spurious `ChildProcess.kill` error — the process still starts. Verify with `GET /openapi.json`.

## Conventions
- Migrations: numbered `alembic/versions/catering_XXX_*.py`, revision ids `catering_XXX`. Run with `& .\venv\Scripts\python.exe -m alembic upgrade head`.
- Public status endpoint response has TOP-LEVEL keys `inquiry`, `quotation`, `booking` — quotation is NOT nested inside inquiry.
- Customer-facing write endpoints on the portal are gated by the per-inquiry access token passed as a QUERY param (`?token=...`). Billing/payment endpoints use a separate OTP-based billing JWT instead.
- Manual response construction drops new schema fields silently — when adding fields to an Out model, grep for manual constructor calls.
- `CateringInquiryItem.kind` is constrained: 'default' | 'custom' | 'included' | 'addon'.
- Shared pricing lives in `app/flow.py`: `_price_catalog_ids()` is the single source of catalog item pricing (per_guest × guests, flat × qty). Used by custom-mode totals, premade add-ons, submit-time totals, quotation breakdowns, and the accept-time equipment copy.
- Dishes never need copying into bookings: booking detail and the customer status page read `inquiry.items` through the linked inquiry/quotation live.

## Environment notes
- Windows PowerShell 5.1; `rg` NOT installed (use Grep tool); git NOT installed.
- AGENTS.md did not exist until 2026-08-23 — keep it updated when conventions change.

## Feature history snapshot (2026-08-23)
- Premade-package add-ons (`addon_catalog_ids`, stored as kind='addon' inquiry items) + `requested_service_style` override; accept-time pass copies customer equipment picks into `catering_equipment_assignments`; staff picks are informational only.
- Proof-of-payment upload exists but `/uploads` has NO StaticFiles mount yet (URLs 404) and the admin portal has no proof viewer.

## Quiz system scoring convention (2026-09-08)
- PHP quiz backend has two identical-logic copies: `Backend-PHP/` (XAMPP local) and `deploy/` (Render production, `quiz-system-api-50if.onrender.com`). The Android app uses `USE_HOSTED=true` → `deploy/`. Local helpers (`helpers/*.php`) MUST stay byte-identical across both copies.
- Scoring convention: `exam_submissions.score` stores RAW earned points (sum of points of correct questions), never scaled. Percentage is ALWAYS derived as `(score / SUM(questions.points)) * 100` — NOT `exams.total_points` (that column is only an administrative cap). ENUM is all-or-nothing (no partial credit), matching `helpers/exam_grading.php::isAnswerCorrect()`.
- Pass/fail compares the derived percentage against `exams.passing_score` (a 0–100 threshold). MySQL `/` is floating division (DIV is integer), so `(es.score / qtp.tp) * 100` in SQL is correct.
- Submit flow merges auto-saved answers from `exam_temp_answers` so fast submits don't drop answers; `exam_submissions` is idempotent (return existing submission on resubmit).
- `Backend-PHP/database/migration_score_raw_points.sql` rescales legacy stored scores (previously scaled onto `exams.total_points`) back to raw earned points.

## Teacher report output (2026-09-17)
- The Android teacher Reports & Analytics screen uses `ReportPrintHelper` for all output. PDF export and Print share the same fixed A4 layout: metric cards, a repeated student-table header, non-splitting fixed-height rows, and page-number footers. The Excel action writes a real XLSX with a separate Summary sheet, numeric score/time/flag cells, a bold frozen/filterable results header, and bounded auto-fit column widths.
- `api/teacher/reports_analytics.php` includes `time_used_secs`, `flag_count`, and `auto_submitted` per student. Activity-log telemetry is optional; `exit_attempts` remains the fallback when the live-monitoring migration is not installed.

## Admin Web Panel (2026-09-08)
- Public landing page ships as `Backend-PHP/index.html` (single-file, self-contained design; strip labels: BSED / BEED / BSCS / BSOA). Admin access links point to `rmc-admin/login.php`; nav "Get the app" and Play buttons anchor to `#contact` until a real store URL exists. `partials/header.php` + `partials/footer.php` + `assets/css/site.css` are an unused older landing design — do not treat as the live page.
- RMC Quiz & Exam admin panel lives in `Backend-PHP/rmc-admin/` (deployed to Render together with the backend later). v3 palette: navy `#0A1F44`, royal `#1E4FA0`, amber accent `#E8A33D` (unified panel-wide with the standalone dashboard; `assets/admin.css` accent tokens are amber, renamed from the old amber→blue restyle). Fraunces (headings) / Inter (UI) / IBM Plex Mono (data). No build step — plain PHP + HTML/CSS/JS. `login.php` is self-contained (own inline `<style>`, no admin.css link; back link `../index.html`); the shell (`inc/header.php` + `inc/footer.php`) styles live in `assets/admin.css`.
- Admin accounts are seeded manually (no self-registration); seeded admin: username `admin`, password `admin123` (role 3 `ADMIN`). All existing role/status/student/teacher flows must stay: local helpers must remain byte-identical across `Backend-PHP/` and `deploy/`; the new admin surface is additive (`api/admin/*`, `rmc-admin/*`).
- The panel reuses the existing API (`api/login.php`, `admin/*`) server-side. `rmc-admin/inc/bootstrap.php` derives the API base URL from the request (`admin_api_base()`), so panel and API always stay same-host on XAMPP and Render. The API session token is stored in `$_SESSION['admin_user']['token']` and NEVER exposed to the browser.
- Browser-side mutations in the panel go through `rmc-admin/ajax.php` (whitelisted `?action=`, server calls the API with the stored token) — never call `api/admin/*` endpoints with fetch from the browser.
- Admin endpoints (`api/admin/*`) are gated with `requireRole($pdo, ['ADMIN'])`. Rules: admins cannot change their own role/status (UI disables + `SELF_OPERATION` 403); username/email uniqueness enforced on create+edit; passwords via `helpers/validation.php` (8+ chars, one digit); every admin mutation logs to `activity_logs` (actions `USER_CREATE`/`USER_UPDATE`/`USER_STATUS`, `CLASS_CREATE`/`CLASS_UPDATE`/`CLASS_STATUS`/`CLASS_ROSTER`). Exam oversight actions log `EXAM_STATUS` (force close / schedule / archive; force-close and archive clamp `end_time=NOW()`, `is_closed=1`; schedule restores `SCHEDULED` w/ explicit start/end). Session terminations log `SESSION_KILL` (never able to kill the acting admin's own token; `admin/maintenance.php` hardware-status, `admin/logs.php` audit trail, `admin/reports.php` analytics).
- Suspend/ban is immediate: `user_status.php` and `user_update.php` DELETE the user's `sessions` rows whenever status becomes non-`ACTIVE` (login already refuses non-ACTIVE at `login.php`).
- Shell is persistent: `rmc-admin/inc/header.php` + `inc/footer.php` + `assets/admin.js`. Future screens keep nav items disabled until built (badge `S<n>`); flip `href` + `'soon' => null` in the `$nav_items` array when a screen ships.
- Dashboard is a STANDALONE page (`rmc-admin/dashboard.php`), not part of the shell — it reproduces the `rmc_admin_dashboard.html` template exactly (navy sidebar + college seal + amber accents; no admin.css link) and reads real data server-side from `api/admin/stats.php` + `api/admin/logs.php` using the stored admin token via `admin_api_request()`. Seal image lives at `assets/rmc-seal.jpg` (extracted from the template's embedded base64). Post-login redirect is `dashboard.php`; `inc/header.php` shell nav includes a Dashboard link for other screens. `stats.php` also returns `new_students_7d` / `new_teachers_7d` for the card deltas. The template is in the user's Downloads folder and was last re-synced 2026-09-11.
- DEPLOY (2026-09-11): the admin surface (`rmc-admin/` + `api/admin/`) + `index.html` are synced into `deploy/` so a Render redeploy ships the panel same-host. `deploy/database/quiz_system.sql` seeds roles 1-2 ONLY — the Aiven DB needs the ADDITIVE admin seed run once before admin login works (role 3 `ADMIN` + the `admin`/`admin123` user; see `deploy/database/admin_seed.sql` if committed later). All `api/admin/*` endpoints are column-compatible with `quiz_system.sql`. `deploy/`'s non-admin `api/` files are NEWER than `Backend-PHP/api/` (drift predates this session) — do not overwrite deploy api files with `Backend-PHP/api/` copies.
- CONSOLIDATED SPA (2026-09-11, pushed `6fad6aa`): `rmc-admin/dashboard.php` is now the WHOLE admin panel — all six modules (Users, Classes, Assessments, Reports, Logs, Maintenance) are views inside it; the standalone `users.php`/`classes.php`/`assessments.php`/`reports.php`/`logs.php`/`maintenance.php` + `exam_detail.php` screens still exist server-side and stay functional but are NOT linked from the UI (nav has no All Screens page). `dashboard.php` is self-contained (own inline CSS + JS, no admin.css link) and reads data server-side. New module CSS/JS lives only inside dashboard.php. `ajax.php` now enforces per-route HTTP methods (GET routes like `class_roster`/`exam_detail` are allowed; before it was POST-only) — keep method-matching updates in `ajax.php` when adding routes. Dashboard JS gets row data via `window.USERS/CLASSES/TEACHERS/EXAMS` JSON exports in the initial page load; modals/views use unique ID namespaces (`modal-user`, `f_…`, `c_…`, `s_…`, `ed…`, `cf…`, `kill…`) — do not collide with legacy `modal-create`/`createForm` ids. Sync rule: `dashboard.php` + `ajax.php` must be copied BOTH to `deploy/rmc-admin/` and the `quiz-deploy` clone before pushing.
