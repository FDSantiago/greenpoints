<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only ledger: balance = SUM(points) per user. Never UPDATE or DELETE rows.
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            // RESTRICT (not SET NULL/CASCADE) because MySQL forbids those on columns used in a CHECK.
            $table->foreignId('waste_log_id')->nullable()->constrained('waste_logs');
            $table->foreignId('redemption_id')->nullable()->constrained('redemptions');
            $table->string('type', 20);                // earn | redeem | adjustment | refund
            $table->integer('points');                 // signed: + earn/refund, - redeem
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'created_at']);     // leaderboard period queries
        });

        DB::statement("ALTER TABLE point_transactions ADD CONSTRAINT point_txn_type_chk CHECK (type IN ('earn','redeem','adjustment','refund'))");
        DB::statement('ALTER TABLE point_transactions ADD CONSTRAINT point_txn_ref_chk CHECK (waste_log_id IS NULL OR redemption_id IS NULL)');
        DB::statement('ALTER TABLE point_transactions ADD CONSTRAINT point_txn_nonzero_chk CHECK (points <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
    }
};