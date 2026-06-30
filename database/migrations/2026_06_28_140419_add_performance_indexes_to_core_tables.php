<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index tổ hợp cho các cột lọc/sắp xếp nóng (phạm vi theo tổ chức).
     * Tăng tốc danh sách đơn, báo cáo (status + khoảng ngày + người tạo),
     * dashboard, quét mã vận đơn, báo cáo tour.
     *
     * @var array<string, array<int, array{0: array<int, string>, 1: string}>>
     */
    private array $indexes = [
        'sales_orders' => [
            [['organization_id', 'status', 'order_date'], 'so_org_status_date_idx'],
            [['organization_id', 'created_by'], 'so_org_created_by_idx'],
            [['organization_id', 'payment_status'], 'so_org_payment_status_idx'],
            [['organization_id', 'tracking_number'], 'so_org_tracking_idx'],
        ],
        'purchase_orders' => [
            [['organization_id', 'status', 'order_date'], 'po_org_status_date_idx'],
            [['organization_id', 'created_by'], 'po_org_created_by_idx'],
        ],
        'tours' => [
            [['organization_id', 'status', 'start_date'], 'tour_org_status_date_idx'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $defs) {
            Schema::table($table, function (Blueprint $t) use ($defs) {
                foreach ($defs as [$columns, $name]) {
                    $t->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $defs) {
            Schema::table($table, function (Blueprint $t) use ($defs) {
                foreach ($defs as [, $name]) {
                    $t->dropIndex($name);
                }
            });
        }
    }
};
