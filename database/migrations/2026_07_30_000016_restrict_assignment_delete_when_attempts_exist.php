<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An assignment with attempts must not be deleted: nullOnDelete() turned those
 * attempts into unassigned rows and hid them from every teacher. Restrict is
 * the correct answer until archiving exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_attempts', function (Blueprint $table) {
            $table->dropForeign(['lesson_assignment_id']);
        });

        Schema::table('lesson_attempts', function (Blueprint $table) {
            $table->foreign('lesson_assignment_id')
                ->references('id')
                ->on('lesson_assignments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lesson_attempts', function (Blueprint $table) {
            $table->dropForeign(['lesson_assignment_id']);
        });

        Schema::table('lesson_attempts', function (Blueprint $table) {
            $table->foreign('lesson_assignment_id')
                ->references('id')
                ->on('lesson_assignments')
                ->nullOnDelete();
        });
    }
};
