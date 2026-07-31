<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_submission_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_submission_id')
                ->constrained('block_submissions')
                ->cascadeOnDelete();
            $table->foreignId('reviewed_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            // Stored even though derivable: a grade must stay self-describing
            // against the pinned version, not today's draft field count.
            $table->unsignedSmallInteger('points_awarded')->nullable();
            $table->unsignedSmallInteger('points_possible')->nullable();
            $table->text('comment')->nullable();
            $table->text('private_note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['block_submission_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_submission_reviews');
    }
};
