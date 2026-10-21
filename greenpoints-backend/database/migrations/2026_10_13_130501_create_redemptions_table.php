<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('reward_id')->constrained();
            $table->unsignedInteger('points_spent');                 // snapshot of cost at redemption
            $table->string('status', 20)->default('pending');        // pending | ready | claimed | cancelled
            $table->string('redemption_code', 16)->unique();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('redeemed_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'redeemed_at']);
        });

        DB::statement("ALTER TABLE redemptions ADD CONSTRAINT redemptions_status_chk CHECK (status IN ('pending','ready','claimed','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
    }
};