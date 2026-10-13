<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_recyclable')->default(true);
            $table->decimal('points_per_kg', 8, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE waste_categories ADD CONSTRAINT waste_categories_rate_chk CHECK (points_per_kg >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_categories');
    }
};