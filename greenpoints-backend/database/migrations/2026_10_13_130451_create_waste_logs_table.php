<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('submission_id')->index();   // groups items from one drop-off
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('waste_categories')->restrictOnDelete();
            $table->decimal('weight_kg', 8, 3);
            $table->string('waste_condition', 20)->default('clean');      // clean | contaminated
            $table->decimal('contamination_factor', 3, 2)->default(1.00); // multiplier snapshot
            $table->unsignedInteger('points_awarded')->nullable();        // set on verification
            $table->string('status', 20)->default('pending');             // pending | verified | rejected
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('dropped_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'dropped_at']);
            $table->index('status');
        });

        DB::statement("ALTER TABLE waste_logs ADD CONSTRAINT waste_logs_status_chk CHECK (status IN ('pending','verified','rejected'))");
        DB::statement("ALTER TABLE waste_logs ADD CONSTRAINT waste_logs_condition_chk CHECK (waste_condition IN ('clean','contaminated'))");
        DB::statement('ALTER TABLE waste_logs ADD CONSTRAINT waste_logs_weight_chk CHECK (weight_kg > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_logs');
    }
};