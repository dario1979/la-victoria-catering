<?php

namespace App\Support\Imports;

use App\Models\InventoryLot;
use App\Models\Location;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ImportValidator
{
    public function __construct(
        private readonly ImportDefinition $definitions,
        private readonly SpreadsheetReader $reader,
    ) {}

    /**
     * @param  array<string, string>  $mapping
     * @return array{rows: list<array<string, string>>, preview: list<array<string, string>>, errors: list<array{row: int, field: string, message: string}>}
     */
    public function validate(
        string $path,
        string $extension,
        string $type,
        array $mapping,
        int $organizationId,
        int $branchId,
    ): array {
        $definition = $this->definitions->for($type);
        $sheet = $this->reader->read($path, $extension);
        $this->validateMapping($definition, $mapping, $sheet['headers']);
        $formulaLookup = array_fill_keys($sheet['formula_cells'], true);
        $errors = [];
        $rows = [];
        $seen = [];
        $recipeGroups = [];

        foreach ($sheet['rows'] as $offset => $source) {
            $rowNumber = $offset + 2;
            $row = [];
            foreach ($definition['fields'] as $field) {
                $header = $mapping[$field] ?? null;
                $row[$field] = $header === null ? '' : trim((string) ($source[$header] ?? ''));
                if ($header !== null && isset($formulaLookup["{$header}:{$rowNumber}"])) {
                    $errors[] = ['row' => $rowNumber, 'field' => $field, 'message' => 'Las fórmulas no están permitidas.'];
                }
            }

            $naturalKey = $this->naturalKey($type, $row);
            if (isset($seen[$naturalKey])) {
                $errors[] = ['row' => $rowNumber, 'field' => '_row', 'message' => 'La clave natural está duplicada dentro del archivo.'];
            } else {
                $seen[$naturalKey] = true;
            }
            if ($type === 'recipes') {
                $groupKey = mb_strtolower($row['product_name']).'|'.$row['version'];
                $groupValues = [$row['expected_yield'], $row['yield_unit'], $row['waste_percent']];
                if (isset($recipeGroups[$groupKey]) && $recipeGroups[$groupKey] !== $groupValues) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => '_row',
                        'message' => 'Rendimiento, unidad y merma deben coincidir en todas las filas de la versión.',
                    ];
                } else {
                    $recipeGroups[$groupKey] = $groupValues;
                }
            }
            $validator = Validator::make($row, $definition['rules']);
            foreach ($validator->errors()->toArray() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = ['row' => $rowNumber, 'field' => $field, 'message' => $message];
                }
            }
            if ($validator->errors()->isEmpty()) {
                foreach ($this->domainErrors($type, $row, $organizationId, $branchId) as $error) {
                    $errors[] = ['row' => $rowNumber, ...$error];
                }
            }
            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'preview' => array_slice($rows, 0, (int) config('imports.preview_rows')),
            'errors' => $errors,
        ];
    }

    /** @param array<string, mixed> $definition @param array<string, string> $mapping @param list<string> $headers */
    private function validateMapping(array $definition, array $mapping, array $headers): void
    {
        $messages = [];
        foreach ($definition['required'] as $field) {
            if (! isset($mapping[$field]) || $mapping[$field] === '') {
                $messages["mapping.{$field}"][] = 'La columna es obligatoria.';
            }
        }
        foreach ($mapping as $field => $header) {
            if (! in_array($field, $definition['fields'], true) || ! in_array($header, $headers, true)) {
                $messages["mapping.{$field}"][] = 'El mapeo no coincide con los encabezados analizados.';
            }
        }
        if (count(array_filter($mapping)) !== count(array_unique(array_filter($mapping)))) {
            $messages['mapping'][] = 'Una columna de origen no puede mapearse a más de un campo.';
        }
        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @param  array<string, string>  $row
     * @return list<array{field: string, message: string}>
     */
    private function domainErrors(
        string $type,
        array $row,
        int $organizationId,
        int $branchId,
    ): array {
        return match ($type) {
            'customers' => $this->existing(
                'customers',
                $organizationId,
                $row['tax_id'] !== '' ? 'tax_id' : 'name',
                $row['tax_id'] !== '' ? $row['tax_id'] : $row['name']
            ),
            'products' => $this->existing('products', $organizationId, 'name', $row['name']),
            'suppliers' => $this->existing(
                'suppliers',
                $organizationId,
                $row['tax_id'] !== '' ? 'tax_id' : 'trade_name',
                $row['tax_id'] !== '' ? $row['tax_id'] : $row['trade_name']
            ),
            'locations' => Location::query()
                ->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->whereRaw('lower(name) = ?', [mb_strtolower($row['name'])])->exists()
                    ? [['field' => 'name', 'message' => 'La ubicación ya existe en la sucursal.']] : [],
            'supplier_products' => $this->supplierProductErrors($row, $organizationId),
            'initial_stock' => $this->stockErrors($row, $organizationId, $branchId),
            'recipes' => $this->recipeErrors($row, $organizationId),
            default => [],
        };
    }

    /** @return list<array{field: string, message: string}> */
    private function existing(string $table, int $organizationId, string $field, string $value): array
    {
        $exists = \DB::table($table)->where('organization_id', $organizationId)
            ->whereRaw("lower({$field}) = ?", [mb_strtolower($value)])->exists();

        return $exists ? [['field' => $field, 'message' => 'La clave natural ya existe en la organización.']] : [];
    }

    /** @param array<string, string> $row @return list<array{field: string, message: string}> */
    private function supplierProductErrors(array $row, int $organizationId): array
    {
        $supplier = $this->supplier($row, $organizationId);
        $product = $this->product($row['product_name'], $organizationId);
        $errors = [];
        if (! $supplier) {
            $errors[] = ['field' => 'supplier_name', 'message' => 'No se encontró el proveedor dentro de la organización.'];
        }
        if (! $product) {
            $errors[] = ['field' => 'product_name', 'message' => 'No se encontró el producto dentro de la organización.'];
        }
        if ($supplier && $product && SupplierProduct::query()
            ->where('organization_id', $organizationId)
            ->where('supplier_id', $supplier->id)->where('product_id', $product->id)->exists()) {
            $errors[] = ['field' => '_row', 'message' => 'La relación proveedor-producto ya existe.'];
        }

        return $errors;
    }

    /** @param array<string, string> $row @return list<array{field: string, message: string}> */
    private function stockErrors(array $row, int $organizationId, int $branchId): array
    {
        $product = $this->product($row['product_name'], $organizationId);
        $location = Location::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($row['location_name'])])->first();
        $errors = [];
        if (! $product) {
            $errors[] = ['field' => 'product_name', 'message' => 'No se encontró el producto dentro de la organización.'];
        } elseif ($product->unit !== $row['unit']) {
            $errors[] = ['field' => 'unit', 'message' => 'La unidad no coincide con la unidad base del producto.'];
        }
        if (! $location) {
            $errors[] = ['field' => 'location_name', 'message' => 'No se encontró la ubicación dentro de la sucursal.'];
        }
        if ($product && $location && InventoryLot::query()
            ->where('product_id', $product->id)->where('location_id', $location->id)
            ->where('code', $row['lot_code'])->exists()) {
            $errors[] = ['field' => 'lot_code', 'message' => 'El lote ya existe para producto y ubicación.'];
        }

        return $errors;
    }

    /** @param array<string, string> $row @return list<array{field: string, message: string}> */
    private function recipeErrors(array $row, int $organizationId): array
    {
        $product = $this->product($row['product_name'], $organizationId);
        $ingredient = $this->product($row['ingredient_name'], $organizationId);
        $errors = [];
        if (! $product) {
            $errors[] = ['field' => 'product_name', 'message' => 'No se encontró el producto elaborado.'];
        }
        if (! $ingredient) {
            $errors[] = ['field' => 'ingredient_name', 'message' => 'No se encontró el ingrediente.'];
        } elseif ($ingredient->unit !== $row['ingredient_unit']) {
            $errors[] = ['field' => 'ingredient_unit', 'message' => 'La unidad no coincide con la unidad base del ingrediente.'];
        }
        if ($product && Recipe::query()->where('product_id', $product->id)->where('version', $row['version'])->exists()) {
            $errors[] = ['field' => 'version', 'message' => 'La versión de receta ya existe.'];
        }

        return $errors;
    }

    /** @param array<string, string> $row */
    private function naturalKey(string $type, array $row): string
    {
        $key = match ($type) {
            'customers' => $row['tax_id'] ?: $row['name'],
            'products' => $row['name'],
            'suppliers' => $row['tax_id'] ?: $row['trade_name'],
            'supplier_products' => ($row['supplier_tax_id'] ?: $row['supplier_name']).'|'.$row['product_name'],
            'locations' => $row['name'],
            'initial_stock' => $row['product_name'].'|'.$row['location_name'].'|'.$row['lot_code'],
            'recipes' => $row['product_name'].'|'.$row['version'].'|'.$row['ingredient_name'],
            default => json_encode($row, JSON_THROW_ON_ERROR),
        };

        return mb_strtolower($key);
    }

    private function product(string $name, int $organizationId): ?Product
    {
        return Product::query()->where('organization_id', $organizationId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
    }

    /** @param array<string, string> $row */
    private function supplier(array $row, int $organizationId): ?Supplier
    {
        $query = Supplier::query()->where('organization_id', $organizationId);

        return $row['supplier_tax_id'] !== ''
            ? $query->where('tax_id', $row['supplier_tax_id'])->first()
            : $query->whereRaw('lower(trade_name) = ?', [mb_strtolower($row['supplier_name'])])->first();
    }
}
