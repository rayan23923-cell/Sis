<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicYear;
use App\Models\AdministrativeOrder;
use App\Models\ProgressionDecision;
use App\Models\StudentProgram;
use App\Services\GradebookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressionTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
    }

    private function lockB(array $finals): void
    {
        $o = $this->offeringB;
        $g = app(GradebookService::class);
        $teacher = $o->teacher;
        $g->saveMarks($o, $teacher, $this->marksFor($o, $finals));
        $g->compute($o->fresh(), $teacher);
        $g->submit($o->fresh(), $teacher);
        $g->approve($o->fresh(), $this->admin);
        $g->lock($o->fresh(), $this->admin);
    }

    private function params(): array
    {
        return ['program' => $this->offeringB->program_id, 'stage' => 1, 'year' => AcademicYear::first()->id];
    }

    public function test_pending_results_block_decisions(): void
    {
        $this->actingAs($this->registrarB)->get(route('progression.index', $this->params()))
            ->assertOk()->assertInertia(fn ($p) => $p->component('Progression/Index')
            ->where('rows.0.decision', null)->where('rows.0.problem', fn ($m) => str_contains($m, 'غير مقفلة')));

        $this->actingAs($this->admin)->post(route('progression.save'), $this->params())->assertSessionHas('success');
        $this->assertSame(0, ProgressionDecision::count());
    }

    public function test_locked_year_promotes_students_after_order(): void
    {
        $this->lockB([50, 50, 50, 50]);

        $this->actingAs($this->admin)->post(route('progression.save'), $this->params())->assertSessionHas('success');
        $this->assertSame(4, ProgressionDecision::where('status', 'draft')->where('decision', 'promoted')->count());
        $this->assertSame(1, StudentProgram::where('program_id', $this->offeringB->program_id)->max('stage'));

        $order = AdministrativeOrder::create(['number' => '9', 'issued_on' => now()->toDateString(), 'type' => 'year_results', 'title' => 'نتائج', 'issued_by_authority' => 'العمادة']);
        $this->actingAs($this->admin)->post(route('progression.apply'), $this->params() + ['order_id' => $order->id])->assertSessionHas('success');

        $this->assertSame(4, StudentProgram::where('program_id', $this->offeringB->program_id)->where('stage', 2)->count());
        $this->assertTrue(ProgressionDecision::all()->every(fn ($d) => $d->status === 'applied' && $d->administrative_order_id === $order->id));

        // applying again has nothing left and moves nobody
        $this->actingAs($this->admin)->post(route('progression.apply'), $this->params() + ['order_id' => $order->id])->assertSessionHas('error');
        $this->assertSame(0, StudentProgram::where('stage', 3)->count());
    }

    public function test_bologna_without_progression_policy_reports_problem(): void
    {
        $this->actingAs($this->admin)->get(route('progression.index', ['program' => $this->offeringA->program_id, 'stage' => 1, 'year' => AcademicYear::first()->id]))
            ->assertOk()->assertInertia(fn ($p) => $p->where('rows.0.decision', null));
    }

    public function test_other_college_cannot_decide(): void
    {
        $this->actingAs($this->committeeA)->post(route('progression.save'), ['program' => $this->offeringB->program_id, 'stage' => 1, 'year' => AcademicYear::first()->id])
            ->assertNotFound();
        // a college registrar can view but not decide
        $this->actingAs($this->registrarB)->post(route('progression.save'), $this->params())->assertForbidden();
    }
}
