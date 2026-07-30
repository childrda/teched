<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_assignment_status_changes', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete: an assignment that was archived/unarchived
            // cannot be deleted — archive is the supported retirement path,
            // and the audit trail must not disappear with the row.
            // Explicit FK names: MySQL's 64-char limit rejects the defaults.
            $table->unsignedBigInteger('lesson_assignment_id');
            $table->string('action'); // archived | unarchived
            $table->unsignedBigInteger('changed_by_user_id');
            $table->timestamp('created_at');

            $table->foreign('lesson_assignment_id', 'lasc_assignment_fk')
                ->references('id')->on('lesson_assignments')->restrictOnDelete();
            $table->foreign('changed_by_user_id', 'lasc_changed_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('lesson_assignment_version_changes', function (Blueprint $table) {
            $table->id();
            // Same restrict policy as status_changes — a repinned assignment
            // keeps its history and must be archived rather than deleted.
            $table->unsignedBigInteger('lesson_assignment_id');
            $table->unsignedBigInteger('previous_lesson_version_id');
            $table->unsignedBigInteger('new_lesson_version_id');
            $table->unsignedBigInteger('changed_by_user_id');
            $table->timestamp('created_at');

            $table->foreign('lesson_assignment_id', 'lavc_assignment_fk')
                ->references('id')->on('lesson_assignments')->restrictOnDelete();
            $table->foreign('previous_lesson_version_id', 'lavc_prev_version_fk')
                ->references('id')->on('lesson_versions')->restrictOnDelete();
            $table->foreign('new_lesson_version_id', 'lavc_new_version_fk')
                ->references('id')->on('lesson_versions')->restrictOnDelete();
            $table->foreign('changed_by_user_id', 'lavc_changed_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_assignment_version_changes');
        Schema::dropIfExists('lesson_assignment_status_changes');
    }
};
