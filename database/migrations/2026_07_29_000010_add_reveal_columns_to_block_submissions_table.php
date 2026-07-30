<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('block_submissions', function (Blueprint $table) {
            $table->string('reveal_trigger')->nullable()->after('requires_manual_review');
            // Explicit nullable timestamp — no ON UPDATE CURRENT_TIMESTAMP
            // (MySQL's first undeclared TIMESTAMP trap). Reveal is stamped
            // once at create; the column is never rewritten.
            $table->timestamp('revealed_at')->nullable()->after('reveal_trigger');
        });
    }

    public function down(): void
    {
        Schema::table('block_submissions', function (Blueprint $table) {
            $table->dropColumn(['reveal_trigger', 'revealed_at']);
        });
    }
};
