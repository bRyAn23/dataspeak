<?php

namespace App\Services\Uploads;

use App\Exceptions\ApiException;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Converts an Excel workbook to the CSV the rest of the pipeline expects (FEAT-UPL-001 extension).
 * Streams row by row (OpenSpout). Workbooks often start with a notes or cover sheet, so the worksheet with the
 * largest table (data rows x header columns, first wins ties) is used, and written as UTF-8 CSV with a header. Dates become ISO strings, booleans "true"/"false", blank rows are dropped, ragged rows are padded or
 * trimmed to the header width. No cell value is ever logged; only counts leave this class.
 */
class SpreadsheetConverter
{
    public static function isSpreadsheet(UploadedFile $file): bool
    {
        return in_array(strtolower($file->getClientOriginalExtension()), (array) config('dataspeak.upload.spreadsheet_extensions', ['xlsx']), true);
    }

    /**
     * @return array{format: string, sheet: string, sheets_total: int, row_count: int, column_count: int}
     */
    public function toCsv(string $xlsxPath, string $csvPath): array
    {
        $reader = new Reader;
        $out = null;
        try {
            // Pass 1: measure every sheet (counts only) and pick the largest table.
            $reader->open($xlsxPath);
            $sheetsTotal = 0;
            $best = null;
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetsTotal++;
                $measured = $this->writeSheet($sheet->getRowIterator(), null);
                if ($measured === null) {
                    continue;
                }
                $size = $measured['row_count'] * $measured['column_count'];
                if ($best === null || $size > $best['size']) {
                    $best = ['index' => $sheet->getIndex(), 'name' => $sheet->getName(), 'size' => $size];
                }
            }
            $reader->close();

            $chosen = null;
            if ($best !== null) {
                // Pass 2: write the chosen sheet.
                $reader->open($xlsxPath);
                foreach ($reader->getSheetIterator() as $sheet) {
                    if ($sheet->getIndex() !== $best['index']) {
                        continue;
                    }
                    $out = fopen($csvPath, 'wb');
                    if ($out === false) {
                        throw new \RuntimeException('cannot open csv for writing');
                    }
                    $written = $this->writeSheet($sheet->getRowIterator(), $out);
                    fclose($out);
                    $out = null;
                    $chosen = ['sheet' => $best['name']] + ($written ?? ['row_count' => 0, 'column_count' => 0]);
                    break;
                }
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($csvPath);
            throw ApiException::unprocessable('SPREADSHEET_UNREADABLE', 'The Excel file could not be read. Save it again as .xlsx (or export it as CSV) and upload once more.');
        } finally {
            $reader->close();
        }

        if ($chosen === null) {
            throw ApiException::unprocessable('SPREADSHEET_EMPTY', 'The workbook has no worksheet with a header row followed by data.');
        }

        return ['format' => 'xlsx', 'sheets_total' => $sheetsTotal] + $chosen;
    }

    /**
     * @param  iterable<Row>  $rows
     * @param  resource|null  $out  null measures the sheet without writing
     * @return array{row_count: int, column_count: int}|null null when the sheet has no header row
     */
    private function writeSheet(iterable $rows, $out): ?array
    {
        $header = null;
        $count = 0;
        foreach ($rows as $row) {
            $cells = array_map($this->scalar(...), $row->toArray());
            if ($this->blank($cells)) {
                continue;
            }
            if ($header === null) {
                $header = $this->headerNames($cells);
                if ($out !== null) {
                    fputcsv($out, $header, ',', '"', '');
                }

                continue;
            }
            if ($out !== null) {
                $width = count($header);
                fputcsv($out, array_slice(array_pad($cells, $width, ''), 0, $width), ',', '"', '');
            }
            $count++;
        }

        return $header === null || $count === 0 ? null : ['row_count' => $count, 'column_count' => count($header)];
    }

    /** @param  list<string>  $cells */
    private function headerNames(array $cells): array
    {
        while ($cells !== [] && trim((string) end($cells)) === '') {
            array_pop($cells); // trailing empty header cells are not columns
        }
        $seen = [];
        foreach ($cells as $i => $cell) {
            $name = trim($cell) !== '' ? trim($cell) : 'column_'.($i + 1);
            $base = $name;
            $n = 2;
            while (isset($seen[$name])) {
                $name = $base.'_'.$n++;
            }
            $seen[$name] = true;
            $cells[$i] = $name;
        }

        return array_values($cells);
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('H:i:s') === '00:00:00' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.'),
            default => (string) $value,
        };
    }

    /** @param  list<string>  $cells */
    private function blank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
