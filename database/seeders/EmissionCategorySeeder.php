<?php

namespace Database\Seeders;

use App\Models\EmissionCategory;
use Illuminate\Database\Seeder;

class EmissionCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Konsumsi Energi', 'slug' => 'energy', 'icon' => 'bolt', 'sort_order' => 1],
            ['name' => 'Aktivitas Project', 'slug' => 'activity', 'icon' => 'briefcase', 'sort_order' => 2],
            ['name' => 'Supply Chain / Vendor', 'slug' => 'vendor', 'icon' => 'truck', 'sort_order' => 3],
        ];

        foreach ($categories as $category) {
            EmissionCategory::firstOrCreate(['slug' => $category['slug']], $category);
        }
    }
}
