@extends('pdf.layout')
@section('content')
<div style="margin-top: 6mm;">
    @if($addressed_to)<p style="font-size: 12pt; font-weight: bold;">إلى / {{ $addressed_to }}</p>@endif
    <h1 style="font-size: 18pt; margin-top: 4mm;">م / تأييد استمرار بالدراسة</h1>

    <p style="font-size: 12pt; line-height: 2.1; text-align: justify; margin-top: 6mm;">
        تؤيد {{ $university }} أن الطالب/الطالبة <b>{{ $student->name_ar }}</b>
        @if($student->name_en)(<span class="ltr">{{ $student->name_en }}</span>)@endif
        ذا الرقم الجامعي <b class="ltr">{{ $student->university_number }}</b>
        مستمر/مستمرة بالدراسة في <b>{{ $program?->department->college->name_ar }}</b> — {{ $program?->department->name_ar }}،
        {{ $program?->name_ar }}، المرحلة <b>{{ $stage ?? '—' }}</b>، الدراسة <b>{{ $shift === 'evening' ? 'المسائية' : 'الصباحية' }}</b>،
        للعام الدراسي <b class="ltr">{{ $year ?? '—' }}</b>.
    </p>
    <p style="font-size: 12pt; line-height: 2.1;">زُوّد/زُوّدت بهذا التأييد بناءً على طلبه/طلبها@if($purpose) لغرض {{ $purpose }}@endif.</p>
    <p style="font-size: 12pt; margin-top: 4mm;">مع التقدير.</p>

    <table class="sign" style="margin-top: 14mm;"><tr><td>مسجل الكلية</td><td>معاون العميد للشؤون الإدارية</td></tr></table>
</div>
@include('pdf._verify')
@endsection
