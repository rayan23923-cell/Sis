<?php

namespace App\Services\Imports;

use App\Services\WorkflowException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Small helpers around PhpSpreadsheet: RTL templates out, plain rows in. */
class Sheet
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, int>  $editable  zero-based column indexes the user fills (highlighted)
     */
    public static function download(string $filename, string $title, array $headers, array $rows, array $editable = [], array $notes = []): StreamedResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));
        $sheet->setRightToLeft(true);
        $sheet->fromArray($headers, null, 'A1');
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2', true);
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        foreach ($editable as $i) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getStyle($col.'1')->getFill()->getStartColor()->setRGB('CCFBF1');
        }
        foreach (range(1, count($headers)) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
        if ($notes) {
            $info = $book->createSheet();
            $info->setTitle('تعليمات');
            $info->setRightToLeft(true);
            foreach (array_values($notes) as $i => $n) {
                $info->setCellValue('A'.($i + 1), $n);
            }
            $info->getColumnDimension('A')->setWidth(110);
            $book->setActiveSheetIndex(0);
        }

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Rows of the first sheet keyed by the header text of the first non-empty row.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public static function read(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (\Throwable) {
            throw new WorkflowException('تعذر قراءة الملف. استخدم القالب بصيغة Excel ‎(.xlsx)‎ أو CSV.');
        }
        $all = $book->getSheet(0)->toArray(null, true, false, false);
        $all = array_values(array_filter($all, fn ($r) => count(array_filter($r, fn ($v) => $v !== null && trim((string) $v) !== '')) > 0));
        if (count($all) < 2) {
            throw new WorkflowException('الملف فارغ أو لا يحتوي إلا العناوين.');
        }
        if (count($all) > 2001) {
            throw new WorkflowException('الحد الأقصى 2000 صف في الملف الواحد.');
        }
        $headers = array_map(fn ($h) => trim((string) $h), array_shift($all));

        return ['headers' => $headers, 'rows' => $all];
    }

    public static function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim(strtr((string) $v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']));
        $s = preg_replace('/\s+/u', ' ', $s);

        return $s === '' ? null : $s;
    }

    public static function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 1000 && (float) $v < 100000) {
            return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        $s = self::text($v);
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('#^(\d{4})[/.-](\d{1,2})[/.-](\d{1,2})$#', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        return $s; // left for the validator to reject
    }
}
