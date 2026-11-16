<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('language', 5);
            $table->string('sample_path');
            $table->string('sample_mime');
            // Date à laquelle l'auteur a attesté avoir le droit d'utiliser cette voix
            $table->timestamp('consent_confirmed_at');
            $table->timestamps();
        });

        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // null = voix par défaut du modèle
            $table->foreignId('voice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('voice_name')->nullable();
            $table->text('text');
            $table->string('language', 5);
            $table->json('settings');
            $table->string('status')->default('pending')->index();
            $table->string('audio_path')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
        Schema::dropIfExists('voices');
    }
};
