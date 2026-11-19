@extends('pdf.layout')
@section('content')
<h1>الجدول الأسبوعي</h1>
<div class="sub">{{ $heading }} — {{ $term->label() }}</div>
@php
    $byDay = collect($slots)->groupBy('day');
    $times = collect($slots)->map(fn ($s) => $s['starts_at'].'–'.$s['ends_at'])->unique()->sort()->values();
@endphp
@if($times->isEmpty())
    <p class="note">لا توجد مواعيد في الجدول.</p>
@else
<table class="grid" style="font-size: 8pt;">
    <thead><tr><th style="width: 18mm;">اليوم</th>@foreach($times as $t)<th class="ltr">{{ $t }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach($days as $d => $dayName)
        <tr>
            <td class="c" style="font-weight: bold;">{{ $dayName }}</td>
            @foreach($times as $t)
                @php $cell = ($byDay[$d] ?? collect())->filter(fn ($s) => $s['starts_at'].'–'.$s['ends_at'] === $t); @endphp
                <td class="c" style="@if($cell->isNotEmpty()) background: #eef6ff; @endif">
                    @foreach($cell as $s)
                        <b>{{ $s['name'] }}</b>@if($s['kind'] === 'lab') (عملي)@endif<br>
                        <span class="muted">{{ $s['room'] ?? '—' }}@if($s['teacher']) · {{ $s['teacher'] }}@endif @if($s['program'] && str_starts_with($heading, 'جدول')) · {{ $s['program'] }} م{{ $s['stage'] }}/{{ $s['section'] }}@endif</span>
                        @if(! $loop->last)<hr style="margin: 1mm 0;">@endif
                    @endforeach
                </td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
@endif
@endsection
