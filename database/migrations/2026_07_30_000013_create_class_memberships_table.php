<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('withdrawn_at')->nullable();

            $table->unique(['school_class_id', 'user_id']);
            $table->index(['user_id', 'withdrawn_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_memberships');
    }
};
