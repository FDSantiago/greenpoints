<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type', 20)->default('class'); // class | zone
            $table->timestamps();
        });

        DB::statement("ALTER TABLE class_groups ADD CONSTRAINT class_groups_type_chk CHECK (type IN ('class','zone'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('class_groups');
    }
};