@extends('pdf.layout')
@section('content')
<h1>وصل قبض رسوم دراسية</h1>
<div class="sub">رقم الوصل <span class="ltr"><b>{{ $payment->receipt_number }}</b></span> — العام الدراسي <span class="ltr">{{ $invoice->year->name }}</span></div>
@if($payment->voided_at)
<div class="warn" style="background:#fee2e2;color:#991b1b;">هذا الوصل ملغى في <span class="ltr">{{ $payment->voided_at->timezone('Asia/Baghdad')->format('Y-m-d') }}</span>: {{ $payment->void_reason }}</div>
@endif
<table class="info">
    <tr><td class="k">اسم الطالب</td><td><b>{{ $student->name_ar }}</b></td><td class="k">الرقم الجامعي</td><td class="ltr">{{ $student->university_number }}</td></tr>
    <tr><td class="k">البرنامج</td><td>{{ $student->currentProgram?->program->name_ar }}</td><td class="k">المرحلة</td><td>{{ $invoice->stage }}</td></tr>
</table>
<table class="grid">
    <tr><th>المبلغ المستلم (دينار)</th><th>تاريخ الدفع</th><th>طريقة الدفع</th><th>رقم المعاملة</th></tr>
    <tr>
        <td class="c" style="font-size: 13pt;"><b>{{ number_format($payment->amount) }}</b></td>
        <td class="c ltr">{{ $payment->paid_on->toDateString() }}</td>
        <td class="c">{{ $method }}</td>
        <td class="c ltr">{{ $payment->reference ?? '—' }}</td>
    </tr>
</table>
<div class="section">موقف المطالبة بعد هذه الدفعة</div>
<table class="grid">
    <tr><th>مبلغ الرسوم</th><th>التخفيض</th><th>الصافي</th><th>مجموع المدفوع</th><th>المتبقي</th></tr>
    <tr>
        <td class="c">{{ number_format($invoice->amount) }}</td>
        <td class="c">{{ number_format($invoice->discount) }}</td>
        <td class="c">{{ number_format($invoice->net()) }}</td>
        <td class="c">{{ number_format($invoice->paid()) }}</td>
        <td class="c"><b>{{ number_format($balance) }}</b></td>
    </tr>
</table>
@if($payment->notes)<p class="note">ملاحظات: {{ $payment->notes }}</p>@endif
<table class="sign"><tr><td>المستلم<br>{{ $payment->receiver?->name }}</td><td></td><td>الختم</td></tr></table>
<p class="note">المبالغ بالدينار العراقي. لا يُعتد بالوصل إذا كان ملغى.</p>
@endsection
