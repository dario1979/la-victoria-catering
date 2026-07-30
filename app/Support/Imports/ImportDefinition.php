<?php

namespace App\Support\Imports;

use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class ImportDefinition
{
    public const TYPES = [
        'customers',
        'products',
        'suppliers',
        'supplier_products',
        'locations',
        'initial_stock',
        'recipes',
    ];

    /** @return array<string, mixed> */
    public function for(string $type): array
    {
        return match ($type) {
            'customers' => [
                'label' => 'Clientes',
                'fields' => ['name', 'tax_id', 'tax_condition', 'email', 'phone', 'credit_limit', 'active'],
                'required' => ['name'],
                'allowed' => ['active' => ['true', 'false']],
                'rules' => [
                    'name' => ['required', 'string', 'max:255'],
                    'tax_id' => ['nullable', 'string', 'max:32'],
                    'tax_condition' => ['nullable', 'string', 'max:64'],
                    'email' => ['nullable', 'email', 'max:255'],
                    'phone' => ['nullable', 'string', 'max:64'],
                    'credit_limit' => ['nullable', 'decimal:0,2', 'gte:0'],
                    'active' => ['nullable', Rule::in(['true', 'false', '1', '0', 'yes', 'no', 'si', 'sí'])],
                ],
                'example' => ['Cliente Piloto', '30710000001', 'responsable_inscripto', 'cliente@example.test', '1100000000', '150000.00', 'true'],
            ],
            'products' => [
                'label' => 'Productos',
                'fields' => ['name', 'type', 'unit', 'minimum_stock', 'price', 'active'],
                'required' => ['name', 'type', 'unit'],
                'allowed' => [
                    'type' => ['raw_material', 'semi_finished', 'finished_product', 'packaging'],
                    'unit' => ['unit', 'kg', 'g', 'l', 'ml'],
                    'active' => ['true', 'false'],
                ],
                'rules' => [
                    'name' => ['required', 'string', 'max:255'],
                    'type' => ['required', Rule::in(['raw_material', 'semi_finished', 'finished_product', 'packaging'])],
                    'unit' => ['required', Rule::in(['unit', 'kg', 'g', 'l', 'ml'])],
                    'minimum_stock' => ['nullable', 'decimal:0,3', 'gte:0'],
                    'price' => ['nullable', 'decimal:0,2', 'gte:0'],
                    'active' => ['nullable', Rule::in(['true', 'false', '1', '0', 'yes', 'no', 'si', 'sí'])],
                ],
                'example' => ['Harina piloto', 'raw_material', 'kg', '20.000', '0.00', 'true'],
            ],
            'suppliers' => [
                'label' => 'Proveedores',
                'fields' => ['trade_name', 'legal_name', 'tax_id', 'email', 'phone', 'contact_name', 'address', 'payment_terms', 'lead_time_days', 'active'],
                'required' => ['trade_name'],
                'allowed' => ['active' => ['true', 'false']],
                'rules' => [
                    'trade_name' => ['required', 'string', 'max:255'],
                    'legal_name' => ['nullable', 'string', 'max:255'],
                    'tax_id' => ['nullable', 'string', 'max:32'],
                    'email' => ['nullable', 'email', 'max:255'],
                    'phone' => ['nullable', 'string', 'max:64'],
                    'contact_name' => ['nullable', 'string', 'max:255'],
                    'address' => ['nullable', 'string', 'max:1000'],
                    'payment_terms' => ['nullable', 'string', 'max:255'],
                    'lead_time_days' => ['nullable', 'integer', 'between:0,365'],
                    'active' => ['nullable', Rule::in(['true', 'false', '1', '0', 'yes', 'no', 'si', 'sí'])],
                ],
                'example' => ['Molino Piloto', 'Molino Piloto SA', '30710000002', 'compras@example.test', '1100000001', 'Contacto Ficticio', 'Calle de prueba 123', '30 días', '3', 'true'],
            ],
            'supplier_products' => [
                'label' => 'Catálogo proveedor-producto',
                'fields' => ['supplier_tax_id', 'supplier_name', 'product_name', 'supplier_code', 'purchase_unit', 'conversion_factor', 'minimum_quantity', 'lead_time_days', 'preferred', 'active'],
                'required' => ['product_name', 'purchase_unit', 'conversion_factor'],
                'allowed' => [
                    'purchase_unit' => ['unit', 'kg', 'g', 'l', 'ml'],
                    'preferred' => ['true', 'false'],
                    'active' => ['true', 'false'],
                ],
                'rules' => [
                    'supplier_tax_id' => ['nullable', 'string', 'max:32', 'required_without:supplier_name'],
                    'supplier_name' => ['nullable', 'string', 'max:255', 'required_without:supplier_tax_id'],
                    'product_name' => ['required', 'string', 'max:255'],
                    'supplier_code' => ['nullable', 'string', 'max:255'],
                    'purchase_unit' => ['required', Rule::in(['unit', 'kg', 'g', 'l', 'ml'])],
                    'conversion_factor' => ['required', 'decimal:0,6', 'gt:0'],
                    'minimum_quantity' => ['nullable', 'decimal:0,3', 'gte:0'],
                    'lead_time_days' => ['nullable', 'integer', 'between:0,365'],
                    'preferred' => ['nullable', Rule::in(['true', 'false', '1', '0', 'yes', 'no', 'si', 'sí'])],
                    'active' => ['nullable', Rule::in(['true', 'false', '1', '0', 'yes', 'no', 'si', 'sí'])],
                ],
                'example' => ['30710000002', '', 'Harina piloto', 'HAR-25', 'kg', '1.000000', '25.000', '3', 'true', 'true'],
            ],
            'locations' => [
                'label' => 'Ubicaciones',
                'fields' => ['name', 'active'],
                'required' => ['name'],
                'allowed' => ['active' => ['true', 'false']],
                'rules' => [
                    'name' => ['required', 'string', 'max:255'],
                    'active' => ['nullable', Rule::in(['true', 'false', '1', '0', 'yes', 'no', 'si', 'sí'])],
                ],
                'example' => ['Depósito piloto', 'true'],
            ],
            'initial_stock' => [
                'label' => 'Stock inicial por lote',
                'fields' => ['product_name', 'location_name', 'lot_code', 'quantity', 'unit', 'manufactured_on', 'expires_on'],
                'required' => ['product_name', 'location_name', 'lot_code', 'quantity', 'unit'],
                'allowed' => ['unit' => ['unit', 'kg', 'g', 'l', 'ml']],
                'rules' => [
                    'product_name' => ['required', 'string', 'max:255'],
                    'location_name' => ['required', 'string', 'max:255'],
                    'lot_code' => ['required', 'string', 'max:255'],
                    'quantity' => ['required', 'decimal:0,3', 'gt:0'],
                    'unit' => ['required', Rule::in(['unit', 'kg', 'g', 'l', 'ml'])],
                    'manufactured_on' => ['nullable', 'date_format:Y-m-d'],
                    'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:manufactured_on'],
                ],
                'example' => ['Harina piloto', 'Depósito piloto', 'INICIAL-001', '100.000', 'kg', '2026-07-01', '2026-12-31'],
            ],
            'recipes' => [
                'label' => 'Recetas e ingredientes',
                'fields' => ['product_name', 'version', 'expected_yield', 'yield_unit', 'waste_percent', 'ingredient_name', 'ingredient_quantity', 'ingredient_unit'],
                'required' => ['product_name', 'version', 'expected_yield', 'yield_unit', 'ingredient_name', 'ingredient_quantity', 'ingredient_unit'],
                'allowed' => [
                    'yield_unit' => ['unit', 'kg', 'g', 'l', 'ml'],
                    'ingredient_unit' => ['unit', 'kg', 'g', 'l', 'ml'],
                ],
                'rules' => [
                    'product_name' => ['required', 'string', 'max:255'],
                    'version' => ['required', 'integer', 'min:1'],
                    'expected_yield' => ['required', 'decimal:0,3', 'gt:0'],
                    'yield_unit' => ['required', Rule::in(['unit', 'kg', 'g', 'l', 'ml'])],
                    'waste_percent' => ['nullable', 'decimal:0,2', 'between:0,100'],
                    'ingredient_name' => ['required', 'string', 'max:255'],
                    'ingredient_quantity' => ['required', 'decimal:0,3', 'gt:0'],
                    'ingredient_unit' => ['required', Rule::in(['unit', 'kg', 'g', 'l', 'ml'])],
                ],
                'example' => ['Pan piloto', '1', '10.000', 'unit', '2.50', 'Harina piloto', '1.000', 'kg'],
            ],
            default => throw new InvalidArgumentException("Unsupported import type: {$type}"),
        };
    }
}
