<?php

namespace App\Console\Commands;

use App\AcademicRules\PolicySet;
use App\Models\AcademicPolicy;
use App\Models\FinalResult;
use App\Services\PolicyResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * R-4 (DOC-P3-01): a READ-ONLY report of stored course results whose policy snapshot does not match the policies
 * in force on their academic year's policy date (the X-9 symptom: results computed with "today's" policies).
 *
 * It never changes a result or a snapshot. Any correction goes through an administrative order (FR-AR-19).
 * Read-only is enforced twice: every statement other than a read is refused before it reaches the database, and on
 * MariaDB the whole run is one READ ONLY transaction.
 */
class AuditPolicyDates extends Command
{
    protected $signature = 'sis:audit-policy-dates
        {--year= : Only this academic year, by name (e.g. 2025-2026)}
        {--program= : Only this program id}
        {--output= : Where to write the CSV report (default storage/app/reports/policy-date-audit-<time>.csv)}
        {--show-student-number : Print full university numbers (masked by default)}
        {--format=csv : csv or json}
        {--include-matching : Also list results that match}';

    protected $description = 'Read-only report: results whose policy snapshot differs from the policies of their academic year.';

    /** Policy types that change a course result; `decision` only matters for evaluative courses. */
    private const RESULT_TYPES = ['grade_scale', 'rounding', 'total', 'rounds'];

    private const CLASS_AR = [
        'suspicious' => 'مشتبه به',
        'needs_review' => 'يحتاج مراجعة بشرية',
        'match' => 'مطابق',
    ];

    private const REASON_AR = [
        'UNKNOWN_POLICY' => 'النسخة المستخدمة غير موجودة في جدول السياسات',
        'OUT_OF_PERIOD' => 'النسخة المستخدمة لم تكن نافذة في تاريخ سنة النتيجة',
        'EXPECTED_EXISTED_BEFORE' => 'السياسة المتوقعة كانت معتمدة قبل احتساب النتيجة ولم تُستخدم',
        'NO_EXPECTED' => 'لا توجد اليوم سياسة من هذا النوع نافذة في تاريخ السنة',
        'TIMING_UNCLEAR' => 'السياسة المتوقعة اعتُمدت بين إنشاء النتيجة وقفلها',
        'CORRECTION_KEEPS_ORIGINAL' => 'تصحيح يحتفظ بسياسة النتيجة الأصلية (FR-AR-19)',
        'NEWER_VERSION_AFTER_RESULT' => 'النسخة المتوقعة اعتُمدت بعد احتساب النتيجة',
        'MISSING_IN_SNAPSHOT' => 'اللقطة لا تحوي هذا النوع، والسياسة المتوقعة اعتُمدت قبل النتيجة',
        'ADDED_AFTER_RESULT' => 'اللقطة لا تحوي هذا النوع، والسياسة اعتُمدت بعد النتيجة',
        'MATCH' => 'مطابق',
    ];

    private const COLUMNS = ['result_id', 'student', 'academic_year', 'policy_date', 'program_id', 'course', 'term', 'exam_round',
        'result_version', 'status', 'is_current', 'computed_at', 'policy_type', 'used_policy_id', 'used_version',
        'used_effective_from', 'used_effective_to', 'expected_policy_id', 'expected_version', 'expected_effective_from',
        'expected_effective_to', 'expected_approved_at', 'classification', 'classification_ar', 'reason', 'reason_ar', 'likely_legitimate'];

    /** Reasons that usually have a lawful explanation; still for a person to confirm, never decided by the command. */
    private const LIKELY_LEGITIMATE = ['ADDED_AFTER_RESULT', 'CORRECTION_KEEPS_ORIGINAL', 'NEWER_VERSION_AFTER_RESULT'];

    private bool $guard = false;

    /** @var array<int, ?AcademicPolicy> */
    private array $policyRows = [];

