<?php

namespace Database\Seeders;

use App\Models\ClassGroup;
use Illuminate\Database\Seeder;

class ClassGroupSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['11-A', '11-B', '12-A'] as $name) {
            ClassGroup::updateOrCreate(['name' => $name], ['type' => 'class']);
        }
    }
}
