<?php

namespace Tests\Feature\Sis;

use App\Models\AdministrativeOrder;
use App\Models\FinalResult;
use App\Services\GradebookService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * B-09 (FR-SEC-08): the database refuses to change a locked result, whatever writes it. These tests go around the
 * model on purpose (DB::table), as a script or a direct SQL statement would. They run on SQLite and, in CI, on
 * MariaDB 10.11 and 11.8 with the same assertions.
 */
class LockedResultTriggerTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    private const REFUSED_UPDATE = 'a locked result cannot be changed';

    private const REFUSED_DELETE = 'only draft results can be removed';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
    }

    private function runTo(string $step): void
    {
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 14, 17]), 'compute' => true]);
        if ($step === 'draft') {
            return;
        }
        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('success');
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'approve'])->assertSessionHas('success');
        if ($step === 'approved') {
            return;
        }
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'lock'])->assertSessionHas('success');
    }

    private function row(int $id): array
    {
        return (array) DB::table('final_results')->where('id', $id)->first();
    }

    private function assertRefused(callable $write, string $message): void
    {
        try {
            $write();
            $this->fail('the database accepted a write it must refuse');
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_every_column_of_a_locked_result_is_refused_a_direct_update(): void
    {
        $this->runTo('locked');
        $r = FinalResult::orderBy('id')->first();
        $other = FinalResult::orderBy('id')->skip(1)->first();
        $order = AdministrativeOrder::create(['number' => '9', 'issued_on' => now()->toDateString(), 'type' => 'result_correction', 'title' => 'ت', 'issued_by_authority' => 'ع']);
        $before = $this->row($r->id);

        $changes = [
            'id' => $r->id + 1000, 'enrollment_id' => $other->enrollment_id, 'exam_round' => 2, 'version' => 9,
            'total_mark' => 1, 'rounded_mark' => 1, 'grade_code' => 'Z', 'grade_name_ar' => 'تغيير', 'grade_points' => 3.5,
            'passed' => ! $r->passed, 'outcome' => $r->outcome === 'fail' ? 'pass' : 'fail', 'credits_earned' => 99,
            'policy_snapshot' => '{}', 'input_hash' => str_repeat('0', 64), 'status' => 'draft', 'supersedes_id' => $other->id,
            'administrative_order_id' => $order->id, 'computed_by' => $this->admin->id, 'approved_by' => $this->admin->id,
            'approved_at' => '2000-01-01 00:00:00', 'locked_at' => '2000-01-01 00:00:00', 'created_at' => '2000-01-01 00:00:00',
        ];
        foreach ($changes as $column => $value) {
            $this->assertRefused(fn () => DB::table('final_results')->where('id', $r->id)->update([$column => $value]), self::REFUSED_UPDATE);
        }
        // nor alongside the one change that is allowed
        $this->assertRefused(fn () => DB::table('final_results')->where('id', $r->id)->update(['is_current' => false, 'rounded_mark' => 99]), self::REFUSED_UPDATE);

        $this->assertSame($before, $this->row($r->id), 'the locked row is exactly as it was');
    }

    public function test_superseding_a_locked_result_is_the_one_change_allowed_and_cannot_be_undone(): void
    {
        $this->runTo('locked');
        $r = FinalResult::orderBy('id')->first();

        DB::table('final_results')->where('id', $r->id)->update(['is_current' => false, 'updated_at' => now()]);
        $this->assertFalse((bool) $this->row($r->id)['is_current']);

        $this->assertRefused(fn () => DB::table('final_results')->where('id', $r->id)->update(['is_current' => true]), self::REFUSED_UPDATE);
    }

    public function test_locked_and_approved_results_cannot_be_deleted_drafts_can(): void
    {
        $this->runTo('locked');
        $locked = FinalResult::orderBy('id')->first();
        $this->assertRefused(fn () => DB::table('final_results')->where('id', $locked->id)->delete(), self::REFUSED_DELETE);
        $this->assertRefused(fn () => DB::table('final_results')->delete(), self::REFUSED_DELETE);
        $this->assertSame(4, FinalResult::count());

        // the second offering: a draft can go (as a withdrawal removes it), an approved result cannot
        $o = $this->offeringB;
        $c = $o->components()->where('exam_round', 1)->get()->keyBy('code');
        $rows = $o->enrollments()->pluck('id')->mapWithKeys(fn ($id) => [$id => [$c['coursework']->id => ['mark' => 30], $c['final']->id => ['mark' => 40]]])->all();
        $g = app(GradebookService::class);
        $g->saveMarks($o, $o->teacher, $rows);
        $g->compute($o->fresh(), $o->teacher);
        $draft = FinalResult::whereIn('enrollment_id', array_keys($rows))->orderBy('id')->first();
        DB::table('final_results')->where('id', $draft->id)->delete();
        $this->assertNull(FinalResult::find($draft->id));

        $g->compute($o->fresh(), $o->teacher);
        $g->submit($o->fresh(), $o->teacher);
        $g->approve($o->fresh(), $this->admin);
        $approved = FinalResult::whereIn('enrollment_id', array_keys($rows))->where('status', 'approved')->firstOrFail();
        $this->assertRefused(fn () => DB::table('final_results')->where('id', $approved->id)->delete(), self::REFUSED_DELETE);
    }

    public function test_the_application_paths_still_work_with_the_triggers_in_place(): void
    {
        // compute, submit, approve, lock, correct with an administrative order, open the next round
        $this->runTo('locked');
        $old = FinalResult::orderBy('enrollment_id')->skip(2)->first(); // 44, fail
        $final = $this->offeringA->components()->where('code', 'final')->first();
        $order = AdministrativeOrder::create(['number' => '77', 'issued_on' => now()->toDateString(), 'type' => 'result_correction', 'title' => 'تصحيح', 'issued_by_authority' => 'العمادة']);
        $this->actingAs($this->committeeA)->post(route('offerings.correct', [$this->offeringA, $old]), [
            'order_id' => $order->id, 'reason' => 'خطأ في الجمع', 'marks' => [$final->id => ['mark' => 26]],
        ])->assertSessionHas('success');
        $this->assertFalse($old->fresh()->is_current);
        $this->assertSame(44.0, $old->fresh()->rounded_mark);
        $this->assertSame(56.0, FinalResult::where('supersedes_id', $old->id)->value('rounded_mark'));

        $this->actingAs($this->committeeA)->post(route('offerings.decide', $this->offeringA->fresh()), ['action' => 'next_round'])->assertSessionHas('success');
        $this->assertSame(2, $this->offeringA->fresh()->current_round);
    }

    public function test_the_triggers_are_installed(): void
    {
        $names = match (DB::getDriverName()) {
            'sqlite' => DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'final_results')->pluck('name'),
            default => DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->where('EVENT_OBJECT_TABLE', 'final_results')->pluck('TRIGGER_NAME'),
        };
        $this->assertEqualsCanonicalizing(['final_results_locked_update', 'final_results_locked_delete'], $names->all());
    }

    /** down() removes the triggers and nothing else; up() puts them back. SQLite only: DDL inside the test's transaction. */
    public function test_the_migration_rolls_back_and_forward_cleanly(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertTrue(true, 'covered on MariaDB by LockedResultsBackupRestoreTest');

            return;
        }
        $this->runTo('locked');
        $r = FinalResult::orderBy('id')->first();
        $migration = require database_path('migrations/2026_11_21_000000_protect_locked_final_results.php');

        $migration->down();
        DB::table('final_results')->where('id', $r->id)->update(['grade_code' => 'Z']);
        $this->assertSame('Z', $this->row($r->id)['grade_code'], 'without the triggers the database itself no longer refuses');
        DB::table('final_results')->where('id', $r->id)->update(['grade_code' => $r->grade_code]);

        $migration->up();
        $this->assertRefused(fn () => DB::table('final_results')->where('id', $r->id)->update(['grade_code' => 'Z']), self::REFUSED_UPDATE);
        $this->assertSame(4, FinalResult::count(), 'no data touched either way');
    }
}
