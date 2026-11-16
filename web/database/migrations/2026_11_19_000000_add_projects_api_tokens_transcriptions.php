<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();
        });

        Schema::table('generations', function (Blueprint $table) {
            // Supprimer un projet ne supprime pas ses audios : ils redeviennent « sans projet »
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
        });

        // Clés d'accès à l'API : seule l'empreinte de la clé est conservée
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('token_hash', 64)->unique();
            $table->string('hint', 12);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('transcriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('audio_path');
            // Langue demandée (null = détection automatique), puis langue reconnue
            $table->string('language', 5)->nullable();
            $table->string('detected_language', 10)->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('engine_job_id')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->longText('text')->nullable();
            $table->json('segments')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
        Schema::dropIfExists('api_tokens');
        Schema::table('generations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
        Schema::dropIfExists('projects');
    }
};
