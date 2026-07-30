<?php

namespace App\Support\Imports;

use Illuminate\Validation\ValidationException;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class SpreadsheetReader
{
    /** @return array{headers: list<string>, rows: list<array<string, string>>, formula_cells: list<string>} */
    public function read(string $path, string $extension): array
    {
        $matrix = $extension === 'csv'
            ? $this->csv($path)
            : $this->xlsx($path);

        abort_if(count($matrix) < 2, 422, 'El archivo debe incluir encabezados y al menos una fila.');
        $headers = array_map(fn (mixed $value): string => trim((string) $value), array_shift($matrix));
        if (in_array('', $headers, true) || count(array_unique($headers)) !== count($headers)) {
            throw ValidationException::withMessages([
                'file' => ['Los encabezados no pueden estar vacíos ni repetidos.'],
            ]);
        }
        if (count($matrix) > (int) config('imports.max_rows')) {
            throw ValidationException::withMessages([
                'file' => ['El archivo supera el límite de '.config('imports.max_rows').' filas.'],
            ]);
        }

        $formulaCells = [];
        $rows = [];
        foreach ($matrix as $offset => $values) {
            $rowNumber = $offset + 2;
            $values = array_pad(array_slice($values, 0, count($headers)), count($headers), '');
            $row = [];
            foreach ($headers as $index => $header) {
                $value = trim((string) $values[$index]);
                if ($this->dangerousFormula($value)) {
                    $formulaCells[] = "{$header}:{$rowNumber}";
                }
                $row[$header] = $value;
            }
            if (array_filter($row, fn (string $value): bool => $value !== '') !== []) {
                $rows[] = $row;
            }
        }

        return ['headers' => $headers, 'rows' => $rows, 'formula_cells' => $formulaCells];
    }

    /** @return list<list<string>> */
    private function csv(string $path): array
    {
        $handle = fopen($path, 'rb');
        abort_if($handle === false, 422, 'No se pudo leer el archivo CSV.');
        $sample = fgets($handle);
        abort_if($sample === false, 422, 'El archivo CSV está vacío.');
        $delimiter = substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($rows === [] && isset($row[0])) {
                $row[0] = ltrim((string) $row[0], "\xEF\xBB\xBF");
            }
            $rows[] = array_map(fn (mixed $value): string => (string) $value, $row);
            if (count($rows) > (int) config('imports.max_rows') + 2) {
                break;
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<list<string>> */
    private function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        abort_unless($zip->open($path) === true, 422, 'El XLSX no es un contenedor válido.');
        try {
            $sheetName = 'xl/worksheets/sheet1.xml';
            $sheetStat = $zip->statName($sheetName);
            abort_if($sheetStat === false || ($sheetStat['size'] ?? 0) > 20 * 1024 * 1024, 422, 'La hoja XLSX es inválida o demasiado grande.');
            $sheetXml = $zip->getFromName($sheetName);
            abort_if($sheetXml === false, 422, 'El XLSX no contiene una primera hoja legible.');
            $shared = $this->sharedStrings($zip);
        } finally {
            $zip->close();
        }

        $xml = simplexml_load_string($sheetXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        abort_if($xml === false, 422, 'La hoja XLSX contiene XML inválido.');
        $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];
        foreach ($xml->xpath('//x:sheetData/x:row') ?: [] as $rowNode) {
            $rowNode->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $row = [];
            foreach ($rowNode->xpath('./x:c') ?: [] as $cell) {
                $cell->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                if (($cell->xpath('./x:f') ?: []) !== []) {
                    $reference = (string) ($cell['r'] ?? '?');
                    $row[$this->columnIndex($reference)] = "={$reference}";

                    continue;
                }
                $reference = (string) ($cell['r'] ?? '');
                $index = $this->columnIndex($reference);
                $type = (string) ($cell['t'] ?? '');
                if ($type === 'inlineStr') {
                    $texts = $cell->xpath('./x:is//x:t') ?: [];
                    $value = implode('', array_map(fn ($node): string => (string) $node, $texts));
                } else {
                    $value = (string) (($cell->xpath('./x:v') ?: [])[0] ?? '');
                    if ($type === 's') {
                        $value = $shared[(int) $value] ?? '';
                    }
                }
                $row[$index] = $value;
            }
            if ($row !== []) {
                $last = max(array_keys($row));
                $rows[] = array_map(fn (int $index): string => $row[$index] ?? '', range(0, $last));
            }
            if (count($rows) > (int) config('imports.max_rows') + 2) {
                break;
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $contents = $zip->getFromName('xl/sharedStrings.xml');
        if ($contents === false) {
            return [];
        }
        abort_if(strlen($contents) > 10 * 1024 * 1024, 422, 'La tabla de textos XLSX es demasiado grande.');
        $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        abort_if($xml === false, 422, 'La tabla de textos XLSX es inválida.');
        $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return array_map(function ($item): string {
            $item->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $texts = $item->xpath('.//x:t') ?: [];

            return implode('', array_map(fn ($node): string => (string) $node, $texts));
        }, $xml->xpath('//x:si') ?: []);
    }

    private function columnIndex(string $reference): int
    {
        if (! preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            throw new RuntimeException('Invalid XLSX cell reference.');
        }
        $number = 0;
        foreach (str_split(strtoupper($matches[1])) as $letter) {
            $number = ($number * 26) + ord($letter) - 64;
        }

        return $number - 1;
    }

    private function dangerousFormula(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (in_array($value[0], ['=', '+', '@'], true)) {
            return true;
        }

        return $value[0] === '-' && ! preg_match('/^-\d+(?:[.,]\d+)?$/', $value);
    }
}
