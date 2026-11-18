<?php

namespace App\Services\Imports;

use App\Models\AcademicYear;
use App\Models\AdministrativeOrder;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentService;
use App\Services\WorkflowException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk admission from Excel in two steps: a preview that checks every row (and possible duplicates)
 * without saving, then a confirmation that admits the accepted rows in one transaction.
 */
class StudentImport
{
    /** Column header => field. The first four are required. */
    public const COLUMNS = [
        'الاسم الرباعي' => 'name_ar',
        'اسم الأم' => 'mother_name_ar',
        'تاريخ الميلاد' => 'birth_date',
        'الجنس' => 'gender',
        'الرقم الوطني' => 'national_id',
        'الاسم بالإنجليزية' => 'name_en',
        'الهاتف' => 'phone',
        'البريد الإلكتروني' => 'email',
        'قناة القبول' => 'admission_channel',
        'الدراسة' => 'study_shift',
    ];

    public function __construct(private readonly StudentService $students) {}

    public function template(): StreamedResponse
    {
        return Sheet::download('students-template.xlsx', 'الطلبة', array_keys(self::COLUMNS), [
            ['علي حسين كاظم جاسم', 'زينب عباس علي', '2006-03-15', 'ذكر', '', 'Ali Hussein Kadhim', '07701234567', '', 'مركزي', 'صباحي'],
        ], range(0, count(self::COLUMNS) - 1), [
            'السطر الثاني مثال؛ احذفه قبل الرفع.',
            'الأعمدة الأربعة الأولى إلزامية. التاريخ بصيغة 2006-03-15 أو 15/03/2006. الجنس: ذكر أو أنثى.',
            'قناة القبول: مركزي أو موازي أو مسائي أو انتقال. الدراسة: صباحي أو مسائي (تُترك فارغة فتُحدد من القناة).',
            'يُفحص كل سطر قبل الحفظ، ويُعرض الطلبة المشابهون لطلبة موجودين لتقرر قبولهم أو استبعادهم.',
            'الحد الأقصى 2000 طالب في الملف الواحد.',
        ]);
    }

