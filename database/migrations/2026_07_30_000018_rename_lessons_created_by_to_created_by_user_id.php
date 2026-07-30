<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ownership column for LessonPolicy. The authoring table already had
 * `created_by` as a nullable audit FK with nullOnDelete; Phase 5A promotes
 * it to a true owner with restrictOnDelete and renames it to match the
 * prompt's `created_by_user_id`.
 *
 * Backfill: the seeded WEL lesson already sets created_by to the publishing
 * author. Any other row with a null owner is pointed at the earliest
 * lesson_versions.published_by for that lesson when available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->renameColumn('created_by', 'created_by_user_id');
        });

        // Point orphaned authoring rows at their first publisher when one exists.
        // Seeded WEL already sets the owner to the publishing author explicitly.
        foreach (DB::table('lessons')->whereNull('created_by_user_id')->orderBy('id')->get() as $lesson) {
            $publisherId = DB::table('lesson_versions')
                ->where('lesson_id', $lesson->id)
                ->whereNotNull('published_by')
                ->orderBy('version')
                ->value('published_by');

            if ($publisherId !== null) {
                DB::table('lessons')
                    ->where('id', $lesson->id)
                    ->update(['created_by_user_id' => $publisherId]);
            }
        }

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->renameColumn('created_by_user_id', 'created_by');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
