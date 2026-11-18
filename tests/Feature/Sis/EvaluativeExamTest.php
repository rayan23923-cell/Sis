<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicPolicy;
use App\Models\AcademicYear;
use App\Services\GradebookService;
use App\Services\MasterSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluativeExamTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
        $this->offeringB->update(['is_evaluative' => true]);
        foreach (AcademicPolicy::where('type', 'decision')->get() as $p) {
            \DB::table('academic_policies')->where('id', $p->id)->update(['parameters' => json_encode(['evaluative' => ['fail_below' => 20, 'out_of' => 60, 'treatment' => 5]] + $p->parameters)]);
        }
    }

    /** @param list<array{0: float, 1: float}> $marks coursework of 40, final of 60 */
    private function lock(array $marks): void
    {
        $o = $this->offeringB;
        $c = $o->components()->where('exam_round', 1)->get()->keyBy('code');
        $rows = [];
        foreach ($o->enrollments()->orderBy('id')->get()->values() as $i => $e) {
            [$cw, $final] = $marks[$i] ?? [30, 40];
            $rows[$e->id] = [$c['coursework']->id => ['mark' => $cw], $c['final']->id => ['mark' => $final]];
        }
        $g = app(GradebookService::class);
        $g->saveMarks($o, $o->teacher, $rows);
        $g->compute($o->fresh(), $o->teacher);
        $g->submit($o->fresh(), $o->teacher);
        $g->approve($o->fresh(), $this->admin);
        $g->lock($o->fresh(), $this->admin);
    }

    public function test_an_evaluative_course_fails_below_the_threshold_whatever_the_total(): void
    {
        $this->lock([[30, 50], [40, 18], [40, 15], [30, 17]]);
        $results = $this->offeringB->enrollments()->orderBy('id')->with('currentResult')->get()->map->currentResult;
        $this->assertFalse($results[2]->passed);              // 55 in total, but 15 < 20 in the evaluative exam
        $this->assertSame('resit', $results[2]->outcome);
        $this->assertEquals(55, $results[2]->rounded_mark);

        $sheet = app(MasterSheetService::class)->build($this->offeringB->program, 1, AcademicYear::first(), 1);
        $rows = collect($sheet['students'])->keyBy(fn ($r) => (int) collect($r['cells'])->first()['r1']);
        // 58 with 18 in the exam: the treatment (5 of 100 → 3 of 60) covers the 2 missing marks
        $c = collect($rows[58]['cells'])->first();
        $this->assertTrue($c['passed']);
        $this->assertEquals(2, $c['ev_treated']);
        $this->assertSame('ناجح دور أول', $rows[58]['result']);
        // 55 with 15: 5 missing, more than the treatment → ضعيف, and the ordinary decision cannot lift it
        $c = collect($rows[55]['cells'])->first();
        $this->assertTrue($c['ev_failed']);
        $this->assertSame('ضعيف', $c['grade']);
        $this->assertNull($c['award']);
        $this->assertSame('راسب دور أول', $rows[55]['result']);
        $this->assertSame(1, $rows[55]['evaluative_failed']);
        // 47 with 17: the treatment adds 3 to the exam, which also reaches the pass mark
        $this->assertSame('ناجح دور أول', $rows[47]['result']);
        $this->assertEquals(5, $sheet['policy']['evaluative']['treatment']);

        $q = ['program' => $this->offeringB->program_id, 'stage' => 1, 'year' => AcademicYear::first()->id, 'round' => 1];
        $this->actingAs($this->admin)->get(route('master-sheet.show', $q))->assertOk();
        $this->actingAs($this->admin)->get(route('master-sheet.pdf', $q))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->admin)->get(route('master-sheet.excel', $q))->assertOk();
    }
}
