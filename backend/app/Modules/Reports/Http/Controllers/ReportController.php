<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Branch;
use App\Modules\Reports\Export\ReportExporter;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Management and finance reports. `report.view` decides how far a user sees: everything, their own branch, or (for
 * an external office) only its own account. Every report can be downloaded as CSV, which Excel opens.
 */
class ReportController extends Controller
{
    /** Reports about a period (the others show the situation right now). */
    private const DATED_REPORTS = ['branch-activity', 'cancellations', 'refunds', 'staff-movements'];

    private const REPORTS = ['treasury-balances', 'branch-activity', 'cancellations', 'refunds', 'staff-movements', 'debts', 'agents'];

    public function __construct(private ReportService $reports) {}

    public function dashboard(Request $request): JsonResponse
    {
        Gate::authorize('report.view');

        return response()->json(['data' => $this->reports->dashboard($this->branchScope($request))]);
    }

    public function show(Request $request, ReportExporter $exporter, string $report): JsonResponse|StreamedResponse|Response
    {
        Gate::authorize('report.view');
        abort_unless(in_array($report, self::REPORTS, true), 404);

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', 'in:json,csv,xlsx,pdf'],
        ]);
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $table = match ($report) {
            'agents' => $this->reports->agentAccounts($this->agentScope($request)),
            'treasury-balances' => $this->reports->treasuryBalances($this->branchScope($request)),
            'branch-activity' => $this->reports->branchActivity($this->branchScope($request), $from, $to),
            'cancellations' => $this->reports->cancellations($this->branchScope($request), $from, $to),
            'refunds' => $this->reports->refunds($this->branchScope($request), $from, $to),
            'staff-movements' => $this->reports->staffMovements($this->branchScope($request), $from, $to),
            'debts' => $this->reports->debts($this->branchScope($request)),
        };

        return match ($filters['format'] ?? 'json') {
            'csv' => $this->csv($report, $table),
            'xlsx' => response($exporter->xlsx($table, $this->notes($request, $report, $from, $to)), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$report.'.xlsx"',
            ]),
            'pdf' => response($exporter->pdf($table, $this->notes($request, $report, $from, $to)), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$report.'.pdf"',
            ]),
            default => response()->json(['data' => $table]),
        };
    }

    /**
     * The lines printed under a report's title: the period, the branch it is limited to, who printed it and when.
     *
     * @return list<string>
     */
    private function notes(Request $request, string $report, ?string $from, ?string $to): array
    {
        $user = $request->user();
        $notes = [];

        if (in_array($report, self::DATED_REPORTS, true) && ($from !== null || $to !== null)) {
            $notes[] = 'الفترة: من '.($from ?? '—').' إلى '.($to ?? '—');
        }

        if ($user->scopeFor('report.view') === 'branch' && $user->branch_id !== null) {
            $notes[] = 'الفرع: '.Branch::whereKey($user->branch_id)->value('name');
        }

        $notes[] = 'طُبع بواسطة: '.$user->name;
        $notes[] = 'بتاريخ: '.now('Africa/Cairo')->format('Y/m/d H:i');

        return $notes;
    }

    /**
     * The branch a report is limited to, or null for everything. Offices have no company reports.
     */
    private function branchScope(Request $request): ?int
    {
        $user = $request->user();

        return match ($user->scopeFor('report.view')) {
            'all' => null,
            'branch' => $user->branch_id ?? abort(403),
            default => abort(403),
        };
    }

    /**
     * The only office the user may see, or null when they may see all of them.
     */
    private function agentScope(Request $request): ?int
    {
        $user = $request->user();

        return match ($user->scopeFor('report.view')) {
            'all' => null,
            'own' => $user->agent_id ?? abort(403),
            default => abort(403),
        };
    }

    /**
     * @param  array{title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>}  $table
     */
    private function csv(string $report, array $table): StreamedResponse
    {
        return response()->streamDownload(function () use ($table): void {
            $out = fopen('php://output', 'w');
            // The byte-order mark makes Excel read the Arabic text as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_column($table['columns'], 'label'));

            foreach ($table['rows'] as $row) {
                fputcsv($out, array_map(fn (array $column): string => $this->cell($row[$column['key']] ?? ''), $table['columns']));
            }

            fclose($out);
        }, "{$report}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Stops a text cell from being read as a spreadsheet formula.
     */
    private function cell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && str_contains('=+-@', $text[0]) && ! is_numeric($text) ? "'{$text}" : $text;
    }
}
