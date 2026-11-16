<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dictionnaire : « term » est lu comme « replacement »
        Schema::create('pronunciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('term', 100);
            $table->string('replacement', 200);
            // null = toutes les langues
            $table->string('language', 5)->nullable();
            $table->boolean('case_sensitive')->default(false);
            $table->timestamps();
        });

        Schema::table('voices', function (Blueprint $table) {
            // Version nettoyée de l'échantillon (bruit, silences, volume)
            $table->string('cleaned_path')->nullable();
            $table->boolean('use_cleaned')->default(false);
            // Réglages favoris appliqués quand la voix est choisie
            $table->json('settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pronunciations');
        Schema::table('voices', function (Blueprint $table) {
            $table->dropColumn(['cleaned_path', 'use_cleaned', 'settings']);
        });
    }
};
