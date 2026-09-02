<?php

namespace App\Http\Controllers;

use App\Exports\OvertimeWeekly\OvertimeWeeklyExport;
use App\Models\Department;
use App\Services\Reports\OvertimeReportTemplateRegistry;
use App\Services\Reports\WeeklyOvertimeReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Weekly overtime report (Formato de Tiempo Extra) per department.
 */
class OvertimeReportController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly WeeklyOvertimeReportService $reportService,
        private readonly OvertimeReportTemplateRegistry $registry,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(function ($request, $next) {
                $user = $request->user();
                // Department-wide weekly overtime report; only roles with team or org-wide
                // visibility may access it.
                if (! $user->hasPermissionTo('reports.view_all')
                    && ! $user->hasPermissionTo('reports.view_team')) {
                    abort(403);
                }

                return $next($request);
            }),
        ];
    }

    /**
     * Selector page (department + week picker).
     */
    public function index(Request $request): Response
    {
        $departments = Department::active()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        // Encargados (view_team sin view_all): SOLO sus departamentos — el de
        // su propio registro y los de su gente (Luis 2026-08-12: "los
        // encargados no pueden ver lo de otros departamentos").
        $allowed = $this->allowedDepartmentIds($request->user());
        if ($allowed !== null) {
            $departments = $departments->whereIn('id', $allowed)->values();
        }

        return Inertia::render('Reports/OvertimeWeekly/Index', [
            'departments' => $departments,
            'defaultWeekStart' => Carbon::now()->startOfWeek()->toDateString(),
            'canViewAllDepartments' => $this->isAdmin($request),
        ]);
    }

    /**
     * Departamentos que puede reportear el usuario. null = sin restricción
     * (reports.view_all). Para view_team: el depto propio + los de sus
     * subordinados (un encargado puede tener gente en más de uno).
     *
     * @return \Illuminate\Support\Collection<int>|null
     */
    private function allowedDepartmentIds($user): ?\Illuminate\Support\Collection
    {
        if ($user->hasPermissionTo('reports.view_all')) {
            return null;
        }

        $employee = $user->employee;
        if (! $employee) {
            return collect();
        }

        $ids = \App\Models\Employee::whereIn('id', $employee->allSubordinateIds())
            ->pluck('department_id')
            ->push($employee->department_id)
            ->filter()
            ->unique()
            ->values();

        return $ids;
    }

    /**
     * HTML preview of the rendered report.
     */
    public function preview(Request $request): Response
    {
        [$department, $start, $end] = $this->resolveInputs($request);

        $showAmounts = $this->isAdmin($request);
        $report = $department
            ? $this->reportService->buildReport($department, $start, $end, $request->boolean('include_pending'), $showAmounts)
            : $this->reportService->buildConsolidatedReport($start, $end, $request->boolean('include_pending'), $showAmounts);
        $template = $department ? $this->registry->for($department) : $this->registry->consolidated();

        return Inertia::render('Reports/OvertimeWeekly/Preview', [
            'report' => $report,
            'layout' => $template->vueComponent(),
        ]);
    }

    /**
     * PDF export via DomPDF.
     */
    public function exportPdf(Request $request): HttpResponse
    {
        [$department, $start, $end] = $this->resolveInputs($request);

        $showAmounts = $this->isAdmin($request);
        $report = $department
            ? $this->reportService->buildReport($department, $start, $end, $request->boolean('include_pending'), $showAmounts)
            : $this->reportService->buildConsolidatedReport($start, $end, $request->boolean('include_pending'), $showAmounts);
        $report['show_observations'] = $request->boolean('show_observations', true);
        $template = $department ? $this->registry->for($department) : $this->registry->consolidated();

        $pdf = Pdf::loadView($template->pdfView(), ['report' => $report])
            ->setPaper('a4', 'landscape');

        $filename = $this->buildFilename($report, 'pdf');

        return $pdf->download($filename);
    }

    /**
     * Excel export via Maatwebsite.
     */
    public function exportExcel(Request $request): BinaryFileResponse
    {
        [$department, $start, $end] = $this->resolveInputs($request);

        $showAmounts = $this->isAdmin($request);
        $report = $department
            ? $this->reportService->buildReport($department, $start, $end, $request->boolean('include_pending'), $showAmounts)
            : $this->reportService->buildConsolidatedReport($start, $end, $request->boolean('include_pending'), $showAmounts);
        $report['show_observations'] = $request->boolean('show_observations', true);
        $template = $department ? $this->registry->for($department) : $this->registry->consolidated();

        $title = sprintf(
            'FORMATO DE TIEMPO EXTRA %s - PERIODO DEL %s AL %s%s',
            strtoupper($report['department']['name']),
            Carbon::parse($report['week_start'])->format('d/m/Y'),
            Carbon::parse($report['week_end'])->format('d/m/Y'),
            ($report['includes_pending'] ?? false) ? ' (INCLUYE PENDIENTES DE APROBAR)' : '',
        );

        $export = new OvertimeWeeklyExport(
            $template->excelHeadings($report),
            $template->excelRows($report),
            $title,
        );

        $filename = $this->buildFilename($report, 'xlsx');

        return Excel::download($export, $filename);
    }

    /**
     * Validate and parse request inputs into a Department, start date and an
     * optional range end.
     *
     * `week_start` is the range start (kept for backwards compatibility).
     * `end_date` is optional: when present the report covers the literal range
     * [week_start, end_date]; when absent it falls back to the lun–dom week
     * that contains week_start.
     *
     * @return array{0: ?Department, 1: Carbon, 2: ?Carbon}
     */
    private function resolveInputs(Request $request): array
    {
        $validated = $request->validate([
            'department_id' => ['required'],
            'week_start' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:week_start'],
        ]);

        $allDepartments = $validated['department_id'] === 'all';
        if ($allDepartments && ! $this->isAdmin($request)) {
            abort(403, 'Solo administración puede generar el reporte de todos los departamentos.');
        }

        if (! $allDepartments) {
            $request->validate([
                'department_id' => ['integer', 'exists:departments,id'],
            ]);
        }

        $department = $allDepartments ? null : Department::findOrFail((int) $validated['department_id']);

        // Mismo candado que el selector: un encargado no genera (ni exporta)
        // el reporte de un departamento ajeno aunque arme la URL a mano.
        $allowed = $this->allowedDepartmentIds($request->user());
        if ($department && $allowed !== null && ! $allowed->contains($department->id)) {
            abort(403, 'Solo puedes generar el reporte de tus departamentos.');
        }

        $start = Carbon::parse($validated['week_start']);
        $end = ! empty($validated['end_date']) ? Carbon::parse($validated['end_date']) : null;

        return [$department, $start, $end];
    }

    /**
     * Build a filename like "tiempo_extra_corte_2026-03-02.pdf".
     */
    private function buildFilename(array $report, string $extension): string
    {
        $code = strtolower($report['department']['code'] ?: 'reporte');
        $weekStart = $report['week_start'];
        // Que el archivo con pendientes no se confunda con el oficial aprobado.
        $suffix = ($report['includes_pending'] ?? false) ? '_con_pendientes' : '';

        return "tiempo_extra_{$code}_{$weekStart}{$suffix}.{$extension}";
    }

    private function isAdmin(Request $request): bool
    {
        return $request->user()->hasAnyRole(['superadmin', 'admin']);
    }
}
