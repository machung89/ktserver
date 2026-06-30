<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentType;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use ScopedByOrganization;

    /**
     * Số liệu tổng quan cho dashboard: doanh thu & nhập trong tháng (gồm cả đơn nháp),
     * tổng công nợ phải thu của khách hàng.
     */
    public function dashboardSummary(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = $this->orgId();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        // Phạm vi xem dữ liệu: null = xem tất cả; ngược lại chỉ đơn của mình + người được phép xem.
        $salesCreators = $user->hasPermission('sales.view_all')
            ? null
            : array_merge([$user->id], $user->getViewableUserIds());
        $purchaseCreators = $user->hasPermission('purchases.view_all')
            ? null
            : array_merge([$user->id], $user->getViewableUserIds());

        // Doanh thu thuần (chưa VAT) tháng — gồm cả đơn nháp, bỏ đơn đã hủy
        $revenueMonth = (float) DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->where('so.organization_id', $orgId)
            ->where('so.status', '!=', 'cancelled')
            ->whereBetween('so.order_date', [$monthStart, $monthEnd])
            ->when($salesCreators, fn ($q, $v) => $q->whereIn('so.created_by', $v))
            ->sum(DB::raw('soi.amount - soi.order_discount_alloc'));

        // Doanh thu thuần (chưa VAT) hôm nay — gồm cả đơn nháp, bỏ đơn đã hủy
        $revenueToday = (float) DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->where('so.organization_id', $orgId)
            ->where('so.status', '!=', 'cancelled')
            ->whereDate('so.order_date', now()->toDateString())
            ->when($salesCreators, fn ($q, $v) => $q->whereIn('so.created_by', $v))
            ->sum(DB::raw('soi.amount - soi.order_discount_alloc'));

        // Tổng nhập tháng — gồm cả đơn nháp, bỏ đơn đã hủy
        $purchaseMonth = (float) DB::table('purchase_orders')
            ->where('organization_id', $orgId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('order_date', [$monthStart, $monthEnd])
            ->when($purchaseCreators, fn ($q, $v) => $q->whereIn('created_by', $v))
            ->sum('total_amount');

        // Tổng công nợ phải thu — đơn bán đã xác nhận/giao/hoàn thành còn nợ
        $totalDebt = (float) DB::table('sales_orders')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['confirmed', 'shipping', 'completed'])
            ->whereRaw('total_amount - paid_amount > 0.01')
            ->when($salesCreators, fn ($q, $v) => $q->whereIn('created_by', $v))
            ->selectRaw('COALESCE(SUM(total_amount - paid_amount), 0) as debt')
            ->value('debt');

        // Giá trị tồn kho hiện tại = Σ(số lượng × giá vốn bình quân) từ bảng inventories (nguồn chuẩn)
        $inventoryValue = (float) DB::table('inventories')
            ->where('organization_id', $orgId)
            ->selectRaw('COALESCE(SUM(quantity * avg_cost), 0) as val')
            ->value('val');

        // ── Sản phẩm sắp hết: tồn khả dụng ≤ tối thiểu HOẶC số ngày bán hết < ngưỡng ──
        $coverDays = (int) (Organization::find($orgId)?->setting('low_stock_cover_days', 7) ?? 7);
        $from = now()->subDays(30)->toDateString();

        $velocitySub = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->where('so.organization_id', $orgId)
            ->whereIn('so.status', ['confirmed', 'shipping', 'completed'])
            ->where('so.order_date', '>=', $from)
            ->where('soi.is_return', false)
            ->groupBy('soi.product_id')
            ->selectRaw('soi.product_id, SUM(soi.quantity) / 30 as velocity');

        $lowStockBase = DB::table('inventories as i')
            ->leftJoinSub($velocitySub, 'v', 'v.product_id', '=', 'i.product_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')
            ->where('i.organization_id', $orgId)
            ->where('p.is_active', true)
            ->where(function ($q) use ($coverDays) {
                $q->whereRaw('(i.quantity - i.reserved_quantity) <= i.min_quantity')
                    ->orWhereRaw('COALESCE(v.velocity, 0) > 0 AND (i.quantity - i.reserved_quantity) / v.velocity < ?', [$coverDays]);
            });

        $lowStockCount = (clone $lowStockBase)->count();

        $lowStockItems = (clone $lowStockBase)
            ->selectRaw('p.code as product_code, p.name as product_name, p.unit, w.name as warehouse,
                (i.quantity - i.reserved_quantity) as available, i.min_quantity,
                CASE WHEN COALESCE(v.velocity,0) > 0 THEN ROUND((i.quantity - i.reserved_quantity) / v.velocity, 1) ELSE NULL END as days_of_cover')
            ->orderByRaw('days_of_cover IS NULL, days_of_cover ASC, available ASC')
            ->limit(10)
            ->get();

        // ── Thống kê năm nay ──
        $yearStart = now()->startOfYear()->toDateString();
        $yearEnd = now()->endOfYear()->toDateString();

        // Doanh thu thuần (chưa VAT) & giá vốn năm nay (đơn đã xác nhận/giao/hoàn thành)
        $yearAgg = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->where('so.organization_id', $orgId)
            ->whereIn('so.status', ['confirmed', 'shipping', 'completed'])
            ->whereBetween('so.order_date', [$yearStart, $yearEnd])
            ->when($salesCreators, fn ($q, $v) => $q->whereIn('so.created_by', $v))
            ->selectRaw('COALESCE(SUM(((soi.amount - soi.order_discount_alloc))), 0) as revenue, COALESCE(SUM(soi.quantity * soi.cost_price), 0) as cost')
            ->first();

        $yearRevenue = (float) ($yearAgg->revenue ?? 0);
        $yearCost = (float) ($yearAgg->cost ?? 0);

        $yearOrders = (int) DB::table('sales_orders')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['confirmed', 'shipping', 'completed'])
            ->whereBetween('order_date', [$yearStart, $yearEnd])
            ->when($salesCreators, fn ($q, $v) => $q->whereIn('created_by', $v))
            ->count();

        $yearPurchase = (float) DB::table('purchase_orders')
            ->where('organization_id', $orgId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('order_date', [$yearStart, $yearEnd])
            ->when($purchaseCreators, fn ($q, $v) => $q->whereIn('created_by', $v))
            ->sum('total_amount');

        // Doanh thu thuần (chưa VAT) 12 tháng gần nhất (đã lọc theo quyền xem) cho biểu đồ
        $monthlyFrom = now()->subMonths(11)->startOfMonth()->toDateString();
        $monthly = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->where('so.organization_id', $orgId)
            ->whereIn('so.status', ['confirmed', 'shipping', 'completed'])
            ->where('so.order_date', '>=', $monthlyFrom)
            ->when($salesCreators, fn ($q, $v) => $q->whereIn('so.created_by', $v))
            ->groupByRaw("DATE_FORMAT(so.order_date, '%Y-%m')")
            ->orderByRaw("DATE_FORMAT(so.order_date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(so.order_date, '%Y-%m') as month, SUM(soi.amount - soi.order_discount_alloc) as revenue")
            ->get();

        // ── Sản phẩm bán chạy nhất trong 30 ngày gần nhất (theo số lượng) ──
        $topProducts = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->where('so.organization_id', $orgId)
            ->whereIn('so.status', ['confirmed', 'shipping', 'completed'])
            ->where('so.order_date', '>=', $from)
            ->where('soi.is_return', false)
            ->when($salesCreators, fn ($q, $v) => $q->whereIn('so.created_by', $v))
            ->groupBy('soi.product_id', 'p.code', 'p.name', 'p.unit')
            ->selectRaw('p.code as product_code, p.name as product_name, p.unit, SUM(soi.quantity) as quantity, SUM(soi.amount - soi.order_discount_alloc) as revenue')
            ->havingRaw('SUM(soi.quantity) > 0')
            ->orderByDesc('quantity')
            ->limit(10)
            ->get();

        return response()->json([
            'revenue_today' => $revenueToday,
            'revenue_month' => $revenueMonth,
            'purchase_month' => $purchaseMonth,
            'total_debt' => $totalDebt,
            'inventory_value' => $inventoryValue,
            'low_stock_count' => $lowStockCount,
            'low_stock_items' => $lowStockItems,
            'top_products' => $topProducts,
            'year' => [
                'revenue' => $yearRevenue,
                'cost' => $yearCost,
                'gross_profit' => round($yearRevenue - $yearCost, 2),
                'orders' => $yearOrders,
                'purchase' => $yearPurchase,
            ],
            'monthly' => $monthly,
        ]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $from = $request->from; // YYYY-MM-DD
        $to = $request->to;     // YYYY-MM-DD

        $movements = DB::table('inventory_transaction_items as iti')
            ->join('inventory_transactions as it', 'it.id', '=', 'iti.inventory_transaction_id')
            ->join('products as p', 'p.id', '=', 'iti.product_id')
            ->join('warehouses as w', 'w.id', '=', 'it.warehouse_id')
            ->where('it.organization_id', $orgId)
            ->where('it.is_posted', true)
            ->when($request->warehouse_id, fn ($q, $v) => $q->where('it.warehouse_id', $v))
            ->select([
                'iti.product_id',
                'it.warehouse_id',
                'p.code as product_code',
                'p.name as product_name',
                'p.unit',
                'w.name as warehouse_name',
                DB::raw('CAST(iti.quantity AS DECIMAL(15,3)) as quantity'),
                DB::raw('DATE(it.transaction_date) as txn_date'),
            ])
            ->get();

        if ($movements->isEmpty()) {
            return response()->json(['data' => []]);
        }

        // Lấy avg_cost hiện tại từ bảng inventories (WAC)
        $avgCosts = DB::table('inventories')
            ->where('organization_id', $orgId)
            ->pluck('avg_cost', DB::raw("CONCAT(product_id,'_',warehouse_id)"));

        $rows = $movements
            ->groupBy(fn ($r) => $r->product_id.'_'.$r->warehouse_id)
            ->map(function ($txns, $key) use ($from, $to, $avgCosts) {
                $first = $txns->first();

                // Tồn đầu kỳ = tất cả giao dịch trước ngày bắt đầu kỳ
                $beforePeriod = $from
                    ? $txns->filter(fn ($t) => $t->txn_date < $from)
                    : collect();
                $openingQty = (float) $beforePeriod->sum('quantity');

                // Giao dịch trong kỳ
                $inPeriod = $txns
                    ->when($from, fn ($c) => $c->filter(fn ($t) => $t->txn_date >= $from))
                    ->when($to, fn ($c) => $c->filter(fn ($t) => $t->txn_date <= $to));

                $inQty = (float) $inPeriod->filter(fn ($t) => (float) $t->quantity > 0)->sum('quantity');
                $outQty = abs((float) $inPeriod->filter(fn ($t) => (float) $t->quantity < 0)->sum('quantity'));
                $closingQty = $openingQty + $inQty - $outQty;

                // Dùng avg_cost (WAC) từ inventories thay vì products.cost_price
                $avgCost = (float) ($avgCosts[$key] ?? 0);

                return [
                    'product_code' => $first->product_code,
                    'product_name' => $first->product_name,
                    'unit' => $first->unit,
                    'warehouse' => $first->warehouse_name,
                    'opening_qty' => round($openingQty, 3),
                    'opening_value' => round($openingQty * $avgCost),
                    'in_qty' => round($inQty, 3),
                    'out_qty' => round($outQty, 3),
                    'closing_qty' => round($closingQty, 3),
                    'closing_value' => round($closingQty * $avgCost),
                    'avg_cost' => $avgCost,
                ];
            })
            ->values()
            ->sortBy(['product_code', 'warehouse'])
            ->values();

        return response()->json([
            'data' => $rows,
            'total_opening_value' => $rows->sum('opening_value'),
            'total_closing_value' => $rows->sum('closing_value'),
        ]);
    }

    public function receivables(): JsonResponse
    {
        $orgId = $this->orgId();

        // Công nợ = Σ(tổng đơn − đã thu) trên đơn đã lên sổ 131 (xác nhận/đang giao/hoàn thành).
        // paid_amount đã đồng bộ allocations (thu gộp / tiền thu trước) → nhất quán với per-order & stats.
        // DB::table bỏ qua global scope nên phải tự lọc organization_id.
        $rows = DB::table('sales_orders')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['confirmed', 'shipping', 'completed'])
            ->when($this->salesCreatorFilter(), fn ($q, $v) => $q->whereIn('created_by', $v))
            ->groupBy('company_id')
            ->select(
                'company_id',
                DB::raw('SUM(total_amount) as total_sales'),
                DB::raw('SUM(paid_amount) as total_receipts'),
                DB::raw('SUM(total_amount - paid_amount) as balance'),
            )
            ->havingRaw('SUM(total_amount - paid_amount) > 0')
            ->get();

        $names = DB::table('companies')
            ->where('organization_id', $orgId)
            ->whereIn('id', $rows->pluck('company_id')->filter())
            ->pluck('name', 'id');

        $data = $rows->map(fn ($r) => [
            'company_name' => $names[$r->company_id] ?? '',
            'total_sales' => (float) $r->total_sales,
            'total_receipts' => (float) $r->total_receipts,
            'balance' => (float) $r->balance,
        ])->values();

        return response()->json(['data' => $data, 'total_balance' => $data->sum('balance')]);
    }

    public function payables(): JsonResponse
    {
        $orgId = $this->orgId();

        $rows = DB::table('purchase_orders')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['confirmed', 'shipping', 'completed'])
            ->when($this->purchaseCreatorFilter(), fn ($q, $v) => $q->whereIn('created_by', $v))
            ->groupBy('company_id')
            ->select(
                'company_id',
                DB::raw('SUM(total_amount) as total_purchases'),
                DB::raw('SUM(paid_amount) as total_payments'),
                DB::raw('SUM(total_amount - paid_amount) as balance'),
            )
            ->havingRaw('SUM(total_amount - paid_amount) > 0')
            ->get();

        $names = DB::table('companies')
            ->where('organization_id', $orgId)
            ->whereIn('id', $rows->pluck('company_id')->filter())
            ->pluck('name', 'id');

        $data = $rows->map(fn ($r) => [
            'company_name' => $names[$r->company_id] ?? '',
            'total_purchases' => (float) $r->total_purchases,
            'total_payments' => (float) $r->total_payments,
            'balance' => (float) $r->balance,
        ])->values();

        return response()->json(['data' => $data, 'total_balance' => $data->sum('balance')]);
    }

    public function sales(Request $request): JsonResponse
    {
        return match ($request->get('group_by', 'product')) {
            'employee' => $this->salesByEmployee($request),
            'customer' => $this->salesGroupedByCustomer($request),
            'date' => $this->salesGroupedByDate($request),
            'month' => $this->salesGroupedByMonth($request),
            'category' => $this->salesGroupedByCategory($request),
            default => $this->salesGroupedByProduct($request),
        };
    }

    /**
     * Báo cáo doanh thu / giá vốn / lợi nhuận theo TOUR (loại hình tour du lịch).
     * Khớp với KQHĐKD: doanh thu thuần = total_amount − tax_amount (TK 511);
     * giá vốn = Σ(unit_price × quantity × days) dịch vụ (TK 632, chưa VAT đầu vào).
     */
    public function tours(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $showProfit = $this->canViewProfit();

        $costSub = DB::table('tour_services')
            ->selectRaw('tour_id, COALESCE(SUM(unit_price * quantity * days), 0) as cost')
            ->groupBy('tour_id');

        $rows = DB::table('tours as t')
            ->leftJoinSub($costSub, 'sc', 'sc.tour_id', '=', 't.id')
            ->leftJoin('companies as c', 'c.id', '=', 't.customer_id')
            ->where('t.organization_id', $orgId)
            ->whereIn('t.status', ['confirmed', 'completed'])
            ->when($request->from, fn ($q, $v) => $q->where('t.start_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('t.start_date', '<=', $v))
            ->orderByDesc('t.start_date')
            ->select([
                't.id',
                't.tour_number',
                't.name',
                'c.name as customer_name',
                't.start_date',
                't.end_date',
                't.num_guests',
                't.status',
                DB::raw('(t.total_amount - t.tax_amount) as revenue'),
                DB::raw('COALESCE(sc.cost, 0) as cost'),
            ])
            ->get()
            ->map(function ($r) use ($showProfit) {
                $revenue = round((float) $r->revenue);
                $cost = round((float) $r->cost);

                $row = [
                    'id' => $r->id,
                    'tour_number' => $r->tour_number,
                    'name' => $r->name,
                    'customer_name' => $r->customer_name,
                    'start_date' => $r->start_date,
                    'end_date' => $r->end_date,
                    'num_guests' => (int) $r->num_guests,
                    'status' => $r->status,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $revenue - $cost,
                    'margin' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : 0,
                ];

                return $showProfit ? $row : $this->stripProfitFields($row);
            });

        return response()->json([
            'data' => $rows->values(),
            'total_revenue' => round($rows->sum('revenue')),
            'tour_count' => $rows->count(),
            'guest_count' => (int) $rows->sum('num_guests'),
            ...($showProfit ? [
                'total_cost' => round($rows->sum('cost')),
                'gross_profit' => round($rows->sum('profit')),
            ] : []),
        ]);
    }

    private function canViewProfit(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $user?->hasPermission('reports.view_profit') ?? false;
    }

    /**
     * Phạm vi xem báo cáo bán hàng: null = xem tất cả; ngược lại chỉ đơn của mình + người được phép xem.
     *
     * @return array<int>|null
     */
    private function salesCreatorFilter(): ?array
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->hasPermission('sales.view_all') ? null : array_merge([$user->id], $user->getViewableUserIds());
    }

    /** @return array<int>|null */
    private function purchaseCreatorFilter(): ?array
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->hasPermission('purchases.view_all') ? null : array_merge([$user->id], $user->getViewableUserIds());
    }

    private function stripProfitFields(array $row): array
    {
        unset($row['cost'], $row['profit'], $row['margin'], $row['standard_total'], $row['employee_profit']);

        return $row;
    }

    private function salesGroupedByProduct(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $showProfit = $this->canViewProfit();

        // Gom nhóm bằng SQL (SUM/GROUP BY) thay vì nạp toàn bộ dòng hàng vào PHP —
        // an toàn với hàng triệu bản ghi.
        $rows = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('product_categories as cat', 'cat.id', '=', 'p.category_id')
            ->leftJoin('product_categories as parent', 'parent.id', '=', 'cat.parent_id')
            ->where('so.organization_id', $orgId)
            ->whereIn('so.status', ['confirmed', 'shipping', 'completed'])
            ->when($this->salesCreatorFilter(), fn ($q, $v) => $q->whereIn('so.created_by', $v))
            ->when($request->employee_id, fn ($q, $v) => $q->where('so.created_by', $v))
            ->when($request->from, fn ($q, $v) => $q->where('so.order_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('so.order_date', '<=', $v))
            ->when($request->category_id, fn ($q, $v) => $q->where('p.category_id', $v))
            ->groupBy('soi.product_id', 'p.code', 'p.name', 'p.unit', 'cat.name', 'parent.name')
            ->select([
                DB::raw('COALESCE(p.code, \'\') as product_code'),
                DB::raw('COALESCE(p.name, \'\') as product_name'),
                DB::raw('COALESCE(p.unit, \'\') as unit'),
                'cat.name as cat_name',
                'parent.name as parent_name',
                DB::raw('SUM(soi.quantity) as quantity'),
                DB::raw('SUM(((soi.amount - soi.order_discount_alloc))) as revenue'),
                DB::raw('SUM(soi.quantity * soi.cost_price) as cost'),
                DB::raw('SUM(CASE WHEN soi.standard_price > 0 THEN soi.quantity * soi.standard_price ELSE 0 END) as standard_total'),
            ])
            ->orderByDesc(DB::raw('SUM(((soi.amount - soi.order_discount_alloc)))'))
            ->get();

        $grouped = $rows->map(function ($r) use ($showProfit) {
            $catLabel = $r->cat_name
                ? ($r->parent_name ? $r->parent_name.' › '.$r->cat_name : $r->cat_name)
                : null;

            $revenue = round((float) $r->revenue, 2);
            $cost = round((float) $r->cost, 2);
            $standardTotal = round((float) $r->standard_total, 2);

            $row = [
                'product_code' => $r->product_code,
                'product_name' => $r->product_name,
                'unit' => $r->unit,
                'category' => $catLabel,
                'quantity' => round((float) $r->quantity, 3),
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => round($revenue - $cost, 2),
                'margin' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : 0,
                'standard_total' => $standardTotal,
                'employee_profit' => $standardTotal > 0 ? round($revenue - $standardTotal, 2) : 0,
            ];

            return $showProfit ? $row : $this->stripProfitFields($row);
        })
            ->filter(fn ($r) => $r['quantity'] != 0 || $r['revenue'] != 0)
            ->values();

        return response()->json([
            'data' => $grouped,
            'total_revenue' => round($grouped->sum('revenue'), 2),
            ...($showProfit ? [
                'total_cost' => round($grouped->sum('cost'), 2),
                'gross_profit' => round($grouped->sum('profit'), 2),
            ] : []),
        ]);
    }

    private function salesGroupedByDate(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $showProfit = $this->canViewProfit();

        $rows = DB::table('sales_orders as so')
            ->leftJoin('sales_order_items as soi', function ($j) {
                $j->on('soi.sales_order_id', '=', 'so.id')->where('soi.is_return', false);
            })
            ->where('so.organization_id', $orgId)
            ->whereIn('so.status', ['confirmed', 'shipping', 'completed'])
            ->when($this->salesCreatorFilter(), fn ($q, $v) => $q->whereIn('so.created_by', $v))
            ->when($request->employee_id, fn ($q, $v) => $q->where('so.created_by', $v))
            ->when($request->from, fn ($q, $v) => $q->where('so.order_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('so.order_date', '<=', $v))
            ->groupBy(DB::raw('DATE(so.order_date)'))
            ->select([
                DB::raw('DATE(so.order_date) as date'),
                DB::raw('COUNT(DISTINCT so.id) as order_count'),
                DB::raw('SUM(((soi.amount - soi.order_discount_alloc))) as revenue'),
                DB::raw('SUM(soi.quantity * soi.cost_price) as cost'),
                DB::raw('SUM(CASE WHEN soi.standard_price > 0 THEN soi.quantity * soi.standard_price ELSE 0 END) as standard_total'),
            ])
            ->orderBy(DB::raw('DATE(so.order_date)'))
            ->get()
            ->map(function ($r) use ($showProfit) {
                $revenue = round((float) $r->revenue, 2);
                $cost = round((float) $r->cost, 2);
                $profit = round($revenue - $cost, 2);
                $standardTotal = round((float) $r->standard_total, 2);

                $row = [
                    'date' => $r->date,
                    'order_count' => (int) $r->order_count,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $profit,
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
                    'standard_total' => $standardTotal,
                    'employee_profit' => $standardTotal > 0 ? round($revenue - $standardTotal, 2) : null,
                ];

                return $showProfit ? $row : $this->stripProfitFields($row);
            });

        return response()->json([
            'data' => $rows,
            'total_revenue' => round($rows->sum('revenue'), 2),
            ...($showProfit ? [
                'total_cost' => round($rows->sum('cost'), 2),
                'gross_profit' => round($rows->sum('profit'), 2),
            ] : []),
        ]);
    }

    private function salesGroupedByCustomer(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $showProfit = $this->canViewProfit();

        $rows = DB::table('sales_orders as so')
            ->leftJoin('sales_order_items as soi', function ($j) {
                $j->on('soi.sales_order_id', '=', 'so.id')->where('soi.is_return', false);
            })
            ->leftJoin('companies as c', 'c.id', '=', 'so.company_id')
            ->where('so.organization_id', $orgId)
            ->whereIn('so.status', ['confirmed', 'shipping', 'completed'])
            ->when($this->salesCreatorFilter(), fn ($q, $v) => $q->whereIn('so.created_by', $v))
            ->when($request->employee_id, fn ($q, $v) => $q->where('so.created_by', $v))
            ->when($request->from, fn ($q, $v) => $q->where('so.order_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('so.order_date', '<=', $v))
            ->groupBy('so.company_id', 'c.name')
            ->select([
                'so.company_id',
                DB::raw("COALESCE(c.name, '(Khách lẻ)') as customer_name"),
                DB::raw('COUNT(DISTINCT so.id) as order_count'),
                DB::raw('SUM(((soi.amount - soi.order_discount_alloc))) as revenue'),
                DB::raw('SUM(soi.quantity * soi.cost_price) as cost'),
                DB::raw('SUM(CASE WHEN soi.standard_price > 0 THEN soi.quantity * soi.standard_price ELSE 0 END) as standard_total'),
            ])
            ->orderByDesc(DB::raw('SUM(((soi.amount - soi.order_discount_alloc)))'))
            ->get()
            ->map(function ($r) use ($showProfit) {
                $revenue = round((float) $r->revenue, 2);
                $cost = round((float) $r->cost, 2);
                $profit = round($revenue - $cost, 2);
                $standardTotal = round((float) $r->standard_total, 2);

                $row = [
                    'company_id' => $r->company_id,
                    'customer_name' => $r->customer_name,
                    'order_count' => (int) $r->order_count,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $profit,
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
                    'standard_total' => $standardTotal,
                    'employee_profit' => $standardTotal > 0 ? round($revenue - $standardTotal, 2) : null,
                ];

                return $showProfit ? $row : $this->stripProfitFields($row);
            });

        return response()->json([
            'data' => $rows,
            'total_revenue' => round($rows->sum('revenue'), 2),
            ...($showProfit ? [
                'total_cost' => round($rows->sum('cost'), 2),
                'gross_profit' => round($rows->sum('profit'), 2),
            ] : []),
        ]);
    }

    private function salesGroupedByMonth(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $showProfit = $this->canViewProfit();

        $rows = DB::table('sales_orders as so')
            ->leftJoin('sales_order_items as soi', function ($j) {
                $j->on('soi.sales_order_id', '=', 'so.id')->where('soi.is_return', false);
            })
            ->where('so.organization_id', $orgId)
            ->whereIn('so.status', ['confirmed', 'shipping', 'completed'])
            ->when($this->salesCreatorFilter(), fn ($q, $v) => $q->whereIn('so.created_by', $v))
            ->when($request->employee_id, fn ($q, $v) => $q->where('so.created_by', $v))
            ->when($request->from, fn ($q, $v) => $q->where('so.order_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('so.order_date', '<=', $v))
            ->groupBy(DB::raw("DATE_FORMAT(so.order_date, '%Y-%m')"))
            ->select([
                DB::raw("DATE_FORMAT(so.order_date, '%Y-%m') as month"),
                DB::raw('COUNT(DISTINCT so.id) as order_count'),
                DB::raw('SUM(((soi.amount - soi.order_discount_alloc))) as revenue'),
                DB::raw('SUM(soi.quantity * soi.cost_price) as cost'),
                DB::raw('SUM(CASE WHEN soi.standard_price > 0 THEN soi.quantity * soi.standard_price ELSE 0 END) as standard_total'),
            ])
            ->orderBy(DB::raw("DATE_FORMAT(so.order_date, '%Y-%m')"))
            ->get()
            ->map(function ($r) use ($showProfit) {
                $revenue = round((float) $r->revenue, 2);
                $cost = round((float) $r->cost, 2);
                $profit = round($revenue - $cost, 2);
                $standardTotal = round((float) $r->standard_total, 2);

                $row = [
                    'month' => $r->month,
                    'order_count' => (int) $r->order_count,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $profit,
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
                    'standard_total' => $standardTotal,
                    'employee_profit' => $standardTotal > 0 ? round($revenue - $standardTotal, 2) : null,
                ];

                return $showProfit ? $row : $this->stripProfitFields($row);
            });

        return response()->json([
            'data' => $rows,
            'total_revenue' => round($rows->sum('revenue'), 2),
            ...($showProfit ? [
                'total_cost' => round($rows->sum('cost'), 2),
                'gross_profit' => round($rows->sum('profit'), 2),
            ] : []),
        ]);
    }

    private function salesGroupedByCategory(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $showProfit = $this->canViewProfit();

        $rows = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->leftJoin('product_categories as parent_pc', 'parent_pc.id', '=', 'pc.parent_id')
            ->where('so.organization_id', $orgId)
            ->whereIn('so.status', ['confirmed', 'shipping', 'completed'])
            ->when($this->salesCreatorFilter(), fn ($q, $v) => $q->whereIn('so.created_by', $v))
            ->when($request->employee_id, fn ($q, $v) => $q->where('so.created_by', $v))
            ->where('soi.is_return', false)
            ->when($request->from, fn ($q, $v) => $q->where('so.order_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('so.order_date', '<=', $v))
            ->groupBy('pc.id', 'pc.name', 'parent_pc.name')
            ->select([
                DB::raw("COALESCE(CASE WHEN parent_pc.name IS NOT NULL THEN CONCAT(parent_pc.name, ' › ', pc.name) ELSE pc.name END, '(Chưa phân loại)') as category_name"),
                DB::raw('SUM(soi.quantity) as quantity'),
                DB::raw('COUNT(DISTINCT so.id) as order_count'),
                DB::raw('SUM(((soi.amount - soi.order_discount_alloc))) as revenue'),
                DB::raw('SUM(soi.quantity * soi.cost_price) as cost'),
                DB::raw('SUM(CASE WHEN soi.standard_price > 0 THEN soi.quantity * soi.standard_price ELSE 0 END) as standard_total'),
            ])
            ->orderByDesc(DB::raw('SUM(((soi.amount - soi.order_discount_alloc)))'))
            ->get()
            ->map(function ($r) use ($showProfit) {
                $revenue = round((float) $r->revenue, 2);
                $cost = round((float) $r->cost, 2);
                $profit = round($revenue - $cost, 2);
                $standardTotal = round((float) $r->standard_total, 2);

                $row = [
                    'category_name' => $r->category_name,
                    'quantity' => round((float) $r->quantity, 3),
                    'order_count' => (int) $r->order_count,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $profit,
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
                    'standard_total' => $standardTotal,
                    'employee_profit' => $standardTotal > 0 ? round($revenue - $standardTotal, 2) : null,
                ];

                return $showProfit ? $row : $this->stripProfitFields($row);
            });

        return response()->json([
            'data' => $rows,
            'total_revenue' => round($rows->sum('revenue'), 2),
            ...($showProfit ? [
                'total_cost' => round($rows->sum('cost'), 2),
                'gross_profit' => round($rows->sum('profit'), 2),
            ] : []),
        ]);
    }

    public function purchases(Request $request): JsonResponse
    {
        return match ($request->get('group_by', 'product')) {
            'supplier' => $this->purchasesGroupedBySupplier($request),
            'date' => $this->purchasesGroupedByDate($request),
            'month' => $this->purchasesGroupedByMonth($request),
            'category' => $this->purchasesGroupedByCategory($request),
            default => $this->purchasesGroupedByProduct($request),
        };
    }

    /**
     * Áp các bộ lọc chung cho báo cáo nhập hàng lên query (chỉ đơn đã xác nhận/hoàn thành).
     */
    private function applyPurchaseFilters(Builder $query, Request $request): Builder
    {
        return $query
            ->where('po.organization_id', $this->orgId())
            ->whereIn('po.status', ['confirmed', 'completed'])
            ->when($this->purchaseCreatorFilter(), fn ($q, $v) => $q->whereIn('po.created_by', $v))
            ->when($request->supplier_id, fn ($q, $v) => $q->where('po.company_id', $v))
            ->when($request->from, fn ($q, $v) => $q->where('po.order_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('po.order_date', '<=', $v));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function purchaseReportResponse($rows): JsonResponse
    {
        return response()->json([
            'data' => $rows,
            'total_quantity' => round($rows->sum('quantity'), 3),
            'total_amount' => round($rows->sum('amount'), 2),
            'total_with_tax' => round($rows->sum('total'), 2),
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function purchaseRow(array $base, object $r): array
    {
        $amount = round((float) $r->amount, 2);
        $total = round((float) $r->total, 2);

        return array_merge($base, [
            'order_count' => (int) $r->order_count,
            'quantity' => round((float) $r->quantity, 3),
            'amount' => $amount,
            'tax' => round($total - $amount, 2),
            'total' => $total,
        ]);
    }

    private function purchasesGroupedByProduct(Request $request): JsonResponse
    {
        $query = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->leftJoin('products as p', 'p.id', '=', 'poi.product_id')
            ->leftJoin('product_categories as cat', 'cat.id', '=', 'p.category_id')
            ->leftJoin('product_categories as parent', 'parent.id', '=', 'cat.parent_id');

        $rows = $this->applyPurchaseFilters($query, $request)
            ->when($request->category_id, fn ($q, $v) => $q->where('p.category_id', $v))
            ->groupBy('poi.product_id', 'p.code', 'p.name', 'p.unit', 'cat.name', 'parent.name')
            ->select([
                'poi.product_id',
                DB::raw("COALESCE(p.code, '') as product_code"),
                DB::raw("COALESCE(p.name, '') as product_name"),
                DB::raw("COALESCE(p.unit, '') as unit"),
                'cat.name as cat_name',
                'parent.name as parent_name',
                DB::raw('SUM(poi.quantity) as quantity'),
                DB::raw('COUNT(DISTINCT po.id) as order_count'),
                DB::raw('SUM(poi.amount) as amount'),
                DB::raw('SUM(poi.amount * (1 + poi.tax_rate / 100)) as total'),
            ])
            ->orderByDesc(DB::raw('SUM(poi.amount)'))
            ->get()
            ->map(function ($r) {
                $catLabel = $r->cat_name
                    ? ($r->parent_name ? $r->parent_name.' › '.$r->cat_name : $r->cat_name)
                    : null;

                return $this->purchaseRow([
                    'product_id' => $r->product_id,
                    'product_code' => $r->product_code,
                    'product_name' => $r->product_name,
                    'unit' => $r->unit,
                    'category' => $catLabel,
                ], $r);
            })
            ->filter(fn ($r) => $r['quantity'] != 0 || $r['amount'] != 0)
            ->values();

        return $this->purchaseReportResponse($rows);
    }

    private function purchasesGroupedBySupplier(Request $request): JsonResponse
    {
        $query = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->leftJoin('companies as c', 'c.id', '=', 'po.company_id');

        $rows = $this->applyPurchaseFilters($query, $request)
            ->groupBy('po.company_id', 'c.name')
            ->select([
                'po.company_id',
                DB::raw("COALESCE(c.name, '(Không rõ)') as company_name"),
                DB::raw('COUNT(DISTINCT po.id) as order_count'),
                DB::raw('SUM(poi.quantity) as quantity'),
                DB::raw('SUM(poi.amount) as amount'),
                DB::raw('SUM(poi.amount * (1 + poi.tax_rate / 100)) as total'),
            ])
            ->orderByDesc(DB::raw('SUM(poi.amount)'))
            ->get()
            ->map(fn ($r) => $this->purchaseRow([
                'company_id' => $r->company_id,
                'company_name' => $r->company_name,
            ], $r));

        return $this->purchaseReportResponse($rows);
    }

    private function purchasesGroupedByDate(Request $request): JsonResponse
    {
        $query = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id');

        $rows = $this->applyPurchaseFilters($query, $request)
            ->groupBy(DB::raw('DATE(po.order_date)'))
            ->select([
                DB::raw('DATE(po.order_date) as date'),
                DB::raw('COUNT(DISTINCT po.id) as order_count'),
                DB::raw('SUM(poi.quantity) as quantity'),
                DB::raw('SUM(poi.amount) as amount'),
                DB::raw('SUM(poi.amount * (1 + poi.tax_rate / 100)) as total'),
            ])
            ->orderBy(DB::raw('DATE(po.order_date)'))
            ->get()
            ->map(fn ($r) => $this->purchaseRow(['date' => $r->date], $r));

        return $this->purchaseReportResponse($rows);
    }

    private function purchasesGroupedByMonth(Request $request): JsonResponse
    {
        $query = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id');

        $rows = $this->applyPurchaseFilters($query, $request)
            ->groupBy(DB::raw("DATE_FORMAT(po.order_date, '%Y-%m')"))
            ->select([
                DB::raw("DATE_FORMAT(po.order_date, '%Y-%m') as month"),
                DB::raw('COUNT(DISTINCT po.id) as order_count'),
                DB::raw('SUM(poi.quantity) as quantity'),
                DB::raw('SUM(poi.amount) as amount'),
                DB::raw('SUM(poi.amount * (1 + poi.tax_rate / 100)) as total'),
            ])
            ->orderBy(DB::raw("DATE_FORMAT(po.order_date, '%Y-%m')"))
            ->get()
            ->map(fn ($r) => $this->purchaseRow(['month' => $r->month], $r));

        return $this->purchaseReportResponse($rows);
    }

    private function purchasesGroupedByCategory(Request $request): JsonResponse
    {
        $query = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->join('products as p', 'p.id', '=', 'poi.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->leftJoin('product_categories as parent_pc', 'parent_pc.id', '=', 'pc.parent_id');

        $rows = $this->applyPurchaseFilters($query, $request)
            ->groupBy('pc.id', 'pc.name', 'parent_pc.name')
            ->select([
                DB::raw("COALESCE(CASE WHEN parent_pc.name IS NOT NULL THEN CONCAT(parent_pc.name, ' › ', pc.name) ELSE pc.name END, '(Chưa phân loại)') as category_name"),
                DB::raw('COUNT(DISTINCT po.id) as order_count'),
                DB::raw('SUM(poi.quantity) as quantity'),
                DB::raw('SUM(poi.amount) as amount'),
                DB::raw('SUM(poi.amount * (1 + poi.tax_rate / 100)) as total'),
            ])
            ->orderByDesc(DB::raw('SUM(poi.amount)'))
            ->get()
            ->map(fn ($r) => $this->purchaseRow(['category_name' => $r->category_name], $r));

        return $this->purchaseReportResponse($rows);
    }

    /**
     * Báo cáo hiệu quả khuyến mại theo kỳ.
     * - Giảm giá đơn (order_discount): số đơn áp dụng + tổng giảm giá + doanh thu đơn áp dụng.
     * - Mua X tặng Y (buy_x_get_y): số đơn + số lượng & giá trị (giá vốn) hàng tặng.
     */
    public function promotions(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $from = $request->from;
        $to = $request->to;
        $statuses = ['confirmed', 'shipping', 'completed'];

        $promotions = Promotion::where('organization_id', $orgId)->get();

        // Giảm giá cấp đơn: gom theo promotion_id
        $orderStats = DB::table('sales_orders')
            ->where('organization_id', $orgId)
            ->whereNotNull('promotion_id')
            ->whereIn('status', $statuses)
            ->when($from, fn ($q, $v) => $q->where('order_date', '>=', $v))
            ->when($to, fn ($q, $v) => $q->where('order_date', '<=', $v))
            ->groupBy('promotion_id')
            ->selectRaw('promotion_id, COUNT(*) as order_count, COALESCE(SUM(discount_amount),0) as discount_total, COALESCE(SUM(total_amount),0) as revenue')
            ->get()
            ->keyBy('promotion_id');

        // Mua X tặng Y: thống kê hàng tặng (đơn giá 0) theo sản phẩm tặng
        $promoGiftMap = $promotions->where('type', 'buy_x_get_y')
            ->mapWithKeys(fn ($p) => [$p->id => $this->promoGiftIds($p)])
            ->filter(fn ($ids) => ! empty($ids));
        $allGiftIds = $promoGiftMap->flatten()->unique()->values()->all();

        $giftStats = ! empty($allGiftIds)
            ? DB::table('sales_order_items as soi')
                ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
                ->where('so.organization_id', $orgId)
                ->whereIn('so.status', $statuses)
                ->whereIn('soi.product_id', $allGiftIds)
                ->where('soi.unit_price', 0)
                ->when($from, fn ($q, $v) => $q->where('so.order_date', '>=', $v))
                ->when($to, fn ($q, $v) => $q->where('so.order_date', '<=', $v))
                ->groupBy('soi.product_id')
                ->selectRaw('soi.product_id, SUM(soi.quantity) as qty, SUM(soi.quantity * soi.cost_price) as value, COUNT(DISTINCT soi.sales_order_id) as order_count')
                ->get()
                ->keyBy('product_id')
            : collect();

        $rows = $promotions->map(function ($p) use ($orderStats, $promoGiftMap, $giftStats) {
            $orderCount = 0;
            $discountTotal = 0.0;
            $revenue = 0.0;
            $giftQty = 0.0;
            $giftValue = 0.0;

            $type = $p->type instanceof \BackedEnum ? $p->type->value : $p->type;

            if ($type === 'order_discount') {
                $s = $orderStats[$p->id] ?? null;
                $orderCount = (int) ($s->order_count ?? 0);
                $discountTotal = round((float) ($s->discount_total ?? 0));
                $revenue = round((float) ($s->revenue ?? 0));
            } elseif ($type === 'buy_x_get_y') {
                foreach ($promoGiftMap[$p->id] ?? [] as $gid) {
                    if (isset($giftStats[$gid])) {
                        $giftQty += (float) $giftStats[$gid]->qty;
                        $giftValue += (float) $giftStats[$gid]->value;
                        $orderCount = max($orderCount, (int) $giftStats[$gid]->order_count);
                    }
                }
                $giftValue = round($giftValue);
            }

            return [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $type,
                'is_active' => (bool) $p->is_active,
                'order_count' => $orderCount,
                'discount_total' => $discountTotal,
                'revenue' => $revenue,
                'gift_qty' => round($giftQty, 3),
                'gift_value' => $giftValue,
            ];
        })
            ->filter(fn ($r) => $r['order_count'] > 0 || $r['discount_total'] > 0 || $r['gift_qty'] > 0)
            ->values();

        return response()->json([
            'data' => $rows,
            'total_orders' => (int) $rows->sum('order_count'),
            'total_discount' => round($rows->sum('discount_total')),
            'total_gift_value' => round($rows->sum('gift_value')),
        ]);
    }

    /**
     * Lấy danh sách sản phẩm tặng của 1 khuyến mại (hỗ trợ định dạng nhiều quy tắc lẫn cũ).
     *
     * @return array<int, int>
     */
    private function promoGiftIds(Promotion $promotion): array
    {
        $conditions = $promotion->conditions ?? [];

        if (! empty($conditions['rules'])) {
            return collect($conditions['rules'])->pluck('gift_product_id')->filter()->unique()->values()->all();
        }

        $id = $conditions['gift_product_id'] ?? null;

        return $id ? [(int) $id] : [];
    }

    public function soldProducts(Request $request): JsonResponse
    {
        $orgId = $this->orgId();

        $rows = DB::table('sales_order_items as soi')
            ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
            ->join('products as p', 'p.id', '=', 'soi.product_id')
            ->leftJoin('product_categories as cat', 'cat.id', '=', 'p.category_id')
            ->leftJoin('product_categories as parent_cat', 'parent_cat.id', '=', 'cat.parent_id')
            ->where('so.organization_id', $orgId)
            ->whereIn('so.status', ['confirmed', 'shipping', 'completed'])
            ->when($this->salesCreatorFilter(), fn ($q, $v) => $q->whereIn('so.created_by', $v))
            ->where('soi.is_return', false)
            ->when($request->from, fn ($q, $v) => $q->where('so.order_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('so.order_date', '<=', $v))
            ->when($request->company_id, fn ($q, $v) => $q->where('so.company_id', $v))
            ->when($request->category_id, fn ($q, $v) => $q->where('p.category_id', $v))
            ->groupBy('p.id', 'p.code', 'p.name', 'p.unit', 'cat.name', 'parent_cat.name')
            ->select([
                'p.id as product_id',
                'p.code as product_code',
                'p.name as product_name',
                'p.unit',
                DB::raw('CASE WHEN parent_cat.name IS NOT NULL THEN CONCAT(parent_cat.name, \' › \', cat.name) ELSE cat.name END as category_name'),
                DB::raw('SUM(soi.quantity) as quantity'),
                DB::raw('COUNT(DISTINCT so.id) as order_count'),
                DB::raw('SUM(soi.amount - soi.order_discount_alloc) as amount'),
            ])
            ->orderByDesc(DB::raw('SUM(soi.quantity)'))
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'product_code' => $r->product_code,
                'product_name' => $r->product_name,
                'unit' => $r->unit,
                'category_name' => $r->category_name,
                'quantity' => round((float) $r->quantity, 3),
                'order_count' => (int) $r->order_count,
                'amount' => round((float) $r->amount, 2),
            ]);

        return response()->json([
            'data' => $rows,
            'total_quantity' => round($rows->sum('quantity'), 3),
            'total_amount' => round($rows->sum('amount'), 2),
        ]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $asOf = $request->as_of ?? now()->toDateString();

        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jel.account_id')
            ->where('je.organization_id', $orgId)
            ->where('je.entry_date', '<=', $asOf)
            ->whereIn('a.type', ['asset', 'liability', 'equity'])
            ->selectRaw('a.code, a.name, a.type, SUM(jel.debit_amount) as total_debit, SUM(jel.credit_amount) as total_credit')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->get()
            ->map(function ($r) {
                $balance = $r->type === 'asset'
                    ? (float) $r->total_debit - (float) $r->total_credit
                    : (float) $r->total_credit - (float) $r->total_debit;

                return ['code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'balance' => $balance];
            })
            ->filter(fn ($r) => $r['balance'] != 0)
            ->values();

        $netProfit = $this->calculateNetProfit($orgId, null, $asOf);
        $section = fn ($prefix) => $rows->filter(fn ($r) => str_starts_with($r['code'], $prefix))->values();

        return response()->json([
            'as_of' => $asOf,
            'current_assets' => $section('1'),
            'non_current_assets' => $section('2'),
            'liabilities' => $section('3'),
            'equity' => $section('4'),
            'net_profit' => $netProfit,
            'totals' => [
                'total_assets' => $rows->where('type', 'asset')->sum('balance'),
                'total_liabilities' => $rows->where('type', 'liability')->sum('balance'),
                'total_equity' => $rows->where('type', 'equity')->sum('balance') + $netProfit,
            ],
        ]);
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $from = $request->from;
        $to = $request->to ?? now()->toDateString();

        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jel.account_id')
            ->where('je.organization_id', $orgId)
            ->when($from, fn ($q) => $q->where('je.entry_date', '>=', $from))
            ->where('je.entry_date', '<=', $to)
            ->whereIn('a.type', ['revenue', 'expense'])
            ->selectRaw('a.code, a.name, a.type, SUM(jel.debit_amount) as total_debit, SUM(jel.credit_amount) as total_credit')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->get()
            ->mapWithKeys(function ($r) {
                $balance = $r->type === 'revenue'
                    ? (float) $r->total_credit - (float) $r->total_debit
                    : (float) $r->total_debit - (float) $r->total_credit;

                return [$r->code => $balance];
            });

        $sumPrefix = fn ($prefix) => $rows
            ->filter(fn ($v, $k) => str_starts_with((string) $k, $prefix))
            ->sum();

        $salesRevenue = $sumPrefix('511');
        $financialRevenue = $sumPrefix('515');
        // 521/531/532 là tài khoản điều chỉnh doanh thu (dư Nợ), balance = credit-debit < 0
        // Negate để hiển thị dương và trừ đúng hướng
        $deductions = -($sumPrefix('521') + $sumPrefix('531') + $sumPrefix('532'));
        $netRevenue = $salesRevenue - $deductions;
        $cogs = $sumPrefix('632');
        $grossProfit = $netRevenue - $cogs;
        $financialExpense = $sumPrefix('635');
        $sellingExpense = $sumPrefix('641');
        $adminExpense = $sumPrefix('642');
        $productionOverhead = $sumPrefix('627');

        // Các TK chi phí loại 6 chưa được phân loại (628, 623, v.v.) — chỉ lấy 6xx, tránh nhầm 7xx
        $knownExpensePrefixes = ['632', '635', '641', '642', '627', '821', '811'];
        $otherOperatingExpense = $rows
            ->filter(function ($_, $k) use ($knownExpensePrefixes) {
                $code = (string) $k;

                return str_starts_with($code, '6')
                    && ! collect($knownExpensePrefixes)->contains(fn ($p) => str_starts_with($code, $p));
            })
            ->sum();

        $operatingProfit = $grossProfit + $financialRevenue - $financialExpense
            - $sellingExpense - $adminExpense - $productionOverhead - $otherOperatingExpense;
        $otherIncome = $sumPrefix('711');
        $otherExpense = $sumPrefix('811');
        $otherProfit = $otherIncome - $otherExpense;
        $profitBeforeTax = $operatingProfit + $otherProfit;
        $incomeTax = $sumPrefix('821');
        $netProfit = $profitBeforeTax - $incomeTax;

        $lines = [
            ['code' => '01', 'label' => 'Doanh thu bán hàng và cung cấp dịch vụ', 'value' => $salesRevenue],
            ['code' => '02', 'label' => 'Các khoản giảm trừ doanh thu', 'value' => $deductions],
            ['code' => '10', 'label' => 'Doanh thu thuần về bán hàng và CCDV', 'value' => $netRevenue, 'bold' => true],
            ['code' => '11', 'label' => 'Giá vốn hàng bán', 'value' => $cogs],
            ['code' => '20', 'label' => 'Lợi nhuận gộp về bán hàng và CCDV', 'value' => $grossProfit, 'bold' => true],
            ['code' => '21', 'label' => 'Doanh thu hoạt động tài chính', 'value' => $financialRevenue],
            ['code' => '22', 'label' => 'Chi phí tài chính', 'value' => $financialExpense],
            ['code' => '25', 'label' => 'Chi phí bán hàng', 'value' => $sellingExpense],
            ['code' => '26', 'label' => 'Chi phí quản lý doanh nghiệp', 'value' => $adminExpense],
        ];

        if ($productionOverhead != 0) {
            $lines[] = ['code' => '27', 'label' => 'Chi phí sản xuất chung (627)', 'value' => $productionOverhead];
        }

        if ($otherOperatingExpense != 0) {
            $lines[] = ['code' => '28', 'label' => 'Chi phí hoạt động khác', 'value' => $otherOperatingExpense];
        }

        $lines = array_merge($lines, [
            ['code' => '30', 'label' => 'Lợi nhuận thuần từ hoạt động kinh doanh', 'value' => $operatingProfit, 'bold' => true],
            ['code' => '31', 'label' => 'Thu nhập khác', 'value' => $otherIncome],
            ['code' => '32', 'label' => 'Chi phí khác', 'value' => $otherExpense],
            ['code' => '40', 'label' => 'Lợi nhuận khác', 'value' => $otherProfit, 'bold' => true],
            ['code' => '50', 'label' => 'Tổng lợi nhuận kế toán trước thuế', 'value' => $profitBeforeTax, 'bold' => true],
            ['code' => '51', 'label' => 'Chi phí thuế thu nhập doanh nghiệp', 'value' => $incomeTax],
            ['code' => '60', 'label' => 'Lợi nhuận sau thuế thu nhập doanh nghiệp', 'value' => $netProfit, 'bold' => true, 'highlight' => true],
        ]);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'lines' => $lines,
            'net_profit' => $netProfit,
        ]);
    }

    public function cashbook(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $dateFrom = $request->date_from;
        $dateTo = $request->date_to;

        $accountQuery = Account::where(fn ($q) => $q->where('code', 'like', '111%')->orWhere('code', 'like', '112%'));
        if ($request->filled('account_id')) {
            // Chọn tài khoản cha → gồm cả tài khoản con (mã con bắt đầu bằng mã cha theo chuẩn VAS)
            $selected = Account::find($request->account_id);
            if ($selected) {
                $accountQuery->where('code', 'like', $selected->code.'%');
            } else {
                $accountQuery->where('id', $request->account_id);
            }
        }
        $accountIds = $accountQuery->pluck('id');

        $openingBalance = 0.0;
        if ($dateFrom) {
            $openingThu = Payment::whereIn('account_id', $accountIds)
                ->where('type', PaymentType::Receipt)
                ->where('payment_date', '<', $dateFrom)
                ->sum('amount');
            // Chuyển tiền đến TK này = thu
            $openingTransferIn = Payment::whereIn('to_account_id', $accountIds)
                ->where('type', PaymentType::Transfer)
                ->where('payment_date', '<', $dateFrom)
                ->sum('amount');
            $openingChi = Payment::whereIn('account_id', $accountIds)
                ->where('type', PaymentType::Payment)
                ->where('payment_date', '<', $dateFrom)
                ->sum('amount');
            // Chuyển tiền đi từ TK này = chi
            $openingTransferOut = Payment::whereIn('account_id', $accountIds)
                ->where('type', PaymentType::Transfer)
                ->where('payment_date', '<', $dateFrom)
                ->sum('amount');
            $openingBalance = (float) $openingThu + (float) $openingTransferIn
                - (float) $openingChi - (float) $openingTransferOut;
        }

        // Phiếu thông thường và chuyển tiền đi (account_id match)
        $outEntries = Payment::with(['company', 'account', 'toAccount'])
            ->whereIn('account_id', $accountIds)
            ->when($dateFrom, fn ($q, $v) => $q->where('payment_date', '>=', $v))
            ->when($dateTo, fn ($q, $v) => $q->where('payment_date', '<=', $v))
            ->get()
            ->each(fn ($p) => $p->_inflow = false);

        // Chuyển tiền đến (to_account_id match) — đây là phần bị bỏ sót
        $inEntries = Payment::with(['company', 'account', 'toAccount'])
            ->whereIn('to_account_id', $accountIds)
            ->where('type', PaymentType::Transfer)
            ->when($dateFrom, fn ($q, $v) => $q->where('payment_date', '>=', $v))
            ->when($dateTo, fn ($q, $v) => $q->where('payment_date', '<=', $v))
            ->get()
            ->each(fn ($p) => $p->_inflow = true);

        $entries = $outEntries->merge($inEntries)
            ->sortBy([['payment_date', 'asc'], ['id', 'asc']])
            ->values();

        // Tài khoản đối ứng cho phiếu thu/chi thông thường
        $regularIds = $outEntries->where('type', '!=', PaymentType::Transfer)->pluck('id');
        $counterpartMap = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->join('accounts as a', 'a.id', '=', 'jel.account_id')
            ->where('je.reference_type', Payment::class)
            ->whereIn('je.reference_id', $regularIds)
            ->where('a.code', 'not like', '111%')
            ->where('a.code', 'not like', '112%')
            ->select('je.reference_id as payment_id', 'a.code', 'a.name')
            ->get()
            ->keyBy('payment_id');

        // Mỗi movement: ['date', 'sort_id', 'row' => [...]] — gộp payment + bút toán thủ công rồi tính số dư một lượt
        $movements = $entries->map(function (Payment $p) use ($counterpartMap) {
            $amount = (float) $p->amount;
            $type = $p->type instanceof PaymentType ? $p->type : PaymentType::from($p->type->value ?? $p->type);
            $isInflow = $type === PaymentType::Receipt
                || ($type === PaymentType::Transfer && $p->_inflow);

            $thu = $isInflow ? $amount : 0.0;
            $chi = $isInflow ? 0.0 : $amount;

            if ($type === PaymentType::Transfer) {
                $counterAccount = $p->_inflow ? $p->account : $p->toAccount;
                $cp = $counterAccount ? (object) ['code' => $counterAccount->code, 'name' => $counterAccount->name] : null;
            } else {
                $cp = $counterpartMap->get($p->id);
            }

            $displayAccount = $p->_inflow ? $p->toAccount : $p->account;

            return [
                'date' => $p->payment_date->toDateString(),
                'sort_id' => $p->id,
                'row' => [
                    'id' => 'P'.$p->id,
                    'payment_id' => $p->id,
                    'payment_number' => $p->payment_number,
                    'payment_date' => $p->payment_date->toDateString(),
                    'description' => $p->description,
                    'company' => $p->company?->name,
                    'account_code' => $displayAccount?->code,
                    'account_name' => $displayAccount?->name,
                    'counterpart_code' => $cp?->code,
                    'counterpart_name' => $cp?->name,
                    'thu' => $thu,
                    'chi' => $chi,
                ],
            ];
        })->all();

        // Bút toán thủ công (và các bút toán không qua phiếu thu/chi) có phát sinh trên TK tiền 111/112
        $manualMovements = $this->cashbookManualMovements($accountIds, $orgId, $dateFrom, $dateTo);
        $movements = array_merge($movements, $manualMovements);

        // Cộng số dư đầu kỳ phần bút toán thủ công trước dateFrom
        if ($dateFrom) {
            $openingBalance += $this->cashbookManualOpening($accountIds, $orgId, $dateFrom);
        }

        // Sắp xếp theo ngày rồi id, tính số dư lũy kế
        usort($movements, function ($a, $b) {
            return [$a['date'], $a['sort_id']] <=> [$b['date'], $b['sort_id']];
        });

        $balance = $openingBalance;
        $totalThu = 0.0;
        $totalChi = 0.0;

        $rows = array_map(function ($m) use (&$balance, &$totalThu, &$totalChi) {
            $totalThu += $m['row']['thu'];
            $totalChi += $m['row']['chi'];
            $balance += $m['row']['thu'] - $m['row']['chi'];

            return [...$m['row'], 'balance' => round($balance, 2)];
        }, $movements);

        return response()->json([
            'opening_balance' => round($openingBalance, 2),
            'total_thu' => round($totalThu, 2),
            'total_chi' => round($totalChi, 2),
            'closing_balance' => round($balance, 2),
            'data' => $rows,
        ]);
    }

    /**
     * Lấy phát sinh tiền (111/112) từ bút toán KHÔNG gắn với phiếu thu/chi (bút toán thủ công).
     *
     * @param  Collection<int, int>  $accountIds
     * @return array<int, array{date: string, sort_id: int, row: array<string, mixed>}>
     */
    private function cashbookManualMovements($accountIds, int $orgId, ?string $dateFrom, ?string $dateTo): array
    {
        $lines = JournalEntryLine::with(['account', 'journalEntry'])
            ->whereIn('account_id', $accountIds)
            ->whereHas('journalEntry', function ($q) use ($orgId, $dateFrom, $dateTo) {
                $q->where('organization_id', $orgId)
                    ->whereNull('reference_type')
                    ->when($dateFrom, fn ($q2, $v) => $q2->where('entry_date', '>=', $v))
                    ->when($dateTo, fn ($q2, $v) => $q2->where('entry_date', '<=', $v));
            })
            ->get();

        // Nạp tài khoản đối ứng theo lô (tránh N+1): 1 truy vấn cho mọi bút toán liên quan
        $entryIds = $lines->pluck('journal_entry_id')->unique()->all();
        $counterByEntry = JournalEntryLine::with('account')
            ->whereIn('journal_entry_id', $entryIds)
            ->whereNotIn('account_id', $accountIds->all())
            ->get()
            ->groupBy('journal_entry_id');

        return $lines->map(function (JournalEntryLine $line) use ($counterByEntry) {
            $entry = $line->journalEntry;
            $thu = (float) $line->debit_amount;   // Nợ TK tiền = tiền vào
            $chi = (float) $line->credit_amount;  // Có TK tiền = tiền ra

            // Tài khoản đối ứng: dòng khác trong cùng bút toán, không thuộc TK tiền
            $counter = $counterByEntry->get($line->journal_entry_id)?->first();

            return [
                'date' => $entry->entry_date instanceof Carbon
                    ? $entry->entry_date->toDateString()
                    : (string) $entry->entry_date,
                'sort_id' => $line->id,
                'row' => [
                    'id' => 'J'.$line->id,
                    'payment_number' => $entry->entry_number,
                    'payment_date' => $entry->entry_date instanceof Carbon
                        ? $entry->entry_date->toDateString()
                        : (string) $entry->entry_date,
                    'description' => $line->description ?: $entry->description,
                    'company' => null,
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'counterpart_code' => $counter?->account?->code,
                    'counterpart_name' => $counter?->account?->name,
                    'thu' => $thu,
                    'chi' => $chi,
                ],
            ];
        })->all();
    }

    /**
     * Số dư đầu kỳ từ bút toán thủ công trên TK tiền trước ngày dateFrom.
     *
     * @param  Collection<int, int>  $accountIds
     */
    private function cashbookManualOpening($accountIds, int $orgId, string $dateFrom): float
    {
        $agg = JournalEntryLine::whereIn('account_id', $accountIds)
            ->whereHas('journalEntry', function ($q) use ($orgId, $dateFrom) {
                $q->where('organization_id', $orgId)
                    ->whereNull('reference_type')
                    ->where('entry_date', '<', $dateFrom);
            })
            ->selectRaw('COALESCE(SUM(debit_amount),0) as d, COALESCE(SUM(credit_amount),0) as c')
            ->first();

        return (float) $agg->d - (float) $agg->c;
    }

    public function salesByEmployee(Request $request): JsonResponse
    {
        $orgId = $this->orgId();
        $showProfit = $this->canViewProfit();

        $rows = DB::table('sales_orders as so')
            ->join('users as u', 'u.id', '=', 'so.created_by')
            ->leftJoin('sales_order_items as soi', function ($j) {
                $j->on('soi.sales_order_id', '=', 'so.id')->where('soi.is_return', false);
            })
            ->where('so.organization_id', $orgId)
            ->whereIn('so.status', ['confirmed', 'shipping', 'completed'])
            ->when($this->salesCreatorFilter(), fn ($q, $v) => $q->whereIn('so.created_by', $v))
            ->when($request->from, fn ($q, $v) => $q->where('so.order_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->where('so.order_date', '<=', $v))
            ->groupBy('u.id', 'u.name', 'u.department', 'u.position')
            ->select([
                'u.id as user_id',
                'u.name',
                'u.department',
                'u.position',
                DB::raw('COUNT(DISTINCT so.id) as order_count'),
                DB::raw('SUM(((soi.amount - soi.order_discount_alloc))) as revenue'),
                DB::raw('SUM(soi.quantity * soi.cost_price) as cost'),
                DB::raw('SUM(CASE WHEN soi.standard_price > 0 THEN soi.quantity * soi.standard_price ELSE 0 END) as standard_total'),
            ])
            ->orderByDesc(DB::raw('SUM(((soi.amount - soi.order_discount_alloc)))'))
            ->get()
            ->map(function ($r) use ($showProfit) {
                $revenue = round((float) $r->revenue, 2);
                $cost = round((float) $r->cost, 2);
                $profit = round($revenue - $cost, 2);
                $standardTotal = round((float) $r->standard_total, 2);

                $row = [
                    'user_id' => $r->user_id,
                    'name' => $r->name,
                    'department' => $r->department,
                    'position' => $r->position,
                    'order_count' => (int) $r->order_count,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $profit,
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
                    'standard_total' => $standardTotal,
                    'employee_profit' => $standardTotal > 0 ? round($revenue - $standardTotal, 2) : null,
                ];

                return $showProfit ? $row : $this->stripProfitFields($row);
            });

        return response()->json([
            'data' => $rows,
            'total_revenue' => round($rows->sum('revenue'), 2),
            ...($showProfit ? [
                'total_cost' => round($rows->sum('cost'), 2),
                'gross_profit' => round($rows->sum('profit'), 2),
            ] : []),
        ]);
    }

    private function calculateNetProfit(int $orgId, ?string $from, string $to): float
    {
        $result = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jel.account_id')
            ->where('je.organization_id', $orgId)
            ->when($from, fn ($q) => $q->where('je.entry_date', '>=', $from))
            ->where('je.entry_date', '<=', $to)
            ->whereIn('a.type', ['revenue', 'expense'])
            ->selectRaw('a.type, SUM(jel.debit_amount) as total_debit, SUM(jel.credit_amount) as total_credit')
            ->groupBy('a.type')
            ->get()
            ->keyBy('type');

        $rev = $result['revenue'] ?? null;
        $exp = $result['expense'] ?? null;
        $revenue = $rev ? (float) $rev->total_credit - (float) $rev->total_debit : 0;
        $expense = $exp ? (float) $exp->total_debit - (float) $exp->total_credit : 0;

        return $revenue - $expense;
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $orgId = $this->orgId();

        $accounts = Account::whereHas('journalEntryLines', fn ($q) => $q->whereHas('journalEntry', fn ($jq) => $jq->where('organization_id', $orgId)))
            ->orderBy('code')
            ->get();

        $rows = $accounts->map(function (Account $account) use ($request, $orgId) {
            $allLines = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', fn ($q) => $q->where('organization_id', $orgId))
                ->with('journalEntry')
                ->get();

            $beforeLines = $request->from
                ? $allLines->filter(fn ($l) => $l->journalEntry->entry_date->toDateString() < $request->from)
                : collect();

            $openingNet = $beforeLines->sum(fn ($l) => (float) $l->debit_amount)
                - $beforeLines->sum(fn ($l) => (float) $l->credit_amount);

            $periodLines = $allLines->when(
                $request->from,
                fn ($c) => $c->filter(fn ($l) => $l->journalEntry->entry_date->toDateString() >= $request->from)
            )->when(
                $request->to,
                fn ($c) => $c->filter(fn ($l) => $l->journalEntry->entry_date->toDateString() <= $request->to)
            );

            $periodDebit = $periodLines->sum(fn ($l) => (float) $l->debit_amount);
            $periodCredit = $periodLines->sum(fn ($l) => (float) $l->credit_amount);

            $closingNet = $openingNet + $periodDebit - $periodCredit;

            return [
                'code' => $account->code,
                'name' => $account->name,
                'opening_debit' => $openingNet > 0 ? $openingNet : 0,
                'opening_credit' => $openingNet < 0 ? -$openingNet : 0,
                'period_debit' => $periodDebit,
                'period_credit' => $periodCredit,
                'closing_debit' => $closingNet > 0 ? $closingNet : 0,
                'closing_credit' => $closingNet < 0 ? -$closingNet : 0,
            ];
        });

        return response()->json([
            'data' => $rows,
            'totals' => [
                'opening_debit' => $rows->sum('opening_debit'),
                'opening_credit' => $rows->sum('opening_credit'),
                'period_debit' => $rows->sum('period_debit'),
                'period_credit' => $rows->sum('period_credit'),
                'closing_debit' => $rows->sum('closing_debit'),
                'closing_credit' => $rows->sum('closing_credit'),
            ],
        ]);
    }
}
