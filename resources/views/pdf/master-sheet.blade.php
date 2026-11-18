@extends('pdf.layout')
@section('content')
<h1>الماستر شيت — {{ $round === 1 ? 'نتائج الدور الأول' : 'النتائج النهائية بعد الدور الثاني' }}</h1>
<div class="sub">{{ $program->name_ar }} — المرحلة {{ $stage }} — العام الدراسي <span class="ltr">{{ $year->name }}</span></div>
@if($sheet['policy'])
<p class="note">القرار: {{ $sheet['policy']['amount1'] }} درجات للدور الأول{{ $sheet['policy']['amount2'] ? ' و'.$sheet['policy']['amount2'].' إضافية للدور الثاني' : '' }}. خصم الدور الثاني ومواد التحميل: {{ $sheet['policy']['deduction'] }} درجات (لا تقل عن 50). العبور: {{ $sheet['policy']['max_carried'] ? 'حتى '.$sheet['policy']['max_carried'].' مادة' : 'لا يوجد' }}.</p>
@endif
<table class="grid" style="font-size: 6.8pt;">
    <thead>
    <tr>
        <th rowspan="2" style="width: 6mm;">ت</th><th rowspan="2" style="width: 30mm;">اسم الطالب</th>
        @php $span = $round === 1 ? 2 : 3; @endphp
        @foreach($sheet['courses'] as $c)<th colspan="{{ $span }}">{{ $c['name'] }}<br><span style="font-weight: normal;">{{ $c['units'] }} وحدة</span></th>@endforeach
        <th rowspan="2" style="width: 20mm;">التحميل</th><th rowspan="2" style="width: 11mm;">المعدل</th><th rowspan="2" style="width: 26mm;">النتيجة</th>
    </tr>
    <tr>@foreach($sheet['courses'] as $c)<th style="width: 7mm;">د1</th>@if($round !== 1)<th style="width: 7mm;">د2</th>@endif<th style="width: 11mm;">الاستحقاق</th>@endforeach</tr>
    </thead>
    @foreach($sheet['students'] as $i => $s)
    <tr @if($i % 2) class="alt" @endif>
        <td class="c">{{ $i + 1 }}</td>
        <td>{{ $s['name'] }}<br><span class="muted ltr">{{ $s['number'] }}</span></td>
        @foreach($sheet['courses'] as $c)
            @php $x = $s['cells'][$c['code']] ?? null; @endphp
            @if($x)
                @php
                    $bg = $x['entitlement'] === null ? '' : (! ($x['passed'] ?? false) ? '#fee2e2' : ($x['award'] ? '#d1fae5' : (($x['deducted'] ?? false) ? '#e0f2fe' : '')));
                @endphp
                <td class="c">{{ $x['r1'] ?? '—' }}</td>
                @if($round !== 1)<td class="c">{{ $x['r2'] ?? '' }}</td>@endif
                <td class="c" style="font-weight: bold; @if($bg) background: {{ $bg }}; @endif">{{ $x['entitlement'] ?? '—' }}@if($x['grade'])<br><span style="font-weight: normal; font-size: 6pt;">{{ $x['grade'] }}</span>@endif</td>
            @else
                <td colspan="{{ $span }}" class="c muted">—</td>
            @endif
        @endforeach
        <td style="font-size: 6pt;">@foreach($s['cells'] as $x)@if($x && $x['carried']){{ $x['name'] }}: {{ $x['entitlement'] ?? '—' }}<br>@endif @endforeach</td>
        <td class="c" style="font-weight: bold;">{{ $s['gpa'] ?? '' }}</td>
        <td>{{ $s['result'] }}@if($s['failed'])<br><span class="fail" style="font-size: 6pt;">{{ collect($s['failed'])->pluck('name')->implode('، ') }}</span>@endif</td>
    </tr>
    @endforeach
</table>
<p class="note">الألوان: الأخضر نجاح بقرار، الأحمر رسوب، الأزرق خصم الدور الثاني أو التحميل. عدد الطلبة {{ $sheet['stats']['students'] }}، الناجحون {{ $sheet['stats']['passed'] }}، نسبة النجاح {{ $sheet['stats']['rate'] ?? '—' }}%.</p>
<table class="sign"><tr><td>عضو اللجنة الامتحانية</td><td>رئيس اللجنة الامتحانية</td><td>العميد</td></tr></table>
@endsection
