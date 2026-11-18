<?php

namespace App\Services\Imports;

use App\Models\CourseOffering;
use App\Models\User;
use App\Services\GradebookService;
use App\Services\WorkflowException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Marks to and from Excel for the open round of an offering. The import only fills draft marks;
 * the teacher still reviews them in the gradebook and computes, exactly as when typing them in.
 */
class MarksImport
{
    public function __construct(private readonly GradebookService $gradebook) {}

    public function template(CourseOffering $offering): StreamedResponse
    {
        $offering->loadMissing('course');
        $round = $offering->current_round;
        $components = $this->gradebook->editableComponents($offering, $round)->values();
        $headers = array_merge(['الرقم الجامعي', 'اسم الطالب'], $components->map(fn ($c) => "{$c->name_ar} [{$c->code}] من ".(float) $c->max_mark)->all());
        $rows = $this->gradebook->eligible($offering, $round)->sortBy(fn ($e) => $e->student->name_ar)->map(function ($e) use ($components) {
            $marks = $e->marks->keyBy('assessment_component_id');

            return array_merge([(string) $e->student->university_number, $e->student->name_ar], $components->map(function ($c) use ($marks) {
                $m = $marks->get($c->id);

                return $m?->is_absent ? 'غ' : ($m?->mark !== null ? (float) $m->mark : null);
            })->all());
        })->values()->all();

        return Sheet::download("marks-{$offering->course->code}-{$offering->section}-r{$round}.xlsx", $offering->course->code,
            $headers, $rows, range(2, count($headers) - 1), [
                'املأ الأعمدة الملوّنة فقط ولا تغيّر عمود الرقم الجامعي أو عناوين الأعمدة.',
                'اكتب «غ» للطالب الغائب. الخلية الفارغة لا تغيّر الدرجة المحفوظة سابقاً.',
                'بعد الرفع تظهر الدرجات في صفحة المادة كمسودة؛ راجعها ثم اضغط «حفظ واحتساب».',
            ]);
    }

    /** @return array{saved: int, rows: int} */
    public function import(CourseOffering $offering, User $user, string $path): array
    {
        if ($offering->status !== 'open') {
            throw new WorkflowException('لا يمكن رفع درجات بعد رفع النتائج إلى اللجنة.');
        }
        $round = $offering->current_round;
        $components = $this->gradebook->editableComponents($offering, $round)->keyBy('code');
        $eligible = $this->gradebook->eligible($offering, $round)->keyBy(fn ($e) => (string) $e->student->university_number);
        ['headers' => $headers, 'rows' => $rows] = Sheet::read($path);

        $numberCol = array_search('الرقم الجامعي', $headers, true);
        if ($numberCol === false) {
            throw new WorkflowException('لم يُعثر على عمود «الرقم الجامعي». استخدم القالب المنزّل من صفحة المادة.');
        }
        $cols = [];
        foreach ($headers as $i => $h) {
            if (preg_match('/\[([a-z0-9_]+)\]/i', $h, $m) && $components->has($m[1])) {
                $cols[$i] = $components->get($m[1]);
            }
        }
        if (! $cols) {
            throw new WorkflowException('لا توجد أعمدة درجات مطابقة لمكونات هذا الدور. نزّل القالب من جديد.');
        }

        $problems = [];
        $payload = [];
        $seen = [];
        foreach ($rows as $n => $row) {
            $line = $n + 2;
            $number = Sheet::text($row[$numberCol] ?? null);
            if ($number === null) {
                continue;
            }
            $enrollment = $eligible->get($number);
            if (! $enrollment) {
                $problems[] = "السطر {$line}: الرقم {$number} غير مسجل في هذه الشعبة.";

                continue;
            }
            if (isset($seen[$number])) {
                $problems[] = "السطر {$line}: الرقم {$number} مكرر في الملف.";

                continue;
            }
            $seen[$number] = true;
            foreach ($cols as $i => $c) {
                $v = Sheet::text($row[$i] ?? null);
                if ($v === null) {
                    continue; // an empty cell leaves the stored mark as it is
                }
                if (in_array($v, ['غ', 'غائب', 'abs', 'ABS', 'A'], true)) {
                    $payload[$enrollment->id][$c->id] = ['mark' => null, 'absent' => true];
                } elseif (! is_numeric($v) || (float) $v < 0 || (float) $v > (float) $c->max_mark) {
                    $problems[] = "السطر {$line} ({$enrollment->student->name_ar}): «{$v}» في {$c->name_ar} ليست درجة بين 0 و".(float) $c->max_mark.'.';
                } else {
                    $payload[$enrollment->id][$c->id] = ['mark' => round((float) $v, 2), 'absent' => false];
                }
            }
        }
        if ($problems) {
            throw (new WorkflowException('لم يُحفظ شيء: صحّح الأخطاء في الملف ثم ارفعه مجدداً ('.count($problems).').'))->withProblems($problems);
        }

        return ['saved' => $this->gradebook->saveMarks($offering, $user, $payload), 'rows' => count($payload)];
    }
}
