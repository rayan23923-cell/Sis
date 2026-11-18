<?php

namespace App\Services\Documents;

use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

/** Renders a Blade view to an Arabic (RTL) PDF with mPDF, which shapes Arabic text correctly. */
class Pdf
{
    public function render(string $view, array $data, bool $landscape = false): string
    {
        $tmp = storage_path('framework/cache/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $landscape ? 'A4-L' : 'A4',
            'directionality' => 'rtl',
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_top' => 30, 'margin_bottom' => 18, 'margin_left' => 12, 'margin_right' => 12,
            'margin_header' => 8, 'margin_footer' => 8,
            'tempDir' => $tmp,
        ]);
        $mpdf->SetTitle($data['title'] ?? 'SIS');
        $mpdf->SetCreator(config('app.name'));
        if (! ($data['official'] ?? true)) {
            $mpdf->SetWatermarkText('غير رسمية', 0.08);
            $mpdf->showWatermarkText = true;
            $mpdf->watermark_font = 'dejavusans';
        }
        $mpdf->WriteHTML(view($view, $data)->render());

        return $mpdf->Output('', 'S');
    }

    public function response(string $view, array $data, string $filename, bool $landscape = false): Response
    {
        return response($this->render($view, $data, $landscape), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
