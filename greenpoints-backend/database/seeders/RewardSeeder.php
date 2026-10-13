<?php

namespace Database\Seeders;

use App\Models\Reward;
use Illuminate\Database\Seeder;

class RewardSeeder extends Seeder
{
    public function run(): void
    {
        $rewards = [
            ['name' => 'Everyday bottle',        'description' => 'Reusable water bottle made from recycled materials.', 'points_cost' => 2400, 'stock_qty' => 20],
            ['name' => 'Watering plants',        'description' => 'A live plant for your desk or room.',                 'points_cost' => 1800, 'stock_qty' => 15],
            ['name' => 'Canteen coffee voucher', 'description' => 'One free coffee at the school canteen.',               'points_cost' => 1000, 'stock_qty' => 50],
        ];

        foreach ($rewards as $reward) {
            Reward::updateOrCreate(['name' => $reward['name']], $reward + ['is_active' => true]);
        }
    }
}