    /** Parses and checks the file; returns a token for the confirmation step. */
    public function preview(string $path, Program $program, AcademicYear $year, int $stage, User $user): string
    {
        if (! $program->curricula()->where('status', 'approved')->exists()) {
            throw new WorkflowException('لا يوجد منهج معتمد لهذا البرنامج. اعتمد منهجاً قبل قبول الطلبة.');
        }
        ['headers' => $headers, 'rows' => $rows] = Sheet::read($path);
        $map = [];
        foreach ($headers as $i => $h) {
            $h = trim(str_replace('*', '', $h));
            if (isset(self::COLUMNS[$h])) {
                $map[$i] = self::COLUMNS[$h];
            }
        }
        $missing = array_diff(array_slice(array_keys(self::COLUMNS), 0, 4), array_map(fn ($f) => array_search($f, self::COLUMNS, true), $map));
        if ($missing) {
            throw new WorkflowException('أعمدة إلزامية غير موجودة: '.implode('، ', $missing).'. استخدم القالب.');
        }

        $out = [];
        $seenIds = [];
        $seenKeys = [];
        foreach ($rows as $n => $raw) {
            $data = ['line' => $n + 2];
            foreach ($map as $i => $field) {
                $data[$field] = $field === 'birth_date' ? Sheet::date($raw[$i] ?? null) : Sheet::text($raw[$i] ?? null);
            }
            $data['gender'] = match (mb_strtolower((string) ($data['gender'] ?? ''))) {
                'ذكر', 'm', 'male', 'ذ' => 'm',
                'أنثى', 'انثى', 'f', 'female', 'أ', 'ا' => 'f',
                default => $data['gender'] ?? null,
            };
            $data['admission_channel'] = match ($data['admission_channel'] ?? null) {
                null, '', 'مركزي', 'القبول المركزي', 'central' => 'central',
                'موازي', 'الموازي', 'الدراسة الموازية', 'parallel' => 'parallel',
                'مسائي', 'المسائي', 'الدراسة المسائية', 'evening' => 'evening',
                'انتقال', 'transfer' => 'transfer',
                default => $data['admission_channel'],
            };
            $data['study_shift'] = match ($data['study_shift'] ?? null) {
                'صباحي', 'صباحية', 'morning' => 'morning',
                'مسائي', 'مسائية', 'evening' => 'evening',
                null, '' => Student::shiftFor($data['admission_channel']),
                default => $data['study_shift'],
            };
            $v = Validator::make($data, [
                'name_ar' => ['required', 'string', 'max:150'],
                'mother_name_ar' => ['required', 'string', 'max:150'],
                'birth_date' => ['required', 'date_format:Y-m-d', 'before:today'],
                'gender' => ['required', 'in:m,f'],
                'national_id' => ['nullable', 'string', 'max:30'],
                'name_en' => ['nullable', 'string', 'max:150'],
                'phone' => ['nullable', 'string', 'max:30'],
                'email' => ['nullable', 'email'],
                'admission_channel' => ['nullable', 'in:central,parallel,evening,transfer,other'],
                'study_shift' => ['required', 'in:morning,evening'],
            ], [], ['admission_channel' => 'قناة القبول', 'study_shift' => 'الدراسة', 'name_ar' => 'الاسم الرباعي', 'mother_name_ar' => 'اسم الأم', 'birth_date' => 'تاريخ الميلاد', 'gender' => 'الجنس', 'email' => 'البريد', 'national_id' => 'الرقم الوطني']);
            $errors = $v->errors()->all();

            $key = ($data['name_ar'] ?? '').'|'.($data['birth_date'] ?? '');
            if (isset($seenKeys[$key]) || (! empty($data['national_id']) && isset($seenIds[$data['national_id']]))) {
                $errors[] = 'مكرر داخل الملف (السطر '.($seenKeys[$key] ?? $seenIds[$data['national_id']]).').';
            }
            $seenKeys[$key] ??= $data['line'];
            if (! empty($data['national_id'])) {
                $seenIds[$data['national_id']] ??= $data['line'];
            }

            $dupes = $errors ? collect() : $this->students->possibleDuplicates($data);
            if (! empty($data['national_id']) && $dupes->contains(fn ($s) => $s->national_id === $data['national_id'])) {
                $errors[] = 'الرقم الوطني مسجل لطالب آخر.';
            }
            $out[] = $data + [
                'state' => $errors ? 'error' : ($dupes->isNotEmpty() ? 'duplicate' : 'ok'),
                'errors' => $errors,
                'matches' => $dupes->map(fn ($s) => $s->name_ar.' — '.$s->university_number)->values()->all(),
            ];
        }

        $token = (string) Str::uuid();
        Cache::put("student-import:{$token}", [
            'user_id' => $user->id, 'program_id' => $program->id, 'year_id' => $year->id, 'stage' => $stage, 'rows' => $out,
        ], now()->addHours(2));

        return $token;
    }

    public function get(string $token, User $user): ?array
    {
        $data = Cache::get("student-import:{$token}");

        return $data && $data['user_id'] === $user->id ? $data : null;
    }

    /** @return array<int, string> university numbers created */
    public function commit(string $token, User $user, bool $includeDuplicates, ?AdministrativeOrder $order): array
    {
        $data = $this->get($token, $user) ?? throw new WorkflowException('انتهت صلاحية المعاينة. ارفع الملف مجدداً.');
        $program = Program::findOrFail($data['program_id']);
        $year = AcademicYear::findOrFail($data['year_id']);
        $rows = collect($data['rows'])->filter(fn ($r) => $r['state'] === 'ok' || ($includeDuplicates && $r['state'] === 'duplicate'));
        if ($rows->isEmpty()) {
            throw new WorkflowException('لا توجد سطور صالحة للحفظ.');
        }

        $numbers = DB::transaction(fn () => $rows->map(fn ($r) => $this->students->admit(
            $r + ['stage' => $data['stage'], 'allow_duplicate' => true], $program, null, $year, $user, $order,
        )->university_number)->all());
        Cache::forget("student-import:{$token}");

        return $numbers;
    }
}
