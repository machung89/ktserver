<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dựng lại reserved_quantity từ các ĐƠN NHÁP đang giữ chỗ (chỉ dòng SL dương;
 * bung combo/định mức theo nguyên liệu; manufacturing thì không bung công thức).
 *
 * Chạy lại lần 2: dọn reserved âm/sai còn sót (vd mã 10882 tồn 0 nhưng reserved -1
 * khiến "có thể bán" = 1). Từ nay chokepoint đã chặn reserved âm (GREATEST(0, ...)).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('inventories')->update(['reserved_quantity' => 0]);

        $modes = DB::table('organizations')->get(['id', 'settings'])
            ->mapWithKeys(fn ($o) => [$o->id => (json_decode($o->settings ?? '{}', true)['business_mode'] ?? 'retail')]);

        $recipes = DB::table('recipes')->whereIn('type', ['combo', 'recipe'])->get()->keyBy('product_id');
        $ingredientsByRecipe = DB::table('recipe_ingredients')->get()->groupBy('recipe_id');

        $items = DB::table('sales_order_items as i')
            ->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
            ->where('o.status', 'draft')
            ->where('i.quantity', '>', 0)
            ->get(['i.product_id', 'i.warehouse_id', 'i.quantity', 'o.organization_id']);

        $acc = []; // "org|wh|prod" => qty
        foreach ($items as $it) {
            $isManufacturing = ($modes[$it->organization_id] ?? 'retail') === 'manufacturing';
            $recipe = ! $isManufacturing ? ($recipes[$it->product_id] ?? null) : null;

            if ($recipe) {
                $yield = (float) $recipe->yield_quantity ?: 1.0;
                $multiplier = (float) $it->quantity / $yield;
                foreach ($ingredientsByRecipe[$recipe->id] ?? [] as $ing) {
                    $qty = round((float) $ing->quantity * $multiplier, 4);
                    $key = "{$it->organization_id}|{$it->warehouse_id}|{$ing->ingredient_id}";
                    $acc[$key] = ($acc[$key] ?? 0) + $qty;
                }
            } else {
                $key = "{$it->organization_id}|{$it->warehouse_id}|{$it->product_id}";
                $acc[$key] = ($acc[$key] ?? 0) + (float) $it->quantity;
            }
        }

        foreach ($acc as $key => $qty) {
            [$org, $wh, $prod] = explode('|', $key);
            DB::table('inventories')
                ->where(['organization_id' => $org, 'warehouse_id' => $wh, 'product_id' => $prod])
                ->update(['reserved_quantity' => round($qty, 3)]);
        }
    }

    public function down(): void
    {
        // Sửa dữ liệu một chiều — không hoàn tác.
    }
};
