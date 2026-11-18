<html dir="rtl" lang="ar">
<head>
<style>
    body { font-family: dejavusans; font-size: 9.5pt; color: #1e293b; }
    .muted { color: #64748b; }
    h1 { font-size: 14pt; margin: 0 0 2mm; text-align: center; }
    .sub { text-align: center; color: #475569; font-size: 9pt; margin-bottom: 4mm; }
    table.grid { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
    table.grid th { background: #e2e8f0; font-weight: bold; padding: 1.6mm 1.2mm; border: 0.2mm solid #94a3b8; font-size: 8.5pt; }
    table.grid td { padding: 1.4mm 1.2mm; border: 0.2mm solid #cbd5e1; font-size: 8.8pt; }
    table.grid tr.alt td { background: #f8fafc; }
    table.info { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
    table.info td { padding: 1.2mm 1.5mm; font-size: 9pt; }
    table.info td.k { color: #64748b; width: 18%; }
    .c { text-align: center; }
    .ltr { direction: ltr; }
    .fail { color: #b91c1c; font-weight: bold; }
    .section { font-weight: bold; font-size: 10.5pt; background: #f1f5f9; padding: 1.5mm 2mm; margin: 3mm 0 1.5mm; border-right: 1mm solid #0f766e; }
    .note { font-size: 8pt; color: #64748b; margin-top: 2mm; }
    .warn { font-size: 8.5pt; color: #92400e; background: #fef3c7; padding: 1.5mm 2mm; margin-bottom: 3mm; }
    table.sign { width: 100%; margin-top: 12mm; }
    table.sign td { text-align: center; width: 33%; font-size: 9pt; padding-top: 10mm; }
</style>
</head>
<body>
<htmlpageheader name="hdr">
    <table width="100%" style="border-bottom: 0.4mm solid #0f766e; padding-bottom: 2mm;">
        <tr>
            <td style="width: 60%; font-size: 11pt; font-weight: bold;">{{ $university }}<br><span style="font-size: 9pt; font-weight: normal; color: #475569;">{{ $unit ?? '' }}</span></td>
            <td style="width: 40%; text-align: left; font-size: 8.5pt; color: #64748b;">{{ $title }}<br><span class="ltr">{{ $ref ?? '' }}</span></td>
        </tr>
    </table>
</htmlpageheader>
<htmlpagefooter name="ftr">
    <table width="100%" style="font-size: 7.5pt; color: #64748b; border-top: 0.2mm solid #cbd5e1; padding-top: 1mm;">
        <tr>
            <td style="width: 50%;">طُبعت بواسطة {{ $printedBy }} — <span class="ltr">{{ $printedAt }}</span></td>
            <td style="width: 50%; text-align: left;">صفحة {PAGENO} من {nbpg}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpageheader name="hdr" value="on" show-this-page="1" />
<sethtmlpagefooter name="ftr" value="on" />
@unless($official ?? true)
    <div class="warn">هذه الوثيقة محسوبة بسياسات غير رسمية (للتجربة) ولا يُعتدّ بها قبل إدخال الأنظمة الرسمية المعتمدة.</div>
@endunless
@yield('content')
</body>
</html>
