<?php

namespace Database\Seeders;

use App\Models\Province;
use App\Models\Ward;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;

class ProvinceSeeder extends Seeder
{
    public function run(): void
    {
        $response = Http::withoutVerifying()->get('https://provinces.open-api.vn/api/v2/', [
            'depth' => 2,
        ]);

        if (! $response->successful()) {
            $this->command->error('Failed to fetch province list from API.');

            return;
        }

        $provinces = $response->json('results') ?? $response->json();

        if (empty($provinces)) {
            $this->command->error('No data returned from API.');

            return;
        }

        $wardCount = 0;

        foreach ($provinces as $data) {
            $province = Province::updateOrCreate(
                ['code' => $data['code']],
                [
                    '_name' => $data['name'],
                    'codename' => $data['codename'] ?? null,
                    'division_type' => $data['division_type'] ?? null,
                    'phone_code' => $data['phone_code'] ?? null,
                ]
            );

            foreach ($data['wards'] ?? [] as $ward) {
                $divisionType = $ward['division_type'] ?? '';
                $wardName = $divisionType
                    ? trim(preg_replace('/^'.preg_quote($divisionType, '/').'\s+/iu', '', $ward['name']))
                    : $ward['name'];

                Ward::updateOrCreate(
                    ['code' => $ward['code']],
                    [
                        '_name' => $wardName,
                        '_prefix' => ucfirst($divisionType),
                        '_province_id' => $province->id,
                        'codename' => $ward['codename'] ?? null,
                        'division_type' => $divisionType,
                    ]
                );

                $wardCount++;
            }
        }

        $this->command->info('Imported '.count($provinces).' provinces and '.$wardCount.' wards successfully.');
    }
}
