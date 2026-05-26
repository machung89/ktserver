<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    use ScopedByOrganization;

    public function show(): JsonResponse
    {
        $org = Organization::with('bank')->findOrFail($this->orgId());

        return response()->json(['data' => $this->format($org)]);
    }

    public function update(Request $request): JsonResponse
    {
        $org = Organization::findOrFail($this->orgId());

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'ward' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'print_template' => ['nullable', Rule::in(['default', 'professional', 'compact'])],
            'settings' => ['nullable', 'array'],
            'settings.allow_negative_stock' => ['boolean'],
            'settings.auto_confirm_sale' => ['boolean'],
            'settings.auto_confirm_purchase' => ['boolean'],
            'settings.require_cost_price' => ['boolean'],
            'settings.enable_sales_tax' => ['boolean'],
            'settings.enable_purchase_tax' => ['boolean'],
            'settings.default_tax_rate' => ['numeric', 'min:0', 'max:100'],
            'settings.enable_discount' => ['boolean'],
            'settings.enable_employee_profit' => ['boolean'],
            'settings.business_mode' => ['nullable', Rule::in(['retail', 'restaurant', 'tour'])],
            'settings.tour_number_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9_-]*$/'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
        ]);

        $org->update($validated);

        return response()->json(['data' => $this->format($org->load('bank'))]);
    }

    public function deleteAllProducts(Request $request): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'string', 'in:XOA']]);

        $orgId = $this->orgId();
        $productIds = DB::table('products')->where('organization_id', $orgId)->pluck('id');

        if ($productIds->isEmpty()) {
            return response()->json(['message' => 'Không có sản phẩm nào để xóa']);
        }

        $blockingCount = DB::table('sales_order_items')->whereIn('product_id', $productIds)->count()
            + DB::table('purchase_order_items')->whereIn('product_id', $productIds)->count();

        if ($blockingCount > 0) {
            return response()->json([
                'message' => "Không thể xóa: có {$blockingCount} dòng đơn hàng đang tham chiếu đến sản phẩm. Hãy reset dữ liệu giao dịch trước.",
            ], 422);
        }

        DB::transaction(function () use ($productIds, $orgId) {
            DB::table('inventory_transaction_items')->whereIn('product_id', $productIds)->delete();
            DB::table('inventories')->where('organization_id', $orgId)->delete();
            DB::table('recipe_ingredients')->whereIn('product_id', $productIds)->orWhereIn('ingredient_id', $productIds)->delete();
            DB::table('recipes')->whereIn('product_id', $productIds)->delete();
            DB::table('products')->where('organization_id', $orgId)->delete();
        });

        return response()->json(['message' => 'Đã xóa toàn bộ sản phẩm']);
    }

    public function deleteAllCompanies(Request $request): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'string', 'in:XOA']]);

        $orgId = $this->orgId();
        $companyIds = DB::table('companies')->where('organization_id', $orgId)->pluck('id');

        if ($companyIds->isEmpty()) {
            return response()->json(['message' => 'Không có đối tác nào để xóa']);
        }

        DB::transaction(function () use ($companyIds, $orgId) {
            DB::table('sales_orders')->where('organization_id', $orgId)->whereIn('company_id', $companyIds)->update(['company_id' => null]);
            DB::table('purchase_orders')->where('organization_id', $orgId)->whereIn('company_id', $companyIds)->update(['company_id' => null]);
            DB::table('payments')->where('organization_id', $orgId)->whereIn('company_id', $companyIds)->update(['company_id' => null]);
            DB::table('companies')->where('organization_id', $orgId)->delete();
        });

        return response()->json(['message' => 'Đã xóa toàn bộ đối tác']);
    }

    public function resetData(Request $request): JsonResponse
    {
        $request->validate([
            'confirm' => ['required', 'string', 'in:RESET'],
        ]);

        $orgId = $this->orgId();

        DB::transaction(function () use ($orgId) {
            // Tour
            DB::table('tour_guide_advances')->where('organization_id', $orgId)->delete();
            DB::table('tour_payment_requests')->whereIn(
                'tour_id',
                DB::table('tours')->where('organization_id', $orgId)->pluck('id')
            )->delete();
            DB::table('tour_services')->whereIn(
                'tour_id',
                DB::table('tours')->where('organization_id', $orgId)->pluck('id')
            )->delete();
            DB::table('tours')->where('organization_id', $orgId)->delete();

            // Tài sản cố định
            DB::table('fixed_asset_depreciations')->whereIn(
                'fixed_asset_id',
                DB::table('fixed_assets')->where('organization_id', $orgId)->pluck('id')
            )->delete();
            DB::table('fixed_assets')->where('organization_id', $orgId)->delete();

            // Đơn bán
            DB::table('sales_order_items')->whereIn(
                'sales_order_id',
                DB::table('sales_orders')->where('organization_id', $orgId)->pluck('id')
            )->delete();
            DB::table('sales_orders')->where('organization_id', $orgId)->delete();

            // Đơn mua
            DB::table('purchase_order_items')->whereIn(
                'purchase_order_id',
                DB::table('purchase_orders')->where('organization_id', $orgId)->pluck('id')
            )->delete();
            DB::table('purchase_orders')->where('organization_id', $orgId)->delete();

            // Thanh toán
            DB::table('payments')->where('organization_id', $orgId)->delete();

            // Bút toán
            DB::table('journal_entry_lines')->whereIn(
                'journal_entry_id',
                DB::table('journal_entries')->where('organization_id', $orgId)->pluck('id')
            )->delete();
            DB::table('journal_entries')->where('organization_id', $orgId)->delete();

            // Tồn kho
            DB::table('inventory_transaction_items')->whereIn(
                'inventory_transaction_id',
                DB::table('inventory_transactions')->where('organization_id', $orgId)->pluck('id')
            )->delete();
            DB::table('inventory_transactions')->where('organization_id', $orgId)->delete();
            DB::table('inventories')->where('organization_id', $orgId)->delete();
        });

        return response()->json(['message' => 'Đã xóa toàn bộ dữ liệu giao dịch']);
    }

    /** @return array<string, mixed> */
    private function format(Organization $org): array
    {
        return [
            'id' => $org->id,
            'name' => $org->name,
            'tax_code' => $org->tax_code,
            'address' => $org->address,
            'city' => $org->city,
            'ward' => $org->ward,
            'phone' => $org->phone,
            'email' => $org->email,
            'website' => $org->website,
            'logo_url' => $org->logo_url,
            'print_template' => $org->print_template ?? 'default',
            'bank_id' => $org->bank_id,
            'bank_account_name' => $org->bank_account_name,
            'bank_account_number' => $org->bank_account_number,
            'bank' => $org->relationLoaded('bank') && $org->bank ? [
                'id' => $org->bank->id,
                'bin' => $org->bank->bin,
                'short_name' => $org->bank->short_name,
                'name' => $org->bank->name,
                'logo' => $org->bank->logo,
            ] : null,
            'settings' => [
                'allow_negative_stock' => (bool) $org->setting('allow_negative_stock', false),
                'auto_confirm_sale' => (bool) $org->setting('auto_confirm_sale', false),
                'auto_confirm_purchase' => (bool) $org->setting('auto_confirm_purchase', false),
                'require_cost_price' => (bool) $org->setting('require_cost_price', false),
                'enable_sales_tax' => (bool) $org->setting('enable_sales_tax', false),
                'enable_purchase_tax' => (bool) $org->setting('enable_purchase_tax', false),
                'default_tax_rate' => (float) $org->setting('default_tax_rate', 0),
                'enable_discount' => (bool) $org->setting('enable_discount', false),
                'enable_employee_profit' => (bool) $org->setting('enable_employee_profit', false),
                'business_mode' => $org->setting('business_mode', 'retail'),
                'tour_number_prefix' => $org->setting('tour_number_prefix', 'TOUR'),
            ],
        ];
    }
}
