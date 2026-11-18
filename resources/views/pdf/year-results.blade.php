@extends('pdf.layout')
@section('content')
<h1>نتائج نهاية العام الدراسي <span class="ltr">{{ $year->name }}</span></h1>
<div class="sub">{{ $program->name_ar }} — المرحلة {{ $stage }}</div>
<table class="grid">
    <thead><tr>
        <th style="width: 4%;">ت</th><th style="width: 12%;">الرقم الجامعي</th><th>اسم الطالب</th><th style="width: 7%;">المواد</th>
        <th style="width: 8%;">المعدل</th><th style="width: 24%;">المواد غير المجتازة</th><th style="width: 15%;">القرار</th>
    </tr></thead>
    <tbody>
    @foreach($rows as $i => $r)
        <tr class="{{ $i % 2 ? 'alt' : '' }}">
            <td class="c">{{ $i + 1 }}</td><td class="c ltr">{{ $r['number'] }}</td><td>{{ $r['name'] }}</td><td class="c">{{ $r['courses'] }}</td>
            <td class="c">{{ $r['gpa'] ?? '—' }}</td>
            <td style="font-size: 8pt;">{{ collect($r['failed'])->pluck('name')->join('، ') ?: '—' }}</td>
            <td class="c {{ in_array($r['decision'], ['repeat']) ? 'fail' : '' }}">{{ $r['decision'] ? $decisions[$r['decision']] : ($r['problem'] ? 'معلّق' : '—') }}@if(($r['saved']['status'] ?? null) === 'applied') ✓@endif</td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="note">
    @foreach($summary as $k => $n){{ $decisions[$k] ?? 'معلّق' }}: {{ $n }} &nbsp;·&nbsp; @endforeach
    ✓ = طُبّق بأمر إداري
</div>
<table class="sign"><tr><td>مقرر اللجنة الامتحانية</td><td>رئيس اللجنة الامتحانية</td><td>العميد</td></tr></table>
@endsection
