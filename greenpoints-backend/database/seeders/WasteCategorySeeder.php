<?php

namespace Database\Seeders;

use App\Models\WasteCategory;
use Illuminate\Database\Seeder;

class WasteCategorySeeder extends Seeder
{
    public function run(): void
    {
        // PLACEHOLDER RATES: the group has not decided the scoring formula yet.
        // Changing a rate here never rewrites past points (waste_logs.points_awarded is a snapshot).
        $categories = [
            ['name' => 'Plastic',       'is_recyclable' => true,  'points_per_kg' => 10.00],
            ['name' => 'Glass',         'is_recyclable' => true,  'points_per_kg' => 5.00],
            ['name' => 'Paper',         'is_recyclable' => true,  'points_per_kg' => 4.00],
            ['name' => 'Metal',         'is_recyclable' => true,  'points_per_kg' => 8.00],
            ['name' => 'Organic waste', 'is_recyclable' => false, 'points_per_kg' => 2.00],
        ];

        foreach ($categories as $category) {
            WasteCategory::updateOrCreate(
                ['name' => $category['name']],
                ['is_recyclable' => $category['is_recyclable'], 'points_per_kg' => $category['points_per_kg'], 'is_active' => true],
            );
        }
    }
}
