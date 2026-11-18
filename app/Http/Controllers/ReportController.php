<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Services\Imports\Sheet;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-reports');
        $year = $this->year($request);

        return Inertia::render('Reports/Index', [
            'years' => AcademicYear::orderByDesc('starts_on')->get(['id', 'name']),
            'year' => $year?->id,
            'tables' => $year ? collect(array_keys(ReportService::TABLES))->map(fn ($k) => $this->reports->table($k, $request->user(), $year))->values() : [],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('view-reports');
        $key = $request->validate(['table' => ['required', Rule::in(array_keys(ReportService::TABLES))]])['table'];
        $year = $this->year($request) ?? abort(404);
        $t = $this->reports->table($key, $request->user(), $year);
        $rows = $t['total'] ? [...$t['rows'], $t['total']] : $t['rows'];

        return Sheet::download("{$key}-{$year->name}.xlsx", 'تقرير', $t['columns'], $rows, [], [$t['title'].' — '.$year->name, 'أُعدّ في '.now()->timezone('Asia/Baghdad')->format('Y-m-d H:i').' بواسطة '.$request->user()->name]);
    }

    private function year(Request $request): ?AcademicYear
    {
        return AcademicYear::find($request->integer('year')) ?? AcademicYear::where('is_current', true)->first() ?? AcademicYear::latest('starts_on')->first();
    }
}
