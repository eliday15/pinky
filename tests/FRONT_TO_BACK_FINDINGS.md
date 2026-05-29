# Front-to-Back Findings — Pinky HR

Generated while building the comprehensive feature-test suite (now 1,187 passing tests,
6,366 assertions).

**Resolution status (2026-05-29):** **ALL items #1–#13 are FIXED** — each fix is locked
in by a now-passing test; the suite has **0 skips**. The two former design decisions were
resolved per the owner's call:
- **#11** — the dead `verified` middleware was **removed** from `routes/web.php` (the app
  uses admin-provisioning + must_change_password + mandatory 2FA, not email verification;
  mail is `log`-only and users are auto-verified on creation).
- **#9** — the `supervisor` role was **granted `reports.view_team`**, giving supervisors
  team-scoped access (their direct reports only) to all reports, including the weekly
  overtime "Formato de Tiempo Extra".

**Production runs on MySQL.** Some findings only manifest on SQLite (the test/e2e
driver) or on Postgres — those are flagged `SQLite/portability` and do **not** affect
production today, but they (a) block test coverage of those code paths and (b) would
break a non-MySQL deploy. Everything else is a **real production defect** regardless of DB.

Legend: 🔴 high · 🟠 medium · 🟡 low/design

---

## Real production defects (DB-independent)

### 🔴 1. Attendance time-edit returns HTTP 500
- **Where:** `app/Http/Controllers/AttendanceController.php:336`
- **What:** `$dailyHours = $schedule ? ($daySchedule->daily_work_hours ?? 8) : 8;` — `$schedule`
  is never defined in `update()` (the method uses `$employee->schedule`). Laravel converts the
  undefined-variable `E_WARNING` to an `ErrorException` → **500 whenever both check_in and
  check_out are edited for a scheduled employee.** The whole manual attendance correction flow is broken.
- **Fix:** use `$employee->schedule` and move `$daySchedule`/`$dailyHours` inside the
  `if ($employee->schedule)` block (or guard `$daySchedule`).
- **Test:** `tests/Feature/Attendance/AttendanceControllerTest.php` → `update with both times recalculates hours`

### 🔴 2. Bulk incident creation 500s when "reason" is omitted
- **Where:** `app/Http/Controllers/IncidentController.php:393` (validate at :340)
- **What:** `'reason' => $validated['reason']` but `reason` is `nullable`; when the request omits it,
  the key is absent from `$validated` → `Undefined array key "reason"` → `ErrorException` → 500.
- **Fix:** `'reason' => $validated['reason'] ?? null`.
- **Test:** `tests/Feature/Incidents/IncidentControllerTest.php` → `store bulk auto approves and deducts vacation`

### 🔴 3. Report CSV/Excel exports leak ALL employees' data
- **Where:** `app/Http/Controllers/ReportExportController.php` (lines 50, 79, 121, 161, 189, 243, 273, 301, 445, 475, …)
- **What:** every export uses `Employee::active()->pluck('id')` instead of the
  `ScopesReportEmployees::scopedActiveEmployeeIds()` trait that every *screen* report uses. The
  gate accepts `reports.view_own`, so an **employee downloads every employee's attendance/payroll/incident rows** — cross-employee data leak.
- **Fix:** replace `Employee::active()->pluck('id')` with `$this->scopedActiveEmployeeIds()` (the controller can `use ScopesReportEmployees`).
- **Test:** `tests/Feature/Reports/ReportExportTest.php` → `employee export leaks other employees data`

### 🔴 4. "Asistencia" discipline report is always empty
- **Where:** `app/Http/Controllers/AttendanceReportController.php:209`
- **What:** `in_array($currentDate->englishDayOfWeek, $workingDays)` compares `"Monday"` (capitalized)
  against `working_days` stored lowercase (`"monday"`). Never matches → `expectedDays` is always 0 →
  the attendance/asistencia report never reports any expected day. DB-independent.
- **Fix:** `strtolower($currentDate->englishDayOfWeek)` (or normalize both sides).
- **Test:** `tests/Feature/Reports/DisciplineReportTest.php` → `asistencia never reports perfect attendance due to day case bug`

### 🟠 5. Incidents store NEGATIVE hours from a time range
- **Where:** `app/Http/Controllers/IncidentController.php:182` (store) and `:474` (update)
- **What:** `$validated['hours'] = $end->diffInMinutes($start) / 60`. On Carbon 3 (Laravel 12)
  `diffInMinutes` is **signed**; with the later time as receiver it yields a negative value
  (e.g. `09:00→13:30` → `-4.5`). A 4.5h permission is persisted as `-4.5`.
- **Fix:** `$start->diffInMinutes($end)` (or `abs(...)`).
- **Test:** `tests/Feature/Incidents/IncidentControllerTest.php` → `store auto calculates hours from time range`

### 🟠 6. Creating a user without `employee_id` 500s
- **Where:** `app/Http/Controllers/UserController.php:122` (store) and `:202` (update)
- **What:** `if ($validated['employee_id'])` — `employee_id` is `nullable`; if the request omits it,
  the key is absent → `Undefined array key` → 500 instead of creating the user.
