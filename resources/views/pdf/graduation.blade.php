@extends('pdf.layout')
@section('content')
<div style="border: 0.6mm double #0f3d5e; padding: 8mm 10mm; margin-top: 4mm;">
    <h1 style="font-size: 20pt; margin-bottom: 1mm;">وثيقة تخرج</h1>
    <div class="sub" style="font-size: 10pt;">Graduation Document</div>

    <p style="font-size: 11.5pt; line-height: 2; text-align: justify; margin-top: 6mm;">
        تشهد {{ $university }} بأن الطالب/الطالبة <b>{{ $student->name_ar }}</b>
        @if($student->name_en)(<span class="ltr">{{ $student->name_en }}</span>)@endif
        ذا الرقم الجامعي <b class="ltr">{{ $student->university_number }}</b>
        قد أكمل/أكملت متطلبات الدراسة في <b>{{ $program?->name_ar }}</b>
        — {{ $program?->department->name_ar }}، {{ $program?->department->college->name_ar }}،
        وتخرج/تخرجت بتاريخ <b class="ltr">{{ $graduated_on ?? '—' }}</b>@if($graduation_order) بموجب الأمر الإداري {{ $graduation_order }}@endif.
    </p>

    @if(count($years))
    <table class="grid" style="margin-top: 5mm;">
        <thead><tr><th>العام الدراسي</th><th>عدد المواد</th><th>الوحدات المكتسبة</th><th>المعدل السنوي</th><th>القرار</th></tr></thead>
        <tbody>
        @foreach($years as $i => $y)
            <tr class="{{ $i % 2 ? 'alt' : '' }}">
                <td class="c ltr">{{ $y['name'] }}</td><td class="c">{{ count($y['rows']) }}</td><td class="c">{{ $y['credits'] }}</td>
                <td class="c">{{ $y['gpa'] ?? '—' }}</td><td class="c">{{ $y['decision'] ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
    <p class="note">المعدل التراكمي للتخرج يُثبَّت حسب تعليمات الجامعة المعتمدة، وتفاصيل المواد في كشف الدرجات.</p>

    <table class="sign"><tr><td>مسجل الكلية</td><td>العميد</td><td>رئيس الجامعة</td></tr></table>
</div>
@include('pdf._verify')
@endsection
