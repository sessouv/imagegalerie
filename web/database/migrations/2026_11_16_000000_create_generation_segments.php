<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un texte est découpé en passages, générés un par un puis assemblés
        Schema::create('generation_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('text');
            // Dernier passage d'un paragraphe : suivi d'une pause plus longue
            $table->boolean('ends_paragraph')->default(false);
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('audio_path')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['generation_id', 'position']);
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->longText('text')->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_segments');
    }
};
