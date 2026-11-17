<?php

namespace App\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Unduhan CSV/XLSX dari dataset {headers, rows} (ekspor laporan admin dan laporan trainer). */
final class SpreadsheetDownload
{
    public const FORMATS = ['csv', 'xlsx'];

    /** @param  array{headers: string[], rows: array<int, array<int, mixed>>}  $data */
    public static function make(array $data, string $file, string $format): StreamedResponse
    {
        return response()->streamDownload(function () use ($data, $format): void {
            $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues($data['headers']));
            foreach ($data['rows'] as $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($v) => $v ?? '', $row)));
            }
            $writer->close();
        }, $file, ['Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8']);
    }
}
