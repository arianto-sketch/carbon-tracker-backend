<?php

namespace Database\Seeders;

use App\Models\EmissionCategory;
use App\Models\EmissionFactor;
use App\Models\User;
use Illuminate\Database\Seeder;

class EmissionFactorSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $adminId = $admin?->id ?? 1;

        $energy = EmissionCategory::where('slug', 'energy')->first();
        $activity = EmissionCategory::where('slug', 'activity')->first();
        $vendor = EmissionCategory::where('slug', 'vendor')->first();

        $factors = [
            // Energi
            [
                'category_id' => $energy->id,
                'name' => 'Listrik PLN Jawa-Bali',
                'slug' => 'electricity_jawa_bali',
                'source_unit' => 'kWh',
                'factor_value' => 0.87000000,
                'source' => 'Kementerian ESDM Indonesia 2022',
                'version' => '2022',
            ],
            [
                'category_id' => $energy->id,
                'name' => 'Listrik PLN Sumatra',
                'slug' => 'electricity_sumatra',
                'source_unit' => 'kWh',
                'factor_value' => 0.84000000,
                'source' => 'Kementerian ESDM Indonesia 2022',
                'version' => '2022',
            ],
            [
                'category_id' => $energy->id,
                'name' => 'Bensin (kendaraan)',
                'slug' => 'gasoline_vehicle',
                'source_unit' => 'liter',
                'factor_value' => 2.31000000,
                'source' => 'IPCC 2006 Guidelines',
                'version' => '2006',
            ],
            [
                'category_id' => $energy->id,
                'name' => 'Solar (kendaraan)',
                'slug' => 'diesel_vehicle',
                'source_unit' => 'liter',
                'factor_value' => 2.68000000,
                'source' => 'IPCC 2006 Guidelines',
                'version' => '2006',
            ],
            // Aktivitas
            [
                'category_id' => $activity->id,
                'name' => 'Penerbangan domestik',
                'slug' => 'flight_domestic',
                'source_unit' => 'km per orang',
                'factor_value' => 0.25500000,
                'source' => 'ICAO Carbon Emissions Calculator 2023',
                'version' => '2023',
            ],
            [
                'category_id' => $activity->id,
                'name' => 'Penerbangan internasional',
                'slug' => 'flight_international',
                'source_unit' => 'km per orang',
                'factor_value' => 0.19500000,
                'source' => 'ICAO Carbon Emissions Calculator 2023',
                'version' => '2023',
            ],
            [
                'category_id' => $activity->id,
                'name' => 'Kertas A4 (rim)',
                'slug' => 'paper_a4_ream',
                'source_unit' => 'rim',
                'factor_value' => 2.00000000,
                'source' => 'GHG Protocol',
                'version' => '2023',
            ],
            // Vendor
            [
                'category_id' => $vendor->id,
                'name' => 'Air minum galon',
                'slug' => 'water_gallon',
                'source_unit' => 'galon',
                'factor_value' => 0.44000000,
                'source' => 'Estimasi lokal',
                'version' => '2023',
            ],
        ];

        foreach ($factors as $factor) {
            EmissionFactor::firstOrCreate(
                ['slug' => $factor['slug']],
                array_merge($factor, ['created_by' => $adminId])
            );
        }
    }
}
