<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One pending code per unverified user. The row is deleted once the email is verified.
        Schema::create('email_verifications', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('code_hash')->nullable(); // null = no usable code (never sent, or too many wrong tries)
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable(); // start of the resend cooldown
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
        });

        // Accounts that existed before email verification keep working.
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verifications');
    }
};
