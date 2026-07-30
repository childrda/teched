<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Creator/owner for display and lifecycle only — NOT the
            // authorization source. Policies read class_memberships.
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->string('school_year');
            $table->boolean('active')->default(true);
            $table->string('external_provider')->nullable();
            $table->string('external_id')->nullable();
            $table->timestamps();

            // NULLs do not collide — many manually created classes may share
            // (null, null) while Phase 7 imports stay idempotent on a real pair.
            $table->unique(['external_provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_classes');
    }
};
