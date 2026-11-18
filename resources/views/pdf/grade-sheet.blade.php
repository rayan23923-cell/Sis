@extends('pdf.layout')
@section('content')
<h1>قائمة درجات {{ $round === 1 ? 'الدور الأول' : 'الدور الثاني' }}</h1>
<table class="info">
    <tr><td class="k">المادة</td><td><b>{{ $o->course->name_ar }}</b> <span class="ltr">({{ $o->course->code }})</span></td><td class="k">الفصل</td><td>{{ $o->term->label() }}</td></tr>
    <tr><td class="k">القسم</td><td>{{ $o->course->department->name_ar }}</td><td class="k">المرحلة / الشعبة</td><td>{{ $o->stage }} / {{ $o->section }}</td></tr>
    <tr><td class="k">التدريسي</td><td>{{ $o->teacher?->name ?? '—' }}</td><td class="k">حالة النتائج</td><td>{{ $statusLabel }}</td></tr>
</table>
<table class="grid">
    <thead><tr>
        <th style="width: 4%;">ت</th><th style="width: 12%;">الرقم الجامعي</th><th>اسم الطالب</th>
        @foreach($components as $c)<th style="width: 8%;">{{ $c->name_ar }}<br><span class="muted">({{ (float) $c->max_mark }})</span></th>@endforeach
        <th style="width: 7%;">المجموع</th><th style="width: 7%;">بعد التقريب</th><th style="width: 11%;">التقدير</th><th style="width: 10%;">النتيجة</th>
    </tr></thead>
    <tbody>
    @foreach($rows as $i => $r)
        <tr class="{{ $i % 2 ? 'alt' : '' }}">
            <td class="c">{{ $i + 1 }}</td><td class="c ltr">{{ $r['number'] }}</td><td>{{ $r['name'] }}@if($r['carried']) <span class="muted">(محمّل)</span>@endif</td>
            @foreach($components as $c)<td class="c">{{ $r['marks'][$c->id] }}</td>@endforeach
            <td class="c">{{ $r['total'] ?? '—' }}</td><td class="c"><b>{{ $r['rounded'] ?? '—' }}</b></td><td class="c">{{ $r['grade'] ?? '—' }}</td>
            <td class="c {{ $r['failed'] ? 'fail' : '' }}">{{ $r['outcome'] ?? '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="note">
    عدد الطلبة: {{ count($rows) }} &nbsp;·&nbsp; الناجحون: {{ $summary['pass'] }} &nbsp;·&nbsp; الراسبون: {{ $summary['fail'] }} &nbsp;·&nbsp; الدور الثاني: {{ $summary['resit'] }} &nbsp;·&nbsp; بلا نتيجة: {{ $summary['none'] }}
</div>
<table class="sign"><tr><td>تدريسي المادة<br>{{ $o->teacher?->name }}</td><td>رئيس اللجنة الامتحانية</td><td>العميد</td></tr></table>
@endsection
