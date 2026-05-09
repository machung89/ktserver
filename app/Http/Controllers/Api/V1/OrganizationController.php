<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    use ScopedByOrganization;

    public function show(): JsonResponse
    {
        $org = Organization::findOrFail($this->orgId());

        return response()->json(['data' => $this->format($org)]);
    }

    public function update(Request $request): JsonResponse
    {
        $org = Organization::findOrFail($this->orgId());

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
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
            'settings.default_tax_rate' => ['numeric', 'min:0', 'max:100'],
        ]);

        $org->update($validated);

        return response()->json(['data' => $this->format($org)]);
    }

    /** @return array<string, mixed> */
    private function format(Organization $org): array
    {
        return [
            'id' => $org->id,
            'name' => $org->name,
            'tax_code' => $org->tax_code,
            'address' => $org->address,
            'phone' => $org->phone,
            'email' => $org->email,
            'website' => $org->website,
            'logo_url' => $org->logo_url,
            'print_template' => $org->print_template ?? 'default',
            'settings' => [
                'allow_negative_stock' => (bool) $org->setting('allow_negative_stock', false),
                'auto_confirm_sale' => (bool) $org->setting('auto_confirm_sale', false),
                'auto_confirm_purchase' => (bool) $org->setting('auto_confirm_purchase', false),
                'require_cost_price' => (bool) $org->setting('require_cost_price', false),
                'enable_sales_tax' => (bool) $org->setting('enable_sales_tax', false),
                'default_tax_rate' => (float) $org->setting('default_tax_rate', 0),
            ],
        ];
    }
}
