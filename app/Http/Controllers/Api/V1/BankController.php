<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class BankController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $banks = Bank::orderBy('short_name')->get();

        return JsonResource::collection($banks);
    }
}
