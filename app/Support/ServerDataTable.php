<?php

namespace App\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use JsonSerializable;
use Stringable;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use UnexpectedValueException;
use ZipArchive;

final class ServerDataTable
{
    /**
     * @param  list<string|callable(Builder, string, string): void>  $searchable
     * @param  array<string, string>  $sortable
     * @param  array<string, string|callable(Builder, mixed): void>  $filterable
     * @param  array<string, string|callable(object): mixed>  $exportColumns
     */
    public function respond(
        Builder $query,
        Request $request,
        array $searchable,
        array $sortable,
        array $filterable,
        array $exportColumns,
        string $exportName,
        string $defaultSort = 'id',
        string $defaultDirection = 'desc',
    ): HttpResponse {
        $filters = $this->filters($request);
        foreach (array_keys($filterable) as $key) {
            if (! array_key_exists($key, $filters) && $request->query->has($key)) {
                $filters[$key] = $request->query($key);
            }
        }
        $validated = validator([
            ...$request->only(['page', 'per_page', 'search', 'sort', 'direction', 'export']),
            'filters' => $filters,
        ], [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'sort' => ['sometimes', 'string', Rule::in(array_keys($sortable))],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'filters' => ['array'],
            'filters.*' => ['nullable'],
            'export' => ['sometimes', Rule::in(['xlsx'])],
        ])->validate();

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '' && $searchable !== []) {
            $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function (Builder $nested) use ($searchable, $search, $operator): void {
                foreach ($searchable as $definition) {
                    if (! is_string($definition) && is_callable($definition)) {
                        $definition($nested, $search, $operator);
                    } else {
                        $nested->orWhere($definition, $operator, "%{$search}%");
                    }
                }
            });
        }

        foreach ($filters as $name => $value) {
            if ($value === null || $value === '' || ! array_key_exists($name, $filterable)) {
                continue;
            }
            $definition = $filterable[$name];
            if (! is_string($definition) && is_callable($definition)) {
                $definition($query, $value);
            } else {
                $query->where($definition, $value);
            }
        }

        $sort = (string) ($validated['sort'] ?? $defaultSort);
        $direction = (string) ($validated['direction'] ?? $defaultDirection);
        $query->orderBy($sortable[$sort] ?? $sortable[$defaultSort], $direction);

        if (($validated['export'] ?? null) === 'xlsx') {
            return $this->excel($query, $exportColumns, $exportName);
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'from' => $page->firstItem(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'to' => $page->lastItem(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** @param  array<string, string|callable(object): mixed>  $columns */
    private function excel(Builder $query, array $columns, string $name): HttpResponse
    {
        $worksheetPath = tempnam(sys_get_temp_dir(), 'lvb-sheet-');
        $workbookPath = tempnam(sys_get_temp_dir(), 'lvb-xlsx-');
        abort_if($worksheetPath === false || $workbookPath === false, 500, 'No se pudo preparar la exportación.');
        $worksheet = fopen($worksheetPath, 'wb');
        abort_if($worksheet === false, 500, 'No se pudo preparar la exportación.');

        fwrite($worksheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        fwrite($worksheet, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        $this->xlsxRow($worksheet, array_keys($columns), 1);
        $rowNumber = 2;
        foreach ($query->lazy(500) as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = ! is_string($column) && is_callable($column)
                    ? $column($row)
                    : data_get($row, $column);
            }
            $this->xlsxRow($worksheet, $values, $rowNumber++);
        }
        fwrite($worksheet, '</sheetData></worksheet>');
        fclose($worksheet);

        $zip = new ZipArchive;
        abort_unless($zip->open($workbookPath, ZipArchive::OVERWRITE) === true, 500, 'No se pudo generar la exportación.');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Datos" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');
        $zip->addFile($worksheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($worksheetPath);

        return response()->download($workbookPath, "{$name}-".now()->format('Y-m-d-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ])->deleteFileAfterSend(true);
    }

    /** @param resource $handle */
    private function xlsxRow($handle, array $values, int $row): void
    {
        fwrite($handle, "<row r=\"{$row}\">");
        foreach ($values as $index => $value) {
            $column = $this->columnName($index + 1);
            $escaped = htmlspecialchars($this->normalizeCell($value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            fwrite($handle, "<c r=\"{$column}{$row}\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>");
        }
        fwrite($handle, '</row>');
    }

    private function normalizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }
        if (is_array($value) || $value instanceof JsonSerializable) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        throw new UnexpectedValueException('Unsupported spreadsheet cell value: '.get_debug_type($value));
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $filters = $request->query('filters', []);
        if (is_string($filters)) {
            $decoded = json_decode($filters, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($filters) ? $filters : [];
    }
}
