<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempt_reopens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_attempt_id')
                ->constrained('lesson_attempts')
                ->cascadeOnDelete();
            $table->foreignId('reopened_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            // The completion moment being cleared — preserved so the record
            // that this attempt was once finished survives the reopen.
            $table->timestamp('previous_completed_at');
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_reopens');
    }
};