    public function handle(PolicyResolver $resolver): int
    {
        $db = DB::connection();
        $this->installReadOnlyGuard($db);
        $dbReadOnly = $db->getDriverName() === 'mariadb' && $db->transactionLevel() === 0;

        $this->guard = true;
        if ($dbReadOnly) {
            $db->unprepared('START TRANSACTION READ ONLY');
        }

        try {
            [$rows, $counts, $scanned] = $this->scan($resolver);
        } finally {
            if ($dbReadOnly) {
                $db->unprepared('ROLLBACK');
            }
            $this->guard = false;
        }

        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['csv', 'json'], true)) {
            $this->error('--format must be csv or json.');

            return self::INVALID;
        }
        $path = $this->option('output') ?: storage_path('app/reports/policy-date-audit-'.now()->format('Ymd-His').'.'.$format);
        $this->writeReport($path, $format, $rows, $counts, $scanned, $dbReadOnly);

        $this->table(['التصنيف', 'العدد'], collect(self::CLASS_AR)->map(fn ($ar, $k) => [$ar, $counts[$k] ?? 0])->values()->all());
        $this->line("نتائج مفحوصة: {$scanned}");
        $this->line("التقرير: {$path}");
        $this->line('لم يُعدّل أي شيء. أي تصحيح يكون بأمر إداري.');

        return self::SUCCESS;
    }

    /** Refuse any statement that is not a read while the audit runs, before it reaches the database. */
    private function installReadOnlyGuard(Connection $db): void
    {
        $db->beforeExecuting(function (string $query) {
            if ($this->guard && ! preg_match('/^\s*(select|show|with|pragma|explain|\(\s*select|start transaction read only|rollback)\b/i', $query)) {
                throw new RuntimeException('sis:audit-policy-dates is read-only; refused: '.mb_substr($query, 0, 80));
            }
        });
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, int>, 2: int} */
    private function scan(PolicyResolver $resolver): array
    {
        $rows = [];
        $counts = array_fill_keys(array_keys(self::CLASS_AR), 0);
        $scanned = 0;

        $query = FinalResult::query()
            ->with(['enrollment.offering.term.year', 'enrollment.offering.course', 'enrollment.studentProgram.program', 'enrollment.student'])
            ->when($this->option('year'), fn ($q, $y) => $q->whereHas('enrollment.offering.term.year', fn ($y2) => $y2->where('name', $y)))
            ->when($this->option('program'), fn ($q, $p) => $q->whereHas('enrollment.studentProgram', fn ($s) => $s->where('program_id', (int) $p)));

        foreach ($query->lazyById(500) as $result) {
            $scanned++;
            $enrollment = $result->enrollment;
            $offering = $enrollment->offering;
            $program = $enrollment->studentProgram->program;
            $on = $offering->policyDate();
            $expected = $resolver->candidates($program, $on)->keyBy('type');
            $used = $this->usedPolicies($result);

            $types = self::RESULT_TYPES;
            if ($offering->is_evaluative) {
                $types[] = 'decision';
            }
            $worst = 'match';
            foreach (array_unique(array_merge(array_keys($used), array_values(array_intersect($types, $expected->keys()->all())))) as $type) {
                [$class, $reason] = $this->classify($result, $used[$type] ?? null, $expected->get($type), $on);
                $worst = $this->worse($worst, $class);
                if ($class !== 'match' || $this->option('include-matching')) {
                    $rows[] = $this->row($result, $type, $used[$type] ?? null, $expected->get($type), $on, $class, $reason);
                }
            }
            $counts[$worst]++;
        }

        return [$rows, $counts, $scanned];
    }

    /** @return array<string, array{id: int, version: int}> the policies stored in the result's snapshot, by type */
    private function usedPolicies(FinalResult $result): array
    {
        $out = [];
        $set = PolicySet::fromResultSnapshot($result->policy_snapshot ?? []);
        foreach ([...self::RESULT_TYPES, 'decision'] as $type) {
            if ($set->has($type)) {
                $p = $set->get($type);
                $out[$type] = ['id' => $p->id, 'version' => $p->version];
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: string} classification and reason code */
    private function classify(FinalResult $result, ?array $used, ?AcademicPolicy $expected, Carbon $on): array
    {
        if ($used && $expected && $used['id'] === $expected->id) {
            return ['match', 'MATCH'];
        }

        if (! $used) {
            // the snapshot has no policy of this type, but one applies now
            return $this->approvedAfter($expected, $result) ? ['needs_review', 'ADDED_AFTER_RESULT'] : ['needs_review', 'MISSING_IN_SNAPSHOT'];
        }

        $row = $this->policyRow($used['id']);
        if (! $row) {
            return ['suspicious', 'UNKNOWN_POLICY'];
        }
        if (! $this->inForce($row, $on)) {
            return ['suspicious', 'OUT_OF_PERIOD'];
        }
        if ($result->supersedes_id && $this->supersededUsed($result, $row->type) === $used['id']) {
            return ['needs_review', 'CORRECTION_KEEPS_ORIGINAL'];
        }
        if (! $expected) {
            return ['needs_review', 'NO_EXPECTED'];
        }
        if ($this->approvedAfter($expected, $result)) {
            return ['needs_review', 'NEWER_VERSION_AFTER_RESULT'];
        }
        $approved = $expected->approved_at ?? $expected->created_at;
        if ($approved && $result->created_at && $approved->lt($result->created_at)) {
            return ['suspicious', 'EXPECTED_EXISTED_BEFORE'];
        }

        return ['needs_review', 'TIMING_UNCLEAR'];
    }

    private function approvedAfter(?AcademicPolicy $policy, FinalResult $result): bool
    {
        $approved = $policy?->approved_at ?? $policy?->created_at;
        // a locked result's snapshot cannot change after locking, so locked_at bounds when it was computed
        // (updated_at moves later, e.g. when a correction marks it not current)
        $computed = $result->locked_at ?? $result->updated_at ?? $result->created_at;

        return $approved && $computed && $approved->gt($computed);
    }

    private function inForce(AcademicPolicy $p, Carbon $on): bool
    {
        return $p->effective_from && $p->effective_from->copy()->startOfDay()->lte($on)
            && (! $p->effective_to || $p->effective_to->copy()->endOfDay()->gte($on));
    }

    private function supersededUsed(FinalResult $result, string $type): ?int
    {
        $original = FinalResult::find($result->supersedes_id);

        return $original ? ($this->usedPolicies($original)[$type]['id'] ?? null) : null;
    }

    private function policyRow(int $id): ?AcademicPolicy
    {
        return array_key_exists($id, $this->policyRows) ? $this->policyRows[$id] : ($this->policyRows[$id] = AcademicPolicy::find($id));
    }

    private function worse(string $a, string $b): string
    {
        $rank = ['match' => 0, 'needs_review' => 1, 'suspicious' => 2];

        return $rank[$b] > $rank[$a] ? $b : $a;
    }

    /** @return array<string, mixed> */
    private function row(FinalResult $r, string $type, ?array $used, ?AcademicPolicy $expected, Carbon $on, string $class, string $reason): array
    {
        $e = $r->enrollment;
        $usedRow = $used ? $this->policyRow($used['id']) : null;
        $date = fn ($d) => $d?->toDateString() ?? '';

        return [
            'result_id' => $r->id,
            'student' => $this->student($e->student?->university_number),
            'academic_year' => $e->offering->term->year->name,
            'policy_date' => $on->toDateString(),
            'program_id' => $e->studentProgram->program_id,
            'course' => $e->offering->course?->code ?? '',
            'term' => $e->offering->term->code.' '.$e->offering->term->name_ar,
            'exam_round' => $r->exam_round,
            'result_version' => $r->version,
            'status' => $r->status,
            'is_current' => $r->is_current ? 1 : 0,
            'computed_at' => $r->created_at?->toDateTimeString() ?? '',
            'policy_type' => $type,
            'used_policy_id' => $used['id'] ?? '',
            'used_version' => $used['version'] ?? '',
            'used_effective_from' => $date($usedRow?->effective_from),
            'used_effective_to' => $date($usedRow?->effective_to),
            'expected_policy_id' => $expected?->id ?? '',
            'expected_version' => $expected?->version ?? '',
            'expected_effective_from' => $date($expected?->effective_from),
            'expected_effective_to' => $date($expected?->effective_to),
            'expected_approved_at' => ($expected?->approved_at ?? $expected?->created_at)?->toDateTimeString() ?? '',
            'classification' => $class,
            'classification_ar' => self::CLASS_AR[$class],
            'reason' => $reason,
            'reason_ar' => self::REASON_AR[$reason],
            'likely_legitimate' => in_array($reason, self::LIKELY_LEGITIMATE, true) ? 1 : 0,
        ];
    }

    /** University numbers are masked by default: only the last three characters are shown. */
    private function student(?string $number): string
    {
        if ($number === null || $number === '') {
            return '';
        }
        if ($this->option('show-student-number')) {
            return $number;
        }

        return str_repeat('*', max(0, mb_strlen($number) - 3)).mb_substr($number, -3);
    }

    /**
     * The report holds student data: it stays on the server under storage/ (never public), readable only by the
     * application's own user (0640 in a 0750 folder). The command itself only runs from a shell on the server.
     */
    private function writeReport(string $path, string $format, array $rows, array $counts, int $scanned, bool $dbReadOnly): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $meta = [
            'report' => 'sis:audit-policy-dates',
            'generated_at' => now()->toIso8601String(),
            'filters' => ['year' => $this->option('year'), 'program' => $this->option('program')],
            'results_scanned' => $scanned,
            'results_by_classification' => $counts,
            'rows' => count($rows),
            'student_numbers' => $this->option('show-student-number') ? 'full' : 'masked',
            'read_only' => ['statement_guard' => true, 'database_read_only_transaction' => $dbReadOnly],
            'policy_date_rule' => 'CourseOffering::policyDate() = end of the academic year (pending the university decision, DOC-P3-01 R-3)',
        ];

        if ($format === 'json') {
            file_put_contents($path, json_encode(['meta' => $meta, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $h = fopen($path, 'w');
            fwrite($h, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic
            fputcsv($h, self::COLUMNS, escape: '');
            foreach ($rows as $row) {
                fputcsv($h, array_values($row), escape: '');
            }
            fclose($h);
        }
        @chmod($path, 0640);

        $meta['sha256'] = hash_file('sha256', $path);
        file_put_contents($path.'.meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($path.'.meta.json', 0640);
    }
}
