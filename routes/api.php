<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\Public\TableOrderController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\BankController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\FixedAssetController;
use App\Http\Controllers\Api\V1\InventoryAdjustmentController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductCategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\ProvinceController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RestaurantTableController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\ShopeeImportController;
use App\Http\Controllers\Api\V1\TourController;
use App\Http\Controllers\Api\V1\TourGuideAdvanceController;
use App\Http\Controllers\Api\V1\TourPaymentController;
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Controllers\Api\V1\WarehouseExportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public table ordering (no auth)
Route::prefix('public')->group(function () {
    Route::get('table/{token}', [TableOrderController::class, 'info']);
    Route::post('table/{token}/order', [TableOrderController::class, 'placeOrder']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'changePassword']);

    Route::prefix('v1')->middleware('org')->group(function (): void {

        // Thông tin user + permissions
        Route::get('me', function (Request $request) {
            $user = $request->user()->load(['roles.permissions', 'organization']);
            $isAdmin = $user->roles->contains('name', 'admin');
            $org = $user->organization;

            $permissions = $isAdmin
                ? ['*']
                : $user->roles
                    ->flatMap(fn ($r) => $r->permissions->pluck('name'))
                    ->unique()
                    ->values();

            return response()->json([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $isAdmin,
                'permissions' => $permissions,
                'org_settings' => [
                    'enable_sales_tax' => (bool) $org?->setting('enable_sales_tax', false),
                    'default_tax_rate' => (float) $org?->setting('default_tax_rate', 0),
                    'enable_discount' => (bool) $org?->setting('enable_discount', false),
                    'enable_employee_profit' => (bool) $org?->setting('enable_employee_profit', false),
                    'business_mode' => $org?->setting('business_mode', 'retail'),
                    'org_name' => $org?->name,
                ],
            ]);
        });

        // Danh mục — Sản phẩm
        Route::middleware('permission:products.view')->group(function () {
            Route::get('products', [ProductController::class, 'index']);
            Route::get('products/barcode', [ProductController::class, 'lookupBarcode']);
            Route::get('products/{product}', [ProductController::class, 'show']);
        });
        Route::post('products', [ProductController::class, 'store'])->middleware('permission:products.create');
        Route::post('products/import', [ProductController::class, 'bulkImport'])->middleware('permission:products.create');
        Route::put('products/{product}', [ProductController::class, 'update'])->middleware('permission:products.edit');
        Route::post('products/{product}/image', [ProductController::class, 'uploadImage'])->middleware('permission:products.edit');
        Route::delete('products/{product}/image', [ProductController::class, 'deleteImage'])->middleware('permission:products.edit');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.delete');

        // Công thức sản phẩm
        Route::get('recipes', [RecipeController::class, 'index'])->middleware('permission:products.view');
        Route::get('recipes/{recipe}', [RecipeController::class, 'show'])->middleware('permission:products.view');
        Route::post('recipes', [RecipeController::class, 'store'])->middleware('permission:products.create');
        Route::put('recipes/{recipe}', [RecipeController::class, 'update'])->middleware('permission:products.edit');
        Route::delete('recipes/{recipe}', [RecipeController::class, 'destroy'])->middleware('permission:products.delete');

        // Nhóm hàng / Ngành hàng
        Route::get('product-categories', [ProductCategoryController::class, 'index'])->middleware('permission:products.view');
        Route::post('product-categories', [ProductCategoryController::class, 'store'])->middleware('permission:products.create');
        Route::put('product-categories/{productCategory}', [ProductCategoryController::class, 'update'])->middleware('permission:products.edit');
        Route::delete('product-categories/{productCategory}', [ProductCategoryController::class, 'destroy'])->middleware('permission:products.delete');

        // Danh mục — Kho
        Route::get('warehouses', [WarehouseController::class, 'index']);
        Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show']);
        Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('permission:warehouses.create');
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('permission:warehouses.edit');
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->middleware('permission:warehouses.delete');

        // Phiếu xuất kho
        Route::middleware('permission:warehouse_exports.view')->group(function () {
            Route::get('warehouse-exports', [WarehouseExportController::class, 'index']);
            Route::get('warehouse-exports/{warehouseExport}', [WarehouseExportController::class, 'show']);
        });
        Route::post('warehouse-exports', [WarehouseExportController::class, 'store'])->middleware('permission:warehouse_exports.create');
        Route::put('warehouse-exports/{warehouseExport}', [WarehouseExportController::class, 'update'])->middleware('permission:warehouse_exports.edit');
        Route::delete('warehouse-exports/{warehouseExport}', [WarehouseExportController::class, 'destroy'])->middleware('permission:warehouse_exports.delete');

        // Danh mục — Đối tác
        Route::middleware('permission:companies.view')->group(function () {
            Route::get('companies', [CompanyController::class, 'index']);
            Route::get('companies/{company}/stats', [CompanyController::class, 'stats']);
            Route::get('companies/{company}', [CompanyController::class, 'show']);
        });
        Route::post('companies', [CompanyController::class, 'store'])->middleware('permission:companies.create');
        Route::post('companies/import', [CompanyController::class, 'bulkImport'])->middleware('permission:companies.create');
        Route::put('companies/{company}', [CompanyController::class, 'update'])->middleware('permission:companies.edit');
        Route::delete('companies/{company}', [CompanyController::class, 'destroy'])->middleware('permission:companies.delete');

        // Tồn kho
        Route::middleware('permission:inventory.view')->group(function () {
            Route::get('inventory', [InventoryController::class, 'index']);
            Route::get('inventory/product/{product}', [InventoryController::class, 'byProduct']);
            Route::get('inventory/product/{product}/history', [InventoryController::class, 'productHistory']);
            Route::get('inventory/warehouse/{warehouse}', [InventoryController::class, 'byWarehouse']);
        });

        // Điều chỉnh tồn kho
        Route::get('inventory/adjustments', [InventoryAdjustmentController::class, 'index'])->middleware('permission:inventory.view');
        Route::post('inventory/adjustments', [InventoryAdjustmentController::class, 'store'])->middleware('permission:inventory.adjust');
        Route::get('inventory/adjustments/{adjustment}', [InventoryAdjustmentController::class, 'show'])->middleware('permission:inventory.view');
        Route::delete('inventory/adjustments/{adjustment}', [InventoryAdjustmentController::class, 'destroy'])->middleware('permission:inventory.adjust');

        // Mua hàng
        Route::middleware('permission:purchases.view')->group(function () {
            Route::get('purchases', [PurchaseOrderController::class, 'index']);
            Route::get('purchases/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
        });
        Route::post('purchases', [PurchaseOrderController::class, 'store'])->middleware('permission:purchases.create');
        Route::put('purchases/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchases.edit');
        Route::post('purchases/bulk-confirm', [PurchaseOrderController::class, 'bulkConfirm'])->middleware('permission:purchases.confirm');
        Route::post('purchases/{purchaseOrder}/confirm', [PurchaseOrderController::class, 'confirm'])->middleware('permission:purchases.confirm');
        Route::post('purchases/{purchaseOrder}/ship', [PurchaseOrderController::class, 'ship'])->middleware('permission:purchases.confirm');
        Route::post('purchases/{purchaseOrder}/complete', [PurchaseOrderController::class, 'complete'])->middleware('permission:purchases.confirm');
        Route::post('purchases/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchases.cancel');

        // Khuyến mại
        Route::get('promotions/applicable', [PromotionController::class, 'applicable'])->middleware('permission:sales.create');
        Route::middleware('permission:promotions.view')->group(function () {
            Route::get('promotions', [PromotionController::class, 'index']);
            Route::get('promotions/usage-stats', [PromotionController::class, 'usageStats']);
            Route::get('promotions/{promotion}/usage', [PromotionController::class, 'usageDetail']);
            Route::get('promotions/{promotion}', [PromotionController::class, 'show']);
        });
        Route::post('promotions', [PromotionController::class, 'store'])->middleware('permission:promotions.create');
        Route::put('promotions/{promotion}', [PromotionController::class, 'update'])->middleware('permission:promotions.edit');
        Route::post('promotions/{promotion}/recalculate-orders', [PromotionController::class, 'recalculateOrders'])->middleware('permission:promotions.edit');
        Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy'])->middleware('permission:promotions.delete');

        // Bán hàng
        Route::middleware('permission:sales.view')->group(function () {
            Route::get('sales', [SalesOrderController::class, 'index']);
            Route::get('sales/counts', [SalesOrderController::class, 'counts']);
            Route::get('sales/{salesOrder}', [SalesOrderController::class, 'show']);
        });
        Route::post('sales', [SalesOrderController::class, 'store'])->middleware('permission:sales.create');
        Route::post('sales/import', [SalesOrderController::class, 'bulkImport'])->middleware('permission:sales.create');
        Route::post('sales/shopee-import', [ShopeeImportController::class, 'import'])->middleware('permission:sales.create');
        Route::put('sales/{salesOrder}', [SalesOrderController::class, 'update'])->middleware('permission:sales.edit');
        Route::post('sales/bulk-confirm', [SalesOrderController::class, 'bulkConfirm'])->middleware('permission:sales.confirm');
        Route::post('sales/{salesOrder}/confirm', [SalesOrderController::class, 'confirm'])->middleware('permission:sales.confirm');
        Route::post('sales/{salesOrder}/ship', [SalesOrderController::class, 'ship'])->middleware('permission:sales.confirm');
        Route::post('sales/{salesOrder}/complete', [SalesOrderController::class, 'complete'])->middleware('permission:sales.confirm');
        Route::post('sales/{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])->middleware('permission:sales.cancel');
        Route::post('sales/{salesOrder}/return-items', [SalesOrderController::class, 'returnItems'])->middleware('permission:sales.confirm');
        Route::patch('sales/{salesOrder}/items/{salesOrderItem}', [SalesOrderController::class, 'updateItem'])->middleware('permission:sales.view');

        // Thanh toán
        Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payments.view');
        Route::get('payments/advance-balance', [PaymentController::class, 'advanceBalance'])->middleware('permission:payments.view');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payments.view');
        Route::post('payments', [PaymentController::class, 'store'])->middleware('permission:payments.create');
        Route::post('payments/{payment}/approve', [PaymentController::class, 'approve'])->middleware('permission:payments.create');
        Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->middleware('permission:payments.delete');

        // Tài sản cố định
        Route::get('fixed-assets', [FixedAssetController::class, 'index'])->middleware('permission:assets.view');
        Route::get('fixed-assets/{fixedAsset}', [FixedAssetController::class, 'show'])->middleware('permission:assets.view');
        Route::post('fixed-assets', [FixedAssetController::class, 'store'])->middleware('permission:assets.create');
        Route::post('fixed-assets/{fixedAsset}/depreciate', [FixedAssetController::class, 'postDepreciation'])->middleware('permission:assets.create');

        // Kế toán
        Route::middleware('permission:accounts.view')->group(function () {
            Route::get('accounts', [AccountController::class, 'index']);
            Route::get('accounts/{account}', [AccountController::class, 'show']);
        });
        Route::post('accounts', [AccountController::class, 'store'])->middleware('permission:accounts.create');
        Route::put('accounts/{account}', [AccountController::class, 'update'])->middleware('permission:accounts.edit');

        Route::middleware('permission:journal.view')->group(function () {
            Route::get('journal-entries', [JournalEntryController::class, 'index']);
            Route::get('journal-entries/{journalEntry}', [JournalEntryController::class, 'show']);
            Route::get('ledger/{accountCode}', [JournalEntryController::class, 'ledger']);
        });

        // Báo cáo
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('reports/cashbook', [ReportController::class, 'cashbook']);
            Route::get('reports/inventory', [ReportController::class, 'inventory']);
            Route::get('reports/receivables', [ReportController::class, 'receivables']);
            Route::get('reports/payables', [ReportController::class, 'payables']);
            Route::get('reports/sales', [ReportController::class, 'sales']);
            Route::get('reports/sold-products', [ReportController::class, 'soldProducts']);
            Route::get('reports/trial-balance', [ReportController::class, 'trialBalance']);
            Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet']);
            Route::get('reports/income-statement', [ReportController::class, 'incomeStatement']);
            Route::get('reports/sales-by-employee', [ReportController::class, 'salesByEmployee']);
        });

        // Ngân hàng (dữ liệu tham chiếu, không cần permission)
        Route::get('banks', [BankController::class, 'index']);

        // Tỉnh / Phường (dữ liệu tham chiếu, không cần permission)
        Route::get('provinces', [ProvinceController::class, 'index']);
        Route::get('provinces/{province}/wards', [ProvinceController::class, 'wards']);

        // Quản lý bàn ăn (nhà hàng)
        Route::get('restaurant/tables', [RestaurantTableController::class, 'index']);
        Route::post('restaurant/tables', [RestaurantTableController::class, 'store']);
        Route::put('restaurant/tables/{restaurantTable}', [RestaurantTableController::class, 'update']);
        Route::delete('restaurant/tables/{restaurantTable}', [RestaurantTableController::class, 'destroy']);

        // Cài đặt — chỉ admin
        Route::middleware('permission:settings.edit')->group(function () {
            Route::get('organization', [OrganizationController::class, 'show']);
            Route::put('organization', [OrganizationController::class, 'update']);
            Route::post('organization/reset-data', [OrganizationController::class, 'resetData']);
            Route::post('organization/delete-all-products', [OrganizationController::class, 'deleteAllProducts']);
            Route::post('organization/delete-all-companies', [OrganizationController::class, 'deleteAllCompanies']);
        });

        // Nhân viên & phân quyền — chỉ admin
        Route::middleware('permission:employees.view')->group(function () {
            Route::get('employees', [EmployeeController::class, 'index']);
            Route::get('employees/{employee}', [EmployeeController::class, 'show']);
        });
        Route::post('employees', [EmployeeController::class, 'store'])->middleware('permission:employees.create');
        Route::put('employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employees.edit');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:employees.delete');

        Route::middleware('permission:roles.view')->group(function () {
            Route::get('roles', [RoleController::class, 'index']);
            Route::get('roles/{role}', [RoleController::class, 'show']);
            Route::get('all-permissions', [RoleController::class, 'permissions']);
        });
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.edit');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');

        // Tour du lịch
        Route::get('tours', [TourController::class, 'index']);
        Route::post('tours', [TourController::class, 'store']);
        Route::get('tours/{tour}', [TourController::class, 'show']);
        Route::put('tours/{tour}', [TourController::class, 'update']);
        Route::post('tours/{tour}/confirm', [TourController::class, 'confirm']);
        Route::post('tours/{tour}/complete', [TourController::class, 'complete']);
        Route::post('tours/{tour}/cancel', [TourController::class, 'cancel']);
        Route::post('tours/{tour}/collect', [TourController::class, 'collect']);
        Route::delete('tours/{tour}', [TourController::class, 'destroy']);

        // Tạm ứng hướng dẫn viên
        Route::get('tours/{tour}/guide-advances', [TourGuideAdvanceController::class, 'index']);
        Route::post('tours/{tour}/guide-advances', [TourGuideAdvanceController::class, 'store']);
        Route::delete('tour-guide-advances/{tourGuideAdvance}', [TourGuideAdvanceController::class, 'destroy'])->middleware('permission:payments.delete');

        // Phiếu thanh toán dịch vụ tour
        Route::get('tour-payments', [TourPaymentController::class, 'index']);
        Route::post('tour-payments', [TourPaymentController::class, 'store']);
        Route::get('tour-payments/supplier-advance-balance', [TourPaymentController::class, 'supplierAdvanceBalance']);
        Route::post('tour-payments/{tourPayment}/approve', [TourPaymentController::class, 'approve']);
        Route::post('tour-payments/{tourPayment}/reject', [TourPaymentController::class, 'reject']);
        Route::delete('tour-payments/{tourPayment}', [TourPaymentController::class, 'destroy'])->middleware('permission:payments.delete');
    });
});
