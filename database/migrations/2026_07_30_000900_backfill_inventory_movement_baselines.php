<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventory_lots')->orderBy('id')->each(function (object $lot): void {
            $movementTotal = DB::table('stock_movements')
                ->where('inventory_lot_id', $lot->id)->sum('quantity');
            $difference = $this->scaled((string) $lot->quantity) - $this->scaled((string) $movementTotal);
            if ($difference === 0) {
                return;
            }

            $location = DB::table('locations')->where('id', $lot->location_id)
                ->first(['organization_id', 'branch_id']);
            DB::table('stock_movements')->insert([
                'organization_id' => $lot->organization_id ?? $location?->organization_id,
                'branch_id' => $lot->branch_id ?? $location?->branch_id,
                'product_id' => $lot->product_id,
                'location_id' => $lot->location_id,
                'inventory_lot_id' => $lot->id,
                'unit' => $lot->unit,
                'quantity' => $this->decimal($difference),
                'type' => 'legacy_baseline',
                'reason' => 'integrity_ledger_adoption',
                'idempotency_key' => "migration:inventory-baseline:{$lot->id}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('stock_movements')
            ->where('type', 'legacy_baseline')
            ->where('reason', 'integrity_ledger_adoption')
            ->where('idempotency_key', 'like', 'migration:inventory-baseline:%')
            ->delete();
    }

    private function scaled(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $scaled = ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? -$scaled : $scaled;
    }

    private function decimal(int $scaled): string
    {
        $negative = $scaled < 0;
        $scaled = abs($scaled);

        return ($negative ? '-' : '').intdiv($scaled, 1000).'.'.str_pad((string) ($scaled % 1000), 3, '0', STR_PAD_LEFT);
    }
};
