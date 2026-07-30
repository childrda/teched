<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_attempts', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->after('completed_at');
            $table->foreignId('superseded_by_user_id')
                ->nullable()
                ->after('superseded_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lesson_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by_user_id');
            $table->dropColumn('superseded_at');
        });
    }
};
