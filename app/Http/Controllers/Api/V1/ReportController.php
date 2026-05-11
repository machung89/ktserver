<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentType;
use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use ScopedByOrganization;

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
        $orders = SalesOrder::with('company')
            ->whereIn('status', ['confirmed', 'completed'])
            ->get()
            ->groupBy('company_id');

        $receipts = Payment::where('type', 'receipt')
            ->get()
            ->groupBy('company_id');

        $companies = Company::whereIn('id', $orders->keys())->get()->keyBy('id');

        $data = $orders->map(function ($companyOrders, $companyId) use ($receipts, $companies) {
            $totalSales = $companyOrders->sum(fn ($o) => (float) $o->total_amount);
            $totalReceipts = ($receipts[$companyId] ?? collect())->sum(fn ($p) => (float) $p->amount);

            return [
                'company_name' => $companies[$companyId]->name ?? '',
                'total_sales' => $totalSales,
                'total_receipts' => $totalReceipts,
                'balance' => $totalSales - $totalReceipts,
            ];
        })->values()->filter(fn ($r) => $r['balance'] > 0)->values();

        return response()->json(['data' => $data, 'total_balance' => $data->sum('balance')]);
    }

    public function payables(): JsonResponse
    {
        $orders = PurchaseOrder::with('company')
            ->whereIn('status', ['confirmed', 'completed'])
            ->get()
            ->groupBy('company_id');

        $payments = Payment::where('type', 'payment')
            ->get()
            ->groupBy('company_id');

        $companies = Company::whereIn('id', $orders->keys())->get()->keyBy('id');

        $data = $orders->map(function ($companyOrders, $companyId) use ($payments, $companies) {
            $totalPurchases = $companyOrders->sum(fn ($o) => (float) $o->total_amount);
            $totalPayments = ($payments[$companyId] ?? collect())->sum(fn ($p) => (float) $p->amount);

            return [
                'company_name' => $companies[$companyId]->name ?? '',
                'total_purchases' => $totalPurchases,
                'total_payments' => $totalPayments,
                'balance' => $totalPurchases - $totalPayments,
            ];
        })->values()->filter(fn ($r) => $r['balance'] > 0)->values();

        return response()->json(['data' => $data, 'total_balance' => $data->sum('balance')]);
    }

    public function sales(Request $request): JsonResponse
    {
        $items = SalesOrderItem::with(['product.category.parent', 'salesOrder'])
            ->whereHas('salesOrder', fn ($q) => $q->whereIn('status', ['confirmed', 'shipping', 'completed'])
                ->when($request->from, fn ($q2, $v) => $q2->whereDate('order_date', '>=', $v))
                ->when($request->to, fn ($q2, $v) => $q2->whereDate('order_date', '<=', $v))
            )
            ->when($request->category_id, fn ($q, $v) => $q->whereHas('product', fn ($pq) => $pq->where('category_id', $v)))
            ->get();

        $grouped = $items->groupBy('product_id')->map(function ($rows) {
            $first = $rows->first();
            $product = $first->product;
            $qty = $rows->sum(fn ($r) => (float) $r->quantity);
            $revenue = $rows->sum(fn ($r) => (float) $r->amount);
            $cost = $rows->sum(fn ($r) => (float) $r->quantity * (float) $r->cost_price);

            $cat = $product?->category;
            $catLabel = $cat
                ? ($cat->parent ? $cat->parent->name.' › '.$cat->name : $cat->name)
                : null;

            return [
                'product_code' => $product?->code ?? '',
                'product_name' => $product?->name ?? '',
                'unit' => $product?->unit ?? '',
                'category' => $catLabel,
                'quantity' => round($qty, 3),
                'revenue' => round($revenue, 2),
                'cost' => round($cost, 2),
                'profit' => round($revenue - $cost, 2),
                'margin' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : 0,
            ];
        })
            ->filter(fn ($r) => $r['quantity'] != 0 || $r['revenue'] != 0)
            ->sortByDesc('revenue')
            ->values();

        return response()->json([
            'data' => $grouped,
            'total_revenue' => round($grouped->sum('revenue'), 2),
            'total_cost' => round($grouped->sum('cost'), 2),
            'gross_profit' => round($grouped->sum('profit'), 2),
        ]);
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
            ->where('soi.is_return', false)
            ->when($request->from, fn ($q, $v) => $q->whereDate('so.order_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('so.order_date', '<=', $v))
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
                DB::raw('SUM(soi.amount) as amount'),
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
            ->whereDate('je.entry_date', '<=', $asOf)
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
            ->when($from, fn ($q) => $q->whereDate('je.entry_date', '>=', $from))
            ->whereDate('je.entry_date', '<=', $to)
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
        $deductions = $sumPrefix('521') + $sumPrefix('531') + $sumPrefix('532');
        $netRevenue = $salesRevenue - $deductions;
        $cogs = $sumPrefix('632');
        $grossProfit = $netRevenue - $cogs;
        $financialExpense = $sumPrefix('635');
        $sellingExpense = $sumPrefix('641');
        $adminExpense = $sumPrefix('642');
        $operatingProfit = $grossProfit + $financialRevenue - $financialExpense - $sellingExpense - $adminExpense;
        $otherIncome = $sumPrefix('711');
        $otherExpense = $sumPrefix('811');
        $otherProfit = $otherIncome - $otherExpense;
        $profitBeforeTax = $operatingProfit + $otherProfit;
        $incomeTax = $sumPrefix('821');
        $netProfit = $profitBeforeTax - $incomeTax;

        return response()->json([
            'from' => $from,
            'to' => $to,
            'lines' => [
                ['code' => '01', 'label' => 'Doanh thu bán hàng và cung cấp dịch vụ', 'value' => $salesRevenue],
                ['code' => '02', 'label' => 'Các khoản giảm trừ doanh thu', 'value' => $deductions],
                ['code' => '10', 'label' => 'Doanh thu thuần về bán hàng và CCDV', 'value' => $netRevenue, 'bold' => true],
                ['code' => '11', 'label' => 'Giá vốn hàng bán', 'value' => $cogs],
                ['code' => '20', 'label' => 'Lợi nhuận gộp về bán hàng và CCDV', 'value' => $grossProfit, 'bold' => true],
                ['code' => '21', 'label' => 'Doanh thu hoạt động tài chính', 'value' => $financialRevenue],
                ['code' => '22', 'label' => 'Chi phí tài chính', 'value' => $financialExpense],
                ['code' => '25', 'label' => 'Chi phí bán hàng', 'value' => $sellingExpense],
                ['code' => '26', 'label' => 'Chi phí quản lý doanh nghiệp', 'value' => $adminExpense],
                ['code' => '30', 'label' => 'Lợi nhuận thuần từ hoạt động kinh doanh', 'value' => $operatingProfit, 'bold' => true],
                ['code' => '31', 'label' => 'Thu nhập khác', 'value' => $otherIncome],
                ['code' => '32', 'label' => 'Chi phí khác', 'value' => $otherExpense],
                ['code' => '40', 'label' => 'Lợi nhuận khác', 'value' => $otherProfit, 'bold' => true],
                ['code' => '50', 'label' => 'Tổng lợi nhuận kế toán trước thuế', 'value' => $profitBeforeTax, 'bold' => true],
                ['code' => '51', 'label' => 'Chi phí thuế thu nhập doanh nghiệp', 'value' => $incomeTax],
                ['code' => '60', 'label' => 'Lợi nhuận sau thuế thu nhập doanh nghiệp', 'value' => $netProfit, 'bold' => true, 'highlight' => true],
            ],
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
            $accountQuery->where('id', $request->account_id);
        }
        $accountIds = $accountQuery->pluck('id');

        $openingBalance = 0.0;
        if ($dateFrom) {
            $openingThu = Payment::whereIn('account_id', $accountIds)
                ->where('type', PaymentType::Receipt)
                ->whereDate('payment_date', '<', $dateFrom)
                ->sum('amount');
            $openingChi = Payment::whereIn('account_id', $accountIds)
                ->where('type', PaymentType::Payment)
                ->whereDate('payment_date', '<', $dateFrom)
                ->sum('amount');
            $openingBalance = (float) $openingThu - (float) $openingChi;
        }

        $entries = Payment::with(['company', 'account'])
            ->whereIn('account_id', $accountIds)
            ->when($dateFrom, fn ($q, $v) => $q->whereDate('payment_date', '>=', $v))
            ->when($dateTo, fn ($q, $v) => $q->whereDate('payment_date', '<=', $v))
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        // Single query to get the counterpart account for each payment
        $counterpartMap = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->join('accounts as a', 'a.id', '=', 'jel.account_id')
            ->where('je.reference_type', Payment::class)
            ->whereIn('je.reference_id', $entries->pluck('id'))
            ->where('a.code', 'not like', '111%')
            ->where('a.code', 'not like', '112%')
            ->select('je.reference_id as payment_id', 'a.code', 'a.name')
            ->get()
            ->keyBy('payment_id');

        $balance = $openingBalance;
        $totalThu = 0.0;
        $totalChi = 0.0;

        $rows = $entries->map(function (Payment $p) use (&$balance, &$totalThu, &$totalChi, $counterpartMap) {
            $amount = (float) $p->amount;
            $isReceipt = $p->type === PaymentType::Receipt;
            $thu = $isReceipt ? $amount : 0.0;
            $chi = $isReceipt ? 0.0 : $amount;
            $totalThu += $thu;
            $totalChi += $chi;
            $balance += $thu - $chi;

            $cp = $counterpartMap->get($p->id);

            return [
                'id' => $p->id,
                'payment_number' => $p->payment_number,
                'payment_date' => $p->payment_date->toDateString(),
                'description' => $p->description,
                'company' => $p->company?->name,
                'account_code' => $p->account?->code,
                'account_name' => $p->account?->name,
                'counterpart_code' => $cp?->code,
                'counterpart_name' => $cp?->name,
                'thu' => $thu,
                'chi' => $chi,
                'balance' => round($balance, 2),
            ];
        });

        return response()->json([
            'opening_balance' => round($openingBalance, 2),
            'total_thu' => round($totalThu, 2),
            'total_chi' => round($totalChi, 2),
            'closing_balance' => round($balance, 2),
            'data' => $rows,
        ]);
    }

    private function calculateNetProfit(int $orgId, ?string $from, string $to): float
    {
        $result = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jel.account_id')
            ->where('je.organization_id', $orgId)
            ->when($from, fn ($q) => $q->whereDate('je.entry_date', '>=', $from))
            ->whereDate('je.entry_date', '<=', $to)
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
