<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;

class BankSeeder extends Seeder
{
    public function run(): void
    {
        $response = Http::withoutVerifying()->get('https://api.vietqr.io/v2/banks');

        if (! $response->successful() || ($response->json('code') !== '00')) {
            $this->command->error('Failed to fetch bank list from VietQR API.');

            return;
        }

        $banks = $response->json('data');

        foreach ($banks as $bank) {
            Bank::updateOrCreate(
                ['id' => $bank['id']],
                [
                    'name' => $bank['name'],
                    'code' => $bank['code'],
                    'bin' => $bank['bin'],
                    'short_name' => $bank['shortName'],
                    'logo' => $bank['logo'] ?? null,
                    'swift_code' => $bank['swift_code'] ?? null,
                    'transfer_supported' => (bool) ($bank['transferSupported'] ?? false),
                    'lookup_supported' => (bool) ($bank['lookupSupported'] ?? false),
                ]
            );
        }

        $this->command->info('Imported '.count($banks).' banks successfully.');
    }
}
