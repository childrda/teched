<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempt_retry_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_attempt_id')->constrained('lesson_attempts')->cascadeOnDelete();
            $table->string('block_id', 26);
            $table->foreignId('granted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('additional_attempts');
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lesson_attempt_id', 'block_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_retry_grants');
    }
};
