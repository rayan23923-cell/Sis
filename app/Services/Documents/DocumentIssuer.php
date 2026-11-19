<?php

namespace App\Services\Documents;

use App\Models\AcademicYear;
use App\Models\IssuedDocument;
use App\Models\Student;
use App\Models\User;
use App\Services\WorkflowException;

/**
 * Issues a verifiable document (QR) for a student and renders its PDF.
 * Used by direct printing and by the students' document requests.
 */
class DocumentIssuer
{
    public function __construct(private readonly TranscriptService $transcripts, private readonly Pdf $pdf) {}

    /**
     * @param  array{addressed_to?: ?string, purpose?: ?string, lang?: string}  $extra
     * @return array{doc: IssuedDocument, pdf: string, filename: string}
     */
    public function issue(string $type, Student $student, User $by, array $extra = []): array
    {
        $data = $this->transcripts->build($student);
        $view = match ($type) {
            'transcript' => 'pdf.transcript',
            'graduation' => 'pdf.graduation',
            'enrollment' => 'pdf.enrollment',
            default => throw new WorkflowException('نوع وثيقة غير معروف.'),
        };
        if ($type === 'graduation') {
            if ($student->status !== 'graduated') {
                throw new WorkflowException('وثيقة التخرج تصدر للطالب المتخرج فقط.');
            }
            $change = $student->statusChanges()->where('to_status', 'graduated')->latest('effective_on')->with('order')->first();
            $data['graduated_on'] = $change?->effective_on?->toDateString();
            $data['graduation_order'] = $change?->order?->reference();
        }
        if ($type === 'enrollment') {
            if ($student->status !== 'active') {
                throw new WorkflowException('تأييد الاستمرار بالدراسة يصدر للطالب المستمر فقط.');
            }
            $data['year'] = AcademicYear::where('is_current', true)->value('name');
            $data['shift'] = $student->study_shift ?? 'morning';
        }
        $data['addressed_to'] = $extra['addressed_to'] ?? null;
        $data['purpose'] = $extra['purpose'] ?? null;

        $doc = IssuedDocument::create([
            'type' => $type, 'student_id' => $student->id, 'official' => $data['official'], 'issued_by' => $by->id,
            'snapshot' => [
                'name' => $student->name_ar, 'name_en' => $student->name_en, 'number' => $student->university_number,
                'college' => $data['program']?->department->college->name_ar, 'department' => $data['program']?->department->name_ar,
                'program' => $data['program']?->name_ar, 'status' => $data['status'], 'stage' => $data['stage'],
                'years' => collect($data['years'])->map(fn ($y) => ['year' => $y['name'], 'gpa' => $y['gpa'], 'credits' => $y['credits'], 'decision' => $y['decision'], 'courses' => count($y['rows'])])->all(),
                'graduated_on' => $data['graduated_on'] ?? null,
                'year' => $data['year'] ?? null, 'addressed_to' => $data['addressed_to'],
            ],
        ]);
        $title = IssuedDocument::TYPES[$type];
        $pdf = $this->pdf->render($view, $data + ['doc' => $doc] + [
            'title' => $title, 'unit' => $data['program']?->department->college->name_ar, 'ref' => $doc->code,
            'university' => config('sis.university_name'), 'printedBy' => $by->name, 'printedAt' => now()->timezone('Asia/Baghdad')->format('Y-m-d H:i'),
        ]);

        return ['doc' => $doc, 'pdf' => $pdf, 'filename' => $type.'-'.$student->university_number.'.pdf'];
    }
}
