{{-- QR verification block shared by issued documents --}}
<table width="100%" style="margin-top: 6mm; border-top: 0.2mm solid #cbd5e1; padding-top: 3mm;">
    <tr>
        <td style="width: 28mm; vertical-align: top;"><barcode code="{{ $doc->verifyUrl() }}" type="QR" size="0.9" error="M" disableborder="1" /></td>
        <td style="vertical-align: middle; font-size: 8.5pt; color: #475569;">
            للتحقق من صحة هذه الوثيقة امسح الرمز، أو افتح <span class="ltr">{{ route('verify.form') }}</span> وأدخل الرمز:
            <div class="ltr" style="font-family: dejavusansmono; font-size: 13pt; font-weight: bold; color: #0f3d5e; margin-top: 1mm;">{{ $doc->code }}</div>
            <div style="font-size: 7.5pt; margin-top: 1mm;">الوثيقة صادرة إلكترونياً بتاريخ <span class="ltr">{{ $doc->created_at->timezone('Asia/Baghdad')->format('Y-m-d') }}</span>، وتُعدّ لاغية إذا لم يُطابق محتواها ما يظهر في صفحة التحقق.</div>
        </td>
    </tr>
</table>
