<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Program;
use App\Services\Documents\Pdf;
use App\Services\Imports\Sheet;
use App\Services\MasterSheetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MasterSheetController extends Controller
{
    public function __construct(private readonly MasterSheetService $sheets) {}

    public function show(Request $request): Response
    {
        Gate::authorize('view-progression');
        [$programs, $program, $stage, $year, $round] = $this->target($request);

        return Inertia::render('MasterSheet/Show', [
            'years' => AcademicYear::orderByDesc('starts_on')->get(['id', 'name']),
            'programs' => $programs->map(fn ($p) => ['id' => $p->id, 'name' => $p->name_ar, 'department' => $p->department->name_ar, 'years' => $p->duration_years, 'system' => $p->study_system]),
            'selected' => ['year' => $year?->id, 'program' => $program?->id, 'stage' => $stage, 'round' => $round],
            'sheet' => $program && $year ? $this->sheets->build($program, $stage, $year, $round) : null,
        ]);
    }

    public function pdf(Request $request, Pdf $pdf): HttpResponse
    {
        Gate::authorize('view-progression');
        [, $program, $stage, $year, $round] = $this->target($request);
        abort_unless($program && $year, 404);
        $sheet = $this->sheets->build($program, $stage, $year, $round);
        $program->load('department.college');

        return $pdf->response('pdf.master-sheet', [
            'title' => 'الماستر شيت', 'ref' => $year->name.' / '.$program->code.' / '.$stage,
            'university' => config('sis.university_name'), 'unit' => $program->department->college->name_ar.' — '.$program->department->name_ar,
            'printedBy' => $request->user()->name, 'printedAt' => now()->timezone('Asia/Baghdad')->format('Y-m-d H:i'),
            'official' => $sheet['official'], 'sheet' => $sheet, 'program' => $program, 'stage' => $stage, 'year' => $year, 'round' => $round,
        ], "master-sheet-{$program->code}-{$stage}-{$year->name}.pdf", true);
    }

    public function excel(Request $request): StreamedResponse
    {
        Gate::authorize('view-progression');
        [, $program, $stage, $year, $round] = $this->target($request);
        abort_unless($program && $year, 404);
        $sheet = $this->sheets->build($program, $stage, $year, $round);

        $headers = ['ت', 'الرقم الجامعي', 'اسم الطالب'];
        foreach ($sheet['courses'] as $c) {
            foreach (['السعي', 'النهائي', 'الدور الأول', 'الدور الثاني', 'القرار', 'الاستحقاق', 'التقدير'] as $h) {
                $headers[] = $c['name'].' — '.$h;
            }
        }
        array_push($headers, 'مواد التحميل', 'المعدل', 'النتيجة', 'مواد الرسوب');
        $rows = [];
        foreach ($sheet['students'] as $i => $s) {
            $row = [$i + 1, $s['number'], $s['name']];
            foreach ($sheet['courses'] as $c) {
                $x = $s['cells'][$c['code']] ?? null;
                array_push($row, $x['coursework'] ?? null, $x['final'] ?? null, $x['r1'] ?? null, $x['r2'] ?? null, $x['award'] ?? null, $x['entitlement'] ?? null, $x['grade'] ?? null);
            }
            $carried = collect($s['cells'])->filter(fn ($x) => $x && $x['carried'])->map(fn ($x) => $x['name'].' ('.($x['entitlement'] ?? '—').')')->implode('، ');
            array_push($row, $carried, $s['gpa'], $s['result'], collect($s['failed'])->pluck('name')->implode('، '));
            $rows[] = $row;
        }

        return Sheet::download("master-sheet-{$program->code}-{$stage}-{$year->name}.xlsx", 'الماستر شيت', $headers, $rows, [],
            ['الماستر شيت — '.$program->name_ar.' — المرحلة '.$stage.' — '.$year->name.($round === 1 ? ' — الدور الأول' : ' — بعد الدور الثاني'),
                'أُعدّ في '.now()->timezone('Asia/Baghdad')->format('Y-m-d H:i').' بواسطة '.$request->user()->name.($sheet['official'] ? '' : ' — محسوب بسياسات غير رسمية')]);
    }

    private function target(Request $request): array
    {
        $user = $request->user();
        $programs = Program::visibleTo($user)->with('department:id,name_ar,college_id')->orderBy('name_ar')->get();
        $year = AcademicYear::find($request->integer('year')) ?? AcademicYear::where('is_current', true)->first() ?? AcademicYear::latest('starts_on')->first();
        $program = $programs->firstWhere('id', $request->integer('program')) ?? $programs->first();
        $stage = max(1, $request->integer('stage', 1));
        $round = $request->integer('round', 2) === 1 ? 1 : 2;

        return [$programs, $program, $stage, $year, $round];
    }
}
