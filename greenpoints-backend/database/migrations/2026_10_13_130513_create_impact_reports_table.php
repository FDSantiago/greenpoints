<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impact_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('class_groups')->cascadeOnDelete();
            $table->date('report_month');              // first day of the month
            $table->decimal('total_kg_diverted', 10, 3)->default(0);
            $table->unsignedBigInteger('total_points')->default(0);
            $table->timestamp('generated_at')->useCurrent();

            $table->unique(['group_id', 'report_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impact_reports');
    }
};