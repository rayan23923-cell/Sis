<?php

namespace Tests\Feature\Sis;

use App\Console\Commands\AuditPolicyDates;
use App\Models\AcademicPolicy;
use App\Models\AdministrativeOrder;
use App\Models\FinalResult;
use App\Services\GradebookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** R-4 (DOC-P3-01): `sis:audit-policy-dates` reports, classifies, and never changes anything. */
class AuditPolicyDatesTest extends TestCase
{
    use RefreshDatabase, SisFixture;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-20 09:00:00');
        $this->buildUniversity();
        $this->out = storage_path('framework/testing-audit-'.getmypid().'.csv');
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
        @unlink($this->out.'.meta.json');
        parent::tearDown();
    }

    /** @param  (callable(): void)|null  $beforeLock  runs on the approved results, the last moment they can still be written (B-09) */
    private function runToLocked(?callable $beforeLock = null): void
    {
        $o = $this->offeringA;
        $this->actingAs($this->teacherA)->post(route('offerings.marks', $o), ['rows' => $this->marksFor($o, [55, 20, 14, 17]), 'compute' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->teacherA)->post(route('offerings.submit', $o))->assertSessionHas('success');
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'approve'])->assertSessionHas('success');
        if ($beforeLock) {
            $beforeLock();
        }
        $this->actingAs($this->committeeA)->post(route('offerings.decide', $o), ['action' => 'lock'])->assertSessionHas('success');
    }

    /**
     * The X-9 symptom: next year's grade scale stored in a 2025-2026 result. Written into the approved row just
     * before the lock, as results computed before PR #2 were; once locked the database refuses the write (B-09).
     */
    private function lockWithNextYearsScale(): array
    {
        $next = $this->newScale('2026-09-01');
        $this->runToLocked(function () use ($next) {
            $r = FinalResult::orderBy('id')->first();
            $snap = $r->policy_snapshot;
            $snap['grade_scale'] = $next->toRule()->snapshot();
            DB::table('final_results')->where('id', $r->id)->update(['policy_snapshot' => json_encode($snap)]);
        });

        return [FinalResult::orderBy('id')->first(), $next];
    }

    private function newScale(string $from, $approvedAt = null): AcademicPolicy
    {
        $cur = AcademicPolicy::where('type', 'grade_scale')->where('study_system', 'bologna')->orderByDesc('version')->firstOrFail();

        return AcademicPolicy::create([
            'type' => 'grade_scale', 'name_ar' => 'نسخة', 'version' => $cur->version + 1, 'executor' => $cur->executor,
            'parameters' => $cur->parameters, 'study_system' => 'bologna', 'scope_type' => 'university',
            'effective_from' => $from, 'is_official' => false, 'status' => 'approved', 'approved_at' => $approvedAt ?? now(),
        ]);
    }

    /** @return array{0: list<array<string, string>>, 1: array<string, mixed>} */
    private function audit(array $options = []): array
    {
        $this->artisan('sis:audit-policy-dates', ['--output' => $this->out] + $options)->assertSuccessful();
        $lines = array_map(fn ($l) => str_getcsv($l, escape: ''), file($this->out, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $header = array_map(fn ($h) => ltrim($h, "\u{FEFF}"), array_shift($lines));

        return [array_map(fn ($l) => array_combine($header, $l), $lines), json_decode(file_get_contents($this->out.'.meta.json'), true)];
    }

    private function fingerprint(): string
    {
        return md5(json_encode([
            DB::table('final_results')->orderBy('id')->get(), DB::table('academic_policies')->orderBy('id')->get(),
            DB::table('audit_logs')->count(),
        ]));
    }

    public function test_results_computed_with_their_years_policies_are_reported_clean(): void
    {
        $this->runToLocked();
        [$rows, $meta] = $this->audit();

        $this->assertSame([], $rows);
        $this->assertSame(4, $meta['results_scanned']);
        $this->assertSame(4, $meta['results_by_classification']['match']);
        $this->assertSame(0, $meta['results_by_classification']['suspicious']);
        $this->assertSame(hash_file('sha256', $this->out), $meta['sha256']);
    }

    public function test_a_policy_not_in_force_in_the_results_year_is_suspicious(): void
    {
        [$r, $next] = $this->lockWithNextYearsScale();
        $this->assertSame('locked', $r->status);

        [$rows, $meta] = $this->audit();
        $this->assertCount(1, $rows);
        $this->assertSame((string) $r->id, $rows[0]['result_id']);
        $this->assertSame(['suspicious', 'OUT_OF_PERIOD', 'grade_scale'], [$rows[0]['classification'], $rows[0]['reason'], $rows[0]['policy_type']]);
        $this->assertSame((string) $next->version, $rows[0]['used_version']);
        $this->assertSame('2026-09-01', $rows[0]['used_effective_from']);
        $this->assertSame('2025-2026', $rows[0]['academic_year']);
        $this->assertSame('2026-08-31', $rows[0]['policy_date']);
        $this->assertSame('S1 الأول', $rows[0]['term']);
        $this->assertSame('0', $rows[0]['likely_legitimate']);
        $this->assertSame(1, $meta['results_by_classification']['suspicious']);
    }

    public function test_a_version_approved_before_the_result_but_not_used_is_suspicious(): void
    {
        $this->runToLocked();
        $this->newScale('2025-09-01', now()->subDays(3)); // was in force and approved before the results were computed

        [$rows] = $this->audit();
        $this->assertCount(4, $rows);
        $this->assertSame(['EXPECTED_EXISTED_BEFORE'], array_values(array_unique(array_column($rows, 'reason'))));
        $this->assertSame('suspicious', $rows[0]['classification']);
    }

    public function test_a_version_approved_after_the_result_may_be_legitimate_and_corrections_keep_the_original(): void
    {
        $this->runToLocked();
        $this->travel(1)->days();
        $this->newScale('2025-09-01');
        $this->travel(1)->days();

        $old = FinalResult::orderBy('enrollment_id')->skip(2)->first();
        $order = AdministrativeOrder::create(['number' => '7', 'issued_on' => now()->toDateString(), 'type' => 'result_correction', 'title' => 'ت', 'issued_by_authority' => 'ع']);
        $final = $this->offeringA->components()->where('code', 'final')->first();
        $new = app(GradebookService::class)->correct($old, [$final->id => ['mark' => 12]], $order, $this->admin, 'خطأ');

        [$rows, $meta] = $this->audit();
        $byResult = collect($rows)->keyBy('result_id');
        $this->assertSame('CORRECTION_KEEPS_ORIGINAL', $byResult[(string) $new->id]['reason']);
        $this->assertSame('NEWER_VERSION_AFTER_RESULT', $byResult[(string) $old->id]['reason']);
        $this->assertSame(0, $meta['results_by_classification']['suspicious']);
        $this->assertSame(5, $meta['results_by_classification']['needs_review']);
        $this->assertSame(['1'], array_values(array_unique(array_column($rows, 'likely_legitimate'))), 'a person decides; the hint says it is probably lawful');
    }

    public function test_the_report_can_be_saved_as_json_readable_only_by_the_application(): void
    {
        $this->runToLocked();
        $this->newScale('2025-09-01', now()->subDays(3));
        $json = preg_replace('/\\.csv$/', '.json', $this->out);

        $this->artisan('sis:audit-policy-dates', ['--output' => $json, '--format' => 'json'])->assertSuccessful();
        $report = json_decode(file_get_contents($json), true);
        $meta = json_decode(file_get_contents($json.'.meta.json'), true);

        $this->assertCount(4, $report['rows']);
        $this->assertSame('suspicious', $report['rows'][0]['classification']);
        $this->assertSame(hash_file('sha256', $json), $meta['sha256']);
        $this->assertSame('0640', substr(sprintf('%o', fileperms($json)), -4));
        $this->artisan('sis:audit-policy-dates', ['--format' => 'xml'])->assertFailed();
        @unlink($json);
        @unlink($json.'.meta.json');
    }

    public function test_student_numbers_are_masked_unless_asked(): void
    {
        $this->runToLocked();
        $this->newScale('2025-09-01', now()->subDays(3));
        $number = FinalResult::orderBy('id')->first()->enrollment->student->university_number;

        [$rows, $meta] = $this->audit();
        $this->assertNotSame($number, $rows[0]['student']);
        $this->assertStringEndsWith(mb_substr($number, -3), $rows[0]['student']);
        $this->assertStringStartsWith('*', $rows[0]['student']);
        $this->assertSame('masked', $meta['student_numbers']);

        [$rows, $meta] = $this->audit(['--show-student-number' => true]);
        $this->assertSame($number, $rows[0]['student']);
        $this->assertSame('full', $meta['student_numbers']);
    }

    public function test_the_audit_changes_nothing(): void
    {
        $this->lockWithNextYearsScale();

        $before = $this->fingerprint();
        $this->audit(['--include-matching' => true]);
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_writes_are_refused_while_the_audit_runs(): void
    {
        $command = app(AuditPolicyDates::class);
        $install = new \ReflectionMethod($command, 'installReadOnlyGuard');
        $install->invoke($command, DB::connection());
        (new \ReflectionProperty($command, 'guard'))->setValue($command, true);

        try {
            DB::table('final_results')->update(['is_current' => false]);
            $this->fail('a write went through during the audit');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('read-only', $e->getMessage());
        } finally {
            (new \ReflectionProperty($command, 'guard'))->setValue($command, false);
        }
        $this->assertNotNull(DB::table('final_results')->count()); // reads still work
    }
}
