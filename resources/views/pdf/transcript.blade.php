@extends('pdf.layout')
@section('content')
<h1>كشف الدرجات</h1>
<div class="sub">Academic Transcript</div>
<table class="info">
    <tr><td class="k">اسم الطالب</td><td><b>{{ $student->name_ar }}</b></td><td class="k">الرقم الجامعي</td><td class="ltr">{{ $student->university_number }}</td></tr>
    <tr><td class="k">Name</td><td class="ltr">{{ $student->name_en ?: '—' }}</td><td class="k">الحالة</td><td>{{ $status }}</td></tr>
    <tr><td class="k">الكلية</td><td>{{ $program?->department->college->name_ar }}</td><td class="k">القسم</td><td>{{ $program?->department->name_ar }}</td></tr>
    <tr><td class="k">البرنامج</td><td>{{ $program?->name_ar }}</td><td class="k">المرحلة الحالية</td><td>{{ $stage }}</td></tr>
    <tr><td class="k">سنة القبول</td><td class="ltr">{{ $student->admissionYear?->name }}</td><td class="k">نظام الدراسة</td><td>{{ $system }}</td></tr>
</table>

@forelse($years as $y)
    <div class="section">العام الدراسي <span class="ltr">{{ $y['name'] }}</span>@if($y['decision']) — {{ $y['decision'] }}@endif</div>
    <table class="grid">
        <thead><tr>
            <th style="width: 11%;">الرمز</th><th>المادة</th><th style="width: 12%;">الفصل</th><th style="width: 7%;">الوحدات</th>
            <th style="width: 8%;">الدرجة</th><th style="width: 14%;">التقدير</th><th style="width: 9%;">الدور</th><th style="width: 12%;">النتيجة</th>
        </tr></thead>
        <tbody>
        @foreach($y['rows'] as $i => $r)
            <tr class="{{ $i % 2 ? 'alt' : '' }}">
                <td class="c ltr">{{ $r['code'] }}</td><td>{{ $r['name'] }}</td><td class="c">{{ $r['term'] }}</td><td class="c">{{ $r['credits'] }}</td>
                <td class="c">{{ $r['mark'] ?? '—' }}</td><td class="c">{{ $r['grade'] ?? '—' }}</td><td class="c">{{ $r['round'] }}</td>
                <td class="c {{ $r['failed'] ? 'fail' : '' }}">{{ $r['outcome'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="note">
        المعدل السنوي: <b>{{ $y['gpa'] ?? '—' }}</b> &nbsp;·&nbsp; الوحدات المكتسبة: <b>{{ $y['credits'] }}</b>
        @if($y['pending']) &nbsp;·&nbsp; نتائج لم تُقفل بعد: {{ $y['pending'] }} @endif
    </div>
@empty
    <p class="muted c">لا توجد نتائج مسجلة لهذا الطالب.</p>
@endforelse

<p class="note">لا تظهر في هذا الكشف إلا النتائج المقفلة من اللجنة الامتحانية. أي تصحيح لاحق يصدر بأمر إداري ويظهر بنسخته الأحدث.</p>
<table class="sign"><tr><td>المسجل</td><td>رئيس القسم</td><td>العميد</td></tr></table>
@endsection
