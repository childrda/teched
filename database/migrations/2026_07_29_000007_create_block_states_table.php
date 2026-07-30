<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_attempt_id')->constrained('lesson_attempts')->cascadeOnDelete();
            $table->string('block_id', 26);
            $table->string('block_type');
            $table->json('state');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();

            $table->unique(['lesson_attempt_id', 'block_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_states');
    }
};
