<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make lessons.created_by_user_id non-nullable and record ownership repairs.
 *
 * Backfill order (query builder only):
 * 1. earliest lesson_versions.published_by
 * 2. earliest admin
 * 3. earliest teacher
 * 4. fail — never assign a student, never leave null
 *
 * Migration-time audit rows use source=migration and null changed_by_user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_owner_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('previous_owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('new_owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source'); // manual | migration
            $table->timestamp('created_at')->useCurrent();
        });

        $orphans = DB::table('lessons')->whereNull('created_by_user_id')->orderBy('id')->get();

        if ($orphans->isNotEmpty()) {
            $adminId = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');
            $teacherId = DB::table('users')->where('role', 'teacher')->orderBy('id')->value('id');
            $fallbackOwner = $adminId ?? $teacherId;

            if ($fallbackOwner === null) {
                throw new \RuntimeException(
                    'Cannot backfill lesson ownership: no admin or teacher user exists. Seed staff accounts first.'
                );
            }

            foreach ($orphans as $lesson) {
                $publisherId = DB::table('lesson_versions')
                    ->where('lesson_id', $lesson->id)
                    ->whereNotNull('published_by')
                    ->orderBy('version')
                    ->value('published_by');

                $newOwner = $publisherId ?? $fallbackOwner;

                DB::table('lessons')
                    ->where('id', $lesson->id)
                    ->update(['created_by_user_id' => $newOwner]);

                DB::table('lesson_owner_changes')->insert([
                    'lesson_id' => $lesson->id,
                    'previous_owner_user_id' => null,
                    'new_owner_user_id' => $newOwner,
                    'changed_by_user_id' => null,
                    'source' => 'migration',
                    'created_at' => now(),
                ]);
            }
        }

        // Still-null would mean a concurrent insert race; refuse to proceed.
        if (DB::table('lessons')->whereNull('created_by_user_id')->exists()) {
            throw new \RuntimeException(
                'Lesson ownership backfill left null created_by_user_id rows.'
            );
        }

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_user_id')->nullable(false)->change();
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
            $table->unsignedBigInteger('created_by_user_id')->nullable()->change();
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        Schema::dropIfExists('lesson_owner_changes');
    }
};
