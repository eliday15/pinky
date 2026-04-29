<?php

use App\Http\Controllers\AnomalyResolutionController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthorizationController;
use App\Http\Controllers\CompensationTypeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeBulkController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncidentTypeController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\OvertimeReportController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SecurityDeviceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VacationTableController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
});

Route::middleware(['auth', 'verified', 'password-changed', 'two-factor-setup'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Schedules
    Route::resource('schedules', ScheduleController::class);

    // Departments
    Route::resource('departments', DepartmentController::class);

    // Positions
    Route::resource('positions', PositionController::class);

    // Compensation Types
    Route::resource('compensation-types', CompensationTypeController::class)->except(['show']);

    // Incident Types
    Route::resource('incident-types', IncidentTypeController::class)->except(['show']);

    // Vacation Table
    Route::get('/settings/vacation-table', [VacationTableController::class, 'index'])->name('settings.vacation-table');
    Route::put('/settings/vacation-table', [VacationTableController::class, 'update'])->name('settings.vacation-table.update');

    // Employee Bulk Excel Import/Export (MUST be before resource route)
    Route::get('/employees/export', [EmployeeBulkController::class, 'export'])->name('employees.export');
    Route::get('/employees/import', [EmployeeBulkController::class, 'showImport'])->name('employees.import');
    Route::post('/employees/import/preview', [EmployeeBulkController::class, 'preview'])->name('employees.import.preview');
    Route::post('/employees/import/confirm', [EmployeeBulkController::class, 'confirm'])->name('employees.import.confirm');

    // Employees
    Route::resource('employees', EmployeeController::class);
    Route::post('/employees/bulk-update', [EmployeeController::class, 'bulkUpdate'])->name('employees.bulkUpdate');

    // Attendance
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/calendar', [AttendanceController::class, 'calendar'])->name('attendance.calendar');
    Route::get('/attendance/export', [AttendanceController::class, 'export'])->name('attendance.export');
    Route::get('/attendance/sync-logs', [AttendanceController::class, 'syncLogs'])->name('attendance.sync-logs');
    Route::post('/attendance/sync', [AttendanceController::class, 'sync'])->name('attendance.sync');
    Route::get('/attendance/{attendance}/edit', [AttendanceController::class, 'edit'])->name('attendance.edit');
    Route::put('/attendance/{attendance}', [AttendanceController::class, 'update'])->name('attendance.update');

    // Incidents
    Route::get('/incidents/create-bulk', [IncidentController::class, 'createBulk'])->name('incidents.createBulk');
    Route::post('/incidents/store-bulk', [IncidentController::class, 'storeBulk'])->name('incidents.storeBulk');
    Route::resource('incidents', IncidentController::class);
    Route::post('/incidents/{incident}/approve', [IncidentController::class, 'approve'])->name('incidents.approve');
    Route::post('/incidents/{incident}/reject', [IncidentController::class, 'reject'])->name('incidents.reject');

    // Authorizations
    Route::get('/authorizations/create-bulk', [AuthorizationController::class, 'createBulk'])->name('authorizations.createBulk');
    Route::post('/authorizations/store-bulk', [AuthorizationController::class, 'storeBulk'])->name('authorizations.storeBulk');
    Route::resource('authorizations', AuthorizationController::class);
    Route::post('/authorizations/{authorization}/approve', [AuthorizationController::class, 'approve'])->name('authorizations.approve');
    Route::post('/authorizations/{authorization}/reject', [AuthorizationController::class, 'reject'])->name('authorizations.reject');
    Route::post('/authorizations/{authorization}/mark-paid', [AuthorizationController::class, 'markPaid'])->name('authorizations.markPaid');

    // Payroll
    Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::get('/payroll/create', [PayrollController::class, 'create'])->name('payroll.create');
    Route::post('/payroll', [PayrollController::class, 'store'])->name('payroll.store');
    Route::get('/payroll/{payroll}', [PayrollController::class, 'show'])->name('payroll.show');
    Route::post('/payroll/{payroll}/calculate', [PayrollController::class, 'calculate'])->name('payroll.calculate');
    Route::post('/payroll/{payroll}/approve', [PayrollController::class, 'approve'])->name('payroll.approve');
    Route::post('/payroll/{payroll}/mark-paid', [PayrollController::class, 'markPaid'])->name('payroll.markPaid');
    Route::delete('/payroll/{payroll}', [PayrollController::class, 'destroy'])->name('payroll.destroy');
    Route::get('/payroll/{payroll}/export/contpaqi', [PayrollController::class, 'exportContpaqi'])->name('payroll.export.contpaqi');
    Route::get('/payroll/entry/{entry}', [PayrollController::class, 'entryDetail'])->name('payroll.entry');

    // Reports
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/daily', [ReportController::class, 'daily'])->name('reports.daily');
    Route::get('/reports/weekly', [ReportController::class, 'weekly'])->name('reports.weekly');
    Route::get('/reports/monthly', [ReportController::class, 'monthly'])->name('reports.monthly');
    Route::get('/reports/payroll', [ReportController::class, 'payroll'])->name('reports.payroll');
    Route::get('/reports/overtime', [ReportController::class, 'overtime'])->name('reports.overtime');
    Route::get('/reports/absences', [ReportController::class, 'absences'])->name('reports.absences');
    Route::get('/reports/late-arrivals', [ReportController::class, 'lateArrivals'])->name('reports.lateArrivals');
    Route::get('/reports/vacation-balance', [ReportController::class, 'vacationBalance'])->name('reports.vacationBalance');
    Route::get('/reports/department-comparison', [ReportController::class, 'departmentComparison'])->name('reports.departmentComparison');
    Route::get('/reports/incidents', [ReportController::class, 'incidents'])->name('reports.incidents');
    Route::get('/reports/productivity', [ReportController::class, 'productivity'])->name('reports.productivity');
    Route::get('/reports/payroll-trends', [ReportController::class, 'payrollTrends'])->name('reports.payrollTrends');

    // Weekly overtime report (Formato de Tiempo Extra) per department
    Route::prefix('reports/overtime-weekly')->name('reports.overtime-weekly.')->group(function () {
        Route::get('/', [OvertimeReportController::class, 'index'])->name('index');
        Route::get('/preview', [OvertimeReportController::class, 'preview'])->name('preview');
        Route::get('/export/pdf', [OvertimeReportController::class, 'exportPdf'])->name('export.pdf');
        Route::get('/export/excel', [OvertimeReportController::class, 'exportExcel'])->name('export.excel');
    });

    // Discipline Reports
    Route::get('/reports/faltas', [AttendanceReportController::class, 'faltas'])->name('reports.faltas');
    Route::get('/reports/asistencia', [AttendanceReportController::class, 'asistencia'])->name('reports.asistencia');
    Route::get('/reports/retardos', [AttendanceReportController::class, 'retardos'])->name('reports.retardos');
    Route::get('/reports/salidas-tempranas', [AttendanceReportController::class, 'earlyDepartures'])->name('reports.earlyDepartures');

    // FASE 5.4: Report Exports (CSV/Excel)
    Route::get('/reports/export/daily', [ReportExportController::class, 'exportDaily'])->name('reports.export.daily');
    Route::get('/reports/export/weekly', [ReportExportController::class, 'exportWeekly'])->name('reports.export.weekly');
    Route::get('/reports/export/monthly', [ReportExportController::class, 'exportMonthly'])->name('reports.export.monthly');
    Route::get('/reports/export/absences', [ReportExportController::class, 'exportAbsences'])->name('reports.export.absences');
    Route::get('/reports/export/late-arrivals', [ReportExportController::class, 'exportLateArrivals'])->name('reports.export.lateArrivals');
    Route::get('/reports/export/vacation-balance', [ReportExportController::class, 'exportVacationBalance'])->name('reports.export.vacationBalance');
    Route::get('/reports/export/incidents', [ReportExportController::class, 'exportIncidents'])->name('reports.export.incidents');
    Route::get('/reports/export/overtime', [ReportExportController::class, 'exportOvertime'])->name('reports.export.overtime');

    // Discipline Report Exports
    Route::get('/reports/export/faltas', [ReportExportController::class, 'exportFaltas'])->name('reports.export.faltas');
    Route::get('/reports/export/asistencia', [ReportExportController::class, 'exportAsistencia'])->name('reports.export.asistencia');
    Route::get('/reports/export/retardos', [ReportExportController::class, 'exportRetardos'])->name('reports.export.retardos');
    Route::get('/reports/export/salidas-tempranas', [ReportExportController::class, 'exportEarlyDepartures'])->name('reports.export.earlyDepartures');

    // Profile routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/attendance', [SettingsController::class, 'attendance'])->name('settings.attendance');
    Route::get('/settings/payroll', [SettingsController::class, 'payroll'])->name('settings.payroll');
    Route::get('/settings/general', [SettingsController::class, 'general'])->name('settings.general');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::put('/settings/single', [SettingsController::class, 'updateSingle'])->name('settings.updateSingle');

    // Security devices (2FA) management from Settings
    Route::post('/settings/security/devices', [SecurityDeviceController::class, 'store'])->name('settings.security.devices.store');
    Route::post('/settings/security/devices/confirm', [SecurityDeviceController::class, 'confirm'])->name('settings.security.devices.confirm')->middleware('throttle:6,1');
    Route::delete('/settings/security/devices/{device}', [SecurityDeviceController::class, 'destroy'])->name('settings.security.devices.destroy');
    Route::post('/settings/security/recovery-codes', [SecurityDeviceController::class, 'regenerateRecoveryCodes'])->name('settings.security.recovery-codes.regenerate');

    // Anomalies
    Route::get('/anomalies', [AnomalyResolutionController::class, 'index'])->name('anomalies.index');
    Route::post('/anomalies/bulk-resolve', [AnomalyResolutionController::class, 'bulkResolve'])->name('anomalies.bulk-resolve');
    Route::post('/anomalies/bulk-dismiss', [AnomalyResolutionController::class, 'bulkDismiss'])->name('anomalies.bulk-dismiss');
    Route::get('/anomalies/{anomaly}', [AnomalyResolutionController::class, 'show'])->name('anomalies.show');
    Route::post('/anomalies/{anomaly}/resolve', [AnomalyResolutionController::class, 'resolve'])->name('anomalies.resolve');
    Route::post('/anomalies/{anomaly}/dismiss', [AnomalyResolutionController::class, 'dismiss'])->name('anomalies.dismiss');
    Route::post('/anomalies/{anomaly}/link-authorization', [AnomalyResolutionController::class, 'linkAuthorization'])->name('anomalies.linkAuthorization');

    // Users
    Route::resource('users', UserController::class)->except(['show']);
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    Route::post('/users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor'])->name('users.reset-two-factor');

    // Audit Logs
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');
});

require __DIR__.'/auth.php';
