<?php

namespace Tests\Feature\Sis;

use App\Notifications\Notice;
use App\Support\ProtectedTriggers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * B-10 (FR-SEC-19): the daily check of the triggers that protect locked results. Dropping a trigger is DDL, which
 * MariaDB commits at once, so those cases run on SQLite here and on MariaDB in Infra\BackupTriggersTest.
 */
class CheckTriggersTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUniversity();
        Notification::fake();
    }

    private function lock(): void
    {
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 14, 17]), 'compute' => true]);
        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o));
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'approve']);
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'lock'])->assertSessionHas('success');
    }

    private function rows(): array
    {
        return DB::table('final_results')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function test_the_expected_triggers_are_the_ones_the_migration_installs(): void
    {
        $this->assertSame([], ProtectedTriggers::missing());
        $this->assertEqualsCanonicalizing(array_keys(ProtectedTriggers::EXPECTED), ProtectedTriggers::present());
    }

    public function test_it_passes_with_nothing_locked_and_with_locked_results_and_changes_nothing(): void
    {
        $this->artisan('sis:check-triggers')->assertExitCode(0);
        $this->lock();
        $before = $this->rows();
        $this->artisan('sis:check-triggers')->expectsOutputToContain('are in place')->assertExitCode(0);
        $this->assertSame($before, $this->rows());
        Notification::assertNothingSent();
    }

    public function test_a_missing_trigger_is_reported_to_the_log_and_the_admins(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertTrue(true, 'covered on MariaDB by Infra\BackupTriggersTest');

            return;
        }
        $this->lock();
        DB::unprepared('DROP TRIGGER final_results_locked_delete');
        Log::spy();

        $this->artisan('sis:check-triggers')->expectsOutputToContain('final_results_locked_delete: missing')->assertExitCode(1);
        Log::shouldHaveReceived('critical')->withArgs(fn ($m, $c) => $c['problems'] === ['final_results_locked_delete: missing'])->once();
        Notification::assertSentTo($this->admin, Notice::class, fn (Notice $n) => $n->kind === 'danger' && str_contains($n->body, 'final_results_locked_delete'));
        Notification::assertNotSentTo($this->teacherA, Notice::class);
    }

    public function test_a_trigger_that_no_longer_refuses_is_reported_and_the_try_is_rolled_back(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->assertTrue(true, 'DDL commits on MariaDB; the missing case is covered there');

            return;
        }
        $this->lock();
        $before = $this->rows();
        DB::unprepared('DROP TRIGGER final_results_locked_delete');
        DB::unprepared('CREATE TRIGGER final_results_locked_delete BEFORE DELETE ON final_results FOR EACH ROW BEGIN SELECT 1; END');

        $this->artisan('sis:check-triggers')->expectsOutputToContain('final_results_locked_delete: present but did not refuse')->assertExitCode(1);
        $this->assertSame($before, $this->rows(), 'the delete it tried was rolled back');
    }
}
