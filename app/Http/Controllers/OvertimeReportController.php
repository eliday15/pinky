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
                if (! $user->hasPermissionTo('reports.view_all')
                    && ! $user->hasPermissionTo('reports.view_team')
                    && ! $user->hasPermissionTo('reports.view_own')) {
                    abort(403);
                }

                return $next($request);
            }),
        ];
    }

    /**
     * Selector page (department + week picker).
     */
    public function index(): Response
    {
        return Inertia::render('Reports/OvertimeWeekly/Index', [
            'departments' => Department::active()
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'defaultWeekStart' => Carbon::now()->startOfWeek()->toDateString(),
        ]);
    }

    /**
     * HTML preview of the rendered report.
     */
    public function preview(Request $request): Response
    {
        [$department, $weekStart] = $this->resolveInputs($request);

        $report = $this->reportService->buildReport($department, $weekStart);
        $template = $this->registry->for($department);

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
        [$department, $weekStart] = $this->resolveInputs($request);

        $report = $this->reportService->buildReport($department, $weekStart);
        $template = $this->registry->for($department);

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
        [$department, $weekStart] = $this->resolveInputs($request);

        $report = $this->reportService->buildReport($department, $weekStart);
        $template = $this->registry->for($department);

        $title = sprintf(
            'FORMATO DE TIEMPO EXTRA %s - SEMANA DEL %s AL %s',
            strtoupper($report['department']['name']),
            Carbon::parse($report['week_start'])->format('d/m/Y'),
            Carbon::parse($report['week_end'])->format('d/m/Y'),
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
     * Validate and parse request inputs into a Department + Carbon.
     *
     * @return array{0: Department, 1: Carbon}
     */
    private function resolveInputs(Request $request): array
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'week_start' => ['required', 'date'],
        ]);

        $department = Department::findOrFail($validated['department_id']);
        $weekStart = Carbon::parse($validated['week_start']);

        return [$department, $weekStart];
    }

    /**
     * Build a filename like "tiempo_extra_corte_2026-03-02.pdf".
     */
    private function buildFilename(array $report, string $extension): string
    {
        $code = strtolower($report['department']['code'] ?: 'reporte');
        $weekStart = $report['week_start'];

        return "tiempo_extra_{$code}_{$weekStart}.{$extension}";
    }
}
