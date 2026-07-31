<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5G: nullable password (Google-provisioned accounts), deactivated_at,
 * and immutable user_account_changes audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->timestamp('deactivated_at')->nullable()->after('remember_token');
        });

        Schema::create('user_account_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            // Nullable only for console/system actions with no authenticated actor.
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_account_changes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
            $table->string('password')->nullable(false)->change();
        });
    }
};
