<?php

namespace Tests\Feature\Sis;

use App\Models\AcademicPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\RecordPolicyVersionJob;
use Tests\TestCase;

/**
 * B-02 (FR-AR-24): no stale policy cache between queue jobs.
 * The worker test runs a real `queue:work` process, so it needs a database another process can see: MariaDB.
 */
class PolicyResolverLifetimeTest extends TestCase
{
    use SisFixture;

    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Needs a database shared with a worker process (DB_CONNECTION=mariadb).');
        }
        // committed data, visible to the worker process (no wrapping transaction)
        Artisan::call('migrate:fresh');
        $this->buildUniversity();
        $this->file = storage_path('framework/testing-policy-jobs-'.getmypid().'.jsonl');
        @unlink($this->file);
    }

    protected function tearDown(): void
    {
        if ($this->file !== '') {
            @unlink($this->file);
        }
        RefreshDatabaseState::$migrated = false; // the next RefreshDatabase test starts from a fresh schema
        parent::tearDown();
    }

    private function lines(): array
    {
        return is_file($this->file) ? array_map(fn ($l) => json_decode($l, true), file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
    }

    private function waitFor(int $count, Process $worker): void
    {
        $until = microtime(true) + 60;
        while (count($this->lines()) < $count) {
            if (microtime(true) > $until || ! $worker->isRunning() && count($this->lines()) < $count) {
                $worker->stop();
                $this->fail("Worker did not run job {$count}.\n".$worker->getOutput().$worker->getErrorOutput());
            }
            usleep(200_000);
        }
    }

    #[Group('mariadb')]
    public function test_a_long_running_worker_sees_a_policy_approved_between_two_jobs(): void
    {
        $program = $this->offeringA->program ?? $this->offeringA->enrollments()->first()->studentProgram->program;
        $on = $this->offeringA->policyDate()->toDateString();

        $env = ['QUEUE_CONNECTION' => 'database', 'APP_ENV' => 'testing'];
        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $k) {
            $env[$k] = (string) env($k);
        }
        $worker = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', 'database', '--queue=policy-lifetime',
            '--sleep=1', '--max-jobs=2', '--timeout=60', '--tries=1'], base_path(), $env);
        $worker->start();

        try {
            dispatch(new RecordPolicyVersionJob('before', $program->id, $on, $this->file))->onConnection('database')->onQueue('policy-lifetime');
            $this->waitFor(1, $worker);

            // approved while the same worker process keeps running
            $current = AcademicPolicy::where('type', 'grade_scale')->where('study_system', $program->study_system)->orderByDesc('version')->firstOrFail();
            AcademicPolicy::create([
                'type' => 'grade_scale', 'name_ar' => 'نسخة جديدة', 'version' => $current->version + 1, 'executor' => $current->executor,
                'parameters' => $current->parameters, 'study_system' => $current->study_system, 'scope_type' => 'university',
                'effective_from' => '2025-09-01', 'is_official' => false, 'status' => 'approved', 'approved_at' => now(),
            ]);

            dispatch(new RecordPolicyVersionJob('after', $program->id, $on, $this->file))->onConnection('database')->onQueue('policy-lifetime');
            $this->waitFor(2, $worker);
            $worker->wait();
        } finally {
            $worker->stop();
        }

        [$before, $after] = $this->lines();
        $this->assertSame($before['pid'], $after['pid'], 'both jobs must run in the same long-lived worker process');
        $this->assertSame($current->version, $before['version']);
        $this->assertSame($current->version + 1, $after['version'], 'the second job must see the policy approved after the worker started');
    }
}
