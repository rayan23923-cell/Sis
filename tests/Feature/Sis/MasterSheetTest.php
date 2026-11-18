<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicYear;
use App\Services\GradebookService;
use App\Services\MasterSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterSheetTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
    }

    /** Coursework 30/40 plus a final out of 60 → totals 30 + final. */
    private function lockB(array $finals): void
    {
        $o = $this->offeringB;
        $g = app(GradebookService::class);
        $g->saveMarks($o, $o->teacher, $this->marksFor($o, $finals));
        $g->compute($o->fresh(), $o->teacher);
        $g->submit($o->fresh(), $o->teacher);
        $g->approve($o->fresh(), $this->admin);
        $g->lock($o->fresh(), $this->admin);
    }

    private function sheet(int $round = 1): array
    {
        return app(MasterSheetService::class)->build($this->offeringB->program, 1, AcademicYear::first(), $round);
    }

    public function test_decision_lifts_near_misses_and_marks_results(): void
    {
        // totals: 80, 47 (needs 3 → lifted by the 5-mark decision), 40 (needs 10 → fails), 50
        $this->lockB([50, 17, 10, 20]);
        $s = collect($this->sheet()['students'])->sortBy('number')->values();
        $byTotal = collect($s)->keyBy(fn ($r) => (int) collect($r['cells'])->first()['r1']);

        $this->assertSame('ناجح دور أول', $byTotal[80]['result']);
        $this->assertSame('ناجح دور أول بقرار', $byTotal[47]['result']);
        $this->assertSame('مقبول بقرار', collect($byTotal[47]['cells'])->first()['grade']);
        $this->assertEquals(50, collect($byTotal[47]['cells'])->first()['entitlement']);
        $this->assertSame('راسب دور أول', $byTotal[40]['result']);
        $this->assertEquals(80, $byTotal[80]['gpa']);
        $this->assertNull($byTotal[40]['gpa']);
    }

    public function test_pages_and_exports_render(): void
    {
        $this->lockB([50, 17, 10, 20]);
        $q = ['program' => $this->offeringB->program_id, 'stage' => 1, 'year' => AcademicYear::first()->id, 'round' => 1];
        $this->actingAs($this->admin)->get(route('master-sheet.show', $q))->assertOk()
            ->assertInertia(fn ($p) => $p->component('MasterSheet/Show')->where('sheet.stats.with_decision', 1)->where('sheet.policy.amount1', 5));
        $this->actingAs($this->admin)->get(route('master-sheet.pdf', $q))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->admin)->get(route('master-sheet.excel', $q))->assertOk();
        $this->actingAs($this->teacherA)->get(route('master-sheet.show', $q))->assertForbidden();
    }

    public function test_second_round_deducts_ten_and_uses_the_decision_left_over(): void
    {
        // round 1: 80, 47 (lifted, uses 3 of 5), 40 and 30 fail
        $this->lockB([50, 17, 10, 0.1]);
        $o = $this->offeringB->fresh();
        $g = app(GradebookService::class);
        $this->assertTrue($g->canOpenNextRound($o));
        $g->openNextRound($o, $this->admin);
        $o = $o->fresh();
        $r2 = $o->components()->where('code', 'final_r2')->first();
        $rows = [];
        foreach ($g->eligible($o, 2) as $e) {
            $rows[$e->id] = [$r2->id => ['mark' => $e->results->first()->rounded_mark == 40 ? 45 : 18]];   // 75 and 48
        }
        $g->saveMarks($o, $o->teacher, $rows);
        $g->compute($o->fresh(), $o->teacher);
        $g->submit($o->fresh(), $o->teacher);
        $g->approve($o->fresh(), $this->admin);
        $g->lock($o->fresh(), $this->admin);

        $rows = collect($this->sheet(2)['students'])->keyBy(fn ($r) => (int) collect($r['cells'])->first()['r1']);
        // 47 was lifted in round 1 and sat no second round
        $this->assertSame('ناجح دور أول بقرار', $rows[47]['result']);
        // 40 → 75 in round 2 → entitlement 65 (−10)
        $c = collect($rows[40]['cells'])->first();
        $this->assertEquals(75, $c['r2']);
        $this->assertEquals(65, $c['entitlement']);
        $this->assertTrue($c['deducted']);
        $this->assertSame('ناجح دور ثاني', $rows[40]['result']);
        // 30 → 48 in round 2; the 2 marks left from round 1 lift it to 50
        $c = collect($rows[30]['cells'])->first();
        $this->assertEquals(2, $c['award']);
        $this->assertSame('ناجح دور ثاني بقرار', $rows[30]['result']);
    }
}
