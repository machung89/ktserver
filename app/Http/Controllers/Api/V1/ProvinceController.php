<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Province;
use Illuminate\Http\JsonResponse;

class ProvinceController extends Controller
{
    public function index(): JsonResponse
    {
        $provinces = Province::orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
        ]);

        return response()->json($provinces);
    }

    public function wards(Province $province): JsonResponse
    {
        $wards = $province->wards()->orderBy('_prefix')->orderBy('_name')->get()->map(fn ($w) => [
            'id' => $w->id,
            'name' => $w->_name,
            'prefix' => $w->_prefix,
            'full_name' => $w->full_name,
        ]);

        return response()->json($wards);
    }
}
