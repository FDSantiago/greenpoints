<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Filled from the School Registry (System 2) when a student registers. Nullable: staff,
            // admins and accounts created before the integration have no registry record.
            $table->string('student_id', 10)->nullable()->unique()->after('group_id');
            $table->string('course', 20)->nullable()->after('student_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['student_id']);
            $table->dropColumn(['student_id', 'course']);
        });
    }
};
