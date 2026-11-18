@extends('pdf.layout')
@section('content')
<div class="warn">وثيقة سرية: سلّم كل قسيمة لصاحبها فقط. كلمة المرور مؤقتة ويُطلب تغييرها عند أول دخول، ولا يمكن استرجاعها من النظام لاحقاً.</div>
@foreach(array_chunk($slips, 2) as $pair)
<table width="100%" style="margin-bottom: 4mm;"><tr>
    @foreach($pair as $s)
    <td style="width: 50%; padding: 2mm;">
        <div style="border: 0.3mm dashed #64748b; padding: 3mm 4mm;">
            <div style="font-weight: bold; font-size: 10.5pt;">{{ $s['name'] }}</div>
            <div style="font-size: 8.5pt; color: #64748b; margin-bottom: 2mm;">بوابة الطالب — {{ $program->name_ar }} / المرحلة {{ $stage }}</div>
            <table style="font-size: 9.5pt;">
                <tr><td style="color: #64748b; width: 30mm;">الرابط</td><td class="ltr">{{ $url }}</td></tr>
                <tr><td style="color: #64748b;">اسم الدخول (الرقم الجامعي)</td><td class="ltr"><b>{{ $s['number'] }}</b></td></tr>
                <tr><td style="color: #64748b;">كلمة المرور المؤقتة</td><td class="ltr" style="font-family: dejavusansmono; font-size: 11pt;"><b>{{ $s['password'] }}</b></td></tr>
            </table>
        </div>
    </td>
    @endforeach
    @if(count($pair) === 1)<td style="width: 50%;"></td>@endif
</tr></table>
@endforeach
@endsection
