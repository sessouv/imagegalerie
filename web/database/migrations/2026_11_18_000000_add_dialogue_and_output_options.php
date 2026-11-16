<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Nom donné à l'audio : affiché et repris dans le nom du fichier téléchargé
            $table->string('title', 120)->nullable();
            $table->string('wav_path')->nullable();
        });

        // Chaque passage porte sa propre voix : un dialogue en utilise plusieurs
        Schema::table('generation_segments', function (Blueprint $table) {
            $table->foreignId('voice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('voice_name')->nullable();
        });

        foreach (DB::table('generations')->whereNotNull('voice_name')->get(['id', 'voice_id', 'voice_name']) as $generation) {
            DB::table('generation_segments')->where('generation_id', $generation->id)
                ->update(['voice_id' => $generation->voice_id, 'voice_name' => $generation->voice_name]);
        }
    }

    public function down(): void
    {
        Schema::table('generation_segments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voice_id');
            $table->dropColumn('voice_name');
        });
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['title', 'wav_path']);
        });
    }
};