- **Fix:** `if (! empty($validated['employee_id']))` / `$validated['employee_id'] ?? null`.
- **Test:** `tests/Feature/Admin/UserControllerTest.php` → `store crashes when employee id is omitted`

### 🟠 7. `reports.payroll` / `reports.payrollTrends` ignore report scoping
- **Where:** `app/Http/Controllers/ReportController.php` `payroll()` (~229-267), `payrollTrends()` (~665-700)
- **What:** unlike every other report action, these never call `scopedActiveEmployeeIds()`, so any
  gate-passing user (incl. a `view_own` employee) sees ALL employees' payroll data.
- **Fix:** scope the queries via the trait like the sibling actions.
- **Test:** `tests/Feature/Reports/ReportControllerTest.php` (payroll scoping cases)

### 🟠 8. `AttendanceController::calendar()` has no authorization check
- **Where:** `app/Http/Controllers/AttendanceController.php` `calendar()` (~215-264)
- **What:** unlike `index()/edit()/export()`, it calls neither `$this->authorize()` nor any
  permission check and does not scope the `?employee=` param — any authenticated user can view any
  employee's attendance calendar.
- **Fix:** add `$this->authorize('viewAny', AttendanceRecord::class)` + the same per-scope filtering as `index()`.

### 🟡 9. Supervisors cannot see the team overtime report
- **Where:** `database/seeders/RolesPermissionsSeeder.php` (supervisor block) vs `OvertimeReportController`
- **What:** the weekly overtime ("Formato de Tiempo Extra") report is intended for supervisors per
  department, but the `supervisor` role is granted **no** `reports.*` permission → 403.
- **Fix (design decision):** grant `reports.view_team` to supervisor, or intentionally keep it admin/RRHH-only.
- **Test:** `tests/Feature/Reports/OvertimeReportTest.php` → `supervisor should be able to view team overtime report`

### 🟡 10. Sync-agent route-model binding runs before auth
- **Where:** `routes/api.php` + middleware order on `api/sync-agent/{syncLog}/start|done`
- **What:** `SubstituteBindings` resolves `{syncLog}` before `SyncAgentAuth`, so an unauthenticated
  caller gets 404 (exists) vs 401 (missing) — a minor existence-probing oracle.
- **Fix:** run `SyncAgentAuth` before binding (group/middleware priority) — low priority.

### 🟡 11. `verified` middleware never enforces email verification
- **Where:** `app/Models/User.php` (does not implement `MustVerifyEmail`); `routes/web.php:37`
- **What:** the main app group is guarded by `verified`, but since `User` doesn't implement
  `MustVerifyEmail`, the middleware is a no-op. Likely intentional (no email-verification flow), but
  the guard is misleading.
- **Fix (design decision):** implement `MustVerifyEmail` if verification is intended, else drop the `verified` middleware.
- **Test:** `tests/Feature/Auth/VerifiedMiddlewareTest.php` → `unverified user is redirected to verification notice`

---

## SQLite / portability only (production MySQL unaffected)

### 🟠 12. Date-range reports drop the first day on SQLite
- **Where:** `ReportController` (weekly ~107, monthly ~156, overtime ~274, absences ~316, lateArrivals ~383,
  departmentComparison ~484, incidents ~536, productivity ~599) and `AttendanceReportController` (faltas, etc.)
- **What:** `whereBetween('work_date', [$start, $end])` binds Carbon datetimes (`startOfWeek` = `00:00:00`).
  `work_date` is `date`-cast. On SQLite (TEXT comparison) `'2024-01-01' < '2024-01-01 00:00:00'`, so the
  **first day is excluded**. MySQL coerces DATE↔DATETIME, so production is correct.
- **Fix (also enables SQLite test coverage):** bind dates with `->toDateString()` (or `whereDate`/`startOfDay`+`->toDateString()`).

### 🟡 13. Anomalies index uses MySQL-only `FIELD()` ordering
- **Where:** `app/Http/Controllers/AnomalyResolutionController.php:69`
- **What:** `orderByRaw("FIELD(severity, 'critical','warning','info')")` is MySQL-only. On SQLite/Postgres
  the index 500s once any anomaly exists. Production MySQL is fine.
- **Fix (also enables SQLite test coverage):** order by a portable `CASE severity WHEN 'critical' THEN 1 ...` expression.

---

## Not a bug — test-harness limitation (already addressed)

- The "2FA plaintext secret → DecryptException" skips were **not** an app defect. `TwoFactorService`
  stores secrets encrypted (`Crypt::encryptString`) and decrypts on verify; production is correct.
  The test harness (`Tests\Concerns\InteractsWithAuth::confirmTwoFactorFor`) seeded a *plaintext*
  secret, so 2FA-code verification paths failed in tests only. Harness updated to store an encrypted
  secret + provide a valid-TOTP helper so those paths are now testable.
