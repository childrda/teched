<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Least privilege: omit role and the row is a student, never staff.
            $table->string('role')->default('student')->after('password');

            // Phase 6 Google sign-in will match on this, never on email.
            $table->string('google_id')->nullable()->unique()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['role', 'google_id']);
        });
    }
};
