<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite indexes hỗ trợ lọc theo tổ chức + trạng thái/ngày + sắp xếp,
     * tránh full table scan & filesort khi dữ liệu đơn hàng lên hàng triệu bản ghi.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $indexes = [
        'sales_orders' => [
            'so_org_created_idx' => ['organization_id', 'created_at'],
            'so_org_order_date_idx' => ['organization_id', 'order_date'],
            'so_org_status_idx' => ['organization_id', 'status'],
        ],
        'purchase_orders' => [
            'po_org_created_idx' => ['organization_id', 'created_at'],
            'po_org_order_date_idx' => ['organization_id', 'order_date'],
            'po_org_status_idx' => ['organization_id', 'status'],
        ],
        'payments' => [
            'pay_org_type_date_idx' => ['organization_id', 'type', 'payment_date'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            Schema::table($table, function (Blueprint $t) use ($table, $definitions) {
                foreach ($definitions as $name => $columns) {
                    if (! $this->indexExists($table, $name)) {
                        $t->index($columns, $name);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $definitions) {
            Schema::table($table, function (Blueprint $t) use ($table, $definitions) {
                foreach (array_keys($definitions) as $name) {
                    if ($this->indexExists($table, $name)) {
                        $t->dropIndex($name);
                    }
                }
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);

        return ! empty($rows);
    }
};
